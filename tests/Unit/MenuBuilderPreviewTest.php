<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use DateTime;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tahadudhiya\MenuBuilder\controllers\PreviewController;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use Tahadudhiya\MenuBuilder\models\MenuBuilderPreviewOptions;
use Tahadudhiya\MenuBuilder\services\MenuBuilderPreviewService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderResolver;
use Tahadudhiya\MenuBuilder\variables\MenuBuilderVariable;
use Tahadudhiya\MenuBuilder\visibility\VisibilityContext;

/**
 * Control-panel preview.
 *
 * Preview is a *simulation*, which makes two things worth pinning: that the
 * simulation can only ever narrow or match what a real visitor would see
 * (an editor-supplied query param must never widen it), and that simulating
 * changes nothing — no writes, and nothing left behind in the shared cache
 * or in the request's current site.
 *
 * Everything decided by MenuBuilderPreviewOptions is pure, so the whole
 * security-relevant surface is exercised here without a booted Craft app;
 * the parts that need one (site switching, element resolution) are pinned
 * structurally against the source, the same way MenuBuilderVisibilityTest
 * pins the cache/visibility ordering.
 */
class MenuBuilderPreviewTest extends TestCase
{
    private const ALLOWED_SITES = [1, 2];
    private const ALLOWED_GROUPS = [3, 4];

    private function normalize(array $params): MenuBuilderPreviewOptions
    {
        return MenuBuilderPreviewOptions::normalize($params, self::ALLOWED_SITES, self::ALLOWED_GROUPS, 1);
    }

    private function context(MenuBuilderPreviewOptions $options): VisibilityContext
    {
        return $options->toVisibilityContext(
            new DateTime('2026-08-28 10:00:00', new DateTimeZone('UTC')),
            new DateTimeZone('UTC'),
            'production'
        );
    }

    // ---------------------------------------------------------------------
    // Defaults, and what an unrecognised option does
    // ---------------------------------------------------------------------

    public function testAPreviewWithNoOptionsIsALoggedOutDesktopVisitorOnTheDefaultSite(): void
    {
        $options = $this->normalize([]);

        $this->assertSame(MenuBuilderPreviewOptions::DEVICE_DESKTOP, $options->device);
        $this->assertSame(MenuBuilderPreviewOptions::AUDIENCE_LOGGED_OUT, $options->audience);
        $this->assertSame([], $options->userGroupIds);
        $this->assertSame(1, $options->siteId);
    }

    /**
     * The narrowest audience is the fallback on purpose: an unrecognised
     * value must never be the one that reveals more than a real visitor
     * would see.
     */
    public function testAnUnrecognisedAudienceFallsBackToLoggedOut(): void
    {
        foreach ([null, '', 'admin', 'ADMIN', ['userGroup'], 7, true] as $value) {
            $this->assertSame(
                MenuBuilderPreviewOptions::AUDIENCE_LOGGED_OUT,
                $this->normalize(['audience' => $value])->audience,
                'An audience that is not one of the three known values must fail closed.'
            );
        }
    }

    public function testAnUnrecognisedDeviceFallsBackToDesktop(): void
    {
        foreach ([null, '', 'tablet', 'MOBILE', ['mobile'], 1] as $value) {
            $this->assertSame(MenuBuilderPreviewOptions::DEVICE_DESKTOP, $this->normalize(['device' => $value])->device);
        }
    }

    public function testDeviceIsCarriedThroughWhenItIsKnown(): void
    {
        $options = $this->normalize(['device' => 'mobile']);

        $this->assertSame(MenuBuilderPreviewOptions::DEVICE_MOBILE, $options->device);
        $this->assertTrue($options->isMobile());
        $this->assertFalse($this->normalize(['device' => 'desktop'])->isMobile());
    }

    // ---------------------------------------------------------------------
    // Placement — a preview control, never a stored setting
    // ---------------------------------------------------------------------

