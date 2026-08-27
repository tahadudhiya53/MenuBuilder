<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use DateTime;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tahadudhiya\MenuBuilder\controllers\ItemsController;
use Tahadudhiya\MenuBuilder\events\RegisterVisibilityRulesEvent;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use Tahadudhiya\MenuBuilder\services\MenuBuilderResolver;
use Tahadudhiya\MenuBuilder\services\MenuBuilderVisibilityService;
use Tahadudhiya\MenuBuilder\visibility\AlwaysRule;
use Tahadudhiya\MenuBuilder\visibility\DateRangeRule;
use Tahadudhiya\MenuBuilder\visibility\EnvironmentRule;
use Tahadudhiya\MenuBuilder\visibility\LoggedInRule;
use Tahadudhiya\MenuBuilder\visibility\LoggedOutRule;
use Tahadudhiya\MenuBuilder\visibility\SiteRule;
use Tahadudhiya\MenuBuilder\visibility\UserGroupRule;
use Tahadudhiya\MenuBuilder\visibility\VisibilityContext;
use Tahadudhiya\MenuBuilder\visibility\VisibilityRuleInterface;

/**
 * Visibility end to end: each built-in rule, the service that ANDs an item's
 * rules together, the edit form's translation of its fields into persisted
 * rule configs, and the cache boundary that keeps the whole decision
 * per-request.
 *
 * Every rule fails closed — a missing, empty or malformed config hides the
 * item instead of exposing it, and no rule may throw, since a malformed
 * import would otherwise become a 500 or a leaked item. The rules themselves
 * carry no user identity, which is what makes it safe for one cached tree to
 * be shared between an anonymous visitor and a logged-in one: link resolution
 * is cached per group/site, visibility is applied to that cached tree on
 * every render.
 *
 * MenuBuilderResolver::filterVisible() and ItemsController::buildVisibilityRules()
 * need no booted Craft app but are non-public, so they are invoked directly
 * through reflection; the public getTree() around them needs a database and is
 * covered by the manual testing checklist.
 */
class MenuBuilderVisibilityTest extends TestCase
{
    private function context(
        bool $isLoggedIn = false,
        array $userGroupIds = [],
        ?int $currentSiteId = 1,
        ?string $environment = 'production',
        string $now = 'now',
    ): VisibilityContext {
        $timezone = new \DateTimeZone('UTC');

        return new VisibilityContext(
            isLoggedIn: $isLoggedIn,
            userGroupIds: $userGroupIds,
            currentSiteId: $currentSiteId,
            now: new DateTime($now, $timezone),
            environment: $environment,
            timezone: $timezone,
        );
    }

    // ---------------------------------------------------------------------
    // The individual rules — each one fails closed
    // ---------------------------------------------------------------------

    public function testAlwaysRule(): void
    {
        $rule = new AlwaysRule();

        $this->assertTrue($rule->passes([], $this->context(isLoggedIn: false)));
        $this->assertTrue($rule->passes([], $this->context(isLoggedIn: true)));
    }

    public function testLoggedInRule(): void
    {
        $rule = new LoggedInRule();

        $this->assertTrue($rule->passes([], $this->context(isLoggedIn: true)));
        $this->assertFalse($rule->passes([], $this->context(isLoggedIn: false)));
    }

    public function testLoggedOutRule(): void
    {
        $rule = new LoggedOutRule();

        $this->assertFalse($rule->passes([], $this->context(isLoggedIn: true)));
        $this->assertTrue($rule->passes([], $this->context(isLoggedIn: false)));
    }

    public function testUserGroupRule(): void
    {
        $rule = new UserGroupRule();

        $this->assertFalse($rule->passes(['groupIds' => [1]], $this->context(isLoggedIn: false)), 'Anonymous visitors never match a group rule.');
        $this->assertTrue($rule->passes(['groupIds' => [1, 2]], $this->context(isLoggedIn: true, userGroupIds: [2, 9])));
        $this->assertFalse($rule->passes(['groupIds' => [1, 2]], $this->context(isLoggedIn: true, userGroupIds: [9])));
        $this->assertFalse($rule->passes(['groupIds' => [1]], $this->context(isLoggedIn: true, userGroupIds: [])), 'A logged-in user in no groups matches no group rule.');
    }

