<?php

namespace Tahadudhiya\MenuBuilder\models;

use ArrayIterator;
use Closure;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Stringable;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Traversable;

/**
 * The value a {@see \Tahadudhiya\MenuBuilder\fields\MenuBuilderField} hands to Twig —
 * `entry.navigation`.
*/
class MenuBuilderFieldValue implements IteratorAggregate, Countable, JsonSerializable, Stringable
{
    private bool $treeLoaded = false;

    private ?MenuBuilderTree $tree = null;

    public function __construct(
        /**
         * The selected menu's UID — the identity the field persists.
        */
        public readonly ?string $groupUid,
        private readonly ?MenuBuilderGroup $group = null,
        /**
         * The site of the element this value was read from, used by {@see isAvailableForSite()}.
        */
        public readonly ?int $siteId = null,
        /**
         * Test seam only: `fn(string $handle, ?string $currentUri) => ?MenuBuilderTree`.
        */
        private readonly ?Closure $treeResolver = null,
    ) {
    }

    /**
     * The selected menu's configuration, or null when it no longer exists.
    */
    public function getGroup(): ?MenuBuilderGroup
    {
        return $this->group;
    }

    /**
     * Whether the selected menu still exists.
    */
    public function exists(): bool
    {
        return $this->group !== null;
    }

    /**
     * The menu's handle — the same string `craft.menuBuilder.get()` takes, for templates that
     * want to hand the selection to another API.
    */
    public function getHandle(): ?string
    {
        return $this->group?->handle;
    }

    /**
     * The menu's editor-facing name, for labels and CP previews.
    */
    public function getName(): ?string
    {
        return $this->group?->name;
    }

    /**
     * Whether the menu is enabled.
    */
    public function isEnabled(): bool
    {
        return $this->group !== null && $this->group->enabled;
    }

    /**
     * Whether the selected menu is available on the *element's* site — the site mismatch the
     * field's validation reports when the field is translatable.
    */
    public function isAvailableForSite(): bool
    {
        return $this->group !== null && $this->group->isAvailableForSite($this->siteId);
    }

    /**
     * The resolved menu, or null when the selection can't render (deleted, disabled, or unavailable
     * on the site being rendered).
     *
     * @param string|null $currentUri Overrides the page active state is marked against, exactly as `craft.menuBuilder.get()` does.
    */
    public function getTree(?string $currentUri = null): ?MenuBuilderTree
    {
        $handle = $this->getHandle();

        if ($handle === null) {
            return null;
        }

        if ($currentUri !== null) {
            return $this->resolve($handle, $currentUri);
        }

        if (!$this->treeLoaded) {
            $this->tree = $this->resolve($handle, null);
            $this->treeLoaded = true;
        }

        return $this->tree;
    }

    private function resolve(string $handle, ?string $currentUri): ?MenuBuilderTree
    {
        if ($this->treeResolver !== null) {
            return ($this->treeResolver)($handle, $currentUri);
        }

        return MenuBuilder::getInstance()->resolver->getTree($handle, $currentUri);
    }

    /**
     * The resolved tree's top-level nodes, so `{% for item in entry.navigation %}` works without
     * `.tree`.
     *
     * @return Traversable<int,MenuBuilderNode>
    */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->getTree()?->items ?? []);
    }

    /**
     * How many top-level nodes the menu resolves to; 0 when it can't resolve.
    */
    public function count(): int
    {
        return count($this->getTree()?->items ?? []);
    }

    /**
     * The menu's name, so `{{ entry.navigation }}` prints something an editor recognises rather
     * than a UID or an object-to-string error.
    */
    public function __toString(): string
    {
        return $this->getName() ?? '';
    }

    /**
     * The selection, not the resolved menu: a tree is per-site, per-visitor and per-page, so
     * serializing one would bake a particular request into whatever consumed it.
     *
     * @return array{uid: string|null, handle: string|null, name: string|null, exists: bool}
    */
    public function jsonSerialize(): array
    {
        return [
            'uid' => $this->groupUid,
            'handle' => $this->getHandle(),
            'name' => $this->getName(),
            'exists' => $this->exists(),
        ];
    }
}
