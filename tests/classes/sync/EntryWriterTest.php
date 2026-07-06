<?php namespace Renick\TailorCompanion\Tests\Classes\Sync;

use PluginTestCase;
use Renick\TailorCompanion\Classes\Sync\EntryWriter;
use Renick\TailorCompanion\Models\AuditLog;
use Tailor\Classes\BlueprintIndexer;
use Tailor\Models\EntryRecord;

class EntryWriterTest extends PluginTestCase
{
    protected EntryWriter $writer;
    protected string $postUuid;
    protected string $sinkUuid;

    public function setUp(): void
    {
        parent::setUp();
        $this->migrateTailor();

        $this->writer = new EntryWriter;
        $this->postUuid = BlueprintIndexer::instance()->findSectionByHandle('Blog\Post')->uuid;
        $this->sinkUuid = BlueprintIndexer::instance()->findSectionByHandle('Demo\KitchenSink')->uuid;
    }

    protected function makeCategory(string $title): EntryRecord
    {
        $cat = EntryRecord::inSection('Blog\Category');
        $cat->title = $title;
        $cat->slug = \Str::slug($title) . '-' . random_int(10000, 99999);
        $cat->save();
        return $cat;
    }

    // -- create ---------------------------------------------------------------

    public function testCreateWithScalarsAndRelations()
    {
        $cat = $this->makeCategory('Writer Cat');

        $result = $this->writer->apply([
            'op' => 'create',
            'blueprint_uuid' => $this->postUuid,
            'local_id' => 'local-abc',
            'fields' => [
                'title' => 'Written from app',
                'content' => '<p>Body</p>',
                'excerpt' => 'Excerpt here',
                'categories' => [(int) $cat->id],
            ],
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('local-abc', $result['local_id']);
        $this->assertIsInt($result['id']);

        // Returned entry carries server-generated values (slug!)
        $this->assertNotEmpty($result['entry']['slug']);
        $this->assertSame('Written from app', $result['entry']['title']);
        $this->assertSame([(int) $cat->id], $result['entry']['fields']['categories']);

        // Persisted for real
        $fresh = EntryRecord::inSectionUuid($this->postUuid)->where('id', $result['id'])->first();
        $this->assertNotNull($fresh);
        $this->assertSame('<p>Body</p>', $fresh->content);
    }

    public function testCreateValidationErrorReturnsFieldMessages()
    {
        $result = $this->writer->apply([
            'op' => 'create',
            'blueprint_uuid' => $this->postUuid,
            'fields' => [
                // title missing → required
                'excerpt' => 'No title',
            ],
        ]);

        $this->assertSame('error', $result['status']);
        $this->assertArrayHasKey('title', $result['errors']);
    }

    public function testCreateUnknownBlueprintIsError()
    {
        $result = $this->writer->apply([
            'op' => 'create',
            'blueprint_uuid' => '00000000-0000-0000-0000-000000000000',
            'fields' => ['title' => 'X'],
        ]);

        $this->assertSame('error', $result['status']);
        $this->assertArrayHasKey('blueprint_uuid', $result['errors']);
    }

    // -- warnings (schema-change safety) ---------------------------------------------

    public function testUnknownAndReadonlyFieldsBecomeWarningsWithValues()
    {
        $result = $this->writer->apply([
            'op' => 'create',
            'blueprint_uuid' => $this->postUuid,
            'fields' => [
                'title' => 'Warning Test',
                'ghost_field' => 'value-must-come-back',
            ],
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertCount(1, $result['warnings']);
        $this->assertSame('ghost_field', $result['warnings'][0]['field']);
        $this->assertSame('unknown_field', $result['warnings'][0]['code']);
        $this->assertSame('value-must-come-back', $result['warnings'][0]['value']);

        // Inverse relation is readonly
        $tagUuid = BlueprintIndexer::instance()->findSectionByHandle('Blog\Tag')->uuid;
        $result = $this->writer->apply([
            'op' => 'create',
            'blueprint_uuid' => $tagUuid,
            'fields' => [
                'title' => 'Tag with posts write',
                'posts' => [1, 2],
            ],
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('readonly_field', $result['warnings'][0]['code']);
        $this->assertSame([1, 2], $result['warnings'][0]['value']);
    }

    // -- update & conflicts -------------------------------------------------------

    public function testUpdateWithMatchingBaseSucceeds()
    {
        $created = $this->writer->apply([
            'op' => 'create',
            'blueprint_uuid' => $this->postUuid,
            'fields' => ['title' => 'To Update'],
        ]);

        $result = $this->writer->apply([
            'op' => 'update',
            'blueprint_uuid' => $this->postUuid,
            'id' => $created['id'],
            'base_updated_at' => $created['entry']['updated_at'],
            'fields' => ['title' => 'Updated!'],
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('Updated!', $result['entry']['title']);
    }

    public function testUpdateWithStaleBaseIsConflict()
    {
        $created = $this->writer->apply([
            'op' => 'create',
            'blueprint_uuid' => $this->postUuid,
            'fields' => ['title' => 'Conflict Base'],
        ]);

        $result = $this->writer->apply([
            'op' => 'update',
            'blueprint_uuid' => $this->postUuid,
            'id' => $created['id'],
            'base_updated_at' => '2020-01-01T00:00:00+00:00',
            'fields' => ['title' => 'Stale write'],
        ]);

        $this->assertSame('conflict', $result['status']);
        $this->assertSame('Conflict Base', $result['server_state']['title'], 'Server state returned untouched');

        // Server record unchanged
        $fresh = EntryRecord::inSectionUuid($this->postUuid)->where('id', $created['id'])->first();
        $this->assertSame('Conflict Base', $fresh->title);
    }

    public function testUpdateOfDeletedRecordIsDeletedConflict()
    {
        $result = $this->writer->apply([
            'op' => 'update',
            'blueprint_uuid' => $this->postUuid,
            'id' => 424242,
            'fields' => ['title' => 'Ghost'],
        ]);

        $this->assertSame('conflict', $result['status']);
        $this->assertSame('deleted', $result['server_state']);
    }

    public function testRelationOnlyUpdateBumpsUpdatedAt()
    {
        $cat = $this->makeCategory('Bump Cat');
        $created = $this->writer->apply([
            'op' => 'create',
            'blueprint_uuid' => $this->postUuid,
            'fields' => ['title' => 'Relation Bump'],
        ]);

        $before = $created['entry']['updated_at'];
        sleep(1); // second-precision timestamps

        $result = $this->writer->apply([
            'op' => 'update',
            'blueprint_uuid' => $this->postUuid,
            'id' => $created['id'],
            'base_updated_at' => $before,
            'fields' => ['categories' => [(int) $cat->id]],
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertNotSame($before, $result['entry']['updated_at'], 'updated_at must change on relation-only update');
    }

    // -- delete -----------------------------------------------------------------

    public function testDeleteAndIdempotency()
    {
        $created = $this->writer->apply([
            'op' => 'create',
            'blueprint_uuid' => $this->postUuid,
            'fields' => ['title' => 'To Delete'],
        ]);

        $result = $this->writer->apply([
            'op' => 'delete',
            'blueprint_uuid' => $this->postUuid,
            'id' => $created['id'],
            'base_updated_at' => $created['entry']['updated_at'],
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertNull(EntryRecord::inSectionUuid($this->postUuid)->where('id', $created['id'])->first());

        // Deleting again is idempotent ok
        $again = $this->writer->apply([
            'op' => 'delete',
            'blueprint_uuid' => $this->postUuid,
            'id' => $created['id'],
        ]);
        $this->assertSame('ok', $again['status']);
    }

    // -- nested repeater ------------------------------------------------------------

    public function testRepeaterReplaceAllSemantics()
    {
        $created = $this->writer->apply([
            'op' => 'create',
            'blueprint_uuid' => $this->sinkUuid,
            'fields' => [
                'title' => 'Repeater Writer',
                'steps' => [
                    ['step_title' => 'One', 'duration' => 1],
                    ['step_title' => 'Two', 'duration' => 2],
                ],
            ],
        ]);

        $this->assertSame('ok', $created['status']);
        $steps = $created['entry']['fields']['steps'];
        $this->assertCount(2, $steps);
        $this->assertSame('One', $steps[0]['step_title']);

        // Update: keep item 1 (by _id), drop item 2, add a new one
        $result = $this->writer->apply([
            'op' => 'update',
            'blueprint_uuid' => $this->sinkUuid,
            'id' => $created['id'],
            'base_updated_at' => $created['entry']['updated_at'],
            'fields' => [
                'steps' => [
                    ['_id' => $steps[0]['_id'], 'step_title' => 'One edited', 'duration' => 11],
                    ['step_title' => 'Three', 'duration' => 3],
                ],
            ],
        ]);

        $this->assertSame('ok', $result['status']);
        $newSteps = $result['entry']['fields']['steps'];
        $this->assertCount(2, $newSteps);
        $this->assertSame('One edited', $newSteps[0]['step_title']);
        $this->assertSame($steps[0]['_id'], $newSteps[0]['_id'], 'Existing item updated in place');
        $this->assertSame('Three', $newSteps[1]['step_title']);
    }

    public function testGroupedRepeaterCreateAndUpdate()
    {
        $created = $this->writer->apply([
            'op' => 'create',
            'blueprint_uuid' => $this->sinkUuid,
            'fields' => [
                'title' => 'Grouped Repeater',
                'blocks' => [
                    ['_group' => 'text_block', 'heading' => 'Intro', 'body' => 'Hello'],
                    ['_group' => 'quote_block', 'quote' => 'To be or not to be'],
                ],
            ],
        ]);

        $this->assertSame('ok', $created['status'], json_encode($created['errors'] ?? []));
        $blocks = $created['entry']['fields']['blocks'];
        $this->assertCount(2, $blocks);
        $this->assertSame('text_block', $blocks[0]['_group']);
        $this->assertSame('Intro', $blocks[0]['heading']);
        $this->assertSame('quote_block', $blocks[1]['_group']);
        $this->assertSame('To be or not to be', $blocks[1]['quote']);
    }

    public function testNestedItemCannotReparentViaHostId()
    {
        $created = $this->writer->apply([
            'op' => 'create',
            'blueprint_uuid' => $this->sinkUuid,
            'fields' => [
                'title' => 'Reparent Attempt',
                'steps' => [['step_title' => 'One', 'duration' => 1]],
            ],
        ]);
        $stepId = $created['entry']['fields']['steps'][0]['_id'];

        // Malicious update trying to rewrite the repeater's host
        $result = $this->writer->apply([
            'op' => 'update',
            'blueprint_uuid' => $this->sinkUuid,
            'id' => $created['id'],
            'base_updated_at' => $created['entry']['updated_at'],
            'fields' => [
                'steps' => [[
                    '_id' => $stepId, 'step_title' => 'One edited',
                    'host_id' => 999999, 'host_field' => 'evil',
                ]],
            ],
        ]);

        $this->assertSame('ok', $result['status']);
        $step = $result['entry']['fields']['steps'][0];
        $this->assertSame('One edited', $step['step_title']);
        // host_id was ignored — the item still belongs to our entry
        $this->assertSame($stepId, $step['_id']);
    }

    // -- custom fields ---------------------------------------------------------------

    public function testCustomFieldRoundTripsLosslessly()
    {
        $created = $this->writer->apply([
            'op' => 'create',
            'blueprint_uuid' => $this->sinkUuid,
            'fields' => [
                'title' => 'Custom Field Writer',
                'custom_widget' => 'opaque-blob-☂-unchanged',
            ],
        ]);

        $this->assertSame('ok', $created['status']);
        $this->assertSame([], $created['warnings'], 'Known custom field is writable, not a warning');
        $this->assertSame('opaque-blob-☂-unchanged', $created['entry']['fields']['custom_widget']);
    }

    // -- audit log ----------------------------------------------------------------------

    public function testMutationsWriteAuditDiffs()
    {
        AuditLog::query()->delete();

        $created = $this->writer->apply([
            'op' => 'create',
            'blueprint_uuid' => $this->postUuid,
            'fields' => ['title' => 'Audited Post'],
        ]);

        $log = AuditLog::orderByDesc('id')->first();
        $this->assertSame('create', $log->action);
        $this->assertSame($this->postUuid, $log->blueprint_uuid);
        $this->assertSame($created['id'], (int) $log->record_id);
        $this->assertSame(['from' => null, 'to' => 'Audited Post'], $log->diff['title']);

        $this->writer->apply([
            'op' => 'update',
            'blueprint_uuid' => $this->postUuid,
            'id' => $created['id'],
            'fields' => ['title' => 'Audited Post v2'],
        ]);

        $log = AuditLog::orderByDesc('id')->first();
        $this->assertSame('update', $log->action);
        $this->assertSame(['from' => 'Audited Post', 'to' => 'Audited Post v2'], $log->diff['title']);

        $this->writer->apply([
            'op' => 'delete',
            'blueprint_uuid' => $this->postUuid,
            'id' => $created['id'],
        ]);

        $log = AuditLog::orderByDesc('id')->first();
        $this->assertSame('delete', $log->action);
    }
}
