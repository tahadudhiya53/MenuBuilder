<?php

namespace Tahadudhiya\MenuBuilder;

use Craft;
use craft\base\Plugin;
use craft\db\Query;
use craft\db\Table;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\Gc;
use craft\services\Gql;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use Tahadudhiya\MenuBuilder\elements\MenuBuilderItemContent;
use Tahadudhiya\MenuBuilder\fields\MenuBuilderField;
use Tahadudhiya\MenuBuilder\gql\MenuBuilderNavigationQuery;
use Tahadudhiya\MenuBuilder\models\MenuBuilderApiConfig;
use Tahadudhiya\MenuBuilder\services\MenuBuilderActiveResolver;
use Tahadudhiya\MenuBuilder\services\MenuBuilderBreadcrumbService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderCacheService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderDynamicNavigationService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderElementService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderGroupService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderItemContentService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderItemService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderLicenseService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderLinkHealthService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderLinkResolver;
use Tahadudhiya\MenuBuilder\services\MenuBuilderMenuLimitService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderPreviewService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderResolver;
use Tahadudhiya\MenuBuilder\services\MenuBuilderScopeService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderVisibilityService;
use Tahadudhiya\MenuBuilder\variables\MenuBuilderVariable;
use yii\base\Event;

/**
 * @property-read MenuBuilderGroupService $groups
 * @property-read MenuBuilderItemService $items
 * @property-read MenuBuilderItemContentService $itemContent
 * @property-read MenuBuilderLinkResolver $linkResolver
 * @property-read MenuBuilderLinkHealthService $linkHealth
 * @property-read MenuBuilderVisibilityService $visibility
 * @property-read MenuBuilderCacheService $cache
 * @property-read MenuBuilderActiveResolver $activeResolver
 * @property-read MenuBuilderResolver $resolver
 * @property-read MenuBuilderScopeService $scope
 * @property-read MenuBuilderElementService $elements
 * @property-read MenuBuilderDynamicNavigationService $dynamicNavigation
 * @property-read MenuBuilderPreviewService $preview
 * @property-read MenuBuilderBreadcrumbService $breadcrumbs
 * @property-read MenuBuilderLicenseService $license
 * @property-read MenuBuilderMenuLimitService $menuLimit
*/
class MenuBuilder extends Plugin
{
    /**
     * The free edition: every feature the plugin has, inside one menu.
    */
    public const EDITION_FREE = 'free';

    /**
     * The commercial edition: the same plugin, without the menu ceiling.
    */
    public const EDITION_PRO = 'pro';

    /**
     * @inheritdoc Order matters twice over: Craft installs the *first* edition when none is named, and `Plugin::is()` compares editions by their index here, so Free must stay first and Pro last.
    */
    public static function editions(): array
    {
        return [
            self::EDITION_FREE,
            self::EDITION_PRO,
        ];
    }

    public string $schemaVersion = '1.0.0';

    /**
     * The REST API's configuration, read from `config/menubuilder.php` once per request.
    */
    private static ?MenuBuilderApiConfig $apiConfig = null;

    public static function apiConfig(): MenuBuilderApiConfig
    {
        if (self::$apiConfig === null) {
            $config = Craft::$app->getConfig()->getConfigFromFile('menubuilder');

            // getConfigFromFile() can hand back a callable or a BaseConfig for the config files
            // Craft itself owns.
            self::$apiConfig = MenuBuilderApiConfig::fromArray($config);
        }

        return self::$apiConfig;
    }
    public bool $hasCpSettings = false;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'groups' => MenuBuilderGroupService::class,
                'items' => MenuBuilderItemService::class,
                'itemContent' => MenuBuilderItemContentService::class,
                'linkResolver' => MenuBuilderLinkResolver::class,
                'linkHealth' => MenuBuilderLinkHealthService::class,
                'visibility' => MenuBuilderVisibilityService::class,
                'cache' => MenuBuilderCacheService::class,
                'activeResolver' => MenuBuilderActiveResolver::class,
                'resolver' => MenuBuilderResolver::class,
                'scope' => MenuBuilderScopeService::class,
                'elements' => MenuBuilderElementService::class,
                'dynamicNavigation' => MenuBuilderDynamicNavigationService::class,
                'preview' => MenuBuilderPreviewService::class,
                'breadcrumbs' => MenuBuilderBreadcrumbService::class,
                'license' => MenuBuilderLicenseService::class,
                'menuLimit' => MenuBuilderMenuLimitService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->attachEventHandlers();
        $this->elements->attachListeners();

