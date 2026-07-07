<?php namespace Renick\TailorCompanion\Classes\Sync;

use Carbon\CarbonInterface;
use Renick\TailorCompanion\Classes\Schema\FieldTypeRegistry;
use Tailor\Classes\BlueprintIndexer;
use Tailor\Models\EntryRecord;

/**
 * EntryTransformer serializes a Tailor entry record to the wire format
 * (docs/architecture/05-field-mapping.md):
 *
 *   scalar     → primitive (dates as ISO 8601)
 *   json       → array (already decoded by the jsonable cast)
 *   media      → media-library path string(s), app resolves via base URL
 *   relation   → id (singular) / id array (multi); labels sideloaded
 *   attachment → array of {id, name, size, content_type, url}
 *   nested     → array of items {_id, _group, ...subfields}, sort order kept
 *   unknown    → raw value passthrough — NEVER dropped or mutated
 */
class EntryTransformer
{
    protected FieldTypeRegistry $registry;

    public function __construct()
    {
        $this->registry = new FieldTypeRegistry;
    }

    /**
     * transform one entry to its wire representation.
     */
    public function transform(EntryRecord $entry): array
    {
        [$fields, $relationLabels] = $this->serializeFields($entry);

        return [
            'id' => (int) $entry->getKey(),
            'blueprint_uuid' => (string) $entry->blueprint_uuid,
            'content_group' => $entry->content_group,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'is_enabled' => $this->effectiveIsEnabled($entry),
            'published_at' => $this->presentValue($entry->published_at),
            'expired_at' => $this->presentValue($entry->expired_at),
            'site_id' => $entry->site_id,
            'created_at' => $this->presentValue($entry->created_at),
            'updated_at' => $this->presentValue($entry->updated_at),
            'fields' => $fields,
            'relation_labels' => $relationLabels ?: (object) [],
            'is_global' => false,
        ];
    }

    /**
     * transformGlobal serializes a Tailor global's single record. Globals have
     * no title/slug/publishing columns — just field values.
     */
    public function transformGlobal(\Tailor\Models\GlobalRecord $global): array
    {
        [$fields, $relationLabels] = $this->serializeFields($global);

        $blueprint = BlueprintIndexer::instance()->findGlobal((string) $global->blueprint_uuid);

        return [
            'id' => (int) $global->getKey(),
            'blueprint_uuid' => (string) $global->blueprint_uuid,
            'content_group' => null,
            'title' => $blueprint ? (string) $blueprint->name : null,
            'slug' => null,
            'is_enabled' => true,
            'published_at' => null,
            'expired_at' => null,
            'site_id' => $global->site_id ?? null,
            'created_at' => $this->presentValue($global->created_at),
            'updated_at' => $this->presentValue($global->updated_at),
            'fields' => $fields,
            'relation_labels' => $relationLabels ?: (object) [],
            'is_global' => true,
        ];
    }

    /**
     * serializeFields runs the shared field loop over any blueprint model
     * (entry or global). Returns [fields, relationLabels].
     */
    protected function serializeFields(\Tailor\Classes\BlueprintModel $model): array
    {
        $fields = [];
        $relationLabels = [];

        $fieldset = BlueprintIndexer::instance()->findContentFieldset((string) $model->blueprint_uuid);

        if ($fieldset) {
            foreach ($fieldset->getAllFields() as $name => $field) {
                $name = (string) $name;

                if ($this->registry->isMixin($field) || str_starts_with($name, '_')) {
                    continue;
                }

                $kind = $this->registry->kindFor($field);

                switch ($kind) {
                    case 'relation':
                        [$value, $labels] = $this->transformRelation($model, $name, $field);
                        $fields[$name] = $value;
                        if ($labels !== null) {
                            $relationLabels[$name] = $labels;
                        }
                        break;

                    case 'attachment':
                        $fields[$name] = $this->transformAttachments($model, $name, $field);
                        break;

                    case 'nested':
                        $fields[$name] = $this->transformNested($model, $name);
                        break;

                    default: // scalar, json, media, unknown
                        $fields[$name] = $this->presentValue($model->{$name});
                        break;
                }
            }
        }

        return [$fields, $relationLabels];
    }

