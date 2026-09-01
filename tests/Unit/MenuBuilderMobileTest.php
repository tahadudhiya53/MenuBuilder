<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\helpers\MobileHelper;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use Tahadudhiya\MenuBuilder\models\MenuBuilderTree;

/**
 * The mobile-navigation model: one menu, presented differently.
 *
 * There is no second menu and no duplicated tree — see the {@see MobileHelper}
 * class docblock for why. What exists instead is four presentation facts per
 * item, in the `metadata` bag that already carries the mega-menu config and
 * the badge style, and one pure re-shaping of an already-resolved tree
 * ({@see MenuBuilderTree::forViewport()}).
 *
 * Two properties are load-bearing throughout and are tested here rather than
 * assumed:
 *
 * - **Everything fails closed toward keeping the link.** An unrecognised
 *   visibility, an unknown viewport, a garbage mega behaviour: the item stays
 *   in the navigation. The opposite failure — links silently vanishing from
 *   the only navigation a phone has — is the one nobody notices until a
 *   customer can't find the shop.
 * - **A default is never stored.** An item nobody has configured for mobile
 *   carries no `mobile` key at all, so "empty means unconfigured" stays true
 *   in the column, in the cache and in the form.
 */
class MenuBuilderMobileTest extends TestCase
{
    // ---------------------------------------------------------------
    // MobileHelper: the grammar
    // ---------------------------------------------------------------

    public function testTheThreeVisibilitiesReadBackAsThemselves(): void
    {
        foreach (MobileHelper::VISIBILITIES as $visibility) {
            $this->assertSame($visibility, MobileHelper::visibility($visibility));
        }
    }

    public function testAnUnrecognizedVisibilityFailsClosedToBoth(): void
    {
        foreach ([null, '', 'DESKTOPONLY', 'phone', 'desktop', 1, true, ['mobileOnly'], new \stdClass()] as $value) {
            $this->assertSame(
                MobileHelper::VISIBILITY_BOTH,
                MobileHelper::visibility($value),
                'An unreadable visibility must keep the item in both navigations, never drop it from one.'
            );
        }
    }

    public function testOnlyAKnownVisibilityIsStorable(): void
    {
        $this->assertTrue(MobileHelper::isValidVisibility(null));
        $this->assertTrue(MobileHelper::isValidVisibility(''));
        $this->assertTrue(MobileHelper::isValidVisibility('mobileOnly'));
        $this->assertFalse(MobileHelper::isValidVisibility('phone'));
        $this->assertFalse(MobileHelper::isValidVisibility(3));
    }

    public function testVisibilityDecidesWhichViewportsAnItemBelongsTo(): void
    {
        $both = [];
        $desktopOnly = ['visibility' => MobileHelper::VISIBILITY_DESKTOP_ONLY];
        $mobileOnly = ['visibility' => MobileHelper::VISIBILITY_MOBILE_ONLY];

        $this->assertTrue(MobileHelper::isVisibleOn(MobileHelper::VIEWPORT_DESKTOP, $both));
        $this->assertTrue(MobileHelper::isVisibleOn(MobileHelper::VIEWPORT_MOBILE, $both));

        $this->assertTrue(MobileHelper::isVisibleOn(MobileHelper::VIEWPORT_DESKTOP, $desktopOnly));
        $this->assertFalse(MobileHelper::isVisibleOn(MobileHelper::VIEWPORT_MOBILE, $desktopOnly));

        $this->assertFalse(MobileHelper::isVisibleOn(MobileHelper::VIEWPORT_DESKTOP, $mobileOnly));
        $this->assertTrue(MobileHelper::isVisibleOn(MobileHelper::VIEWPORT_MOBILE, $mobileOnly));
    }

    /** A template that passes a typo must render the menu, not an empty landmark. */
    public function testAnUnknownViewportKeepsEverything(): void
    {
        foreach ([['visibility' => 'desktopOnly'], ['visibility' => 'mobileOnly'], []] as $config) {
            $this->assertTrue(MobileHelper::isVisibleOn('tablet', $config));
        }
    }