    public function testPlacementDefaultsToBothAndHonoursEachKnownValue(): void
    {
        $this->assertSame(MenuBuilderPreviewOptions::PLACEMENT_BOTH, $this->normalize([])->placement);
        $this->assertTrue($this->normalize([])->isHeader());
        $this->assertTrue($this->normalize([])->isFooter());
        $this->assertTrue($this->normalize([])->isBoth());
        $this->assertTrue($this->normalize(['placement' => 'footer'])->isFooter());
        $this->assertFalse($this->normalize(['placement' => 'footer'])->isHeader());
        $this->assertTrue($this->normalize(['placement' => 'header'])->isHeader());
        $this->assertFalse($this->normalize(['placement' => 'header'])->isFooter());
    }

    public function testAnUnrecognisedPlacementFallsBackRatherThanRenderingNowhere(): void
    {
        foreach ([null, '', 'sidebar', 'FOOTER', ['footer'], 3, true] as $value) {
            $this->assertSame(MenuBuilderPreviewOptions::PLACEMENT_BOTH, $this->normalize(['placement' => $value])->placement);
        }
    }

    // ---------------------------------------------------------------------
    // The audience → VisibilityContext mapping
    // ---------------------------------------------------------------------

    public function testTheLoggedOutAudienceIsAnAnonymousVisitor(): void
    {
        $context = $this->context($this->normalize(['audience' => 'loggedOut']));

        $this->assertFalse($context->isLoggedIn);
        $this->assertSame([], $context->userGroupIds);
    }

    public function testTheLoggedInAudienceIsASignedInVisitorInNoGroup(): void
    {
        $context = $this->context($this->normalize(['audience' => 'loggedIn']));

        $this->assertTrue($context->isLoggedIn);
        $this->assertSame([], $context->userGroupIds);
    }

    public function testTheUserGroupAudienceIsASignedInVisitorInTheChosenGroups(): void
    {
        $context = $this->context($this->normalize(['audience' => 'userGroup', 'userGroupIds' => ['3', '4']]));

        $this->assertTrue($context->isLoggedIn);
        $this->assertSame([3, 4], $context->userGroupIds);
    }

    /**
     * Group IDs are meaningless outside the group audience, and carrying
     * them anyway would let a `loggedIn` preview quietly pass a `userGroup`
     * visibility rule.
     */
    public function testGroupIdsAreIgnoredUnlessTheAudienceIsAUserGroup(): void
    {
        $options = new MenuBuilderPreviewOptions(
            audience: MenuBuilderPreviewOptions::AUDIENCE_LOGGED_IN,
            userGroupIds: [3],
        );

        $this->assertSame([], $this->context($options)->userGroupIds);

        $loggedOut = new MenuBuilderPreviewOptions(
            audience: MenuBuilderPreviewOptions::AUDIENCE_LOGGED_OUT,
            userGroupIds: [3],
        );

        $this->assertFalse($this->context($loggedOut)->isLoggedIn);
        $this->assertSame([], $this->context($loggedOut)->userGroupIds);
    }

    public function testTheContextCarriesTheSimulatedSiteAndTheRealClockAndEnvironment(): void
    {
        $context = $this->context($this->normalize(['siteId' => '2']));

        $this->assertSame(2, $context->currentSiteId);
        $this->assertSame('production', $context->environment, 'The environment is the real one — an `environment` rule must answer what it answers for a visitor.');
        $this->assertSame('2026-08-28 10:00:00', $context->now->format('Y-m-d H:i:s'), 'Time is not simulated.');
        $this->assertSame('UTC', $context->timezone?->getName());
    }

    // ---------------------------------------------------------------------
    // The user-group allowlist
    // ---------------------------------------------------------------------

    public function testAUserGroupOutsideTheAllowlistIsDropped(): void
    {
        $options = $this->normalize(['audience' => 'userGroup', 'userGroupIds' => ['3', '99']]);

        $this->assertSame([3], $options->userGroupIds);
    }

    /**
     * "A user group" with nothing selectable left is exactly "some logged-in
     * user" — so it says so, rather than being a third audience that behaves
     * identically but reads differently.
     */
    public function testAUserGroupAudienceWithNoUsableGroupBecomesTheLoggedInAudience(): void
    {
        foreach ([[], ['99'], null, 'userGroup', ['3' => 'x'], [true], [3.0], ['3abc'], [0], [-1]] as $value) {
            $options = $this->normalize(['audience' => 'userGroup', 'userGroupIds' => $value]);

            $this->assertSame(MenuBuilderPreviewOptions::AUDIENCE_LOGGED_IN, $options->audience);
            $this->assertSame([], $options->userGroupIds);
        }
    }

