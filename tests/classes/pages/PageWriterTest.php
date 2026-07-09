<?php namespace Renick\TailorCompanion\Tests\Classes\Pages;

use PluginTestCase;
use Renick\TailorCompanion\Classes\Pages\LayoutSchemaSerializer;
use Renick\TailorCompanion\Classes\Pages\PagesFeature;
use Renick\TailorCompanion\Classes\Pages\PageWriter;
use Renick\TailorCompanion\Models\AuditLog;
use Renick\TailorCompanion\Tests\Fakes\PagesTestGateway;

require_once __DIR__ . '/../../fakes/PagesTestGateway.php';

/**
 * Writer logic against an in-memory gateway — runs with or without RainLab.
 */
class PageWriterTest extends PluginTestCase
{
    protected PagesTestGateway $gateway;
    protected array $layout;
    protected PageWriter $writer;

    protected string $markup = <<<'TWIG'
    {variable name="hero" label="Hero" type="text"}{/variable}
    {variable name="show_toc" label="Show ToC" type="switch"}{/variable}
    {variable name="attachment" label="File" type="fileupload"}{/variable}
    <article>{% page %}</article>
    {% placeholder sidebar type="html" %}
    TWIG;

    public function setUp(): void
    {
        parent::setUp();

        $this->gateway = new PagesTestGateway;
        $this->gateway->pages = ['about' => $this->rawPage()];
        PagesFeature::setGateway($this->gateway);

        $this->layout = (new LayoutSchemaSerializer)
            ->serializeLayout('static-default', 'Static default', true, $this->markup);
        $this->writer = new PageWriter;
    }

    public function tearDown(): void
    {
        PagesFeature::setGateway(null);
        parent::tearDown();
    }

    protected function rawPage(): array
    {
        return [
            'fileName' => 'about',
            'viewBag' => [
                'title' => 'About', 'url' => '/about', 'layout' => 'static-default',
                'is_hidden' => '0', 'navigation_hidden' => '0',
                'hero' => 'Old hero', 'legacy' => 'keep',
            ],
            'markup' => '<p>Old body</p>',
            'code' => "{% put sidebar %}\n<p>Old side</p>\n{% endput %}",
            'mtime' => 1710000000,
            'contentHash' => 'base-hash',
        ];
    }

    public function testConflictWhenBaseHashMismatches()
    {
        $result = $this->writer->apply($this->rawPage(), $this->layout, ['title' => 'New'], null, 'stale-hash');

        $this->assertSame('conflict', $result['status']);
        $this->assertSame('About', $result['page']['title']); // unchanged server page
        $this->assertNull($this->gateway->lastUpdate); // never saved
    }

    public function testAppliesImplicitSyntaxPlaceholderAndMarkup()
    {
        $result = $this->writer->apply($this->rawPage(), $this->layout, [
            'title' => 'New title',
            'is_hidden' => true,
            'hero' => 'New hero',
            'placeholder:sidebar' => '<p>New side</p>',
            'markup' => '<p>New body</p>',
        ], null, 'base-hash');

        $this->assertSame('ok', $result['status']);
        $this->assertSame([], $result['warnings']);

        $saved = $this->gateway->lastUpdate;
        $this->assertSame('New title', $saved['viewBag']['title']);
        $this->assertSame('1', $saved['viewBag']['is_hidden']); // bool → INI string
        $this->assertSame('New hero', $saved['viewBag']['hero']);
        $this->assertStringContainsString('New side', $saved['code']);
        $this->assertSame('<p>New body</p>', $saved['markup']);

        // Fresh page reflected back
        $this->assertSame('New title', $result['page']['title']);
        $this->assertSame('<p>New side</p>', $result['page']['fields']['placeholder:sidebar']);
    }

    public function testSwitchBoolIsStoredAsIniString()
    {
        // The app sends switch fields as JSON booleans; the view bag serializes
        // to INI, so they must be stored as "1"/"0".
        $this->writer->apply($this->rawPage(), $this->layout, ['show_toc' => true], null, 'base-hash');
        $this->assertSame('1', $this->gateway->lastUpdate['viewBag']['show_toc']);

        $this->writer->apply($this->rawPage(), $this->layout, ['show_toc' => false], null, 'base-hash');
        $this->assertSame('0', $this->gateway->lastUpdate['viewBag']['show_toc']);
    }

    public function testReadonlyAndUnknownFieldsBecomeWarnings()
    {
        $result = $this->writer->apply($this->rawPage(), $this->layout, [
            'url' => '/hijack',
            'layout' => 'other',
            'attachment' => [99],
            'ghost' => 'x',
            'placeholder:nope' => 'y',
        ], null, 'base-hash');

        $codes = [];
        foreach ($result['warnings'] as $w) {
            $codes[$w['field']] = $w['code'];
        }
        $this->assertSame('readonly_field', $codes['url']);
        $this->assertSame('readonly_field', $codes['layout']);
        $this->assertSame('readonly_field', $codes['attachment']);
        $this->assertSame('unknown_field', $codes['ghost']);
        $this->assertSame('unknown_field', $codes['placeholder:nope']);

        // None of the rejected values were written
        $this->assertSame('/about', $this->gateway->lastUpdate['viewBag']['url']);
    }

    public function testUnknownViewBagKeysRoundTripByDefault()
    {
        $this->writer->apply($this->rawPage(), $this->layout, ['hero' => 'x'], null, 'base-hash');

        $this->assertSame('keep', $this->gateway->lastUpdate['viewBag']['legacy']);
    }

    public function testViewbagExtraReplacesLosslessKeys()
    {
        $this->writer->apply($this->rawPage(), $this->layout, ['hero' => 'x'], ['legacy' => 'changed', 'added' => 'new'], 'base-hash');

        $vb = $this->gateway->lastUpdate['viewBag'];
        $this->assertSame('changed', $vb['legacy']);
        $this->assertSame('new', $vb['added']);
        // claimed keys survive the extra replacement
        $this->assertSame('About', $vb['title']);
        $this->assertSame('x', $vb['hero']);
    }

    public function testMarkupRejectedWhenLayoutHasNoContent()
    {
        $noContent = (new LayoutSchemaSerializer)
            ->serializeLayout('static-landing', 'Landing', false, '{variable name="x" label="X" type="text"}{/variable}');

        $result = $this->writer->apply($this->rawPage(), $noContent, ['markup' => 'nope'], null, 'base-hash');

        $this->assertSame('unknown_field', $result['warnings'][0]['code']);
        $this->assertNull($this->gateway->lastUpdate['markup']);
    }

    public function testWritesAuditLogWithFileName()
    {
        $before = AuditLog::count();

        $this->writer->apply($this->rawPage(), $this->layout, ['title' => 'Audited'], null, 'base-hash');

        $this->assertSame($before + 1, AuditLog::count());
        $log = AuditLog::latest('id')->first();
        $this->assertSame('update', $log->action);
        $this->assertSame('static-page', $log->blueprint_uuid);
        $this->assertSame('about', $log->getAttribute('diff')['_page']);
    }

    public function testNoDiffNoAudit()
    {
        $before = AuditLog::count();

        // Writing the same value produces no diff → no audit entry
        $this->writer->apply($this->rawPage(), $this->layout, ['hero' => 'Old hero'], null, 'base-hash');

        $this->assertSame($before, AuditLog::count());
    }

    public function testMissingBaseHashForceApplies()
    {
        $result = $this->writer->apply($this->rawPage(), $this->layout, ['title' => 'Forced'], null, null);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('Forced', $this->gateway->lastUpdate['viewBag']['title']);
    }
}
