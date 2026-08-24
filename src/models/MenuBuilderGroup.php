<?php

namespace Tahadudhiya\MenuBuilder\models;

use craft\base\Model;
use Tahadudhiya\MenuBuilder\helpers\LinkAttributeHelper;

/**
 * A named navigation (e.g. "Main Navigation", "Footer Navigation"). Items
 * belong to exactly one group (see MenuBuilderItem::$groupId) and a parent
 * item must always belong to the same group — cross-group parenting is
 * rejected by MenuBuilderItemService.
 */
class MenuBuilderGroup extends Model
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

    public ?string $uid = null;
    public ?string $dateCreated = null;
    public ?string $dateUpdated = null;

    protected function defineRules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_]*$/', 'message' => 'Handle must start with a letter and contain only letters, numbers, and underscores.'],
            [['description', 'cssClass'], 'string'],
            [['enabled'], 'boolean'],
            [['sortOrder'], 'integer'],
            [['maxDepth'], 'integer', 'min' => 1, 'max' => 10],
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
     * Whether a node at the given 1-based nesting level (1 = top level) is
     * still within this group's configured maximum depth. Always true when
     * no maximum is configured.
     */
    public function allowsDepth(int $level): bool
    {
        return $this->maxDepth === null || $level <= $this->maxDepth;
    }
}
