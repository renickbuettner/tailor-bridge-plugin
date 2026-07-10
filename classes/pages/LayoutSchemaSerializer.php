<?php namespace Renick\TailorCompanion\Classes\Pages;

use October\Rain\Parse\Syntax\Parser as SyntaxParser;

/**
 * LayoutSchemaSerializer pre-aggregates static page layouts into the same
 * wire field-schema the app renders for Tailor blueprints, so page editors
 * come for free on the client. Per layout it emits:
 *
 *  - implicit page fields (title, url, layout, hidden flags, SEO meta),
 *  - the layout's October syntax fields ({variable}/{repeater}/… tags,
 *    parsed with the exact SyntaxParser call RainLab makes),
 *  - a `markup` content field when the layout's staticPage component says
 *    `useContent`,
 *  - one field per editable `{% placeholder %}` (wired as
 *    `placeholder:<name>` — `:` is illegal in real field names, so this can
 *    never collide with a syntax field).
 *
 * The heavy lifting takes raw strings in and arrays out, so all of it is
 * unit-testable without RainLab.Pages installed.
 */
class LayoutSchemaSerializer
{
    public const CONTENT_TAB = 'Content';
    public const SEO_TAB = 'SEO';
    public const PLACEHOLDER_PREFIX = 'placeholder:';

    protected PageFieldTypeMap $typeMap;
    protected PlaceholderCodec $placeholders;
    protected PageFormResolver $resolver;

    public function __construct(?PageFormResolver $resolver = null)
    {
        $this->typeMap = new PageFieldTypeMap;
        $this->placeholders = new PlaceholderCodec;
        $this->resolver = $resolver ?? new PageFormResolver;
    }

    /**
     * serializeAll aggregates every static-page layout of the active theme.
     *
     * @return array{layouts: array, pages_schema_version: string}
     */
    public function serializeAll(): array
    {
        $fingerprint = new PagesSchemaFingerprint;
        $layouts = [];

        // Let the resolver find theme-relative form/groups references.
        $this->resolver->setThemePath(PagesFeature::gateway()->themePath());

        foreach (PagesFeature::gateway()->layouts() as $raw) {
            $layout = $this->serializeLayout(
                $raw['fileName'],
                $raw['description'] ?? null,
                (bool) ($raw['useContent'] ?? true),
                (string) ($raw['markup'] ?? '')
            );
            $layout['fingerprint'] = $fingerprint->forLayout($layout);
            $layouts[] = $layout;
        }

        return [
            'layouts' => $layouts,
            'pages_schema_version' => $fingerprint->globalVersion($layouts),
        ];
    }

    /**
     * schemaVersion returns only the global pages schema fingerprint (used
     * by /ping).
     */
    public function schemaVersion(): string
    {
        return $this->serializeAll()['pages_schema_version'];
    }

    /**
     * serializeLayout builds the wire structure for one layout from raw
     * strings — no RainLab access.
     */
    public function serializeLayout(string $fileName, ?string $description, bool $useContent, string $markup): array
    {
        $fields = $this->implicitPageFields();

        foreach ($this->syntaxFields($markup) as $name => $config) {
            $fields[] = $this->serializeSyntaxField($name, $config);
        }

        if ($useContent) {
            $fields[] = $this->field('markup', 'richeditor', 'scalar', 'Content', [
                'tab' => self::CONTENT_TAB,
                'implicit' => true,
            ]);
        }

        foreach ($this->placeholders->declarations($markup) as $name => $info) {
            if ($info['ignore']) {
                continue;
            }

            $fields[] = $this->field(
                self::PLACEHOLDER_PREFIX . $name,
                $info['type'] === 'text' ? 'textarea' : 'richeditor',
                'scalar',
                $info['title'],
                [
                    'tab' => self::CONTENT_TAB,
                    'config' => ['placeholder' => true, 'placeholder_type' => $info['type']],
                ]
            );
        }

        return [
            'file_name' => $fileName,
            'name' => $description !== null && strlen($description) ? $description : $fileName,
            'use_content' => $useContent,
            'fields' => $fields,
        ];
    }

    /**
     * syntaxFields parses the layout's {variable} tags. Unparseable markup
     * yields no fields instead of failing the whole schema.
     */
    protected function syntaxFields(string $markup): array
    {
        try {
            // Identical call to RainLab's Page::listLayoutSyntaxFields()
            return SyntaxParser::parse($markup, ['tagPrefix' => 'page:'])->toEditor();
        }
        catch (\Throwable $ex) {
            return [];
        }
    }

