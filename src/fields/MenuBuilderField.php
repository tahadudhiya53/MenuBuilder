<?php

namespace Tahadudhiya\MenuBuilder\fields;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\InlineEditableFieldInterface;
use craft\base\MergeableFieldInterface;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use GraphQL\Type\Definition\Type;
use Tahadudhiya\MenuBuilder\gql\MenuBuilderMenuType;
use Tahadudhiya\MenuBuilder\helpers\MenuBuilderFieldHelper;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderFieldValue;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use yii\db\Schema;

/**
 * Lets a content author attach one MenuBuilder navigation to an element — an entry, a Matrix
 * block, a category, a user — and read it back in Twig as `entry.navigation`.
*/
class MenuBuilderField extends Field implements InlineEditableFieldInterface, MergeableFieldInterface
{
    /**
     * @var string[] UIDs of the menus this field offers. Empty = every menu, the same "empty means unrestricted" convention {@see MenuBuilderGroup::$siteIds} uses.
    */
    public array $allowedGroupUids = [];

    /**
     * @var bool Whether disabled menus appear in the picker. Off by default: a disabled menu resolves to no tree, so offering one invites an author to pick something that renders nothing.
    */
    public bool $includeDisabledMenus = false;

    public static function displayName(): string
    {
        return Craft::t('menu-builder', 'Navigation');
    }

    public static function icon(): string
    {
        return 'bars';
    }

    public static function phpType(): string
    {
        return sprintf('\\%s|null', MenuBuilderFieldValue::class);
    }

    public static function dbType(): string
    {
        return Schema::TYPE_STRING;
    }

