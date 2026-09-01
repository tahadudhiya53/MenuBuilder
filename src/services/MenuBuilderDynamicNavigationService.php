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
 * Resolves a `dynamic` item's `metadata['dynamicSource']` config
 * into a bounded list of Craft elements. Never runs raw/unbounded queries —
 * `sourceType` picks a fixed element class, `sourceId` scopes to one
 * section/category-group/volume, `limit` is always clamped to
 * {@see MenuBuilderItem::DYNAMIC_SOURCE_MAX_LIMIT} server-side regardless of
 * what's stored, and `orderBy` is restricted to a fixed whitelist
 * ({@see MenuBuilderItem::DYNAMIC_SOURCE_ORDER_BY}) — never editor-supplied
 * SQL. Every query is scoped to the current site and to normally-visible
 * elements (live entries, enabled categories, enabled assets), the same
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
        $config = self::normalizeConfig($config);

        if ($config === null) {
            return [];
        }

        $site = Craft::$app->getSites()->getCurrentSite();

        // No `default` arm and no null guard after it: $sourceType is already
        // constrained to DYNAMIC_SOURCE_TYPES by normalizeConfig(), so both
        // were unreachable. Should that list ever grow, this match throws
        // rather than silently returning an empty menu.
        $query = match ($config['sourceType']) {
            'entries' => Entry::find()->sectionId($config['sourceId'])->status(Entry::STATUS_LIVE),
            'categories' => Category::find()->groupId($config['sourceId'])->status(Category::STATUS_ENABLED),
            'assets' => Asset::find()->volumeId($config['sourceId'])->status(Asset::STATUS_ENABLED),
        };

        return $query->site($site)->orderBy($config['orderBy'])->limit($config['limit'])->all();
    }

    /**
     * Normalizes a stored `dynamicSource` config into the exact values the
     * query is allowed to use, or null when it can't be used at all. Pure
     * and public so the clamping/whitelisting rules — the security-relevant
     * half of this service — are unit-testable without a booted Craft app.
     *
     * @return array{sourceType:'entries'|'categories'|'assets',sourceId:int,limit:int,orderBy:string}|null
     */
    public static function normalizeConfig(array $config): ?array
    {
        $sourceType = $config['sourceType'] ?? null;
        $sourceId = isset($config['sourceId']) && (is_int($config['sourceId']) || (is_string($config['sourceId']) && ctype_digit($config['sourceId'])))
            ? (int)$config['sourceId']
            : null;

        if (!in_array($sourceType, MenuBuilderItem::DYNAMIC_SOURCE_TYPES, true) || $sourceId === null || $sourceId < 1) {
            return null;
        }

        $limit = is_numeric($config['limit'] ?? null) ? (int)$config['limit'] : MenuBuilderItem::DYNAMIC_SOURCE_MAX_LIMIT;
        $limit = max(1, min($limit, MenuBuilderItem::DYNAMIC_SOURCE_MAX_LIMIT));

        $orderBy = in_array($config['orderBy'] ?? null, MenuBuilderItem::DYNAMIC_SOURCE_ORDER_BY, true)
            ? $config['orderBy']
            : MenuBuilderItem::DYNAMIC_SOURCE_ORDER_BY[0];

        /** @var 'entries'|'categories'|'assets' $sourceType — narrowed by the in_array() guard above. */
        return [
            'sourceType' => $sourceType,
            'sourceId' => $sourceId,
            'limit' => $limit,
            'orderBy' => $orderBy,
        ];
    }
}