    /**
     * A group rule with nothing to match on is missing configuration, not
     * "any logged-in user" (that is what the loggedIn rule is for), so it
     * hides the item rather than exposing it to every authenticated visitor.
     */
    public function testUserGroupRuleFailsClosedOnMissingOrMalformedGroupIds(): void
    {
        $rule = new UserGroupRule();
        $context = $this->context(isLoggedIn: true, userGroupIds: [1, 5]);

        $this->assertFalse($rule->passes([], $context), 'Absent groupIds.');
        $this->assertFalse($rule->passes(['groupIds' => []], $context), 'Empty groupIds.');
        $this->assertFalse($rule->passes(['groupIds' => 'not-an-array'], $context));
        $this->assertFalse($rule->passes(['groupIds' => ['1' => 5]], $context), 'A non-list (keyed) array is malformed.');
        $this->assertFalse($rule->passes(['groupIds' => [true]], $context), 'A bool must not be intval-ed into group ID 1.');
        $this->assertFalse($rule->passes(['groupIds' => [1.0]], $context), 'A float is not an ID.');
        $this->assertFalse($rule->passes(['groupIds' => [null]], $context));
        $this->assertFalse($rule->passes(['groupIds' => ['5abc']], $context));
        $this->assertFalse($rule->passes(['groupIds' => [0]], $context));
        $this->assertFalse($rule->passes(['groupIds' => [-1]], $context));
        $this->assertFalse($rule->passes(['groupIds' => [['nested']]], $context));
    }

    public function testUserGroupRuleAcceptsDigitStringIds(): void
    {
        $rule = new UserGroupRule();

        $this->assertTrue($rule->passes(['groupIds' => ['5']], $this->context(isLoggedIn: true, userGroupIds: [5])), 'A JSON round-tripped/posted "5" is still group 5.');
    }

    public function testSiteRule(): void
    {
        $rule = new SiteRule();

        $this->assertTrue($rule->passes(['siteIds' => [1, 2]], $this->context(currentSiteId: 1)));
        $this->assertTrue($rule->passes(['siteIds' => [1, 2]], $this->context(currentSiteId: 2)), 'A second site in the list still matches.');
        $this->assertFalse($rule->passes(['siteIds' => [2, 3]], $this->context(currentSiteId: 1)));
    }

    /**
     * Same reasoning as the group rule: a site rule exists only to restrict,
     * so an empty/malformed list — or a request with no resolvable current
     * site (console, pre-install) — hides rather than shows.
     */
    public function testSiteRuleFailsClosedOnMissingConfigurationOrUnknownSite(): void
    {
        $rule = new SiteRule();

        $this->assertFalse($rule->passes([], $this->context(currentSiteId: 1)), 'Absent siteIds.');
        $this->assertFalse($rule->passes(['siteIds' => []], $this->context(currentSiteId: 1)), 'Empty siteIds.');
        $this->assertFalse($rule->passes(['siteIds' => 'not-an-array'], $this->context(currentSiteId: 1)));
        $this->assertFalse($rule->passes(['siteIds' => [true]], $this->context(currentSiteId: 1)), 'A bool must not be intval-ed into site ID 1.');
        $this->assertFalse($rule->passes(['siteIds' => [1, 2]], $this->context(currentSiteId: null)), 'An unknown current site cannot satisfy a site restriction.');
    }

    public function testDateRangeRule(): void
    {
        $rule = new DateRangeRule();
        $context = $this->context();

        $this->assertFalse($rule->passes([], $context), 'A range with neither bound is missing configuration, not "always visible".');

        $future = (clone $context->now)->modify('+1 day')->format('c');
        $past = (clone $context->now)->modify('-1 day')->format('c');

        $this->assertFalse($rule->passes(['start' => $future], $context));
        $this->assertTrue($rule->passes(['start' => $past], $context));
        $this->assertFalse($rule->passes(['end' => $past], $context));
        $this->assertTrue($rule->passes(['end' => $future], $context));
        $this->assertTrue($rule->passes(['start' => $past, 'end' => $future], $context));
    }

    public function testDateRangeRuleFailsClosedOnInvalidDateString(): void
    {
        $rule = new DateRangeRule();
        $context = $this->context();

        $this->assertFalse($rule->passes(['start' => 'not-a-real-date'], $context), 'An unparseable start should hide the item, not act as unbounded.');
        $this->assertFalse($rule->passes(['end' => 'also-not-a-date'], $context));
    }

    public function testDateRangeRuleFailsClosedWhenStartIsAfterEnd(): void
    {
        $rule = new DateRangeRule();
        $context = $this->context();

        $future = (clone $context->now)->modify('+1 day')->format('c');
        $past = (clone $context->now)->modify('-1 day')->format('c');

        $this->assertFalse($rule->passes(['start' => $future, 'end' => $past], $context), 'A contradictory range (start after end) must never show gated navigation.');
    }

