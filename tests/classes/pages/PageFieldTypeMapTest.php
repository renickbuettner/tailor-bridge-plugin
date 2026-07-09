<?php namespace Renick\TailorCompanion\Tests\Classes\Pages;

use PluginTestCase;
use Renick\TailorCompanion\Classes\Pages\PageFieldTypeMap;

class PageFieldTypeMapTest extends PluginTestCase
{
    protected PageFieldTypeMap $map;

    public function setUp(): void
    {
        parent::setUp();
        $this->map = new PageFieldTypeMap;
    }

    public function testScalarTypesMapToScalar()
    {
        foreach (['text', 'textarea', 'richeditor', 'markdown', 'dropdown', 'checkbox', 'switch', 'datepicker', 'colorpicker', 'balloon-selector', 'number', 'radio', 'email', 'password'] as $type) {
            $this->assertSame('scalar', $this->map->kindFor($type), "{$type} should be scalar");
        }
    }

    public function testMediafinderMapsToMedia()
    {
        $this->assertSame('media', $this->map->kindFor('mediafinder'));
    }

    public function testRepeaterMapsToNested()
    {
        $this->assertSame('nested', $this->map->kindFor('repeater'));
    }

    public function testUnknownTypesFallBackToUnknown()
    {
        $this->assertSame('unknown', $this->map->kindFor('fileupload'));
        $this->assertSame('unknown', $this->map->kindFor('some-future-widget'));
    }

    public function testFileuploadIsReadonly()
    {
        $this->assertTrue($this->map->isReadonly('fileupload'));
        $this->assertFalse($this->map->isReadonly('text'));
    }
}
