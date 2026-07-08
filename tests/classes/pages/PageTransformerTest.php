<?php namespace Renick\TailorCompanion\Tests\Classes\Pages;

use PluginTestCase;
use Renick\TailorCompanion\Classes\Pages\LayoutSchemaSerializer;
use Renick\TailorCompanion\Classes\Pages\PageTransformer;

/**
 * Pure array-in tests — no RainLab needed. The layout schema is built by the
 * (also RainLab-free) serializer so the partition logic is exercised end to end.
 */
class PageTransformerTest extends PluginTestCase
{
    protected PageTransformer $transformer;
    protected array $layout;

    protected string $markup = <<<'TWIG'
    {variable name="hero_heading" label="Hero heading" type="text"}{/variable}
    <article>{% page %}</article>
    {% placeholder sidebar title="Sidebar" type="html" %}
    TWIG;

    public function setUp(): void
    {
        parent::setUp();
        $this->transformer = new PageTransformer;
        $this->layout = (new LayoutSchemaSerializer)
            ->serializeLayout('static-default', 'Static default', true, $this->markup);
    }

    protected function rawPage(array $overrides = []): array
    {
        return array_merge([
            'fileName' => 'about',
            'viewBag' => [
                'title' => 'About',
                'url' => '/about',
                'layout' => 'static-default',
                'is_hidden' => '0',
                'navigation_hidden' => '1',
                'meta_title' => 'About us',
                'meta_description' => null,
                'hero_heading' => 'We build things',
                'legacy_flag' => 'keep-me',
            ],
            'markup' => '<p>Body</p>',
            'code' => "{% put sidebar %}\n<p>Side</p>\n{% endput %}",
            'mtime' => 1710000000,
            'contentHash' => 'abc123',
        ], $overrides);
    }

    public function testDetailSurfacesImplicitProps()
    {
        $page = $this->transformer->detail($this->rawPage(), $this->layout);

        $this->assertSame('about', $page['file_name']);
        $this->assertSame('About', $page['title']);
        $this->assertSame('/about', $page['url']);
        $this->assertSame('static-default', $page['layout']);
        $this->assertFalse($page['is_hidden']);
        $this->assertTrue($page['navigation_hidden']);
        $this->assertSame('About us', $page['meta_title']);
    }

    public function testDetailPartitionsFieldsAndExtra()
    {
        $page = $this->transformer->detail($this->rawPage(), $this->layout);

        $this->assertSame('We build things', $page['fields']['hero_heading']);
        $this->assertSame('<p>Side</p>', $page['fields']['placeholder:sidebar']);
        $this->assertSame('<p>Body</p>', $page['fields']['markup']);

        // Unknown view-bag key is preserved, not dropped
        $this->assertSame(['legacy_flag' => 'keep-me'], (array) $page['viewbag_extra']);
    }

    public function testMtimeIsIso8601()
    {
        $page = $this->transformer->detail($this->rawPage(), $this->layout);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $page['mtime']);
        $this->assertSame('abc123', $page['content_hash']);
    }

    public function testMissingPlaceholderValueBecomesEmptyString()
    {
        $page = $this->transformer->detail($this->rawPage(['code' => '']), $this->layout);
        $this->assertSame('', $page['fields']['placeholder:sidebar']);
    }

    public function testTreeNodeOmitsFieldValues()
    {
        $node = $this->transformer->treeNode($this->rawPage(), [
            $this->transformer->treeNode($this->rawPage(['fileName' => 'child'])),
        ]);

        $this->assertSame('about', $node['file_name']);
        $this->assertSame('About', $node['title']);
        $this->assertFalse($node['is_hidden']);
        $this->assertArrayNotHasKey('fields', $node);
        $this->assertSame('child', $node['children'][0]['file_name']);
    }

    public function testUnknownLayoutSendsEverythingToExtra()
    {
        // Empty-fields layout → hero_heading is not claimed → lands in extra
        $emptyLayout = ['file_name' => 'x', 'name' => 'x', 'use_content' => false, 'fields' => []];
        $page = $this->transformer->detail($this->rawPage(), $emptyLayout);

        $extra = (array) $page['viewbag_extra'];
        $this->assertArrayHasKey('hero_heading', $extra);
        $this->assertArrayHasKey('legacy_flag', $extra);
        $this->assertArrayNotHasKey('markup', (array) $page['fields']);
    }
}
