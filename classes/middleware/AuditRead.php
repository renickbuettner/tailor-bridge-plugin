<?php namespace Renick\TailorCompanion\Classes\Middleware;

use BackendAuth;
use Closure;
use Illuminate\Http\Request;
use Renick\TailorCompanion\Models\AuditLog;
use Renick\TailorCompanion\Models\Setting;

/**
 * AuditRead records data-retrieval (sync) requests in the audit log, so the
 * backend shows who read which data when — not just who mutated it.
 *
 * It only logs successful GET requests: mutations (POST/PATCH) already write
 * their own richer audit entries via the writers, and logging them here would
 * double-count. Applied to the site-scoped route group, so it covers exactly
 * the content endpoints (schema, entries, records, globals, sync/changes,
 * files, pages) and skips the trivial meta endpoints (ping, version, sites,
 * logs) that sit outside that group.
 *
 * Logging is best-effort: it runs after the response and never throws into the
 * request — an audit hiccup must not break a read. Gated by the
 * `audit_reads_enabled` setting for admins who find it too noisy.
 */
class AuditRead
{
    /** Query keys worth recording; others are dropped to keep entries small. */
    protected const TRACKED_QUERY = ['since', 'q', 'search', 'page', 'per_page', 'perPage', 'limit', 'cursor'];

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        try {
            $this->maybeLog($request, $response);
        }
        catch (\Throwable $ignored) {
            // Auditing is observational — never let it fail a read.
        }

        return $response;
    }

    /**
     * maybeLog writes one audit entry for a successful data read.
     */
    protected function maybeLog(Request $request, $response): void
    {
        if (!$request->isMethod('GET')) {
            return;                                   // mutations audit themselves
        }

        if (!Setting::get('audit_reads_enabled', true)) {
            return;
        }

        $status = is_object($response) && method_exists($response, 'getStatusCode')
            ? $response->getStatusCode()
            : 200;
        if ($status < 200 || $status >= 300) {
            return;                                   // only successful reads
        }

        $route = $request->route();
        $params = $route ? $route->parameters() : [];
        $endpoint = $route ? $route->uri() : $request->path();
        $token = $request->attributes->get(TokenAuth::REQUEST_TOKEN_KEY);

        AuditLog::record($this->actionFor($endpoint), [
            'token_id' => $token?->id,
            'backend_user_id' => BackendAuth::getUser()?->id,
            'blueprint_uuid' => $this->uuidParam($params),
            'record_id' => $this->recordId($params),
            'diff' => array_filter([
                'endpoint' => $endpoint,
                'params' => $this->safeParams($params) ?: null,
                'query' => $this->safeQuery($request) ?: null,
            ], fn ($v) => $v !== null),
        ]);
    }

    /**
     * actionFor names the read: the pull sync is called out explicitly so it
     * can be filtered/searched apart from incidental reads.
     */
    protected function actionFor(string $endpoint): string
    {
        return str_contains($endpoint, 'sync/changes') ? 'sync' : 'read';
    }

    /**
     * uuidParam returns the {uuid} route parameter when it looks like a
     * blueprint uuid — so entry/global/record reads populate the Blueprint
     * column. Non-uuid path params (page file names, menu codes) go to `diff`.
     */
    protected function uuidParam(array $params): ?string
    {
        $uuid = $params['uuid'] ?? null;

        return is_string($uuid) && strlen($uuid) <= 36 && str_contains($uuid, '-') ? $uuid : null;
    }

    protected function recordId(array $params): ?int
    {
        return isset($params['id']) && is_numeric($params['id']) ? (int) $params['id'] : null;
    }

    /**
     * safeParams keeps the non-uuid route params (field, fileName, code) as
     * short strings for context.
     */
    protected function safeParams(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            if ($key === 'uuid' || $key === 'id' || !is_scalar($value)) {
                continue;
            }
            $out[$key] = substr((string) $value, 0, 120);
        }

        return $out;
    }

    /**
     * safeQuery keeps only the tracked query keys, capped in length.
     */
    protected function safeQuery(Request $request): array
    {
        $out = [];
        foreach (self::TRACKED_QUERY as $key) {
            if ($request->query->has($key)) {
                $value = $request->query->get($key);
                if (is_scalar($value)) {
                    $out[$key] = substr((string) $value, 0, 120);
                }
            }
        }

        return $out;
    }
}
