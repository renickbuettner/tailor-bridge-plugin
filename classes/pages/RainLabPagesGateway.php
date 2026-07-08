<?php namespace Renick\TailorCompanion\Classes\Pages;

use Cms\Classes\Layout;
use Cms\Classes\Theme;
use File;
use System\Classes\SiteManager;

/**
 * RainLabPagesGateway is the real PagesGateway backed by RainLab.Pages.
 * RainLab classes are referenced inside method bodies only, so this file
 * loads fine when the plugin is absent — it just must not be called then
 * (PagesFeature::isAvailable() gates every caller).
 */
class RainLabPagesGateway implements PagesGateway
{
    /**
     * layouts returns raw layout templates declaring the staticPage component.
     */
    public function layouts(): array
    {
        $theme = $this->theme();
        if (!$theme) {
            return [];
        }

        $result = [];

        foreach (Layout::listInTheme($theme, true) as $layout) {
            if (!$layout->hasComponent('staticPage')) {
                continue;
            }

            $properties = $layout->settings['components']['staticPage'] ?? [];

            $result[] = [
                'fileName' => $layout->getBaseFileName(),
                'description' => strlen((string) $layout->description) ? $layout->description : null,
                'useContent' => (bool) ($properties['useContent'] ?? true),
                'markup' => (string) $layout->markup,
            ];
        }

        return $result;
    }

    /**
     * tree returns the nested hierarchy from meta/static-pages.yaml.
     */
    public function tree(): array
    {
        $theme = $this->theme();
        if (!$theme) {
            return [];
        }

        $pageList = new \RainLab\Pages\Classes\PageList($theme);

        $mapNodes = function (array $nodes) use (&$mapNodes) {
            $result = [];
            foreach ($nodes as $node) {
                $result[] = [
                    'fileName' => $node->page->getBaseFileName(),
                    'children' => $mapNodes($node->subpages),
                ];
            }
            return $result;
        };

        return $mapNodes($pageList->getPageTree(true));
    }

    /**
     * page returns raw data for one static page.
     */
    public function page(string $fileName): ?array
    {
        $page = $this->loadPage($fileName);

        return $page ? $this->rawPageData($page) : null;
    }

    /**
     * updatePage applies changes through the RainLab model and returns the
     * fresh raw data.
     */
    public function updatePage(string $fileName, array $viewBag, ?string $markup, ?string $code): array
    {
        $page = $this->loadPage($fileName);

        if (!$page) {
            throw new \RuntimeException("Static page '{$fileName}' not found.");
        }

        $fill = ['settings' => ['viewBag' => $viewBag]];
        if ($markup !== null) {
            $fill['markup'] = $markup;
        }
        $page->fill($fill);

        // The placeholders attribute is broken on October 4.x (RainLab checks
        // the pre-4.0 Cms\Twig\PutNode namespace and silently drops all
        // values), so the writer renders the {% put %} blocks and we assign
        // the code section directly. Saving still runs the full model path.
        if ($code !== null) {
            $page->code = $code;
        }

        $page->save();

        // Reload from disk so mtime/hash reflect what was actually written.
        return $this->page($fileName);
    }

    /**
     * menus lists the theme's static menus.
     */
    public function menus(): array
    {
        $theme = $this->theme();
        if (!$theme) {
            return [];
        }

        $result = [];

        foreach (\RainLab\Pages\Classes\Menu::listInTheme($theme, true) as $menu) {
            $result[] = [
                'code' => $menu->getBaseFileName(),
                'name' => $menu->name ?: null,
                'contentHash' => $this->hashFile($menu->getFilePath()),
            ];
        }

        return $result;
    }

    /**
     * menu returns one menu with raw item arrays.
     */
    public function menu(string $code): ?array
    {
        $theme = $this->theme();
        if (!$theme) {
            return null;
        }

        $menu = \RainLab\Pages\Classes\Menu::load($theme, $code . '.yaml');

        if (!$menu) {
            return null;
        }

        return [
            'code' => $menu->getBaseFileName(),
            'name' => $menu->name ?: null,
            // Raw attribute access on purpose: the items accessor returns
            // MenuItem objects, we want the lossless yaml arrays.
            'items' => $menu->getAttributes()['items'] ?? [],
        ];
    }

    /**
     * loadPage loads a RainLab page model by base file name.
     */
    protected function loadPage(string $fileName): ?\RainLab\Pages\Classes\Page
    {
        $theme = $this->theme();
        if (!$theme) {
            return null;
        }

        return \RainLab\Pages\Classes\Page::load($theme, $fileName . '.htm');
    }

    /**
     * rawPageData maps a loaded page model to the gateway array shape.
     */
    protected function rawPageData(\RainLab\Pages\Classes\Page $page): array
    {
        return [
            'fileName' => $page->getBaseFileName(),
            'viewBag' => $page->getViewBag()->getProperties(),
            'markup' => (string) $page->markup,
            'code' => (string) $page->code,
            'mtime' => $page->mtime ? (int) $page->mtime : null,
            'contentHash' => $this->hashFile($page->getFilePath()),
        ];
    }

    /**
     * hashFile returns the sha256 of a file's raw contents.
     */
    protected function hashFile(?string $path): string
    {
        if (!$path || !File::exists($path)) {
            return '';
        }

        return hash('sha256', File::get($path));
    }

    /**
     * theme resolves the theme static pages live in, or null when none can be
     * resolved. Callers must tolerate null — static pages then simply appear
     * empty rather than crashing the request.
     *
     * Resolution order:
     *  1. `Theme::getActiveTheme()` — the normal path. Works when
     *     `cms.active_theme` is populated (e.g. a single-theme install driven
     *     by the `ACTIVE_THEME` env var).
     *  2. The site definition's `theme` column. October persists the active
     *     theme per site (see `Theme::setActiveTheme`), and a bare API request
     *     does NOT populate `cms.active_theme` from the site — so `getActiveTheme`
     *     falls back to the env default (often a non-existent theme → null).
     *     Reading the site's own theme fixes multisite / per-site-theme installs.
     *  3. `Theme::getEditTheme()` — a last backend-context fallback.
     */
    protected function theme(): ?Theme
    {
        if ($theme = Theme::getActiveTheme()) {
            return $theme;
        }

        $manager = SiteManager::instance();
        foreach ([$manager->getActiveSite(), $manager->getPrimarySite()] as $site) {
            $code = $site?->theme;
            if ($code && ($loaded = Theme::load($code)) && $loaded->isValid()) {
                return $loaded;
            }
        }

        return Theme::getEditTheme();
    }
}
