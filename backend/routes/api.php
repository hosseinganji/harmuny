<?php

use App\Http\Controllers\Api\Admin\ChannelController;
use App\Http\Controllers\Api\Admin\DiscoveryController;
use App\Http\Controllers\Api\Admin\UploadController;
use App\Http\Controllers\Api\ArtistFollowController;
use App\Http\Controllers\Api\SongRequestController;
use App\Http\Controllers\Api\Admin\ReportsController;
use App\Http\Controllers\Api\Admin\StatsController;
use App\Http\Controllers\Api\Admin\TelegramController;
use App\Http\Controllers\Api\Admin\TrackAdminController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HistoryController;
use App\Http\Controllers\Api\JamController;
use App\Http\Controllers\Api\LikeController;
use App\Http\Controllers\Api\LyricsController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\OnlineController;
use App\Http\Controllers\Api\PlaylistController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PushController;
use App\Http\Controllers\Api\RadioController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\TrackController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:20,1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);
});

Route::get('avatars/{user}', [ProfileController::class, 'avatar']);
// Jams line their clocks up with this one; it answers without signing in, to be as quick as possible.
Route::get('jams/clock', [JamController::class, 'clock']);

// Media URLs authenticate with a bearer token or a ?k= media key.
Route::middleware('media')->group(function () {
    Route::get('tracks/{track}/stream', [MediaController::class, 'stream']);
    Route::get('tracks/{track}/cover', [MediaController::class, 'cover']);
    Route::get('playlists/{playlist}/cover', [PlaylistController::class, 'cover']);
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::patch('me', [ProfileController::class, 'update']);
    Route::post('me/avatar', [ProfileController::class, 'uploadAvatar']);
    Route::delete('me/avatar', [ProfileController::class, 'deleteAvatar']);
    Route::put('me/password', [ProfileController::class, 'changePassword']);
    Route::get('me/sessions', [ProfileController::class, 'sessions']);
    Route::delete('me/sessions/{id}', [ProfileController::class, 'revokeSession']);
    Route::get('me/stats', [ProfileController::class, 'stats']);
    Route::get('online', OnlineController::class);
    Route::post('presence', [OnlineController::class, 'presence']);
    Route::get('push/key', [PushController::class, 'key']);
    Route::post('push/subscriptions', [PushController::class, 'subscribe']);
    Route::delete('push/subscriptions', [PushController::class, 'unsubscribe']);

    Route::get('home', [TrackController::class, 'home']);
    Route::get('library/sync', [TrackController::class, 'sync']);
    Route::get('tracks', [TrackController::class, 'index']);
    Route::get('tracks/{track}', [TrackController::class, 'show']);
    Route::get('tracks/{track}/details', [TrackController::class, 'details']);
    Route::get('tracks/{track}/lyrics', [LyricsController::class, 'track']);
    Route::get('lyrics/lookup', [LyricsController::class, 'lookup']);
    Route::get('artists', [TrackController::class, 'artists']);
    Route::get('artist', [TrackController::class, 'artist']);
    Route::get('search', SearchController::class);
    Route::get('radio', [RadioController::class, 'radio']);
    Route::get('mixes/daily', [RadioController::class, 'dailyMix']);
    Route::get('mixes/release-radar', [RadioController::class, 'releaseRadar']);
    Route::get('follows', [ArtistFollowController::class, 'index']);
    Route::post('follows', [ArtistFollowController::class, 'store']);
    Route::delete('follows', [ArtistFollowController::class, 'destroy']);
    Route::get('requests', [SongRequestController::class, 'index']);
    Route::post('requests', [SongRequestController::class, 'store'])->middleware('throttle:20,1');
    Route::patch('requests/{songRequest}', [SongRequestController::class, 'update']);
    Route::delete('requests/{songRequest}', [SongRequestController::class, 'destroy']);
    Route::post('smart-shuffle', [RadioController::class, 'smartShuffle']);

    // Jams: listening together.
    Route::get('jams/current', [JamController::class, 'current']);
    Route::post('jams', [JamController::class, 'start']);
    Route::get('jams/{jam}', [JamController::class, 'show']);
    Route::patch('jams/{jam}', [JamController::class, 'update']);
    Route::delete('jams/{jam}', [JamController::class, 'end']);
    Route::post('jams/{jam}/join', [JamController::class, 'join']);
    Route::post('jams/{jam}/leave', [JamController::class, 'leave']);
    Route::post('jams/{jam}/ping', [JamController::class, 'ping']);
    Route::post('jams/{jam}/playback', [JamController::class, 'playback']);
    Route::post('jams/{jam}/queue', [JamController::class, 'add']);
    Route::put('jams/{jam}/queue', [JamController::class, 'reorder']);
    Route::delete('jams/{jam}/queue/{uid}', [JamController::class, 'remove']);
    Route::post('jams/{jam}/react', [JamController::class, 'react'])->middleware('throttle:90,1');
    Route::post('jams/{jam}/invite', [JamController::class, 'invite'])->middleware('throttle:20,1');
    Route::delete('jams/{jam}/members/{user}', [JamController::class, 'kick']);

    Route::get('likes', [LikeController::class, 'index']);
    Route::post('tracks/{track}/like', [LikeController::class, 'store']);
    Route::delete('tracks/{track}/like', [LikeController::class, 'destroy']);

    Route::get('history', [HistoryController::class, 'index']);
    Route::post('plays', [HistoryController::class, 'store']);
    Route::delete('history', [HistoryController::class, 'clear']);

    Route::get('playlists', [PlaylistController::class, 'index']);
    Route::post('playlists', [PlaylistController::class, 'store']);
    Route::get('playlists/{playlist}', [PlaylistController::class, 'show']);
    Route::patch('playlists/{playlist}', [PlaylistController::class, 'update']);
    Route::delete('playlists/{playlist}', [PlaylistController::class, 'destroy']);
    Route::post('playlists/{playlist}/tracks', [PlaylistController::class, 'addTracks']);
    Route::delete('playlists/{playlist}/tracks/{entry}', [PlaylistController::class, 'removeTrack']);
    Route::put('playlists/{playlist}/order', [PlaylistController::class, 'reorder']);
    Route::put('playlists/{playlist}/collaborators', [PlaylistController::class, 'setCollaborators']);
    Route::delete('playlists/{playlist}/collaborators/me', [PlaylistController::class, 'leave']);
    Route::get('members', [PlaylistController::class, 'members']);
    Route::post('playlists/{playlist}/cover', [PlaylistController::class, 'uploadCover']);
    Route::delete('playlists/{playlist}/cover', [PlaylistController::class, 'deleteCover']);

    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('stats', StatsController::class);
        Route::get('reports', ReportsController::class);

        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::patch('users/{user}', [UserController::class, 'update']);
        Route::delete('users/{user}', [UserController::class, 'destroy']);

        Route::get('telegram', [TelegramController::class, 'status']);
        Route::post('telegram/credentials', [TelegramController::class, 'credentials']);
        Route::post('telegram/phone', [TelegramController::class, 'sendCode']);
        Route::post('telegram/code', [TelegramController::class, 'submitCode']);
        Route::post('telegram/password', [TelegramController::class, 'submitPassword']);
        Route::post('telegram/logout', [TelegramController::class, 'logout']);
        Route::get('telegram/dialogs', [TelegramController::class, 'dialogs']);
        Route::post('telegram/mode', [TelegramController::class, 'setMode']);
        Route::post('telegram/bot', [TelegramController::class, 'botToken']);
        Route::post('telegram/bot/check', [TelegramController::class, 'botCheckStart']);
        Route::post('telegram/bot/disconnect', [TelegramController::class, 'botDisconnect']);

        Route::get('channels', [ChannelController::class, 'index']);
        Route::post('channels', [ChannelController::class, 'store']);
        Route::patch('channels/{channel}', [ChannelController::class, 'update']);
        Route::delete('channels/{channel}', [ChannelController::class, 'destroy']);
        Route::post('channels/{channel}/sync', [ChannelController::class, 'sync']);

        Route::post('uploads', [UploadController::class, 'store']);
        Route::get('discovery', [DiscoveryController::class, 'status']);
        Route::put('discovery/channels', [DiscoveryController::class, 'channels']);
        Route::post('discovery/run', [DiscoveryController::class, 'run']);

        Route::patch('tracks/{track}', [TrackAdminController::class, 'update']);
        Route::post('tracks/{track}/cover', [TrackAdminController::class, 'uploadCover']);
        Route::delete('tracks/{track}', [TrackAdminController::class, 'destroy']);
    });
});
