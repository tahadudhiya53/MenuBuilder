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
    public const PLUGIN_HANDLE = 'menu-builder';

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
            ? Craft::t('menu-builder', 'Pro')
            : Craft::t('menu-builder', 'Free');
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
            return UrlHelper::cpUrl(sprintf(
                'plugin-store/buy/%s/%s',
                self::PLUGIN_HANDLE,
                MenuBuilder::EDITION_PRO,
            ));
        }

        return sprintf('https://plugins.craftcms.com/%s', self::PLUGIN_HANDLE);
    }
}
