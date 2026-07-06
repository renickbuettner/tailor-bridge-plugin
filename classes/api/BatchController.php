<?php namespace Renick\TailorCompanion\Classes\Api;

use Illuminate\Http\Request;
use Renick\TailorCompanion\Classes\Middleware\TokenAuth;
use Renick\TailorCompanion\Classes\Sync\EntryWriter;
use Response;

/**
 * BatchController applies pushed sync operations in order. Results are
 * per-op (ok / conflict / error) — one failing op never fails the batch,
 * matching the app's row-wise queue semantics.
 */
class BatchController
{
    const MAX_OPS = 100;

    public function __invoke(Request $request)
    {
        $ops = $request->input('ops');

        if (!is_array($ops) || !count($ops)) {
            return Response::json([
                'error' => ['code' => 'validation', 'message' => 'Body must contain a non-empty ops array.'],
            ], 422);
        }

        if (count($ops) > self::MAX_OPS) {
            return Response::json([
                'error' => ['code' => 'too_many_ops', 'message' => 'At most ' . self::MAX_OPS . ' ops per batch.'],
            ], 422);
        }

        $token = $request->attributes->get(TokenAuth::REQUEST_TOKEN_KEY);
        $writer = new EntryWriter;

        $results = [];
        $counts = ['ok' => 0, 'conflict' => 0, 'error' => 0];

        foreach ($ops as $op) {
            $result = $writer->apply((array) $op, $token);
            $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
            $results[] = $result;
        }

        return Response::json([
            'data' => ['results' => $results],
            'meta' => [
                'applied' => $counts['ok'],
                'conflicts' => $counts['conflict'],
                'errors' => $counts['error'],
            ],
        ]);
    }
}
