<?php namespace Renick\TailorCompanion\Classes\Sync;

use BackendAuth;
use Date;
use Db;
use October\Rain\Database\ModelException;
use Renick\TailorCompanion\Classes\Schema\FieldTypeRegistry;
use Renick\TailorCompanion\Models\AccessToken;
use Renick\TailorCompanion\Models\AuditLog;
use System\Models\File as FileModel;
use Tailor\Classes\BlueprintIndexer;
use Tailor\Models\EntryRecord;
use Throwable;

/**
 * EntryWriter applies one sync operation (create/update/delete) to a Tailor
 * entry. Design rules (docs/architecture/04-sync-protocol.md):
 *
 * - Blueprint validation ALWAYS runs (save via the extended model) → `error`
 *   results carry per-field messages.
 * - Optimistic concurrency: ops carry base_updated_at; a mismatch → `conflict`
 *   with the current server state. The server never silently overwrites.
 * - Unknown/readonly fields are never applied silently — they come back as
 *   warnings WITH the rejected value, so the app can surface them.
 * - Every successful mutation writes an audit log entry with a field diff.
 * - Each op runs in its own transaction; one failing op never poisons a batch.
 */
class EntryWriter
{
    /**
     * @var array implicitWritable top-level entry attributes the app may set
     */
    protected array $implicitWritable = ['title', 'slug', 'is_enabled', 'published_at', 'expired_at'];

    protected FieldTypeRegistry $registry;
    protected EntryTransformer $transformer;

    public function __construct()
    {
        $this->registry = new FieldTypeRegistry;
        $this->transformer = new EntryTransformer;
    }

    /**
     * apply one operation. Returns a per-op result, never throws for
     * data-level problems.
     *
     * Op shape: {op, blueprint_uuid, id?, local_id?, base_updated_at?, fields?}
     */
    public function apply(array $op, ?AccessToken $token = null): array
    {
        $type = (string) ($op['op'] ?? '');
        $localId = $op['local_id'] ?? null;

        if (!in_array($type, ['create', 'update', 'delete'], true)) {
            return $this->errorResult($op, ['op' => ['Unknown operation type.']]);
        }

        $uuid = (string) ($op['blueprint_uuid'] ?? '');
        $isGlobal = BlueprintIndexer::instance()->findGlobal($uuid) !== null;
        $isSection = BlueprintIndexer::instance()->findSection($uuid) !== null;
        if (!$isGlobal && !$isSection) {
            return $this->errorResult($op, ['blueprint_uuid' => ['Unknown blueprint.']]);
        }

        try {
            return Db::transaction(function () use ($type, $op, $uuid, $token, $isGlobal) {
                if ($isGlobal) {
                    return $this->applyGlobalUpdate($op, $uuid, $token);
                }
                return match ($type) {
                    'create' => $this->applyCreate($op, $uuid, $token),
                    'update' => $this->applyUpdate($op, $uuid, $token),
                    'delete' => $this->applyDelete($op, $uuid, $token),
                };
            });
        }
        catch (ModelException $ex) {
            return $this->errorResult($op, $ex->getErrors()->toArray());
        }
        catch (Throwable $ex) {
            return $this->errorResult($op, ['_general' => [$ex->getMessage()]]);
        }
    }

    // -- Operations -----------------------------------------------------------

    protected function applyCreate(array $op, string $uuid, ?AccessToken $token): array
    {
        $entry = EntryRecord::inSectionUuid($uuid);

        // The API only creates canonical published records. Without this the
        // unset draft_mode reads as 0 → Tailor treats the record as a draft
        // and skips slug autogeneration.
        $entry->draft_mode = 1;

        if (!empty($op['content_group'])) {
            $entry->content_group = $op['content_group'];
        }

        $plan = $this->planFieldWrites($entry, (array) ($op['fields'] ?? []));

        $this->applyScalars($entry, $plan['scalars']);
        $entry->save();

        $this->applyRelationsAndNested($entry, $plan);

        $this->audit('create', $entry, $token, $this->diffForCreate($plan));

        return $this->okResult($op, $entry, $plan['warnings']);
    }

