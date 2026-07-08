<?php namespace Renick\TailorCompanion\Classes\Pages;

/**
 * PagesSchemaFingerprint hashes serialized layout structures so the app can
 * detect pages-schema changes cheaply (via /ping) and per layout (via
 * /pages/schema). Content-based like SchemaFingerprint: only changes that are
 * visible to the app (fields, labels, tabs, …) alter the hash — reformatting
 * layout HTML around unchanged declarations does not.
 */
class PagesSchemaFingerprint
{
    /**
     * forLayout hashes one serialized layout structure (any existing
     * `fingerprint` key is excluded so the hash stays self-consistent).
     */
    public function forLayout(array $layoutStructure): string
    {
        unset($layoutStructure['fingerprint']);

        return hash('sha256', json_encode($this->normalize($layoutStructure)));
    }

    /**
     * globalVersion combines all layout structures into one version string.
     * Changes when any layout changes or layouts are added/removed.
     */
    public function globalVersion(array $layoutStructures): string
    {
        $parts = [];
        foreach ($layoutStructures as $layout) {
            $parts[$layout['file_name']] = $layout['fingerprint'] ?? $this->forLayout($layout);
        }
        ksort($parts);

        $lines = [];
        foreach ($parts as $fileName => $fingerprint) {
            $lines[] = $fileName . ':' . $fingerprint;
        }

        return hash('sha256', implode("\n", $lines));
    }

    /**
     * normalize sorts arrays recursively by key so hashing is order-stable.
     * (stdClass config sentinels normalize to empty arrays.)
     */
    protected function normalize($value)
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }

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
