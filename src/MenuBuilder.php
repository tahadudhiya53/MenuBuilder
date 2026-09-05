<?php

namespace Tahadudhiya\MenuBuilder;

use Craft;
use craft\base\Plugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Fields;
use craft\services\Gql;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use Tahadudhiya\MenuBuilder\fields\MenuBuilderField;
use Tahadudhiya\MenuBuilder\gql\MenuBuilderNavigationQuery;
use Tahadudhiya\MenuBuilder\models\MenuBuilderApiConfig;
use Tahadudhiya\MenuBuilder\services\MenuBuilderActiveResolver;
use Tahadudhiya\MenuBuilder\services\MenuBuilderBreadcrumbService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderCacheService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderDynamicNavigationService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderElementService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderGroupService;
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
     * First in {@see editions()}, so it is what Craft installs by default
     * and what an unrecognized edition falls back to.
     */
    public const EDITION_FREE = 'free';

    /**
     * The commercial edition: the same plugin, without the menu ceiling.
     * There is no separate Pro implementation of anything — see
     * {@see \Tahadudhiya\MenuBuilder\services\MenuBuilderMenuLimitService}.
     */
    public const EDITION_PRO = 'pro';

    /**
     * @inheritdoc
     *
     * Order matters twice over: Craft installs the *first* edition when none
     * is named, and `Plugin::is()` compares editions by their index here, so
     * Free must stay first and Pro last.
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
     * The REST API's configuration, read from `config/menu-builder.php` once
     * per request.
     *
     * Static and memoized because it is needed at two very different moments
     * — while URL rules are being registered, and again inside
     * {@see \Tahadudhiya\MenuBuilder\controllers\ApiController} — and the
     * two must never see different values: a route that exists for a config
     * the controller then refuses is a 404 nobody can explain.
     */
    private static ?MenuBuilderApiConfig $apiConfig = null;

    public static function apiConfig(): MenuBuilderApiConfig
    {
        if (self::$apiConfig === null) {
            $config = Craft::$app->getConfig()->getConfigFromFile('menu-builder');

            // getConfigFromFile() can hand back a callable or a BaseConfig
            // for the config files Craft itself owns. This plugin's file is a
            // plain array; anything else is not a config it can read, and
            // MenuBuilderApiConfig::fromArray() turns that into the disabled
            // default rather than into a fatal on every request.
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
        // craft\base\Plugin auto-registers the CP template root; the site
        // (front-end) root is not auto-registered, and the optional
        // _macros/tree.twig helper is meant to be importable from front-end
        // templates too.
        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            function(RegisterTemplateRootsEvent $event) {
                $event->roots['menu-builder'] = __DIR__ . '/templates';
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['menu-builder'] = 'menu-builder/groups/index';
                $event->rules['menu-builder/groups/new'] = 'menu-builder/groups/edit';
                $event->rules['menu-builder/groups/<groupId:\d+>'] = 'menu-builder/groups/edit';
                $event->rules['menu-builder/<groupHandle:[a-zA-Z][a-zA-Z0-9_]*>'] = 'menu-builder/dashboard/index';
                $event->rules['menu-builder/<groupHandle:[a-zA-Z][a-zA-Z0-9_]*>/preview'] = 'menu-builder/preview/index';
                $event->rules['menu-builder/<groupHandle:[a-zA-Z][a-zA-Z0-9_]*>/items/<itemId:\d+>'] = 'menu-builder/items/edit';
            }
        );

        // The field type is registered here rather than in a service: it is a
        // Craft component type, and Craft only asks for the list once, at
        // Fields::getAllFieldTypes(). Nothing about it needs the plugin's own
        // services to be resolved first.
        // The read-only REST API. Its URL rules are registered **only** when
        // the install has turned it on in config/menu-builder.php: an API
        // that nobody asked for should not have a URL, however
        // comprehensively its own gates would refuse a request. See
        // MenuBuilderApiConfig for why the per-menu GraphQL schema scope
        // isn't opt-in enough on its own.
        $apiConfig = self::apiConfig();

        if ($apiConfig->enabled) {
            Event::on(
                UrlManager::class,
                UrlManager::EVENT_REGISTER_SITE_URL_RULES,
                function(RegisterUrlRulesEvent $event) use ($apiConfig) {
                    $prefix = $apiConfig->routePrefix();

                    $event->rules[$prefix . '/navigations'] = 'menu-builder/api/index';

                    // Deliberately `[^/]+` rather than Craft's handle
                    // grammar: a handle-shaped pattern would let a malformed
                    // handle fall through to Craft's own 404, which is an
                    // HTML error page an API consumer has to parse. Matching
                    // anything and refusing it in the controller keeps every
                    // answer this endpoint gives a JSON one.
                    $event->rules[$prefix . '/navigations/<handle:[^/]+>'] = 'menu-builder/api/view';
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

        // GraphQL. Registered unconditionally, but scoped to nothing by
        // default: the schema components below start unticked, and
        // MenuBuilderNavigationQuery::getQueries() adds no fields at all to a
        // schema that names no menu — so an install that doesn't use GraphQL
        // sees no change, and a headless one opts in menu by menu. See
        // MenuBuilderNavigationResolver for the five gates a menu passes.
        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS,
            function(RegisterGqlSchemaComponentsEvent $event) {
                $components = MenuBuilderNavigationQuery::schemaComponents();

                if ($components !== []) {
                    $event->queries[Craft::t('menu-builder', 'MenuBuilder')] = $components;
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

        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => 'MenuBuilder',
                    'permissions' => [
                        'menuBuilder:view' => [
                            'label' => Craft::t('menu-builder', 'View navigation'),
                        ],
                        'menuBuilder:create' => [
                            'label' => Craft::t('menu-builder', 'Create menu items'),
                        ],
                        'menuBuilder:edit' => [
                            'label' => Craft::t('menu-builder', 'Edit menu items'),
                        ],
                        'menuBuilder:delete' => [
                            'label' => Craft::t('menu-builder', 'Delete navigation groups and menus'),
                        ],
                        'menuBuilder:manageSettings' => [
                            'label' => Craft::t('menu-builder', 'Manage navigation groups (create, edit, and duplicate)'),
                        ],
                    ],
                ];
            }
        );
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

        $item['label'] = Craft::t('menu-builder', 'MenuBuilder');

        $currentUser = Craft::$app->getUser()->getIdentity();
        $canView = $currentUser !== null
            && ((bool)$currentUser->admin || $currentUser->can('menuBuilder:view'));

        return self::shapeCpNavItem($item, $canView);
    }

    /**
     * Shapes the control-panel nav item, factored out of getCpNavItem() as pure
     * logic so it can be checked without a booted Craft app.
     *
     * The plugin has a single CP destination, so the item carries **no** subnav:
     * a one-entry subnav turns the top-level item into a disclosure toggle that
     * has to be expanded before the menus index can be reached. Without it, one
     * click on "MenuBuilder" opens the index in place.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>|null Null when the user may not view menus.
     */
    public static function shapeCpNavItem(array $item, bool $canView): ?array
    {
        if (!$canView) {
            return null;
        }

        $item['url'] = 'menu-builder';
        unset($item['subnav']);

        return $item;
    }
}
