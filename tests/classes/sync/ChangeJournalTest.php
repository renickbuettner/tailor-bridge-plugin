<?php namespace Renick\TailorCompanion\Tests\Classes\Sync;

use Date;
use PluginTestCase;
use Renick\TailorCompanion\Classes\Sync\ChangeJournal;
use Renick\TailorCompanion\Models\JournalEntry;
use Tailor\Models\EntryRecord;

class ChangeJournalTest extends PluginTestCase
{
    protected ChangeJournal $journal;

    public function setUp(): void
    {
        parent::setUp();

        // Content tables for the theme blueprints (Blog\Post etc.)
        $this->migrateTailor();
        ChangeJournal::resetTableReadyCache();

        $this->journal = new ChangeJournal;
    }

    protected function makePost(string $title = 'Journal Test Post'): EntryRecord
    {
        $post = EntryRecord::inSection('Blog\Post');
        $post->title = $title;
        $post->slug = \Str::slug($title) . '-' . str_pad((string) random_int(0, 99999), 5, '0');
        $post->save();

        return $post;
    }

    // -- Event capture (integration through real model events) --------------

    public function testCreateIsJournaled()
    {
        $post = $this->makePost();

        $entry = JournalEntry::orderByDesc('id')->first();
        $this->assertNotNull($entry);
        $this->assertSame(JournalEntry::ACTION_CREATED, $entry->action);
        $this->assertSame((int) $post->id, (int) $entry->record_id);
        $this->assertSame($post->blueprint_uuid, $entry->blueprint_uuid);
    }

    public function testUpdateIsJournaled()
    {
        $post = $this->makePost();
        JournalEntry::query()->delete();

        $post->title = 'Updated Title';
        $post->save();

        $entry = JournalEntry::orderByDesc('id')->first();
        $this->assertNotNull($entry);
        $this->assertSame(JournalEntry::ACTION_UPDATED, $entry->action);
    }

    public function testDeleteIsJournaled()
    {
        $post = $this->makePost();
        JournalEntry::query()->delete();

        $post->delete();

        $entry = JournalEntry::orderByDesc('id')->first();
        $this->assertNotNull($entry);
        $this->assertSame(JournalEntry::ACTION_DELETED, $entry->action);
    }

    // -- Canonical filter ----------------------------------------------------

    public function testNonCanonicalRecordsAreNotJournaled()
    {
        $draft = EntryRecord::inSection('Blog\Post');
        $draft->draft_mode = 2;
        $this->assertFalse($this->journal->isCanonical($draft));
        $this->assertNull($this->journal->record($draft, JournalEntry::ACTION_CREATED));

        $version = EntryRecord::inSection('Blog\Post');
        $version->is_version = true;
        $this->assertFalse($this->journal->isCanonical($version));
        $this->assertNull($this->journal->record($version, JournalEntry::ACTION_CREATED));

        $canonical = EntryRecord::inSection('Blog\Post');
        $this->assertTrue($this->journal->isCanonical($canonical));
    }

    // -- Coalescing ----------------------------------------------------------

    protected function seedJournal(array $rows): void
    {
        foreach ($rows as [$uuid, $recordId, $action]) {
            $entry = new JournalEntry;
            $entry->blueprint_uuid = $uuid;
            $entry->record_id = $recordId;
            $entry->action = $action;
            $entry->created_at = Date::now();
            $entry->save();
        }
    }

    public function testChangesSinceCoalescesMultipleUpdates()
    {
        $this->seedJournal([
            ['bp-1', 1, 'updated'],
            ['bp-1', 1, 'updated'],
            ['bp-1', 1, 'updated'],
        ]);

        $result = $this->journal->changesSince(0);

        $this->assertCount(1, $result['changes']);
        $this->assertSame('updated', $result['changes'][0]['action']);
        $this->assertSame(JournalEntry::max('id'), $result['latest_cursor']);
    }

    public function testChangesSinceCreatedThenUpdatedIsCreated()
    {
        $this->seedJournal([
            ['bp-1', 2, 'created'],
            ['bp-1', 2, 'updated'],
        ]);

        $result = $this->journal->changesSince(0);

        $this->assertCount(1, $result['changes']);
        $this->assertSame('created', $result['changes'][0]['action']);
    }

    public function testChangesSinceCreatedThenDeletedCancelsOut()
    {
        $this->seedJournal([
            ['bp-1', 3, 'created'],
            ['bp-1', 3, 'updated'],
            ['bp-1', 3, 'deleted'],
        ]);

        $result = $this->journal->changesSince(0);

        $this->assertCount(0, $result['changes']);
        $this->assertSame(JournalEntry::max('id'), $result['latest_cursor'], 'Cursor still advances');
    }

    public function testChangesSinceUpdatedThenDeletedIsDeleted()
    {
        $this->seedJournal([
            ['bp-1', 4, 'updated'],
            ['bp-1', 4, 'deleted'],
        ]);

        $result = $this->journal->changesSince(0);

        $this->assertCount(1, $result['changes']);
        $this->assertSame('deleted', $result['changes'][0]['action']);
    }

    public function testChangesSinceDeletedThenRestoredIsUpdated()
    {
        // Soft delete + restore: deleted then updated → app should re-fetch
        $this->seedJournal([
            ['bp-1', 5, 'deleted'],
            ['bp-1', 5, 'updated'],
        ]);

        $result = $this->journal->changesSince(0);

        $this->assertCount(1, $result['changes']);
        $this->assertSame('updated', $result['changes'][0]['action']);
    }

    public function testChangesSinceRespectsCursor()
    {
        $this->seedJournal([['bp-1', 6, 'created']]);
        $cursor = JournalEntry::max('id');
        $this->seedJournal([['bp-1', 7, 'created']]);

        $result = $this->journal->changesSince($cursor);

        $this->assertCount(1, $result['changes']);
        $this->assertSame(7, $result['changes'][0]['record_id']);
    }

    public function testChangesSinceSeparatesBlueprintsAndRecords()
    {
        $this->seedJournal([
            ['bp-1', 8, 'updated'],
            ['bp-2', 8, 'updated'],
            ['bp-1', 9, 'updated'],
        ]);

        $result = $this->journal->changesSince(0);

        $this->assertCount(3, $result['changes']);
    }

    // -- Pruning ---------------------------------------------------------------

    public function testPruneRemovesOldRows()
    {
        $old = new JournalEntry;
        $old->blueprint_uuid = 'bp-old';
        $old->record_id = 1;
        $old->action = 'updated';
        $old->created_at = Date::now()->subDays(90);
        $old->save();

        $this->seedJournal([['bp-new', 2, 'updated']]);

        $removed = $this->journal->prune(30);

        $this->assertSame(1, $removed);
        $this->assertSame(1, JournalEntry::count());
        $this->assertSame('bp-new', JournalEntry::first()->blueprint_uuid);
    }
}