    protected function applyUpdate(array $op, string $uuid, ?AccessToken $token): array
    {
        $entry = $this->findEntry($uuid, $op);
        if (!$entry) {
            return $this->deletedConflictResult($op);
        }

        if (!$this->baseMatches($entry, $op)) {
            return $this->conflictResult($op, $entry);
        }

        $plan = $this->planFieldWrites($entry, (array) ($op['fields'] ?? []));
        $before = $this->captureBefore($entry, $plan);

        // Allow switching entry type (content_group) on update
        if (array_key_exists('content_group', $op) && $op['content_group'] !== null) {
            $entry->content_group = $op['content_group'];
        }

        $this->applyScalars($entry, $plan['scalars']);
        $entry->save();

        $changedRelations = $this->applyRelationsAndNested($entry, $plan);

        // Relation-only changes must still bump updated_at so other devices
        // see the change via the journal and future base checks work.
        if ($changedRelations && !count($plan['scalars'])) {
            $entry->touch();
        }

        $this->audit('update', $entry, $token, $this->diffForUpdate($before, $entry, $plan));

        return $this->okResult($op, $entry, $plan['warnings']);
    }

    protected function applyDelete(array $op, string $uuid, ?AccessToken $token): array
    {
        $entry = $this->findEntry($uuid, $op);
        if (!$entry) {
            // Already gone — deleting is idempotent
            return ['status' => 'ok', 'op' => 'delete', 'local_id' => $op['local_id'] ?? null,
                'id' => isset($op['id']) ? (int) $op['id'] : null, 'warnings' => []];
        }

        if (!$this->baseMatches($entry, $op)) {
            return $this->conflictResult($op, $entry);
        }

        $id = (int) $entry->getKey();
        $title = $entry->title;
        $entry->delete();

        $this->audit('delete', $entry, $token, ['title' => ['from' => $title, 'to' => null]], $id);

        return ['status' => 'ok', 'op' => 'delete', 'local_id' => $op['local_id'] ?? null,
            'id' => $id, 'warnings' => []];
    }

    /**
     * applyGlobalUpdate writes the single record of a global blueprint. Globals
     * always exist (findForGlobalUuid creates on demand) and carry no
     * title/slug/publishing — only field values. Only "update" is meaningful.
     */
    protected function applyGlobalUpdate(array $op, string $uuid, ?AccessToken $token): array
    {
        $global = \Tailor\Models\GlobalRecord::findForGlobalUuid($uuid);

        $plan = $this->planFieldWrites($global, (array) ($op['fields'] ?? []), true);

        $this->applyScalars($global, $plan['scalars']);
        $global->save();

        $this->applyRelationsAndNested($global, $plan);

        $this->audit('update', $global, $token, $this->diffForCreate($plan));

        $fresh = \Tailor\Models\GlobalRecord::findForGlobalUuid($uuid);
        return [
            'status' => 'ok',
            'op' => $op['op'],
            'local_id' => $op['local_id'] ?? null,
            'id' => (int) $global->getKey(),
            'entry' => $this->transformer->transformGlobal($fresh),
            'warnings' => $plan['warnings'],
        ];
    }

    // -- Field planning --------------------------------------------------------

