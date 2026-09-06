<?php

namespace Tahadudhiya\MenuBuilder\services;

use Craft;
use craft\base\Component;
use Tahadudhiya\MenuBuilder\MenuBuilder;

/**
 * How many menus this install may have, and whether it may have one more.
*/
class MenuBuilderMenuLimitService extends Component
{
    /**
     * The Free edition's ceiling.
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
     * How many menus exist right now — every menu, enabled or not, on every site.
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
     * Pure form of {@see canCreateMenu()}, so the arithmetic is testable without a database.
    */
    public static function canCreate(?int $maxMenus, int $menuCount): bool
    {
        return $maxMenus === null || $menuCount < $maxMenus;
    }

    /**
     * What the CP shows, and what the refused save reports.
    */
    public static function limitMessage(): string
    {
        return Craft::t('menu-builder', 'You’ve reached the Free plan limit. MenuBuilder Free includes {count} menu. Upgrade to Pro to create unlimited menus.', [
            'count' => self::FREE_MAX_MENUS,
        ]);
    }

    /**
     * Everything the control panel needs to describe the current edition, in one call — see
     * `templates/groups/_index.twig`.
     *
     * @return array{ isPro: bool, editionName: string, menuCount: int, maxMenus: int|null, canCreate: bool, upgradeUrl: string|null, licenseActive: bool, limitMessage: string, }
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