    public function testAnOrderAcceptsIntegersAndDigitStringsOnly(): void
    {
        $this->assertSame(3, MobileHelper::order(3));
        $this->assertSame(3, MobileHelper::order('3'));
        $this->assertSame(3, MobileHelper::order(' 3 '));
        $this->assertSame(0, MobileHelper::order(0));

        foreach ([null, '', true, false, 'first', '3rd', '-2', 1.5, [], new \stdClass()] as $value) {
            $this->assertNull(MobileHelper::order($value), 'Only a whole number is an order.');
        }
    }

    public function testAnOutOfRangeOrderClampsRatherThanDisappears(): void
    {
        $this->assertSame(MobileHelper::ORDER_MAX, MobileHelper::order(999999));
        $this->assertSame(MobileHelper::ORDER_MIN, MobileHelper::order(MobileHelper::ORDER_MIN));
    }

    /** Absence and an explicit `false` are different answers — see MobileHelper::collapsible(). */
    public function testCollapsibleDistinguishesAbsenceFromAnExplicitFalse(): void
    {
        $this->assertTrue(MobileHelper::collapsible(true));
        $this->assertTrue(MobileHelper::collapsible('1'));
        $this->assertTrue(MobileHelper::collapsible(1));
        $this->assertFalse(MobileHelper::collapsible(false));
        $this->assertFalse(MobileHelper::collapsible('0'));
        $this->assertFalse(MobileHelper::collapsible(0));
        $this->assertNull(MobileHelper::collapsible(null));
        $this->assertNull(MobileHelper::collapsible(''));
        $this->assertNull(MobileHelper::collapsible('yes'));
    }

    public function testTheMegaBehaviorsReadBackAsThemselves(): void
    {
        foreach (MobileHelper::MEGA_BEHAVIORS as $behavior) {
            $this->assertSame($behavior, MobileHelper::megaMenuBehavior($behavior));
        }
    }

    /** Failing closed to "hide" would drop links from the only navigation a phone has. */
    public function testAnUnrecognizedMegaBehaviorFailsClosedToStackAndNeverToHide(): void
    {
        foreach ([null, '', 'HIDE ', 'collapse', 0, true, ['hide']] as $value) {
            $this->assertSame(MobileHelper::MEGA_STACK, MobileHelper::megaMenuBehavior($value));
        }
    }

    // ---------------------------------------------------------------
    // MobileHelper::config() / fromForm(): defaults are never stored
    // ---------------------------------------------------------------

    public function testAnItemWithNoMobileKeyReadsAsNothingConfigured(): void
    {
        $this->assertSame([], MobileHelper::config([]));
        $this->assertSame([], MobileHelper::config(['megaMenu' => ['enabled' => true]]));
        $this->assertSame([], MobileHelper::config([MobileHelper::METADATA_KEY => 'not an object']));
    }

    public function testEveryDefaultIsOmittedFromStorage(): void
    {
        $this->assertSame([], MobileHelper::fromForm('both', '', '', 'stack'));
        $this->assertSame([], MobileHelper::fromForm(null, null, null, null));
        $this->assertSame([], MobileHelper::fromForm('nonsense', 'nonsense', 'nonsense', 'nonsense'));
    }

    public function testOnlyTheNonDefaultAnswersAreStored(): void
    {
        $this->assertSame(
            ['visibility' => 'mobileOnly', 'order' => 2, 'collapsible' => false, 'megaMenu' => 'hide'],
            MobileHelper::fromForm('mobileOnly', '2', '0', 'hide')
        );

        $this->assertSame(['order' => 5], MobileHelper::fromForm('both', 5, '', 'stack'));
        $this->assertSame(['collapsible' => false], MobileHelper::fromForm('both', null, false, 'stack'));
    }

