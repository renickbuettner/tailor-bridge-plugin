<?php namespace Renick\TailorCompanion\Classes\Api;

use Renick\TailorCompanion\Classes\Pages\MenuTransformer;
use Renick\TailorCompanion\Classes\Pages\PagesFeature;
use Response;

/**
 * MenusController serves the theme's static menus (read-only in v1). Reached
 * only when StaticPagesEnabled passed.
 */
class MenusController
{
    /**
     * index lists menus with their content hashes.
     */
    public function index()
    {
        $menus = array_map(fn ($menu) => [
            'code' => $menu['code'],
            'name' => $menu['name'] ?? null,
            'content_hash' => $menu['contentHash'] ?? '',
        ], PagesFeature::gateway()->menus());

        return Response::json(['data' => $menus]);
    }

    /**
     * show returns one menu with its recursive item tree.
     */
    public function show(string $code)
    {
        $raw = PagesFeature::gateway()->menu($code);

        if ($raw === null) {
            return Response::json([
                'error' => ['code' => 'menu_not_found', 'message' => 'No static menu with this code.'],
            ], 404);
        }

        return Response::json([
            'data' => (new MenuTransformer)->menu($raw),
        ]);
    }
}