    /**
     * A directly-posted/imported `visibility` array isn't guaranteed to
     * contain strings — `passes()` must fail closed rather than let a
     * TypeError from a string-typed helper escape.
     */
    public function testDateRangeRuleFailsClosedOnNonStringValuesWithoutThrowing(): void
    {
        $rule = new DateRangeRule();
        $context = $this->context();

        foreach ([['start' => ['nested' => 'array']], ['start' => true], ['start' => new \stdClass()], ['end' => ['nested' => 'array']]] as $config) {
            $this->assertFalse($rule->passes($config, $context));
        }
    }

    public function testDateRangeRuleRejectsInvalidCalendarDates(): void
    {
        $rule = new DateRangeRule();
        $context = $this->context();

        $this->assertFalse($rule->passes(['start' => '2026-02-30'], $context), 'February has no 30th — must not silently normalize to March 2.');
        $this->assertFalse($rule->passes(['start' => '2026-13-01'], $context), 'Month 13 does not exist.');
        $this->assertFalse($rule->passes(['end' => '2026-04-31 10:00:00'], $context), 'April has no 31st.');
    }

    public function testDateRangeRuleHonorsExplicitTimezoneOffsetOverContextTimezone(): void
    {
        $context = $this->context();
        $rule = new DateRangeRule();

        // The context is UTC; a start bound 2 hours in the future in UTC
        // terms, expressed with an explicit +05:00 offset, must still be
        // evaluated against its own offset rather than being reinterpreted
        // in the context's timezone.
        $offsetFuture = (clone $context->now)->modify('+2 hours')->setTimezone(new \DateTimeZone('+05:00'))->format('Y-m-d\TH:i:sP');

        $this->assertFalse($rule->passes(['start' => $offsetFuture], $context), 'An explicit offset must be honored, not overridden by the context timezone.');

        $offsetPast = (clone $context->now)->modify('-2 hours')->setTimezone(new \DateTimeZone('-08:00'))->format('Y-m-d\TH:i:sP');

        $this->assertTrue($rule->passes(['start' => $offsetPast], $context));
    }

    public function testDateRangeRuleInterpretsNaiveDateInApplicationTimezoneNotAmbientDefault(): void
    {
        $tz = new \DateTimeZone('Asia/Kolkata');
        $now = new DateTime('2026-06-15 12:00:00', $tz);
        $context = new VisibilityContext(
            isLoggedIn: false,
            userGroupIds: [],
            currentSiteId: 1,
            now: $now,
            environment: 'production',
            timezone: $tz,
        );
        $rule = new DateRangeRule();

        // A naive start one hour in the future, interpreted in the
        // application's (non-UTC) timezone, must still be treated as
        // future — not reinterpreted against PHP's ambient default tz.
        $naiveFutureStart = (clone $now)->modify('+1 hour')->format('Y-m-d H:i:s');

        $this->assertFalse($rule->passes(['start' => $naiveFutureStart], $context));
    }

    /**
     * The three "before / during / after" states of a bounded range, checked
     * against one fixed `now` rather than a moving clock.
     */
    public function testDateRangeRuleAcrossBeforeDuringAndAfterTheWindow(): void
    {
        $rule = new DateRangeRule();
        $tz = new \DateTimeZone('UTC');
        $config = ['start' => '2026-06-10T00:00:00+00:00', 'end' => '2026-06-20T00:00:00+00:00'];

        $at = function(string $when) use ($tz): VisibilityContext {
            return new VisibilityContext(
                isLoggedIn: false,
                userGroupIds: [],
                currentSiteId: 1,
                now: new DateTime($when, $tz),
                environment: 'production',
                timezone: $tz,
            );
        };

        $this->assertFalse($rule->passes($config, $at('2026-06-09 23:59:59')), 'Before the window.');
        $this->assertTrue($rule->passes($config, $at('2026-06-15 12:00:00')), 'During the window.');
        $this->assertFalse($rule->passes($config, $at('2026-06-20 00:00:01')), 'After the window.');
        $this->assertTrue($rule->passes($config, $at('2026-06-10 00:00:00')), 'The start instant itself is inside the window.');
        $this->assertTrue($rule->passes($config, $at('2026-06-20 00:00:00')), 'The end instant itself is inside the window.');
    }

    public function testEnvironmentRule(): void
    {
        $rule = new EnvironmentRule();

        $this->assertTrue($rule->passes(['environments' => ['production', 'staging']], $this->context(environment: 'staging')));
        $this->assertFalse($rule->passes(['environments' => ['production']], $this->context(environment: 'staging')));
        $this->assertTrue($rule->passes(['environments' => [' staging ']], $this->context(environment: 'staging')), 'Entries are trimmed.');
    }

