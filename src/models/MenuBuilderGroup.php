<?php

namespace Tahadudhiya\MenuBuilder\models;

use craft\base\FieldLayoutProviderInterface;
use craft\base\Model;
use craft\behaviors\FieldLayoutBehavior;
use craft\models\FieldLayout;
use Tahadudhiya\MenuBuilder\elements\MenuBuilderItemContent;
use Tahadudhiya\MenuBuilder\helpers\LinkAttributeHelper;

/**
 * A named navigation (e.g. "Main Navigation", "Footer Navigation"). Items
 * belong to exactly one group (see MenuBuilderItem::$groupId) and a parent
 * item must always belong to the same group — cross-group parenting is
 * rejected by MenuBuilderItemService.
 */
class MenuBuilderGroup extends Model implements FieldLayoutProviderInterface
{
    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    public ?string $description = null;
    public bool $enabled = true;
    public int $sortOrder = 0;
    public ?int $maxDepth = null;
    public ?string $cssClass = null;

    /**
     * @var int[] Sites this whole menu is restricted to (empty = every site).
     *            Mirrors the per-item `site` visibility rule (see
     *            visibility/SiteRule) one level up: an unavailable group is
     *            skipped wholesale by MenuBuilderResolver::getTree() rather
     *            than filtered item by item. Persisted inside the `settings`
     *            bag (see MenuBuilderGroupService) so no schema change is
     *            needed.
     */
    public array $siteIds = [];

    /** @var array<string,string> Arbitrary HTML attributes for the rendered <nav>/wrapper. */
    public array $htmlAttributes = [];

    /** @var array<string,mixed> Reserved for future frontend-rendering configuration. */
    public array $settings = [];

    /**
     * @var int|null The Craft field layout every item in this menu is
     *      offered, or null when the menu defines no extra fields.
     *
     * A real `fieldlayouts` row, not a bespoke definition list: the layout
     * is built by Craft's own field layout designer, so a menu can use
     * *any* installed field type — Matrix, relations, third-party fields —
     * arranged into tabs, with Craft's own conditions and instructions.
     * The built-in icon/badge/description columns are deliberately not in
     * it; they have their own columns and their own editor UI.
     *
     * The content for one item lives on that item's
     * {@see MenuBuilderItemContent} element — see that class for why the
     * item is not itself the element.
     */
    public ?int $fieldLayoutId = null;

    public ?string $uid = null;
    public ?string $dateCreated = null;
    public ?string $dateUpdated = null;

