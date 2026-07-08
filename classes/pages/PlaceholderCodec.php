<?php namespace Renick\TailorCompanion\Classes\Pages;

use Cms\Twig\DebugExtension;
use Cms\Twig\Extension as CmsTwigExtension;
use Cms\Twig\Loader as TwigLoader;
use System\Twig\Extension as SystemTwigExtension;
use Twig\Environment as TwigEnvironment;
use Twig\Source as TwigSource;

/**
 * PlaceholderCodec handles everything around CMS placeholders for static
 * pages: reading `{% placeholder %}` declarations from layout markup and
 * parsing/rendering the `{% put %}` blocks stored in a page's code section.
 *
 * This is deliberately implemented against October core Twig only (no
 * RainLab classes): RainLab's own placeholder attribute APIs instanceof-check
 * the pre-October-4 `Cms\Twig\PutNode` namespace and silently return nothing
 * on October 4.x. The node matching here goes by class base name so it works
 * on both namespaces — and without RainLab.Pages installed at all.
 */
class PlaceholderCodec
{
    /**
     * declarations extracts placeholder definitions from layout markup.
     * Placeholders with type `hidden` (or an ignore attribute) are marked so
     * the schema can skip them. Returns [] for unparseable markup.
     *
     * @return array<string, array{title: string, type: string, ignore: bool}>
     */
    public function declarations(string $layoutMarkup): array
    {
        $result = [];

        foreach ($this->flattenedNodes($layoutMarkup) as $node) {
            if (!$this->nodeIs($node, 'PlaceholderNode')) {
                continue;
            }

            $name = $node->getAttribute('name');

            $title = $node->hasAttribute('title') ? trim((string) $node->getAttribute('title')) : '';
            $type = $node->hasAttribute('type') ? trim((string) $node->getAttribute('type')) : '';
            $ignore = $node->hasAttribute('ignore') ? (bool) $node->getAttribute('ignore') : false;

            $result[$name] = [
                'title' => strlen($title) ? $title : $name,
                'type' => strlen($type) ? $type : 'html',
                'ignore' => $ignore || $type === 'hidden',
            ];
        }

        return $result;
    }

    /**
     * parsePutBlocks extracts placeholder values from a page's code section
     * (`{% put name %}content{% endput %}`). Returns [] for unparseable code.
     *
     * @return array<string, string>
     */
    public function parsePutBlocks(string $code): array
    {
        if (!strlen(trim($code))) {
            return [];
        }

        $tree = $this->nodeTree($code);
        if ($tree === null) {
            return [];
        }

        $bodyNode = $tree->getNode('body')->getNode(0);
        $nodes = $this->nodeIs($bodyNode, 'PutNode') ? [$bodyNode] : iterator_to_array($bodyNode, false);

        $result = [];
        foreach ($nodes as $node) {
            if (!$this->nodeIs($node, 'PutNode') || !$node->getAttribute('capture')) {
                continue;
            }

            $name = $node->getNode('names')->getNode(0)->getAttribute('name');
            $result[$name] = trim((string) $node->getNode('values')->getAttribute('data'));
        }

        return $result;
    }

    /**
     * renderPutBlocks serializes placeholder values back into the code
     * section, using the same format RainLab's editor writes. Empty values
     * drop their block.
     *
     * @param array<string, string> $values
     */
    public function renderPutBlocks(array $values): string
    {
        $result = '';

        foreach ($values as $name => $content) {
            if (!strlen(trim((string) $content))) {
                continue;
            }

            $result .= '{% put ' . $name . ' %}' . PHP_EOL;
            $result .= $content . PHP_EOL;
            $result .= '{% endput %}' . PHP_EOL . PHP_EOL;
        }

        return trim($result);
    }

    /**
     * flattenedNodes returns every node of the markup's Twig tree.
     */
    protected function flattenedNodes(string $markup): array
    {
        $tree = $this->nodeTree($markup);
        if ($tree === null) {
            return [];
        }

        $bodyNode = $tree->getNode('body')->getNode(0);

        return array_merge([$bodyNode], $this->flatten($bodyNode));
    }

    /**
     * flatten recursively collects a Twig node's descendants.
     */
    protected function flatten($node): array
    {
        $result = [];

        if (!$node instanceof \Twig\Node\Node) {
            return $result;
        }

        foreach ($node as $subNode) {
            $result[] = $subNode;
            $result = array_merge($result, $this->flatten($subNode));
        }

        return $result;
    }

    /**
     * nodeTree parses markup with the CMS Twig extensions (placeholder/put
     * token parsers included). Null when the markup does not parse.
     */
    protected function nodeTree(string $markup)
    {
        try {
            $twig = new TwigEnvironment(new TwigLoader, []);
            CmsTwigExtension::addExtensionToTwig($twig);
            SystemTwigExtension::addExtensionToTwig($twig);
            DebugExtension::addExtensionToTwig($twig);

            return $twig->parse($twig->tokenize(new TwigSource($markup, 'placeholderCodec')));
        }
        catch (\Throwable $ex) {
            return null;
        }
    }

    /**
     * nodeIs matches a Twig node by class base name, so it works with the
     * pre-4.0 (`Cms\Twig\PutNode`) and current (`Cms\Twig\Node\PutNode`)
     * namespaces alike.
     */
    protected function nodeIs($node, string $baseName): bool
    {
        return is_object($node)
            && (str_ends_with(get_class($node), '\\' . $baseName));
    }
}