    /**
     * transformRelation returns [ids-or-id, labels] for an entries field.
     *
     * @return array{0: mixed, 1: ?array}
     */
    protected function transformRelation(\Tailor\Classes\BlueprintModel $entry, string $name, $field): array
    {
        $config = $field->getConfig() ?: [];

        // Singular is defined by maxItems === 1, for BOTH entries and
        // recordfinder fields (Tailor makes maxItems=1 a belongsTo with a
        // `<field>_id` column; otherwise a many-relation). A recordfinder
        // with maxItems !== 1 is a genuine multi relation and MUST read as a
        // collection — special-casing the class here silently truncated
        // multi recordfinders to a single id.
        $isSingular = (int) ($config['maxItems'] ?? 0) === 1;

        if ($isSingular) {
            $id = $entry->{$name . '_id'} ?? optional($entry->{$name})->id;
            $related = $entry->{$name};

            $labels = $related
                ? [['id' => (int) $related->getKey(), 'title' => $this->relationLabelText($related, $field)]]
                : null;

            return [$id !== null ? (int) $id : null, $labels];
        }

        // Multi relation (incl. read-only inverse): collection of records
        $records = $entry->{$name};
        if (!$records) {
            return [[], null];
        }

        $ids = [];
        $labels = [];
        foreach ($records as $record) {
            $ids[] = (int) $record->getKey();
            $labels[] = ['id' => (int) $record->getKey(), 'title' => $this->relationLabelText($record, $field)];
        }

        return [$ids, $labels ?: null];
    }

    /**
     * relationLabelText picks a human label for a related record. Recordfinder
     * targets are arbitrary models that may not have a `title` (e.g. a User
     * has `login`), so honour the field's `nameFrom` and fall back through
     * title → name → primary key. Entries relations leave nameFrom unset and
     * naturally resolve to `title`.
     */
    protected function relationLabelText($record, $field): string
    {
        $config = $field->getConfig() ?: [];
        $nameFrom = $config['nameFrom'] ?? null;

        if ($nameFrom && ($record->{$nameFrom} ?? null) !== null) {
            return (string) $record->{$nameFrom};
        }

        return (string) ($record->title ?? $record->name ?? $record->getKey());
    }

    /**
     * transformAttachments serializes fileupload attachments.
     */
    protected function transformAttachments(\Tailor\Classes\BlueprintModel $entry, string $name, $field): array
    {
        $config = $field->getConfig() ?: [];
        $isSingle = (int) ($config['maxFiles'] ?? 0) === 1;

        $value = $entry->{$name};
        $files = $isSingle
            ? ($value ? [$value] : [])
            : ($value ? $value->all() : []);

        $result = [];
        foreach ($files as $file) {
            $result[] = [
                'id' => (int) $file->id,
                'name' => (string) $file->file_name,
                'size' => (int) $file->file_size,
                'content_type' => (string) $file->content_type,
                'url' => (string) $file->getPath(),
            ];
        }

        return $result;
    }

    /**
     * transformNested serializes repeater/nestedform items inline. Sub-field
     * values live in the ExpandoModel's content_value blob — after fetch they
     * are merged into the attributes, so we take attributes minus internals.
     */
    protected function transformNested(\Tailor\Classes\BlueprintModel $entry, string $name): array
    {
        $value = $entry->{$name};
        if (!$value) {
            return [];
        }

        // nestedform → single item; repeater/nesteditems → collection
        $items = $value instanceof \October\Rain\Database\Model ? [$value] : $value->all();

        $internals = [
            'id', 'content_value', 'content_group', 'content_spawn_path',
            'host_id', 'host_type', 'host_field', 'sort_order', 'parent_id',
            'site_id', 'site_root_id', 'created_at', 'updated_at', 'blueprint_uuid',
        ];

        $result = [];
        foreach ($items as $item) {
            $attributes = $item->getAttributes();
            $data = [
                '_id' => (int) $item->getKey(),
                '_group' => $item->content_group ?? null,
            ];

            foreach ($attributes as $key => $raw) {
                if (in_array($key, $internals, true)) {
                    continue;
                }
                // Use the accessor so jsonable/date casts apply
                $data[$key] = $this->presentValue($item->{$key});
            }

            $result[] = $data;
        }

        return $result;
    }

    /**
     * effectiveIsEnabled — the raw column is null until explicitly set; the
     * effective default comes from the blueprint (fields.is_enabled.default,
     * true if unspecified), mirroring Tailor's status logic.
     */
    protected function effectiveIsEnabled(EntryRecord $entry): bool
    {
        if ($entry->is_enabled !== null) {
            return (bool) $entry->is_enabled;
        }

        $blueprint = BlueprintIndexer::instance()->findSection((string) $entry->blueprint_uuid);

        return $blueprint ? (bool) $blueprint->isEntryEnabledByDefault() : true;
    }

    /**
     * presentValue converts dates to ISO 8601 and passes everything else raw.
     */
    protected function presentValue($value)
    {
        if ($value instanceof CarbonInterface || $value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return $value;
    }
}