    /**
     * One malformed entry must not be quietly dropped while its neighbours
     * are honoured — ConfigHelper::strictIdList() rejects the whole list, so
     * the audience falls back rather than half-applying.
     */
    public function testAMalformedGroupListIsRejectedWholesaleRatherThanFiltered(): void
    {
        $options = $this->normalize(['audience' => 'userGroup', 'userGroupIds' => ['3', true]]);

        $this->assertSame(MenuBuilderPreviewOptions::AUDIENCE_LOGGED_IN, $options->audience);
        $this->assertSame([], $options->userGroupIds);
    }

    /** Craft's checkboxSelect posts an empty padding value; that one value is expected, not malformed. */
    public function testTheCheckboxSelectPaddingValueDoesNotInvalidateTheSelection(): void
    {
        $options = $this->normalize(['audience' => 'userGroup', 'userGroupIds' => ['', '4']]);

        $this->assertSame(MenuBuilderPreviewOptions::AUDIENCE_USER_GROUP, $options->audience);
        $this->assertSame([4], $options->userGroupIds);
    }

    // ---------------------------------------------------------------------
    // The site allowlist
    // ---------------------------------------------------------------------

    public function testASiteInsideTheAllowlistIsHonoured(): void
    {
        $this->assertSame(2, $this->normalize(['siteId' => '2'])->siteId);
        $this->assertSame(2, $this->normalize(['siteId' => 2])->siteId);
    }

    /**
     * The site decides which site's content the tree resolves against, so an
     * arbitrary ID must not be honoured just because it parses as an int —
     * that would be a read of another site's content through a preview.
     */
    public function testASiteOutsideTheAllowlistFallsBackToTheDefault(): void
    {
        foreach (['99', 99, '0', '-1', 'abc', '', null, true, ['2'], '2abc'] as $value) {
            $this->assertSame(1, $this->normalize(['siteId' => $value])->siteId, 'A site outside the allowlist must not be previewed.');
        }
    }

    public function testTheDefaultSiteIsItselfCheckedAgainstTheAllowlist(): void
    {
        $options = MenuBuilderPreviewOptions::normalize(['siteId' => '99'], [5], [], 1);

        $this->assertSame(1, $options->siteId, 'The caller-supplied default stands when it is all there is…');

        $options = MenuBuilderPreviewOptions::normalize([], [5], [], null);

        $this->assertSame(5, $options->siteId, '…and the first allowed site is used when there is no default at all.');
    }

    // ---------------------------------------------------------------------
    // The "N of M items" summary
    // ---------------------------------------------------------------------

    public function testPersistedAndDynamicNodesAreCountedSeparately(): void
    {
        $nodes = [
            $this->node(1, children: [
                $this->node(2),
                $this->node(101, isDynamic: true),
                $this->node(102, isDynamic: true),
            ]),
            $this->node(3),
        ];

        $this->assertSame(3, MenuBuilderPreviewService::countPersistedNodes($nodes));
        $this->assertSame(2, MenuBuilderPreviewService::countDynamicNodes($nodes));
    }

    public function testAnEmptyPreviewCountsNothing(): void
    {
        $this->assertSame(0, MenuBuilderPreviewService::countPersistedNodes([]));
        $this->assertSame(0, MenuBuilderPreviewService::countDynamicNodes([]));
    }

    // ---------------------------------------------------------------------
    // Preview changes nothing
    // ---------------------------------------------------------------------

    /**
     * The one promise the whole feature rests on. Asserted against the
     * source because there is no database here to observe a write with —
     * and because a write added to this service later would be a bug nobody
     * would think to look for.
     */
    public function testThePreviewServicePerformsNoWrites(): void
    {
        $source = $this->sourceOf(MenuBuilderPreviewService::class);

        foreach (['->save(', '->delete(', 'deleteById(', '->move(', 'beginTransaction(', '->insert(', '->update(', 'invalidate'] as $write) {
            $this->assertStringNotContainsString($write, $source, "Preview must not write: found `$write` in MenuBuilderPreviewService.");
        }
    }

