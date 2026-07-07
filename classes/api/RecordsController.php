<?php namespace Renick\TailorCompanion\Classes\Api;

use Illuminate\Http\Request;
use Response;
use Schema;
use Tailor\Classes\BlueprintIndexer;

/**
 * RecordsController powers the recordfinder picker: it searches the regular
 * model a recordfinder field points at. Those models are never synced to the
 * app (they belong to plugins, not Tailor), so the app queries them live.
 *
 * SECURITY: the model class is resolved ONLY from the blueprint's field
 * config, never from the request — the client supplies just the blueprint
 * uuid and field name. This prevents the endpoint from being turned into a
 * "dump any Eloquent model" primitive.
 *
 *   GET /v1/records/{uuid}/{field}?q=&cursor=&per_page=
 */
class RecordsController
{
    const MAX_PER_PAGE = 100;
    const DEFAULT_PER_PAGE = 25;

    public function index(Request $request, string $uuid, string $field)
    {
        // Blueprint must exist (section or global) …
        $exists = BlueprintIndexer::instance()->findSection($uuid)
            ?: BlueprintIndexer::instance()->findGlobal($uuid);
        if (!$exists) {
            return $this->notFound('unknown_blueprint', 'No blueprint with this uuid.');
        }

        // … and carry a recordfinder field by this name.
        $fieldset = BlueprintIndexer::instance()->findContentFieldset($uuid);
        $definition = $fieldset?->getField($field);
        if (!$definition || !$definition instanceof \Tailor\ContentFields\RecordFinderField) {
            return $this->notFound('unknown_recordfinder', 'No recordfinder field with this name.');
        }

        $config = $definition->getConfig() ?: [];
        $modelClass = $definition->modelClass;
        if (!$modelClass || !class_exists($modelClass)) {
            return $this->notFound('invalid_model_class', 'The recordfinder model is not available.');
        }

        $nameFrom = (string) ($config['nameFrom'] ?? 'title');
        $descriptionFrom = $config['descriptionFrom'] ?? null;

        $perPage = min(max((int) $request->query('per_page', self::DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);
        $cursor = max((int) $request->query('cursor', 0), 0);
        $search = trim((string) $request->query('q', ''));

        $model = new $modelClass;
        $query = $model->newQuery()
            ->where($model->getKeyName(), '>', $cursor)
            ->orderBy($model->getKeyName());

        // Raw where conditions from the blueprint (e.g. `is_activated = 1`).
        if (!empty($config['conditions'])) {
            $query->whereRaw((string) $config['conditions']);
        }

        // Search: a model-defined searchScope wins; otherwise a LIKE on the
        // display column when it is a real column (accessors can't be queried).
        if ($search !== '') {
            $searchScope = $config['searchScope'] ?? null;
            if ($searchScope && method_exists($model, 'scope' . ucfirst($searchScope))) {
                $query->{$searchScope}($search);
            } elseif ($this->isColumn($model, $nameFrom)) {
                $query->where($nameFrom, 'like', '%' . $search . '%');
            }
        }

        $records = $query->limit($perPage + 1)->get();
        $hasMore = $records->count() > $perPage;
        $records = $records->take($perPage);

        $data = [];
        foreach ($records as $record) {
            $data[] = [
                'id' => (int) $record->getKey(),
                'title' => (string) ($this->attr($record, $nameFrom) ?? $record->getKey()),
                'description' => $descriptionFrom ? $this->stringOrNull($this->attr($record, $descriptionFrom)) : null,
            ];
        }

        return Response::json([
            'data' => $data,
            'meta' => [
                'has_more' => $hasMore,
                'next_cursor' => $hasMore ? (int) $records->last()->getKey() : null,
            ],
        ]);
    }

    protected function attr($record, string $name)
    {
        return $record->{$name} ?? null;
    }

    protected function stringOrNull($value): ?string
    {
        return $value !== null ? (string) $value : null;
    }

    protected function isColumn($model, string $name): bool
    {
        try {
            return Schema::connection($model->getConnectionName())
                ->hasColumn($model->getTable(), $name);
        } catch (\Throwable $ex) {
            return false;
        }
    }

    protected function notFound(string $code, string $message)
    {
        return Response::json(['error' => ['code' => $code, 'message' => $message]], 404);
    }
}
