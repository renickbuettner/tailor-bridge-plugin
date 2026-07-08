<?php namespace Renick\TailorCompanion\Tests\Classes\Pages;

use PluginTestCase;
use Renick\TailorCompanion\Classes\Pages\LayoutSchemaSerializer;
use Renick\TailorCompanion\Classes\Pages\PagesSchemaFingerprint;

class PagesSchemaFingerprintTest extends PluginTestCase
{
    protected LayoutSchemaSerializer $serializer;
    protected PagesSchemaFingerprint $fingerprint;

    public function setUp(): void
    {
        parent::setUp();
        $this->serializer = new LayoutSchemaSerializer;
        $this->fingerprint = new PagesSchemaFingerprint;
    }

    protected function layout(string $markup, bool $useContent = true): array
    {
        return $this->serializer->serializeLayout('static-default', 'Static default', $useContent, $markup);
    }

    public function testFingerprintIsStableAndHex()
    {
        $layout = $this->layout('{variable name="x" label="X" type="text"}{/variable}');

        $a = $this->fingerprint->forLayout($layout);
        $b = $this->fingerprint->forLayout($layout);

        $this->assertSame($a, $b);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a);
    }

    public function testFingerprintChangesWhenAFieldLabelChanges()
    {
        $before = $this->layout('{variable name="x" label="Before" type="text"}{/variable}');
        $after = $this->layout('{variable name="x" label="After" type="text"}{/variable}');

        $this->assertNotSame(
            $this->fingerprint->forLayout($before),
            $this->fingerprint->forLayout($after)
        );
    }

    public function testFingerprintIgnoresSurroundingMarkup()
    {
        // Same declarations, different HTML around them → same form → same hash
        $a = $this->layout('<div class="a">{variable name="x" label="X" type="text"}{/variable}</div>');
        $b = $this->layout('<section>{variable name="x" label="X" type="text"}{/variable}</section>');

        $this->assertSame(
            $this->fingerprint->forLayout($a),
            $this->fingerprint->forLayout($b)
        );
    }

    public function testGlobalVersionChangesWhenLayoutAddedOrRemoved()
    {
        $one = $this->layout('{variable name="x" label="X" type="text"}{/variable}');
        $one['fingerprint'] = $this->fingerprint->forLayout($one);

        $two = $this->serializer->serializeLayout('static-landing', 'Landing', false, '{variable name="y" label="Y" type="text"}{/variable}');
        $two['fingerprint'] = $this->fingerprint->forLayout($two);

        $single = $this->fingerprint->globalVersion([$one]);
        $pair = $this->fingerprint->globalVersion([$one, $two]);

        $this->assertNotSame($single, $pair);
    }

    public function testGlobalVersionIsOrderIndependent()
    {
        $one = $this->layout('{variable name="x" label="X" type="text"}{/variable}');
        $one['file_name'] = 'a';
        $two = $this->layout('{variable name="y" label="Y" type="text"}{/variable}');
        $two['file_name'] = 'b';

        $this->assertSame(
            $this->fingerprint->globalVersion([$one, $two]),
            $this->fingerprint->globalVersion([$two, $one])
        );
    }
}
