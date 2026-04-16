<?php

use App\Http\Controllers\Api\V1\ActivateController;
use App\Http\Controllers\Api\V1\CheckController;
use App\Http\Controllers\Api\V1\DeactivateController;
use App\Http\Controllers\Api\V1\DownloadController;
use App\Http\Controllers\Api\V1\PingController;
use App\Http\Controllers\Api\V1\UpdateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Plugin-facing API — called by the Intake WordPress plugin
|--------------------------------------------------------------------------
|
| All routes are stateless and rate-limited. No authentication middleware
| is applied here — each controller validates its own inputs and keys.
|
| Rate limits (applied in RouteServiceProvider or here):
|   ping       — 10/hour per IP (free installs ping weekly, not constantly)
|   activate   — 20/hour per IP
|   deactivate — 20/hour per IP
|   check      — 120/hour per IP (cached server-side, plugin caches too)
|   update     — 60/hour per IP
|   download   — 10/hour per IP
|
*/

Route::prefix('v1')->group(function () {

    // Free install tracking — no key required
    Route::post('ping', PingController::class)
        ->middleware('throttle:10,60')
        ->name('api.v1.ping');

    // Premium license activation
    Route::post('activate', ActivateController::class)
        ->middleware('throttle:20,60')
        ->name('api.v1.activate');

    // Remove an activation (free up a site slot)
    Route::post('deactivate', DeactivateController::class)
        ->middleware('throttle:20,60')
        ->name('api.v1.deactivate');

    // Periodic validity + feature flag check
    Route::get('check', CheckController::class)
        ->middleware('throttle:120,60')
        ->name('api.v1.check');

    // WordPress update check (called by WP core via Update URI header)
    Route::get('update', UpdateController::class)
        ->middleware('throttle:60,60')
        ->name('api.v1.update');

    // Signed ZIP download for premium licensees
    Route::get('download/{license_key}', DownloadController::class)
        ->middleware('throttle:10,60')
        ->name('api.v1.download');
});
