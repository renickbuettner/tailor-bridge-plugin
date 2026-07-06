<?php namespace Renick\TailorCompanion\Tests\Classes\Schema;

use PluginTestCase;
use Renick\TailorCompanion\Classes\Schema\SchemaSerializer;
use Tailor\Classes\BlueprintIndexer;

class SchemaSerializerTest extends PluginTestCase
{
    protected SchemaSerializer $serializer;

    public function setUp(): void
    {
        parent::setUp();
        $this->migrateTailor();
        $this->serializer = new SchemaSerializer;
    }

    protected function findSerialized(array $schema, string $handle): ?array
    {
        foreach ($schema['blueprints'] as $blueprint) {
            if ($blueprint['handle'] === $handle) {
                return $blueprint;
            }
        }
        return null;
    }

    protected function findField(array $blueprint, string $name): ?array
    {
        foreach ($blueprint['fields'] as $field) {
            if ($field['name'] === $name) {
                return $field;
            }
        }
        return null;
    }

    public function testSerializeAggregatesAllBlueprints()
    {
        $schema = $this->serializer->serialize();

        $this->assertNotEmpty($schema['schema_version']);
        $this->assertNotNull($this->findSerialized($schema, 'Blog\Post'));
        $this->assertNotNull($this->findSerialized($schema, 'Blog\Category'));
        $this->assertNotNull($this->findSerialized($schema, 'Blog\Tag'));
        $this->assertNotNull($this->findSerialized($schema, 'Demo\KitchenSink'));
    }

    public function testBlueprintLevelMetadata()
    {
        $schema = $this->serializer->serialize();

        $post = $this->findSerialized($schema, 'Blog\Post');
        $this->assertSame('stream', $post['type']);
        $this->assertNull($post['structure']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $post['fingerprint']);
        $this->assertIsInt($post['entry_count']);

        $category = $this->findSerialized($schema, 'Blog\Category');
        $this->assertSame('structure', $category['type']);
        $this->assertSame(['max_depth' => 1, 'tree' => false], $category['structure']);
    }

    public function testImplicitFieldsAreIncluded()
    {
        $schema = $this->serializer->serialize();
        $post = $this->findSerialized($schema, 'Blog\Post');

        foreach (['title', 'slug', 'is_enabled', 'published_at', 'expired_at'] as $name) {
            $field = $this->findField($post, $name);
            $this->assertNotNull($field, "Implicit field {$name} missing");
            $this->assertTrue($field['implicit']);
        }

        $title = $this->findField($post, 'title');
        $this->assertTrue($title['required']);
        $this->assertSame('scalar', $title['kind']);
    }

    public function testRelationFieldResolvesSourceUuid()
    {
        $schema = $this->serializer->serialize();
        $post = $this->findSerialized($schema, 'Blog\Post');

        $categories = $this->findField($post, 'categories');
        $this->assertSame('relation', $categories['kind']);
        $this->assertSame('entries', $categories['type']);

        $categoryBlueprint = BlueprintIndexer::instance()->findSectionByHandle('Blog\Category');
        $this->assertSame($categoryBlueprint->uuid, $categories['config']['source_uuid']);
    }

    public function testInverseRelationIsReadonlyAndHidden()
    {
        $schema = $this->serializer->serialize();
        $tag = $this->findSerialized($schema, 'Blog\Tag');

        $posts = $this->findField($tag, 'posts');
        $this->assertNotNull($posts);
        $this->assertTrue($posts['readonly'], 'Inverse relation must be readonly');
        $this->assertTrue($posts['hidden']);
    }

    public function testKitchenSinkFieldNormalization()
    {
        $schema = $this->serializer->serialize();
        $sink = $this->findSerialized($schema, 'Demo\KitchenSink');

        // Options pass through
        $dropdown = $this->findField($sink, 'color_choice');
        $this->assertSame(['red' => 'Red', 'green' => 'Green', 'blue' => 'Blue'], $dropdown['config']['options']);

        // Datepicker mode
        $date = $this->findField($sink, 'event_at');
        $this->assertSame('datetime', $date['config']['mode']);

        // Singular relation max_items
        $mainCategory = $this->findField($sink, 'main_category');
        $this->assertSame(1, $mainCategory['config']['max_items']);

        // Attachment max_files
        $gallery = $this->findField($sink, 'gallery');
        $this->assertSame('attachment', $gallery['kind']);
        $this->assertSame(3, $gallery['config']['max_files']);

        // Custom placeholder
        $custom = $this->findField($sink, 'custom_widget');
        $this->assertTrue($custom['custom']);
        $this->assertSame('unknown', $custom['kind']);
        $this->assertSame('acme_fancy_widget', $custom['type']);
    }

    public function testRepeaterSubFieldsAreSerialized()
    {
        $schema = $this->serializer->serialize();
        $sink = $this->findSerialized($schema, 'Demo\KitchenSink');

        $steps = $this->findField($sink, 'steps');
        $this->assertSame('nested', $steps['kind']);

        $subFields = $steps['config']['form']['fields'];
        $names = array_column($subFields, 'name');
        $this->assertContains('step_title', $names);
        $this->assertContains('duration', $names);

        foreach ($subFields as $sub) {
            $this->assertSame('scalar', $sub['kind']);
            $this->assertFalse($sub['custom']);
        }
    }

    public function testFingerprintExcludesVolatileData()
    {
        $blueprint = BlueprintIndexer::instance()->findSectionByHandle('Blog\Post');
        $base = $this->serializer->baseStructure($blueprint);

        $this->assertArrayNotHasKey('entry_count', $base);
        $this->assertArrayNotHasKey('fingerprint', $base);
    }
}
