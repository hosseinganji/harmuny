<?php

namespace App\Services;

use App\Models\Jam;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Jams: members listening to one queue together, each on their own device (or on one speaker for a room).
 *
 * The server holds the truth: the queue, and where playback is, anchored to its clock. Devices play the
 * queue themselves and keep in step with that anchor, so a phone that loses the connection for a while
 * (screen locked) carries on through the songs on its own and lines up again when it is back.
 *
 * Every change runs under a row lock, bumps the version and is published to the members' devices.
 */
class JamService
{
    /** Songs still to come a queue can hold; a few dozen played ones are kept too, for "previous". */
    private const MAX_UPCOMING = 500;

    private const KEEP_PLAYED = 50;

    /** A song started "now" is anchored a moment ahead: about how long a device needs to begin it. */
    public const START_LEAD_MS = 300;

    /** Members away this long (no ping) leave the Jam. */
    private const AWAY_SECONDS = 15 * 60;

    /** A member counts as here while pinging (devices ping every 15 seconds while the app is open or playing). */
    public const ONLINE_SECONDS = 45;

    /** If no device reports that a song ended, the server moves on this long after it should have. */
    private const END_GRACE_MS = 8000;

    public const REACTIONS = ['🔥', '❤️', '😍', '😂', '👏', '🎉', '💃', '🥲'];

    public function __construct(private JamHub $hub, private RadioService $radio) {}

    // ---- lifecycle -----------------------------------------------------------------------------

    /**
     * Start a Jam from the host's queue (the song playing now first).
     *
     * @param  int[]  $trackIds
     */
    public function start(User $host, array $trackIds, int $positionMs = 0, bool $playing = false): Jam
    {
        $this->leaveAll($host);
        $queue = $this->entries($trackIds, $host->id);
        $jam = Jam::query()->create([
            'code' => $this->newCode(),
            'host_id' => $host->id,
            'queue' => $queue,
            'current_uid' => $queue[0]['uid'] ?? null,
            'playing' => $playing && $queue,
            'position_ms' => $queue ? max(0, $positionMs) : 0,
            'anchor_ms' => Jam::nowMs(),
        ]);
        $jam->members()->attach($host->id, ['listening' => true, 'joined_at' => now(), 'last_seen_at' => now()]);
        if ($this->topUp($jam)) {
            $jam->save();
        }

        return $jam;
    }

    public function join(Jam $jam, User $user, bool $listening = true): Jam
    {
        $this->ensureActive($jam);
        if ($jam->isBanned($user)) {
            $this->deny('banned', 'You were removed from this Jam.');
        }
        $this->leaveAll($user, $jam);

        return $this->mutate($jam, function (Jam $jam) use ($user, $listening) {
            $pivot = $jam->members()->whereKey($user->id)->first()?->pivot;
            if ($pivot) {
                $jam->members()->updateExistingPivot($user->id, ['listening' => $listening, 'last_seen_at' => now()]);

                return (bool) $pivot->listening === $listening ? false : null;
            }
            $jam->members()->attach($user->id, ['listening' => $listening, 'joined_at' => now(), 'last_seen_at' => now()]);

            return ['type' => 'join', 'user_id' => $user->id];
        });
    }

    /** Leave (or be dropped for being away). A host who leaves hands the Jam on; the last one out ends it. */
    public function leave(Jam $jam, User $user, string $reason = 'leave'): void
    {
        if (! $jam->refresh()->isActive()) {
            return;
        }
        $this->mutate($jam, function (Jam $jam) use ($user, $reason) {
            if (! $jam->members()->whereKey($user->id)->exists()) {
                return false;
            }
            $jam->members()->detach($user->id);
            $event = ['type' => $reason, 'user_id' => $user->id];
            $successor = $this->successor($jam);
            if (! $successor) {
                $jam->ended_at = now();
                $jam->playing = false;

                return ['type' => 'end', 'user_id' => $user->id];
            }
            if ($jam->host_id === $user->id) {
                $jam->host_id = $successor->id;
                $event['host_id'] = $successor->id;
            }

            return $event;
        });
    }

