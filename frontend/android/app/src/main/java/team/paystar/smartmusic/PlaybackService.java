package team.paystar.smartmusic;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.content.pm.ServiceInfo;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.net.wifi.WifiManager;
import android.os.Build;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;
import android.os.PowerManager;
import android.support.v4.media.MediaMetadataCompat;
import android.support.v4.media.session.MediaSessionCompat;
import android.support.v4.media.session.PlaybackStateCompat;

import androidx.annotation.Nullable;
import androidx.core.app.NotificationCompat;
import androidx.core.app.NotificationManagerCompat;
import androidx.media.app.NotificationCompat.MediaStyle;

import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/**
 * Keeps Harmony playing with the screen off. The music itself still plays in the web view; this
 * foreground service keeps the app's process, CPU and Wi-Fi awake (so the next song can load and start,
 * which a phone in deep sleep otherwise blocks), and gives Android a media session: the notification,
 * the lock screen, headphone, Bluetooth and car buttons, all passed back to the app.
 */
public class PlaybackService extends Service {

    /** What the app last reported; the plugin fills it in, the service shows it. */
    static final class State {
        String title = "";
        String artist = "";
        String album = "";
        String artwork = null;
        boolean playing = false;
        long positionMs = 0;
        long durationMs = 0;
    }

    interface Listener {
        void onAction(String action, long positionMs);
    }

    static final State state = new State();
    static Listener listener;
    static PlaybackService instance;

    private static final String CHANNEL = "playback";
    private static final int NOTIFICATION_ID = 1;
    private static final String EXTRA_ACTION = "action";

    private final Handler main = new Handler(Looper.getMainLooper());
    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private MediaSessionCompat session;
    private PowerManager.WakeLock wakeLock;
    private WifiManager.WifiLock wifiLock;
    /** Paused: let the phone sleep, but not in the moment between two songs. */
    private final Runnable releaseLocks = () -> {
        if (wakeLock.isHeld()) wakeLock.release();
        if (wifiLock.isHeld()) wifiLock.release();
    };
    private String artworkLoaded = null;
    private Bitmap artwork = null;

