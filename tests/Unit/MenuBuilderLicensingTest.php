<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\services\MenuBuilderLicenseService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderMenuLimitService;

/**
 * The edition rules, decided without a booted Craft app.
*/
class MenuBuilderLicensingTest extends TestCase
{
    // Editions

    /**
     * Craft installs the first edition when none is named, and `Plugin::is()` compares editions by
     * their index in this list, so the order is load-bearing: Free first, Pro last.
    */
    public function testFreeIsTheDefaultEditionAndProIsTheHighest(): void
    {
        $editions = MenuBuilder::editions();

        $this->assertSame(['free', 'pro'], $editions);
        $this->assertSame('free', MenuBuilder::EDITION_FREE);
        $this->assertSame('pro', MenuBuilder::EDITION_PRO);
        $this->assertSame(MenuBuilder::EDITION_FREE, $editions[0]);
        $this->assertSame(MenuBuilder::EDITION_PRO, end($editions));
    }

    public function testOnlyTheProEditionIsPro(): void
    {
        $this->assertTrue(MenuBuilderLicenseService::editionIsPro(MenuBuilder::EDITION_PRO));
        $this->assertFalse(MenuBuilderLicenseService::editionIsPro(MenuBuilder::EDITION_FREE));
    }

    /**
     * The edition comes out of project config, which is a file a human can edit.
     *
     * @dataProvider unrecognizedEditionProvider
    */
    public function testAnUnrecognizedEditionIsTreatedAsFree(?string $edition): void
    {
        $this->assertFalse(MenuBuilderLicenseService::editionIsPro($edition));
        $this->assertSame(MenuBuilderMenuLimitService::FREE_MAX_MENUS, MenuBuilderMenuLimitService::maxMenusFor(
            MenuBuilderLicenseService::editionIsPro($edition)
        ));
    }

    /** @return array<string,array{string|null}> */
    public static function unrecognizedEditionProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'craft’s default edition name' => ['standard'],
            'wrong case' => ['Pro'],
            'invented' => ['enterprise'],
        ];
    }

    /**
     * The guard in front of `Plugin::is()`.
    */
    public function testOnlyDeclaredEditionsAreRecognized(): void
    {
        $this->assertTrue(MenuBuilderLicenseService::isKnownEdition(MenuBuilder::EDITION_FREE));
        $this->assertTrue(MenuBuilderLicenseService::isKnownEdition(MenuBuilder::EDITION_PRO));

        foreach ([null, '', 'standard', 'Pro', 'enterprise'] as $edition) {
            $this->assertFalse(
                MenuBuilderLicenseService::isKnownEdition($edition),
                sprintf('"%s" was treated as a declared edition.', (string)$edition)
            );
        }
    }

    public function testEachEditionHasItsOwnName(): void
    {
        $this->assertSame('Pro', MenuBuilderLicenseService::editionName(MenuBuilder::EDITION_PRO));
        $this->assertSame('Free', MenuBuilderLicenseService::editionName(MenuBuilder::EDITION_FREE));
        $this->assertSame('Free', MenuBuilderLicenseService::editionName(null));
    }

    // Where "Upgrade to Pro" goes

    /**
     * The upgrade link must not use `plugin-store/buy/<handle>/<edition>`.
     *
     * That route is real, but the Plugin Store's own app reads it as "add this plugin to the
     * cart" and decides whether it can by looking at the price of the plugin's *first* edition.
     * MenuBuilder's first edition is Free, at no cost, so the check fails and the app redirects
     * to the Plugin Store index — the upgrade button bounced editors to a page that said nothing
     * about MenuBuilder. The editions screen is the supported entry point for a plugin whose base
     * edition is free: it renders one card per declared edition with whichever of Try and Buy the
     * store offers this install.
    */
    public function testTheUpgradeLinkGoesToTheEditionsScreenRatherThanTheCart(): void
    {
        $path = MenuBuilderLicenseService::upgradePath();

        $this->assertSame('plugin-store/menubuilder/editions', $path);
        $this->assertStringNotContainsString('plugin-store/buy', $path);
        $this->assertStringContainsString(MenuBuilderLicenseService::PLUGIN_HANDLE, $path);
    }

    /**
     * The handle in the link is the handle Craft knows this plugin by — the Plugin Store resolves
     * the listing from it, and a mismatch would 404 just as surely as the wrong route redirects.
    */
    public function testTheUpgradeLinkNamesThePluginHandleComposerDeclares(): void
    {
        $composer = json_decode(
            (string)file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
            true,
        );

        $this->assertSame($composer['extra']['handle'], MenuBuilderLicenseService::PLUGIN_HANDLE);
    }

    /** Whoever can't reach the CP's Plugin Store is sent to the public listing instead. */
    public function testTheFallbackIsThePublicListing(): void
    {
        $this->assertSame(
            'https://plugins.craftcms.com/menubuilder',
            MenuBuilderLicenseService::marketplaceUrl()
        );
    }

    // The limit

    public function testFreeAllowsExactlyOneMenuAndProAllowsUnlimited(): void
    {
        $this->assertSame(1, MenuBuilderMenuLimitService::FREE_MAX_MENUS);
        $this->assertSame(1, MenuBuilderMenuLimitService::maxMenusFor(isPro: false));
        $this->assertNull(MenuBuilderMenuLimitService::maxMenusFor(isPro: true));
    }

    public function testFreeMayCreateItsFirstMenuAndNoMore(): void
    {
        $free = MenuBuilderMenuLimitService::maxMenusFor(isPro: false);

        $this->assertTrue(MenuBuilderMenuLimitService::canCreate($free, menuCount: 0));
        $this->assertFalse(MenuBuilderMenuLimitService::canCreate($free, menuCount: 1));
    }

    /**
     * The lapsed-Pro case, as arithmetic: an install holding more menus than its edition allows is
     * refused a *new* one and nothing else.
     *
     * @dataProvider overTheLimitProvider
    */
    public function testAnInstallOverTheFreeLimitIsSimplyNotAllowedAnother(int $menuCount): void
    {
        $this->assertFalse(MenuBuilderMenuLimitService::canCreate(
            MenuBuilderMenuLimitService::maxMenusFor(isPro: false),
            $menuCount,
        ));
    }

    /** @return array<string,array{int}> */
    public static function overTheLimitProvider(): array
    {
        return [
            'at the limit' => [1],
            'two menus' => [2],
            'the five-menu Pro install from the docs' => [5],
            'a large install' => [50],
        ];
    }

    /** @dataProvider proMenuCountProvider */
    public function testProMayAlwaysCreateAnotherMenu(int $menuCount): void
    {
        $this->assertTrue(MenuBuilderMenuLimitService::canCreate(
            MenuBuilderMenuLimitService::maxMenusFor(isPro: true),
            $menuCount,
        ));
    }

    /** @return array<string,array{int}> */
    public static function proMenuCountProvider(): array
    {
        return [
            'none yet' => [0],
            'one' => [1],
            'five' => [5],
            'a hundred' => [100],
        ];
    }

    /**
     * The wording the CP button, the flash and the refused save all share.
    */
    public function testTheLimitMessageSaysWhatTheLimitIsAndHowToLiftIt(): void
    {
        $message = MenuBuilderMenuLimitService::limitMessage();

        $this->assertStringContainsString('Free', $message);
        $this->assertStringContainsString((string)MenuBuilderMenuLimitService::FREE_MAX_MENUS, $message);
        $this->assertStringContainsString('Pro', $message);
        $this->assertStringContainsString('unlimited', $message);
    }
}
