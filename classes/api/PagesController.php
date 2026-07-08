<?php namespace Renick\TailorCompanion\Classes\Api;

use Illuminate\Http\Request;
use Renick\TailorCompanion\Classes\Middleware\TokenAuth;
use Renick\TailorCompanion\Classes\Pages\LayoutSchemaSerializer;
use Renick\TailorCompanion\Classes\Pages\PagesFeature;
use Renick\TailorCompanion\Classes\Pages\PageTransformer;
use Renick\TailorCompanion\Classes\Pages\PageWriter;
use Response;

/**
 * PagesController serves the static page tree and single pages, and applies
 * edits to existing pages. Reached only when StaticPagesEnabled passed, so the
 * feature is guaranteed available here.
 */
class PagesController
{
    /**
     * tree returns the nested page hierarchy with per-page metadata + hashes.
     */
    public function tree()
    {
        $gateway = PagesFeature::gateway();
        $transformer = new PageTransformer;

        $build = function (array $nodes) use (&$build, $gateway, $transformer) {
            $result = [];
            foreach ($nodes as $node) {
                $raw = $gateway->page($node['fileName']);
                if ($raw === null) {
                    continue;
                }
                $result[] = $transformer->treeNode($raw, $build($node['children'] ?? []));
            }
            return $result;
        };

        return Response::json([
            'data' => ['pages' => $build($gateway->tree())],
            'meta' => [
                'pages_schema_version' => (new LayoutSchemaSerializer)->schemaVersion(),
            ],
        ]);
    }

    /**
     * show returns one page in full editable form.
     */
    public function show(string $fileName)
    {
        $raw = PagesFeature::gateway()->page($fileName);

        if ($raw === null) {
            return $this->notFound();
        }

        return Response::json([
            'data' => (new PageTransformer)->detail($raw, $this->layoutSchemaFor($raw)),
        ]);
    }

    /**
     * update applies field changes to an existing page.
     */
    public function update(Request $request, string $fileName)
    {
        $raw = PagesFeature::gateway()->page($fileName);

        if ($raw === null) {
            return $this->notFound();
        }

        $token = $request->attributes->get(TokenAuth::REQUEST_TOKEN_KEY);

        $result = (new PageWriter)->apply(
            $raw,
            $this->layoutSchemaFor($raw),
            (array) $request->input('fields', []),
            $request->input('viewbag_extra'),
            $request->input('base_hash'),
            $token
        );

        if ($result['status'] === 'conflict') {
            return Response::json([
                'error' => ['code' => 'conflict', 'message' => 'The page changed on the server since it was loaded.'],
                'server_state' => $result['page'],
            ], 409);
        }

        return Response::json([
            'data' => $result['page'],
            'meta' => ['warnings' => $result['warnings']],
        ]);
    }

    /**
     * layoutSchemaFor resolves the serialized layout matching a page's layout.
     * Falls back to an implicit-only schema when the layout is unknown, so all
     * unrecognized view-bag keys round-trip as viewbag_extra.
     */
    protected function layoutSchemaFor(array $rawPage): array
    {
        $layoutName = $rawPage['viewBag']['layout'] ?? null;

        foreach ((new LayoutSchemaSerializer)->serializeAll()['layouts'] as $layout) {
            if ($layout['file_name'] === $layoutName) {
                return $layout;
            }
        }

        return ['file_name' => (string) $layoutName, 'name' => (string) $layoutName, 'use_content' => false, 'fields' => []];
    }

    protected function notFound()
    {
        return Response::json([
            'error' => ['code' => 'page_not_found', 'message' => 'No static page with this file name.'],
        ], 404);
    }
}
