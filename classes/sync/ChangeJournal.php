<?php namespace Renick\TailorCompanion\Classes\Sync;

use Date;
use Log;
use Renick\TailorCompanion\Models\JournalEntry;
use Schema;
use Tailor\Models\EntryRecord;
use Throwable;

/**
 * ChangeJournal records every create/update/delete of canonical Tailor entry
 * records into a journal with a monotonic cursor (the journal row id).
 *
 * This is the pull-sync backbone: Tailor soft-delete is opt-in per blueprint,
 * so deletes can be hard — `updated_at` polling cannot see them. The journal
 * captures changes from ANY source (API, backend UI, console) because it hooks
 * model events, bound once in Plugin::boot(). Subclasses (StreamRecord,
 * StructureRecord, SingleRecord) inherit the extension via class_parents.
 */
class ChangeJournal
{
    /**
     * @var bool|null tableReady caches the schema check per request
     */
    protected static $tableReady = null;

    /**
     * registerHooks binds journal recording to Tailor entry model events.
     */
    public static function registerHooks(): void
    {
        EntryRecord::extend(function (EntryRecord $model) {
            $model->bindEvent('model.afterCreate', function () use ($model) {
                (new static)->record($model, JournalEntry::ACTION_CREATED);
            });

            $model->bindEvent('model.afterUpdate', function () use ($model) {
                (new static)->record($model, JournalEntry::ACTION_UPDATED);
            });

            $model->bindEvent('model.afterDelete', function () use ($model) {
                (new static)->record($model, JournalEntry::ACTION_DELETED);
            });
        });
    }

    /**
     * record writes a journal row for a canonical record. Never throws —
     * a journal failure must not break content saving.
     */
    public function record(EntryRecord $model, string $action): ?JournalEntry
    {
        try {
            if (!$this->isCanonical($model) || !$this->isTableReady()) {
                return null;
            }

            $entry = new JournalEntry;
            $entry->blueprint_uuid = (string) $model->blueprint_uuid;
            $entry->record_id = (int) $model->getKey();
            $entry->action = $action;
            $entry->site_id = $model->site_id ?? null;
            $entry->created_at = Date::now();
            $entry->save();

            return $entry;
        }
        catch (Throwable $ex) {
            Log::error('[TailorCompanion] Journal write failed: ' . $ex->getMessage());
            return null;
        }
    }

    /**
     * isCanonical — only published, non-version rows are sync-relevant.
     * Draft/version churn must not reach the app.
     *
     * Multisite: propagation saves sibling-site rows, firing model events for
     * each. Those rows are canonical but belong to other sites the API scopes
     * away, so journaling them would flood the app with un-fetchable ids. Only
     * journal rows for the primary site.
     */
    public function isCanonical(EntryRecord $model): bool
    {
        $draftMode = $model->draft_mode ?? 1;
        if ((int) $draftMode !== 1) {
            return false;
        }

        if ($model->is_version) {
            return false;
        }

        if (!$this->isPrimarySite($model)) {
            return false;
        }

        return true;
    }

    /**
     * isPrimarySite returns true for single-site installs, or when the row
     * belongs to the primary site on a multisite install.
     */
    protected function isPrimarySite(EntryRecord $model): bool
    {
        if ($model->site_id === null) {
            return true;
        }

        try {
            $manager = \System\Classes\SiteManager::instance();
            if (!$manager->hasMultiSite()) {
                return true;
            }
            $primary = $manager->getPrimarySite();
            return $primary === null || (int) $model->site_id === (int) $primary->id;
        }
        catch (\Throwable $ex) {
            return true;
        }
    }

    /**
     * changesSince returns coalesced changes after the given cursor.
     *
     * Coalescing per (blueprint, record): the chronological history collapses
     * to what the app must do — created+…+deleted within the window cancels
     * out entirely; otherwise the presence of `created` wins over `updated`,
     * and a final `deleted` wins over everything.
     *
     * @return array{changes: array, latest_cursor: int, has_more: bool}
     */
    public function changesSince(int $cursor, int $limit = 5000): array
    {
        $rows = JournalEntry::where('id', '>', $cursor)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        // The window was capped — the client must loop with latest_cursor
        // instead of assuming it is fully synced.
        $hasMore = $rows->count() >= $limit;

        $latestCursor = $cursor;
        $grouped = [];

        foreach ($rows as $row) {
            $latestCursor = max($latestCursor, $row->id);
            $key = $row->blueprint_uuid . '|' . $row->record_id;

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'blueprint_uuid' => $row->blueprint_uuid,
                    'record_id' => $row->record_id,
                    'created_in_window' => false,
                    'last_action' => null,
                    'cursor' => $row->id,
                ];
            }

            if ($row->action === JournalEntry::ACTION_CREATED) {
                $grouped[$key]['created_in_window'] = true;
            }

            $grouped[$key]['last_action'] = $row->action;
            $grouped[$key]['cursor'] = $row->id;
        }

        $changes = [];
        foreach ($grouped as $item) {
            if ($item['last_action'] === JournalEntry::ACTION_DELETED) {
                // Created and deleted within the window → the app never saw it.
                if ($item['created_in_window']) {
                    continue;
                }
                $action = JournalEntry::ACTION_DELETED;
            }
            elseif ($item['created_in_window']) {
                $action = JournalEntry::ACTION_CREATED;
            }
            else {
                $action = JournalEntry::ACTION_UPDATED;
            }

            $changes[] = [
                'cursor' => $item['cursor'],
                'blueprint_uuid' => $item['blueprint_uuid'],
                'record_id' => $item['record_id'],
                'action' => $action,
            ];
        }

        return ['changes' => $changes, 'latest_cursor' => $latestCursor, 'has_more' => $hasMore];
    }

    const PRUNED_TO_PARAM = 'journal.pruned_to';

    /**
     * prunedTo returns the highest cursor ever removed by pruning. A client
     * whose cursor is below this missed history and must do a full re-pull.
     */
    public function prunedTo(): int
    {
        return (int) \Renick\TailorCompanion\Models\State::get(self::PRUNED_TO_PARAM, 0);
    }

    /**
     * isCursorUsable — false when history after this cursor was pruned.
     */
    public function isCursorUsable(int $cursor): bool
    {
        return $cursor >= $this->prunedTo();
    }

    /**
     * prune removes journal rows older than the retention window and records
     * the pruned high-water mark for cursor-expiry detection.
     */
    public function prune(int $days): int
    {
        $cutoff = Date::now()->subDays($days);

        $maxPruned = JournalEntry::where('created_at', '<', $cutoff)->max('id');

        // Delete FIRST — only record the high-water mark once rows are gone,
        // else a failed delete would 410 clients whose history still exists.
        $deleted = JournalEntry::where('created_at', '<', $cutoff)->delete();

        if ($maxPruned !== null && $deleted > 0) {
            \Renick\TailorCompanion\Models\State::put(self::PRUNED_TO_PARAM, (int) $maxPruned);
        }

        return $deleted;
    }

    /**
     * isTableReady guards against journal writes before the plugin migrated
     * (e.g. during a fresh october:migrate that also saves Tailor records).
     */
    protected function isTableReady(): bool
    {
        if (static::$tableReady === null) {
            static::$tableReady = Schema::hasTable('renick_tailorcompanion_journal');
        }

        return static::$tableReady;
    }

    /**
     * resetTableReadyCache is used by tests and after plugin migration.
     */
    public static function resetTableReadyCache(): void
    {
        static::$tableReady = null;
    }
}
