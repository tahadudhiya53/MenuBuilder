# MenuBuilder for Craft CMS 5

Advanced, flexible navigation management for Craft CMS 5 — multiple menus, drag-and-drop
hierarchy, mixed link types, non-clickable headings, mega menus, dynamic (auto-generated)
navigation, per-item visibility rules, and a small, stable Twig API.

- **Requires:** Craft CMS ^5.0, PHP >= 8.2
- **License:** MIT
- **Handle:** `menu-builder`

---

## Feature list

Everything below is implemented and shipping in 1.0.0.

### Menus (groups)

| Feature | What it does |
|---|---|
| Multiple menus | Any number of named navigations (Main, Footer, Utility…), each with its own handle |
| Enable / disable | A disabled menu returns no tree to Twig; togglable inline from the menus list |
| Max nesting depth | Optional 1–10 cap, enforced server-side on every move, not just in the UI |
| Site restriction | Restrict a whole menu to selected sites (multi-site installs) |
| CSS class + HTML attributes | Rendered onto your `<nav>`/wrapper; attributes are validated server-side |
| Description | Internal note for editors |
| Duplicate | Clone a menu — its settings **and** all its items, in one transaction, with a unique handle |
| Project config | Menu structure/settings are mirrored into `project.yaml` and applied on deploy |

### Menu items

| Feature | What it does |
|---|---|
| 8 item types | Entry, Category, Asset, Custom URL, Anchor, Non-clickable heading, Separator, Dynamic navigation |
| Element links resolved live | Entry/category/asset URLs and titles are re-resolved per request per site — never a stored URL that can go stale |
| Title fallback | Leave the title blank on an element-backed item to inherit the linked element's own title |
| Explicit "clickable" flag | A heading is a label or a link because you said so — never inferred from whether a URL happens to be set |
| Broken-link fallback behaviour | Per item: hide it, keep it but drop the link, or fall back to a URL |
| Open in new tab | `target="_blank"`, with `rel="noopener"` merged in automatically (your own `rel` values are kept) |
| `rel` presets + custom | `nofollow`, `sponsored` checkboxes plus a free-form `rel` field |
| Anchor links | `#fragment` links, validated (no spaces/quotes/angle brackets) |
| Appearance fields | Icon, badge, description, image, "featured" flag, CSS class |
| Accessibility fields | ARIA label, `title` attribute; `aria-current="page"` on the active item |
| Custom HTML attributes | Arbitrary `data-*`/HTML attributes, validated against event-handler keys and `javascript:` values |
| Enable / disable | Per item, inline from the row menu |
| Duplicate | Clones the item *and its whole subtree* in one transaction |
| Orphaned element warning | A "Linked element unavailable" badge when a linked entry/category/asset was hard-deleted |

### Visibility rules

Per item, combined with **AND**. A misconfigured or unknown rule **fails closed** (hides the item)
rather than leaking gated navigation.

- `loggedIn` — only signed-in users
- `loggedOut` — only anonymous visitors
- `userGroup` — restrict to selected user groups
- `site` — restrict to selected sites
- `dateRange` — visible from / until, evaluated in the app's configured timezone
- `environment` — restrict to named environments (matches `CRAFT_ENVIRONMENT`)
- `always` — explicit no-op

### Mega menus

Mark any item as a mega-menu parent (1–6 columns) and assign each child to a column. No second
tree, no separate item type — it's presentation layered onto the hierarchy you already built.
`node.megaMenu` and `node.megaMenuColumns()` expose it in Twig, and the bundled macro ships an
accessible disclosure-based renderer.

### Dynamic navigation

A `dynamic` item synthesises its children at render time from a source instead of storing rows:

- Source: entries by section, categories by group, or assets by volume
- Limit (hard-capped at 50 server-side) and order (newest, oldest, title A–Z, title Z–A — a fixed whitelist, never editor SQL)
- Scoped to the current site and to normally-visible elements only
- Cached and invalidated like static content, including when a brand-new element should now appear

### Control panel

