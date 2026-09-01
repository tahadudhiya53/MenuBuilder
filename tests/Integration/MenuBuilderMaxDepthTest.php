<?php

namespace Tahadudhiya\MenuBuilder\Tests\Integration;

use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;

/**
 * Max-depth enforcement through the real save/move path, against a real
 * database.
 *
 * The pure depth arithmetic is covered by MenuBuilderTreeTest; what only a
 * booted install can prove is what the *service* does with it — and that is
 * where the bug this suite was written for lived: an insert passed `0` for
 * the not-yet-existing item's ID, which is `childMap()`'s key for the root
 * set, so the new item was charged for the height of every existing root
 * branch. On the real three-level menu of the phase 30 simulation
 * (Products › Electronics › Phones, maxDepth 3) the third level could not be
 * created at all.
 */
class MenuBuilderMaxDepthTest extends CraftIntegrationTestCase
{
    private function nest(int $groupId, string $title, ?int $parentId): MenuBuilderItem
    {
        $item = new MenuBuilderItem();
        $item->groupId = $groupId;
        $item->parentId = $parentId;
        $item->title = $title;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->customUrl = '/' . strtolower($title);

        MenuBuilder::getInstance()->items->save($item);

        return $item;
    }

    public function testAThirdLevelCanBeInsertedIntoAMenuWhoseMaxDepthIsThree(): void
    {
        $menu = self::createMenu('depth3', 'Depth 3');
        $menu->maxDepth = 3;
        $this->assertTrue(MenuBuilder::getInstance()->groups->save($menu));

        $products = $this->nest((int)$menu->id, 'Products', null);
        $this->assertNotNull($products->id, json_encode($products->getErrors()));

        $electronics = $this->nest((int)$menu->id, 'Electronics', (int)$products->id);
        $this->assertNotNull($electronics->id, json_encode($electronics->getErrors()));

        // A second root branch, so the root forest is not the only thing in
        // the menu — the regression only showed up once other branches had
        // height of their own.
        $services = $this->nest((int)$menu->id, 'Services', null);
        $this->nest((int)$menu->id, 'Consulting', (int)$services->id);

        $phones = $this->nest((int)$menu->id, 'Phones', (int)$electronics->id);

        $this->assertNotNull(
            $phones->id,
            'A level-3 insert must be allowed on a maxDepth-3 menu: ' . json_encode($phones->getErrors())
        );

        $tree = MenuBuilder::getInstance()->items->getTree((int)$menu->id);
        $titles = [];

        foreach ($tree as $root) {
            foreach ($root->children as $child) {
                foreach ($child->children as $grandchild) {
                    $titles[] = $grandchild->title;
                }
            }
        }

        $this->assertContains('Phones', $titles, 'The inserted item is really in the tree at level 3.');
    }

    public function testAFourthLevelIsStillRefused(): void
    {
        $menu = self::createMenu('depth3b', 'Depth 3 B');
        $menu->maxDepth = 3;
        MenuBuilder::getInstance()->groups->save($menu);

        $a = $this->nest((int)$menu->id, 'A', null);
        $b = $this->nest((int)$menu->id, 'B', (int)$a->id);
        $c = $this->nest((int)$menu->id, 'C', (int)$b->id);
        $d = $this->nest((int)$menu->id, 'D', (int)$c->id);

        $this->assertNull($d->id, 'Level 4 must be refused.');
        $this->assertArrayHasKey('parentId', $d->getErrors());
    }

    public function testMovingASubtreeStillObeysTheLimit(): void
    {
        $menu = self::createMenu('depth2', 'Depth 2');
        $menu->maxDepth = 2;
        MenuBuilder::getInstance()->groups->save($menu);

        $a = $this->nest((int)$menu->id, 'A', null);
        $b = $this->nest((int)$menu->id, 'B', (int)$a->id);
        $c = $this->nest((int)$menu->id, 'C', null);

        // A leaf under a root item is level 2 — fine.
        $this->assertTrue(MenuBuilder::getInstance()->items->move((int)$c->id, (int)$a->id, 1));

        // A + its child under another item would be level 3 — refused, and
        // the descendants are what makes it so.
        $this->assertFalse(MenuBuilder::getInstance()->items->move((int)$a->id, (int)$b->id, 0));
    }

    public function testAMenuWithNoMaxDepthAcceptsDeepNesting(): void
    {
        $menu = self::createMenu('depthNull', 'No limit');

        $parentId = null;

        for ($level = 1; $level <= 6; $level++) {
            $item = $this->nest((int)$menu->id, "L{$level}", $parentId);
            $this->assertNotNull($item->id, "Level {$level}: " . json_encode($item->getErrors()));
            $parentId = (int)$item->id;
        }
    }
}