    /**
     * Switching Craft's current site is what makes "preview another site"
     * mean anything, and it is request-scoped — but only if it is always put
     * back. Without the `finally`, an exception mid-resolve would leave the
     * rest of the control-panel request rendering as a different site.
     */
    public function testTheSimulatedSiteIsAlwaysRestored(): void
    {
        $source = $this->sourceOf(MenuBuilderPreviewService::class);
        $start = strpos($source, 'private function withSite');

        $this->assertNotFalse($start);

        $body = substr($source, $start);

        $this->assertStringContainsString('setCurrentSite($site)', $body);
        $this->assertStringContainsString('} finally {', $body, 'The original site must be restored in a finally block.');
        $this->assertStringContainsString('setCurrentSite($original)', $body);
    }

    /**
     * A simulated audience must enter the pipeline where the real one does:
     * after the shared cache. Passing it any earlier would bake one
     * previewer's audience into an entry every visitor then reads — the same
     * invariant MenuBuilderVisibilityTest pins for the request path.
     */
    public function testTheSimulatedAudienceIsAppliedAfterTheSharedCacheRead(): void
    {
        $source = $this->sourceOf(MenuBuilderResolver::class);
        $start = strpos($source, 'public function getTree');
        $end = strpos($source, 'public static function internalHosts');
        $body = substr($source, (int)$start, (int)$end - (int)$start);

        $cacheCall = strpos($body, 'cache->getOrSet');
        $filterCall = strpos($body, '$this->filterVisible(');

        $this->assertNotFalse($cacheCall);
        $this->assertGreaterThan($cacheCall, $filterCall);

        $cachedPayload = substr($body, (int)$cacheCall, (int)$filterCall - (int)$cacheCall);

        $this->assertStringNotContainsString('$context', $cachedPayload, 'The cached payload must not be built from the simulated audience.');
    }

    /** The override is optional, so every existing caller of getTree() is unaffected. */
    public function testTheResolverAcceptsAnOptionalSimulatedContext(): void
    {
        $parameters = (new ReflectionMethod(MenuBuilderResolver::class, 'getTree'))->getParameters();

        $this->assertCount(4, $parameters);
        $this->assertSame('context', $parameters[2]->getName());
        $this->assertTrue($parameters[2]->isOptional(), 'A front-end call must keep working unchanged.');
        $this->assertSame('markActive', $parameters[3]->getName());
        $this->assertTrue($parameters[3]->isOptional(), 'Front-end active-state behaviour remains the default.');
        $this->assertTrue($parameters[3]->getDefaultValue());

        $type = $parameters[2]->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(VisibilityContext::class, $type->getName());
        $this->assertTrue($type->allowsNull());
    }

    /** A generic presentation preview must not invent a current page. */
    public function testThePreviewExplicitlyDisablesActiveState(): void
    {
        $source = $this->sourceOf(MenuBuilderPreviewService::class);

        $this->assertStringContainsString('markActive: false', $source);
        $this->assertStringNotContainsString('$options->uri', $source);
    }

    // ---------------------------------------------------------------------
    // The screen itself
    // ---------------------------------------------------------------------

    public function testPreviewRequiresOnlyTheViewPermission(): void
    {
        $this->assertSame('menuBuilder:view', PreviewController::requiredPermissionForAction('index'));
    }

    public function testThePreviewNoLongerSimulatesASeenFromPage(): void
    {
        $template = $this->previewTemplate();
        $properties = array_map(
            static fn($property): string => $property->getName(),
            (new ReflectionClass(MenuBuilderPreviewOptions::class))->getProperties()
        );

        $this->assertStringNotContainsString('Seen from', $template);
        $this->assertStringNotContainsString('name="uri"', $template);
        $this->assertNotContains('uri', $properties);
        $this->assertFalse(method_exists(MenuBuilderPreviewOptions::class, 'normalizeUri'));
        $this->assertFalse(method_exists(MenuBuilderPreviewService::class, 'addressLabel'));
    }

