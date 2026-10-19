<?php

use App\Http\Controllers\Api\Admin\ChannelController;
use App\Http\Controllers\Api\Admin\ReportsController;
use App\Http\Controllers\Api\Admin\StatsController;
use App\Http\Controllers\Api\Admin\TelegramController;
use App\Http\Controllers\Api\Admin\TrackAdminController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HistoryController;
use App\Http\Controllers\Api\LikeController;
use App\Http\Controllers\Api\LyricsController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\OnlineController;
use App\Http\Controllers\Api\PlaylistController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RadioController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\TrackController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:20,1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);
});

Route::get('avatars/{user}', [ProfileController::class, 'avatar']);

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

    Route::get('home', [TrackController::class, 'home']);
    Route::get('library/sync', [TrackController::class, 'sync']);
    Route::get('tracks', [TrackController::class, 'index']);
    Route::get('tracks/{track}', [TrackController::class, 'show']);
    Route::get('tracks/{track}/lyrics', [LyricsController::class, 'track']);
    Route::get('lyrics/lookup', [LyricsController::class, 'lookup']);
    Route::get('artists', [TrackController::class, 'artists']);
    Route::get('artist', [TrackController::class, 'artist']);
    Route::get('search', SearchController::class);
    Route::get('radio', [RadioController::class, 'radio']);
    Route::get('mixes/daily', [RadioController::class, 'dailyMix']);

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

        Route::patch('tracks/{track}', [TrackAdminController::class, 'update']);
        Route::post('tracks/{track}/cover', [TrackAdminController::class, 'uploadCover']);
        Route::delete('tracks/{track}', [TrackAdminController::class, 'destroy']);
    });
});
