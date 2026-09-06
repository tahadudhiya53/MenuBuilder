<?php

namespace Tahadudhiya\MenuBuilder\services;

use Craft;
use craft\base\Component;
use Tahadudhiya\MenuBuilder\elements\MenuBuilderItemContent;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Throwable;

/**
 * The one place that creates, loads, copies and destroys the {@see MenuBuilderItemContent} elements
 * behind menu items' custom fields.
*/
class MenuBuilderItemContentService extends Component
{
    /** @var array<int,true> Content IDs registered by preload() and not yet fetched. */
    private array $pending = [];

    /** @var array<int,MenuBuilderItemContent> Fetched content, by ID. */
    private array $loaded = [];

    /**
     * @var array<int,true> IDs that were asked for and did not come back — see loadPending().
    */
    private array $missing = [];

    /**
     * The content element for one item, or null when there is nothing to edit — either the menu
     * has no fields, or the item has no content row yet and the menu still has none.
    */
    public function contentForItem(MenuBuilderItem $item): ?MenuBuilderItemContent
    {
        $group = $item->groupId !== null
            ? MenuBuilder::getInstance()->groups->getById($item->groupId)
            : null;

        if ($group === null || !$group->hasCustomFields()) {
            return null;
        }

        if ($item->contentId !== null) {
            $content = $this->getById($item->contentId);

            if ($content !== null) {
                // Re-point at the menu's *current* layout.
                $content->fieldLayoutId = $group->fieldLayoutId;

                return $content;
            }
        }

        return $this->newContent($group->fieldLayoutId);
    }

    /**
     * Persists an item's content element and points the item at it.
    */
    public function save(MenuBuilderItem $item): bool
    {
        $content = $item->getContent();

        if ($content === null) {
            return true;
        }

        // Search indexing is off: this element has no title, no URL and no element index to be
        // found in, so indexing it would add rows to `searchindex` that nothing can ever query.
        if (!Craft::$app->getElements()->saveElement($content, true, false, false)) {
            foreach ($content->getErrors() as $attribute => $errors) {
                foreach ($errors as $error) {
                    $item->addError('fields.' . $attribute, $error);
                }
            }

            return false;
        }

        $item->contentId = $content->id;

        return true;
    }

    /**
     * Validates an item's field content without saving it, so the CP editor can reject a bad Matrix
     * block at the same moment it rejects a bad URL rather than after the item row is already
     * written.
    */
    public function validateContent(MenuBuilderItem $item): bool
    {
        $content = $item->getContent();

        if ($content === null) {
            return true;
        }

        $content->setScenario(MenuBuilderItemContent::SCENARIO_LIVE);

        if ($content->validate()) {
            return true;
        }

        foreach ($content->getErrors() as $attribute => $errors) {
            foreach ($errors as $error) {
                $item->addError('fields.' . $attribute, $error);
            }
        }

        return false;
    }

    /**
     * A copy of `$sourceContentId`'s field values as a brand-new element, for a duplicated item.
    */
    public function duplicateContent(?int $sourceContentId, ?int $fieldLayoutId): ?int
    {
        if ($sourceContentId === null || $fieldLayoutId === null) {
            return null;
        }

        $source = $this->getById($sourceContentId);

        if ($source === null) {
            return null;
        }

        // Craft's own duplicator, not a hand-rolled field copy: it is what knows to re-own nested
        // elements (Matrix blocks) to the new element rather than leaving two owners pointing at
        // one block.
        try {
            $clone = Craft::$app->getElements()->duplicateElement($source, [
                'fieldLayoutId' => $fieldLayoutId,
            ]);
        } catch (Throwable $exception) {
            Craft::warning('Failed to duplicate navigation item content: ' . $exception->getMessage(), __METHOD__);

            return null;
        }

        return $clone->id;
    }

    /**
     * Hard-deletes content elements by ID.
     *
     * @param int[] $contentIds
    */
    public function deleteByIds(array $contentIds): void
    {
        foreach (array_unique(array_filter($contentIds)) as $contentId) {
            $content = $this->getById((int)$contentId);

            if ($content === null) {
                continue;
            }

            try {
                Craft::$app->getElements()->deleteElement($content, true);
            } catch (Throwable $exception) {
                Craft::warning('Failed to delete navigation item content: ' . $exception->getMessage(), __METHOD__);
            }

            unset($this->loaded[(int)$contentId]);
        }
    }