    public function kick(Jam $jam, User $host, User $user): Jam
    {
        $this->ensureHost($jam, $host);
        abort_if($host->is($user), 422, 'The host cannot remove themselves; leave instead.');

        return $this->mutate($jam, function (Jam $jam) use ($host, $user) {
            $jam->members()->detach($user->id);
            $jam->banned = array_values(array_unique([...($jam->banned ?? []), $user->id]));

            return ['type' => 'kick', 'user_id' => $user->id, 'by' => $host->id];
        });
    }

    public function end(Jam $jam, User $user): Jam
    {
        $this->ensureHost($jam, $user);

        return $this->mutate($jam, function (Jam $jam) use ($user) {
            $jam->ended_at = now();
            $jam->playing = false;

            return ['type' => 'end', 'user_id' => $user->id];
        });
    }

    /** Settings only the host changes: whether guests control playback, and the Jam mix. */
    public function configure(Jam $jam, User $user, array $settings): Jam
    {
        $this->ensureHost($jam, $user);

        return $this->mutate($jam, function (Jam $jam) use ($settings, $user) {
            $jam->fill(array_intersect_key($settings, array_flip(['guests_control', 'autofill'])));
            $this->topUp($jam);

            return ['type' => 'settings', 'user_id' => $user->id];
        });
    }

    /**
     * A device checking in (every 15 seconds while open or playing): keeps its member here, records whether
     * it plays the music itself, and tidies the Jam (members long gone, songs nobody reported ending).
     */
    public function ping(Jam $jam, User $user, ?bool $listening = null): Jam
    {
        $this->ensureActive($jam);
        $pivot = $jam->members()->whereKey($user->id)->first()?->pivot;
        // Dropped for being away (a phone locked for a long time): back in, unless the host removed them.
        if (! $pivot) {
            return $this->join($jam, $user, $listening ?? true);
        }
        $jam->members()->updateExistingPivot($user->id, ['last_seen_at' => now()] + ($listening === null ? [] : ['listening' => $listening]));
        $jam = $this->tidy($jam);
        if ($jam->isActive() && $listening !== null && (bool) $pivot->listening !== $listening) {
            $jam = $this->mutate($jam, fn () => null);
        }

        return $jam;
    }

    /** Drop members away for long and catch up on songs that ended with no device left to say so. */
    public function tidy(Jam $jam): Jam
    {
        $away = $jam->members()->wherePivot('last_seen_at', '<', now()->subSeconds(self::AWAY_SECONDS))->get();
        foreach ($away as $user) {
            $this->leave($jam, $user, 'away');
        }
        $jam->refresh();
        if ($jam->isActive() && $this->overdue($jam)) {
            $jam = $this->mutate($jam, fn (Jam $jam) => $this->catchUp($jam) ? [] : false);
        }

        return $jam;
    }

    /** Run by the scheduler for Jams whose members all closed the app. */
    public function tidyAll(): void
    {
        Jam::query()->active()->each(fn (Jam $jam) => $this->tidy($jam));
    }

    /** Leave whatever Jam this member is in (one at a time). */
    public function leaveAll(User $user, ?Jam $except = null): void
    {
        Jam::query()->active()
            ->whereHas('members', fn ($q) => $q->whereKey($user->id))
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->get()
            ->each(fn (Jam $jam) => $this->leave($jam, $user));
    }

    public function current(User $user): ?Jam
    {
        return Jam::query()->active()->whereHas('members', fn ($q) => $q->whereKey($user->id))->latest('id')->first();
    }

    // ---- playback ------------------------------------------------------------------------------

