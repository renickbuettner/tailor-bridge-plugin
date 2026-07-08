<?php namespace Renick\TailorCompanion\Tests\Classes\Pages;

use PluginTestCase;
use Renick\TailorCompanion\Classes\Pages\PlaceholderCodec;

/**
 * PlaceholderCodec relies only on October core Twig (the placeholder/put token
 * parsers), so these run whether or not RainLab.Pages is installed.
 */
class PlaceholderCodecTest extends PluginTestCase
{
    protected PlaceholderCodec $codec;

    public function setUp(): void
    {
        parent::setUp();
        $this->codec = new PlaceholderCodec;
    }

    public function testDeclarationsReadTitleTypeAndDefaults()
    {
        $markup = <<<TWIG
        <div>
            {% placeholder sidebar title="Sidebar content" type="html" %}
            {% placeholder footer_note title="Footer note" type="text" %}
            {% placeholder promo default title="Promo box" type="html" %}
                <p>default</p>
            {% endplaceholder %}
        </div>
        TWIG;

        $decls = $this->codec->declarations($markup);

        $this->assertSame(['sidebar', 'footer_note', 'promo'], array_keys($decls));
        $this->assertSame('Sidebar content', $decls['sidebar']['title']);
        $this->assertSame('html', $decls['sidebar']['type']);
        $this->assertSame('text', $decls['footer_note']['type']);
        $this->assertFalse($decls['sidebar']['ignore']);
    }

    public function testHiddenPlaceholdersAreMarkedIgnore()
    {
        $markup = '{% placeholder head_scripts type="hidden" %}';

        $decls = $this->codec->declarations($markup);

        $this->assertArrayHasKey('head_scripts', $decls);
        $this->assertTrue($decls['head_scripts']['ignore']);
    }

    public function testPlaceholderWithoutTitleFallsBackToName()
    {
        $decls = $this->codec->declarations('{% placeholder sidebar %}');

        $this->assertSame('sidebar', $decls['sidebar']['title']);
        $this->assertSame('html', $decls['sidebar']['type']);
    }

    public function testUnparseableMarkupYieldsNoDeclarations()
    {
        $this->assertSame([], $this->codec->declarations('{% placeholder missing_end'));
    }

    public function testPutBlocksRoundTrip()
    {
        $values = [
            'sidebar' => '<p>Side content</p>',
            'footer_note' => 'All rights reserved.',
        ];

        $code = $this->codec->renderPutBlocks($values);
        $parsed = $this->codec->parsePutBlocks($code);

        $this->assertSame($values, $parsed);
    }

    public function testRenderSkipsEmptyValues()
    {
        $code = $this->codec->renderPutBlocks(['sidebar' => '', 'footer_note' => 'x']);

        $this->assertStringNotContainsString('put sidebar', $code);
        $this->assertStringContainsString('put footer_note', $code);
    }

    public function testParseSinglePutBlock()
    {
        $code = "{% put sidebar %}\nonly one\n{% endput %}";

        $this->assertSame(['sidebar' => 'only one'], $this->codec->parsePutBlocks($code));
    }

    public function testParseEmptyCodeYieldsEmptyArray()
    {
        $this->assertSame([], $this->codec->parsePutBlocks(''));
        $this->assertSame([], $this->codec->parsePutBlocks("   \n  "));
    }
}
