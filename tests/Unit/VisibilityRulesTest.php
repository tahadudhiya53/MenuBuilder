<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use DateTime;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\visibility\AlwaysRule;
use Tahadudhiya\MenuBuilder\visibility\DateRangeRule;
use Tahadudhiya\MenuBuilder\visibility\EnvironmentRule;
use Tahadudhiya\MenuBuilder\visibility\LoggedInRule;
use Tahadudhiya\MenuBuilder\visibility\LoggedOutRule;
use Tahadudhiya\MenuBuilder\visibility\SiteRule;
use Tahadudhiya\MenuBuilder\visibility\UserGroupRule;
use Tahadudhiya\MenuBuilder\visibility\VisibilityContext;

class VisibilityRulesTest extends TestCase
{
    private function context(
        bool $isLoggedIn = false,
        array $userGroupIds = [],
        ?int $currentSiteId = 1,
        ?string $environment = 'production',
    ): VisibilityContext {
        $timezone = new \DateTimeZone('UTC');

        return new VisibilityContext(
            isLoggedIn: $isLoggedIn,
            userGroupIds: $userGroupIds,
            currentSiteId: $currentSiteId,
            now: new DateTime('now', $timezone),
            environment: $environment,
            timezone: $timezone,
        );
    }

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
        $this->assertTrue($rule->passes(['groupIds' => []], $this->context(isLoggedIn: true, userGroupIds: [5])), 'Empty groupIds always passes for a logged-in user.');
        $this->assertTrue($rule->passes(['groupIds' => [1, 2]], $this->context(isLoggedIn: true, userGroupIds: [2, 9])));
        $this->assertFalse($rule->passes(['groupIds' => [1, 2]], $this->context(isLoggedIn: true, userGroupIds: [9])));
    }

    public function testSiteRule(): void
    {
        $rule = new SiteRule();

        $this->assertTrue($rule->passes([], $this->context(currentSiteId: 1)), 'Empty siteIds always passes.');
        $this->assertTrue($rule->passes(['siteIds' => [1, 2]], $this->context(currentSiteId: 1)));
        $this->assertFalse($rule->passes(['siteIds' => [2, 3]], $this->context(currentSiteId: 1)));
    }

    public function testDateRangeRule(): void
    {
        $rule = new DateRangeRule();
        $context = $this->context();

        $this->assertTrue($rule->passes([], $context), 'No bounds always passes.');

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

    public function testEnvironmentRule(): void
    {
        $rule = new EnvironmentRule();

        $this->assertTrue($rule->passes([], $this->context(environment: 'staging')), 'Empty list always passes.');
        $this->assertTrue($rule->passes(['environments' => ['production', 'staging']], $this->context(environment: 'staging')));
        $this->assertFalse($rule->passes(['environments' => ['production']], $this->context(environment: 'staging')));
    }
}