    /**
     * play / pause / seek / next / prev / jump: control playback for everyone (the host, or anyone when the
     * host allows it). advance: a device reached the end of the song (anyone). vote: ask to skip the song.
     * shuffle / clear: the songs still to come.
     */
    public function playback(Jam $jam, User $user, string $action, array $data = []): Jam
    {
        $this->ensureMember($jam, $user);
        if ($action === 'vote' && $jam->canControl($user)) {
            $action = 'next';
        }
        if (! in_array($action, ['advance', 'vote'], true)) {
            $this->ensureControl($jam, $user);
        }
        $position = isset($data['position_ms']) ? max(0, (int) $data['position_ms']) : null;
        $from = $data['from'] ?? null;
        $by = ['user_id' => $user->id];

        return $this->mutate($jam, function (Jam $jam) use ($action, $position, $from, $data, $by, $user) {
            $now = Jam::nowMs();
            switch ($action) {
                case 'play':
                    if ($jam->current_uid === null) {
                        return false;
                    }
                    if ($this->finished($jam)) {
                        // Pressing play at the end of the queue starts it again from the last song.
                        $position = 0;
                    }
                    $jam->position_ms = $position ?? $jam->positionAt($now);
                    $jam->anchor_ms = $now;
                    $jam->playing = true;

                    return ['type' => 'play'] + $by;
                case 'pause':
                    if (! $jam->playing) {
                        return false;
                    }
                    $jam->position_ms = $position ?? $jam->positionAt($now);
                    $jam->anchor_ms = $now;
                    $jam->playing = false;

                    return ['type' => 'pause'] + $by;
                case 'seek':
                    $jam->position_ms = $position ?? 0;
                    $jam->anchor_ms = $now;

                    return ['type' => 'seek'] + $by;
                case 'next':
                    if ($from !== null && $from !== $jam->current_uid) {
                        return false; // already moved on
                    }
                    $this->advance($jam);

                    return ['type' => 'skip'] + $by;
                case 'advance':
                    if ($from === null || $from !== $jam->current_uid) {
                        return false; // another device got there first
                    }
                    $this->advance($jam);

                    return [];
                case 'prev':
                    $i = $jam->currentIndex();
                    if (($position ?? $jam->positionAt($now)) > 3000 || $i <= 0) {
                        $jam->position_ms = 0;
                        $jam->anchor_ms = $now;
                    } else {
                        $this->moveTo($jam, $i - 1, $now + self::START_LEAD_MS);
                    }

                    return ['type' => 'prev'] + $by;
                case 'jump':
                    $i = $this->indexOf($jam, (string) ($data['uid'] ?? ''));
                    abort_if($i < 0, 404, 'That song is no longer in the Jam.');
                    $this->moveTo($jam, $i, $now + self::START_LEAD_MS);

                    return ['type' => 'jump', 'track_id' => $jam->queue[$i]['track_id']] + $by;
                case 'vote':
                    return $this->vote($jam, $user);
                case 'shuffle':
                    $i = $jam->currentIndex();
                    $upcoming = array_slice($jam->queue, $i + 1);
                    shuffle($upcoming);
                    $jam->queue = [...array_slice($jam->queue, 0, $i + 1), ...$upcoming];

                    return ['type' => 'shuffle'] + $by;
                case 'clear':
                    $jam->queue = array_slice($jam->queue, 0, $jam->currentIndex() + 1);

                    return ['type' => 'clear'] + $by;
            }
            abort(422, 'Unknown action.');
        });
    }

    /** Guests who can't control playback vote; once enough of the members here agree, the song is skipped. */
    private function vote(Jam $jam, User $user): array|false
    {
        $votes = ($jam->skip_votes['uid'] ?? null) === $jam->current_uid ? $jam->skip_votes['users'] : [];
        if (in_array($user->id, $votes, true)) {
            return false;
        }
        $votes[] = $user->id;
        $needed = $this->votesNeeded($jam);
        if (count($votes) >= $needed) {
            $this->advance($jam);

            return ['type' => 'skip', 'user_id' => null, 'voted' => true];
        }
        $jam->skip_votes = ['uid' => $jam->current_uid, 'users' => $votes];

        return ['type' => 'vote', 'user_id' => $user->id, 'votes' => count($votes), 'needed' => $needed];
    }

    private function votesNeeded(Jam $jam): int
    {
        $here = $jam->members()->wherePivot('last_seen_at', '>=', now()->subSeconds(self::ONLINE_SECONDS))->count();

        return max(2, (int) ceil($here / 2));
    }

    // ---- the queue -----------------------------------------------------------------------------

