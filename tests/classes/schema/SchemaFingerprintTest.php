<?php namespace Renick\TailorCompanion\Tests\Classes\Schema;

use PluginTestCase;
use Renick\TailorCompanion\Classes\Schema\SchemaFingerprint;
use Tailor\Classes\BlueprintIndexer;

class SchemaFingerprintTest extends PluginTestCase
{
    protected SchemaFingerprint $fingerprint;

    public function setUp(): void
    {
        parent::setUp();
        $this->fingerprint = new SchemaFingerprint;
    }

    public function testForAllCoversThemeBlueprints()
    {
        // The companion theme ships the blog blueprints (test fixture)
        $all = $this->fingerprint->forAll();

        $post = BlueprintIndexer::instance()->findSectionByHandle('Blog\Post');
        $this->assertNotNull($post, 'Blog\Post blueprint must be available in the test theme');
        $this->assertArrayHasKey($post->uuid, $all);

        foreach ($all as $uuid => $hash) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
        }
    }

    public function testFingerprintIsStableAcrossCalls()
    {
        $this->assertSame(
            $this->fingerprint->globalVersion(),
            $this->fingerprint->globalVersion()
        );

        $this->assertSame(
            $this->fingerprint->forAll(),
            $this->fingerprint->forAll()
        );
    }

    public function testDifferentBlueprintsHaveDifferentFingerprints()
    {
        $indexer = BlueprintIndexer::instance();
        $post = $indexer->findSectionByHandle('Blog\Post');
        $tag = $indexer->findSectionByHandle('Blog\Tag');

        $this->assertNotNull($post);
        $this->assertNotNull($tag);

        $this->assertNotSame(
            $this->fingerprint->forBlueprint($post),
            $this->fingerprint->forBlueprint($tag)
        );
    }

    public function testFingerprintIgnoresKeyOrder()
    {
        $indexer = BlueprintIndexer::instance();
        $post = $indexer->findSectionByHandle('Blog\Post');

        $original = $this->fingerprint->forBlueprint($post);

        // Reverse the top-level attribute order — content is identical
        $shuffled = clone $post;
        $shuffled->attributes = array_reverse($post->attributes, true);

        $this->assertSame($original, $this->fingerprint->forBlueprint($shuffled));
    }
}
