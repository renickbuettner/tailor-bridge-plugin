<?php namespace Renick\TailorCompanion\Classes\Schema;

use Tailor\Classes\Blueprint;
use Tailor\Classes\BlueprintIndexer;
use Tailor\Classes\ContentFieldBase;
use Tailor\Models\EntryRecord;

/**
 * SchemaSerializer aggregates ALL Tailor blueprints (sections + globals) into
 * the normalized structure the app consumes — the app never parses blueprint
 * YAML itself. See docs/architecture/05-field-mapping.md for the wire format.
 *
 * Volatile data (entry_count) is added on top of the fingerprinted base
 * structure so data changes never alter fingerprints.
 */
class SchemaSerializer
{
    protected FieldTypeRegistry $registry;

    /**
     * @var array implicitFields added by Tailor to every entry blueprint —
     * they are not part of the fieldset definition.
     */
    protected array $implicitFields = [
        ['name' => 'title', 'type' => 'text', 'required' => true],
        ['name' => 'slug', 'type' => 'text', 'required' => true],
        ['name' => 'is_enabled', 'type' => 'switch', 'required' => false],
        ['name' => 'published_at', 'type' => 'datepicker', 'required' => false],
        ['name' => 'expired_at', 'type' => 'datepicker', 'required' => false],
    ];

    public function __construct()
    {
        $this->registry = new FieldTypeRegistry;
    }

    /**
     * serialize the full schema for the API response.
     *
     * @return array{blueprints: array, schema_version: string}
     */
    public function serialize(): array
    {
        $fingerprint = new SchemaFingerprint;
        $indexer = BlueprintIndexer::instance();
        $blueprints = [];

        foreach ($indexer->listSections() as $section) {
            $base = $this->baseStructure($section);
            $base['fingerprint'] = $fingerprint->forBlueprint($section);
            $base['entry_count'] = $this->countEntries($section);
            $blueprints[] = $base;
        }

        foreach ($indexer->listGlobals() as $global) {
            $base = $this->baseStructure($global);
            $base['fingerprint'] = $fingerprint->forBlueprint($global);
            $base['entry_count'] = 1;
            $blueprints[] = $base;
        }

        return [
            'blueprints' => $blueprints,
            'schema_version' => $fingerprint->globalVersion(),
        ];
    }

    /**
     * baseStructure is the fingerprint-stable part of a blueprint's schema —
     * everything the app needs to render forms, nothing volatile.
     */
    public function baseStructure(Blueprint $blueprint): array
    {
        $isEntry = $blueprint instanceof \Tailor\Classes\Blueprint\EntryBlueprint;

        $fields = [];

        if ($isEntry) {
            foreach ($this->implicitFields as $implicit) {
                $fields[] = $this->serializeImplicitField($implicit);
            }
        }

        $fieldset = BlueprintIndexer::instance()->findContentFieldset($blueprint->uuid);
        if ($fieldset) {
            foreach ($fieldset->getAllFields() as $name => $field) {
                if ($this->registry->isMixin($field) || str_starts_with((string) $name, '_')) {
                    continue;
                }
                $fields[] = $this->serializeField((string) $name, $field);
            }
        }

        $structure = null;
        if ($blueprint instanceof \Tailor\Classes\Blueprint\StructureBlueprint) {
            // hasTree() is false for flat structures (maxDepth === 1)
            $structure = [
                'max_depth' => (int) $blueprint->getMaxDepth(),
                'tree' => $blueprint->hasTree(),
            ];
        }

        return [
            'uuid' => (string) $blueprint->uuid,
            'handle' => (string) $blueprint->handle,
            'handle_slug' => (string) $blueprint->handleSlug,
            'name' => (string) $blueprint->name,
            'type' => (string) $blueprint->type,
            'structure' => $structure,
            'drafts' => $isEntry ? (bool) $blueprint->useDrafts() : false,
            'multisite' => $isEntry ? (bool) $blueprint->useMultisite() : false,
            // NOTE: group-specific fields are merged by findContentFieldset;
            // per-group field visibility is a future refinement (see ToDo.md)
            'groups' => $isEntry && $blueprint->hasMultipleEntryTypes()
                ? array_keys((array) $blueprint->getEntryTypeOptions())
                : null,
            'fields' => $fields,
        ];
    }

    /**
     * serializeField normalizes one content field for the wire.
     */
    protected function serializeField(string $name, ContentFieldBase $field): array
    {
        $kind = $this->registry->kindFor($field);
        $custom = $this->registry->isCustom($field);
        $config = $field->getConfig() ?: [];

        $validation = (string) ($config['validation'] ?? '');
        $required = (bool) ($config['required'] ?? false)
            || in_array('required', array_map('trim', explode('|', $validation)), true);

        // Inverse entries relations are read-only by design (Tailor never
        // writes them); computed/unknown kinds stay writable as opaque values.
        $readonly = !empty($config['inverse']);

        return [
            'name' => $name,
            'type' => (string) $field->type,
            'kind' => $kind,
            'label' => (string) ($field->label ?: $name),
            'tab' => $config['tab'] ?? null,
            'span' => $field->span ?? 'full',
            'comment' => $config['comment'] ?? null,
            'hidden' => (bool) ($config['hidden'] ?? false),
            'required' => $required,
            'readonly' => $readonly,
            'custom' => $custom,
            'implicit' => false,
            // Empty PHP arrays JSON-encode as [] — clients expect an object
            'config' => $this->normalizeFieldConfig($kind, (string) $field->type, $config) ?: new \stdClass,
        ];
    }

