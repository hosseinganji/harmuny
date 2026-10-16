<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CacheTrackJob;
use App\Jobs\FetchCoverJob;
use App\Models\Track;
use App\Services\CoverService;
use App\Services\Telegram\TelegramManager;
use App\Services\TrackCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    /**
     * Stream a track with HTTP Range support.
     *
     * Cached files are served straight from disk. Otherwise the requested
     * byte range is proxied from Telegram while a background job caches the
     * whole file, so playback starts immediately on the first listen.
     */
    public function stream(Request $request, Track $track, TrackCache $cache, TelegramManager $telegram): Response
    {
        $mime = $track->mime_type ?: 'audio/mpeg';
        $name = $track->file_name ?: ($track->title.'.mp3');

        abort_unless($track->isCached() || ($track->channel && $track->file_size > 0), 404);

        // Bot downloads are capped at 20 MB, so fetch the whole file once and serve it from disk.
        if (! $track->isCached() && TrackCache::viaBot($track)) {
            try {
                $cache->ensureCached($track);
            } catch (\Throwable $e) {
                Log::warning('Bot download failed', ['track' => $track->id, 'error' => $e->getMessage()]);
                abort(502, 'Could not fetch this song from Telegram.');
            }
            $track->refresh();
        }

        if ($track->isCached()) {
            $cache->touch($track);

            return response()->file($track->cacheAbsolutePath(), [
                'Content-Type' => $mime,
                'Cache-Control' => 'private, max-age=31536000, immutable',
            ])->setContentDisposition('inline', $name, 'track.'.pathinfo($name, PATHINFO_EXTENSION));
        }

        $message = $telegram->getMessage($track->channel->inputPeer(), (int) $track->message_id);
        abort_unless($message, 404, 'The Telegram message for this track no longer exists.');

        CacheTrackJob::dispatch($track);

        $size = (int) $track->file_size;
        [$start, $end] = $this->parseRange($request->header('Range'), $size);
        if ($start === null) {
            return response('', 416, ['Content-Range' => "bytes */{$size}"]);
        }

        $partial = $request->hasHeader('Range');
        $headers = [
            'Content-Type' => $mime,
            'Accept-Ranges' => 'bytes',
            'Content-Length' => (string) ($end - $start + 1),
            'Cache-Control' => 'private, no-transform',
            'X-Accel-Buffering' => 'no',
        ];
        if ($partial) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        return new StreamedResponse(function () use ($telegram, $message, $start, $end, $track) {
            // A full song can take longer than the default web time limit to proxy.
            set_time_limit(0);
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            try {
                $telegram->streamRange($message, $start, $end, function (string $chunk) {
                    echo $chunk;
                    flush();
                    if (connection_aborted()) {
                        throw new \RuntimeException('client gone');
                    }
                });
            } catch (\Throwable $e) {
                if ($e->getMessage() !== 'client gone') {
                    Log::warning('Stream failed', ['track' => $track->id, 'error' => $e->getMessage()]);
                }
            }
        }, $partial ? 206 : 200, $headers);
    }

    public function cover(Track $track, CoverService $covers): Response
    {
        $path = $covers->cached($track);
        if (! $path) {
            // Never hold a web worker on Telegram: fetch in the background, the app retries later.
            if ($track->has_cover) {
                FetchCoverJob::dispatch($track);
            }

            return response('', 404, ['Cache-Control' => 'no-store']);
        }

        return response()->file($path, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=2592000',
        ]);
    }

    /** @return array{0: ?int, 1: ?int} */
    private function parseRange(?string $header, int $size): array
    {
        if (! $header || ! preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m)) {
            return [0, $size - 1];
        }
        if ($m[1] === '' && $m[2] !== '') {
            // Suffix range: the last N bytes.
            $start = max(0, $size - (int) $m[2]);
            $end = $size - 1;
        } else {
            $start = (int) $m[1];
            $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        }

        return $start > $end || $start >= $size ? [null, null] : [$start, $end];
    }
}