    /**
     * serializeSyntaxField maps one parsed syntax field to the wire format.
     */
    protected function serializeSyntaxField(string $name, array $config): array
    {
        $type = (string) ($config['type'] ?? 'text');
        $kind = $this->typeMap->kindFor($type);
        $normalized = $this->normalizeSyntaxConfig($kind, $config);

        // A repeater whose sub-fields resolve to nothing — neither inline nor an
        // external form nor groups — has no schema the app can build an editor
        // from. Mark it read-only so the value round-trips losslessly instead of
        // showing a broken/empty editor that could wipe the content.
        $readonly = $this->typeMap->isReadonly($type)
            || ($kind === 'nested'
                && empty($normalized['form']['fields'])
                && empty($normalized['groups']));

        return $this->field($name, $type, $kind, (string) ($config['label'] ?? $name), [
            'tab' => $config['tab'] ?? null,
            'comment' => $config['comment'] ?? null,
            'readonly' => $readonly,
            'custom' => $kind === 'unknown',
            'config' => $normalized,
        ]);
    }

    /**
     * serializeSubFieldMap serializes a `[name => config]` sub-field map into the
     * wire field list, recursively (a sub-field may itself be a nested repeater).
     */
    protected function serializeSubFieldMap(array $fieldMap): array
    {
        $result = [];
        foreach ($fieldMap as $subName => $subConfig) {
            $result[] = $this->serializeSyntaxField((string) $subName, (array) $subConfig);
        }

        return $result;
    }

    /**
     * normalizeSyntaxConfig extracts the kind-relevant extras, mirroring the
     * Tailor schema config normalization the app already understands.
     */
    protected function normalizeSyntaxConfig(string $kind, array $config): array
    {
        $result = [];

        if (isset($config['options']) && is_array($config['options'])) {
            $options = $config['options'];
            if (array_is_list($options)) {
                $options = array_combine($options, $options);
            }
            $result['options'] = $options;
        }

        if (isset($config['default']) && $config['default'] !== '') {
            $result['default'] = $config['default'];
        }

        if ($kind === 'media' && isset($config['mode'])) {
            $result['mode'] = (string) $config['mode'];
        }

        if ($kind === 'nested') {
            // Inline sub-fields (from `{...}` tags inside the repeater body),
            // or an external/inline `form=` reference resolved to a field map.
            $formFields = (array) ($config['fields'] ?? []);
            if (!$formFields && isset($config['form'])) {
                $formFields = $this->resolver->resolveForm($config['form']);
            }
            if ($formFields) {
                $result['form'] = ['fields' => $this->serializeSubFieldMap($formFields)];
            }

            // Groups / block types — inline map or external reference (each group
            // config may itself be a reference). Same wire shape as Tailor
            // (config.groups: {code: {name, fields}}).
            if (isset($config['groups'])) {
                $groups = [];
                foreach ($this->resolver->resolveGroups($config['groups']) as $code => $group) {
                    $groups[$code] = [
                        'name' => $group['name'],
                        'fields' => $this->serializeSubFieldMap($group['fields']),
                    ];
                }
                if ($groups) {
                    $result['groups'] = $groups;
                }
            }

            if (isset($config['prompt'])) {
                $result['prompt'] = (string) $config['prompt'];
            }
            if (isset($config['titleFrom'])) {
                $result['title_from'] = (string) $config['titleFrom'];
            }
            if (isset($config['maxItems'])) {
                $result['max_items'] = (int) $config['maxItems'];
            }
        }

        return $result;
    }

    /**
     * implicitPageFields are the view-bag properties every static page has.
     * `url` and `layout` are readonly in v1: renames cascade into file names
     * and child URLs, layout changes swap the whole form schema.
     */
    protected function implicitPageFields(): array
    {
        return [
            $this->field('title', 'text', 'scalar', 'Title', ['required' => true, 'implicit' => true]),
            $this->field('url', 'text', 'scalar', 'URL', ['readonly' => true, 'implicit' => true]),
            $this->field('layout', 'text', 'scalar', 'Layout', ['readonly' => true, 'implicit' => true]),
            $this->field('is_hidden', 'switch', 'scalar', 'Hidden', [
                'comment' => 'Hidden pages are only visible to logged-in backend users.',
                'implicit' => true,
            ]),
            $this->field('navigation_hidden', 'switch', 'scalar', 'Hide in navigation', ['implicit' => true]),
            $this->field('meta_title', 'text', 'scalar', 'Meta title', ['tab' => self::SEO_TAB, 'implicit' => true]),
            $this->field('meta_description', 'textarea', 'scalar', 'Meta description', ['tab' => self::SEO_TAB, 'implicit' => true]),
        ];
    }

    /**
     * field assembles one wire field with the shared defaults.
     */
    protected function field(string $name, string $type, string $kind, string $label, array $overrides = []): array
    {
        $config = $overrides['config'] ?? [];

        return [
            'name' => $name,
            'type' => $type,
            'kind' => $kind,
            'label' => $label,
            'tab' => $overrides['tab'] ?? null,
            'span' => 'full',
            'comment' => $overrides['comment'] ?? null,
            'hidden' => false,
            'required' => (bool) ($overrides['required'] ?? false),
            'readonly' => (bool) ($overrides['readonly'] ?? false),
            'custom' => (bool) ($overrides['custom'] ?? false),
            'implicit' => (bool) ($overrides['implicit'] ?? false),
            // Empty PHP arrays JSON-encode as [] — clients expect an object
            'config' => $config ?: new \stdClass,
        ];
    }
}