    @Override
    public void onCreate() {
        super.onCreate();
        instance = this;
        if (Build.VERSION.SDK_INT >= 26) {
            NotificationChannel channel = new NotificationChannel(CHANNEL, getString(R.string.playback_channel), NotificationManager.IMPORTANCE_LOW);
            channel.setShowBadge(false);
            getSystemService(NotificationManager.class).createNotificationChannel(channel);
        }

        session = new MediaSessionCompat(this, "Harmony");
        session.setCallback(new MediaSessionCompat.Callback() {
            @Override public void onPlay() { send("play", -1); }
            @Override public void onPause() { send("pause", -1); }
            @Override public void onStop() { send("pause", -1); }
            @Override public void onSkipToNext() { send("next", -1); }
            @Override public void onSkipToPrevious() { send("prev", -1); }
            @Override public void onSeekTo(long pos) { send("seek", pos); }
        });
        session.setActive(true);

        PowerManager power = (PowerManager) getSystemService(Context.POWER_SERVICE);
        wakeLock = power.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "Harmony:playback");
        wakeLock.setReferenceCounted(false);
        WifiManager wifi = (WifiManager) getApplicationContext().getSystemService(Context.WIFI_SERVICE);
        int mode = Build.VERSION.SDK_INT >= 29 ? WifiManager.WIFI_MODE_FULL_LOW_LATENCY : WifiManager.WIFI_MODE_FULL_HIGH_PERF;
        wifiLock = wifi.createWifiLock(mode, "Harmony:playback");
        wifiLock.setReferenceCounted(false);
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        String action = intent != null ? intent.getStringExtra(EXTRA_ACTION) : null;
        if (action != null) {
            send(action, -1); // a notification button
        }
        refresh(true);
        return START_NOT_STICKY;
    }

    /** Show what the app reported: notification, lock screen, and whether to keep the phone awake. */
    void refresh(boolean promote) {
        State s = state;
        session.setMetadata(new MediaMetadataCompat.Builder()
            .putString(MediaMetadataCompat.METADATA_KEY_TITLE, s.title)
            .putString(MediaMetadataCompat.METADATA_KEY_ARTIST, s.artist)
            .putString(MediaMetadataCompat.METADATA_KEY_ALBUM, s.album)
            .putLong(MediaMetadataCompat.METADATA_KEY_DURATION, s.durationMs)
            .putBitmap(MediaMetadataCompat.METADATA_KEY_ALBUM_ART, artwork)
            .build());
        session.setPlaybackState(new PlaybackStateCompat.Builder()
            .setActions(PlaybackStateCompat.ACTION_PLAY | PlaybackStateCompat.ACTION_PAUSE | PlaybackStateCompat.ACTION_PLAY_PAUSE
                | PlaybackStateCompat.ACTION_SKIP_TO_NEXT | PlaybackStateCompat.ACTION_SKIP_TO_PREVIOUS | PlaybackStateCompat.ACTION_SEEK_TO
                | PlaybackStateCompat.ACTION_STOP)
            .setState(s.playing ? PlaybackStateCompat.STATE_PLAYING : PlaybackStateCompat.STATE_PAUSED, s.positionMs, s.playing ? 1f : 0f)
            .build());

        // Awake while music plays; paused for a minute, the phone may sleep as usual (a song ending and the
        // next one loading also reads as "paused" for a moment, and that is exactly when it must stay awake).
        main.removeCallbacks(releaseLocks);
        if (s.playing) {
            if (!wakeLock.isHeld()) wakeLock.acquire(6 * 60 * 60 * 1000L);
            if (!wifiLock.isHeld()) wifiLock.acquire();
        } else {
            main.postDelayed(releaseLocks, 60_000);
        }

        Notification notification = build();
        if (promote) {
            if (Build.VERSION.SDK_INT >= 29) {
                startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_MEDIA_PLAYBACK);
            } else {
                startForeground(NOTIFICATION_ID, notification);
            }
        } else {
            try {
                NotificationManagerCompat.from(this).notify(NOTIFICATION_ID, notification);
            } catch (SecurityException ignored) {
                // Notifications turned off: the session and the locks still work.
            }
        }
        loadArtwork(s.artwork);
    }

    private Notification build() {
        State s = state;
        Intent open = new Intent(this, MainActivity.class).setFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP);
        PendingIntent content = PendingIntent.getActivity(this, 0, open, PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
        return new NotificationCompat.Builder(this, CHANNEL)
            .setSmallIcon(R.drawable.ic_stat_music)
            .setContentTitle(s.title)
            .setContentText(s.artist)
            .setSubText(s.album.isEmpty() ? null : s.album)
            .setLargeIcon(artwork)
            .setContentIntent(content)
            .setOnlyAlertOnce(true)
            .setSilent(true)
            .setOngoing(s.playing)
            .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
            .addAction(android.R.drawable.ic_media_previous, getString(R.string.action_previous), button("prev", 1))
            .addAction(s.playing ? android.R.drawable.ic_media_pause : android.R.drawable.ic_media_play,
                getString(s.playing ? R.string.action_pause : R.string.action_play), button(s.playing ? "pause" : "play", 2))
            .addAction(android.R.drawable.ic_media_next, getString(R.string.action_next), button("next", 3))
            .setStyle(new MediaStyle().setMediaSession(session.getSessionToken()).setShowActionsInCompactView(0, 1, 2))
            .build();
    }

    private PendingIntent button(String action, int code) {
        Intent intent = new Intent(this, PlaybackService.class).putExtra(EXTRA_ACTION, action);
        return PendingIntent.getService(this, code, intent, PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
    }

    private void loadArtwork(String url) {
        if (url == null ? artworkLoaded == null : url.equals(artworkLoaded)) return;
        artworkLoaded = url;
        if (url == null) {
            artwork = null;
            return;
        }
        io.execute(() -> {
            Bitmap bitmap = null;
            try {
                HttpURLConnection connection = (HttpURLConnection) new URL(url).openConnection();
                connection.setConnectTimeout(10000);
                connection.setReadTimeout(15000);
                try (InputStream in = connection.getInputStream()) {
                    bitmap = BitmapFactory.decodeStream(in);
                }
                if (bitmap != null && bitmap.getWidth() > 512) {
                    bitmap = Bitmap.createScaledBitmap(bitmap, 512, 512 * bitmap.getHeight() / bitmap.getWidth(), true);
                }
            } catch (Exception ignored) {
                // No artwork: the app logo stays.
            }
            Bitmap loaded = bitmap;
            main.post(() -> {
                if (!url.equals(artworkLoaded)) return;
                artwork = loaded;
                refresh(false);
            });
        });
    }

    private void send(String action, long positionMs) {
        if (listener != null) listener.onAction(action, positionMs);
    }

    @Override
    public void onTaskRemoved(Intent rootIntent) {
        // Swiped away from recent apps: the web view is gone, so stop too.
        stopSelf();
    }

    @Override
    public void onDestroy() {
        instance = null;
        main.removeCallbacks(releaseLocks);
        if (wakeLock.isHeld()) wakeLock.release();
        if (wifiLock.isHeld()) wifiLock.release();
        session.setActive(false);
        session.release();
        io.shutdownNow();
        super.onDestroy();
    }

    @Nullable
    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }
}
