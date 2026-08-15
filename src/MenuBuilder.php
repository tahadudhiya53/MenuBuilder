<?php

namespace Tahadudhiya\MenuBuilder;

use Craft;
use craft\base\Plugin;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use Tahadudhiya\MenuBuilder\services\MenuBuilderActiveResolver;
use Tahadudhiya\MenuBuilder\services\MenuBuilderCacheService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderGroupService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderItemService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderLinkResolver;
use Tahadudhiya\MenuBuilder\services\MenuBuilderResolver;
use Tahadudhiya\MenuBuilder\services\MenuBuilderVisibilityService;
use Tahadudhiya\MenuBuilder\variables\MenuBuilderVariable;
use yii\base\Event;

/**
 * @property-read MenuBuilderGroupService $groups
 * @property-read MenuBuilderItemService $items
 * @property-read MenuBuilderLinkResolver $linkResolver
 * @property-read MenuBuilderVisibilityService $visibility
 * @property-read MenuBuilderCacheService $cache
 * @property-read MenuBuilderActiveResolver $activeResolver
 * @property-read MenuBuilderResolver $resolver
 */
class MenuBuilder extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = false;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'groups' => MenuBuilderGroupService::class,
                'items' => MenuBuilderItemService::class,
                'linkResolver' => MenuBuilderLinkResolver::class,
                'visibility' => MenuBuilderVisibilityService::class,
                'cache' => MenuBuilderCacheService::class,
                'activeResolver' => MenuBuilderActiveResolver::class,
                'resolver' => MenuBuilderResolver::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->attachEventHandlers();

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
                $event->rules['menu-builder'] = 'menu-builder/dashboard/index';
                $event->rules['menu-builder/groups/new'] = 'menu-builder/groups/edit';
                $event->rules['menu-builder/groups/<groupId:\d+>'] = 'menu-builder/groups/edit';
                $event->rules['menu-builder/<groupHandle:[a-zA-Z][a-zA-Z0-9_]*>'] = 'menu-builder/dashboard/index';
                $event->rules['menu-builder/<groupHandle:[a-zA-Z][a-zA-Z0-9_]*>/items/new'] = 'menu-builder/items/edit';
                $event->rules['menu-builder/<groupHandle:[a-zA-Z][a-zA-Z0-9_]*>/items/<itemId:\d+>'] = 'menu-builder/items/edit';
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
                            'label' => Craft::t('menu-builder', 'Create navigation groups and items'),
                        ],
                        'menuBuilder:edit' => [
                            'label' => Craft::t('menu-builder', 'Edit navigation groups and items'),
                        ],
                        'menuBuilder:delete' => [
                            'label' => Craft::t('menu-builder', 'Delete navigation groups and items'),
                        ],
                        'menuBuilder:manageSettings' => [
                            'label' => Craft::t('menu-builder', 'Manage navigation settings'),
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
        $item['label'] = Craft::t('menu-builder', 'MenuBuilder');

        $currentUser = Craft::$app->getUser()->getIdentity();
        $subnav = [];

        if ($currentUser && ($currentUser->admin || $currentUser->can('menuBuilder:view'))) {
            $subnav['dashboard'] = ['label' => Craft::t('menu-builder', 'Dashboard'), 'url' => 'menu-builder'];
        }

        $item['subnav'] = $subnav;

        return $item;
    }
}
