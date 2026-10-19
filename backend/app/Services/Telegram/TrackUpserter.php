<?php

namespace App\Services\Telegram;

use App\Models\Channel;
use App\Jobs\FetchCoverJob;
use App\Jobs\NotifyNewTracksJob;
use App\Models\Track;
use App\Services\CoverService;

/** Creates or refreshes the track for one channel post, shared by both sync modes. */
class TrackUpserter
{
    public static function upsert(Channel $channel, array $data): Track
    {
        $track = Track::withTrashed()->firstOrNew([
            'channel_id' => $channel->id,
            'message_id' => $data['message_id'],
        ]);

        if ($track->exists && $track->metadata_locked) {
            // An admin fixed the metadata by hand; only refresh file facts.
            $data = array_intersect_key($data, array_flip([
                'file_id', 'thumb_file_id', 'file_name', 'mime_type', 'file_size', 'caption', 'has_cover', 'posted_at',
            ]));
        } elseif ($track->exists) {
            // Keep richer values discovered from the file's own tags.
            foreach (['artist', 'duration'] as $key) {
                if (empty($data[$key]) && $track->{$key}) {
                    unset($data[$key]);
                }
            }
        }

        $track->fill($data);
        if ($track->trashed()) {
            $track->deleted_at = null;
        }
        $track->save();

        if ($track->has_cover && ! app(CoverService::class)->cached($track)) {
            FetchCoverJob::dispatch($track);
        }

        // A song just posted (not an old one found while reading a channel's history): tell members.
        if ($track->wasRecentlyCreated && $track->posted_at?->gte(now()->subHours(2))) {
            NotifyNewTracksJob::collect($track);
        }

        return $track;
    }
}
