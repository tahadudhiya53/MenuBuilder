<?php

namespace Tahadudhiya\MenuBuilder\gql;

use Craft;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Tahadudhiya\MenuBuilder\helpers\MenuBuilderGqlHelper;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;

/**
 * One resolved navigation item — the GraphQL shape of a
 * {@see MenuBuilderNode}.
 *
 * ## What is deliberately not here
 *
 * **The row ID.** A node carries `id` for Twig's benefit; it is a
 * `menubuilder_items` primary key, which is an internal fact about this
 * install's database and not an identifier any consumer of a *rendered*
 * navigation needs. `handle` is the editor-set, stable, intentionally public
 * name for an item, and it is what this type exposes. (A dynamic item's
 * synthesized children carry a Craft *element* ID in the same property — see
 * MenuBuilderResolver::buildDynamicNode() — so an `id` field here would mean
 * two different things depending on the node, which is a second reason not
 * to have one.)
 *
 * **Anything an editor configured but a visitor never sees**: visibility
 * rules, fallback behaviour, sort columns, the raw metadata bag. A visitor
 * cannot see them in HTML and a GraphQL consumer is a visitor.
 *
 * Everything that *is* here is read through the node's own accessors rather
 * than webonyx's default property lookup, so the fail-closed reads those
 * accessors perform — {@see MenuBuilderNode::iconClass()},
 * {@see MenuBuilderNode::safeHtmlAttributes()},
 * {@see MenuBuilderNode::badgeClass()} — apply to a GraphQL response exactly
 * as they apply to a rendered one. A value written straight into the database
 * cannot reach a consumer through this type any more than it can reach a
 * template.
 */
class MenuBuilderNavigationItemType
{
    public const NAME = 'MenuBuilderNavigationItem';

    public const ATTRIBUTE_NAME = 'MenuBuilderNavigationAttribute';

    public const CUSTOM_FIELD_NAME = 'MenuBuilderNavigationCustomField';

