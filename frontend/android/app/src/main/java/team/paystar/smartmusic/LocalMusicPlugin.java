package team.paystar.smartmusic;

import android.Manifest;
import android.content.ContentResolver;
import android.content.ContentUris;
import android.database.Cursor;
import android.graphics.Bitmap;
import android.net.Uri;
import android.os.Build;
import android.provider.MediaStore;
import android.util.Base64;
import android.util.Size;

import com.getcapacitor.JSArray;
import com.getcapacitor.JSObject;
import com.getcapacitor.PermissionState;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import com.getcapacitor.annotation.Permission;
import com.getcapacitor.annotation.PermissionCallback;

import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.InputStream;

/**
 * Lists every audio file Android's MediaStore knows about, so the app can show
 * the music already on the phone regardless of which folder it lives in.
 */
@CapacitorPlugin(
    name = "LocalMusic",
    permissions = {
        @Permission(alias = "audio", strings = { Manifest.permission.READ_MEDIA_AUDIO }),
        @Permission(alias = "storage", strings = { Manifest.permission.READ_EXTERNAL_STORAGE }),
    }
)
public class LocalMusicPlugin extends Plugin {

    private String permissionAlias() {
        return Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU ? "audio" : "storage";
    }

    @PluginMethod
    public void scan(PluginCall call) {
        if (getPermissionState(permissionAlias()) != PermissionState.GRANTED) {
            requestPermissionForAlias(permissionAlias(), call, "scanAfterPermission");
            return;
        }
        // MediaStore queries can take a moment on large libraries; keep them off the UI thread.
        getBridge().execute(() -> doScan(call));
    }

    @PermissionCallback
    private void scanAfterPermission(PluginCall call) {
        if (getPermissionState(permissionAlias()) == PermissionState.GRANTED) {
            getBridge().execute(() -> doScan(call));
        } else {
            call.reject("Permission to read audio files was denied");
        }
    }

    private void doScan(PluginCall call) {
        ContentResolver resolver = getContext().getContentResolver();
        Uri collection = MediaStore.Audio.Media.EXTERNAL_CONTENT_URI;
        String[] projection = {
            MediaStore.Audio.Media._ID,
            MediaStore.Audio.Media.DATA,
            MediaStore.Audio.Media.TITLE,
            MediaStore.Audio.Media.ARTIST,
            MediaStore.Audio.Media.ALBUM,
            MediaStore.Audio.Media.ALBUM_ID,
            MediaStore.Audio.Media.DURATION,
            MediaStore.Audio.Media.SIZE,
            MediaStore.Audio.Media.MIME_TYPE,
        };
        String selection = MediaStore.Audio.Media.IS_MUSIC + " != 0";

        JSArray files = new JSArray();
        try (Cursor cursor = resolver.query(collection, projection, selection, null, MediaStore.Audio.Media.TITLE + " ASC")) {
            if (cursor != null) {
                while (cursor.moveToNext()) {
                    String path = cursor.getString(1);
                    if (path == null || !new File(path).exists()) continue;
                    File parent = new File(path).getParentFile();

                    JSObject file = new JSObject();
                    file.put("id", String.valueOf(cursor.getLong(0)));
                    file.put("path", path);
                    file.put("title", cursor.getString(2));
                    file.put("artist", cursor.getString(3));
                    file.put("album", cursor.getString(4));
                    file.put("albumId", cursor.isNull(5) ? null : String.valueOf(cursor.getLong(5)));
                    file.put("duration", cursor.getLong(6));
                    file.put("size", cursor.getLong(7));
                    file.put("mime", cursor.getString(8));
                    file.put("folder", parent != null ? parent.getName() : "");
                    files.put(file);
                }
            }
        } catch (Exception e) {
            call.reject("Could not read the music library: " + e.getMessage(), e);
            return;
        }

        JSObject result = new JSObject();
        result.put("files", files);
        call.resolve(result);
    }

    @PluginMethod
    public void artwork(PluginCall call) {
        String albumId = call.getString("albumId");
        if (albumId == null) {
            call.reject("albumId is required");
            return;
        }
        getBridge().execute(() -> {
            JSObject result = new JSObject();
            result.put("data", loadArtwork(Long.parseLong(albumId)));
            call.resolve(result);
        });
    }

    private String loadArtwork(long albumId) {
        ContentResolver resolver = getContext().getContentResolver();
        try {
            byte[] bytes;
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                Uri album = ContentUris.withAppendedId(MediaStore.Audio.Albums.EXTERNAL_CONTENT_URI, albumId);
                Bitmap bitmap = resolver.loadThumbnail(album, new Size(512, 512), null);
                ByteArrayOutputStream out = new ByteArrayOutputStream();
                bitmap.compress(Bitmap.CompressFormat.JPEG, 85, out);
                bytes = out.toByteArray();
            } else {
                Uri art = ContentUris.withAppendedId(Uri.parse("content://media/external/audio/albumart"), albumId);
                try (InputStream in = resolver.openInputStream(art)) {
                    if (in == null) return null;
                    ByteArrayOutputStream out = new ByteArrayOutputStream();
                    byte[] buffer = new byte[8192];
                    int n;
                    while ((n = in.read(buffer)) > 0) out.write(buffer, 0, n);
                    bytes = out.toByteArray();
                }
            }
            return Base64.encodeToString(bytes, Base64.NO_WRAP);
        } catch (Exception e) {
            // No artwork for this album.
            return null;
        }
    }
}
