<?php namespace Renick\TailorCompanion\Classes\Pages;

use BackendAuth;
use Renick\TailorCompanion\Models\AccessToken;
use Renick\TailorCompanion\Models\AuditLog;

/**
 * PageWriter applies field changes to an existing static page. It mirrors
 * EntryWriter's contract:
 *
 * - Optimistic concurrency via base_hash (sha256 of the file the edit was
 *   based on); a mismatch → `conflict` with the current server page. A missing
 *   base hash force-applies. Server never silently overwrites.
 * - url and layout are read-only in v1; unknown/read-only fields are never
 *   applied silently — they come back as warnings WITH the rejected value.
 * - Saving always goes through the gateway (the RainLab model), never raw file
 *   writes; unknown view-bag keys round-trip losslessly via viewbag_extra.
 * - Every applied change writes an audit log entry (the file name lives in the
 *   diff, since the audit table keys on integer record ids).
 */
class PageWriter
{
    public const AUDIT_BLUEPRINT = 'static-page';

    /**
     * @var string[] view-bag props the app may write (url/layout stay readonly)
     */
    protected const IMPLICIT_WRITABLE = ['title', 'is_hidden', 'navigation_hidden', 'meta_title', 'meta_description'];
    protected const BOOL_PROPS = ['is_hidden', 'navigation_hidden'];

    protected PlaceholderCodec $placeholders;
    protected PageTransformer $transformer;

    public function __construct()
    {
        $this->placeholders = new PlaceholderCodec;
        $this->transformer = new PageTransformer;
    }

    /**
     * apply changes to a page. Never throws for data-level problems.
     *
     * @param array $rawPage       gateway page data (viewBag, markup, code, contentHash…)
     * @param array $layoutSchema  serialized layout (LayoutSchemaSerializer)
     * @param array $fields        changed field values keyed by field name
     * @param mixed $viewbagExtra  replacement lossless extras (null = leave as-is)
     * @param ?string $baseHash    content hash the edit was based on (null = force)
     *
     * @return array{status: string, page: array, warnings: array}
     */
    public function apply(array $rawPage, array $layoutSchema, array $fields, $viewbagExtra = null, ?string $baseHash = null, ?AccessToken $token = null): array
    {
        if ($baseHash !== null && $baseHash !== ($rawPage['contentHash'] ?? '')) {
            return [
                'status' => 'conflict',
                'page' => $this->transformer->detail($rawPage, $layoutSchema),
                'warnings' => [],
            ];
        }

        $plan = $this->planWrites($rawPage, $layoutSchema, $fields);

        $viewBag = $this->mergeViewBag($rawPage, $layoutSchema, $plan['viewBag'], $viewbagExtra);
        $code = $plan['placeholdersTouched']
            ? $this->placeholders->renderPutBlocks($plan['placeholders'])
            : null;
        $markup = $plan['markupTouched'] ? $plan['markup'] : null;

        $fresh = PagesFeature::gateway()->updatePage($rawPage['fileName'], $viewBag, $markup, $code);

        if ($plan['diff']) {
            $this->audit($rawPage['fileName'], $plan['diff'], $token);
        }

        return [
            'status' => 'ok',
            'page' => $this->transformer->detail($fresh, $layoutSchema),
            'warnings' => $plan['warnings'],
        ];
    }

