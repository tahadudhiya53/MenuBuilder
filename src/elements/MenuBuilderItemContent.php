<?php

namespace Tahadudhiya\MenuBuilder\elements;

use Craft;
use craft\base\Element;
use craft\elements\User;
use Tahadudhiya\MenuBuilder\MenuBuilder;

/**
 * The element half of a navigation item — the row Craft's own field layout
 * machinery needs in order to exist.
 *
 * ## Why an item is not itself an element
 *
 * Craft 5 stores custom field content in `elements_sites.content`, keyed by
 * field UID, and every relational field (Matrix included) hangs its nested
 * rows off an **owner element id**. So "give menu items real Craft fields"
 * means "menu items need a row in `elements`" — but it does *not* mean
 * `MenuBuilderItem` has to be that row, and it must not be:
 *
 *   - `menubuilder_items.parentId` carries a self-referencing
 *     `ON DELETE CASCADE` (see migrations/Install.php). Deleting an item
 *     deletes its whole subtree in the database, in one statement, with no
 *     PHP-side sweep. If `menubuilder_items.id` were an FK to `elements.id`,
 *     that cascade would delete item rows behind Craft's back and leave
 *     their `elements` rows — and every Matrix block owned by them —
 *     stranded, because Craft only tears an element down through
 *     `Elements::deleteElement()`.
 *   - Every write path in MenuBuilderItemService is raw ActiveRecord inside
 *     a row-locked transaction (`applySortOrders()`, `bulkSetEnabled()`,
 *     `duplicateRecord()`). Element saves cannot run inside those without
 *     re-deriving the locking and hierarchy invariants ARCHITECTURE.md
 *     documents.
 *
 * So the item keeps its table, its cascade and its hierarchy logic, and
 * points at one of these for its content — the same split Craft itself uses
 * for {@see \craft\elements\Address}: an element that exists to carry a
 * field layout on someone else's behalf.
 *
 * ## No table of its own
 *
 * This element stores nothing outside the rows Craft already writes for it.
 * Its field layout is resolved from `elements.fieldLayoutId`, which
 * {@see Element::getFieldLayout()} already reads and
 * {@see \craft\elements\db\ElementQuery} already selects — so a content
 * element is one `elements` row, one `elements_sites` row, and no plugin
 * schema at all. The layout id is written from the owning menu on every
 * save ({@see MenuBuilderItemService::saveContent()}), which is what keeps
 * an item pointed at its menu's *current* layout.
 *
 * ## Lifecycle
 *
 * Created lazily — an item only gets one once its menu has a field layout.
 * Deleted explicitly when its item is deleted, and swept by
 * {@see MenuBuilder::registerGarbageCollection()} when the `parentId`
 * cascade removes an item row without passing through PHP.
 *
 * It is deliberately invisible: no element index, no sources, no URLs, no
 * statuses, no search index, no revisions. Editors only ever meet it as the
 * fields tab inside the navigation item editor.
 */
class MenuBuilderItemContent extends Element
{
    public static function displayName(): string
    {
        return Craft::t('menu-builder', 'Navigation Item Content');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('menu-builder', 'navigation item content');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('menu-builder', 'Navigation Item Content');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('menu-builder', 'navigation item content');
    }

    /**
     * No reference tag. `{menubuilderitemcontent:12}` would resolve to an
     * element with no title and no URL, which is not a reference anybody
     * could use — and offering one implies this is a thing to link to.
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
     * Content is site-agnostic, exactly as the custom field values this
     * replaces were: one set of values per item, shared by every site the
     * menu renders on. Making it localizable would be a per-site content
     * feature, which is phase 10's question and not this one's — and
     * switching it on later only *adds* rows, so nothing here forecloses it.
     */
    public static function isLocalized(): bool
    {
        return false;
    }

    /**
     * Never tracked for changes: this element has no draft, no revision and
     * no author, so a changed-fields log for it would only ever record the
     * navigation item's own save a second time.
     */
    public static function trackChanges(): bool
    {
        return false;
    }

    /**
     * The item this content belongs to, or null when it has been stranded
     * (see the class docblock — garbage collection sweeps those).
     */
    public function getItem(): ?\Tahadudhiya\MenuBuilder\models\MenuBuilderItem
    {
        return $this->id === null
            ? null
            : MenuBuilder::getInstance()->items->getByContentId($this->id);
    }

    /**
     * Content is reachable only through the navigation item editor, which
     * runs its own permission check in BaseMenuBuilderController before this
     * element is ever loaded. These mirror that check rather than inventing
     * a second permission set, so there is one answer to "who may edit a
     * navigation item" and not two that can drift apart.
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
