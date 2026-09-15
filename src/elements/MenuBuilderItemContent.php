<?php

namespace Tahadudhiya\MenuBuilder\elements;

use Craft;
use craft\base\Element;
use craft\elements\User;
use Tahadudhiya\MenuBuilder\MenuBuilder;

/**
 * The element half of a navigation item — the row Craft's own field layout machinery needs in
 * order to exist.
*/
class MenuBuilderItemContent extends Element
{
    public static function displayName(): string
    {
        return Craft::t('menubuilder', 'Navigation Item Content');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('menubuilder', 'navigation item content');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('menubuilder', 'Navigation Item Content');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('menubuilder', 'navigation item content');
    }

    /**
     * No reference tag.
    */
    public static function refHandle(): ?string
    {
        return null;
    }

    public static function hasTitles(): bool
    {
        return false;
    }

    public static function hasUris(): bool
    {
        return false;
    }

    public static function hasStatuses(): bool
    {
        return false;
    }

    /**
     * Content is site-agnostic, exactly as the custom field values this replaces were: one set of
     * values per item, shared by every site the menu renders on.
    */
    public static function isLocalized(): bool
    {
        return false;
    }

    /**
     * Never tracked for changes: this element has no draft, no revision and no author, so a
     * changed-fields log for it would only ever record the navigation item's own save a second
     * time.
    */
    public static function trackChanges(): bool
    {
        return false;
    }

    /**
     * The item this content belongs to, or null when it has been stranded (see the class docblock
     * — garbage collection sweeps those).
    */
    public function getItem(): ?\Tahadudhiya\MenuBuilder\models\MenuBuilderItem
    {
        return $this->id === null
            ? null
            : MenuBuilder::getInstance()->items->getByContentId($this->id);
    }

    /**
     * Content is reachable only through the navigation item editor, which runs its own permission
     * check in BaseMenuBuilderController before this element is ever loaded.
    */
    public function canView(User $user): bool
    {
        return $user->admin || $user->can('menuBuilder:view');
    }

    public function canSave(User $user): bool
    {
        return $user->admin || $user->can('menuBuilder:edit');
    }

    public function canDelete(User $user): bool
    {
        return $user->admin || $user->can('menuBuilder:delete');
    }

    public function canCreateDrafts(User $user): bool
    {
        return false;
    }
}
