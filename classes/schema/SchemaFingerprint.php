<?php namespace Renick\TailorCompanion\Classes\Schema;

use Tailor\Classes\Blueprint;
use Tailor\Classes\BlueprintIndexer;

/**
 * SchemaFingerprint produces content-based hashes over Tailor blueprints so
 * schema changes can be detected cheaply by the app (via /ping) and per
 * blueprint (via /schema).
 *
 * Content-based (serialized wire structure), NOT mtime-based: cache rebuilds
 * and deployments must not trigger false re-syncs, and only changes that are
 * actually visible to the app alter the fingerprint. Deliberately uncached —
 * blueprint parsing is already cached by October's BlueprintIndexer, hashing
 * the arrays is cheap, and this avoids a second invalidation mechanism.
 */
class SchemaFingerprint
{
    /**
     * forBlueprint hashes what the app would receive for this blueprint
     * (the fingerprint-stable base structure, no volatile entry_count).
     */
    public function forBlueprint(Blueprint $blueprint): string
    {
        $data = $this->normalize((new SchemaSerializer)->baseStructure($blueprint));

        return hash('sha256', json_encode($data));
    }

    /**
     * forAll returns [uuid => fingerprint] for every section and global.
     */
    public function forAll(): array
    {
        $indexer = BlueprintIndexer::instance();
        $result = [];

        foreach ($indexer->listSections() as $blueprint) {
            $result[$blueprint->uuid] = $this->forBlueprint($blueprint);
        }

        foreach ($indexer->listGlobals() as $blueprint) {
            $result[$blueprint->uuid] = $this->forBlueprint($blueprint);
        }

        return $result;
    }

    /**
     * global schema version: changes when any blueprint changes or when
     * blueprints are added/removed.
     */
    public function globalVersion(): string
    {
        $parts = $this->forAll();
        ksort($parts);

        $lines = [];
        foreach ($parts as $uuid => $fingerprint) {
            $lines[] = $uuid . ':' . $fingerprint;
        }

        return hash('sha256', implode("\n", $lines));
    }

    /**
     * normalize sorts arrays recursively by key so hashing is order-stable.
     */
    protected function normalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = $this->normalize($item);
        }
        ksort($result);

        return $result;
    }
}
