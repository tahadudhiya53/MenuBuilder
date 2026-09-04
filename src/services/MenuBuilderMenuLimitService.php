<?php

namespace Tahadudhiya\MenuBuilder\services;

use Craft;
use craft\base\Component;
use Tahadudhiya\MenuBuilder\MenuBuilder;

/**
 * How many menus this install may have, and whether it may have one more.
 *
 * The **only** thing the Free edition limits. Everything MenuBuilder can do
 * — unlimited items, unlimited nesting, mega menus, dynamic navigation,
 * visibility rules, scheduling, the Twig API, GraphQL, the REST API, the
 * Navigation field, preview, link health, permissions — is available in
 * Free, inside the one menu it allows. Nothing else in the plugin asks this
 * service anything, and nothing else asks
 * {@see MenuBuilderLicenseService::isPro()} either: one edition question,
 * one answer, one enforcement point.
 *
 * ## Where it is enforced
 *
 * At the service layer, in
 * {@see MenuBuilderGroupService::save()} (new menus only) and
 * {@see MenuBuilderGroupService::duplicate()} — the two write paths through
 * which a menu can come into existence, and the only ones controllers,
 * console commands or third-party code have (ARCHITECTURE.md, "Group
 * persistence — database only": reaching past the service skips validation,
 * transactions and cache invalidation, so nothing does). The CP checks the
 * same method a moment earlier purely so the interface can *say* why, rather
 * than offering a button whose request would be refused; the check that
 * actually holds is the one in the service.
 *
 * ## What it deliberately does not do
 *
 * Nothing here reads, writes, hides or deletes menu data. An install that
 * drops from Pro back to Free keeps every menu it has, keeps rendering all
 * of them on the front end, and can still edit them — it simply can't add
 * another until it is back under the limit. `canCreateMenu()` is a question
 * about *creating*, and it is asked nowhere near the rendering pipeline.
 *
 * ## Multi-site
 *
 * The limit counts menus per **install**, not per site: a menu
 * (`menubuilder_groups`) is a single global row that may optionally be
 * restricted to a set of sites (`MenuBuilderGroup::$siteIds`), not a
 * per-site entity, so "one menu per site" would not describe anything the
 * data model has. See ARCHITECTURE.md, "Editions and the menu limit".
 */
class MenuBuilderMenuLimitService extends Component
{
    /**
     * The Free edition's ceiling. A product rule, not a setting: it is
     * deliberately not exposed in config or the CP, and this constant is the
     * only place the number appears.
     */
    public const FREE_MAX_MENUS = 1;

    public function isPro(): bool
    {
        return MenuBuilder::getInstance()->license->isPro();
    }

    /**
     * The number of menus this install may have, or null for unlimited.
     */
    public function getMaxMenus(): ?int
    {
        return self::maxMenusFor($this->isPro());
    }

    /**
     * Pure form of {@see getMaxMenus()}.
     */
    public static function maxMenusFor(bool $isPro): ?int
    {
        return $isPro ? null : self::FREE_MAX_MENUS;
    }

    /**
     * How many menus exist right now — every menu, enabled or not, on every
     * site. Read through {@see MenuBuilderGroupService::getAll()} rather than
     * with a `COUNT(*)` of its own so it is served by the same per-request
     * memo the rest of a save already populated, and so it can never disagree
     * with the list the CP is showing.
     */
    public function getMenuCount(): int
    {
        return count(MenuBuilder::getInstance()->groups->getAll());
    }

    public function canCreateMenu(): bool
    {
        return self::canCreate($this->getMaxMenus(), $this->getMenuCount());
    }

    /**
     * Pure form of {@see canCreateMenu()}, so the arithmetic is testable
     * without a database.
     *
     * `>=` rather than `>`, so an install that is already *over* the limit —
     * a Pro site whose edition has gone back to Free with five menus in it —
     * is simply not allowed another. It is never asked to give any up.
     */
    public static function canCreate(?int $maxMenus, int $menuCount): bool
    {
        return $maxMenus === null || $menuCount < $maxMenus;
    }

    /**
     * What the CP shows, and what the refused save reports. One wording, so
     * the button, the flash and the model error can't tell three different
     * stories.
     */
    public static function limitMessage(): string
    {
        return Craft::t('menu-builder', 'You’ve reached the Free plan limit. MenuBuilder Free includes {count} menu. Upgrade to Pro to create unlimited menus.', [
            'count' => self::FREE_MAX_MENUS,
        ]);
    }

    /**
     * Everything the control panel needs to describe the current edition, in
     * one call — see `templates/groups/_index.twig`. Assembled here rather
     * than in the controller so the CP has a single source for it, and so
     * adding a fact to that panel doesn't mean adding an edition check to a
     * screen.
     *
     * `licenseStatus` is Craft's own key status, shown as information only;
     * the raw license key is never included.
     *
     * @return array{
     *     isPro: bool,
     *     editionName: string,
     *     menuCount: int,
     *     maxMenus: int|null,
     *     canCreate: bool,
     *     upgradeUrl: string|null,
     *     licenseActive: bool,
     *     limitMessage: string,
     * }
     */
    public function cpSummary(): array
    {
        $license = MenuBuilder::getInstance()->license;
        $isPro = $license->isPro();

        return [
            'isPro' => $isPro,
            'editionName' => $license->getEditionName(),
            'menuCount' => $this->getMenuCount(),
            'maxMenus' => self::maxMenusFor($isPro),
            'canCreate' => $this->canCreateMenu(),
            'upgradeUrl' => $isPro ? null : $license->getUpgradeUrl(),
            'licenseActive' => $isPro && $license->isLicenseActive(),
            'limitMessage' => self::limitMessage(),
        ];
    }
}
