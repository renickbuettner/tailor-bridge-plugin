<?php namespace Renick\TailorCompanion\Tests\Classes\Sync;

use Date;
use PluginTestCase;
use Renick\TailorCompanion\Classes\Sync\EntryTransformer;
use Tailor\Models\EntryRecord;

class EntryTransformerTest extends PluginTestCase
{
    protected EntryTransformer $transformer;

    public function setUp(): void
    {
        parent::setUp();
        $this->migrateTailor();
        $this->transformer = new EntryTransformer;
    }

    protected function makeCategory(string $title = 'Category A'): EntryRecord
    {
        $cat = EntryRecord::inSection('Blog\Category');
        $cat->title = $title;
        $cat->slug = \Str::slug($title) . '-' . random_int(10000, 99999);
        $cat->save();
        return $cat;
    }

    protected function makeTag(string $title): EntryRecord
    {
        $tag = EntryRecord::inSection('Blog\Tag');
        $tag->title = $title;
        $tag->slug = \Str::slug($title) . '-' . random_int(10000, 99999);
        $tag->save();
        return $tag;
    }

    public function testEnvelopeAndScalars()
    {
        $publishedAt = Date::create(2026, 7, 1, 12, 0, 0);

        $post = EntryRecord::inSection('Blog\Post');
        $post->title = 'Wire Post';
        $post->slug = 'wire-post';
        $post->content = '<p>Hello <strong>world</strong></p>';
        $post->excerpt = 'Short summary';
        $post->is_featured = true;
        $post->published_at = $publishedAt;
        $post->save();

        $wire = $this->transformer->transform($post->fresh());

        $this->assertSame((int) $post->id, $wire['id']);
        $this->assertSame($post->blueprint_uuid, $wire['blueprint_uuid']);
        $this->assertSame('Wire Post', $wire['title']);
        $this->assertSame('wire-post', $wire['slug']);
        $this->assertTrue($wire['is_enabled']);
        $this->assertStringStartsWith('2026-07-01T12:00:00', $wire['published_at']);

        // richeditor HTML raw, textarea raw, switch boolean
        $this->assertSame('<p>Hello <strong>world</strong></p>', $wire['fields']['content']);
        $this->assertSame('Short summary', $wire['fields']['excerpt']);
        $this->assertEquals(true, (bool) $wire['fields']['is_featured']);
    }

    public function testMultiRelationsAsIdsWithLabels()
    {
        $catA = $this->makeCategory('Cat A');
        $catB = $this->makeCategory('Cat B');
        $tag = $this->makeTag('Tag X');

        $post = EntryRecord::inSection('Blog\Post');
        $post->title = 'Related Post';
        $post->slug = 'related-post';
        $post->save();
        $post->categories()->add($catA);
        $post->categories()->add($catB);
        $post->tags()->add($tag);

        $wire = $this->transformer->transform($post->fresh());

        $this->assertEqualsCanonicalizing([(int) $catA->id, (int) $catB->id], $wire['fields']['categories']);
        $this->assertSame([(int) $tag->id], $wire['fields']['tags']);

        $labels = collect($wire['relation_labels']['categories'])->pluck('title')->all();
        $this->assertEqualsCanonicalizing(['Cat A', 'Cat B'], $labels);
    }

    public function testInverseRelationYieldsIds()
    {
        $tag = $this->makeTag('Inverse Tag');

        $post = EntryRecord::inSection('Blog\Post');
        $post->title = 'Tagged Post';
        $post->slug = 'tagged-post';
        $post->save();
        $post->tags()->add($tag);

        $wire = $this->transformer->transform($tag->fresh());

        $this->assertSame([(int) $post->id], $wire['fields']['posts']);
    }

    public function testKitchenSinkKinds()
    {
        $cat = $this->makeCategory('Main Cat');

        $sink = EntryRecord::inSection('Demo\KitchenSink');
        $sink->title = 'Sink Entry';
        $sink->slug = 'sink-entry';
        $sink->keywords = ['swift', 'tailor'];
        $sink->toppings = ['cheese', 'basil'];
        $sink->hero_image = '/media/hero.jpg';
        $sink->custom_widget = 'opaque-value-untouched';
        $sink->main_category = $cat->id;
        $sink->event_at = Date::create(2026, 12, 24, 18, 30);
        $sink->save();
        $sink->steps()->create(['step_title' => 'First', 'duration' => 10]);
        $sink->steps()->create(['step_title' => 'Second', 'duration' => 20]);

        $wire = $this->transformer->transform($sink->fresh());
        $fields = $wire['fields'];

        // json kinds → arrays
        $this->assertSame(['swift', 'tailor'], $fields['keywords']);
        $this->assertSame(['cheese', 'basil'], $fields['toppings']);

        // media → path string
        $this->assertSame('/media/hero.jpg', $fields['hero_image']);

        // unknown/custom → lossless raw passthrough
        $this->assertSame('opaque-value-untouched', $fields['custom_widget']);

        // singular relation → id + label
        $this->assertSame((int) $cat->id, $fields['main_category']);
        $this->assertSame('Main Cat', $wire['relation_labels']['main_category'][0]['title']);

        // datepicker → ISO 8601
        $this->assertStringStartsWith('2026-12-24T18:30:00', $fields['event_at']);

        // attachment (empty) → array
        $this->assertSame([], $fields['gallery']);

        // nested repeater → ordered items with _id and sub-fields, no internals
        $steps = $fields['steps'];
        $this->assertCount(2, $steps);
        $this->assertSame('First', $steps[0]['step_title']);
        $this->assertEquals(10, $steps[0]['duration']);
        $this->assertSame('Second', $steps[1]['step_title']);
        $this->assertArrayHasKey('_id', $steps[0]);
        $this->assertArrayNotHasKey('host_id', $steps[0]);
        $this->assertArrayNotHasKey('content_value', $steps[0]);
    }

    public function testEmptyRelationsAndNested()
    {
        $sink = EntryRecord::inSection('Demo\KitchenSink');
        $sink->title = 'Empty Sink';
        $sink->slug = 'empty-sink';
        $sink->save();

        $wire = $this->transformer->transform($sink->fresh());

        $this->assertNull($wire['fields']['main_category']);
        $this->assertSame([], $wire['fields']['related_tags']);
        $this->assertSame([], $wire['fields']['steps']);
        $this->assertSame([], $wire['fields']['gallery']);
    }
}