        Craft::$app->onInit(function() {
            $this->registerVariable();
        });
    }

    private function attachEventHandlers(): void
    {
        // craft\base\Plugin auto-registers the CP template root; the site (front-end) root is not
        // auto-registered, and the optional _macros/tree.twig helper is meant to be importable from
        // front-end templates too.
        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            function(RegisterTemplateRootsEvent $event) {
                $event->roots['menubuilder'] = __DIR__ . '/templates';
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['menubuilder'] = 'menubuilder/groups/index';
                $event->rules['menubuilder/groups/new'] = 'menubuilder/groups/edit';
                $event->rules['menubuilder/groups/<groupId:\d+>'] = 'menubuilder/groups/edit';
                $event->rules['menubuilder/<groupHandle:[a-zA-Z][a-zA-Z0-9_]*>'] = 'menubuilder/dashboard/index';
                $event->rules['menubuilder/<groupHandle:[a-zA-Z][a-zA-Z0-9_]*>/preview'] = 'menubuilder/preview/index';
                $event->rules['menubuilder/<groupHandle:[a-zA-Z][a-zA-Z0-9_]*>/items/<itemId:\d+>'] = 'menubuilder/items/edit';
            }
        );

        // The field type is registered here rather than in a service: it is a Craft component type,
        // and Craft only asks for the list once, at Fields::getAllFieldTypes().
        $apiConfig = self::apiConfig();

        if ($apiConfig->enabled) {
            Event::on(
                UrlManager::class,
                UrlManager::EVENT_REGISTER_SITE_URL_RULES,
                function(RegisterUrlRulesEvent $event) use ($apiConfig) {
                    $prefix = $apiConfig->routePrefix();

                    $event->rules[$prefix . '/navigations'] = 'menubuilder/api/index';

                    // Deliberately `[^/]+` rather than Craft's handle grammar: a handle-shaped
                    // pattern would let a malformed handle fall through to Craft's own 404, which
                    // is an HTML error page an API consumer has to parse.
                    $event->rules[$prefix . '/navigations/<handle:[^/]+>'] = 'menubuilder/api/view';
                }
            );
        }

        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = MenuBuilderField::class;
            }
        );

        // GraphQL.
        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS,
            function(RegisterGqlSchemaComponentsEvent $event) {
                $components = MenuBuilderNavigationQuery::schemaComponents();

                if ($components !== []) {
                    $event->queries[Craft::t('menubuilder', 'MenuBuilder')] = $components;
                }
            }
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_QUERIES,
            function(RegisterGqlQueriesEvent $event) {
                $event->queries = array_merge($event->queries, MenuBuilderNavigationQuery::getQueries());
            }
        );

        // The content element behind menu items' custom fields.
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = MenuBuilderItemContent::class;
            }
        );

        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            function() {
                $this->collectGarbage();
            }
        );

        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => 'MenuBuilder',
                    'permissions' => [
                        'menuBuilder:view' => [
                            'label' => Craft::t('menubuilder', 'View navigation'),
                        ],
                        'menuBuilder:create' => [
                            'label' => Craft::t('menubuilder', 'Create menu items'),
                        ],
                        'menuBuilder:edit' => [
                            'label' => Craft::t('menubuilder', 'Edit menu items'),
                        ],
                        'menuBuilder:delete' => [
                            'label' => Craft::t('menubuilder', 'Delete navigation groups and menus'),
                        ],
                        'menuBuilder:manageSettings' => [
                            'label' => Craft::t('menubuilder', 'Manage navigation groups (create, edit, and duplicate)'),
                        ],
                    ],
                ];
            }
        );
    }

    /**
     * Deletes content elements no navigation item points at any more.
    */
    private function collectGarbage(): void
    {
        $orphanIds = (new Query())
            ->select(['e.id'])
            ->from(['e' => Table::ELEMENTS])
            ->leftJoin(
                ['i' => '{{%menubuilder_items}}'],
                '[[i.contentId]] = [[e.id]]'
            )
            ->where([
                'e.type' => MenuBuilderItemContent::class,
                'i.id' => null,
            ])
            ->column();

        if ($orphanIds === []) {
            return;
        }

        // Deleted through Craft rather than with a DELETE: a content element can own Matrix blocks,
        // and those are elements of their own that only Elements::deleteElement() knows to take
        // down with it.
        $this->itemContent->deleteByIds(array_map('intval', $orphanIds));
    }

    private function registerVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('menuBuilder', MenuBuilderVariable::class);
            }
        );
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        if ($item === null) {
            return null;
        }

        $item['label'] = Craft::t('menubuilder', 'MenuBuilder');

        $currentUser = Craft::$app->getUser()->getIdentity();
        $canView = $currentUser !== null
            && ((bool)$currentUser->admin || $currentUser->can('menuBuilder:view'));

        return self::shapeCpNavItem($item, $canView);
    }

    /**
     * Shapes the control-panel nav item, factored out of getCpNavItem() as pure logic so it can be
     * checked without a booted Craft app.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>|null Null when the user may not view menus.
    */
    public static function shapeCpNavItem(array $item, bool $canView): ?array
    {
        if (!$canView) {
            return null;
        }

        $item['url'] = 'menubuilder';
        unset($item['subnav']);

        return $item;
    }
}