    public function testEnvironmentRuleFailsClosedOnMissingConfigurationOrUnknownEnvironment(): void
    {
        $rule = new EnvironmentRule();

        $this->assertFalse($rule->passes([], $this->context(environment: 'staging')), 'Absent list.');
        $this->assertFalse($rule->passes(['environments' => []], $this->context(environment: 'staging')), 'Empty list.');
        $this->assertFalse($rule->passes(['environments' => 'production'], $this->context(environment: 'production')), 'A bare string is not a list.');
        $this->assertFalse($rule->passes(['environments' => ['']], $this->context(environment: 'production')));
        $this->assertFalse($rule->passes(['environments' => [123]], $this->context(environment: 'production')));
        $this->assertFalse($rule->passes(['environments' => ['prod' => 'production']], $this->context(environment: 'production')), 'A keyed array is malformed.');
        $this->assertFalse($rule->passes(['environments' => ['staging']], $this->context(environment: null)), 'An unidentifiable environment cannot satisfy an environment restriction.');
    }

    /**
     * Every built-in rule is handed config shapes it was never designed for.
     * None may throw — MenuBuilderVisibilityService catches as a backstop,
     * but a rule that fails closed on its own is what keeps a malformed
     * import from turning into a 500 or an exposed item.
     */
    public function testNoBuiltInRuleThrowsOnMalformedConfig(): void
    {
        $rules = [
            new AlwaysRule(), new LoggedInRule(), new LoggedOutRule(),
            new UserGroupRule(), new SiteRule(), new DateRangeRule(), new EnvironmentRule(),
        ];

        $malformed = [
            ['groupIds' => 'x'], ['siteIds' => 'x'], ['environments' => 'x'],
            ['groupIds' => new \stdClass()], ['siteIds' => true], ['environments' => 42],
            ['start' => []], ['end' => false],
            ['groupIds' => null, 'siteIds' => null, 'environments' => null, 'start' => null, 'end' => null],
        ];

        foreach ($rules as $rule) {
            foreach ($malformed as $config) {
                $result = $rule->passes($config, $this->context(isLoggedIn: true, userGroupIds: [1]));
                $this->assertIsBool($result, $rule::class . ' must return a bool, not throw.');
            }
        }
    }

    // ---------------------------------------------------------------------
    // CP form fields to persisted rule configs
    // ---------------------------------------------------------------------

    private function build(array $posted): array
    {
        $controller = (new ReflectionClass(ItemsController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ItemsController::class, 'buildVisibilityRules');

        return $method->invoke($controller, $posted);
    }

    private function assertPersistable(array $rules): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->title = 'Item';
        $item->customUrl = 'https://example.test';
        $item->visibility = $rules;

        $this->assertTrue($item->validate(), json_encode($item->getErrors()));
    }

    public function testAnUntouchedFormProducesNoRules(): void
    {
        $this->assertSame([], $this->build([]));
        $this->assertPersistable([]);
    }

    /**
     * The shapes an unchecked checkbox-select actually posts (a zero-value
     * padding field, or a bare string when nothing is checked) must not
     * become an empty — and now fail-closed — restriction rule.
     */
    public function testEmptyOrPaddedSelectionsProduceNoRuleRatherThanAnEmptyOne(): void
    {
        foreach ([
            ['userGroups' => [''], 'sites' => ['']],
            ['userGroups' => ['0'], 'sites' => ['0']],
            ['userGroups' => '', 'sites' => ''],
            ['userGroups' => [], 'sites' => []],
            ['environments' => ''],
            ['environments' => ' , , '],
            ['dateStart' => '', 'dateEnd' => ''],
        ] as $posted) {
            $rules = $this->build($posted);

            $this->assertSame([], $rules, 'Untouched fields must not emit a rule: ' . json_encode($posted));
            $this->assertPersistable($rules);
        }
    }

    public function testEveryFilledFieldBecomesItsRuleAndValidates(): void
    {
        $rules = $this->build([
            'loggedIn' => '1',
            'userGroups' => ['2', '3'],
            'sites' => ['1'],
            'dateStart' => '2026-06-10T00:00',
            'dateEnd' => '2026-06-20T00:00',
            'environments' => 'production, staging',
        ]);

        $this->assertSame([
            ['type' => 'loggedIn'],
            ['type' => 'userGroup', 'groupIds' => [2, 3]],
            ['type' => 'site', 'siteIds' => [1]],
            ['type' => 'dateRange', 'start' => '2026-06-10T00:00', 'end' => '2026-06-20T00:00'],
            ['type' => 'environment', 'environments' => ['production', 'staging']],
        ], $rules);

        $this->assertPersistable($rules);
    }

