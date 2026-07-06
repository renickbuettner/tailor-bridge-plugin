<?php namespace Renick\TailorCompanion\Classes\Api;

use BackendAuth;
use Date;
use Renick\TailorCompanion\Classes\Schema\SchemaFingerprint;
use Response;
use System;
use System\Classes\VersionManager;

/**
 * PingController validates the token and returns server + schema metadata.
 * The app calls this at every sync start; `schema_version` drives the
 * schema-change flow (docs/architecture/04-sync-protocol.md).
 */
class PingController
{
    public function __invoke()
    {
        $user = BackendAuth::getUser();

        return Response::json([
            'data' => [
                'api_version' => 1,
                'plugin_version' => (string) VersionManager::instance()->getLatestVersion('Renick.TailorCompanion'),
                'october_version' => System::VERSION,
                'user' => [
                    'login' => $user->login,
                    'name' => trim($user->first_name . ' ' . $user->last_name),
                ],
                'schema_version' => (new SchemaFingerprint)->globalVersion(),
                'server_time' => Date::now()->toIso8601String(),
            ],
        ]);
    }
}
