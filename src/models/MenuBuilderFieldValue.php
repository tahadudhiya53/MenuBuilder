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
 * The value a {@see \Tahadudhiya\MenuBuilder\fields\MenuBuilderField} hands
 * to Twig — `entry.navigation`.
 *
 * A **stable object**, never a raw record and never a bare handle string
 * (rule: nothing in this plugin exposes database rows to templates). What it
 * carries is the selection; what it resolves, lazily, is the menu:
 *
 *     {% set nav = entry.navigation %}
 *     {% if nav %}
 *       {{ menuMacros.renderNav(nav.tree) }}
 *     {% endif %}
 *
 * It is iterable and countable over the resolved tree's top-level nodes, so
 * the common case needs no `.tree` at all — exactly the ergonomics
 * {@see MenuBuilderTree} gives `craft.menuBuilder.get()`:
 *
 *     {% for item in entry.navigation %}...{% endfor %}
 *
 * ## Truthiness, and the three ways a value can be "nothing"
 *
 * The field normalizes to `null` when the author selected nothing, so
 * `{% if entry.navigation %}` answers the question templates actually ask.
 * A value object therefore always represents a real selection, which can
 * still fail to render for three distinguishable reasons:
 *
 * | | `exists()` | `tree` | meaning |
 * |---|---|---|---|
 * | menu deleted | `false` | `null` | the selection outlived the menu |
 * | menu disabled / not on this site | `true` | `null` | selection fine, menu not rendering here |
 * | menu resolves | `true` | tree | normal |
 *
 * Iterating yields nothing in all three, so a template that only loops needs
 * none of this.
 *
 * ## Resolution is lazy, and goes through the one resolver
 *
 * Nothing is resolved on construction: an element index listing 100 entries
 * normalizes 100 values and must not resolve 100 menus. The tree comes from
 * {@see \Tahadudhiya\MenuBuilder\services\MenuBuilderResolver}, the single
 * path every other caller uses, so a field-rendered menu is cached,
 * visibility-filtered and active-state-marked identically to
 * `craft.menuBuilder.get()` — and is memoized per instance, so rendering a
 * menu and then its breadcrumbs costs one resolve.
 */
class MenuBuilderFieldValue implements IteratorAggregate, Countable, JsonSerializable, Stringable
{
    private bool $treeLoaded = false;

    private ?MenuBuilderTree $tree = null;

    public function __construct(
        /** The selected menu's UID — the identity the field persists. */
        public readonly ?string $groupUid,
        private readonly ?MenuBuilderGroup $group = null,
        /**
         * The site of the element this value was read from, used by
         * {@see isAvailableForSite()}. Null when the value was normalized
         * without an element (a field-only context, a console script).
         */
        public readonly ?int $siteId = null,
        /**
         * Test seam only: `fn(string $handle, ?string $currentUri) => ?MenuBuilderTree`.
         * Defaults to the plugin's resolver. Injected rather than mocked so
         * this object's laziness, memoization and null-handling are testable
         * without a booted Craft application, the same way
         * {@see MenuBuilderResolver::internalHosts()} is kept static.
         */
        private readonly ?Closure $treeResolver = null,
    ) {
    }

    /** The selected menu's configuration, or null when it no longer exists. */
    public function getGroup(): ?MenuBuilderGroup
    {
        return $this->group;
    }

    /** Whether the selected menu still exists. */
    public function exists(): bool
    {
        return $this->group !== null;
    }

    /**
     * The menu's handle — the same string `craft.menuBuilder.get()` takes,
     * for templates that want to hand the selection to another API.
     */
    public function getHandle(): ?string
    {
        return $this->group?->handle;
    }

    /** The menu's editor-facing name, for labels and CP previews. */
    public function getName(): ?string
    {
        return $this->group?->name;
    }

    /** Whether the menu is enabled. A disabled menu resolves to no tree. */
    public function isEnabled(): bool
    {
        return $this->group !== null && $this->group->enabled;
    }

    /**
     * Whether the selected menu is available on the *element's* site — the
     * site mismatch the field's validation reports when the field is
     * translatable. This asks about the element's site, not the site being
     * rendered; {@see getTree()} answers the latter.
     */
    public function isAvailableForSite(): bool
    {
        return $this->group !== null && $this->group->isAvailableForSite($this->siteId);
    }

    /**
     * The resolved menu, or null when the selection can't render (deleted,
     * disabled, or unavailable on the site being rendered).
     *
     * Resolved for the site of the **current request**, not for
     * `$this->siteId` — that is the site whose links, titles and cache entry
     * a page is being built from, and the resolver is site-scoped through
     * the current site by design (see ARCHITECTURE.md "Caching"). The two
     * differ only when a template renders an element fetched from another
     * site; see "Known limitations".
     *
     * @param string|null $currentUri Overrides the page active state is marked against,
     *                                exactly as `craft.menuBuilder.get()` does. Passing one
     *                                bypasses the memoized tree, since the memoized one was
     *                                marked against a different page.
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
     * The resolved tree's top-level nodes, so `{% for item in entry.navigation %}`
     * works without `.tree`. Empty — never an error — when the menu can't
     * resolve.
     *
     * @return Traversable<int,MenuBuilderNode>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->getTree()?->items ?? []);
    }

    /** How many top-level nodes the menu resolves to; 0 when it can't resolve. */
    public function count(): int
    {
        return count($this->getTree()?->items ?? []);
    }

    /**
     * The menu's name, so `{{ entry.navigation }}` prints something an editor
     * recognises rather than a UID or an object-to-string error. Empty for a
     * selection whose menu is gone — there is no name left to print.
     */
    public function __toString(): string
    {
        return $this->getName() ?? '';
    }

    /**
     * The selection, not the resolved menu: a tree is per-site, per-visitor
     * and per-page, so serializing one would bake a particular request into
     * whatever consumed it. Consumers that want the menu resolve it.
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