    /**
     * The preview surface is the shipped front-end renderer, not a
     * control-panel copy of it — otherwise what an editor verifies here and
     * what a visitor receives could drift apart silently.
     */
    public function testThePreviewScreenRendersThroughTheShippedMacros(): void
    {
        $template = $this->previewTemplate();

        $this->assertStringContainsString('{% import "menu-builder/_macros/tree" as menuMacros %}', $template);
        $this->assertStringContainsString('menuMacros.render(nodes)', $template);
    }

    /**
     * The markup panel exists to show attributes as text. Printing it
     * unescaped would render it a second time instead — and `|raw` anywhere
     * on this screen would put editor-authored titles, badges and classes
     * into the page unescaped.
     */
    public function testThePreviewScreenNeverPrintsAnythingRaw(): void
    {
        $this->assertStringNotContainsString('|raw', $this->previewTemplate());
        $this->assertStringNotContainsString('|raw', $this->stageTemplate());

        // The panel prints the formatted source one line at a time through
        // `{{ }}`, so Twig's autoescaping is what turns it into text —
        // MenuBuilderPreviewRenderTest proves that behaviourally.
        $this->assertStringContainsString('previewService.formatMarkup(previewMarkup|trim)', $this->previewTemplate());
        $this->assertStringContainsString('{{ line }}', $this->previewTemplate());
    }

    /**
     * The visual preview is the point of the screen; the markup panel is a
     * secondary inspection tool. If the source ever came first again, the
     * screen would be a debug view with a preview attached.
     */
    public function testTheVisualStageComesBeforeTheMarkupInspector(): void
    {
        $template = $this->previewTemplate();

        $stage = strpos($template, 'menu-builder/preview/_stage');
        $controls = strpos($template, 'class="menu-builder-preview-controls"');
        $markup = strpos($template, 'menu-builder-preview-code');

        $this->assertNotFalse($controls);
        $this->assertNotFalse($stage);
        $this->assertNotFalse($markup);
        $this->assertGreaterThan($controls, $stage, 'The controls come first, then the preview they drive.');
        $this->assertGreaterThan($stage, $markup, 'The markup inspector is last.');
    }

    /**
     * The stage is chrome. The moment it starts reading nodes it becomes a
     * second renderer, and the preview stops being evidence of anything.
     */
    public function testTheStageAddsChromeWithoutInspectingNodes(): void
    {
        // Comments stripped: they *describe* the node contract at length,
        // which is the opposite of using it.
        $stage = (string)preg_replace('/\{#.*?#\}/s', '', $this->stageTemplate());

        foreach (['node.', 'nodes', 'megaMenuColumns', 'isActive', 'iconAsset', 'hasBadge', 'children'] as $needle) {
            $this->assertStringNotContainsString($needle, $stage, "The stage must not reason about menu data: found `$needle`.");
        }
    }

    /**
     * The preview interaction layer demonstrates behaviour; it must never
     * become a second source of navigation truth, and it must not talk to
     * the server at all.
     */
    public function testThePreviewScriptNeitherResolvesNavigationNorSendsRequests(): void
    {
        $script = (string)file_get_contents(dirname(__DIR__, 2) . '/src/web/assets/cp/js/preview.js');

        foreach (['sendActionRequest', 'fetch(', 'XMLHttpRequest', '$.ajax', '.post(', 'innerHTML'] as $needle) {
            $this->assertStringNotContainsString($needle, $script, "preview.js must not use `$needle`.");
        }

        $this->assertStringContainsString('preventDefault', $script, 'A preview link must not navigate the editor away.');
        $this->assertStringContainsString('aria-expanded', $script, 'Disclosure state is expressed as the attribute the CSS keys off.');
    }

