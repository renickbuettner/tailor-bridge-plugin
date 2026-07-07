<?php

use Renick\TailorCompanion\Classes\Api\BatchController;
use Renick\TailorCompanion\Classes\Api\ChangesController;
use Renick\TailorCompanion\Classes\Api\EntriesController;
use Renick\TailorCompanion\Classes\Api\FilesController;
use Renick\TailorCompanion\Classes\Api\GlobalsController;
use Renick\TailorCompanion\Classes\Api\IssueTokenController;
use Renick\TailorCompanion\Classes\Api\PingController;
use Renick\TailorCompanion\Classes\Api\SchemaController;
use Renick\TailorCompanion\Classes\Api\SitesController;
use Renick\TailorCompanion\Classes\Middleware\ForceJson;
use Renick\TailorCompanion\Classes\Middleware\SiteContext;
use Renick\TailorCompanion\Classes\Middleware\TokenAuth;

/*
 * Companion app API — the only interface the native app talks to.
 * Version prefix /v1 so breaking changes can coexist later.
 */
Route::group([
    'prefix' => 'api/tailor-companion/v1',
    'middleware' => [ForceJson::class],
], function () {

    // Manual pairing with credentials — hard throttle against brute force
    Route::post('auth/token', IssueTokenController::class)
        ->middleware('throttle:5,1');

    // Token-authenticated endpoints — throttle FIRST so invalid-token
    // hammering is rate-limited before it hits the DB lookup.
    Route::group(['middleware' => ['throttle:120,1', TokenAuth::class]], function () {
        Route::get('ping', PingController::class);
        Route::get('sites', SitesController::class);

        // Site-scoped endpoints (X-Tailor-Site header selects the site)
        Route::group(['middleware' => [SiteContext::class]], function () {
            Route::get('schema', SchemaController::class);
            Route::get('entries/{uuid}', [EntriesController::class, 'index']);
            Route::get('entries/{uuid}/{id}', [EntriesController::class, 'show'])->whereNumber('id');
            Route::get('globals/{uuid}', [GlobalsController::class, 'show']);
            Route::get('sync/changes', ChangesController::class);
            Route::post('sync/batch', BatchController::class);
            Route::post('files', [FilesController::class, 'upload']);
            Route::get('files/{id}', [FilesController::class, 'download'])->whereNumber('id');
        });
    });
});