    /**
     * planFieldWrites splits the payload into applicable writes and warnings.
     *
     * @return array{scalars: array, relations: array, attachments: array, nested: array, warnings: array}
     */
    protected function planFieldWrites(\Tailor\Classes\BlueprintModel $entry, array $fields, bool $isGlobal = false): array
    {
        $plan = ['scalars' => [], 'relations' => [], 'attachments' => [], 'nested' => [], 'warnings' => []];

        $fieldset = BlueprintIndexer::instance()->findContentFieldset((string) $entry->blueprint_uuid);

        foreach ($fields as $name => $value) {
            $name = (string) $name;

            if (in_array($name, $this->implicitWritable, true)) {
                $plan['scalars'][$name] = $this->castImplicit($name, $value);
                continue;
            }

            $field = $fieldset?->getField($name);

            if (!$field || str_starts_with($name, '_') || $this->registry->isMixin($field)) {
                $plan['warnings'][] = $this->warning($name, 'unknown_field',
                    'This field does not exist in the current schema.', $value);
                continue;
            }

            $config = $field->getConfig() ?: [];
            if (!empty($config['inverse'])) {
                $plan['warnings'][] = $this->warning($name, 'readonly_field',
                    'Inverse relations are read-only.', $value);
                continue;
            }

            $kind = $this->registry->kindFor($field);

            // Tailor stores relation/repeater values on globals in ways that
            // are not reliably writable via the API (relations can't serialise
            // into the JSON content column; global repeater tables lack the
            // multisite columns). These fields on globals are returned as
            // warnings so the value is never lost, and stay editable in the
            // backend. Scalars, colours, media and tags write normally.
            if ($isGlobal && ($kind === 'relation' || $kind === 'nested')) {
                $plan['warnings'][] = $this->warning($name, 'unsupported_on_global',
                    'This field type cannot be edited on globals via the API.', $value);
                continue;
            }

            switch ($kind) {
                case 'relation':
                    $plan['relations'][$name] = ['field' => $field, 'value' => $value];
                    break;
                case 'attachment':
                    $plan['attachments'][$name] = ['field' => $field, 'value' => $value];
                    break;
                case 'nested':
                    $plan['nested'][$name] = ['field' => $field, 'value' => $value];
                    break;
                default: // scalar, json, media, unknown — assigned directly
                    $plan['scalars'][$name] = $this->castScalar($field, $value);
                    break;
            }
        }

        return $plan;
    }

    protected function applyScalars(\Tailor\Classes\BlueprintModel $entry, array $scalars): void
    {
        foreach ($scalars as $name => $value) {
            $entry->{$name} = $value;
        }
    }

    /**
     * applyRelationsAndNested runs after save (needs the record id).
     * Returns true when anything changed.
     */
    protected function applyRelationsAndNested(\Tailor\Classes\BlueprintModel $entry, array $plan): bool
    {
        $changed = false;

        foreach ($plan['relations'] as $name => $write) {
            $changed = $this->applyRelation($entry, $name, $write) || $changed;
        }

        foreach ($plan['attachments'] as $name => $write) {
            $changed = $this->applyAttachments($entry, $name, $write) || $changed;
        }

        foreach ($plan['nested'] as $name => $write) {
            $changed = $this->applyNested($entry, $name, (array) $write['value']) || $changed;
        }

        return $changed;
    }

    protected function applyRelation(\Tailor\Classes\BlueprintModel $entry, string $name, array $write): bool
    {
        $config = $write['field']->getConfig() ?: [];

        // Singular === maxItems 1 for both entries and recordfinder (belongsTo
        // with a `<field>_id` column). A recordfinder with maxItems !== 1 is a
        // real many-relation stored in tailor_content_joins — it must go
        // through the sync() path below, not associate() (which only exists on
        // BelongsTo and would fatal on a many-relation).
        $isSingular = (int) ($config['maxItems'] ?? 0) === 1;

        if ($isSingular) {
            $newId = $write['value'] !== null ? (int) $write['value'] : null;
            $current = $entry->{$name . '_id'} ?? optional($entry->{$name})->getKey();
            if ((int) $current === (int) $newId && ($current === null) === ($newId === null)) {
                return false;
            }
            // Use the BelongsTo relation API (associate/dissociate) rather than
            // setting the attribute — the latter corrupts globals, whose fields
            // live in a JSON content column.
            $relation = $entry->{$name}();
            if ($newId === null) {
                $relation->dissociate();
            } else {
                $relation->associate($newId);
            }
            $entry->save();
            return true;
        }

        $ids = array_map('intval', array_filter((array) $write['value'], fn ($v) => $v !== null));
        $currentIds = $entry->{$name}()->pluck('id')->map(fn ($v) => (int) $v)->all();

        sort($ids);
        $sorted = $currentIds;
        sort($sorted);
        if ($ids === $sorted) {
            return false;
        }

        $entry->{$name}()->sync($ids);

        return true;
    }