    /** A stored `true` is the default too, so it is not written back — the bag stays minimal. */
    public function testAnExplicitCollapsibleTrueIsStoredBecauseAbsenceAlreadyMeansIt(): void
    {
        $this->assertSame(['collapsible' => true], MobileHelper::config([MobileHelper::METADATA_KEY => ['collapsible' => true]]));
    }

    public function testAStoredBagIsNormalizedOnTheWayOut(): void
    {
        $config = MobileHelper::config([MobileHelper::METADATA_KEY => [
            'visibility' => 'sideways',
            'order' => '99999',
            'collapsible' => 'maybe',
            'megaMenu' => 'explode',
        ]]);

        $this->assertSame(['order' => MobileHelper::ORDER_MAX], $config, 'A hand-written row reads back as defaults, not as what it says.');
    }

    public function testTheViewportAttributeIsOnlyEmittedForARestrictedItem(): void
    {
        $this->assertNull(MobileHelper::viewportAttribute([]));
        $this->assertSame('desktop', MobileHelper::viewportAttribute(['visibility' => 'desktopOnly']));
        $this->assertSame('mobile', MobileHelper::viewportAttribute(['visibility' => 'mobileOnly']));
    }

    // ---------------------------------------------------------------
    // MenuBuilderItem: validation and the CP-form accessors
    // ---------------------------------------------------------------

    private function item(mixed $mobile): MenuBuilderItem
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->title = 'Products';
        $item->customUrl = 'https://example.com';
        $item->metadata = $mobile === null ? [] : [MobileHelper::METADATA_KEY => $mobile];

