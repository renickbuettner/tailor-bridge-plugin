<?php namespace Renick\TailorCompanion\Classes\Pages;

use Date;

/**
 * PageTransformer maps a gateway's raw page data into the app wire format.
 * The layout schema (from LayoutSchemaSerializer) drives the partition of the
 * view bag into implicit page props, editable field values, and untouched
 * `viewbag_extra` — so unknown keys round-trip losslessly, never dropped.
 */
class PageTransformer
{
    /**
     * @var string[] view-bag properties surfaced as top-level page attributes.
     */
    public const IMPLICIT_PROPS = [
        'title', 'url', 'layout', 'is_hidden', 'navigation_hidden', 'meta_title', 'meta_description',
    ];

    protected PlaceholderCodec $placeholders;

    public function __construct()
    {
        $this->placeholders = new PlaceholderCodec;
    }

    /**
     * treeNode builds the lightweight metadata for a page in the tree
     * (no field values — the app fetches those on demand per page).
     */
    public function treeNode(array $rawPage, array $children = []): array
    {
        $viewBag = $rawPage['viewBag'] ?? [];

        return [
            'file_name' => $rawPage['fileName'],
            'title' => (string) ($viewBag['title'] ?? ''),
            'url' => (string) ($viewBag['url'] ?? ''),
            'layout' => isset($viewBag['layout']) ? (string) $viewBag['layout'] : null,
            'is_hidden' => $this->toBool($viewBag['is_hidden'] ?? false),
            'navigation_hidden' => $this->toBool($viewBag['navigation_hidden'] ?? false),
            'content_hash' => (string) ($rawPage['contentHash'] ?? ''),
            'mtime' => $this->toIso($rawPage['mtime'] ?? null),
            'children' => $children,
        ];
    }

    /**
     * detail builds the full editable page, partitioning the view bag against
     * the given serialized layout schema.
     */
    public function detail(array $rawPage, array $layoutSchema): array
    {
        $viewBag = $rawPage['viewBag'] ?? [];

        [$syntaxNames, $placeholderNames, $hasMarkup] = $this->classifyLayout($layoutSchema);

        $fields = [];

        // Editable syntax field values live in the view bag.
        foreach ($syntaxNames as $name) {
            if (array_key_exists($name, $viewBag)) {
                $fields[$name] = $viewBag[$name];
            }
        }

        // Placeholder values live in the code section as {% put %} blocks.
        if ($placeholderNames) {
            $putValues = $this->placeholders->parsePutBlocks((string) ($rawPage['code'] ?? ''));
            foreach ($placeholderNames as $name) {
                $fields[LayoutSchemaSerializer::PLACEHOLDER_PREFIX . $name] = $putValues[$name] ?? '';
            }
        }

        if ($hasMarkup) {
            $fields['markup'] = (string) ($rawPage['markup'] ?? '');
        }

        // Everything the layout doesn't claim round-trips untouched.
        $claimed = array_merge(self::IMPLICIT_PROPS, $syntaxNames);
        $extra = array_diff_key($viewBag, array_flip($claimed));

        return [
            'file_name' => $rawPage['fileName'],
            'title' => (string) ($viewBag['title'] ?? ''),
            'url' => (string) ($viewBag['url'] ?? ''),
            'layout' => isset($viewBag['layout']) ? (string) $viewBag['layout'] : null,
            'is_hidden' => $this->toBool($viewBag['is_hidden'] ?? false),
            'navigation_hidden' => $this->toBool($viewBag['navigation_hidden'] ?? false),
            'meta_title' => isset($viewBag['meta_title']) ? (string) $viewBag['meta_title'] : null,
            'meta_description' => isset($viewBag['meta_description']) ? (string) $viewBag['meta_description'] : null,
            'fields' => $fields ?: new \stdClass,
            'viewbag_extra' => $extra ?: new \stdClass,
            'content_hash' => (string) ($rawPage['contentHash'] ?? ''),
            'mtime' => $this->toIso($rawPage['mtime'] ?? null),
        ];
    }

    /**
     * classifyLayout splits a serialized layout's fields into syntax-field
     * names, placeholder names (unprefixed), and whether a markup field exists.
     *
     * @return array{0: string[], 1: string[], 2: bool}
     */
    protected function classifyLayout(array $layoutSchema): array
    {
        $syntax = [];
        $placeholders = [];
        $hasMarkup = false;
        $prefix = LayoutSchemaSerializer::PLACEHOLDER_PREFIX;

        foreach ($layoutSchema['fields'] ?? [] as $field) {
            $name = $field['name'];

            if ($name === 'markup') {
                $hasMarkup = true;
            }
            elseif (str_starts_with($name, $prefix)) {
                $placeholders[] = substr($name, strlen($prefix));
            }
            elseif (!in_array($name, self::IMPLICIT_PROPS, true)) {
                $syntax[] = $name;
            }
        }

        return [$syntax, $placeholders, $hasMarkup];
    }

    /**
     * toBool coerces INI switch strings ("0"/"1") and real booleans.
     */
    protected function toBool($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * toIso formats a unix timestamp as ISO 8601, or null.
     */
    protected function toIso(?int $mtime): ?string
    {
        return $mtime ? Date::createFromTimestamp($mtime)->toIso8601String() : null;
    }
}