    /**
     * serializeImplicitField for the model-added fields (title, slug, …).
     */
    protected function serializeImplicitField(array $implicit): array
    {
        return [
            'name' => $implicit['name'],
            'type' => $implicit['type'],
            'kind' => 'scalar',
            'label' => ucwords(str_replace('_', ' ', $implicit['name'])),
            'tab' => null,
            'span' => 'full',
            'comment' => null,
            'hidden' => false,
            'required' => $implicit['required'],
            'readonly' => false,
            'custom' => false,
            'implicit' => true,
            'config' => $implicit['name'] === 'published_at' || $implicit['name'] === 'expired_at'
                ? ['mode' => 'datetime']
                : new \stdClass,
        ];
    }

    /**
     * normalizeFieldConfig extracts the kind-relevant extras the app needs.
     */
    protected function normalizeFieldConfig(string $kind, string $type, array $config): array
    {
        $result = [];

        // Static options (dropdown, radio, checkboxlist, balloon-selector,
        // taglist). Taglist accepts a sequential list (`options: [Red, Blue]`)
        // as well as a key=>label map — normalize the list form to a map so
        // the app always sees the same {key: label} shape.
        if (isset($config['options']) && is_array($config['options'])) {
            $options = $config['options'];
            if (array_is_list($options)) {
                $options = array_combine($options, $options);
            }
            $result['options'] = $options;
        }

        // Taglist (json kind): the app needs the input constraints to mirror
        // the backend widget — whether free tags are allowed, whether values
        // are stored as option keys, and the item cap.
        if ($type === 'taglist') {
            $result['custom_tags'] = (bool) ($config['customTags'] ?? false);
            // useKey defaults to true in the widget, but custom tags force it
            // off (keys can't be used with free-form values).
            $useKey = array_key_exists('useKey', $config) ? (bool) $config['useKey'] : true;
            $result['use_key'] = ($result['custom_tags']) ? false : $useKey;
            if (isset($config['maxItems'])) {
                $result['max_items'] = (int) $config['maxItems'];
            }
            if (isset($config['separator'])) {
                $result['separator'] = (string) $config['separator'];
            }
        }

        switch ($kind) {
            case 'scalar':
                foreach (['mode', 'size', 'min', 'max', 'step', 'default'] as $key) {
                    if (isset($config[$key])) {
                        $result[$key] = $config[$key];
                    }
                }
                break;

            case 'media':
                $result['max_items'] = isset($config['maxItems']) ? (int) $config['maxItems'] : null;
                if (isset($config['mode'])) {
                    $result['mode'] = $config['mode'];
                }
                break;

            case 'attachment':
                $result['max_files'] = isset($config['maxFiles']) ? (int) $config['maxFiles'] : null;
                if (isset($config['mode'])) {
                    $result['mode'] = $config['mode'];
                }
                break;

            case 'relation':
                $result['max_items'] = isset($config['maxItems']) ? (int) $config['maxItems'] : null;
                $result['inverse'] = $config['inverse'] ?? null;

                if (isset($config['source'])) {
                    $result['source'] = (string) $config['source'];
                    $result['source_uuid'] = $this->resolveSourceUuid((string) $config['source']);
                }
                if (isset($config['modelClass'])) {
                    $result['model_class'] = (string) $config['modelClass'];
                }
                break;

            case 'nested':
                $result['max_items'] = isset($config['maxItems']) ? (int) $config['maxItems'] : null;

                if (isset($config['form']['fields'])) {
                    $result['form'] = ['fields' => $this->serializeSubFields($config['form']['fields'])];
                }
                if (isset($config['groups']) && is_array($config['groups'])) {
                    $groups = [];
                    foreach ($config['groups'] as $code => $group) {
                        $groups[$code] = [
                            'name' => $group['name'] ?? $code,
                            'fields' => $this->serializeSubFields($group['fields'] ?? []),
                        ];
                    }
                    $result['groups'] = $groups;
                }
                break;
        }

        return $result;
    }

    /**
     * serializeSubFields normalizes repeater/nestedform sub-field definitions
     * by building real field objects, so classification matches top-level fields.
     */
    protected function serializeSubFields(array $fieldConfigs): array
    {
        $manager = \Tailor\Classes\FieldManager::instance();
        $result = [];

        foreach ($fieldConfigs as $name => $config) {
            $field = $manager->makeField((string) $name, (array) $config);
            $field->useConfig((array) $config);
            $result[] = $this->serializeField((string) $name, $field);
        }

        return $result;
    }

    /**
     * resolveSourceUuid maps an entries `source` (handle or uuid) to a uuid.
     */
    protected function resolveSourceUuid(string $source): ?string
    {
        $indexer = BlueprintIndexer::instance();

        $blueprint = $indexer->findSection($source) ?: $indexer->findSectionByHandle($source);

        return $blueprint?->uuid;
    }

    /**
     * countEntries returns the number of canonical records in a section.
     */
    protected function countEntries(Blueprint $blueprint): int
    {
        try {
            return EntryRecord::inSectionUuid($blueprint->uuid)->count();
        }
        catch (\Throwable $ex) {
            // Content table missing (blueprint not migrated yet)
            return 0;
        }
    }
}
