<?php namespace Renick\TailorCompanion\Classes\Pages;

/**
 * MenuTransformer maps a gateway's raw static-menu data into the app wire
 * format. Menus are read-only in v1; unknown item keys (code, cmsPage,
 * attributes, viewBag, …) round-trip losslessly under `extra`.
 */
class MenuTransformer
{
    /**
     * @var string[] item keys surfaced as top-level attributes.
     */
    protected const KNOWN_ITEM_KEYS = ['title', 'type', 'reference', 'url'];

    /**
     * menu maps one menu with its recursive items.
     */
    public function menu(array $rawMenu): array
    {
        return [
            'code' => $rawMenu['code'],
            'name' => $rawMenu['name'] ?? null,
            'items' => $this->items($rawMenu['items'] ?? []),
        ];
    }

    /**
     * items recursively normalizes menu item arrays.
     */
    protected function items(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            $item = (array) $item;
            $extra = array_diff_key($item, array_flip(array_merge(self::KNOWN_ITEM_KEYS, ['items'])));

            $result[] = [
                'title' => isset($item['title']) ? (string) $item['title'] : null,
                'type' => isset($item['type']) ? (string) $item['type'] : null,
                'reference' => isset($item['reference']) ? (string) $item['reference'] : null,
                'url' => isset($item['url']) ? (string) $item['url'] : null,
                'extra' => $extra ?: new \stdClass,
                'items' => $this->items($item['items'] ?? []),
            ];
        }

        return $result;
    }
}
