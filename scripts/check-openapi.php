<?php

/**
 * OpenAPI drift check — dependency-free (plain PHP, no October, no composer).
 *
 * Asserts that every route registered in routes.php under the API group is
 * documented in docs/openapi.yaml, and that the spec documents no phantom
 * paths. Run locally or in CI:
 *
 *     php scripts/check-openapi.php
 *
 * Exit code 0 = in sync, 1 = drift detected.
 */

$root = dirname(__DIR__);
$routesFile = $root . '/routes.php';
$specFile = $root . '/docs/openapi.yaml';

$routesSrc = file_get_contents($routesFile);
$specSrc = file_get_contents($specFile);

if ($routesSrc === false || $specSrc === false) {
    fwrite(STDERR, "Could not read routes.php or docs/openapi.yaml\n");
    exit(1);
}

// --- Route paths from routes.php ------------------------------------------
// The group prefix; paths in the file are relative to it.
if (!preg_match("/'prefix'\\s*=>\\s*'([^']+)'/", $routesSrc, $m)) {
    fwrite(STDERR, "Could not find the route group prefix.\n");
    exit(1);
}
// e.g. api/tailor-companion/v1 — the spec paths are relative to this too.

$routePaths = [];
if (preg_match_all(
    "/Route::(get|post|put|patch|delete)\\(\\s*['\"]([^'\"]+)['\"]/",
    $routesSrc,
    $matches,
    PREG_SET_ORDER
)) {
    foreach ($matches as $match) {
        $path = '/' . ltrim($match[2], '/');
        $routePaths[$path] = strtoupper($match[1]);
    }
}

// --- Paths from openapi.yaml ------------------------------------------------
$specPaths = [];
$inPaths = false;
foreach (explode("\n", $specSrc) as $line) {
    if (preg_match('/^paths:\s*$/', $line)) {
        $inPaths = true;
        continue;
    }
    if ($inPaths && preg_match('/^\S/', $line)) {
        // A new top-level key ends the paths section
        break;
    }
    if ($inPaths && preg_match('/^  (\/\S*):\s*$/', $line, $mm)) {
        $specPaths[$mm[1]] = true;
    }
}

// --- Compare ----------------------------------------------------------------
$errors = [];

foreach (array_keys($routePaths) as $path) {
    if (!isset($specPaths[$path])) {
        $errors[] = "Route {$routePaths[$path]} {$path} is NOT documented in openapi.yaml";
    }
}
foreach (array_keys($specPaths) as $path) {
    if (!isset($routePaths[$path])) {
        $errors[] = "openapi.yaml documents {$path} which is not a registered route";
    }
}

if ($errors) {
    fwrite(STDERR, "OpenAPI drift detected:\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  - {$e}\n");
    }
    exit(1);
}

echo "OpenAPI spec in sync with " . count($routePaths) . " routes.\n";
exit(0);
