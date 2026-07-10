<?php namespace Renick\TailorCompanion\Tests\Fakes;

use Renick\TailorCompanion\Classes\Pages\PagesGateway;

/**
 * PagesTestGateway is an in-memory PagesGateway for tests. It lets the pages
 * endpoints run with the feature forced available even when RainLab.Pages is
 * not installed, and records the last updatePage() call so the writer can be
 * asserted.
 *
 * Loaded via require_once (not PSR-4) so it works on case-sensitive CI.
 */
class PagesTestGateway implements PagesGateway
{
    public array $layoutList = [];
    public array $treeNodes = [];
    /** @var array<string, array> keyed by file name */
    public array $pages = [];
    public array $menuList = [];
    /** @var array<string, array> keyed by code */
    public array $menuData = [];

    /** @var array|null the last updatePage() arguments */
    public ?array $lastUpdate = null;

    public ?string $themePath = null;

    public function themePath(): ?string
    {
        return $this->themePath;
    }

    public function layouts(): array
    {
        return $this->layoutList;
    }

    public function tree(): array
    {
        return $this->treeNodes;
    }

    public function page(string $fileName): ?array
    {
        return $this->pages[$fileName] ?? null;
    }

    public function updatePage(string $fileName, array $viewBag, ?string $markup, ?string $code): array
    {
        $this->lastUpdate = compact('fileName', 'viewBag', 'markup', 'code');

        $page = $this->pages[$fileName];
        $page['viewBag'] = $viewBag;
        if ($markup !== null) {
            $page['markup'] = $markup;
        }
        if ($code !== null) {
            $page['code'] = $code;
        }
        // Simulate a save re-hashing the file.
        $page['contentHash'] = hash('sha256', json_encode([$viewBag, $page['markup'] ?? '', $page['code'] ?? '']));
        $this->pages[$fileName] = $page;

        return $page;
    }

    public function menus(): array
    {
        return $this->menuList;
    }

    public function menu(string $code): ?array
    {
        return $this->menuData[$code] ?? null;
    }
}
