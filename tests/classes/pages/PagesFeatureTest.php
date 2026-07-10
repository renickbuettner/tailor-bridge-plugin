<?php namespace Renick\TailorCompanion\Tests\Classes\Pages;

use PluginTestCase;
use Renick\TailorCompanion\Classes\Pages\PagesFeature;
use Renick\TailorCompanion\Classes\Pages\PagesGateway;
use Renick\TailorCompanion\Models\Setting;

class PagesFeatureTest extends PluginTestCase
{
    public function tearDown(): void
    {
        PagesFeature::forceAvailability(null);
        PagesFeature::setGateway(null);
        parent::tearDown();
    }

    public function testForcedAvailabilityWinsOverDetection()
    {
        PagesFeature::forceAvailability(false);
        $this->assertFalse(PagesFeature::isAvailable());

        PagesFeature::forceAvailability(true);
        $this->assertTrue(PagesFeature::isAvailable());
    }

    public function testForcingNullRestoresDetection()
    {
        PagesFeature::forceAvailability(true);
        PagesFeature::forceAvailability(null);

        // Detection result depends on the host installation; it must simply
        // agree with the components it is defined by.
        $expected = PagesFeature::isInstalled() && (bool) Setting::get('pages_enabled', true);
        $this->assertSame($expected, PagesFeature::isAvailable());
    }

    public function testSettingSwitchDisablesFeatureRegardlessOfInstallState()
    {
        Setting::set('pages_enabled', false);

        // Off when RainLab.Pages is absent (not installed) AND when present
        // (setting gate) — the assertion holds in both test environments.
        $this->assertFalse(PagesFeature::isAvailable());

        Setting::set('pages_enabled', true);
    }

    public function testIsInstalledIsFalseWithoutRainLabClasses()
    {
        if (class_exists(\RainLab\Pages\Classes\Page::class)) {
            $this->markTestSkipped('RainLab.Pages is installed in this environment.');
        }

        $this->assertFalse(PagesFeature::isInstalled());
        $this->assertFalse(PagesFeature::isAvailable());
    }

    public function testGatewayCanBeSwappedForTests()
    {
        $fake = new class implements PagesGateway {
            public function layouts(): array { return []; }
            public function tree(): array { return []; }
            public function page(string $fileName): ?array { return null; }
            public function updatePage(string $fileName, array $viewBag, ?string $markup, ?string $code): array { return []; }
            public function menus(): array { return []; }
            public function menu(string $code): ?array { return null; }
            public function themePath(): ?string { return null; }
        };

        PagesFeature::setGateway($fake);
        $this->assertSame($fake, PagesFeature::gateway());

        PagesFeature::setGateway(null);
    }
}