    /**
     * applyAttachments syncs fileupload fields against uploaded file ids
     * (accepts plain ids or {id: …} objects, matching the read format).
     *
     * SECURITY: only unattached files (fresh API uploads) may be claimed —
     * attaching an arbitrary system_files id would re-parent (steal) files
     * owned by other records or plugins, and a later removal would delete
     * them. Files already attached to THIS entry+field are fine (no-op keep).
     */
    protected function applyAttachments(\Tailor\Classes\BlueprintModel $entry, string $name, array $write): bool
    {
        $config = $write['field']->getConfig() ?: [];
        $isSingle = (int) ($config['maxFiles'] ?? 0) === 1;

        $wantedIds = [];
        foreach ((array) $write['value'] as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : $item;
            if ($id !== null) {
                $wantedIds[] = (int) $id;
            }
        }

        if ($isSingle) {
            $current = $entry->{$name};
            $currentId = $current ? (int) $current->id : null;
            $newId = $wantedIds[0] ?? null;

            if ($currentId === $newId) {
                return false;
            }
            if ($newId !== null && !($file = $this->claimableFile($newId, $entry, $name))) {
                return false; // unknown or foreign file — refuse silently, keep current
            }
            if ($current) {
                $current->delete();
            }
            if ($newId !== null) {
                $entry->{$name}()->add($file);
            }
            return true;
        }

        $current = $entry->{$name};
        $currentIds = $current ? $current->pluck('id')->map(fn ($v) => (int) $v)->all() : [];

        $changed = false;
        foreach ($currentIds as $currentId) {
            if (!in_array($currentId, $wantedIds, true)) {
                FileModel::find($currentId)?->delete();
                $changed = true;
            }
        }
        foreach ($wantedIds as $wantedId) {
            if (!in_array($wantedId, $currentIds, true) && ($file = $this->claimableFile($wantedId, $entry, $name))) {
                $entry->{$name}()->add($file);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * claimableFile returns the file only when it may be attached here:
     * unattached (fresh upload) or already attached to this entry+field.
     */
    protected function claimableFile(int $id, EntryRecord $entry, string $fieldName): ?FileModel
    {
        $file = FileModel::find($id);
        if (!$file) {
            return null;
        }

        if ($file->attachment_type === null && $file->attachment_id === null) {
            return $file;
        }

        if ((int) $file->attachment_id === (int) $entry->getKey()
            && $file->attachment_type === get_class($entry)
            && $file->field === $fieldName) {
            return $file;
        }

        return null;
    }

    /**
     * applyNested replaces repeater content with the payload (replace-all
     * semantics): items with _id are updated, new items created, missing
     * items deleted; array order defines sort_order.
     */
    protected function applyNested(\Tailor\Classes\BlueprintModel $entry, string $name, array $items): bool
    {
        $existing = $entry->{$name};
        $existingById = [];
        if ($existing && !$existing instanceof \October\Rain\Database\Model) {
            foreach ($existing as $item) {
                $existingById[(int) $item->getKey()] = $item;
            }
        }
        elseif ($existing instanceof \October\Rain\Database\Model) {
            $existingById[(int) $existing->getKey()] = $existing;
        }

        $seenIds = [];
        $order = 0;

        // Internal repeater columns must never be writable from the payload —
        // host_id/host_type re-parenting would corrupt other entries.
        $protectedKeys = [
            'id', 'content_value', 'content_group', 'content_spawn_path',
            'host_id', 'host_type', 'host_field', 'sort_order', 'parent_id',
            'site_id', 'site_root_id', 'blueprint_uuid', 'created_at', 'updated_at',
        ];

        foreach ($items as $itemData) {
            $itemData = (array) $itemData;
            $order++;

            $id = isset($itemData['_id']) ? (int) $itemData['_id'] : null;
            $group = $itemData['_group'] ?? null;
            $values = array_filter(
                $itemData,
                fn ($k) => !str_starts_with((string) $k, '_') && !in_array((string) $k, $protectedKeys, true),
                ARRAY_FILTER_USE_KEY
            );

            if ($id && isset($existingById[$id])) {
                $item = $existingById[$id];
                foreach ($values as $k => $v) {
                    $item->{$k} = $v;
                }
                $item->sort_order = $order;
                $item->save();
                $seenIds[] = $id;
            }
            else {
                // make() + property assignment instead of create(): grouped
                // repeater items have an empty fillable list at fill() time
                // (fieldset binds only after content_group is known) — mass
                // assignment would throw for every grouped item.
                $item = $entry->{$name}()->make();
                if ($group !== null) {
                    $item->content_group = $group;
                }
                foreach ($values as $k => $v) {
                    $item->{$k} = $v;
                }
                $item->sort_order = $order;
                $item->save();
                $seenIds[] = (int) $item->getKey();
            }
        }

        $deleted = false;
        foreach ($existingById as $id => $item) {
            if (!in_array($id, $seenIds, true)) {
                $item->delete();
                $deleted = true;
            }
        }

        return count($items) > 0 || $deleted;
    }

    // -- Casting ------------------------------------------------------------------

    protected function castImplicit(string $name, $value)
    {
        if (in_array($name, ['published_at', 'expired_at'], true)) {
            return $value !== null ? Date::parse($value) : null;
        }
        if ($name === 'is_enabled') {
            return $value !== null ? (bool) $value : null;
        }
        return $value;
    }

    protected function castScalar($field, $value)
    {
        if ($field instanceof \Tailor\ContentFields\DatePickerField && is_string($value) && $value !== '') {
            return Date::parse($value);
        }

        return $value;
    }

    // -- Concurrency ------------------------------------------------------------

    protected function findEntry(string $uuid, array $op): ?EntryRecord
    {
        $id = (int) ($op['id'] ?? 0);
        if (!$id) {
            return null;
        }

        return EntryRecord::inSectionUuid($uuid)->where('id', $id)->first();
    }

    /**
     * baseMatches — optimistic concurrency at second precision (the wire
     * format is ISO 8601). Missing base_updated_at means "force apply".
     */
    protected function baseMatches(EntryRecord $entry, array $op): bool
    {
        $base = $op['base_updated_at'] ?? null;
        if ($base === null) {
            return true;
        }

        $serverTime = $entry->updated_at;
        if (!$serverTime) {
            return false;
        }

        try {
            return Date::parse($base)->getTimestamp() === $serverTime->getTimestamp();
        }
        catch (Throwable $ex) {
            return false;
        }
    }

    // -- Audit & diffs ---------------------------------------------------------------

    protected function audit(string $action, \Tailor\Classes\BlueprintModel $entry, ?AccessToken $token, array $changes, ?int $recordId = null): void
    {
        AuditLog::record($action, [
            'token_id' => $token?->id,
            'backend_user_id' => BackendAuth::getUser()?->id,
            'blueprint_uuid' => (string) $entry->blueprint_uuid,
            'record_id' => $recordId ?? (int) $entry->getKey(),
            'diff' => $changes,
        ]);
    }

    protected function captureBefore(EntryRecord $entry, array $plan): array
    {
        $before = [];
        foreach (array_keys($plan['scalars']) as $name) {
            $before[$name] = $entry->{$name};
        }
        foreach (array_keys($plan['relations']) as $name) {
            $config = $plan['relations'][$name]['field']->getConfig() ?: [];
            $before[$name] = (int) ($config['maxItems'] ?? 0) === 1
                ? $entry->{$name . '_id'}
                : $entry->{$name}()->pluck('id')->all();
        }
        foreach (array_keys($plan['nested']) as $name) {
            $before[$name] = '[nested items]';
        }
        foreach (array_keys($plan['attachments']) as $name) {
            $before[$name] = '[attachments]';
        }
        return $before;
    }

    protected function diffForCreate(array $plan): array
    {
        $diff = [];
        foreach ($plan['scalars'] as $name => $value) {
            $diff[$name] = ['from' => null, 'to' => $this->presentForAudit($value)];
        }
        foreach ($plan['relations'] as $name => $write) {
            $diff[$name] = ['from' => null, 'to' => $write['value']];
        }
        foreach (array_keys($plan['nested']) as $name) {
            $diff[$name] = ['from' => null, 'to' => '[nested items]'];
        }
        foreach (array_keys($plan['attachments']) as $name) {
            $diff[$name] = ['from' => null, 'to' => '[attachments]'];
        }
        return $diff;
    }

    protected function diffForUpdate(array $before, EntryRecord $entry, array $plan): array
    {
        $diff = [];
        foreach ($plan['scalars'] as $name => $value) {
            $from = $this->presentForAudit($before[$name] ?? null);
            $to = $this->presentForAudit($value);
            if ($from !== $to) {
                $diff[$name] = ['from' => $from, 'to' => $to];
            }
        }
        foreach ($plan['relations'] as $name => $write) {
            $diff[$name] = ['from' => $before[$name] ?? null, 'to' => $write['value']];
        }
        foreach (array_keys($plan['nested']) as $name) {
            $diff[$name] = ['from' => '[nested items]', 'to' => '[nested items]'];
        }
        foreach (array_keys($plan['attachments']) as $name) {
            $diff[$name] = ['from' => '[attachments]', 'to' => '[attachments]'];
        }
        return $diff;
    }

    protected function presentForAudit($value)
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        return $value;
    }

    // -- Results ------------------------------------------------------------------

    protected function okResult(array $op, EntryRecord $entry, array $warnings): array
    {
        return [
            'status' => 'ok',
            'op' => $op['op'],
            'local_id' => $op['local_id'] ?? null,
            'id' => (int) $entry->getKey(),
            'entry' => $this->transformer->transform($entry->fresh() ?? $entry),
            'warnings' => $warnings,
        ];
    }

    protected function conflictResult(array $op, EntryRecord $entry): array
    {
        return [
            'status' => 'conflict',
            'op' => $op['op'],
            'local_id' => $op['local_id'] ?? null,
            'id' => (int) $entry->getKey(),
            'server_state' => $this->transformer->transform($entry),
            'warnings' => [],
        ];
    }

    protected function deletedConflictResult(array $op): array
    {
        return [
            'status' => 'conflict',
            'op' => $op['op'],
            'local_id' => $op['local_id'] ?? null,
            'id' => isset($op['id']) ? (int) $op['id'] : null,
            'server_state' => 'deleted',
            'warnings' => [],
        ];
    }

    protected function errorResult(array $op, array $errors): array
    {
        return [
            'status' => 'error',
            'op' => $op['op'] ?? null,
            'local_id' => $op['local_id'] ?? null,
            'id' => isset($op['id']) ? (int) $op['id'] : null,
            'errors' => $errors,
            'warnings' => [],
        ];
    }

    protected function warning(string $field, string $code, string $message, $value): array
    {
        return ['field' => $field, 'code' => $code, 'message' => $message, 'value' => $value];
    }
}
