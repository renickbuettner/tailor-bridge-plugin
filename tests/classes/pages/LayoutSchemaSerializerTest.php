<?php namespace Renick\TailorCompanion\Tests\Classes\Pages;

use PluginTestCase;
use Renick\TailorCompanion\Classes\Pages\LayoutSchemaSerializer;
use Renick\TailorCompanion\Classes\Pages\PageFormResolver;

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
    {variable name="use_toc" label="Table of contents" type="switch"}{/variable}
    {repeater name="highlights" prompt="Add a highlight" tab="Highlights"}
        <h2>{text name="heading" label="Heading"}{/text}</h2>
    {/repeater}
    {repeater name="blocks" prompt="Add a block"}
        <div class="block">no inline field tags — external form</div>
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
        $field = $this->field($this->serialize(), 'highlights');
        $config = (array) $field['config'];
        $this->assertArrayHasKey('form', $config);
        $this->assertSame('heading', $config['form']['fields'][0]['name']);
        $this->assertSame('Add a highlight', $config['prompt']);
        // An inline-defined repeater stays editable.
        $this->assertFalse($field['readonly']);
    }

    public function testSwitchFieldIsScalar()
    {
        $field = $this->field($this->serialize(), 'use_toc');
        $this->assertSame('scalar', $field['kind']);
        $this->assertSame('switch', $field['type']);
        $this->assertFalse($field['readonly']);
        $this->assertFalse($field['custom']);
    }

    public function testRepeaterWithoutInlineFieldsIsReadonly()
    {
        // A page-builder repeater with no inline sub-fields can't be edited by
        // the generic form — mark it read-only so the value round-trips.
        $field = $this->field($this->serialize(), 'blocks');
        $this->assertSame('nested', $field['kind']);
        $this->assertTrue($field['readonly'], 'Repeater without sub-fields must be read-only');
        // Nothing resolved → no form/groups in the config at all.
        $config = (array) $field['config'];
        $this->assertArrayNotHasKey('form', $config);
        $this->assertArrayNotHasKey('groups', $config);
    }

    // -- External form/groups resolution -------------------------------------

    /**
     * Fake resolver so the tests don't touch the filesystem — maps known ref
     * strings to field/group definitions.
     */
    protected function resolvingSerializer(): LayoutSchemaSerializer
    {
        $fake = new class extends PageFormResolver {
            public function resolveForm($ref): array
            {
                if ($ref === 'form-ref') {
                    return [
                        'headline' => ['type' => 'text', 'label' => 'Headline'],
                        'enabled' => ['type' => 'switch', 'label' => 'Enabled'],
                    ];
                }
                return parent::resolveForm($ref);
            }

            public function resolveGroups($ref): array
            {
                if ($ref === 'blocks-ref') {
                    return [
                        'heading' => ['name' => 'Heading', 'fields' => [
                            'text' => ['type' => 'text', 'label' => 'Text'],
                            'level' => ['type' => 'dropdown', 'label' => 'Level', 'options' => ['h1' => 'H1', 'h2' => 'H2']],
                        ]],
                        'gallery' => ['name' => 'Gallery', 'fields' => [
                            // nested-within-nested: a repeater inside a group
                            'images' => ['type' => 'repeater', 'label' => 'Images', 'fields' => [
                                'src' => ['type' => 'mediafinder'],
                                'caption' => ['type' => 'text'],
                            ]],
                        ]],
                    ];
                }
                return parent::resolveGroups($ref);
            }
        };

        return new LayoutSchemaSerializer($fake);
    }

    protected function findByName(array $fields, string $name): ?array
    {
        foreach ($fields as $field) {
            if ($field['name'] === $name) {
                return $field;
            }
        }
        return null;
    }

    public function testExternalFormRepeaterBecomesEditable()
    {
        $layout = $this->resolvingSerializer()->serializeLayout('l', 'L', false,
            '{repeater name="items" prompt="Add" form="form-ref"}<div></div>{/repeater}');

        $field = $this->field($layout, 'items');
        $this->assertFalse($field['readonly']);
        $config = (array) $field['config'];
        $sub = $config['form']['fields'];
        $this->assertSame('headline', $sub[0]['name']);
        $enabled = $this->findByName($sub, 'enabled');
        $this->assertSame('scalar', $enabled['kind']);   // switch → scalar
    }

    public function testExternalGroupsRepeaterExposesGroupsAndNesting()
    {
        $layout = $this->resolvingSerializer()->serializeLayout('l', 'L', false,
            '{repeater name="content_sections" prompt="Add section" groups="blocks-ref"}<div></div>{/repeater}');

        $field = $this->field($layout, 'content_sections');
        $this->assertFalse($field['readonly'], 'Resolvable groups repeater is editable');
        $config = (array) $field['config'];
        $this->assertArrayHasKey('groups', $config);
        $this->assertSame('Heading', $config['groups']['heading']['name']);

        // group field serialized to the wire (dropdown → scalar + options)
        $level = $this->findByName($config['groups']['heading']['fields'], 'level');
        $this->assertSame('scalar', $level['kind']);
        $this->assertSame(['h1' => 'H1', 'h2' => 'H2'], (array) $level['config']['options']);

        // nested-within-nested: images repeater inside the gallery group
        $images = $this->findByName($config['groups']['gallery']['fields'], 'images');
        $this->assertSame('nested', $images['kind']);
        $this->assertFalse($images['readonly']);
        $src = $this->findByName($images['config']['form']['fields'], 'src');
        $this->assertSame('media', $src['kind']);
    }

    public function testUnresolvableGroupsStaysReadonly()
    {
        // Default (real) resolver: an unknown ref resolves to nothing → readonly.
        $layout = $this->serializer->serializeLayout('l', 'L', false,
            '{repeater name="x" groups="$/nope.yaml"}<div></div>{/repeater}');
        $this->assertTrue($this->field($layout, 'x')['readonly']);
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
