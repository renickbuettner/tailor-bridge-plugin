<?php namespace Renick\TailorCompanion\Classes\Logs;

/**
 * LogReader returns the last N lines of a log file without loading the whole
 * file into memory — it seeks to the end and reads backwards in chunks until
 * it has collected enough lines or reaches the start. Cost is proportional to
 * the size of the tail requested, not the size of the file, so it stays fast
 * and memory-bounded even on multi-hundred-megabyte logs.
 *
 * Multi-line log entries (stack traces, JSON context) are preserved verbatim
 * as separate lines — the client reassembles and colours them.
 */
class LogReader
{
    /**
     * @var int CHUNK bytes read per backward step.
     */
    protected const CHUNK = 32768;

    /**
     * resolveLogFile returns the active log file path, or null if none exists.
     * Honours the configured `single` channel path, then falls back to the
     * most recently modified *.log in storage/logs (covers the daily driver).
     */
    public function resolveLogFile(): ?string
    {
        $configured = config('logging.channels.single.path');
        if (is_string($configured) && is_file($configured)) {
            return $configured;
        }

        $candidates = glob(storage_path('logs/*.log')) ?: [];
        if (!$candidates) {
            return null;
        }

        usort($candidates, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $candidates[0];
    }

    /**
     * tail returns up to $maxLines lines from the end of $path, in chronological
     * order (oldest first, newest last — the natural reading order for a log).
     *
     * @return array{lines: string[], size_bytes: int, returned: int, truncated: bool, exists: bool}
     */
    public function tail(string $path, int $maxLines): array
    {
        if ($maxLines < 1 || !is_file($path) || !is_readable($path)) {
            return ['lines' => [], 'size_bytes' => 0, 'returned' => 0, 'truncated' => false, 'exists' => false];
        }

        $size = filesize($path);
        if ($size === 0) {
            return ['lines' => [], 'size_bytes' => 0, 'returned' => 0, 'truncated' => false, 'exists' => true];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ['lines' => [], 'size_bytes' => $size, 'returned' => 0, 'truncated' => false, 'exists' => true];
        }

        $buffer = '';
        $pos = $size;

        // Read backwards until we have more newlines than requested (so the
        // last line is complete) or we reach the start of the file.
        while ($pos > 0 && substr_count($buffer, "\n") <= $maxLines) {
            $read = (int) min(self::CHUNK, $pos);
            $pos -= $read;
            fseek($handle, $pos);
            $buffer = fread($handle, $read) . $buffer;
        }
        fclose($handle);

        $all = explode("\n", $buffer);

        // A trailing newline yields an empty final element — drop it so the
        // count reflects real lines.
        if (end($all) === '') {
            array_pop($all);
        }

        $lines = array_slice($all, -$maxLines);

        // More lines exist above if we stopped before the start, or the buffer
        // we read already held more than we returned.
        $truncated = $pos > 0 || count($all) > count($lines);

        return [
            'lines' => array_values($lines),
            'size_bytes' => $size,
            'returned' => count($lines),
            'truncated' => $truncated,
            'exists' => true,
        ];
    }
}
