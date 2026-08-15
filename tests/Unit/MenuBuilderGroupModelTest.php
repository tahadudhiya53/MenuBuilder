<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;

class MenuBuilderGroupModelTest extends TestCase
{
    public function testNameAndHandleRequired(): void
    {
        $group = new MenuBuilderGroup();

        $this->assertFalse($group->validate());
        $this->assertArrayHasKey('name', $group->getErrors());
        $this->assertArrayHasKey('handle', $group->getErrors());
    }

    public function testHandleFormat(): void
    {
        $group = new MenuBuilderGroup();
        $group->name = 'Main';
        $group->handle = '1invalid';

        $this->assertFalse($group->validate());
        $this->assertArrayHasKey('handle', $group->getErrors());

        $group->handle = 'main_nav';
        $this->assertTrue($group->validate());
    }

    public function testAllowsDepth(): void
    {
        $group = new MenuBuilderGroup();
        $group->name = 'Main';
        $group->handle = 'main';

        // No limit configured.
        $this->assertTrue($group->allowsDepth(1));
        $this->assertTrue($group->allowsDepth(100));

        $group->maxDepth = 2;
        $this->assertTrue($group->allowsDepth(1));
        $this->assertTrue($group->allowsDepth(2));
        $this->assertFalse($group->allowsDepth(3));
    }
}
