<?php namespace Renick\TailorCompanion\Classes\Api;

use Illuminate\Http\Request;
use Renick\TailorCompanion\Classes\Middleware\SiteContext;
use Renick\TailorCompanion\Classes\Sync\ChangeJournal;
use Renick\TailorCompanion\Models\JournalEntry;
use Response;

/**
 * ChangesController serves the coalesced change journal for incremental pull.
 *
 * - no/invalid `since` → the client has no cursor yet: full pull required
 * - `since` older than pruned history → 410 Gone: full re-pull required
 */
class ChangesController
{
    public function __invoke(Request $request)
    {
        $journal = new ChangeJournal;

        if (!$request->filled('since')) {
            return Response::json([
                'data' => [],
                'meta' => [
                    'full_pull_required' => true,
                    'latest_cursor' => (int) (JournalEntry::max('id') ?? 0),
                ],
            ]);
        }

        $since = max((int) $request->query('since'), 0);

        if (!$journal->isCursorUsable($since)) {
            return Response::json([
                'error' => [
                    'code' => 'cursor_expired',
                    'message' => 'Journal history after this cursor was pruned. Perform a full pull.',
                ],
            ], 410);
        }

        $siteId = $request->attributes->get(SiteContext::REQUEST_SITE_KEY);
        $result = $journal->changesSince($since, $siteId !== null ? (int) $siteId : null);

        return Response::json([
            'data' => $result['changes'],
            'meta' => [
                'full_pull_required' => false,
                'latest_cursor' => $result['latest_cursor'],
                'has_more' => $result['has_more'],
            ],
        ]);
    }
}
