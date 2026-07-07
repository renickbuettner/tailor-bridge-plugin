<?php namespace Renick\TailorCompanion\Classes\Api;

use Renick\TailorCompanion\Classes\Sync\EntryTransformer;
use Response;
use Tailor\Classes\BlueprintIndexer;
use Tailor\Models\GlobalRecord;

/**
 * GlobalsController serves the single record of a Tailor global blueprint.
 * Globals have exactly one record — there is no list.
 */
class GlobalsController
{
    public function show(string $uuid)
    {
        if (!BlueprintIndexer::instance()->findGlobal($uuid)) {
            return Response::json([
                'error' => ['code' => 'unknown_global', 'message' => 'No global with this uuid.'],
            ], 404);
        }

        $record = GlobalRecord::findForGlobalUuid($uuid);

        return Response::json([
            'data' => (new EntryTransformer)->transformGlobal($record),
        ]);
    }
}
