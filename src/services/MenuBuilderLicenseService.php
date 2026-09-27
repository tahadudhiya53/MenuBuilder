<?php

namespace Tahadudhiya\MenuBuilder\services;

use Craft;
use craft\base\Component;
use craft\enums\LicenseKeyStatus;
use craft\helpers\UrlHelper;
use Tahadudhiya\MenuBuilder\MenuBuilder;

/**
 * The one place that answers "which edition is this install running?".
*/
class MenuBuilderLicenseService extends Component
{
    /**
     * The plugin's Craft Plugin Store handle.
    */
    public const PLUGIN_HANDLE = 'menubuilder';

    /**
     * The active edition — `free` or `pro`.
    */
    public function getEdition(): string
    {
        return MenuBuilder::getInstance()?->edition ?? MenuBuilder::EDITION_FREE;
    }

    /**
     * Whether the active edition includes Pro's allowances.
    */
    public function isPro(): bool
    {
        $plugin = MenuBuilder::getInstance();

        if ($plugin === null || !self::isKnownEdition($plugin->edition)) {
            return false;
        }

        return $plugin->is(MenuBuilder::EDITION_PRO, '>=');
    }

    /**
     * Whether `$edition` is one this plugin actually declares.
    */
    public static function isKnownEdition(?string $edition): bool
    {
        return $edition !== null && in_array($edition, MenuBuilder::editions(), true);
    }

    /**
     * The pure edition→Pro mapping, for the callers that hold an edition string rather than a
     * booted plugin (the display name below, and the unit suite).
    */
    public static function editionIsPro(?string $edition): bool
    {
        return $edition === MenuBuilder::EDITION_PRO;
    }

    /**
     * The edition's display name, for the CP's edition badge.
    */
    public function getEditionName(): string
    {
        return self::editionName($this->getEdition());
    }

    public static function editionName(?string $edition): string
    {
        return self::editionIsPro($edition)
            ? Craft::t('menubuilder', 'Pro')
            : Craft::t('menubuilder', 'Free');
    }

    /**
     * Craft's own view of the license key behind the active edition — `valid`, `trial`,
     * `invalid`, `mismatched`, `astray` or `unknown`.
    */
    public function getLicenseKeyStatus(): ?string
    {
        if (Craft::$app === null) {
            return null;
        }

        return Craft::$app->getPlugins()->getPluginLicenseKeyStatus(self::PLUGIN_HANDLE)->value;
    }

    /**
     * Whether Craft currently considers the license key behind the active edition to be in good
     * standing.
    */
    public function isLicenseActive(): bool
    {
        return in_array($this->getLicenseKeyStatus(), [
            LicenseKeyStatus::Valid->value,
            LicenseKeyStatus::Trial->value,
        ], true);
    }

    /**
     * The Plugin Store path "Upgrade to Pro" sends an admin to.
     *
     * Deliberately *not* `plugin-store/buy/<handle>/pro`. That route exists, but the Plugin
     * Store's Vue app treats it as "add this plugin to the cart", and its buyability check reads
     * the price of the plugin's *first* edition — which for MenuBuilder is Free, at no cost. A
     * free first edition therefore fails the check and the app redirects to the Plugin Store
     * index, which is exactly the bounce editors were seeing. Craft only ever uses that route for
     * a plugin whose paid edition is already installed on trial.
     *
     * The editions screen is the Craft-supported entry point for a free-first plugin: it renders
     * one card per declared edition with the Try/Buy actions the store decides are available for
     * this install.
    */
    public static function upgradePath(): string
    {
        return sprintf('plugin-store/%s/editions', self::PLUGIN_HANDLE);
    }

    /**
     * Where a non-admin (or an install with admin changes turned off) is sent instead — the public
     * listing, which needs no CP access to read.
    */
    public static function marketplaceUrl(): string
    {
        return sprintf('https://plugins.craftcms.com/%s', self::PLUGIN_HANDLE);
    }

    /**
     * Where "Upgrade to Pro" should send this user, or null if there is nowhere useful to send
     * them.
    */
    public function getUpgradeUrl(): ?string
    {
        if (Craft::$app === null) {
            return null;
        }

        $user = Craft::$app->getUser()->getIdentity();

        if ($user !== null && $user->admin && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            return UrlHelper::cpUrl(self::upgradePath());
        }

        return self::marketplaceUrl();
    }
}
