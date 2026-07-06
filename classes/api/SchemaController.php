<?php namespace Renick\TailorCompanion\Classes\Api;

use Illuminate\Http\Request;
use Renick\TailorCompanion\Classes\Schema\SchemaSerializer;
use Response;

/**
 * SchemaController serves the aggregated, normalized Tailor schema.
 * ETag = global schema_version → clients poll cheaply with If-None-Match.
 */
class SchemaController
{
    public function __invoke(Request $request)
    {
        $schema = (new SchemaSerializer)->serialize();
        $etag = '"' . $schema['schema_version'] . '"';

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return Response::make('', 304, ['ETag' => $etag]);
        }

        return Response::json([
            'data' => $schema['blueprints'],
            'meta' => ['schema_version' => $schema['schema_version']],
        ])->header('ETag', $etag);
    }
}