    /** Pointer, keyboard and accessibility preferences all have an explicit preview path. */
    public function testTheIllustrativePreviewIncludesEveryInteractionMode(): void
    {
        $root = dirname(__DIR__, 2);
        $script = (string)file_get_contents($root . '/src/web/assets/cp/js/preview.js');
        $styles = (string)file_get_contents($root . '/src/web/assets/cp/menu-builder-cp.css');

        $this->assertStringContainsString("'mouseenter'", $script, 'Desktop menu panels open from pointer hover.');
        $this->assertStringContainsString(".menu-builder-preview-siteheader li:has(> details)", $script, 'Hover disclosure is scoped to the header, never the footer.');
        $this->assertStringContainsString(".menu-builder-preview-siteheader li:has(> ul)", $script, 'Plain submenu interaction is scoped to the header, never the footer.');
        $this->assertStringContainsString('}, 140)', $script, 'Pointer travel into a wide mega panel has a short close grace period.');
        $this->assertStringContainsString("'focusin'", $script, 'Compact submenus open when reached from the keyboard.');
        $this->assertStringContainsString("event.key !== 'Escape'", $script, 'Open panels can be dismissed from the keyboard.');
        $this->assertStringContainsString('nav.hidden = !open', $script, 'The mobile disclosure changes the navigation visibility.');
        $this->assertStringContainsString('scrim.hidden = !open', $script, 'The rest of the compact page is covered only while navigation is open.');
        $this->assertStringContainsString('burger.focus()', $script, 'Closing the compact menu returns focus to its control.');
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
        $this->assertStringContainsString('@media (forced-colors: active)', $styles);
        $this->assertStringContainsString('grid-column: 1 / -1', $styles, 'A desktop footer mega group spans the full navigation grid instead of collapsing vertically.');
        $this->assertStringContainsString('grid-template-columns: minmax(170px, 0.72fr) minmax(0, 2.2fr) minmax(130px, 0.55fr)', $styles, 'The reference-inspired footer has brand, links and social columns.');
        $this->assertStringContainsString('linear-gradient(145deg, #080d1b, #111a31 55%, #0b1328)', $styles, 'The redesigned footer retains the original dark background treatment.');
        $this->assertStringContainsString('grid-template-columns: repeat(auto-fit, minmax(135px, 1fr))', $styles, 'Real menu groups remain responsive horizontal columns.');
    }

    // ---------------------------------------------------------------------
    // Regression: the front-end API is untouched
    // ---------------------------------------------------------------------

    /**
     * `craft.menuBuilder.get()` is the documented front-end entry point. The
     * preview added an argument to the *resolver*, deliberately not to this.
     */
    public function testTheTwigApiIsUnchangedByPreview(): void
    {
        $parameters = (new ReflectionMethod(MenuBuilderVariable::class, 'get'))->getParameters();

        $this->assertCount(2, $parameters, 'craft.menuBuilder.get(handle, currentUri) takes no audience argument.');
        $this->assertSame('groupHandle', $parameters[0]->getName());
        $this->assertSame('currentUri', $parameters[1]->getName());

        $source = $this->sourceOf(MenuBuilderVariable::class);

        $this->assertStringContainsString('resolver->getTree($groupHandle, $currentUri)', $source, 'A front-end render still asks for the current request\'s audience.');
    }

    /** Read-only screen: the controls are a GET form, so there is no mutation to protect. */
    public function testThePreviewControlsAreAReadOnlyGetForm(): void
    {
        $template = $this->previewTemplate();

        $this->assertStringContainsString('method="get"', $template);
        $this->assertStringNotContainsString('method="post"', $template);
        $this->assertStringNotContainsString('csrfInput()', $template);
        $this->assertStringNotContainsString('name="action"', $template);
    }

    private function previewTemplate(): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/src/templates/preview/index.twig');
    }

    private function stageTemplate(): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/src/templates/preview/_stage.twig');
    }

    private function sourceOf(string $class): string
    {
        return (string)file_get_contents((string)(new ReflectionClass($class))->getFileName());
    }

    /**
     * @param MenuBuilderNode[] $children
     */
    private function node(int $id, array $children = [], bool $isDynamic = false): MenuBuilderNode
    {
        $node = new MenuBuilderNode(
            id: $id,
            handle: null,
            type: 'url',
            title: "Item $id",
            url: '/item-' . $id,
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
            isDynamic: $isDynamic,
        );
        $node->children = $children;

        return $node;
    }
}