    public function testOnlyOneDateBoundIsEnough(): void
    {
        $this->assertSame([['type' => 'dateRange', 'start' => '2026-06-10T00:00']], $this->build(['dateStart' => '2026-06-10T00:00']));
        $this->assertSame([['type' => 'dateRange', 'end' => '2026-06-20T00:00']], $this->build(['dateEnd' => '2026-06-20T00:00']));
    }

    /**
     * A tampered post can send an array where the form sends a string.
     * That must not reach `explode()` as a TypeError, and must not put a
     * non-scalar into a persisted rule config.
     */
    public function testNonScalarPostedValuesAreIgnoredRatherThanCrashing(): void
    {
        $rules = $this->build([
            'dateStart' => ['tampered'],
            'dateEnd' => ['tampered'],
            'environments' => ['tampered'],
            'userGroups' => 'not-an-array',
            'sites' => ['not-an-id'],
        ]);

        $this->assertSame([], $rules);
    }

    // ---------------------------------------------------------------------
    // The service: an item's rules combined with AND
    // ---------------------------------------------------------------------

    public function testNoRulesIsAlwaysVisible(): void
    {
        $service = new MenuBuilderVisibilityService();

        $this->assertTrue($service->isVisible($this->item(1, []), $this->context(isLoggedIn: true)));
    }

    public function testAllRulesMustPass(): void
    {
        $service = new MenuBuilderVisibilityService();

        $item = $this->item(1, [
            ['type' => 'loggedIn'],
            ['type' => 'userGroup', 'groupIds' => [5]],
        ]);

        $this->assertTrue($service->isVisible($item, $this->context(isLoggedIn: true, userGroupIds: [5])), 'Both rules pass.');
        $this->assertFalse($service->isVisible($item, $this->context(isLoggedIn: true, userGroupIds: [9])), 'userGroup rule fails, so the whole item is hidden.');
        $this->assertFalse($service->isVisible($item, $this->context(isLoggedIn: false)), 'loggedIn rule fails, so the whole item is hidden.');
    }

    public function testUnknownRuleTypeFailsClosed(): void
    {
        $service = new MenuBuilderVisibilityService();

        $item = $this->item(1, [
            ['type' => 'always'],
            ['type' => 'somethingThatWasNeverRegistered'],
        ]);

        $this->assertFalse($service->isVisible($item, $this->context(isLoggedIn: true)), 'An unrecognized rule type must hide the item rather than being ignored.');
    }

    public function testMalformedRuleWithoutTypeFailsClosed(): void
    {
        $service = new MenuBuilderVisibilityService();

        $item = $this->item(1, [
            ['groupIds' => [1, 2]], // missing "type"
        ]);

        $this->assertFalse($service->isVisible($item, $this->context(isLoggedIn: true)));
    }

    /**
     * The common rule pairings, each checked in both directions: both rules
     * satisfied is the only visible case, and either one failing hides the
     * item (AND, never OR).
     */
    public function testLoggedInAndUserGroupCombination(): void
    {
        $service = new MenuBuilderVisibilityService();
        $item = $this->item(1, [
            ['type' => 'loggedIn'],
            ['type' => 'userGroup', 'groupIds' => [3, 4]],
        ]);

        $this->assertTrue($service->isVisible($item, $this->context(isLoggedIn: true, userGroupIds: [4])));
        $this->assertFalse($service->isVisible($item, $this->context(isLoggedIn: true, userGroupIds: [7])), 'Right group missing.');
        $this->assertFalse($service->isVisible($item, $this->context(isLoggedIn: false)), 'Anonymous fails both halves.');
    }

    public function testLoggedOutAndSiteCombination(): void
    {
        $service = new MenuBuilderVisibilityService();
        $item = $this->item(1, [
            ['type' => 'loggedOut'],
            ['type' => 'site', 'siteIds' => [2]],
        ]);

        $this->assertTrue($service->isVisible($item, $this->context(isLoggedIn: false, currentSiteId: 2)));
        $this->assertFalse($service->isVisible($item, $this->context(isLoggedIn: false, currentSiteId: 1)), 'Wrong site.');
        $this->assertFalse($service->isVisible($item, $this->context(isLoggedIn: true, currentSiteId: 2)), 'Authenticated visitor on the right site still fails loggedOut.');
    }

