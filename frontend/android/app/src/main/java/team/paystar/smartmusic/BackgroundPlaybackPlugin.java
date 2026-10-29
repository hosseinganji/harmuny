package team.paystar.smartmusic;

import android.content.Intent;

import androidx.core.content.ContextCompat;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

/**
 * The app tells Android what is playing (src/lib/nativePlayback.ts); Android's buttons (notification,
 * lock screen, headphones, car) come back as "action" events. See PlaybackService.
 */
@CapacitorPlugin(name = "BackgroundPlayback")
public class BackgroundPlaybackPlugin extends Plugin implements PlaybackService.Listener {

    @Override
    public void load() {
        PlaybackService.listener = this;
    }

    @PluginMethod
    public void update(PluginCall call) {
        PlaybackService.State s = PlaybackService.state;
        s.title = call.getString("title", "");
        s.artist = call.getString("artist", "");
        s.album = call.getString("album", "");
        s.artwork = call.getString("artwork");
        s.playing = Boolean.TRUE.equals(call.getBoolean("playing", false));
        s.positionMs = call.getLong("position", 0L);
        s.durationMs = call.getLong("duration", 0L);
        getActivity().runOnUiThread(() -> {
            PlaybackService service = PlaybackService.instance;
            if (service != null) {
                service.refresh(false);
                call.resolve();
                return;
            }
            if (!s.playing) {
                call.resolve(); // nothing to keep awake yet
                return;
            }
            try {
                ContextCompat.startForegroundService(getContext(), new Intent(getContext(), PlaybackService.class));
                call.resolve();
            } catch (Exception e) {
                // Android refuses to start it from the background; the next update from the foreground will.
                call.reject(e.getMessage());
            }
        });
    }

    @PluginMethod
    public void stop(PluginCall call) {
        getActivity().runOnUiThread(() -> {
            PlaybackService service = PlaybackService.instance;
            if (service != null) {
                service.stopForeground(true);
                service.stopSelf();
            }
            call.resolve();
        });
    }

    @Override
    public void onAction(String action, long positionMs) {
        JSObject data = new JSObject();
        data.put("action", action);
        data.put("position", positionMs);
        notifyListeners("action", data);
    }

    @Override
    protected void handleOnDestroy() {
        if (PlaybackService.listener == this) PlaybackService.listener = null;
    }
}