    public const MEGA_MENU_NAME = 'MenuBuilderNavigationMegaMenu';

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::NAME, fn() => new ObjectType([
            'name' => self::NAME,
            'description' => Craft::t('menu-builder', 'A resolved navigation item.'),
            // Lazy, because `children` is this very type: webonyx has to be
            // able to hand back the type object before its field list is
            // built, or the recursion never terminates.
            'fields' => fn() => self::fieldDefinitions(),
        ]));
    }

    /** The `{name, value}` pair type shared by every attribute bag on this surface. */
    public static function attributeType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::ATTRIBUTE_NAME, fn() => new ObjectType([
            'name' => self::ATTRIBUTE_NAME,
            'description' => Craft::t('menu-builder', 'One HTML attribute, as a name/value pair.'),
            'fields' => [
                'name' => ['name' => 'name', 'type' => Type::nonNull(Type::string())],
                'value' => ['name' => 'value', 'type' => Type::nonNull(Type::string())],
            ],
        ]));
    }

    /**
     * One editor-defined custom field value. See
     * {@see MenuBuilderGqlHelper::customFieldEntries()} for why a value is
     * offered under four accessors rather than one.
     */
    public static function customFieldType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::CUSTOM_FIELD_NAME, fn() => new ObjectType([
            'name' => self::CUSTOM_FIELD_NAME,
            'description' => Craft::t('menu-builder', 'One editor-defined custom field value on a navigation item.'),
            'fields' => [
                'handle' => ['name' => 'handle', 'type' => Type::nonNull(Type::string())],
                'value' => [
                    'name' => 'value',
                    'type' => Type::string(),
                    'description' => Craft::t('menu-builder', 'The value as a string. A boolean reads “true” or “false”.'),
                ],
                'booleanValue' => [
                    'name' => 'booleanValue',
                    'type' => Type::boolean(),
                    'description' => Craft::t('menu-builder', 'The value, when it is a boolean; null otherwise.'),
                ],
                'numberValue' => [
                    'name' => 'numberValue',
                    'type' => Type::float(),
                    'description' => Craft::t('menu-builder', 'The value, when it is a number; null otherwise.'),
                ],
                'intValue' => [
                    'name' => 'intValue',
                    'type' => Type::int(),
                    'description' => Craft::t('menu-builder', 'The value, when it is a whole number — an asset field’s asset ID, for instance; null otherwise.'),
                ],
            ],
        ]));
    }

    /** A mega-menu-enabled item's panel configuration. */
    public static function megaMenuType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::MEGA_MENU_NAME, fn() => new ObjectType([
            'name' => self::MEGA_MENU_NAME,
            'description' => Craft::t('menu-builder', 'Mega-menu configuration for an item that opens one.'),
            'fields' => [
                'columns' => [
                    'name' => 'columns',
                    'type' => Type::nonNull(Type::int()),
                    'description' => Craft::t('menu-builder', 'How many columns the panel is laid out in.'),
                ],
            ],
        ]));
    }

    /**
     * The item type's fields, resolvers included, as a plain array — the same
     * separation {@see MenuBuilderMenuType::fieldDefinitions()} makes, and for
     * the same reason: what each resolver returns for a given node is
     * unit-testable without a booted Craft application, while
     * {@see getType()} (which needs one, to read the schema's type prefix) is
     * only exercised by the integration suite.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function fieldDefinitions(): array
    {
        return [
            // --- identity -------------------------------------------------
            'handle' => self::field('handle', Type::string(), 'The item’s handle, or null if the editor set none. Row IDs are deliberately not exposed.', fn(MenuBuilderNode $node) => $node->handle),
            'type' => self::field('type', Type::nonNull(Type::string()), 'The link type: entry, category, asset, url, anchor, nonclickable, separator or dynamic.', fn(MenuBuilderNode $node) => $node->type),
            'level' => self::field('level', Type::nonNull(Type::int()), 'The item’s depth, 1 for a top-level item.', fn(MenuBuilderNode $node) => $node->level),
            'isDynamic' => self::field('isDynamic', Type::nonNull(Type::boolean()), 'Whether this item was synthesized from a dynamic navigation source rather than authored.', fn(MenuBuilderNode $node) => $node->isDynamic),

            // --- the link -------------------------------------------------
            'title' => self::field('title', Type::nonNull(Type::string()), 'The item’s resolved title.', fn(MenuBuilderNode $node) => $node->title),
            'url' => self::field('url', Type::string(), 'The resolved URL, or null when the item has none.', fn(MenuBuilderNode $node) => $node->url),
            'isClickable' => self::field('isClickable', Type::nonNull(Type::boolean()), 'Whether this item should render as a link at all.', fn(MenuBuilderNode $node) => $node->isClickable),
            'isLinkAvailable' => self::field('isLinkAvailable', Type::nonNull(Type::boolean()), 'Whether the linked element still resolves. False for a deleted, disabled or non-public element whose item is set to keep showing.', fn(MenuBuilderNode $node) => $node->isLinkAvailable),
            'target' => self::field('target', Type::nonNull(Type::string()), 'The link target.', fn(MenuBuilderNode $node) => $node->target),
            'rel' => self::field('rel', Type::string(), 'The link’s rel, with the safety values a _blank target implies already merged in.', fn(MenuBuilderNode $node) => $node->rel),
            'opensInNewTab' => self::field('opensInNewTab', Type::nonNull(Type::boolean()), 'Whether following this link leaves the current tab — announce the change of context (WCAG 3.2.5).', fn(MenuBuilderNode $node) => $node->opensInNewTab()),

            // --- active state ---------------------------------------------
            'isActive' => self::field('isActive', Type::nonNull(Type::boolean()), 'Whether this item is the page named by the query’s currentUri argument. Always false when no currentUri was given.', fn(MenuBuilderNode $node) => $node->isActive),
            'isActiveAncestor' => self::field('isActiveAncestor', Type::nonNull(Type::boolean()), 'Whether a descendant of this item is the current page.', fn(MenuBuilderNode $node) => $node->isActiveAncestor),

            // --- presentation ---------------------------------------------
            'cssClass' => self::field('cssClass', Type::string(), 'The item’s CSS class.', fn(MenuBuilderNode $node) => $node->cssClass),
            'htmlId' => self::field('htmlId', Type::string(), 'The item’s HTML id.', fn(MenuBuilderNode $node) => $node->htmlId),
            'htmlAttributes' => self::field(
                'htmlAttributes',
                Type::nonNull(Type::listOf(Type::nonNull(self::attributeType()))),
                'The item’s custom HTML attributes, already stripped of anything unsafe or reserved.',
                fn(MenuBuilderNode $node) => MenuBuilderGqlHelper::attributePairs($node->safeHtmlAttributes()),
            ),
            'ariaLabel' => self::field('ariaLabel', Type::string(), 'The item’s aria-label.', fn(MenuBuilderNode $node) => $node->ariaLabel),
            'titleAttribute' => self::field('titleAttribute', Type::string(), 'The item’s title attribute.', fn(MenuBuilderNode $node) => $node->titleAttribute),
            'description' => self::field('description', Type::string(), 'The item’s description.', fn(MenuBuilderNode $node) => $node->description),
            'featured' => self::field('featured', Type::nonNull(Type::boolean()), 'Whether the editor flagged this item as featured.', fn(MenuBuilderNode $node) => $node->featured),
            'imageId' => self::field('imageId', Type::int(), 'The item’s image, as a Craft asset ID — feed it back into Craft’s own asset query.', fn(MenuBuilderNode $node) => $node->image),

            // --- icon -----------------------------------------------------
            'iconType' => self::field('iconType', Type::string(), 'How the icon is expressed: “class” or “asset”, or null when there is none.', fn(MenuBuilderNode $node) => $node->iconType()),
            'iconClass' => self::field('iconClass', Type::string(), 'The icon’s class list, when it is a class icon.', fn(MenuBuilderNode $node) => $node->iconClass()),
            'iconAssetId' => self::field('iconAssetId', Type::int(), 'The icon’s Craft asset ID, when it is an asset icon. The URL is deliberately not resolved here — an asset’s URL can change without the menu changing.', fn(MenuBuilderNode $node) => $node->iconAssetId()),

            // --- badge ----------------------------------------------------
            'badge' => self::field('badge', Type::string(), 'The badge’s text, or null when the item has no badge.', fn(MenuBuilderNode $node) => $node->hasBadge() ? $node->badge : null),
            'badgeStyle' => self::field('badgeStyle', Type::string(), 'The badge’s style, or null for the default.', fn(MenuBuilderNode $node) => $node->hasBadge() ? $node->badgeStyle : null),
            'badgeClass' => self::field('badgeClass', Type::string(), 'The badge’s class list, as the bundled macro renders it.', fn(MenuBuilderNode $node) => $node->hasBadge() ? $node->badgeClass() : null),

            // --- mega menu ------------------------------------------------
            'megaMenu' => self::field('megaMenu', self::megaMenuType(), 'This item’s mega-menu configuration, when it opens one.', fn(MenuBuilderNode $node) => $node->megaMenu),
            'megaMenuColumn' => self::field('megaMenuColumn', Type::int(), 'Which column of its parent’s mega-menu panel this item belongs to.', fn(MenuBuilderNode $node) => $node->megaMenuColumn),

            // --- mobile ---------------------------------------------------
            'mobileVisibility' => self::field('mobileVisibility', Type::nonNull(Type::string()), 'Which navigations this item belongs to: both, desktopOnly or mobileOnly.', fn(MenuBuilderNode $node) => $node->mobileVisibility()),
            'mobileOrder' => self::field('mobileOrder', Type::int(), 'The item’s mobile sort override, or null when it has none.', fn(MenuBuilderNode $node) => $node->mobileOrder()),
            'isMobileCollapsible' => self::field('isMobileCollapsible', Type::nonNull(Type::boolean()), 'Whether this item’s children are a collapsed disclosure on mobile.', fn(MenuBuilderNode $node) => $node->isMobileCollapsible()),
            'mobileMegaMenuBehavior' => self::field('mobileMegaMenuBehavior', Type::nonNull(Type::string()), 'How this item’s mega-menu panel behaves on mobile: stack, columns or hide.', fn(MenuBuilderNode $node) => $node->mobileMegaMenuBehavior()),
            'viewportAttribute' => self::field('viewportAttribute', Type::string(), 'The value for data-mb-viewport, or null when the item belongs to both viewports.', fn(MenuBuilderNode $node) => $node->viewportAttribute()),

            // --- custom fields --------------------------------------------
            'customFields' => self::field(
                'customFields',
                Type::nonNull(Type::listOf(Type::nonNull(self::customFieldType()))),
                'The item’s editor-defined custom field values, already checked against the menu’s current definitions.',
                fn(MenuBuilderNode $node) => MenuBuilderGqlHelper::customFieldEntries($node->customFields),
            ),

            // --- hierarchy ------------------------------------------------
            'hasChildren' => self::field('hasChildren', Type::nonNull(Type::boolean()), 'Whether this item has any visible children.', fn(MenuBuilderNode $node) => $node->hasChildren()),
            'children' => [
                'name' => 'children',
                // Self-referential, so this whole field list is built lazily
                // (see getType()). The registry hands back the same instance,
                // which is what makes the cycle legal rather than infinite.
                'type' => Type::nonNull(Type::listOf(Type::nonNull(self::getType()))),
                'description' => Craft::t('menu-builder', 'This item’s children, already visibility-filtered and in order.'),
                'resolve' => static fn(MenuBuilderNode $node) => $node->children,
            ],
        ];
    }

    /**
     * @param callable(MenuBuilderNode):mixed $resolve
     * @return array<string,mixed>
     */
    private static function field(string $name, Type $type, string $description, callable $resolve): array
    {
        return [
            'name' => $name,
            'type' => $type,
            'description' => Craft::t('menu-builder', $description),
            'resolve' => $resolve,
        ];
    }
}
