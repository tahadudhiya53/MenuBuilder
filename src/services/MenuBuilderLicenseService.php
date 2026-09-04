<?php

namespace Tahadudhiya\MenuBuilder\services;

use Craft;
use craft\base\Component;
use craft\enums\LicenseKeyStatus;
use craft\helpers\UrlHelper;
use Tahadudhiya\MenuBuilder\MenuBuilder;

/**
 * The one place that answers "which edition is this install running?".
 *
 * MenuBuilder is a commercial plugin with two editions — Free and Pro (see
 * {@see MenuBuilder::editions()}) — and Craft already owns everything about
 * how that is decided: the active edition lives in project config
 * (`plugins.menu-builder.edition`), Craft sets it at install and at
 * `Plugins::switchEdition()`, and Craft's own license machinery is what
 * validates the key behind it and nags in the CP when it doesn't hold up.
 * This service adds **no** second mechanism; it reads what Craft already
 * knows and hands it to the rest of the plugin in one shape.
 *
 * Nothing here decides *what* an edition may do. That is
 * {@see MenuBuilderMenuLimitService}, which is the only consumer of
 * {@see isPro()} that matters, so a feature gate can't drift into a template
 * or a controller as its own private license check.
 *
 * ## Why the gate is the edition, not the license key status
 *
 * `isPro()` deliberately consults **only** the active edition, never
 * {@see getLicenseKeyStatus()}. Craft's model is that an unpaid, expired or
 * mismatched key produces a loud CP warning and a Plugin Store prompt — it
 * does not silently downgrade a running site — and a plugin that took the
 * downgrade into its own hands would turn a lapsed invoice, or a failed
 * license *check* (status `unknown` is what a site with no outbound network
 * reports), into an editor suddenly unable to manage their menus. The key
 * status is surfaced in the CP as information (see
 * {@see \Tahadudhiya\MenuBuilder\controllers\GroupsController::actionIndex()})
 * and is not a gate.
 *
 * Either way no menu data is ever touched by an edition change — see
 * ARCHITECTURE.md, "Editions and the menu limit".
 */
class MenuBuilderLicenseService extends Component
{
    /**
     * The plugin's Craft Plugin Store handle. Every URL below is derived
     * from it rather than written out, so there is exactly one place to
     * correct if the store handle ever differs from the plugin handle.
     */
    public const PLUGIN_HANDLE = 'menu-builder';

    /**
     * The active edition — `free` or `pro`.
     *
     * Falls back to Free when there is no plugin instance to ask (an
     * uninstalled plugin, or a unit test with no booted app). Fail-closed is
     * the right default for a *limit*: the worst case is an install that is
     * told to upgrade, not one that quietly hands out the paid edition.
     */
    public function getEdition(): string
    {
        return MenuBuilder::getInstance()?->edition ?? MenuBuilder::EDITION_FREE;
    }

    /**
     * Whether the active edition includes Pro's allowances.
     *
     * Asked through Craft's own `Plugin::is()` with `>=`, which is the
     * documented way to compare editions and the reason
     * {@see MenuBuilder::editions()} is ordered — an edition added above Pro
     * later would inherit Pro's allowances without this method changing.
     *
     * The guard in front of it is not defensiveness for its own sake:
     * `is()` throws `InvalidArgumentException` on an edition it doesn't
     * recognize, and the value it compares comes from project config — a
     * YAML file a human, a merge conflict or a partial deploy can leave
     * holding anything. An unrecognized edition is Free, never a fatal on
     * every request.
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
     * Whether `$edition` is one this plugin actually declares. Pure, so the
     * guard {@see isPro()} depends on is testable without a booted app.
     */
    public static function isKnownEdition(?string $edition): bool
    {
        return $edition !== null && in_array($edition, MenuBuilder::editions(), true);
    }

    /**
     * The pure edition→Pro mapping, for the callers that hold an edition
     * string rather than a booted plugin (the display name below, and the
     * unit suite). {@see isPro()} is what the plugin actually gates on.
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
     * Craft's own view of the license key behind the active edition —
     * `valid`, `trial`, `invalid`, `mismatched`, `astray` or `unknown`.
     *
     * Informational only (see this class's docblock). Null when there is no
     * booted app to ask; the raw key itself is never read or exposed here.
     */
    public function getLicenseKeyStatus(): ?string
    {
        if (Craft::$app === null) {
            return null;
        }

        return Craft::$app->getPlugins()->getPluginLicenseKeyStatus(self::PLUGIN_HANDLE)->value;
    }

    /**
     * Whether Craft currently considers the license key behind the active
     * edition to be in good standing. Shown next to the edition on Pro
     * installs; never used as a gate.
     */
    public function isLicenseActive(): bool
    {
        return in_array($this->getLicenseKeyStatus(), [
            LicenseKeyStatus::Valid->value,
            LicenseKeyStatus::Trial->value,
        ], true);
    }

    /**
     * Where "Upgrade to Pro" should send this user, or null if there is
     * nowhere useful to send them.
     *
     * Both destinations are Craft's own, derived from the plugin handle
     * exactly as `craft\helpers\App::licenseInfo()` derives them — the
     * in-CP Plugin Store checkout for an admin who is allowed to change
     * things, and the public plugin listing for everyone else. Nothing about
     * pricing, purchasing or license issuance belongs to this plugin.
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
