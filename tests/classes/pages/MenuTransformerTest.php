<?php namespace Renick\TailorCompanion\Tests\Classes\Pages;

use PluginTestCase;
use Renick\TailorCompanion\Classes\Pages\MenuTransformer;

class MenuTransformerTest extends PluginTestCase
{
    protected MenuTransformer $transformer;

    public function setUp(): void
    {
        parent::setUp();
        $this->transformer = new MenuTransformer;
    }

    public function testMapsNestedItemsAndPreservesUnknownKeys()
    {
        $raw = [
            'code' => 'main-menu',
            'name' => 'Main menu',
            'items' => [
                [
                    'title' => 'Info',
                    'type' => 'static-page',
                    'reference' => 'info',
                    'code' => 'info-code',
                    'viewBag' => ['foo' => 'bar'],
                    'items' => [
                        ['title' => 'FAQ', 'type' => 'static-page', 'reference' => 'info-faq'],
                    ],
                ],
                ['title' => 'Blog', 'type' => 'url', 'url' => '/blog'],
            ],
        ];

        $menu = $this->transformer->menu($raw);

        $this->assertSame('main-menu', $menu['code']);
        $this->assertSame('Main menu', $menu['name']);

        $info = $menu['items'][0];
        $this->assertSame('Info', $info['title']);
        $this->assertSame('static-page', $info['type']);
        $this->assertSame('info', $info['reference']);
        // Unknown keys preserved losslessly
        $this->assertSame('info-code', ((array) $info['extra'])['code']);
        $this->assertSame(['foo' => 'bar'], ((array) $info['extra'])['viewBag']);
        // Nested items recurse
        $this->assertSame('FAQ', $info['items'][0]['title']);

        $blog = $menu['items'][1];
        $this->assertSame('/blog', $blog['url']);
        $this->assertSame([], $blog['items']);
    }

    public function testEmptyItemsYieldEmptyArray()
    {
        $menu = $this->transformer->menu(['code' => 'empty', 'name' => null, 'items' => []]);
        $this->assertSame([], $menu['items']);
        $this->assertNull($menu['name']);
    }
}
