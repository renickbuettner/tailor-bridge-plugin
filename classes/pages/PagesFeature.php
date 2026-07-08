<?php namespace Renick\TailorCompanion\Classes\Pages;

use Renick\TailorCompanion\Models\Setting;
use System\Classes\PluginManager;

/**
 * PagesFeature is the single switchboard for the optional RainLab.Pages
 * integration. The plugin must work with or without RainLab.Pages installed:
 * every static-pages code path asks this class first, and the answer also
 * surfaces as the `features.static_pages` capability flag on /ping so clients
 * can hide the feature entirely.
 *
 * Detection is runtime-only (no composer dependency): the RainLab plugin has
 * to be installed, registered and not disabled, and the integration can be
 * turned off via the `pages_enabled` setting.
 */
class PagesFeature
{
    /**
     * @var bool|null forced test override for availability
     */
    protected static $forcedAvailability = null;

    /**
     * @var PagesGateway|null gateway instance (or test fake)
     */
    protected static $gateway = null;

    /**
     * isAvailable returns true when static pages can be served.
     */
    public static function isAvailable(): bool
    {
        if (static::$forcedAvailability !== null) {
            return static::$forcedAvailability;
        }

        return static::isInstalled() && (bool) Setting::get('pages_enabled', true);
    }

    /**
     * isInstalled checks for a usable RainLab.Pages installation.
     */
    public static function isInstalled(): bool
    {
        $manager = PluginManager::instance();

        return $manager->hasPlugin('RainLab.Pages')
            && !$manager->isDisabled('RainLab.Pages')
            && class_exists(\RainLab\Pages\Classes\Page::class);
    }

    /**
     * forceAvailability overrides detection in tests (null restores detection).
     */
    public static function forceAvailability(?bool $value): void
    {
        static::$forcedAvailability = $value;
    }

    /**
     * gateway returns the RainLab access layer. Only call when isAvailable().
     */
    public static function gateway(): PagesGateway
    {
        return static::$gateway ??= new RainLabPagesGateway;
    }

    /**
     * setGateway swaps the gateway in tests (null restores the real one).
     */
    public static function setGateway(?PagesGateway $gateway): void
    {
        static::$gateway = $gateway;
    }
}