        return $item;
    }

    public function testAValidMobileConfigValidates(): void
    {
        $item = $this->item(['visibility' => 'mobileOnly', 'order' => 4, 'collapsible' => false, 'megaMenu' => 'columns']);

        $this->assertTrue($item->validate(), implode(' ', $item->getErrorSummary(true)));
    }

    public function testAnItemWithNoMobileConfigValidates(): void
    {
        $this->assertTrue($this->item(null)->validate());
    }

    public function testAMobileBagThatIsNotAnObjectIsRejected(): void
    {
        $item = $this->item('desktopOnly');

        $this->assertFalse($item->validate());
        $this->assertNotEmpty($item->getErrors('metadata'));
    }

    public function testAnUnknownVisibilityIsRejectedRatherThanSilentlyDefaulted(): void
    {
        $item = $this->item(['visibility' => 'phone']);

        $this->assertFalse($item->validate());
        $this->assertNotEmpty($item->getErrors('metadata'));
    }

    public function testAnUnknownMegaBehaviorIsRejected(): void
    {
        $item = $this->item(['megaMenu' => 'explode']);

        $this->assertFalse($item->validate());
    }

    public function testANonNumericOrderIsRejected(): void
    {
        $this->assertFalse($this->item(['order' => 'first'])->validate());
        $this->assertFalse($this->item(['order' => true])->validate());
    }

    /** A sequence hint is not worth refusing a save over — it clamps instead. */
    public function testAnOutOfRangeOrderStillValidates(): void
    {
        $item = $this->item(['order' => 999999]);

        $this->assertTrue($item->validate(), implode(' ', $item->getErrorSummary(true)));
        $this->assertSame(MobileHelper::ORDER_MAX, $item->mobileConfig()['order']);
    }

    public function testANonBooleanCollapsibleIsRejected(): void
    {
        $this->assertFalse($this->item(['collapsible' => 'maybe'])->validate());
        $this->assertTrue($this->item(['collapsible' => true])->validate());
        $this->assertTrue($this->item(['collapsible' => '0'])->validate());
    }

    public function testTheCpFormAccessorsAlwaysHaveAnAnswer(): void
    {
        $this->assertSame('both', $this->item(null)->mobileVisibility());
        $this->assertSame('stack', $this->item(null)->mobileMegaMenuBehavior());
        $this->assertSame('mobileOnly', $this->item(['visibility' => 'mobileOnly'])->mobileVisibility());
        $this->assertSame('hide', $this->item(['megaMenu' => 'hide'])->mobileMegaMenuBehavior());
        $this->assertSame('both', $this->item(['visibility' => 'nonsense'])->mobileVisibility());
    }

    // ---------------------------------------------------------------
    // MenuBuilderNode: the derived accessors
    // ---------------------------------------------------------------

    /** @param MenuBuilderNode[] $children */
    private function node(int $id, string $title = 'Item', array $mobile = [], array $children = []): MenuBuilderNode
    {
        $node = new MenuBuilderNode(
            id: $id,
            handle: null,
            type: 'url',
            title: $title,
            url: '/' . strtolower($title),
            isClickable: true,
            isLinkAvailable: true,
            target: '_self',
            rel: null,
            cssClass: null,
            htmlId: null,
            htmlAttributes: [],
            ariaLabel: null,
            titleAttribute: null,
            icon: null,
            badge: null,
            description: null,
            image: null,
            featured: false,
            level: 1,
            mobile: $mobile,
        );
        $node->children = $children;

        foreach ($children as $child) {
            $child->parent = $node;
        }

        return $node;
    }

    public function testANodeWithNoMobileConfigBelongsEverywhere(): void
    {
        $node = $this->node(1);

        $this->assertSame('both', $node->mobileVisibility());
        $this->assertTrue($node->showsOnDesktop());
        $this->assertTrue($node->showsOnMobile());
        $this->assertNull($node->viewportAttribute());
        $this->assertNull($node->mobileOrder());
        $this->assertSame('stack', $node->mobileMegaMenuBehavior());
    }

    public function testARestrictedNodeAnswersPerViewport(): void
    {
        $desktopOnly = $this->node(1, mobile: ['visibility' => 'desktopOnly']);
        $mobileOnly = $this->node(2, mobile: ['visibility' => 'mobileOnly']);

        $this->assertTrue($desktopOnly->showsOnDesktop());
        $this->assertFalse($desktopOnly->showsOnMobile());
        $this->assertSame('desktop', $desktopOnly->viewportAttribute());

        $this->assertFalse($mobileOnly->showsOnDesktop());
        $this->assertTrue($mobileOnly->showsOnMobile());
        $this->assertSame('mobile', $mobileOnly->viewportAttribute());
    }

    /** A <details> around no children is a control that opens an empty panel. */
    public function testALeafIsNeverCollapsible(): void
    {
        $this->assertFalse($this->node(1)->isMobileCollapsible());
        $this->assertFalse($this->node(1, mobile: ['collapsible' => true])->isMobileCollapsible());
    }

    public function testABranchIsCollapsibleByDefaultAndTheEditorCanTurnItOff(): void
    {
        $children = [$this->node(2, 'Child')];

        $this->assertTrue($this->node(1, children: $children)->isMobileCollapsible());
        $this->assertTrue($this->node(1, mobile: ['collapsible' => true], children: $children)->isMobileCollapsible());
        $this->assertFalse($this->node(1, mobile: ['collapsible' => false], children: $children)->isMobileCollapsible());
    }

    /** The node fails closed over its own stored bag, the way iconClass() and badgeClass() do. */
    public function testANodeCarryingAGarbageBagReadsBackAsDefaults(): void
    {
        $node = $this->node(1, mobile: ['visibility' => 'sideways', 'megaMenu' => 'explode', 'order' => 'first']);

        $this->assertSame('both', $node->mobileVisibility());
        $this->assertSame('stack', $node->mobileMegaMenuBehavior());
        $this->assertNull($node->mobileOrder());
        $this->assertNull($node->viewportAttribute());
    }

    // ---------------------------------------------------------------
    // MenuBuilderTree::forViewport(): filtering and ordering
    // ---------------------------------------------------------------

    /** @param MenuBuilderNode[] $nodes */
    private function tree(array $nodes): MenuBuilderTree
    {
        return new MenuBuilderTree(new MenuBuilderGroup(['name' => 'Main', 'handle' => 'main']), $nodes);
    }

    /**
     * @param MenuBuilderNode[] $nodes
     * @return string[]
     */
    private function titles(iterable $nodes): array
    {
        $titles = [];

        foreach ($nodes as $node) {
            $titles[] = $node->title;
        }

        return $titles;
    }

    public function testDesktopDropsMobileOnlyItemsAndKeepsTheRest(): void
    {
        $tree = $this->tree([
            $this->node(1, 'Home'),
            $this->node(2, 'Call us', mobile: ['visibility' => 'mobileOnly']),
            $this->node(3, 'Downloads', mobile: ['visibility' => 'desktopOnly']),
        ]);

        $this->assertSame(['Home', 'Downloads'], $this->titles($tree->forViewport('desktop')));
    }

    public function testMobileDropsDesktopOnlyItemsAndKeepsTheRest(): void
    {
        $tree = $this->tree([
            $this->node(1, 'Home'),
            $this->node(2, 'Call us', mobile: ['visibility' => 'mobileOnly']),
            $this->node(3, 'Downloads', mobile: ['visibility' => 'desktopOnly']),
        ]);

        $this->assertSame(['Home', 'Call us'], $this->titles($tree->forViewport('mobile')));
    }

    public function testRestrictingAParentTakesItsWholeBranchWithIt(): void
    {
        $tree = $this->tree([
            $this->node(1, 'Explore', mobile: ['visibility' => 'desktopOnly'], children: [
                $this->node(2, 'Latest'),
                $this->node(3, 'Archive'),
            ]),
        ]);

        $this->assertCount(0, $tree->forViewport('mobile'));
        $this->assertCount(3, $tree->forViewport('desktop')->flatten());
    }

    public function testNestedItemsAreFilteredAtEveryLevel(): void
    {
        $tree = $this->tree([
            $this->node(1, 'Explore', children: [
                $this->node(2, 'Latest'),
                $this->node(3, 'Print catalogue', mobile: ['visibility' => 'desktopOnly']),
                $this->node(4, 'Call us', mobile: ['visibility' => 'mobileOnly']),
            ]),
        ]);

        $this->assertSame(['Latest', 'Call us'], $this->titles($tree->forViewport('mobile')->items[0]->children));
        $this->assertSame(['Latest', 'Print catalogue'], $this->titles($tree->forViewport('desktop')->items[0]->children));
    }

    public function testMobileOrderPlacesNumberedItemsAndLeavesTheRestAlone(): void
    {
        $tree = $this->tree([
            $this->node(1, 'Home'),
            $this->node(2, 'Products'),
            $this->node(3, 'Contact', mobile: ['order' => 1]),
            $this->node(4, 'About'),
        ]);

        $this->assertSame(['Contact', 'Home', 'Products', 'About'], $this->titles($tree->forViewport('mobile')));
    }

    /** Unnumbered siblings keep the order the editor dragged them into, relative to each other. */
    public function testUnorderedSiblingsKeepTheirEditorOrder(): void
    {
        $tree = $this->tree([
            $this->node(1, 'Zulu'),
            $this->node(2, 'Alpha'),
            $this->node(3, 'Mike'),
        ]);

        $this->assertSame(['Zulu', 'Alpha', 'Mike'], $this->titles($tree->forViewport('mobile')));
    }

    public function testMobileOrderAppliesAtEveryDepth(): void
    {
        $tree = $this->tree([
            $this->node(1, 'Explore', children: [
                $this->node(2, 'Latest'),
                $this->node(3, 'Archive', mobile: ['order' => 1]),
            ]),
        ]);

        $this->assertSame(['Archive', 'Latest'], $this->titles($tree->forViewport('mobile')->items[0]->children));
    }

    /** Order is a mobile-only presentation fact; the desktop tree is the editor's tree. */
    public function testDesktopIgnoresMobileOrder(): void
    {
        $tree = $this->tree([
            $this->node(1, 'Home'),
            $this->node(2, 'Contact', mobile: ['order' => 1]),
        ]);

        $this->assertSame(['Home', 'Contact'], $this->titles($tree->forViewport('desktop')));
    }

    public function testAnUnknownViewportRendersTheWholeMenuUnchanged(): void
    {
        $tree = $this->tree([
            $this->node(1, 'Home'),
            $this->node(2, 'Call us', mobile: ['visibility' => 'mobileOnly']),
            $this->node(3, 'Downloads', mobile: ['visibility' => 'desktopOnly', 'order' => 1]),
        ]);

        $this->assertSame(['Home', 'Call us', 'Downloads'], $this->titles($tree->forViewport('tablet')));
    }

    /** The tree it re-shapes has already been active-marked; losing that would break aria-current. */
    public function testActiveStateSurvivesTheViewportCopy(): void
    {
        $child = $this->node(2, 'Latest');
        $child->isActive = true;
        $parent = $this->node(1, 'Explore', children: [$child]);
        $parent->isActiveAncestor = true;

        $mobile = $this->tree([$parent])->forViewport('mobile');

        $this->assertTrue($mobile->items[0]->isActiveAncestor);
        $this->assertTrue($mobile->items[0]->children[0]->isActive);
        $this->assertTrue($mobile->items[0]->isActiveOrAncestor());
    }

    /** These objects can be the cached ones — see MenuBuilderNode::withChildren(). */
    public function testTheOriginalTreeIsNotMutatedByAViewportCopy(): void
    {
        $original = $this->tree([
            $this->node(1, 'Home'),
            $this->node(2, 'Contact', mobile: ['order' => 1]),
            $this->node(3, 'Downloads', mobile: ['visibility' => 'desktopOnly']),
        ]);

        $original->forViewport('mobile');

        $this->assertSame(['Home', 'Contact', 'Downloads'], $this->titles($original));
    }

    public function testAViewportCopyRewiresParentsToTheCopiedNodes(): void
    {
        $tree = $this->tree([$this->node(1, 'Explore', children: [$this->node(2, 'Latest')])]);
        $mobile = $tree->forViewport('mobile');

        $this->assertSame($mobile->items[0], $mobile->items[0]->children[0]->parent);
        $this->assertNotSame($tree->items[0], $mobile->items[0]->children[0]->parent);
    }

    /** The plain resolve pipeline still resets active state — only forViewport() preserves it. */
    public function testWithChildrenStillResetsActiveStateByDefault(): void
    {
        $node = $this->node(1, 'Home');
        $node->isActive = true;

        $this->assertFalse($node->withChildren([])->isActive);
        $this->assertTrue($node->withChildren([], preserveActiveState: true)->isActive);
    }

    // ---------------------------------------------------------------
    // The CP wiring: form, controller and storage agree
    // ---------------------------------------------------------------

    
    
    /**
     * An item nobody has configured for mobile has an empty `mobile` bag, and
     * Twig runs with `strict_variables` in the CP: reading a key off an empty
     * mapping is a hard error, not null. The editor slideout blew up with
     * `Key "collapsible" does not exist as the sequence/mapping is empty` for
     * exactly that reason, so every read of the two optional config bags in
     * the form has to be guarded — `??` suppresses it, a bare `.key` does not.
     */
    public function testTheFormNeverReadsAnOptionalConfigBagUnguarded(): void
    {
        $form = file_get_contents(__DIR__ . '/../../src/templates/items/_fields.twig');

        preg_match_all('/(mobileConfig|megaMenuConfig)\.([A-Za-z]+)(\s*\?\?)?/', $form, $matches, PREG_SET_ORDER);

        $this->assertNotEmpty($matches, 'The form still reads the mobile and mega menu config bags.');

        foreach ($matches as $match) {
            $this->assertNotEmpty(
                $match[3] ?? '',
                $match[1] . '.' . $match[2] . ' is read without a ?? default, which throws when the bag is empty.',
            );
        }
    }

    public function testTheGroupIsCarriedOntoTheViewportTree(): void
    {
        $tree = $this->tree([$this->node(1, 'Home')]);

        $this->assertSame($tree->group, $tree->forViewport('mobile')->group);
    }
}
