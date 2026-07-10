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

    /**
     * Hard cap on nested sub-field recursion. A page-builder groups/form YAML
     * can reference itself (a block whose repeater points back at the same
     * groups file) — without a bound, resolving it recurses forever and the
     * request dies with an uncatchable fatal (stack overflow / FPM timeout,
     * empty-body 500). Legitimate block nesting is only a few levels deep, so
     * this cap never truncates real content; anything deeper round-trips
     * read-only (lossless), exactly like an unresolvable reference.
     */
    protected const MAX_NESTING = 10;

    protected PageFieldTypeMap $typeMap;
    protected PlaceholderCodec $placeholders;
    protected PageFormResolver $resolver;

    /** Current sub-field recursion depth (see MAX_NESTING). */
    protected int $nestingDepth = 0;

    /**
     * Stack of form/groups reference signatures currently being expanded, used
     * to detect self-referential builders (a `columns` block that can contain
     * `columns`). Re-expanding a reference already open on the path would inline
     * the same sub-schema at every level and blow the payload up
     * combinatorially — so such a back-reference is described once and flagged
     * `recursive` instead.
     *
     * @var array<int, string>
     */
    protected array $refStack = [];

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
        $normalized = $this->normalizeSyntaxConfig($kind, $type, $config);

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
        // Depth guard: past the cap, stop resolving deeper — the nested field
        // then has no sub-fields and is marked read-only (lossless round-trip),
        // breaking any self-referential groups/form cycle before it fatals.
        if ($this->nestingDepth >= self::MAX_NESTING) {
            return [];
        }

        $this->nestingDepth++;
        try {
            $result = [];
            foreach ($fieldMap as $subName => $subConfig) {
                $result[] = $this->serializeSyntaxField((string) $subName, (array) $subConfig);
            }

            return $result;
        }
        finally {
            $this->nestingDepth--;
        }
    }

    /**
     * refSignature returns the cycle-tracking key for a form/groups reference:
     * the string path for an external reference, or null for an inline
     * array/absent reference (which cannot be self-referential).
     *
     * @param mixed $ref
     */
    protected function refSignature($ref): ?string
    {
        return is_string($ref) && $ref !== '' ? $ref : null;
    }

    /**
     * expandWithRef serializes a sub-field map while marking $ref as "open" on
     * the path, so a nested field pointing back at the same reference is caught
     * as a cycle instead of re-expanded. A null $ref (inline fields) is expanded
     * normally — inline definitions can't reference themselves.
     */
    protected function expandWithRef(?string $ref, array $fieldMap): array
    {
        if ($ref !== null) {
            $this->refStack[] = $ref;
        }

        try {
            return $this->serializeSubFieldMap($fieldMap);
        }
        finally {
            if ($ref !== null) {
                array_pop($this->refStack);
            }
        }
    }

    /**
     * normalizeSyntaxConfig extracts the kind-relevant extras, mirroring the
     * Tailor schema config normalization the app already understands.
     */
    protected function normalizeSyntaxConfig(string $kind, string $type, array $config): array
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

        // Taglist (json kind): mirror the backend widget constraints so the
        // app's chip editor behaves identically (matches SchemaSerializer).
        if ($type === 'taglist') {
            $result['custom_tags'] = (bool) ($config['customTags'] ?? false);
            // useKey defaults to true in the widget, but custom tags force it
            // off (free-form values can't be option keys).
            $useKey = array_key_exists('useKey', $config) ? (bool) $config['useKey'] : true;
            $result['use_key'] = $result['custom_tags'] ? false : $useKey;
            if (isset($config['maxItems'])) {
                $result['max_items'] = (int) $config['maxItems'];
            }
            if (isset($config['separator'])) {
                $result['separator'] = (string) $config['separator'];
            }
        }

        if ($kind === 'media' && isset($config['mode'])) {
            $result['mode'] = (string) $config['mode'];
        }

        if ($kind === 'nested') {
            $cycled = false;

            // Inline sub-fields (from `{...}` tags inside the repeater body),
            // or an external/inline `form=` reference resolved to a field map.
            $formFields = (array) ($config['fields'] ?? []);
            $formRef = $this->refSignature($config['form'] ?? null);
            if (!$formFields && isset($config['form'])) {
                if ($formRef !== null && in_array($formRef, $this->refStack, true)) {
                    $cycled = true;                       // form ref already open on the path
                }
                else {
                    $formFields = $this->resolver->resolveForm($config['form']);
                }
            }
            if ($formFields) {
                $result['form'] = ['fields' => $this->expandWithRef($formRef, $formFields)];
            }

            // Groups / block types — inline map or external reference (each group
            // config may itself be a reference). Same wire shape as Tailor
            // (config.groups: {code: {name, fields}}).
            $groupsRef = $this->refSignature($config['groups'] ?? null);
            if (isset($config['groups'])) {
                if ($groupsRef !== null && in_array($groupsRef, $this->refStack, true)) {
                    $cycled = true;                       // groups ref already open on the path
                }
                else {
                    $groups = [];
                    foreach ($this->resolver->resolveGroups($config['groups']) as $code => $group) {
                        $groups[$code] = [
                            'name' => $group['name'],
                            'fields' => $this->expandWithRef($groupsRef, $group['fields']),
                        ];
                    }
                    if ($groups) {
                        $result['groups'] = $groups;
                    }
                }
            }

            // A repeater that refers back to a builder it is already inside is
            // recursive. It is described once (fields left empty here → the
            // field serializes read-only, lossless); the flag lets the client
            // reuse the ancestor schema to keep editing deeper if it chooses.
            if ($cycled) {
                $result['recursive'] = true;
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
