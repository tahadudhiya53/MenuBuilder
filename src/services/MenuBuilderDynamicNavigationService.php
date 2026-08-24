<?php

namespace Tahadudhiya\MenuBuilder\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;

/**
 * Phase 8: resolves a `dynamic` item's `metadata['dynamicSource']` config
 * into a bounded list of Craft elements. Never runs raw/unbounded queries —
 * `sourceType` picks a fixed element class, `sourceId` scopes to one
 * section/category-group/volume, `limit` is always clamped to
 * {@see MenuBuilderItem::DYNAMIC_SOURCE_MAX_LIMIT} server-side regardless of
 * what's stored, and `orderBy` is restricted to a fixed whitelist
 * ({@see MenuBuilderItem::DYNAMIC_SOURCE_ORDER_BY}) — never editor-supplied
 * SQL. Every query is scoped to the current site and to normally-visible
 * elements (live entries, enabled categories, all assets), the same
 * boundary `ElementLinkResolver` already uses — a dynamic item can never
 * surface content a real link to the same element wouldn't.
 */
class MenuBuilderDynamicNavigationService extends Component
{
    /**
     * @return ElementInterface[]
     */
    public function resolveElements(array $config): array
    {
        $sourceType = $config['sourceType'] ?? null;
        $sourceId = isset($config['sourceId']) ? (int)$config['sourceId'] : null;

        if (!in_array($sourceType, MenuBuilderItem::DYNAMIC_SOURCE_TYPES, true) || $sourceId === null || $sourceId < 1) {
            return [];
        }

        $limit = min(
            (int)($config['limit'] ?? MenuBuilderItem::DYNAMIC_SOURCE_MAX_LIMIT),
            MenuBuilderItem::DYNAMIC_SOURCE_MAX_LIMIT
        );
        $limit = max($limit, 1);

        $orderBy = in_array($config['orderBy'] ?? null, MenuBuilderItem::DYNAMIC_SOURCE_ORDER_BY, true)
            ? $config['orderBy']
            : MenuBuilderItem::DYNAMIC_SOURCE_ORDER_BY[0];

        $site = Craft::$app->getSites()->getCurrentSite();

        $query = match ($sourceType) {
            'entries' => Entry::find()->sectionId($sourceId)->status(Entry::STATUS_LIVE),
            'categories' => Category::find()->groupId($sourceId)->status(Category::STATUS_ENABLED),
            'assets' => Asset::find()->volumeId($sourceId),
            default => null,
        };

        if ($query === null) {
            return [];
        }

        return $query->site($site)->orderBy($orderBy)->limit($limit)->all();
    }
}