    /**
     * Add songs. "end": after the other picks but ahead of the Jam mix, so what people choose plays first;
     * "next": right after the current song; "play": next, and start it now (when the member controls
     * playback; otherwise it is simply added).
     *
     * @param  int[]  $trackIds
     */
    public function add(Jam $jam, User $user, array $trackIds, string $mode = 'end'): Jam
    {
        $this->ensureMember($jam, $user);
        $entries = $this->entries($trackIds, $user->id);
        abort_if(! $entries, 422, 'No songs to add.');
        if ($mode === 'play' && ! $jam->canControl($user)) {
            $mode = 'end';
        }
        $next = $mode !== 'end';

        return $this->mutate($jam, function (Jam $jam) use ($entries, $next, $mode, $user) {
            $queue = $jam->queue;
            $i = $jam->currentIndex();
            abort_if(count($queue) - $i - 1 + count($entries) > self::MAX_UPCOMING, 422, 'The Jam queue is full.');
            $finished = $this->finished($jam);
            $at = count($queue);
            if ($next) {
                $at = $i + 1;
            } else {
                for ($k = $i + 1; $k < count($queue); $k++) {
                    if ($queue[$k]['by'] === null) {
                        $at = $k;
                        break;
                    }
                }
            }
            array_splice($queue, $at, 0, $entries);
            $jam->queue = $queue;
            // Played now, or nothing was playing (an empty Jam, or one that reached the end): start right away.
            if ($mode === 'play' || $i < 0 || $finished) {
                $this->moveTo($jam, $at, Jam::nowMs() + self::START_LEAD_MS);
            }

            return [
                'type' => $mode === 'play' ? 'jump' : 'add', 'user_id' => $user->id, 'next' => $next, 'count' => count($entries),
                'track_ids' => array_slice(array_column($entries, 'track_id'), 0, 3),
            ];
        });
    }

    /** Take a song out: anyone who controls playback, or whoever added it. */
    public function remove(Jam $jam, User $user, string $uid): Jam
    {
        $this->ensureMember($jam, $user);

        return $this->mutate($jam, function (Jam $jam) use ($user, $uid) {
            $k = $this->indexOf($jam, $uid);
            if ($k < 0) {
                return false;
            }
            abort_if($k === $jam->currentIndex(), 422, 'The song playing now cannot be removed; skip it instead.');
            if (! $jam->canControl($user) && $jam->queue[$k]['by'] !== $user->id) {
                $this->deny('no_control', 'Only the host can remove songs others added.');
            }
            $queue = $jam->queue;
            array_splice($queue, $k, 1);
            $jam->queue = $queue;

            return [];
        });
    }

    /** @param  string[]  $uids  the songs still to come, in their new order */
    public function reorder(Jam $jam, User $user, array $uids): Jam
    {
        $this->ensureMember($jam, $user);
        $this->ensureControl($jam, $user);

        return $this->mutate($jam, function (Jam $jam) use ($uids) {
            $i = $jam->currentIndex();
            $upcoming = array_slice($jam->queue, $i + 1);
            $rank = array_flip(array_values($uids));
            // Songs added meanwhile (not in the list) keep their place at the end.
            usort($upcoming, fn ($a, $b) => ($rank[$a['uid']] ?? PHP_INT_MAX) <=> ($rank[$b['uid']] ?? PHP_INT_MAX));
            $jam->queue = [...array_slice($jam->queue, 0, $i + 1), ...$upcoming];

            return [];
        });
    }

    /** An emoji for everyone's screen; not stored. */
    public function react(Jam $jam, User $user, string $emoji): void
    {
        $this->ensureMember($jam, $user);
        abort_unless(in_array($emoji, self::REACTIONS, true), 422, 'Unknown reaction.');
        $this->hub->publish($this->topic($jam), ['type' => 'reaction', 'user_id' => $user->id, 'emoji' => $emoji, 'at' => Jam::nowMs()]);
    }

    // ---- what devices see ----------------------------------------------------------------------

    public function topic(Jam $jam): string
    {
        return 'jam/'.$jam->id;
    }

    public function subscription(Jam $jam): ?array
    {
        return $this->hub->subscription($this->topic($jam));
    }