    /**
     * Normalized on the way *in* as well as on the way out, so a project config applied from YAML
     * that somebody hand-edited can't leave a non-UID entry in the allow-list.
    */
    public function __construct($config = [])
    {
        if (isset($config['allowedGroupUids'])) {
            $config['allowedGroupUids'] = MenuBuilderFieldHelper::normalizeUidList($config['allowedGroupUids']);
        }

        parent::__construct($config);
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['allowedGroupUids'], 'validateAllowedGroupUids', 'skipOnEmpty' => false],
            [['allowedGroupUids'], 'safe'],
            [['includeDisabledMenus'], 'boolean'],
        ]);
    }

    /**
     * The settings are posted from a checkbox list, so the shape is checked rather than cast —
     * the same treatment {@see MenuBuilderGroup::validateSiteIds()} gives its site restriction.
    */
    public function validateAllowedGroupUids(): void
    {
        if (!is_array($this->allowedGroupUids)) {
            $this->addError('allowedGroupUids', Craft::t('menu-builder', 'Invalid menus.'));

            return;
        }

        foreach ($this->allowedGroupUids as $uid) {
            if (MenuBuilderFieldHelper::normalizeUid($uid) === null) {
                $this->addError('allowedGroupUids', Craft::t('menu-builder', 'Invalid menus.'));

                return;
            }
        }
    }

    // Value

    /**
     * Null for "nothing selected", so `{% if entry.navigation %}` answers the question templates
     * ask; a value object for every real selection, even one whose menu has since been deleted
     * (that case is `value.exists() === false`, not a silently blank field — see {@see
     * MenuBuilderFieldValue}).
    */
    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof MenuBuilderFieldValue) {
            return $value;
        }

        $uid = MenuBuilderFieldHelper::normalizeUid($value);

        if ($uid === null) {
            return null;
        }

        return new MenuBuilderFieldValue(
            $uid,
            MenuBuilder::getInstance()->groups->getByUid($uid),
            $element?->siteId,
        );
    }

    /**
     * The UID, and nothing else — the tree is per-request and never persisted.
    */
    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof MenuBuilderFieldValue) {
            return $value->groupUid;
        }

        return MenuBuilderFieldHelper::normalizeUid($value);
    }

    public function isValueEmpty(mixed $value, ElementInterface $element): bool
    {
        if ($value instanceof MenuBuilderFieldValue) {
            return $value->groupUid === null;
        }

        return MenuBuilderFieldHelper::normalizeUid($value) === null;
    }

    /**
     * The menu's name and handle, so an entry is findable by the navigation attached to it.
    */
    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        if (!$value instanceof MenuBuilderFieldValue) {
            return '';
        }

        return trim(sprintf('%s %s', $value->getName() ?? '', $value->getHandle() ?? ''));
    }

    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        if (!$value instanceof MenuBuilderFieldValue || !$value->exists()) {
            return '';
        }

        return Html::encode((string)$value);
    }

    // Validation

    /**
     * Scoped to the live scenario, as {@see \craft\fields\BaseRelationField} does, so a draft or a
     * revision holding a now-broken selection still saves — the author is told when it matters,
     * at publish time, not blocked from typing.
    */
    public function getElementValidationRules(): array
    {
        return [
            ['validateMenu', 'on' => [Element::SCENARIO_LIVE], 'skipOnEmpty' => false],
        ];
    }

    public function validateMenu(ElementInterface $element): void
    {
        $value = $element->getFieldValue($this->handle);
        $uid = $value instanceof MenuBuilderFieldValue ? $value->groupUid : null;

        $error = MenuBuilderFieldHelper::validationError(
            $uid,
            $value instanceof MenuBuilderFieldValue ? $value->getGroup() : null,
            $this->allowedGroupUids,
            $this->getIsTranslatable($element),
            $element->siteId,
        );

        if ($error === null) {
            return;
        }

        $element->addError($this->handle, match ($error) {
            MenuBuilderFieldHelper::ERROR_MISSING => Craft::t('menu-builder', 'The selected navigation no longer exists.'),
            MenuBuilderFieldHelper::ERROR_NOT_ALLOWED => Craft::t('menu-builder', 'The selected navigation isn’t available to this field.'),
            MenuBuilderFieldHelper::ERROR_SITE_MISMATCH => Craft::t('menu-builder', 'The selected navigation isn’t available on this site.'),
            default => Craft::t('menu-builder', 'Invalid navigation.'),
        });
    }

    // Control panel

    public function getSettingsHtml(): ?string
    {
        $groups = MenuBuilder::getInstance()->groups->getAll();

        return Cp::checkboxSelectFieldHtml([
            'label' => Craft::t('menu-builder', 'Selectable navigations'),
            'instructions' => Craft::t('menu-builder', 'Which navigations authors may choose from. Leave every box unchecked to offer all of them.'),
            'id' => 'allowedGroupUids',
            'name' => 'allowedGroupUids',
            'options' => array_map(fn(MenuBuilderGroup $group) => [
                'label' => $group->name,
                'value' => (string)$group->uid,
            ], $groups),
            'values' => $this->allowedGroupUids,
        ]) . Cp::lightswitchFieldHtml([
            'label' => Craft::t('menu-builder', 'Allow disabled navigations'),
            'instructions' => Craft::t('menu-builder', 'Whether disabled navigations may be selected. A disabled navigation renders nothing.'),
            'id' => 'includeDisabledMenus',
            'name' => 'includeDisabledMenus',
            'on' => $this->includeDisabledMenus,
        ]);
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        $currentUid = $value instanceof MenuBuilderFieldValue ? $value->groupUid : null;

        $selectable = MenuBuilderFieldHelper::selectableGroups(
            MenuBuilder::getInstance()->groups->getAll(),
            $this->allowedGroupUids,
            $this->includeDisabledMenus,
            $currentUid,
        );

        $options = [['label' => Craft::t('menu-builder', 'None'), 'value' => '']];

        foreach ($selectable as $group) {
            $options[] = [
                'label' => $group->enabled
                    ? $group->name
                    : Craft::t('menu-builder', '{name} (disabled)', ['name' => $group->name]),
                'value' => (string)$group->uid,
            ];
        }

        $html = Cp::selectizeHtml([
            'id' => $this->getInputId(),
            'name' => $this->handle,
            'options' => $options,
            'value' => $currentUid,
        ]);

        return $html . $this->footnoteHtml($value, $inline);
    }

    /**
     * The two things the picker itself can't say: that a stored selection has outlived its menu,
     * and where to go and edit the selected one.
    */
    private function footnoteHtml(mixed $value, bool $inline): string
    {
        if ($inline || !$value instanceof MenuBuilderFieldValue) {
            return '';
        }

        if (!$value->exists()) {
            return Html::tag('p', Html::encode(Craft::t('menu-builder', 'The navigation this was set to no longer exists.')), [
                'class' => ['warning', 'with-icon'],
            ]);
        }

        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null || !MenuBuilderFieldHelper::canLinkToMenu((bool)$user->admin, $user->can('menuBuilder:view'))) {
            return '';
        }

        return Html::tag('p', Html::a(
            Craft::t('menu-builder', 'Edit this navigation'),
            UrlHelper::cpUrl('menu-builder/' . $value->getHandle()),
        ), ['class' => 'light smalltext']);
    }

    // GraphQL

    /**
     * The **selection**, not the resolved tree: a tree is per-site, per-visitor and per-page, and a
     * GraphQL response is cached and shared, so baking one into a query result would be the
     * "user-specific state in a shared cache" mistake the whole resolver pipeline is arranged to
     * avoid.
    */
    public function getContentGqlType(): Type|array
    {
        return MenuBuilderMenuType::getType();
    }

    public function getContentGqlQueryArgumentType(): Type|array
    {
        return Type::listOf(Type::string());
    }

    public function getContentGqlMutationArgumentType(): Type|array
    {
        return [
            'name' => $this->handle,
            'type' => Type::string(),
            'description' => Craft::t('menu-builder', 'The navigation’s UID.'),
        ];
    }
}
