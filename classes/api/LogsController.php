<?php namespace Renick\TailorCompanion\Classes\Api;

use Illuminate\Http\Request;
use Renick\TailorCompanion\Classes\Logs\LogReader;
use Renick\TailorCompanion\Models\Setting;
use Response;

/**
 * LogsController serves the tail of the OctoberCMS application log so the app
 * can show a live error console. Reading is a bounded reverse-tail (see
 * LogReader) so a large log never blows up memory or response time.
 *
 * Gated by the `logs_enabled` setting (logs can contain sensitive data);
 * disabled → 403 logs_disabled, mirroring the api_disabled master switch.
 *
 *   GET /v1/logs?lines=1000
 */
class LogsController
{
    const MAX_LINES = 10000;
    const DEFAULT_LINES = 1000;

    public function __invoke(Request $request)
    {
        if (!Setting::get('logs_enabled', true)) {
            return Response::json([
                'error' => ['code' => 'logs_disabled', 'message' => 'Log access is disabled in the backend settings.'],
            ], 403);
        }

        $lines = (int) $request->query('lines', self::DEFAULT_LINES);
        $lines = max(1, min($lines, self::MAX_LINES));

        $reader = new LogReader;
        $path = $reader->resolveLogFile();

        if ($path === null) {
            return Response::json([
                'data' => [],
                'meta' => [
                    'file' => null,
                    'exists' => false,
                    'size_bytes' => 0,
                    'returned' => 0,
                    'truncated' => false,
                    'max_lines' => self::MAX_LINES,
                ],
            ]);
        }

        $result = $reader->tail($path, $lines);

        return Response::json([
            'data' => $result['lines'],
            'meta' => [
                'file' => basename($path),
                'exists' => $result['exists'],
                'size_bytes' => $result['size_bytes'],
                'returned' => $result['returned'],
                'truncated' => $result['truncated'],
                'max_lines' => self::MAX_LINES,
            ],
        ]);
    }
}
