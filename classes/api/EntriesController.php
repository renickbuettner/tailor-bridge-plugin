<?php namespace Renick\TailorCompanion\Classes\Api;

use Illuminate\Http\Request;
use Renick\TailorCompanion\Classes\Sync\EntryTransformer;
use Response;
use Tailor\Classes\BlueprintIndexer;
use Tailor\Models\EntryRecord;

/**
 * EntriesController serves canonical entries of one blueprint — Tailor's
 * global scopes (draft/version) already filter plain queries down to exactly
 * the rows the app should see.
 *
 * Pagination is cursor-based on the record id (stable across inserts):
 * ?cursor=<last seen id>&per_page=<n ≤ 200>.
 */
class EntriesController
{
    const MAX_PER_PAGE = 200;
    const DEFAULT_PER_PAGE = 50;

    public function index(Request $request, string $uuid)
    {
        if (!$this->findBlueprint($uuid)) {
            return $this->notFound('unknown_blueprint', 'No blueprint with this uuid.');
        }

        $perPage = min(max((int) $request->query('per_page', self::DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);
        $cursor = max((int) $request->query('cursor', 0), 0);

        $query = EntryRecord::inSectionUuid($uuid)
            ->where('id', '>', $cursor)
            ->orderBy('id');

        // Only count on the first page — it drives the progress total and
        // doesn't change across a paged full pull; skip the extra COUNT(*)
        // on every subsequent page.
        $total = $cursor === 0 ? EntryRecord::inSectionUuid($uuid)->count() : null;

        // Fetch one extra row to detect whether more pages exist
        $records = $query->limit($perPage + 1)->get();
        $hasMore = $records->count() > $perPage;
        $records = $records->take($perPage);

        $transformer = new EntryTransformer;
        $data = [];
        foreach ($records as $record) {
            $data[] = $transformer->transform($record);
        }

        return Response::json([
            'data' => $data,
            'meta' => [
                'total' => $total,
                'has_more' => $hasMore,
                'next_cursor' => $hasMore ? (int) $records->last()->getKey() : null,
            ],
        ]);
    }

    public function show(string $uuid, int $id)
    {
        if (!$this->findBlueprint($uuid)) {
            return $this->notFound('unknown_blueprint', 'No blueprint with this uuid.');
        }

        $record = EntryRecord::inSectionUuid($uuid)->where('id', $id)->first();

        if (!$record) {
            return $this->notFound('unknown_entry', 'No entry with this id.');
        }

        return Response::json([
            'data' => (new EntryTransformer)->transform($record),
        ]);
    }

    protected function findBlueprint(string $uuid)
    {
        return BlueprintIndexer::instance()->findSection($uuid);
    }

    protected function notFound(string $code, string $message)
    {
        return Response::json(['error' => ['code' => $code, 'message' => $message]], 404);
    }
}
