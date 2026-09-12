# MenuBuilder for Craft CMS 5

Advanced navigation management for Craft CMS 5 — multiple menus, drag-and-drop hierarchy, eight link
types, mega menus, dynamic navigation, per-item visibility rules, and a small, stable Twig API.

Editors build navigation in the control panel — menus, items dragged into a hierarchy, links to
entries, categories, assets or plain URLs — and templates read the result back as plain data with
`craft.menuBuilder.get('main')`. Nothing about a link is frozen at save time: element URLs and titles
resolve per request and per site, so renaming or moving an entry never leaves a stale link behind.
Rendering stays yours — bundled macros give you accessible markup out of the box, and GraphQL and a
REST API serve headless front ends the same tree.

**Release status: 1.0.0 release candidate — not yet tagged or published.** See
[CHANGELOG.md](CHANGELOG.md).

## Documentation

| Where | What's in it |
|---|---|
| This file | Installation, the editor's guide, the developer/API reference, troubleshooting |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Internals: the resolve pipeline, caching, security model, testing and the release process |
| [CHANGELOG.md](CHANGELOG.md) | Release history and known limitations |
| [LICENSE.md](LICENSE.md) | The Craft License |

Jump to: [Install](#install) · [Quick start](#quick-start) · [Editor's guide](#editors-guide) ·
[Templating](#templating) · [Headless and developer](#headless-and-developer) ·
[Where your data lives](#where-your-data-lives) · [Troubleshooting](#troubleshooting)

## Requirements

| | |
|---|---|
| Craft CMS | `^5.0` |
| PHP | `>= 8.2` |
| Database | Whatever your Craft install uses (MySQL or PostgreSQL) |
| Plugin handle | `menu-builder` |
| License | [The Craft License](LICENSE.md) — a commercial plugin |

No other dependencies. GraphQL and the REST API use Craft's own GraphQL schemas and tokens.

## Install

```sh
composer require tahadudhiya/craft-menu-builder
php craft plugin/install menu-builder
```

Or install **MenuBuilder** from the Plugin Store. Installing creates the `menubuilder_groups` and
`menubuilder_items` tables; uninstalling drops them. It installs on the **Free** edition.

## Quick start

1. **MenuBuilder → Menus → New menu.** Give it a name and handle (`Main Navigation` / `main`).
2. Open it, add items with **Add menu item**, and drag them into shape (or move them with the arrow
   keys).
3. Render it:

```twig
{% import "menu-builder/_macros/tree" as menuMacros %}

{{ menuMacros.renderNav(craft.menuBuilder.get('main')) }}
```

That emits a named `<nav>` landmark, the menu's CSS class and attributes, nested lists,
`aria-current="page"` on the active link, and native `<details>` mega menus. Prefer your own markup?
The resolved tree is plain data — see [Resolving a menu](#resolving-a-menu).

## Features

- **Eight link types** — entry, category, asset, custom URL, anchor, non-clickable heading,
  separator, dynamic. Element links resolve live per request and per site, with a per-item fallback
  when the target is gone.
- **Drag-and-drop hierarchy** with full keyboard equivalents, an optional 1–10 depth cap, and
  server-side validation of every move.
- **Mega menus** (1–6 columns) and **mobile presentation** — one menu reshaped per viewport, never a
  second menu.
- **Dynamic navigation** — children generated from a section, category group or volume.
- **Per-item visibility rules** — seven types, combined with AND, evaluated fresh per request.
- **Custom fields per menu**, on a real Craft field layout, plus built-in icons, badges,
  descriptions, images and validated HTML attributes.
- **Control panel tooling** — slide-out editor, quick add, search, bulk actions, link-health badges,
  and a visual preview for a chosen site, audience and device.
- **Developer surface** — a five-method Twig API, optional accessible macros, a Navigation field,
  GraphQL, a read-only REST API, and two extension events.
- **Multi-site, cached and permissioned** throughout — per-site resolution and cache entries,
  targeted invalidation, five CP permissions enforced server-side.

Deliberately not included in 1.0.0: import/export. See
[Known limitations](CHANGELOG.md#known-limitations).

## Editions

MenuBuilder has two editions. They are the same plugin: there is no Pro-only feature, no Pro-only
code path, and nothing is disabled, degraded or watermarked in Free. The **only** difference is how
many menus an install may have.

| | Menus | Everything else |
|---|---|---|
| **Free** | 1 | All of it — every link type, mega menus, mobile, custom fields, visibility, preview, Twig, GraphQL, REST, the Navigation field, multi-site, permissions, caching |
| **Pro** | Unlimited | All of it, plus commercial support and new releases while the updates renewal is current |

**$19** to buy, then **$5/year** to keep receiving updates and support — Craft's standard commercial
plugin model, priced and charged by the Craft Plugin Store. The renewal buys *updates*, not the right
to keep using Pro: let it lapse and the Pro you have keeps running unchanged. Upgrade from
**MenuBuilder → Menus**, from the sidebar of any menu screen, or from **Settings → Plugins**.

The limit counts **menus per install**, not per site, and applies only to *creating* one (duplicating
included). It never applies to items, never touches existing data — an install that drops back to
Free keeps every menu it has, editable and still rendering — and is never consulted by the front end.
It is enforced in `MenuBuilderGroupService`, so a direct POST is refused too.

---

# Editor's guide

## Menus

**MenuBuilder → Menus → New menu.**

| Setting | What it does |
|---|---|
| Name / handle | The handle is what templates use: `craft.menuBuilder.get('main')` |
| Enabled | A disabled menu renders nothing. Togglable inline from the menus list |
| Max depth | Optional 1–10 cap, enforced on every move — not just in the UI |
| Restrict to sites | Multi-site only. An unavailable menu returns no tree at all |
| CSS class / HTML attributes | Rendered onto the `<nav>` wrapper. Validated server-side |
| Description | Internal note for editors |
| Item Fields | Craft's own field layout designer, on its own tab. Any installed field type — Matrix, relations, third-party fields — in as many tabs as you like |

**Duplicate** clones a menu's settings *and* all its items in one transaction, with a unique handle.

**Reordering the list.** With more than one menu, each row gets a drag handle: drag it, or focus it
and use the up/down arrow keys. The order saves as you go and decides how menus are listed in the
control panel, in `menuBuilderNavigations` over GraphQL, and in the REST list endpoint. It changes
nothing about how any individual menu renders. Needs `menuBuilder:manageSettings`.

The menus list states the active edition and the menu count against its ceiling — `Menus 1 / 1` on
Free, `Unlimited` on Pro. On Free, once the one menu exists, **New menu** and **Duplicate** explain
the limit and offer the upgrade. Every refusal comes from the server, not from a hidden button.

## Menu items

Add items with **Add menu item**, then drag them into shape — or focus a row's handle and use the
arrow keys to move it up, down, in or out one level.

| Type | Links to | When to use it |
|---|---|---|
| Entry / Category / Asset | A Craft element | Anything inside Craft. URL and title re-resolve per request, per site, so moving or renaming the element can't break the link |
| Custom URL | Any absolute URL, root-relative path, `mailto:` or `tel:` | External sites, and pages Craft doesn't own |
| Anchor | A `#fragment` on the current page | Jump links within a long page |
| Heading | A non-clickable label | Grouping a dropdown or mega-menu column |
| Separator | A divider (`<hr>`) | Visually splitting a list |
| Dynamic | Children generated from a source | Lists that should keep themselves up to date — see [Dynamic navigation](#dynamic-navigation) |

Leave the title blank on an element-backed item to inherit the element's own title.

| Tab | Settings |
|---|---|
| Link | Type, target element or URL, clickable flag, open in new tab, `rel` presets and free-form `rel`, fallback behaviour |
| Appearance | Icon, badge + style, description, image, featured, CSS class, HTML id, custom HTML attributes |
| Accessibility | ARIA label, `title` attribute |
| Visibility | The rules below |
| Mobile | Viewport, mobile order, collapsible children, mega-menu behaviour |
| Mega menu | Enable, 1–6 columns; children pick a column |
| Custom fields | Whatever the menu's field layout defines |

**Fallback behaviour** decides what happens when a linked element is missing, disabled or has no URL:
hide the item, keep it without a link, or fall back to a URL you give.

Disabling or duplicating an item applies to its **whole subtree** — children are never promoted.

## Nested navigation

There is one hierarchy: each item optionally has a parent, and siblings have an order. Drag an item
onto a deeper indent to make it a child; drag it back out to promote it. A menu's **Max depth**
setting caps how deep that can go, and the server re-checks every move against the *deepest row of
the subtree being moved* — so lifting a three-level branch into a two-level menu is refused even
though the dragged row itself would fit.

Levels are just nesting: a top-level item is level 1, its children level 2, their children level 3.
Nothing about a level changes what an item can be — a level-3 item can still be an entry link, a
heading or a mega-menu parent.

## Visibility

Per item, combined with **AND**. A malformed or unknown rule **fails closed** — the item is hidden.

| Rule | Shows the item to |
|---|---|
| `loggedIn` | Signed-in users only |
| `loggedOut` | Anonymous visitors only |
| `userGroup` | Members of the selected groups |
| `site` | The selected sites |
| `dateRange` | Visitors between the two dates (app timezone) |
| `environment` | The named `CRAFT_ENVIRONMENT` values |
| `always` | Everyone (explicit no-op) |

**"No restriction" means no rule, not an empty one.** An empty group/site/environment list, or a date
range with neither bound, hides the item and is rejected at save. "Any signed-in user" is `loggedIn`
— not a `userGroup` rule with nothing ticked.

## Mega menus

Mark any item as a mega-menu parent (1–6 columns) and assign each child a column. No second tree —
it is presentation on the hierarchy you already built. A child with no column, or one whose column
doesn't exist, falls back to the first column; a parent with no visible children renders nothing.

Use one when a dropdown has grown past a single readable column — typically a top-level section with
headings grouping its children.

## Mobile

One menu, presented differently. Four per-item settings:

| Setting | Values |
|---|---|
| Shown on | Desktop and mobile *(default)*, Desktop only, Mobile only |
| Mobile order | 0–9999, optional. Unnumbered items keep their dragged order and follow after |
| Children on mobile | Collapsed by default, or Always expanded |
| Mega menu on mobile | Stack the columns *(default)*, Keep the columns, Hide the panel |

MenuBuilder stores no breakpoint and emits no media query — your CSS decides the width. See
[Mobile rendering](#mobile-rendering).

## Dynamic navigation

A `dynamic` item synthesises its children at render time:

- **Source:** entries by section, categories by group, or assets by volume
- **Limit:** up to 50 (capped server-side)
- **Order:** newest, oldest, title A–Z, title Z–A
- Scoped to the current site and to normally-visible elements, cached and invalidated like the rest

Use it for a "Latest news" branch that should never need re-editing. Use ordinary item rows when the
order or the wording matters.

## Custom fields

Each menu has its own **Craft field layout**, built in Craft's own field layout designer under the
menu's **Item Fields** tab. Any installed field type works — Plain Text, Dropdown, Matrix, Entries,
Assets, Money, third-party fields — arranged into as many tabs as you want, with Craft's field
conditions and instructions. Whatever you put there appears on every item in that menu, on its own
tab in the item editor.

Custom fields are for data your templates need and MenuBuilder doesn't have an opinion about — a
subtitle, a promo image, a badge colour of your own. The bundled macros render none of them; see
[Custom fields in templates](#custom-fields-in-templates) for how a developer reads them.

## Link health

Every item whose link doesn't work carries a badge saying which way — content missing, disabled,
unpublished, not on this site, no URL, invalid link, or a dead dynamic source — with a menu-wide
summary at the top and a route into the editor to relink, add a fallback URL, or disable it.
Internal links only: MenuBuilder never crawls external URLs and never deletes an item.

## Preview

Each menu has a **Preview** button (permission: `menuBuilder:view`). It renders the saved menu on an
illustrative page through the same macros and pipeline the front end uses.

| Option | What it changes |
|---|---|
| Site | Which site's content links resolve against |
| Audience | Logged out, logged in, or specific user groups — what visibility rules are evaluated against |
| Shown in | Header, footer, or both |
| Device | Desktop, or a 390px mobile viewport |

It shows structure, state and attributes — **not your theme**: your CSS and JS are deliberately not
loaded. It shows saved data (there is no draft state), changes nothing, and can't expose unpublished
content. Time and environment are real, not simulated. A "Rendered markup" panel shows the same
output as copyable text.

## Multi-site

A menu is one global row that may be restricted to a set of sites, not a per-site entity — so an
install has one menu list however many sites it has. What varies per site is the *result*:

- A menu restricted away from the current site returns nothing at all.
- A per-item `site` rule hides individual items on the sites you don't pick.
- Element links resolve against the current site, so titles and URLs come back in that site's
  language and URL structure, and an element not enabled for the site falls back per the item's
  fallback behaviour.
- Each site gets its own cache entry.

## Permissions

| Permission | Grants |
|---|---|
| `menuBuilder:view` | See the MenuBuilder section, menu trees and the preview screen |
| `menuBuilder:create` | Create and duplicate menu items |
| `menuBuilder:edit` | Edit, reorder, enable/disable items |
| `menuBuilder:delete` | Delete menus and items |
| `menuBuilder:manageSettings` | Create, edit and duplicate menus |

These are **in addition to** Craft's **Access the control panel** permission — grant both. The five
are independent, so grant `view` alongside whichever others a role needs. Admins bypass all five.

Every action is permission-checked server-side and every mutation requires POST with Craft's CSRF
token, including the AJAX ones. Hidden controls are a courtesy; the check on the request is the
boundary.

## Common tasks

| Task | How |
|---|---|
| Basic navigation | New menu → add entry items → render with `renderNav()` |
| A dropdown | Add child items under a top-level item by dragging them one level in |
| A mega menu | On the parent item's **Mega menu** tab, enable it and pick 1–6 columns; on each child, pick its column |
| An external link | Item type **Custom URL**, paste the absolute URL, tick **Open in new tab** |
| A dynamic list | Item type **Dynamic**, pick a source type and source, set a limit and order |
| Hide an item from anonymous visitors | Add a `loggedIn` visibility rule — not an empty `userGroup` rule |
| Schedule an item | Add a `dateRange` rule with a start, an end, or both |
| Add custom data to items | Menu → **Item Fields** → build a Craft field layout; read it with `node.custom('handle')` |
| Move a menu to another environment | Deploy the database — menus are not in project config. See [Where your data lives](#where-your-data-lives) |

---

# Templating

MenuBuilder resolves navigation **data**; rendering is your template's job. Optional macros are
bundled — use them, copy them, or ignore them.

## Resolving a menu

```twig
{% set menu = craft.menuBuilder.get('main') %}
```

`get(handle, currentUri = null)` returns a `MenuBuilderTree`, or `null` when the menu doesn't exist,
is disabled, or isn't available on this site. Pass `currentUri` to match active state against another
page.

| Member | Description |
|---|---|
| `{% for node in menu %}` | Top-level nodes |
| `menu.items` | The same nodes, explicitly |
| `menu.group` | Name, handle, cssClass, htmlAttributes, maxDepth, settings |
| `menu.flatten()` | Depth-first flat list of every node |
| `menu.forViewport('mobile')` | The tree reshaped for one viewport |
| `menu\|length` | Top-level node count |

The whole `craft.menuBuilder` variable is five methods:

```twig
{% set menu  = craft.menuBuilder.get('main') %}                {# the resolved tree #}
{% set trail = craft.menuBuilder.breadcrumbs('main') %}        {# a tree or a handle #}
{% set group = craft.menuBuilder.getGroup('main') %}           {# settings only, no tree #}
{% set item  = craft.menuBuilder.getItem(42) %}                {# raw item — admin/debug #}
{% set icon  = craft.menuBuilder.iconAsset(node) %}            {# Asset behind an `asset:` icon #}
```

## Node reference

`MenuBuilderNode` is the only object templates should treat as public and stable.

| Property | Notes |
|---|---|
| `id`, `handle`, `type`, `title` | `title` includes the element-title fallback |
| `url` | `null` for headings, separators and unresolvable links |
| `isClickable` | True only when the type is linkable, the editor marked it clickable, **and** a URL resolved |
| `isLinkAvailable` | False when a linked element is missing, disabled or unpublished |
| `target`, `rel`, `opensInNewTab()` | `rel` already includes `noopener` for new-tab links |
| `cssClass`, `htmlId`, `safeHtmlAttributes()` | Render `safeHtmlAttributes()` — it re-filters the stored bag |
| `ariaLabel`, `titleAttribute` | Accessibility |
| `hasIcon()`, `iconType()`, `iconClass()`, `iconAssetId()` | See [Icons](#icons) |
| `hasBadge()`, `badge`, `badgeStyle`, `badgeClass()` | See [Badges](#badges) |
| `description`, `image`, `featured` | Presentation extras; `image` is an asset ID |
| `level`, `children`, `hasChildren()`, `parent` | Hierarchy; dynamic children merge into `children` |
| `isActive`, `isActiveAncestor`, `isActiveOrAncestor()` | Per-request, never cached |
| `megaMenu`, `megaMenuColumns()`, `megaMenuColumn` | `megaMenuColumns()` returns `{column: nodes}` |
| `isDynamic` | True for synthesised nodes |
| `custom(handle, default)`, `hasCustom(handle)`, `customHandles()` | See [Custom fields in templates](#custom-fields-in-templates) |
| `mobileVisibility()`, `mobileOrder()`, `isMobileCollapsible()`, `mobileMegaMenuBehavior()`, `showsOnDesktop()`, `showsOnMobile()`, `isVisibleOn(viewport)`, `viewportAttribute()` | See [Mobile rendering](#mobile-rendering) |

## Rendering with the macros

```twig
{% import "menu-builder/_macros/tree" as menuMacros %}

{{ menuMacros.renderNav(craft.menuBuilder.get('main')) }}    {# nav landmark + list #}
{{ menuMacros.render(craft.menuBuilder.get('main').items) }} {# just the list #}

{# Optional: mega-menu keyboard extras (Escape, arrows, Home/End) #}
{% do view.registerAssetBundle('Tahadudhiya\\MenuBuilder\\web\\assets\\nav\\NavAsset') %}
```

| Macro | Signature | Emits |
|---|---|---|
| `renderNav` | `(menu, label = null, disclosure = 'details', idPrefix = '', viewport = 'both')` | One `<nav>` landmark named after the menu, carrying its CSS class and attributes. An empty menu emits nothing |
| `render` | `(nodes, disclosure, idPrefix, viewport)` | The recursive `<ul>` |
| `renderMegaMenu` / `megaMenuPanel` | `(node, disclosure, idPrefix, viewport)` | A mega-menu panel inside a native `<details>` |
| `mobileSubmenu` | `(node, children)` | One collapsible mobile branch |
| `icon`, `badge`, `newTabHint` | `(node)` | The icon, the badge span, a hidden "(opens in a new tab)" |

`disclosure` is `'details'` (native disclosure) or `'none'` (columns in flow, no state claimed).
`viewport` is `'both'`, `'desktop'` or `'mobile'`. `idPrefix` keeps HTML ids unique when one menu
renders twice on a page.

Hand-rolled works just as well — the tree is plain data:

```twig
<nav aria-label="{{ menu.group.name }}">
  <ul>
    {% for node in menu %}
      <li class="{{ node.isActiveOrAncestor() ? 'is-active' }}">
        {% if node.isClickable %}
          <a href="{{ node.url }}"
             {% if node.opensInNewTab() %}target="_blank"{% endif %}
             {% if node.rel %}rel="{{ node.rel }}"{% endif %}
             {% if node.isActive %}aria-current="page"{% endif %}>{{ node.title }}</a>
        {% else %}
          <span>{{ node.title }}</span>
        {% endif %}

        {# A mega-menu parent groups its children into columns; otherwise just recurse. #}
        {% if node.megaMenu %}
          {% for column, nodes in node.megaMenuColumns() %}
            <ul>{% for child in nodes %}<li><a href="{{ child.url }}">{{ child.title }}</a></li>{% endfor %}</ul>
          {% endfor %}
        {% elseif node.hasChildren() %}
          {# recurse #}
        {% endif %}
      </li>
    {% endfor %}
  </ul>
</nav>
```

## Active state

`isActive` is true for the one node whose URL **is** the page being served; every ancestor gets
`isActiveAncestor`. Put `aria-current="page"` on `isActive` only.

Matching compares normalized paths, so `/news`, `news`, `https://example.test/news/` and
`/news?page=2#top` are one page. Nothing matches by prefix — `/news` is not active on `/newsletter`.
Never active: a URL on another host, `mailto:`/`tel:`, a blank or unavailable link, and an
anchor-only item. Recomputed every request, never cached.

## Breadcrumbs

```twig
{% set menu  = craft.menuBuilder.get('main') %}
{% set trail = craft.menuBuilder.breadcrumbs(menu) %}   {# a handle also works #}

{% import "menu-builder/_macros/breadcrumbs" as crumbs %}
{{ crumbs.render(trail) }}
{{ crumbs.render(trail, 'You are here'|t, false) }}   {# own label; last crumb as text #}
```

| Member | Description |
|---|---|
| `{% for crumb in trail %}` | Root first, current page last |
| `trail.crumbs`, `trail.group` | The list; the menu it came from |
| `trail.current()`, `trail.root()`, `trail.ancestors()` | The active node, its top-level ancestor, everything but the last |
| `trail.isEmpty()`, `trail\|length` | Whether there is a trail, and how long |

Each crumb **is** a `MenuBuilderNode`, so `title`, `url`, `isClickable`, `custom()` and the rest
apply.

The trail is the **menu hierarchy** — never parsed from the URL. `null` means there is no such menu;
an empty trail means the page isn't in the menu (render nothing). An ancestor whose own link is
unavailable stays in the trail as an unlinked crumb — check `crumb.isClickable`. A URL placed twice
resolves to the first in document order. Nothing about a trail is cached.

The macro emits `<nav aria-label="Breadcrumb">` around an `<ol>`, `aria-current="page"` on the last
crumb only, and **no separator characters** — draw those in CSS:

```css
.menu-builder-breadcrumbs li + li::before { content: "›"; margin: 0 .5em }
```

## Icons

An icon is stored in one column with exactly three forms: empty (no icon), `asset:123` (a Craft
asset), or an icon class list (`icon-cart`, `fa fa-cart`). Raw SVG markup is deliberately not
storable — upload the SVG and pick it as an asset. Class values are allowlisted to letters, digits,
spaces and `- _ . : /`, and `iconClass()` returns `null` for anything that wouldn't validate today.

```twig
{% if node.iconType() == 'class' %}
  <span class="icon {{ node.iconClass() }}" aria-hidden="true"></span>
{% elseif node.iconType() == 'asset' %}
  {% set icon = craft.menuBuilder.iconAsset(node) %}
  {% if icon %}<img src="{{ icon.url }}" alt="" width="24" height="24" loading="lazy">{% endif %}
{% endif %}
```

Icons are decorative (`aria-hidden` / `alt=""`); if one is an item's only label, give the item an
`ariaLabel`. Always resolve assets through `iconAsset()` (memoized per request), and never inline an
SVG asset's contents.

## Badges

```twig
{% if node.hasBadge() %}
  <span class="{{ node.badgeClass() }}">{{ node.badge }}</span>
{% endif %}
```

Badge text is free text, escaped by Twig like any string — never print it with `|raw`. The style is a
closed enum (`default`, `info`, `success`, `warning`, `critical`); `badgeClass()` returns
`menu-builder-badge` plus an allowlisted modifier, and an unknown style reads as no style. Render the
badge **inside** the link so it joins the accessible name. No front-end CSS ships for these classes.

## Custom fields in templates

Read a menu's [custom field](#custom-fields) values on a node by handle:

```twig
{{ node.custom('subtitle') }}
{{ node.custom('rank', 0) }}

{# The value is whatever the field returns — an Assets field gives you Craft's
   own element query, so chain it exactly as you would anywhere else. #}
{% set teaser = node.custom('teaser').one() %}
{% if teaser %}<img src="{{ teaser.url }}" alt="">{% endif %}

{% for block in node.custom('promoBlocks').all() %}
  <h3>{{ block.heading }}</h3>
{% endfor %}
```

`hasCustom('handle')` is true when the field holds a non-empty value; `customHandles()` lists every
handle the menu defines, for iterating without naming them.

Reads fail closed on the handle: remove a field from the menu's layout and `custom()` returns the
default rather than throwing on a live page. Values are read fresh on every request — the resolved
tree caches each item's *content ID*, never the values — and the whole tree's content is fetched in
one batched query, so a menu with custom fields costs one extra query, not one per item.

Custom fields never affect a URL, active state, visibility or caching, and the bundled macros render
none of them.

## Mobile rendering

**One navigation, one attribute** — the default, and right for a cached page:

```twig
{{ menuMacros.renderNav(craft.menuBuilder.get('main')) }}
```

```css
@media (max-width: 48em)     { [data-mb-viewport="desktop"] { display: none } }
@media (min-width: 48.001em) { [data-mb-viewport="mobile"]  { display: none } }
```

Use `display: none` and nothing else — it removes the item from the accessibility tree and the Tab
order together. `visibility`, `opacity: 0` and off-screen positioning leave keyboard and
screen-reader users walking links that aren't on screen. Mobile *order* does nothing here: one DOM is
in one order.

**Two navigations, one resolve** — for a drawer with its own markup. `forViewport()` reshapes the
tree you already resolved: no extra query, no second cache read.

```twig
{% set menu = craft.menuBuilder.get('main') %}
{{ menuMacros.renderNav(menu.forViewport('desktop'), null, 'details', 'desktop', 'desktop') }}
{{ menuMacros.renderNav(menu.forViewport('mobile'), 'Menu'|t, 'details', 'mobile', 'mobile') }}
```

Exactly one of the two must be `display: none` at any width, and give them different `idPrefix`
values. Mobile order is applied by re-sorting the tree, so DOM order and visual order stay the same
thing — never hand it to CSS `order`.

All the mobile accessors fail closed toward *keeping* the link:

```twig
{{ node.mobileVisibility() }}       {# 'both' | 'desktopOnly' | 'mobileOnly' #}
{{ node.showsOnDesktop() }} {{ node.showsOnMobile() }} {{ node.isVisibleOn('mobile') }}
{{ node.mobileOrder() }}            {# int or null #}
{{ node.isMobileCollapsible() }}    {# false for a leaf, whatever is stored #}
{{ node.mobileMegaMenuBehavior() }} {# 'stack' | 'columns' | 'hide' #}
{{ node.viewportAttribute() }}      {# 'desktop' | 'mobile' | null #}
```

## Accessibility

The bundled macros are built to ship as they are. What they guarantee:

- One named `<nav>` landmark per menu, real `<ul>`/`<li>` nesting, ordinary `<a href>` links and no
  `tabindex` anywhere. A non-clickable item is a `<span>`, a separator is an `<hr>` inside its `<li>`.
- `aria-current="page"` on the active link only; ancestors get the `is-active` class instead.
- A hidden "(opens in a new tab)" inside a `_blank` link's accessible name (WCAG 3.2.5).
- Mega menus and collapsed mobile branches are native `<details>` disclosures — no `aria-expanded`,
  `aria-controls` or `aria-haspopup`, because `open` *is* the state, and no script is required.
- Icons are `aria-hidden` / `alt=""`; badges render inside the link so they join its accessible name.
- Breadcrumbs are `<nav aria-label="Breadcrumb">` around an `<ol>`, with no separator characters.
- Custom HTML attributes are filtered at render as well as on save: no event handlers, no
  `javascript:`/`vbscript:`, none of the macro-owned or ARIA attributes.

**The one rule for your CSS: never make a panel visible while its `<details>` is closed.** Style
`details[open] > .menu-builder-megamenu-panel`, not `li:hover > details > .panel`, and don't set
`display` on the `<details>` element itself. To open on hover, set the `open` property from your own
script — that's the same state a click sets.

Without any bundle, `Tab` walks every link in document order and `Enter`/`Space` toggle a summary.
The optional `NavAsset` adds `Escape` (close and return focus), `ArrowUp`/`ArrowDown`, `Home`/`End`
inside an open panel, and closing a sibling panel when another opens. It sets `details.open` and
writes no attribute of its own.

Your CSS still owns focus indicators, submenus opening on `:focus-within` as well as `:hover`, and
contrast. The reasoning behind these guarantees, and the manual release checklist, are in
[ARCHITECTURE.md](ARCHITECTURE.md#accessibility).

---

# Headless and developer

## The Navigation field

A field that lets an author pick a menu per element — a landing page with its own sidebar nav, a
campaign section with its own footer. Add a field of type **Navigation** to any field layout
(entries, Matrix blocks, categories, users):

```twig
{% set nav = entry.navigation %}

{% if nav %}
  {% import 'menu-builder/_macros/tree' as menuMacros %}
  {{ menuMacros.renderNav(nav.tree, nav.name) }}
{% endif %}
```

The value is iterable over the resolved menu's top-level items, so the common case needs no `.tree`.

| Member | |
|---|---|
| `nav.tree` | The resolved `MenuBuilderTree`, or `null` — everything above applies |
| `nav.handle`, `nav.name` | The menu's handle and name. `{{ nav }}` prints the name |
| `nav.exists`, `nav.enabled` | `false` if the menu was deleted / is disabled |
| `nav.groupUid` | The stored identity |

`entry.navigation` is `null` when nothing was selected; when something is selected but can't render,
`nav.tree` is `null` and iterating yields nothing. The menu resolves lazily and once per value, so
listing a hundred entries costs nothing.

| Setting | Effect |
|---|---|
| Selectable navigations | Which menus authors may choose. Unchecked = all |
| Allow disabled navigations | Whether disabled menus appear in the picker. Off by default |
| Translation method | Craft's standard field translation — untranslatable for one shared selection, per-site to let each site pick |

The field stores the menu's **UID**, so renaming a handle repoints nothing. A deleted menu makes
publishing report *"The selected navigation no longer exists."* while drafts still save; a disabled
menu keeps the selection but renders nothing. Picking a menu needs no MenuBuilder permission.

The field's own settings ride in `project.yaml` like any field's and are safe to deploy, but **menus
themselves are not in project config** — applying the config doesn't create them. See
[Where your data lives](#where-your-data-lives). Over GraphQL the field exposes the selection, not
the menu (`navigation { uid handle name exists enabled }`): take the handle and query the menu itself.

## GraphQL

Read-only access to resolved menus over Craft's GraphQL API.

```graphql
{
  menuBuilder(handle: "main", currentUri: "about/team") {
    name
    items { title url type isActive children { title url } }
  }
}
```

**It is off until you turn it on, menu by menu.** A menu becomes queryable when you tick it in
**Settings → GraphQL → Schemas**, under **MenuBuilder**. A schema naming no menu doesn't get the
fields at all, introspection included. There is no mutation surface.

| Query | Returns |
|---|---|
| `menuBuilder(handle: "main")` | One menu, or `null` |
| `menuBuilderNavigations` | Every enabled menu this schema may read, in control-panel order |

| Argument | Type | Meaning |
|---|---|---|
| `handle` | `String!` | The menu's handle (`menuBuilder` only) |
| `site` / `siteId` | `String` / `Int` | Resolve for this site. Defaults to the request's site |
| `currentUri` | `String` | The page being rendered, for `isActive` / `isActiveAncestor` |
| `viewport` | `String` | `"desktop"` or `"mobile"` |

`menuBuilder` returns the *same* `null` — never an error — when the menu doesn't exist, the handle
isn't a handle, the menu is disabled, it isn't available on the requested site, or it isn't in the
schema's scope. `menuBuilderNavigations` simply omits what you may not read.

The tree goes through the same pipeline as `craft.menuBuilder.get()`. **Visibility is evaluated for
an anonymous visitor**, because Craft caches a result by (site, schema, query, variables) and by
nothing about the caller: items restricted to logged-in visitors or a user group never appear;
logged-out-only items always do; date, environment and site rules apply normally. **Active state is
an argument** — pass `currentUri` or both flags are `false`.

`MenuBuilderNavigation` fields: `handle`, `name`, `uid`, `description`, `cssClass`, `maxDepth`,
`htmlAttributes`, `itemCount`, `items`.

`MenuBuilderNavigationItem` fields:

| Group | Fields |
|---|---|
| Identity | `handle`, `type`, `level`, `isDynamic` |
| Link | `title`, `url`, `isClickable`, `isLinkAvailable`, `target`, `rel`, `opensInNewTab` |
| Active state | `isActive`, `isActiveAncestor` |
| Presentation | `cssClass`, `htmlId`, `htmlAttributes`, `ariaLabel`, `titleAttribute`, `description`, `featured`, `imageId` |
| Icon / badge | `iconType`, `iconClass`, `iconAssetId`; `badge`, `badgeStyle`, `badgeClass` |
| Mega menu | `megaMenu { columns }`, `megaMenuColumn` |
| Mobile | `mobileVisibility`, `mobileOrder`, `isMobileCollapsible`, `mobileMegaMenuBehavior`, `viewportAttribute` |
| Custom fields | `customFields { handle value booleanValue numberValue intValue jsonValue }` |
| Hierarchy | `hasChildren`, `children` |

`htmlAttributes` is a list of `{ name, value }` pairs (GraphQL has no map type). These read through
the same fail-closed accessors as the Twig node. **Asset references are IDs, not URLs** — feed them
into Craft's `asset(id:)` query. **Row IDs are not exposed**; `handle` is an item's stable public
name. A custom field whose value isn't a scalar populates `jsonValue` only.

## REST API

A read-only JSON API for consumers that can't run Twig — a headless front end, a native app, an
external site. It is not a second API: it is a second transport over the same gates, audience and
pipeline as GraphQL. If you render with Twig, use `craft.menuBuilder`.

**Two switches, both required.** First the API, in `config/menu-builder.php` — without this file no
route is registered at all:

```php
<?php
return [
    'api' => [
        'enabled' => true,                // literal `true` — not 1, not 'true'
        'basePath' => 'api/menu-builder', // endpoints live under {basePath}/v1/
        'allowPublicSchema' => true,      // may an unauthenticated request use the public schema?
        'rateLimit' => 60,                // requests/min per caller; also switches the failed-auth limiter; 0 disables both
        'cacheDuration' => 0,             // Cache-Control max-age; 0 sends no-store
        'allowedOrigins' => [],           // exact CORS origins, or ['*']; empty sends no CORS headers
    ],
];
```

Anything malformed falls back to that key's default rather than to something permissive. Second, each
menu, through a GraphQL schema's scope (`menuBuilderGroups.{uid}:read`) — one list of readable menus,
not two.

```
GET {basePath}/v1/navigations            → every menu this caller may read
GET {basePath}/v1/navigations/{handle}   → one menu
```

`GET` and `HEAD` only; `OPTIONS` answers a CORS preflight; everything else is `405`. There is no write
surface. Query parameters: `site` / `siteId`, `currentUri`, `viewport` — unrecognized ones are
ignored, a recognized but malformed one is a `400` that names it.

```sh
curl https://example.com/api/menu-builder/v1/navigations/main \
  -H 'Authorization: Bearer {your Craft GraphQL token}'
```

The token is a Craft GraphQL access token (**GraphQL → Tokens**), validated as Craft validates it.
With no header the request falls back to Craft's public schema unless `allowPublicSchema` is off.

```jsonc
{
  "meta": { "apiVersion": "1.0", "site": { "id": 1, "handle": "default", "language": "en-US" },
            "currentUri": "about", "viewport": null },
  "data": {
    "handle": "main", "name": "Main Navigation", "uid": "…",
    "description": null, "cssClass": "site-nav", "maxDepth": 3,
    "htmlAttributes": {}, "itemCount": 3,
    "items": [{
      "handle": "about", "type": "entry", "level": 1, "isDynamic": false,
      "title": "About", "url": "/about", "isClickable": true, "isLinkAvailable": true,
      "target": "_self", "rel": null, "opensInNewTab": false,
      "isActive": true, "isActiveAncestor": false,
      "cssClass": null, "htmlId": null, "htmlAttributes": { "data-track": "nav" },
      "ariaLabel": null, "titleAttribute": null, "description": null,
      "featured": false, "imageId": null,
      "icon": { "type": "class", "class": "fa fa-info", "assetId": null },
      "badge": { "text": "New", "style": "success", "class": "menu-builder-badge menu-builder-badge--success" },
      "megaMenu": { "columns": 4 }, "megaMenuColumn": null,
      "mobile": { "visibility": "both", "order": null, "isCollapsible": true,
                  "megaMenuBehavior": "stack", "viewportAttribute": null },
      "customFields": { "subtitle": "Who we are", "promoted": true, "relatedEntries": [12, 15] },
      "hasChildren": true, "children": []
    }]
  }
}
```

`icon`, `badge` and `megaMenu` are `null` when absent; `htmlAttributes` and `customFields` are always
objects (`{}`, never `[]`). Custom fields carry each field's **serialized** value, so a relation field
is a list of element IDs you feed back into Craft's own queries rather than resolved elements — the
same reason `imageId` is an ID. Row IDs, and anything a visitor never sees, are not exposed. Menus
resolve for the **anonymous** audience whoever is asking, including a browser carrying an admin's
session cookie.

| Status | `error.code` | When |
|---|---|---|
| `400` | `bad_request` | A recognized parameter is invalid. The message names it |
| `401` | `unauthorized` | No usable token and no public-schema fallback |
| `403` | `forbidden` | Your token's schema doesn't cover the site whose URL you called |
| `404` | `not_found` | The menu isn't servable to you — unknown, disabled, out of scope, or not on this site. Also what a disabled API answers |
| `405` | `method_not_allowed` | Anything but `GET`, `HEAD`, `OPTIONS` |
| `429` | `rate_limited` | Over either rate limit — requests, or failed authentications. Carries `Retry-After` |

```json
{ "error": { "status": 404, "code": "not_found", "message": "No such navigation." } }
```

`404` never says why — an API that distinguished the reasons would enumerate your install's
structure. The list endpoint omits menus you can't read.

Every response carries an `ETag` and `Vary: Authorization, Origin`; send `If-None-Match` for a `304`.
`Cache-Control` is `no-store` until you set `cacheDuration`, then `public, max-age=N` for a
public-schema response and `private, max-age=N` for a token-authenticated one. CORS matching is exact
— no suffix or subdomain matching — with no credentials ever sent; the single entry `'*'` is honoured
and echoed as `*`. The URL carries the major version (`/v1/`); additive changes stay on it, so write
your consumer to ignore unknown fields.

**Rate limiting** is two fixed one-minute windows, both switched by `rateLimit` (set it to `0` and
neither runs):

| | Counts | Keyed by | Budget |
|---|---|---|---|
| Requests | Successfully authenticated requests | Token + address | `rateLimit` (default 60/min), reported in `X-RateLimit-*` |
| Failed authentications | `401`s — a bad, expired or missing token | Address only | 10/min, fixed |

The second exists because the first runs *after* authentication and is keyed partly by the token, so
it can't see a caller who never presented a usable one. Exceed it and further attempts are refused
with `429` and a `Retry-After` before the token is looked up. A correct token is never charged to it,
and one address's failures never affect another's.

## Extending

Two events, and they are the only extension points.

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

A resolver implements `LinkTypeResolverInterface::resolve(MenuBuilderItem $item): ResolvedLink`;
implement `PreloadingLinkTypeResolverInterface` to batch-load its elements. A custom type must
invalidate menu caches itself — `MenuBuilder::getInstance()->cache->invalidateGroups(['main'])`.

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
Unknown rules — and rules that throw — fail closed. Return `false` for config you don't recognise.

There are **no** save/delete events on menus or items. Services are reachable as
`MenuBuilder::getInstance()->groups`, `->items`, `->resolver`, `->cache` and so on; see
[ARCHITECTURE.md](ARCHITECTURE.md#layers) for the full list and what each owns.

## Caching

Resolved menus are cached per **menu + site + configuration version**, and only the link-resolution
pass is cached: visibility filtering and active-state marking always run fresh, so nothing user-,
date- or page-specific is ever shared between visitors.

Invalidation is targeted, never a blanket flush:

- A menu or item change invalidates that menu, on every site, in one step; bulk actions invalidate
  once the batch has committed.
- An entry/category/asset save, delete, restore or URI update invalidates only the menus linking to
  it, plus menus whose dynamic items are sourced from its section, category group or volume.
- A section, category group or volume save invalidates only menus referencing an element inside it.
- Draft, revision and provisional-draft saves are ignored.
- A site save or delete invalidates everything — which covers `project-config/apply` on deploy.

The config version means an edited menu or a plugin upgrade reads a *different* key. Craft's
`cacheDuration` is the upper bound, catching the one change no event announces: an entry going live at
its `postDate` or expiring. No manual cache clearing is needed in normal use. The key construction and
invalidation matrix are in [ARCHITECTURE.md](ARCHITECTURE.md#caching).

## Where your data lives

| | Stored in | Source of truth |
|---|---|---|
| Menus and menu items | The database (`menubuilder_groups`, `menubuilder_items`) | The database |
| A menu's item field layout | The database (`fieldlayoutId` → Craft's `fieldlayouts`) | The database |
| Custom field *values* on items | A `MenuBuilderItemContent` element per item (Craft's `elements` tables) | The database |
| Navigation **field** settings (allow-list, "allow disabled") | Project config, as part of the field, like any Craft field | Project config |
| The active plugin edition (`free` / `pro`) | Project config (`plugins.menu-builder.edition`), where Craft's Plugin Store puts it | Project config |
| REST API settings | The PHP file `config/menu-builder.php`, read per request | That file |

**Menus are not project-config entities.** MenuBuilder writes nothing to `project.yaml` for a menu or
an item, registers no project-config handlers for them, and takes no part in a project-config rebuild.
That means:

- **Deploy menus with your database**, like content. `project-config/apply` neither creates, changes
  nor deletes a menu.
- A Navigation field's stored value is a menu **UID**. Applying that field's config to another
  environment doesn't create the menu it names — the menu must already exist in that environment's
  database, or the selection reads as "doesn't resolve".
- **Installing** runs one migration (`src/migrations/Install.php`) creating the two tables.
  **Uninstalling** hands Craft's rows back (content elements, then field layouts) and drops both
  tables, leaving nothing in `project.yaml` for a reinstall to replay.
- **Back up the database before upgrading** — menus live only there, and rollback is a database
  restore.

Import/export is **not implemented**: no command, no interchange format, no CP screen. Within one
install, **Duplicate menu** clones a menu's settings and every item in one transaction.

The plugin's version is the Git tag it was released from; `composer.json` declares none.
`schemaVersion` is `1.0.0`, and it — plus a digest of the cached classes' shape — is hashed into
every cache key, so an upgrade reads fresh keys.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `craft.menuBuilder.get('main')` returns `null` | The menu doesn't exist, is disabled, or is restricted away from this site | Check the handle's spelling, the **Enabled** switch, and **Restrict to sites** |
| **Menus** is missing from the CP nav | The user lacks `menuBuilder:view`, or Craft's **Access the control panel** | Grant both to the group |
| A CP action answers 403 | The action needs a different permission — deleting needs `:delete`, creating a menu needs `:manageSettings` | See [Permissions](#permissions) |
| An item is missing on the front end but visible in the CP | A visibility rule excludes this visitor, the item is disabled, or its link is unavailable with fallback *hide* | Use [Preview](#preview) as a logged-out visitor; check the link-health badge |
| An item is missing for *everyone* | A restricting rule was left empty or malformed, so it fails closed — or both **logged-in** and **logged-out** are ticked | Remove the rule entirely: "no restriction" is *no rule* |
| A link renders as plain text | The linked element is deleted, disabled, unpublished or has no URL, and the fallback is *keep it, drop the link* | Relink it, add a fallback URL, or disable the item |
| Nothing is ever marked active | The compared URL isn't the page being served — an off-site host, a non-`http(s)` scheme, or an anchor-only link | Use a root-relative path or an element link |
| Active state is "wrong" across sites | A sibling site's host is deliberately not internal, so a cross-site link is never the current page | Expected — the link is active on the site it points at |
| Dynamic children don't appear | The source config is incomplete, or the elements aren't normally visible | Set source type *and* source; entries must be live, categories and assets enabled |
| A menu edit isn't visible on the front end | Rare — invalidation is automatic and targeted | Clear Craft's data caches; if it recurs, report it |
| GraphQL says the field doesn't exist | The active schema names no MenuBuilder menu, so the fields aren't added at all | Tick the menu in **GraphQL → Schemas** |
| REST returns `404` for everything | The API is off, so no route is registered | `config/menu-builder.php` must return `api.enabled => true` — a literal `true` |
| REST returns `403` / `401` / `429` | The token's schema doesn't cover the site; no usable token and no public schema; over the rate limit | Call the right site's URL; send a valid token or allow the public schema; back off or raise `rateLimit` |
| A browser call is blocked by CORS | No origins are allowlisted, which is the default | List the exact origin in `allowedOrigins` |
| A referenced menu is missing after `project-config/apply` | Menus aren't in project config; only the field's settings are | Deploy the database — see [Where your data lives](#where-your-data-lives) |

## Development

```sh
composer test              # PHPUnit, unit suite — pure logic, no booted Craft
composer test-integration  # PHPUnit, integration suite — real Craft + real database
composer check-cs          # ECS
composer phpstan           # PHPStan (level 5)
```

The integration suite needs a database whose name contains `test`, and its connection defaults
(`db:3306`, user and password `db`) are DDEV's *from inside the web container*:

```sh
ddev exec composer test-integration
# or point it at any other database:
MENUBUILDER_TEST_DB_SERVER=127.0.0.1 MENUBUILDER_TEST_DB_PORT=55012 composer test-integration
```

What each suite covers, the environment they need, and the release process are in
[ARCHITECTURE.md](ARCHITECTURE.md#testing).

## Support

- **Bugs and feature requests:** [GitHub issues](https://github.com/tahadudhiya53/MenuBuilder/issues)
- **Source and releases:** [github.com/tahadudhiya53/MenuBuilder](https://github.com/tahadudhiya53/MenuBuilder)
- **Troubleshooting first:** most reports resolve at [Troubleshooting](#troubleshooting) above.

Pro includes commercial support. Free is supported through GitHub issues on a best-effort basis.

## License

MenuBuilder is a **commercial plugin**, licensed under [The Craft License](LICENSE.md) —
`composer.json` declares `proprietary`. One licensed copy runs in one production environment at a
time; development, staging and local installs don't need their own license.

Licensing, payment and license validation are Craft's, not this plugin's: Free and Pro are Craft
[plugin editions](#editions), the active edition lives in project config where Craft's Plugin Store
puts it, and MenuBuilder only ever asks Craft which edition is active. There is no license server, no
phone-home and no license key stored by this plugin.

**Renewal.** The $5/year renewal buys continued updates and support, not continued *use*. If it
lapses, the Pro edition you already have keeps running — you simply stop receiving new releases until
you renew. Letting a license lapse never deletes, hides or disables a menu.