    /**
     * planWrites splits the payload into applicable writes and warnings, using
     * the layout schema as the source of truth for what is editable.
     */
    protected function planWrites(array $rawPage, array $layoutSchema, array $fields): array
    {
        [$syntax, $placeholderNames, $hasMarkup] = $this->classifyLayout($layoutSchema);
        $readonlySyntax = $this->readonlyFieldNames($layoutSchema);

        $currentViewBag = $rawPage['viewBag'] ?? [];
        $currentPlaceholders = $this->placeholders->parsePutBlocks((string) ($rawPage['code'] ?? ''));

        $plan = [
            'viewBag' => [],
            'placeholders' => $currentPlaceholders,
            'placeholdersTouched' => false,
            'markup' => (string) ($rawPage['markup'] ?? ''),
            'markupTouched' => false,
            'warnings' => [],
            'diff' => [],
        ];

        foreach ($fields as $name => $value) {
            $name = (string) $name;

            // Read-only implicit props
            if ($name === 'url' || $name === 'layout') {
                $plan['warnings'][] = $this->warning($name, 'readonly_field', 'This field is read-only.', $value);
                continue;
            }

            if (in_array($name, self::IMPLICIT_WRITABLE, true)) {
                $stored = $this->storeValue($name, $value);
                $plan['viewBag'][$name] = $stored;
                $this->recordDiff($plan, $name, $currentViewBag[$name] ?? null, $stored);
                continue;
            }

            // Placeholder value
            if (str_starts_with($name, LayoutSchemaSerializer::PLACEHOLDER_PREFIX)) {
                $ph = substr($name, strlen(LayoutSchemaSerializer::PLACEHOLDER_PREFIX));
                if (!in_array($ph, $placeholderNames, true)) {
                    $plan['warnings'][] = $this->warning($name, 'unknown_field', 'The layout does not define this placeholder.', $value);
                    continue;
                }
                $plan['placeholders'][$ph] = (string) $value;
                $plan['placeholdersTouched'] = true;
                $this->recordDiff($plan, $name, $currentPlaceholders[$ph] ?? null, (string) $value);
                continue;
            }

            // Markup content
            if ($name === 'markup') {
                if (!$hasMarkup) {
                    $plan['warnings'][] = $this->warning($name, 'unknown_field', 'This layout has no content section.', $value);
                    continue;
                }
                $plan['markup'] = (string) $value;
                $plan['markupTouched'] = true;
                $this->recordDiff($plan, $name, $rawPage['markup'] ?? null, (string) $value);
                continue;
            }

            // Syntax field
            if (in_array($name, $syntax, true)) {
                if (in_array($name, $readonlySyntax, true)) {
                    $plan['warnings'][] = $this->warning($name, 'readonly_field', 'This field is read-only.', $value);
                    continue;
                }
                $plan['viewBag'][$name] = $value; // lossless — stored as-is
                $this->recordDiff($plan, $name, $currentViewBag[$name] ?? null, $value);
                continue;
            }

            $plan['warnings'][] = $this->warning($name, 'unknown_field', 'This field does not exist in the current layout.', $value);
        }

        return $plan;
    }

    /**
     * mergeViewBag rebuilds the full view bag: current values, overwritten by
     * the planned implicit/syntax writes, with the lossless extras replaced
     * when the client sent a viewbag_extra bag.
     */
    protected function mergeViewBag(array $rawPage, array $layoutSchema, array $writes, $viewbagExtra): array
    {
        $viewBag = $rawPage['viewBag'] ?? [];

        // Apply planned writes
        foreach ($writes as $name => $value) {
            $viewBag[$name] = $value;
        }

        // Replace the extra (layout-unknown) keys when provided
        if (is_array($viewbagExtra)) {
            [$syntax] = $this->classifyLayout($layoutSchema);
            $claimed = array_merge(PageTransformer::IMPLICIT_PROPS, $syntax);

            // Drop the old extras, keep claimed keys
            $viewBag = array_intersect_key($viewBag, array_flip($claimed));

            // Merge the new extras (they never overlap claimed keys)
            foreach ($viewbagExtra as $key => $value) {
                if (!in_array((string) $key, $claimed, true)) {
                    $viewBag[$key] = $value;
                }
            }
        }

        return $viewBag;
    }

    /**
     * classifyLayout → [syntaxNames, placeholderNames, hasMarkup].
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
            elseif (!in_array($name, PageTransformer::IMPLICIT_PROPS, true)) {
                $syntax[] = $name;
            }
        }

        return [$syntax, $placeholders, $hasMarkup];
    }

    /**
     * readonlyFieldNames lists syntax fields the layout marked read-only.
     */
    protected function readonlyFieldNames(array $layoutSchema): array
    {
        $names = [];
        foreach ($layoutSchema['fields'] ?? [] as $field) {
            if (!empty($field['readonly'])) {
                $names[] = $field['name'];
            }
        }
        return $names;
    }

    /**
     * storeValue coerces implicit props to their stored (INI) representation.
     */
    protected function storeValue(string $name, $value)
    {
        if (in_array($name, self::BOOL_PROPS, true)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }
        return $value === null ? '' : (string) $value;
    }

    protected function recordDiff(array &$plan, string $name, $from, $to): void
    {
        if ($from !== $to) {
            $plan['diff'][$name] = ['from' => $from, 'to' => $to];
        }
    }

    protected function audit(string $fileName, array $diff, ?AccessToken $token): void
    {
        AuditLog::record('update', [
            'token_id' => $token?->id,
            'backend_user_id' => BackendAuth::getUser()?->id,
            'blueprint_uuid' => self::AUDIT_BLUEPRINT,
            'record_id' => null,
            'diff' => ['_page' => $fileName] + $diff,
        ]);
    }

    protected function warning(string $field, string $code, string $message, $value): array
    {
        return ['field' => $field, 'code' => $code, 'message' => $message, 'value' => $value];
    }
}
