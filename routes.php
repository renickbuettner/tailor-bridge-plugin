<?php

use Renick\TailorCompanion\Classes\Api\BatchController;
use Renick\TailorCompanion\Classes\Api\ChangesController;
use Renick\TailorCompanion\Classes\Api\EntriesController;
use Renick\TailorCompanion\Classes\Api\FilesController;
use Renick\TailorCompanion\Classes\Api\GlobalsController;
use Renick\TailorCompanion\Classes\Api\IssueTokenController;
use Renick\TailorCompanion\Classes\Api\LogsController;
use Renick\TailorCompanion\Classes\Api\MenusController;
use Renick\TailorCompanion\Classes\Api\PagesController;
use Renick\TailorCompanion\Classes\Api\PagesSchemaController;
use Renick\TailorCompanion\Classes\Api\PingController;
use Renick\TailorCompanion\Classes\Api\RecordsController;
use Renick\TailorCompanion\Classes\Api\SchemaController;
use Renick\TailorCompanion\Classes\Api\SitesController;
use Renick\TailorCompanion\Classes\Api\VersionController;
use Renick\TailorCompanion\Classes\Middleware\ForceJson;
use Renick\TailorCompanion\Classes\Middleware\SiteContext;
use Renick\TailorCompanion\Classes\Middleware\StaticPagesEnabled;
use Renick\TailorCompanion\Classes\Middleware\TokenAuth;

/*
 * Companion app API — the only interface the native app talks to.
 * Version prefix /v1 so breaking changes can coexist later.
 */
Route::group([
    'prefix' => 'api/tailor-companion/v1',
    'middleware' => [ForceJson::class],
], function () {

    // Manual pairing with credentials — hard throttle against brute force.
    // The explicit prefix ("auth") keeps this counter separate: Laravel's
    // default throttle signature is domain+IP only, so without prefixes this
    // route would share its 5/min budget with regular API traffic — a single
    // sync burst would lock out pairing from the same network for a minute.
    Route::post('auth/token', IssueTokenController::class)
        ->middleware('throttle:5,1,auth');

    // Token-authenticated endpoints — throttle FIRST so invalid-token
    // hammering is rate-limited before it hits the DB lookup.
    Route::group(['middleware' => ['throttle:120,1,api', TokenAuth::class]], function () {
        Route::get('ping', PingController::class);
        // Dependency-free deploy marker — confirms which plugin code is live
        // even when /ping's optional subsystems are misconfigured.
        Route::get('version', VersionController::class);
        Route::get('sites', SitesController::class);
        // Tail of the application error log (console view in the app)
        Route::get('logs', LogsController::class);

        // Site-scoped endpoints (X-Tailor-Site header selects the site)
        Route::group(['middleware' => [SiteContext::class]], function () {
            Route::get('schema', SchemaController::class);
            Route::get('entries/{uuid}', [EntriesController::class, 'index']);
            Route::get('entries/{uuid}/{id}', [EntriesController::class, 'show'])->whereNumber('id');
            // Live search over a recordfinder field's target model
            Route::get('records/{uuid}/{field}', [RecordsController::class, 'index']);
            Route::get('globals/{uuid}', [GlobalsController::class, 'show']);
            Route::get('sync/changes', ChangesController::class);
            Route::post('sync/batch', BatchController::class);
            Route::post('files', [FilesController::class, 'upload']);
            Route::get('files/{id}', [FilesController::class, 'download'])->whereNumber('id');

            // Static pages (optional RainLab.Pages integration) — the whole
            // group answers 404 feature_unavailable when it's not usable.
            Route::group(['middleware' => [StaticPagesEnabled::class]], function () {
                Route::get('pages/schema', PagesSchemaController::class);
                Route::get('pages/tree', [PagesController::class, 'tree']);
                Route::get('pages/file/{fileName}', [PagesController::class, 'show'])
                    ->where('fileName', '[A-Za-z0-9\-_.]+');
                Route::patch('pages/file/{fileName}', [PagesController::class, 'update'])
                    ->where('fileName', '[A-Za-z0-9\-_.]+');
                Route::get('pages/menus', [MenusController::class, 'index']);
                Route::get('pages/menus/{code}', [MenusController::class, 'show'])
                    ->where('code', '[A-Za-z0-9\-_.]+');
            });
        });
    });
});
