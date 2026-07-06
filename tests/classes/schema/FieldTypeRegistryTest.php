<?php namespace Renick\TailorCompanion\Tests\Classes\Schema;

use PluginTestCase;
use Renick\TailorCompanion\Classes\Schema\FieldTypeRegistry;
use Tailor\Classes\BlueprintIndexer;

class FieldTypeRegistryTest extends PluginTestCase
{
    protected FieldTypeRegistry $registry;
    protected $fieldset;

    public function setUp(): void
    {
        parent::setUp();
        $this->registry = new FieldTypeRegistry;

        $blueprint = BlueprintIndexer::instance()->findSectionByHandle('Demo\KitchenSink');
        $this->assertNotNull($blueprint, 'Kitchen sink fixture blueprint missing');
        $this->fieldset = BlueprintIndexer::instance()->findContentFieldset($blueprint->uuid);
    }

    protected function field(string $name)
    {
        $field = $this->fieldset->getField($name);
        $this->assertNotNull($field, "Field {$name} missing from kitchen sink");
        return $field;
    }

    /**
     * @dataProvider kindProvider
     */
    public function testKindClassification(string $fieldName, string $expectedKind)
    {
        $this->assertSame($expectedKind, $this->registry->kindFor($this->field($fieldName)));
    }

    public static function kindProvider(): array
    {
        return [
            ['subtitle', 'scalar'],       // text (GenericField)
            ['summary', 'scalar'],        // textarea
            ['body', 'scalar'],           // richeditor
            ['notes', 'scalar'],          // markdown
            ['rating', 'scalar'],         // number
            ['is_active', 'scalar'],      // switch
            ['color_choice', 'scalar'],   // dropdown
            ['event_at', 'scalar'],       // datepicker
            ['accent_color', 'scalar'],   // colorpicker (FallbackField, known core)
            ['toppings', 'json'],         // checkboxlist
            ['keywords', 'json'],         // taglist
            ['hero_image', 'media'],      // mediafinder
            ['gallery', 'attachment'],    // fileupload
            ['main_category', 'relation'],// entries maxItems 1
            ['related_tags', 'relation'], // entries multi
            ['steps', 'nested'],          // repeater
            ['custom_widget', 'unknown'], // unregistered type
        ];
    }

    public function testCustomDetection()
    {
        $this->assertTrue($this->registry->isCustom($this->field('custom_widget')));

        foreach (['subtitle', 'body', 'accent_color', 'toppings', 'gallery', 'main_category', 'steps'] as $core) {
            $this->assertFalse($this->registry->isCustom($this->field($core)), "{$core} wrongly flagged custom");
        }
    }
}
