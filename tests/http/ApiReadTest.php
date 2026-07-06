<?php namespace Renick\TailorCompanion\Tests\Http;

use Backend\Models\User;
use Date;
use PluginTestCase;
use Renick\TailorCompanion\Classes\Auth\TokenManager;
use Renick\TailorCompanion\Classes\Sync\ChangeJournal;
use Renick\TailorCompanion\Models\JournalEntry;
use Tailor\Classes\BlueprintIndexer;
use Tailor\Models\EntryRecord;

class ApiReadTest extends PluginTestCase
{
    protected array $authHeader;

    public function setUp(): void
    {
        parent::setUp();
        $this->migrateTailor();
        ChangeJournal::resetTableReadyCache();

        $user = new User;
        $user->first_name = 'Read';
        $user->last_name = 'Tester';
        $user->login = 'readtester';
        $user->email = 'readtester@example.com';
        $user->password = 'read-pass-12345';
        $user->password_confirmation = 'read-pass-12345';
        $user->is_superuser = true;
        $user->is_activated = true;
        $user->save();

        $result = (new TokenManager)->issue($user);
        $this->authHeader = ['Authorization' => 'Bearer ' . $result['token']];
    }

    protected function makePost(string $title): EntryRecord
    {
        $post = EntryRecord::inSection('Blog\Post');
        $post->title = $title;
        $post->slug = \Str::slug($title) . '-' . random_int(10000, 99999);
        $post->save();
        return $post;
    }

    protected function postUuid(): string
    {
        return BlueprintIndexer::instance()->findSectionByHandle('Blog\Post')->uuid;
    }

    // -- /schema ---------------------------------------------------------------

    public function testSchemaRequiresAuth()
    {
        $this->getJson('/api/tailor-companion/v1/schema')->assertStatus(401);
    }

    public function testSchemaReturnsBlueprintsAndEtag()
    {
        $response = $this->getJson('/api/tailor-companion/v1/schema', $this->authHeader);

        $response->assertStatus(200);
        $handles = collect($response->json('data'))->pluck('handle');
        $this->assertTrue($handles->contains('Blog\Post'));
        $this->assertTrue($handles->contains('Demo\KitchenSink'));

        $version = $response->json('meta.schema_version');
        $this->assertSame('"' . $version . '"', $response->headers->get('ETag'));
    }

    public function testSchemaSupportsIfNoneMatch()
    {
        $first = $this->getJson('/api/tailor-companion/v1/schema', $this->authHeader);
        $etag = $first->headers->get('ETag');

        $second = $this->getJson('/api/tailor-companion/v1/schema', $this->authHeader + [
            'If-None-Match' => $etag,
        ]);

        $second->assertStatus(304);
        $this->assertSame('', $second->getContent());
    }

    // -- /entries/{uuid} -----------------------------------------------------------

    public function testEntriesUnknownBlueprintIs404()
    {
        $this->getJson('/api/tailor-companion/v1/entries/00000000-0000-0000-0000-000000000000', $this->authHeader)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'unknown_blueprint');
    }

    public function testEntriesCursorPagination()
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->makePost("Paged Post {$i}");
        }

        $uuid = $this->postUuid();
        $collected = [];
        $cursor = 0;
        $pages = 0;

        do {
            $response = $this->getJson(
                "/api/tailor-companion/v1/entries/{$uuid}?per_page=2&cursor={$cursor}",
                $this->authHeader
            );
            $response->assertStatus(200);
            // total is present only on the first page (progress hint), null after
            if ($cursor === 0) {
                $this->assertSame(5, $response->json('meta.total'));
            } else {
                $this->assertNull($response->json('meta.total'));
            }

            foreach ($response->json('data') as $entry) {
                $collected[] = $entry['id'];
            }

            $cursor = $response->json('meta.next_cursor');
            $pages++;
        } while ($response->json('meta.has_more') && $pages < 10);

        $this->assertSame(3, $pages);
        $this->assertCount(5, $collected);
        $this->assertSame($collected, collect($collected)->sort()->values()->all(), 'Ordered by id');
    }

    public function testEntriesShowAndNotFound()
    {
        $post = $this->makePost('Single Post');
        $uuid = $this->postUuid();

        $this->getJson("/api/tailor-companion/v1/entries/{$uuid}/{$post->id}", $this->authHeader)
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Single Post');

        $this->getJson("/api/tailor-companion/v1/entries/{$uuid}/999999", $this->authHeader)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'unknown_entry');
    }

    // -- /sync/changes ---------------------------------------------------------------

    public function testChangesWithoutCursorSignalsFullPull()
    {
        $this->makePost('Journaled Post');

        $response = $this->getJson('/api/tailor-companion/v1/sync/changes', $this->authHeader);

        $response->assertStatus(200);
        $response->assertJsonPath('meta.full_pull_required', true);
        $this->assertGreaterThan(0, $response->json('meta.latest_cursor'));
        $this->assertSame([], $response->json('data'));
    }

    public function testChangesIncrementalFlow()
    {
        // Baseline cursor
        $baseline = $this->getJson('/api/tailor-companion/v1/sync/changes', $this->authHeader)
            ->json('meta.latest_cursor');

        $post = $this->makePost('Changing Post');
        $post->title = 'Changed Title';
        $post->save();

        $response = $this->getJson("/api/tailor-companion/v1/sync/changes?since={$baseline}", $this->authHeader);

        $response->assertStatus(200);
        $response->assertJsonPath('meta.full_pull_required', false);

        $changes = $response->json('data');
        $this->assertCount(1, $changes, 'create+update coalesce to one change');
        $this->assertSame('created', $changes[0]['action']);
        $this->assertSame((int) $post->id, $changes[0]['record_id']);

        // Cursor advances past everything
        $next = $response->json('meta.latest_cursor');
        $empty = $this->getJson("/api/tailor-companion/v1/sync/changes?since={$next}", $this->authHeader);
        $this->assertSame([], $empty->json('data'));
    }

    public function testChangesWithPrunedCursorIs410()
    {
        // Old row that pruning will remove
        $old = new JournalEntry;
        $old->blueprint_uuid = 'bp-old';
        $old->record_id = 1;
        $old->action = 'updated';
        $old->created_at = Date::now()->subDays(90);
        $old->save();
        $oldId = $old->id;

        (new ChangeJournal)->prune(30);

        $response = $this->getJson('/api/tailor-companion/v1/sync/changes?since=' . ($oldId - 1), $this->authHeader);

        $response->assertStatus(410);
        $response->assertJsonPath('error.code', 'cursor_expired');
    }
}