    public function testUserGroupAndDateRangeCombination(): void
    {
        $service = new MenuBuilderVisibilityService();
        $item = $this->item(1, [
            ['type' => 'userGroup', 'groupIds' => [3]],
            ['type' => 'dateRange', 'start' => '2026-06-10T00:00:00+00:00', 'end' => '2026-06-20T00:00:00+00:00'],
        ]);

        $inGroup = fn(string $now) => $this->context(isLoggedIn: true, userGroupIds: [3], now: $now);

        $this->assertTrue($service->isVisible($item, $inGroup('2026-06-15 12:00:00')), 'In the group, inside the window.');
        $this->assertFalse($service->isVisible($item, $inGroup('2026-06-01 12:00:00')), 'In the group, before the window.');
        $this->assertFalse($service->isVisible($item, $inGroup('2026-07-01 12:00:00')), 'In the group, after the window.');
        $this->assertFalse(
            $service->isVisible($item, $this->context(isLoggedIn: true, userGroupIds: [9], now: '2026-06-15 12:00:00')),
            'Inside the window but in the wrong group.'
        );
    }

    public function testEnvironmentAndLoggedInCombination(): void
    {
        $service = new MenuBuilderVisibilityService();
        $item = $this->item(1, [
            ['type' => 'environment', 'environments' => ['staging']],
            ['type' => 'loggedIn'],
        ]);

        $this->assertTrue($service->isVisible($item, $this->context(isLoggedIn: true, environment: 'staging')));
        $this->assertFalse($service->isVisible($item, $this->context(isLoggedIn: true, environment: 'production')), 'Wrong environment.');
        $this->assertFalse($service->isVisible($item, $this->context(isLoggedIn: false, environment: 'staging')), 'Right environment, anonymous visitor.');
        $this->assertFalse($service->isVisible($item, $this->context(isLoggedIn: true, environment: null)), 'Unidentifiable environment.');
    }

    /**
     * `loggedIn` AND `loggedOut` on one item is unsatisfiable by definition.
     * The CP lets both boxes be ticked, so this pins the consequence: the
     * item disappears for everyone rather than showing for anyone.
     */
    public function testContradictoryLoggedInAndLoggedOutHidesForEveryone(): void
    {
        $service = new MenuBuilderVisibilityService();
        $item = $this->item(1, [['type' => 'loggedIn'], ['type' => 'loggedOut']]);

        $this->assertFalse($service->isVisible($item, $this->context(isLoggedIn: true)));
        $this->assertFalse($service->isVisible($item, $this->context(isLoggedIn: false)));
    }

    /**
     * A passing rule sitting *after* a malformed one must not rescue the
     * item — the loop has to reject on the malformed entry regardless of
     * where it sits in the array.
     */
    public function testMalformedRuleAmongPassingRulesStillHides(): void
    {
        $service = new MenuBuilderVisibilityService();

        $configs = [
            [['type' => 'always'], 'not-an-array-at-all', ['type' => 'always']],
            [['type' => 'always'], ['type' => ['nested']], ['type' => 'always']],
            [['type' => 'always'], ['type' => 42]],
            [['type' => 'always'], ['type' => '']],
            [['type' => 'always'], ['type' => null]],
            [['type' => 'always'], []],
            [['type' => 'always'], 42],
            [['type' => 'always'], null],
        ];

        foreach ($configs as $i => $visibility) {
            $this->assertFalse(
                $service->isVisible($this->item(1, $visibility), $this->context(isLoggedIn: true)),
                "Malformed visibility bag #{$i} must fail closed without throwing."
            );
        }
    }

    /**
     * A third-party rule registered through EVENT_REGISTER_VISIBILITY_RULES
     * is outside this plugin's control; if it throws, the item hides and the
     * page still renders.
     */
    public function testThrowingRuleFailsClosedInsteadOfEscaping(): void
    {
        $service = new MenuBuilderVisibilityService();
        $service->on(MenuBuilderVisibilityService::EVENT_REGISTER_VISIBILITY_RULES, function(RegisterVisibilityRulesEvent $event) {
            $event->rules['explodes'] = new class() implements VisibilityRuleInterface {
                public function passes(array $config, VisibilityContext $context): bool
                {
                    throw new \RuntimeException('boom');
                }
            };
        });

        $this->assertFalse($service->isVisible($this->item(1, [['type' => 'explodes']]), $this->context(isLoggedIn: true)));
    }

    /** A registered third-party rule that passes is still honored. */
    public function testRegisteredThirdPartyRuleParticipatesInTheAnd(): void
    {
        $service = new MenuBuilderVisibilityService();
        $service->on(MenuBuilderVisibilityService::EVENT_REGISTER_VISIBILITY_RULES, function(RegisterVisibilityRulesEvent $event) {
            $event->rules['alwaysTrue'] = new class() implements VisibilityRuleInterface {
                public function passes(array $config, VisibilityContext $context): bool
                {
                    return true;
                }
            };
        });

        $this->assertTrue($service->isVisible($this->item(1, [['type' => 'alwaysTrue'], ['type' => 'always']]), $this->context(isLoggedIn: true)));
        $this->assertFalse($service->isVisible($this->item(1, [['type' => 'alwaysTrue'], ['type' => 'loggedOut']]), $this->context(isLoggedIn: true)));
    }

