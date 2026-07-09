<?php namespace Renick\TailorCompanion\Classes\Pages;

use File;
use Yaml;

/**
 * PageFormResolver resolves a static-page repeater's `form=`/`groups=` reference
 * to concrete field/group definitions.
 *
 * October's Syntax Parser only extracts INLINE `{...}` field tags; a
 * page-builder repeater declares its sub-fields in an EXTERNAL form/groups YAML
 * (e.g. `groups="$/theme/meta/blocks.yaml"`). This resolver loads those the same
 * way October's Repeater form widget does — `File::symbolizePath()` handles the
 * `$/` and `~/` conventions, `Yaml::parseFileCached()` loads the file — and, like
 * `Repeater::processGroupMode()`, follows nested string references (a groups map
 * whose per-group config is itself a `$/...yaml` string).
 *
 * Tolerant by design: an unresolvable or missing reference yields `[]`, so the
 * repeater stays read-only (lossless) instead of failing the request.
 */
class PageFormResolver
{
    /**
     * resolveForm returns a form reference's fields map: [name => config].
     *
     * @param mixed $ref string path, or an already-loaded array
     */
    public function resolveForm($ref): array
    {
        $config = $this->load($ref);

        return (array) ($config['fields'] ?? []);
    }

    /**
     * resolveGroups returns a normalized groups map:
     * [code => ['name' => string, 'fields' => [name => config]]].
     *
     * @param mixed $ref string path, or an already-loaded map/array
     */
    public function resolveGroups($ref): array
    {
        $groups = $this->load($ref);
        $result = [];

        foreach ($groups as $code => $groupConfig) {
            // Underscore-prefixed keys are reserved (mirrors the Repeater widget).
            if (str_starts_with((string) $code, '_')) {
                continue;
            }

            // A group's config may itself be a string reference to a file.
            $groupConfig = $this->load($groupConfig);

            $result[(string) $code] = [
                'name' => (string) ($groupConfig['name'] ?? $code),
                'fields' => (array) ($groupConfig['fields'] ?? []),
            ];
        }

        return $result;
    }

    /**
     * load resolves a reference to an array: a string is loaded as a YAML file
     * (via the `$/` / `~/` symbol resolution), an array is returned as-is,
     * anything else (or a failure) yields an empty array.
     */
    protected function load($ref): array
    {
        if (is_array($ref)) {
            return $ref;
        }

        if (!is_string($ref) || $ref === '') {
            return [];
        }

        try {
            $path = File::symbolizePath($ref);

            if (!$path || !File::isFile($path)) {
                return [];
            }

            return (array) Yaml::parseFileCached($path);
        }
        catch (\Throwable $ex) {
            return [];
        }
    }
}
