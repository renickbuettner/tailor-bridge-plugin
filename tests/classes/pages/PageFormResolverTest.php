<?php namespace Renick\TailorCompanion\Tests\Classes\Pages;

use PluginTestCase;
use Renick\TailorCompanion\Classes\Pages\PageFormResolver;

class PageFormResolverTest extends PluginTestCase
{
    protected PageFormResolver $resolver;
    protected array $tempFiles = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->resolver = new PageFormResolver;
    }

    public function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    protected function tempYaml(string $yaml): string
    {
        $path = sys_get_temp_dir() . '/pfr-' . uniqid() . '.yaml';
        file_put_contents($path, $yaml);
        $this->tempFiles[] = $path;
        return $path;
    }

    public function testResolvesInlineForm()
    {
        $fields = $this->resolver->resolveForm([
            'fields' => [
                'heading' => ['type' => 'text', 'label' => 'Heading'],
                'body' => ['type' => 'textarea'],
            ],
        ]);

        $this->assertArrayHasKey('heading', $fields);
        $this->assertSame('text', $fields['heading']['type']);
    }

    public function testResolvesExternalFormFromYamlFile()
    {
        $path = $this->tempYaml("fields:\n  heading:\n    type: text\n    label: Heading\n  count:\n    type: number\n");

        $fields = $this->resolver->resolveForm($path);

        $this->assertSame(['heading', 'count'], array_keys($fields));
        $this->assertSame('number', $fields['count']['type']);
    }

    public function testResolvesGroupsWithPerGroupFileReferences()
    {
        $heroPath = $this->tempYaml("name: Hero\nfields:\n  title:\n    type: text\n  cta:\n    type: text\n");

        $groups = $this->resolver->resolveGroups([
            'hero' => $heroPath,                                   // per-group file reference
            'text' => ['name' => 'Text', 'fields' => ['body' => ['type' => 'textarea']]], // inline
            '_ignored' => ['name' => 'skip'],                     // underscore keys skipped
        ]);

        $this->assertSame(['hero', 'text'], array_keys($groups));
        $this->assertSame('Hero', $groups['hero']['name']);
        $this->assertArrayHasKey('title', $groups['hero']['fields']);
        $this->assertSame('Text', $groups['text']['name']);
        $this->assertArrayHasKey('body', $groups['text']['fields']);
    }

    public function testResolvesThemeRelativeReferenceFromMetaFolder()
    {
        // A theme whose repeater points at a bare path like "blocks.yaml"
        // (no $/ or ~/), resolved against the theme's meta/ folder.
        $themeDir = sys_get_temp_dir() . '/pfr-theme-' . uniqid();
        mkdir($themeDir . '/meta', 0777, true);
        $file = $themeDir . '/meta/blocks.yaml';
        file_put_contents($file, "hero:\n  name: Hero\n  fields:\n    title:\n      type: text\n");
        $this->tempFiles[] = $file;

        $resolver = new PageFormResolver($themeDir);
        $groups = $resolver->resolveGroups('blocks.yaml');

        $this->assertArrayHasKey('hero', $groups);
        $this->assertArrayHasKey('title', $groups['hero']['fields']);

        @unlink($file);
        @rmdir($themeDir . '/meta');
        @rmdir($themeDir);
    }

    public function testUnresolvableReferenceYieldsEmpty()
    {
        $this->assertSame([], $this->resolver->resolveForm('$/does/not/exist.yaml'));
        $this->assertSame([], $this->resolver->resolveGroups('~/nope.yaml'));
        $this->assertSame([], $this->resolver->resolveForm(null));
        $this->assertSame([], $this->resolver->resolveForm(42));
    }
}
