<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\services\MenuBuilderLicenseService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderMenuLimitService;

/**
 * The edition rules, decided without a booted Craft app.
 *
 * Everything about Free-vs-Pro reduces to two questions — "is this edition
 * Pro?" and "may an install holding N menus have another?" — and both are
 * pure functions here, so they can be pinned exhaustively. What they are
 * pinned *against* is the product rule, written out by hand: Free means one
 * menu, Pro means no ceiling, and an install that is already over its
 * ceiling is never asked to give a menu up.
 *
 * The enforcement itself — that these answers actually stop a second menu
 * reaching the database, through the service and through the controller —
 * is MenuBuilderMenuLimitTest in the integration suite. Neither test can
 * substitute for the other.
 */
class MenuBuilderLicensingTest extends TestCase
{
    // ---------------------------------------------------------------------
    // Editions
    // ---------------------------------------------------------------------

    /**
     * Craft installs the first edition when none is named, and
     * `Plugin::is()` compares editions by their index in this list, so the
     * order is load-bearing: Free first, Pro last.
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
     * The edition comes out of project config, which is a file a human can
     * edit. Anything unrecognized is Free — never Pro, and never a fatal.
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

    /**
     * @return array<string,array{string|null}>
     */
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
     * The guard in front of `Plugin::is()`. `is()` throws on an edition it
     * doesn't declare, and the edition it is handed comes from project
     * config, so an unrecognized value has to be recognized *as*
     * unrecognized before it reaches Craft.
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

    // ---------------------------------------------------------------------
    // The limit
    // ---------------------------------------------------------------------

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
     * The lapsed-Pro case, as arithmetic: an install holding more menus than
     * its edition allows is refused a *new* one and nothing else. Whether
     * its existing menus survive is proven against a real database in
     * MenuBuilderMenuLimitTest; what is pinned here is that being over the
     * limit never produces a negative allowance or an off-by-one that would
     * let one more through.
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

    /**
     * @return array<string,array{int}>
     */
    public static function overTheLimitProvider(): array
    {
        return [
            'at the limit' => [1],
            'two menus' => [2],
            'the five-menu Pro install from the docs' => [5],
            'a large install' => [50],
        ];
    }

    /**
     * @dataProvider proMenuCountProvider
     */
    public function testProMayAlwaysCreateAnotherMenu(int $menuCount): void
    {
        $this->assertTrue(MenuBuilderMenuLimitService::canCreate(
            MenuBuilderMenuLimitService::maxMenusFor(isPro: true),
            $menuCount,
        ));
    }

    /**
     * @return array<string,array{int}>
     */
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
     * Pinned loosely — the sentence may be rephrased — but it has to name
     * the plan, the number and the way out, because that is the whole job it
     * has.
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
