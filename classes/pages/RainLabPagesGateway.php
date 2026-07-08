<?php namespace Renick\TailorCompanion\Classes\Pages;

use Cms\Classes\Layout;
use Cms\Classes\Theme;
use File;

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
        $result = [];

        foreach (Layout::listInTheme($this->theme(), true) as $layout) {
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
        $pageList = new \RainLab\Pages\Classes\PageList($this->theme());

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
        $result = [];

        foreach (\RainLab\Pages\Classes\Menu::listInTheme($this->theme(), true) as $menu) {
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
        $menu = \RainLab\Pages\Classes\Menu::load($this->theme(), $code . '.yaml');

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
        return \RainLab\Pages\Classes\Page::load($this->theme(), $fileName . '.htm');
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
     * theme returns the active theme for the current request.
     */
    protected function theme(): Theme
    {
        return Theme::getActiveTheme();
    }
}
