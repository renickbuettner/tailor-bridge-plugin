<?php namespace Renick\TailorCompanion\Tests\Classes\Pages;

use PluginTestCase;
use Renick\TailorCompanion\Classes\Pages\LayoutSchemaSerializer;

/**
 * Exercises the pure string-in path of the serializer, so it runs without
 * RainLab.Pages installed (the SyntaxParser and CMS Twig it uses are core).
 */
class LayoutSchemaSerializerTest extends PluginTestCase
{
    protected LayoutSchemaSerializer $serializer;

    /**
     * @var string layout covering every mapping branch
     */
    protected string $markup = <<<'TWIG'
    {variable name="hero_heading" label="Hero heading" tab="Hero" type="text"}{/variable}
    {variable name="hero_intro" label="Hero intro" tab="Hero" type="richeditor"}{/variable}
    {variable name="hero_image" label="Hero image" tab="Hero" type="mediafinder" mode="image"}{/variable}
    {variable name="badge_style" label="Badge style" tab="Hero" type="balloon-selector" options="none:None|new:New"}{/variable}
    {variable name="attachment" label="Attachment" type="fileupload"}{/variable}
    {repeater name="highlights" prompt="Add a highlight" tab="Highlights"}
        <h2>{text name="heading" label="Heading"}{/text}</h2>
    {/repeater}
    <article>{% page %}</article>
    {% placeholder sidebar title="Sidebar" type="html" %}
    {% placeholder footer_note title="Footer" type="text" %}
    {% placeholder head_scripts type="hidden" %}
    TWIG;

    public function setUp(): void
    {
        parent::setUp();
        $this->serializer = new LayoutSchemaSerializer;
    }

    protected function serialize(bool $useContent = true): array
    {
        return $this->serializer->serializeLayout('static-default', 'Static default', $useContent, $this->markup);
    }

    protected function fieldNames(array $layout): array
    {
        return array_column($layout['fields'], 'name');
    }

    protected function field(array $layout, string $name): ?array
    {
        foreach ($layout['fields'] as $field) {
            if ($field['name'] === $name) {
                return $field;
            }
        }
        return null;
    }

    public function testEmitsImplicitPageFields()
    {
        $names = $this->fieldNames($this->serialize());

        foreach (['title', 'url', 'layout', 'is_hidden', 'navigation_hidden', 'meta_title', 'meta_description'] as $implicit) {
            $this->assertContains($implicit, $names);
            $this->assertTrue($this->field($this->serialize(), $implicit)['implicit']);
        }
    }

    public function testUrlAndLayoutAreReadonly()
    {
        $layout = $this->serialize();
        $this->assertTrue($this->field($layout, 'url')['readonly']);
        $this->assertTrue($this->field($layout, 'layout')['readonly']);
        $this->assertTrue($this->field($layout, 'title')['required']);
    }

    public function testMapsSyntaxFieldKinds()
    {
        $layout = $this->serialize();

        $this->assertSame('scalar', $this->field($layout, 'hero_heading')['kind']);
        $this->assertSame('scalar', $this->field($layout, 'hero_intro')['kind']);
        $this->assertSame('media', $this->field($layout, 'hero_image')['kind']);
        $this->assertSame('nested', $this->field($layout, 'highlights')['kind']);
        $this->assertSame('Hero', $this->field($layout, 'hero_heading')['tab']);
    }

    public function testBalloonSelectorOptionsNormalizeToMap()
    {
        $config = (array) $this->field($this->serialize(), 'badge_style')['config'];
        $this->assertSame(['none' => 'None', 'new' => 'New'], $config['options']);
    }

    public function testFileuploadIsUnknownAndReadonly()
    {
        $field = $this->field($this->serialize(), 'attachment');
        $this->assertSame('unknown', $field['kind']);
        $this->assertTrue($field['readonly']);
        $this->assertTrue($field['custom']);
    }

    public function testNestedRepeaterExposesSubFields()
    {
        $config = (array) $this->field($this->serialize(), 'highlights')['config'];
        $this->assertArrayHasKey('form', $config);
        $this->assertSame('heading', $config['form']['fields'][0]['name']);
        $this->assertSame('Add a highlight', $config['prompt']);
    }

    public function testMarkupFieldPresentOnlyWithUseContent()
    {
        $this->assertContains('markup', $this->fieldNames($this->serialize(true)));
        $this->assertNotContains('markup', $this->fieldNames($this->serialize(false)));
    }

    public function testPlaceholdersBecomePrefixedFields()
    {
        $layout = $this->serialize();
        $names = $this->fieldNames($layout);

        $this->assertContains('placeholder:sidebar', $names);
        $this->assertContains('placeholder:footer_note', $names);
        // Hidden placeholders are skipped
        $this->assertNotContains('placeholder:head_scripts', $names);

        // text placeholder → textarea editor, html placeholder → richeditor
        $this->assertSame('textarea', $this->field($layout, 'placeholder:footer_note')['type']);
        $this->assertSame('richeditor', $this->field($layout, 'placeholder:sidebar')['type']);
        $this->assertTrue(((array) $this->field($layout, 'placeholder:sidebar')['config'])['placeholder']);
    }

    public function testMalformedMarkupStillReturnsImplicitFields()
    {
        $layout = $this->serializer->serializeLayout('broken', null, true, '{variable name="x" broken');
        $names = $this->fieldNames($layout);

        // No syntax fields parsed, but the page still has its implicit fields
        $this->assertContains('title', $names);
        $this->assertContains('markup', $names);
        $this->assertSame('broken', $layout['name']);
    }

    public function testConfigIsObjectNotArrayForBareFields()
    {
        // title has no config → must serialize as {} not [] for the client
        $json = json_encode($this->field($this->serialize(), 'title'));
        $this->assertStringContainsString('"config":{}', $json);
    }
}