    /**
     * Registers content IDs the current request is about to read values from.
     *
     * @param int[] $contentIds
    */
    public function preload(array $contentIds): void
    {
        foreach ($contentIds as $contentId) {
            $contentId = (int)$contentId;

            if ($contentId > 0 && !isset($this->loaded[$contentId]) && !isset($this->missing[$contentId])) {
                $this->pending[$contentId] = true;
            }
        }
    }

    /**
     * One field value for one content element, or `$default` when the element is gone, the menu no
     * longer defines that handle, or the value is empty.
    */
    public function valueFor(?int $contentId, string $handle, mixed $default = null): mixed
    {
        if ($contentId === null) {
            return $default;
        }

        $content = $this->getById($contentId);

        if ($content === null || $content->getFieldLayout()?->getFieldByHandle($handle) === null) {
            return $default;
        }

        $value = $content->getFieldValue($handle);

        return $value === null ? $default : $value;
    }

    /**
     * Whether a content element holds a non-empty value for the given handle.
    */
    public function hasValueFor(?int $contentId, string $handle): bool
    {
        if ($contentId === null) {
            return false;
        }

        $content = $this->getById($contentId);
        $field = $content?->getFieldLayout()?->getFieldByHandle($handle);

        if ($content === null || $field === null) {
            return false;
        }

        return !$field->isValueEmpty($content->getFieldValue($handle), $content);
    }

    /**
     * The handles the given content element can answer to — what a template or a GraphQL query
     * iterates when it wants "every custom field on this node" without naming them.
     *
     * @return string[]
    */
    public function handlesFor(?int $contentId): array
    {
        if ($contentId === null) {
            return [];
        }

        $layout = $this->getById($contentId)?->getFieldLayout();

        return $layout === null
            ? []
            : array_map(static fn($field) => $field->handle, $layout->getCustomFields());
    }

    /**
     * One content element's field values in their **serialized** form — what each field defines
     * as its storage shape.
     *
     * @return array<string,mixed>
    */
    public function serializedValuesFor(?int $contentId): array
    {
        if ($contentId === null) {
            return [];
        }

        $content = $this->getById($contentId);

        if ($content === null) {
            return [];
        }

        $values = [];

        foreach ($content->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $values[$field->handle] = $field->serializeValue($content->getFieldValue($field->handle), $content);
        }

        return $values;
    }

    /**
     * A new, unsaved content element on the given layout.
    */
    private function newContent(?int $fieldLayoutId): MenuBuilderItemContent
    {
        $content = new MenuBuilderItemContent();
        $content->fieldLayoutId = $fieldLayoutId;
        $content->siteId = Craft::$app->getSites()->getPrimarySite()->id;

        return $content;
    }

    private function getById(int $contentId): ?MenuBuilderItemContent
    {
        if (isset($this->loaded[$contentId])) {
            return $this->loaded[$contentId];
        }

        if (isset($this->missing[$contentId])) {
            return null;
        }

        // Whatever else is pending comes along for the ride: a template that reads one node's field
        // is about to read every other node's too.
        $this->pending[$contentId] = true;
        $this->loadPending();

        return $this->loaded[$contentId] ?? null;
    }

    private function loadPending(): void
    {
        if ($this->pending === []) {
            return;
        }

        $ids = array_keys($this->pending);
        $this->pending = [];

        /** @var MenuBuilderItemContent[] $elements */
        $elements = MenuBuilderItemContent::find()
            ->id($ids)
            ->status(null)
            ->limit(null)
            ->all();

        foreach ($elements as $element) {
            $this->loaded[(int)$element->id] = $element;
        }

        // Absence is recorded, for the same reason the link resolver's preload records it: an ID
        // that doesn't come back (deleted element, stale cache entry) must not send every
        // subsequent read back to the database to be told the same thing again.
        foreach ($ids as $id) {
            if (!isset($this->loaded[$id])) {
                $this->missing[$id] = true;
            }
        }
    }
}
