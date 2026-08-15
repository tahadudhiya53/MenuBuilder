<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use DateTime;
use PHPUnit\Framework\TestCase;
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
        return new VisibilityContext(
            isLoggedIn: $isLoggedIn,
            userGroupIds: $userGroupIds,
            currentSiteId: $currentSiteId,
            now: new DateTime(),
            environment: $environment,
        );
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

    public function testEnvironmentRule(): void
    {
        $rule = new EnvironmentRule();

        $this->assertTrue($rule->passes([], $this->context(environment: 'staging')), 'Empty list always passes.');
        $this->assertTrue($rule->passes(['environments' => ['production', 'staging']], $this->context(environment: 'staging')));
        $this->assertFalse($rule->passes(['environments' => ['production']], $this->context(environment: 'staging')));
    }
}