    /**
     * The `visibility` bag holds no user identity of its own — the same item
     * evaluated against two contexts must give two answers, which is why the
     * decision can never be baked into the shared per-group cache
     * (MenuBuilderCacheService / MenuBuilderResolver::filterVisible()).
     */
    public function testSameItemYieldsDifferentAnswersPerContext(): void
    {
        $service = new MenuBuilderVisibilityService();
        $item = $this->item(1, [['type' => 'userGroup', 'groupIds' => [3]]]);

        $this->assertTrue($service->isVisible($item, $this->context(isLoggedIn: true, userGroupIds: [3])));
        $this->assertFalse($service->isVisible($item, $this->context(isLoggedIn: true, userGroupIds: [4])));
        $this->assertFalse($service->isVisible($item, $this->context(isLoggedIn: false)));
        $this->assertSame([['type' => 'userGroup', 'groupIds' => [3]]], $item->visibility, 'Evaluation must not mutate the item.');
    }

    // ---------------------------------------------------------------------
    // The cache boundary: visibility is applied per request, never cached
    // ---------------------------------------------------------------------

    private function node(int $id, bool $isDynamic = false, array $children = []): MenuBuilderNode
    {
        $node = new MenuBuilderNode(
            id: $id,
            handle: null,
            type: $isDynamic ? MenuBuilderItem::TYPE_DYNAMIC : MenuBuilderItem::TYPE_URL,
            title: 'Node ' . $id,
            url: '/' . $id,
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

    private function item(int $id = 1, array $visibility = []): MenuBuilderItem
    {
        $item = new MenuBuilderItem();
        $item->id = $id;
        $item->visibility = $visibility;

        return $item;
    }

    /**
     * @param MenuBuilderNode[] $nodes
     * @param array<int,MenuBuilderItem> $itemsById
     * @return MenuBuilderNode[]
     */
    private function filter(array $nodes, array $itemsById, VisibilityContext $context): array
    {
        // No setAccessible(): private methods have been invokable through
        // ReflectionMethod without it since PHP 8.1, and the call is
        // deprecated as of 8.5.
        $method = new ReflectionMethod(MenuBuilderResolver::class, 'filterVisible');

        return $method->invoke(new MenuBuilderResolver(), $nodes, $itemsById, new MenuBuilderVisibilityService(), $context);
    }

    /** @param MenuBuilderNode[] $nodes */
    private function ids(array $nodes): array
    {
        return array_map(fn(MenuBuilderNode $node) => $node->id, $nodes);
    }

    public function testHiddenItemsAreDroppedFromTheRenderedTreeOnly(): void
    {
        $cached = [$this->node(1), $this->node(2), $this->node(3)];
        $itemsById = [
            1 => $this->item(1),
            2 => $this->item(2, [['type' => 'loggedIn']]),
            3 => $this->item(3, [['type' => 'loggedOut']]),
        ];

        $anonymous = $this->filter($cached, $itemsById, $this->context(isLoggedIn: false));
        $this->assertSame([1, 3], $this->ids($anonymous));

        $authenticated = $this->filter($cached, $itemsById, $this->context(isLoggedIn: true));
        $this->assertSame([1, 2], $this->ids($authenticated));

        // Same cached input, two different results, and the input itself is
        // untouched — this is the guarantee that makes it safe to share one
        // cache entry between an anonymous visitor and a logged-in one.
        $this->assertSame([1, 2, 3], $this->ids($cached), 'Filtering must not mutate the cached tree.');
    }

    public function testHidingAParentAlsoRemovesItsVisibleChildren(): void
    {
        $cached = [$this->node(1, children: [$this->node(2), $this->node(3)])];
        $itemsById = [
            1 => $this->item(1, [['type' => 'userGroup', 'groupIds' => [4]]]),
            2 => $this->item(2),
            3 => $this->item(3),
        ];

        $this->assertSame([], $this->filter($cached, $itemsById, $this->context(isLoggedIn: true, userGroupIds: [9])));
        $this->assertCount(2, $cached[0]->children, 'The cached parent keeps its children.');

        $visible = $this->filter($cached, $itemsById, $this->context(isLoggedIn: true, userGroupIds: [4]));
        $this->assertSame([1], $this->ids($visible));
        $this->assertSame([2, 3], $this->ids($visible[0]->children));
    }

    public function testAHiddenChildIsDroppedWhileItsParentStays(): void
    {
        $cached = [$this->node(1, children: [$this->node(2), $this->node(3)])];
        $itemsById = [
            1 => $this->item(1),
            2 => $this->item(2, [['type' => 'loggedIn']]),
            3 => $this->item(3),
        ];

        $visible = $this->filter($cached, $itemsById, $this->context(isLoggedIn: false));

        $this->assertSame([1], $this->ids($visible));
        $this->assertSame([3], $this->ids($visible[0]->children));
    }

    /**
     * A cached node whose persisted item is no longer in the fresh read —
     * deleted, or disabled since the tree was cached — has no visibility
     * rules left to evaluate, so it is hidden rather than passed through
     * unchecked. Invalidation should prevent this; this is the backstop.
     */
    public function testAnOrphanedCachedNodeFailsClosed(): void
    {
        $cached = [$this->node(1), $this->node(99)];
        $itemsById = [1 => $this->item(1)];

        $this->assertSame([1], $this->ids($this->filter($cached, $itemsById, $this->context())));
    }

    /**
     * A dynamic-navigation child's `id` is a Craft element ID, not an item
     * ID, so it must not be looked up in the item map — a numeric collision
     * would otherwise apply an unrelated item's visibility rules to it (or,
     * with the orphan check above, hide it outright).
     */
    public function testDynamicChildrenAreNotMatchedAgainstItemIds(): void
    {
        $cached = [$this->node(1, children: [$this->node(2, isDynamic: true)])];
        $itemsById = [
            1 => $this->item(1),
            // Same numeric ID as the synthesized child, gated to a group the
            // visitor isn't in.
            2 => $this->item(2, [['type' => 'userGroup', 'groupIds' => [4]]]),
        ];

        $visible = $this->filter($cached, $itemsById, $this->context(isLoggedIn: true, userGroupIds: [9]));

        $this->assertSame([1], $this->ids($visible));
        $this->assertSame([2], $this->ids($visible[0]->children), 'The synthesized child keeps its own identity.');
    }

    /**
     * Structural guard on the pipeline order, which no unit test can observe
     * without a database: the method that produces the *cached* payload
     * (buildResolvedNodes, passed to MenuBuilderCacheService::getOrSet) must
     * not touch visibility at all, and getTree() must filter after reading
     * the cache. Asserted against the source because getting this order
     * wrong is a security bug — a user-specific decision persisted into a
     * shared cache entry — not a behavioural nuance.
     */
    public function testTheCachedPayloadIsBuiltWithoutAnyVisibilityDecision(): void
    {
        $source = file_get_contents((new ReflectionClass(MenuBuilderResolver::class))->getFileName());

        $start = strpos($source, 'private function buildResolvedNodes');
        $end = strpos($source, 'private function convert');
        $body = substr($source, $start, $end - $start);

        $this->assertStringNotContainsStringIgnoringCase('visib', $body, 'buildResolvedNodes() feeds the shared cache and must make no visibility decision.');
    }

    public function testGetTreeFiltersVisibilityAfterReadingTheCache(): void
    {
        $source = file_get_contents((new ReflectionClass(MenuBuilderResolver::class))->getFileName());

        $start = strpos($source, 'public function getTree');
        $end = strpos($source, 'private function buildResolvedNodes');
        $body = substr($source, $start, $end - $start);

        $cacheCall = strpos($body, 'cache->getOrSet');
        $filterCall = strpos($body, '$this->filterVisible(');
        $activeCall = strpos($body, 'activeResolver->mark');

        $this->assertNotFalse($cacheCall, 'getTree() reads the resolved tree from the cache.');
        $this->assertNotFalse($filterCall);
        $this->assertNotFalse($activeCall);
        $this->assertGreaterThan($cacheCall, $filterCall, 'Visibility must be filtered after the cached link resolution, on the cached result.');
        $this->assertGreaterThan($filterCall, $activeCall, 'Active state is marked on the already-filtered tree.');
    }

    /**
     * The cached payload is MenuBuilderNode; visibility config lives only on
     * MenuBuilderItem. If a `visibility` property ever appeared on the node,
     * a user-independent cache entry would start carrying access rules.
     */
    public function testCachedNodesCarryNoVisibilityData(): void
    {
        $properties = array_map(
            fn(\ReflectionProperty $property) => strtolower($property->getName()),
            (new ReflectionClass(MenuBuilderNode::class))->getProperties()
        );

        foreach ($properties as $name) {
            $this->assertStringNotContainsString('visib', $name);
        }
    }
}
