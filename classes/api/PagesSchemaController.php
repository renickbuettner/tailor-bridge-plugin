<?php namespace Renick\TailorCompanion\Classes\Api;

use Illuminate\Http\Request;
use Renick\TailorCompanion\Classes\Pages\LayoutSchemaSerializer;
use Response;

/**
 * PagesSchemaController serves the pre-aggregated static page layout schema
 * (one form definition per layout, same wire field format as /schema).
 * ETag = pages_schema_version → clients poll cheaply with If-None-Match.
 */
class PagesSchemaController
{
    public function __invoke(Request $request)
    {
        $schema = (new LayoutSchemaSerializer)->serializeAll();
        $etag = '"' . $schema['pages_schema_version'] . '"';

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return Response::make('', 304, ['ETag' => $etag]);
        }

        return Response::json([
            'data' => ['layouts' => $schema['layouts']],
            'meta' => ['pages_schema_version' => $schema['pages_schema_version']],
        ])->header('ETag', $etag);
    }
}