    public function snapshot(Jam $jam): array
    {
        $online = now()->subSeconds(self::ONLINE_SECONDS);
        $members = $jam->members()->get()
            ->sortBy(fn (User $u) => [$u->id === $jam->host_id ? 0 : 1, (string) $u->pivot->joined_at])
            ->values();
        $votes = ($jam->skip_votes['uid'] ?? null) === $jam->current_uid ? $jam->skip_votes['users'] : [];

        return [
            'code' => $jam->code,
            'host_id' => $jam->host_id,
            'guests_control' => $jam->guests_control,
            'autofill' => $jam->autofill,
            'version' => $jam->version,
            'ended' => ! $jam->isActive(),
            'playing' => $jam->playing,
            'current_uid' => $jam->current_uid,
            'position_ms' => $jam->position_ms,
            'anchor_ms' => $jam->anchor_ms,
            'server_now' => Jam::nowMs(),
            'queue' => array_values($jam->queue),
            'skip_votes' => $votes ? ['users' => $votes, 'needed' => $this->votesNeeded($jam)] : null,
            'members' => $members->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'avatar_url' => $u->avatarUrl(),
                'listening' => (bool) $u->pivot->listening,
                'online' => $u->pivot->last_seen_at !== null && Carbon::parse($u->pivot->last_seen_at)->gte($online),
            ])->all(),
        ];
    }

    // ---- internals -----------------------------------------------------------------------------

    /**
     * Apply a change under a row lock, then publish it. The change returns false for "nothing changed",
     * or the event to show on the other devices (an empty array or null: a silent update).
     */
    private function mutate(Jam $jam, callable $change): Jam
    {
        $event = false;
        $fresh = DB::transaction(function () use ($jam, $change, &$event) {
            $locked = Jam::query()->lockForUpdate()->findOrFail($jam->id);
            $this->ensureActive($locked);
            $event = $change($locked);
            if ($event !== false) {
                $locked->version++;
                $locked->save();
            }

            return $locked;
        });
        if ($event !== false) {
            $this->hub->publish($this->topic($fresh), ['type' => 'state', 'jam' => $this->snapshot($fresh), 'event' => $event ?: null]);
        }

        return $fresh;
    }

    /** On to the next song (topping the queue up with the Jam mix); at the very end, stop. */
    private function advance(Jam $jam): void
    {
        $i = $jam->currentIndex();
        $this->topUp($jam, force: $i + 1 >= count($jam->queue));
        if ($this->moveTo($jam, $i + 1, Jam::nowMs() + self::START_LEAD_MS)) {
            $this->topUp($jam);

            return;
        }
        $jam->playing = false;
        $jam->position_ms = $this->durationMs($jam);
        $jam->anchor_ms = Jam::nowMs();
    }

    private function moveTo(Jam $jam, int $index, int $anchorMs): bool
    {
        $entry = $jam->queue[$index] ?? null;
        if (! $entry) {
            return false;
        }
        $jam->current_uid = $entry['uid'];
        $jam->position_ms = 0;
        $jam->anchor_ms = $anchorMs;
        $jam->playing = true;
        $jam->skip_votes = null;
        // Keep a few dozen played songs for "previous"; older ones go.
        if ($index > self::KEEP_PLAYED) {
            $jam->queue = array_slice($jam->queue, $index - self::KEEP_PLAYED);
        }

        return true;
    }

    /** Stopped at the end of the last song. */
    private function finished(Jam $jam): bool
    {
        $duration = $this->durationMs($jam);

        return ! $jam->playing && $jam->currentIndex() === count($jam->queue) - 1
            && $duration > 0 && $jam->position_ms >= $duration - 1000;
    }

    /** Keep at least two songs coming with the Jam mix: songs every member likes, taking turns. */
    private function topUp(Jam $jam, bool $force = false): bool
    {
        $upcoming = count($jam->queue) - $jam->currentIndex() - 1;
        if (! $jam->autofill || (! $force && $upcoming >= 2)) {
            return false;
        }
        $picks = $this->radio->blend($jam->members()->get(), array_column($jam->queue, 'track_id'), 6);
        if ($picks->isEmpty()) {
            return false;
        }
        $entries = $this->entries($picks->pluck('id')->all(), null);
        $jam->queue = [...$jam->queue, ...$entries];
        if ($jam->current_uid === null) {
            $jam->current_uid = $entries[0]['uid'];
            $jam->position_ms = 0;
            $jam->anchor_ms = Jam::nowMs();
        }

        return true;
    }

    private function overdue(Jam $jam): bool
    {
        $duration = $this->durationMs($jam);

        return $jam->playing && $duration > 0 && $jam->positionAt(Jam::nowMs()) > $duration + self::END_GRACE_MS;
    }

    /** Move on through songs that ended while no device was there to report it, each starting as the last ended. */
    private function catchUp(Jam $jam): bool
    {
        $moved = false;
        for ($n = 0; $n < 100 && $this->overdue($jam); $n++) {
            $endedAt = $jam->anchor_ms + $this->durationMs($jam) - $jam->position_ms;
            $i = $jam->currentIndex();
            $this->topUp($jam, force: $i + 1 >= count($jam->queue));
            if (! $this->moveTo($jam, $jam->currentIndex() + 1, $endedAt)) {
                $jam->playing = false;
                $jam->position_ms = $this->durationMs($jam);
                $jam->anchor_ms = Jam::nowMs();

                return true;
            }
            $moved = true;
        }

        return $moved;
    }

    private function durationMs(Jam $jam): int
    {
        $i = $jam->currentIndex();
        if ($i < 0) {
            return 0;
        }

        return 1000 * (int) Track::withTrashed()->whereKey($jam->queue[$i]['track_id'])->value('duration');
    }

    private function indexOf(Jam $jam, string $uid): int
    {
        foreach ($jam->queue as $k => $entry) {
            if ($entry['uid'] === $uid) {
                return $k;
            }
        }

        return -1;
    }

    /**
     * Queue entries for the songs that exist, in the order given.
     *
     * @param  int[]  $trackIds
     * @return array<int, array{uid: string, track_id: int, by: int|null}>
     */
    private function entries(array $trackIds, ?int $by): array
    {
        $ids = array_values(array_unique(array_map('intval', $trackIds)));
        $existing = array_flip(Track::query()->whereIn('id', $ids)->pluck('id')->all());

        return array_values(array_map(
            fn (int $id) => ['uid' => bin2hex(random_bytes(5)), 'track_id' => $id, 'by' => $by],
            array_filter($ids, fn (int $id) => isset($existing[$id])),
        ));
    }

    /** Who takes over: the member who joined first among those here, else anyone left. */
    private function successor(Jam $jam): ?User
    {
        $here = $jam->members()->wherePivot('last_seen_at', '>=', now()->subSeconds(self::ONLINE_SECONDS))->orderByPivot('joined_at')->first();

        return $here ?? $jam->members()->orderByPivot('joined_at')->first();
    }

    private function newCode(): string
    {
        do {
            $code = substr(str_shuffle(str_repeat('abcdefghjkmnpqrstuvwxyz23456789', 3)), 0, 6);
        } while (Jam::query()->where('code', $code)->exists());

        return $code;
    }

    private function ensureActive(Jam $jam): void
    {
        if (! $jam->isActive()) {
            abort(response()->json(['message' => 'This Jam has ended.', 'reason' => 'ended'], 410));
        }
    }

    public function ensureMember(Jam $jam, User $user): void
    {
        $this->ensureActive($jam);
        $updated = $jam->members()->updateExistingPivot($user->id, ['last_seen_at' => now()]);
        if (! $updated && ! $jam->members()->whereKey($user->id)->exists()) {
            $this->deny('not_member', 'Join the Jam first.');
        }
    }

    private function ensureControl(Jam $jam, User $user): void
    {
        if (! $jam->canControl($user)) {
            $this->deny('no_control', 'Only the host controls playback in this Jam.');
        }
    }

    private function ensureHost(Jam $jam, User $user): void
    {
        if ($jam->host_id !== $user->id) {
            $this->deny('not_host', 'Only the host can do that.');
        }
    }

    private function deny(string $reason, string $message): never
    {
        abort(response()->json(['message' => $message, 'reason' => $reason], 403));
    }
}