- Drag-and-drop tree: reorder and reparent in one gesture, with a live drop-position indicator and max-depth enforcement
- Slide-out item editor (with a full-page URL as a deep-link/no-JS fallback)
- Quick-add panel with a "Nest under" parent picker
- Search/filter within a menu (keeps matching items' ancestors)
- Bulk enable / disable / delete with a selection toolbar
- Child-count badges, disabled badges, mega-menu and orphaned-element badges
- Five granular permissions (below)

### Developer surface

- `craft.menuBuilder.get()` / `.getGroup()` / `.getItem()`
- Optional recursive render macros (`render`, `renderMegaMenu`)
- Two extension events: register a link type, register a visibility rule
- Targeted caching: per menu, per site, invalidated on the exact change that affects it

---

## Installation

From your Craft project root:

```sh
composer require tahadudhiya/craft-menu-builder
php craft plugin/install menu-builder
```

Or install **MenuBuilder** from the Plugin Store, then **Settings → Plugins**.

Installing creates two tables, `menubuilder_groups` and `menubuilder_items`. Uninstalling drops them.

---

## Quick start

1. Go to **MenuBuilder → Menus** in the control panel and click **New group**.
2. Give it a name and handle (e.g. `Main Navigation` / `main`), optionally set a max depth and site restriction, and save.
3. Open the menu and add items with **+ Add Menu Item**, then drag them into shape.
4. Render it:

```twig
{% set menu = craft.menuBuilder.get('main') %}

{% if menu %}
    <nav class="{{ menu.group.cssClass }}">
        <ul>
            {% for node in menu %}
                <li class="{{ node.isActiveOrAncestor() ? 'is-active' : '' }}">
                    {% if node.isClickable %}
                        <a href="{{ node.url }}" target="{{ node.target }}"
                           {% if node.rel %}rel="{{ node.rel }}"{% endif %}
                           {% if node.isActive %}aria-current="page"{% endif %}>{{ node.title }}</a>
                    {% else %}
                        <span>{{ node.title }}</span>
                    {% endif %}

                    {% if node.hasChildren() %}
                        {# recurse, or use the bundled macro below #}
                    {% endif %}
                </li>
            {% endfor %}
        </ul>
    </nav>
{% endif %}
```

Prefer not to hand-write markup? Use the bundled macro:

```twig
{% import "menu-builder/_macros/tree" as menuMacros %}
{{ menuMacros.render(craft.menuBuilder.get('main')) }}
```

`render()` calls `renderMegaMenu()` automatically for any mega-menu-enabled node. The macros are a
starting point, not a requirement — copy them into your own templates and edit freely.

---

## Twig API

### `craft.menuBuilder.get(handle, currentUri = null)`

Returns a `MenuBuilderTree`, or `null` when the menu doesn't exist, is disabled, or isn't
available on the current site. Pass `currentUri` to override active-state matching (defaults to
the current request URI).

The tree is directly iterable and countable over its **top-level** nodes:

| Member | Description |
|---|---|
| `{% for node in menu %}` | Top-level nodes |
| `menu.items` | The same nodes, explicitly |
| `menu.group` | The `MenuBuilderGroup` (name, handle, cssClass, htmlAttributes, maxDepth, settings) |
| `menu.flatten()` | Depth-first flat list of every node — handy for finding the active one without recursing |
| `menu|length` | Top-level node count |

### Node properties

`MenuBuilderNode` is the only object templates should treat as public and stable. It carries no
database ids to join and no internal columns.

| Property | Notes |
|---|---|
| `id`, `handle`, `type`, `title` | `title` already includes the element-title fallback |
| `url` | `null` for headings/separators and unresolvable links |
| `isClickable` | True only when the type is linkable, the editor marked it clickable, **and** a URL resolved |
| `isLinkAvailable` | False when a linked element is missing/disabled/unpublished |
| `target`, `rel` | `rel` already includes `noopener` for new-tab links |
| `cssClass`, `htmlId`, `htmlAttributes` | Editor-supplied, server-validated |
| `ariaLabel`, `titleAttribute` | Accessibility |
| `icon`, `badge`, `description`, `image`, `featured` | Presentation extras; `image` is an asset ID |
| `level` | 1-based depth |
| `children`, `hasChildren()`, `parent` | Hierarchy; dynamic children are merged into `children` transparently |
| `isActive`, `isActiveAncestor`, `isActiveOrAncestor()` | Per-request, never cached |
| `megaMenu`, `megaMenuColumns()` | `megaMenu` is null unless this node is a mega-menu parent; `megaMenuColumns()` returns `{column: nodes}` |
| `megaMenuColumn` | This node's column under its mega-menu parent |
| `isDynamic` | True for nodes synthesised from a dynamic source |

### Secondary accessors

```twig
{% set group = craft.menuBuilder.getGroup('main') %}  {# settings only, no tree #}
{% set item  = craft.menuBuilder.getItem(42) %}       {# raw, unresolved item — admin/debug use #}
```

### Mega menu example

```twig
{% for node in craft.menuBuilder.get('main') %}
    {% if node.megaMenu %}
        <div class="mega" style="--columns: {{ node.megaMenu.columns }}">
            {% for column, nodes in node.megaMenuColumns() %}
                <ul class="mega-column">
                    {% for child in nodes %}<li><a href="{{ child.url }}">{{ child.title }}</a></li>{% endfor %}
                </ul>
            {% endfor %}
        </div>
    {% endif %}
{% endfor %}
```

---

## Permissions

| Permission | Grants |
|---|---|
| `menuBuilder:view` | See the MenuBuilder section and menu trees |
| `menuBuilder:create` | Create new menu items (and duplicate items) |
| `menuBuilder:edit` | Edit, reorder, enable/disable existing items |
| `menuBuilder:delete` | Delete menus and menu items |
| `menuBuilder:manageSettings` | Create, edit, and duplicate menus (groups) |

Admins bypass all five. Every control-panel action is permission-checked server-side, requires a
CP request, and state changes require POST with Craft's CSRF token.

---

## Extending

### A custom link type

```php
use Tahadudhiya\MenuBuilder\services\MenuBuilderLinkResolver;
use Tahadudhiya\MenuBuilder\events\RegisterLinkTypesEvent;

Event::on(
    MenuBuilderLinkResolver::class,
    MenuBuilderLinkResolver::EVENT_REGISTER_LINK_TYPES,
    function(RegisterLinkTypesEvent $event) {
        $event->resolvers['product'] = new MyProductLinkResolver();
    }
);
```

A resolver implements `LinkTypeResolverInterface::resolve(MenuBuilderItem $item): ResolvedLink`.

### A custom visibility rule

```php
use Tahadudhiya\MenuBuilder\services\MenuBuilderVisibilityService;
use Tahadudhiya\MenuBuilder\events\RegisterVisibilityRulesEvent;

Event::on(
    MenuBuilderVisibilityService::class,
    MenuBuilderVisibilityService::EVENT_REGISTER_VISIBILITY_RULES,
    function(RegisterVisibilityRulesEvent $event) {
        $event->rules['abTest'] = new MyAbTestRule();
    }
);
```

A rule implements `VisibilityRuleInterface::passes(array $config, VisibilityContext $context): bool`.
Unknown rule types fail closed, so a rule that isn't registered hides its item rather than showing it.

---

## Caching

Resolved menus are cached per **menu handle + site**, and only the link-resolution pass is cached —
visibility filtering and active-state marking always run fresh per request, so nothing user-specific
or date-specific is ever shared between visitors.

Invalidation is targeted rather than a blanket flush:

- Saving/moving/reordering/duplicating/deleting a menu or item invalidates that menu only.
- Saving, deleting, or restoring an entry/category/asset invalidates only the menus that actually
  link to it (one indexed lookup), plus any menu containing a dynamic item.
- Draft, revision, and provisional-draft element saves are ignored on purpose — unpublished edits
  never disturb live navigation.

No manual cache clearing is needed in normal use.

---

## Project config

Menus (groups) are mirrored into `project.yaml` under `menuBuilder.groups.<uid>`, so menu structure
and settings are diffable and deployable like sections and fields. Menu **items** are deliberately
*not* in project config — like entries, they're per-environment content.

---

## Development

```sh
composer test        # PHPUnit — 113 unit tests
composer check-cs    # ECS (no ecs.php checked in yet)
composer phpstan     # PHPStan (no phpstan.neon checked in yet)
```

The unit suite covers pure logic without booting Craft: link resolvers, visibility rules and
context, mega-menu grouping, validation, permission mapping, cache keys, and link-attribute
helpers. Code that needs a live Craft app/DB (`ElementLinkResolver`, `MenuBuilderElementService`,
`MenuBuilderDynamicNavigationService`, project-config handlers) is verified manually — see
[ARCHITECTURE.md](ARCHITECTURE.md#known-limitations).

Internals, invariants, and design decisions: **[ARCHITECTURE.md](ARCHITECTURE.md)**.
Release history: **[CHANGELOG.md](CHANGELOG.md)**.
