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

    public function testNestedTypesMapToNested()
    {
        $this->assertSame('nested', $this->map->kindFor('repeater'));
        $this->assertSame('nested', $this->map->kindFor('nestedform'));
        $this->assertSame('nested', $this->map->kindFor('nesteditems'));
    }

    public function testJsonTypesMapToJson()
    {
        $this->assertSame('json', $this->map->kindFor('taglist'));
        $this->assertSame('json', $this->map->kindFor('checkboxlist'));
        $this->assertSame('json', $this->map->kindFor('datatable'));
    }

    public function testPresentationalTypesMapToPresentational()
    {
        $this->assertSame('presentational', $this->map->kindFor('ruler'));
        $this->assertSame('presentational', $this->map->kindFor('section'));
    }

    public function testUnknownTypesFallBackToUnknown()
    {
        $this->assertSame('unknown', $this->map->kindFor('fileupload'));
        $this->assertSame('unknown', $this->map->kindFor('some-future-widget'));
    }

    public function testReadonlyTypes()
    {
        $this->assertTrue($this->map->isReadonly('fileupload'));
        // Presentational chrome carries no value → never written.
        $this->assertTrue($this->map->isReadonly('ruler'));
        $this->assertTrue($this->map->isReadonly('section'));
        $this->assertFalse($this->map->isReadonly('text'));
        $this->assertFalse($this->map->isReadonly('taglist'));
    }
}