    protected function defineRules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_]*$/', 'message' => 'Handle must start with a letter and contain only letters, numbers, and underscores.'],
            // `handle` and `cssClass` are varchar(255) columns (see the
            // Install migration); without an explicit max, an over-long
            // value reached the database as an integrity error (or, on a
            // non-strict MySQL, was silently truncated into a *different*
            // handle than the one the user typed) instead of a field error.
            [['handle', 'cssClass'], 'string', 'max' => 255],
            [['description'], 'string'],
            [['enabled'], 'boolean'],
            [['sortOrder'], 'integer'],
            [['maxDepth'], 'integer', 'min' => 1, 'max' => 10],
            [['fieldLayoutId'], 'integer'],
            [['htmlAttributes'], 'validateHtmlAttributes', 'skipOnEmpty' => false],
            [['siteIds'], 'validateSiteIds', 'skipOnEmpty' => false],
            [['siteIds'], 'safe'],
            [['htmlAttributes', 'settings'], 'safe'],
        ];
    }

    /**
     * Same defense-in-depth as MenuBuilderItem::validateHtmlAttributes() —
     * a group's htmlAttributes bag is rendered onto a <nav>/wrapper element
     * by downstream templates too, so it needs the same protection against
     * event-handler-shaped keys and javascript: values.
     */
    public function validateHtmlAttributes(): void
    {
        if (!is_array($this->htmlAttributes)) {
            $this->addError('htmlAttributes', 'Invalid attributes.');

            return;
        }

        foreach (LinkAttributeHelper::validateHtmlAttributes($this->htmlAttributes) as $error) {
            $this->addError('htmlAttributes', $error);
        }
    }

    /**
     * Craft's field layout plumbing, attached as a behavior so this model
     * answers `getFieldLayout()` / `setFieldLayout()` the same way every
     * other layout-owning Craft model does — which is what lets the CP's
     * `fieldLayoutDesignerField` macro and `Fields::assembleLayoutFromPost()`
     * work against it without a single line of adapter code.
     *
     * `elementType` names the element the layout's content is stored on, so
     * the designer offers the right field settings and Craft's own
     * field-usage lookups can find this layout.
     */
    public function behaviors(): array
    {
        return [
            'fieldLayout' => [
                'class' => FieldLayoutBehavior::class,
                'elementType' => MenuBuilderItemContent::class,
                'idAttribute' => 'fieldLayoutId',
            ],
        ];
    }

    /**
     * This menu's field layout, always as an object — a menu that has never
     * had one gets an empty layout rather than null, so the designer and
     * every caller below have something to render and to save. Only the
     * *id* distinguishes "saved" from "not yet".
     *
     * @see FieldLayoutBehavior::getFieldLayout()
     */
    public function getFieldLayout(): FieldLayout
    {
        /** @var FieldLayoutBehavior $behavior */
        $behavior = $this->getBehavior('fieldLayout');

        return $behavior->getFieldLayout();
    }

    /**
     * Required by FieldLayoutProviderInterface (via `Grippable`). Craft uses
     * it to name the layout's owner on the field-usage screens — "used by
     * the *main* menu" rather than by an anonymous layout id.
     *
     * A method beside the public `$handle` property rather than a rename:
     * `$group->handle` is read from Twig, the controllers and every service,
     * and the property keeps answering those unchanged.
     */
    public function getHandle(): ?string
    {
        return $this->handle !== '' ? $this->handle : null;
    }

    /**
     * Replaces this menu's field layout.
     *
     * `$fieldLayoutId` is only overwritten when the incoming layout carries
     * an id of its own. The CP posts a freshly assembled layout with no id
     * on every save, and clobbering the stored one with null would strand
     * the row this menu already owns — the save would then write a *second*
     * layout and leave the first behind, unreachable, holding the content
     * every existing item is still pointing at.
     */
    public function setFieldLayout(FieldLayout $fieldLayout): void
    {
        /** @var FieldLayoutBehavior $behavior */
        $behavior = $this->getBehavior('fieldLayout');
        $behavior->setFieldLayout($fieldLayout);

        if ($fieldLayout->id !== null) {
            $this->fieldLayoutId = $fieldLayout->id;
        }
    }

    /**
     * Whether this menu offers its items any custom fields at all.
     *
     * A layout row can exist while holding no fields — an editor who adds a
     * field and then removes it again leaves one behind — so this asks the
     * layout what is *in* it rather than whether it exists. Every caller
     * that would otherwise create a content element, render an empty fields
     * tab, or run an extra query for a menu with no fields checks this
     * first.
     */
    public function hasCustomFields(): bool
    {
        return $this->fieldLayoutId !== null && $this->getFieldLayout()->getCustomFields() !== [];
    }

    /**
     * Site IDs are posted from a checkbox list, so reject anything that
     * isn't a list of positive integers rather than silently casting — the
     * same shape check MenuBuilderItem applies to its `site` rule config.
     */
    public function validateSiteIds(): void
    {
        if (!is_array($this->siteIds)) {
            $this->addError('siteIds', 'Invalid sites.');

            return;
        }

        foreach ($this->siteIds as $siteId) {
            if (!is_int($siteId) || $siteId < 1) {
                $this->addError('siteIds', 'Invalid sites.');

                return;
            }
        }
    }

    /**
     * Whether this menu is available on the given site. An empty
     * restriction list means "every site"; a null current site (console
     * requests, uninstalled Craft) can't be matched against a restriction,
     * so a restricted menu is unavailable there — same conservative
     * behaviour the item-level SiteRule inverts by design (it passes when
     * there's no site to compare), except here the restriction is an
     * explicit availability boundary for the whole menu.
     */
    public function isAvailableForSite(?int $siteId): bool
    {
        if (empty($this->siteIds)) {
            return true;
        }

        return $siteId !== null && in_array($siteId, $this->siteIds, true);
    }

    /**
     * This menu's custom HTML attributes as the bundled `renderNav()` macro
     * emits them: re-checked at render time and stripped of anything unsafe
     * or reserved, exactly as {@see \Tahadudhiya\MenuBuilder\models\MenuBuilderNode::safeHtmlAttributes()}
     * does for an item. `$htmlAttributes` remains the stored bag.
     *
     * @return array<string,string>
     */
    public function safeHtmlAttributes(): array
    {
        return LinkAttributeHelper::filterHtmlAttributes($this->htmlAttributes);
    }

    /**
     * Whether a node at the given 1-based nesting level (1 = top level) is
     * still within this group's configured maximum depth. Always true when
     * no maximum is configured.
     */
    public function allowsDepth(int $level): bool
    {
        return $this->maxDepth === null || $level <= $this->maxDepth;
    }
}
