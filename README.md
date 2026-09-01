# MenuBuilder for Craft CMS 5

Advanced navigation management for Craft CMS 5 — multiple menus, drag-and-drop hierarchy, eight link
types, mega menus, dynamic navigation, per-item visibility rules, and a small, stable Twig API.

- **Requires:** Craft CMS ^5.0, PHP >= 8.2 · **License:** MIT · **Handle:** `menu-builder`
- Internals and design decisions: **[ARCHITECTURE.md](ARCHITECTURE.md)** · Release history:
  **[CHANGELOG.md](CHANGELOG.md)**

## Contents

**Using it** — [Install](#install) · [Quick start](#quick-start) · [Features](#features) ·
[Menus](#menus) · [Menu items](#menu-items) · [Visibility rules](#visibility-rules) ·
[Mega menus](#mega-menus) · [Mobile](#mobile) · [Dynamic navigation](#dynamic-navigation) ·
[Link health](#link-health) · [Preview](#preview) · [Permissions](#permissions)

**Templating** — [Resolving a menu](#resolving-a-menu) · [Node properties](#node-properties) ·
[Macros](#rendering-with-the-macros) · [Active state](#active-state) · [Breadcrumbs](#breadcrumbs) ·
[Icons](#icons) · [Badges](#badges) · [Custom fields](#custom-fields) ·
[Mobile rendering](#mobile-rendering) · [Accessibility](#accessibility)

**Headless & developer** — [Navigation field](#the-navigation-field) · [GraphQL](#graphql) ·
[REST API](#rest-api) · [Extending](#extending) · [Caching](#caching) ·
[Storage & upgrading](#storage-and-upgrading) · [Troubleshooting](#troubleshooting) ·
[Development](#development)

---

## Install

```sh
composer require tahadudhiya/craft-menu-builder
php craft plugin/install menu-builder
```

Or install **MenuBuilder** from the Plugin Store. Installing creates `menubuilder_groups` and
`menubuilder_items`; uninstalling drops them.

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

| | |
|---|---|
| Menus | Any number, each with a handle, enable/disable, optional 1–10 max depth, site restriction, CSS class and HTML attributes. Duplicate copies settings and items |
| Link types | 8: entry, category, asset, custom URL, anchor, non-clickable heading, separator, dynamic |
| Element links | Resolved live per request and per site — never a stored URL. Per-item fallback when the target is gone |
| Visibility | 7 rule types, combined with AND, failing closed |
| Mega menus | Any item, 1–6 columns, rendered as a native `<details>` disclosure |
| Mobile | Per-item viewport, order, collapsible children and mega-menu behaviour — one menu reshaped, never a second menu |
| Dynamic navigation | Children from entries by section, categories by group or assets by volume; limit capped at 50, whitelisted order |
| Item extras | Icons (class or asset), badges in 5 styles, description, image, featured flag, ARIA label, validated attributes, and up to 20 editor-defined custom fields per menu |
| Control panel | Drag-and-drop and keyboard tree, slide-out editor, quick add, search, bulk actions, link-health badges, visual preview |
| Multi-site | Per-menu site restriction, per-item `site` rule, per-site resolution, per-site cache |
| Developer API | `craft.menuBuilder.get()` / `.breadcrumbs()` / `.getGroup()` / `.getItem()`, optional macros, a Navigation field, 2 extension events |
| Headless | GraphQL (2 queries) and a REST API (2 endpoints) — both read-only, both off until you turn them on |
| Caching | Per menu, per site, per config version, with targeted invalidation. Visibility and active state always run fresh |
| Permissions | 5 CP permissions, enforced server-side, every mutation behind POST + CSRF |
| Accessibility | Named landmarks, real lists, `aria-current="page"`, native disclosures, new-tab hints |

Not included: import/export (menus move with the database), and menu reordering from the CP.

---

# Using it

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
| Custom fields | Extra editor-defined fields offered to every item in this menu (max 20) |

**Duplicate** clones a menu's settings *and* all its items in one transaction, with a unique handle.

## Menu items

Add items with **Add menu item**, then drag them into shape — or focus a row's handle and use the
arrow keys to move it up, down, in or out one level.

| Type | Links to |
|---|---|
| Entry / Category / Asset | A Craft element. URL and title are re-resolved per request, per site |
| Custom URL | Any absolute URL, root-relative path, `mailto:` or `tel:` |
| Anchor | A `#fragment` on the current page |
| Heading | A non-clickable label |
| Separator | A divider (`<hr>`) |
| Dynamic | Children generated from a source — see [Dynamic navigation](#dynamic-navigation) |

Leave the title blank on an element-backed item to inherit the element's own title.

| Tab | Settings |
|---|---|
| Link | Type, target element or URL, clickable flag, open in new tab, `rel` presets and free-form `rel`, fallback behaviour |
| Appearance | Icon, badge + style, description, image, featured, CSS class, HTML id, custom HTML attributes |
| Accessibility | ARIA label, `title` attribute |
| Visibility | The rules below |
| Mobile | Viewport, mobile order, collapsible children, mega-menu behaviour |
| Mega menu | Enable, 1–6 columns; children pick a column |
| Custom fields | Whatever the menu defines |

**Fallback behaviour** decides what happens when a linked element is missing, disabled or has no
URL: hide the item, keep it without a link, or fall back to a URL you give.

Disabling or duplicating an item applies to its **whole subtree** — children are never promoted.

## Visibility rules

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

**"No restriction" means no rule, not an empty one.** An empty group/site/environment list, or a
date range with neither bound, hides the item and is rejected at save. "Any signed-in user" is
`loggedIn` — not a `userGroup` rule with nothing ticked.

## Mega menus

Mark any item as a mega-menu parent (1–6 columns) and assign each child a column. No second tree —
it is presentation on the hierarchy you already built. A child with no column, or one whose column
doesn't exist, falls back to the first column; a parent with no visible children renders nothing.

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

## Permissions

| Permission | Grants |
|---|---|
| `menuBuilder:view` | See the MenuBuilder section and menu trees |
| `menuBuilder:create` | Create and duplicate menu items |
| `menuBuilder:edit` | Edit, reorder, enable/disable items |
| `menuBuilder:delete` | Delete menus and items |
| `menuBuilder:manageSettings` | Create, edit and duplicate menus |

These are **in addition to** Craft's **Access the control panel** permission — grant both. The five
are independent, so grant `view` alongside whichever others a role needs. Admins bypass all five.

Every action is permission-checked server-side and every mutation requires POST with Craft's CSRF
token, including the AJAX ones. Hidden controls are a courtesy; the check on the request is the
boundary.

---

# Templating

MenuBuilder resolves navigation **data**; rendering is your template's job. Optional macros are
bundled — use them, copy them, or ignore them.

## Resolving a menu

```twig
{% set menu = craft.menuBuilder.get('main') %}
```

`get(handle, currentUri = null)` returns a `MenuBuilderTree`, or `null` when the menu doesn't exist,
is disabled, or isn't available on this site. Pass `currentUri` to match active state against
another page.

| Member | Description |
|---|---|
| `{% for node in menu %}` | Top-level nodes |
| `menu.items` | The same nodes, explicitly |
| `menu.group` | Name, handle, cssClass, htmlAttributes, maxDepth, settings |
| `menu.flatten()` | Depth-first flat list of every node |
| `menu.forViewport('mobile')` | The tree reshaped for one viewport |
| `menu|length` | Top-level node count |

The other accessors — that's the whole variable:

```twig
{% set group = craft.menuBuilder.getGroup('main') %}          {# settings only, no tree #}
{% set item  = craft.menuBuilder.getItem(42) %}               {# raw item — admin/debug #}
{% set icon  = craft.menuBuilder.iconAsset(node) %}           {# Asset behind an `asset:` icon #}
{% set img   = craft.menuBuilder.customAsset(node, 'teaser') %}
{% set trail = craft.menuBuilder.breadcrumbs('main') %}
```

## Node properties

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
| `custom(handle, default)`, `hasCustom(handle)`, `customFields` | See [Custom fields](#custom-fields) |
| `mobileVisibility()`, `mobileOrder()`, … | See [Mobile rendering](#mobile-rendering) |

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

Hand-rolled works just as well:

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
        {% if node.hasChildren() %}{# recurse #}{% endif %}
      </li>
    {% endfor %}
  </ul>
</nav>
```

Mega menus, if you render them yourself:

```twig
{% if node.megaMenu %}
  <div class="mega" style="--columns: {{ node.megaMenu.columns }}">
    {% for column, nodes in node.megaMenuColumns() %}
      <ul>{% for child in nodes %}<li><a href="{{ child.url }}">{{ child.title }}</a></li>{% endfor %}</ul>
    {% endfor %}
  </div>
{% endif %}
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
| `trail.isEmpty()`, `trail|length` | Whether there is a trail, and how long |

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
`ariaLabel`. Always resolve assets through `iconAsset()` (memoized per request — the cache stores
the reference, not the URL), and never inline an SVG asset's contents.

## Badges

```twig
{% if node.hasBadge() %}
  <span class="{{ node.badgeClass() }}">{{ node.badge }}</span>
{% endif %}
```

Badge text is free text, escaped by Twig like any string — never print it with `|raw`. The style is
a closed enum (`default`, `info`, `success`, `warning`, `critical`); `badgeClass()` returns
`menu-builder-badge` plus an allowlisted modifier, and an unknown style reads as no style. Render
the badge **inside** the link so it joins the accessible name. No front-end CSS ships for these
classes.

## Custom fields

```twig
{{ node.custom('subtitle') }}
{{ node.custom('rank', 0) }}

{% if node.hasCustom('teaser') %}
  {% set teaser = craft.menuBuilder.customAsset(node, 'teaser') %}
  {% if teaser %}<img src="{{ teaser.url }}" alt="">{% endif %}
{% endif %}
```

Each menu defines its own set (max 20) in seven types: text, textarea, number, boolean, select, URL,
asset. Every value is a plain scalar (an `asset` field stores an ID), escaped where you print it —
there is no HTML or template type. Reads fail closed against the menu's current definitions: delete
a field or change its type and values that no longer fit stop being returned. Custom fields never
affect a URL, active state, visibility or caching, and the bundled macros render none of them.

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
screen-reader users walking links that aren't on screen. Mobile *order* does nothing here: one DOM
is in one order.

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

Accessors, all failing closed toward *keeping* the link:

```twig
{{ node.mobileVisibility() }}       {# 'both' | 'desktopOnly' | 'mobileOnly' #}
{{ node.showsOnDesktop() }} {{ node.showsOnMobile() }} {{ node.isVisibleOn('mobile') }}
{{ node.mobileOrder() }}            {# int or null #}
{{ node.isMobileCollapsible() }}    {# false for a leaf, whatever is stored #}
{{ node.mobileMegaMenuBehavior() }} {# 'stack' | 'columns' | 'hide' #}
{{ node.viewportAttribute() }}      {# 'desktop' | 'mobile' | null #}
```

## Accessibility

The bundled macros are built to ship as they are. One rule underneath all of it: **an attribute must
describe something that is true**, and where two things would have to be kept in step, there is only
one of them.

| | |
|---|---|
| Landmark | One `<nav>` per menu, named with `aria-label`. An empty menu renders nothing |
| Lists | Real `<ul>`/`<li>` nesting, so item counts are announced correctly |
| Links | Ordinary `<a href>`; no `tabindex` anywhere |
| Headings | A non-clickable item is a `<span>` — not a focusable element that does nothing |
| Separators | An `<hr>` inside the `<li>`; the role isn't repeated on the list item |
| Active state | `aria-current="page"` on the active link only; ancestors get the `is-active` class |
| New tab | A hidden "(opens in a new tab)" inside a `_blank` link's accessible name (WCAG 3.2.5) |
| Mega menus | A native `<details>`: `<summary>` as the control, `role="group"` panel inside. No `aria-expanded`, `aria-controls` or `aria-haspopup` — `open` *is* the state |
| Mobile branches | The same native `<details>`, one level down. No fake buttons, no script |
| Icons / badges | Icons are `aria-hidden` / `alt=""`; badges render inside the link |
| Breadcrumbs | `<nav aria-label="Breadcrumb">` around an `<ol>`, `aria-current` on the last crumb, no separator characters |
| Custom attributes | Filtered at render as well as on save: no event handlers, no `javascript:`/`vbscript:`, none of the macro-owned or ARIA attributes |

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

The field's settings ride in `project.yaml` like any field's and are safe to deploy, but **menus
themselves are not in project config** — applying the config doesn't create them. Over GraphQL the
field exposes the selection, not the menu (`navigation { uid handle name exists enabled }`): take
the handle and query the menu itself.

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

`MenuBuilderNavigation`: `handle`, `name`, `uid`, `description`, `cssClass`, `maxDepth`,
`htmlAttributes`, `itemCount`, `items`. `MenuBuilderNavigationItem`:

| Group | Fields |
|---|---|
| Identity | `handle`, `type`, `level`, `isDynamic` |
| Link | `title`, `url`, `isClickable`, `isLinkAvailable`, `target`, `rel`, `opensInNewTab` |
| Active state | `isActive`, `isActiveAncestor` |
| Presentation | `cssClass`, `htmlId`, `htmlAttributes`, `ariaLabel`, `titleAttribute`, `description`, `featured`, `imageId` |
| Icon / badge | `iconType`, `iconClass`, `iconAssetId`; `badge`, `badgeStyle`, `badgeClass` |
| Mega menu | `megaMenu { columns }`, `megaMenuColumn` |
| Mobile | `mobileVisibility`, `mobileOrder`, `isMobileCollapsible`, `mobileMegaMenuBehavior`, `viewportAttribute` |
| Custom fields | `customFields { handle value booleanValue numberValue intValue }` |
| Hierarchy | `hasChildren`, `children` |

These read through the same fail-closed accessors as the Twig node. **Asset references are IDs, not
URLs** — feed them into Craft's `asset(id:)` query. **Row IDs are not exposed**; `handle` is an
item's stable public name.

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
        'rateLimit' => 60,                // requests per minute per caller; 0 disables
        'cacheDuration' => 0,             // Cache-Control max-age; 0 sends no-store
        'allowedOrigins' => [],           // exact CORS origins; empty sends no CORS headers
    ],
];
```

Second, each menu, through a GraphQL schema's scope (`menuBuilderGroups.{uid}:read`) — one list of
readable menus, not two.

```
GET {basePath}/v1/navigations            → every menu this caller may read
GET {basePath}/v1/navigations/{handle}   → one menu
```

`GET` and `HEAD` only; `OPTIONS` answers a CORS preflight; everything else is `405`. There is no
write surface. Query parameters: `site` / `siteId`, `currentUri`, `viewport` — unrecognized ones are
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
      "customFields": { "subtitle": "Who we are", "promoted": true },
      "hasChildren": true, "children": []
    }]
  }
}
```

`icon`, `badge` and `megaMenu` are `null` when absent; `htmlAttributes` and `customFields` are
always objects (`{}`, never `[]`). Custom fields keep their JSON type, and an asset field's value is
a Craft asset ID. Row IDs, and anything a visitor never sees, are not exposed. Menus resolve for the
**anonymous** audience whoever is asking, including a browser carrying an admin's session cookie.

| Status | `error.code` | When |
|---|---|---|
| `400` | `bad_request` | A recognized parameter is invalid. The message names it |
| `401` | `unauthorized` | No usable token and no public-schema fallback |
| `403` | `forbidden` | Your token's schema doesn't cover the site whose URL you called |
| `404` | `not_found` | The menu isn't servable to you — unknown, disabled, out of scope, or not on this site. Also what a disabled API answers |
| `405` | `method_not_allowed` | Anything but `GET`, `HEAD`, `OPTIONS` |
| `429` | `rate_limited` | Over the limit; carries `Retry-After` |

```json
{ "error": { "status": 404, "code": "not_found", "message": "No such navigation." } }
```

`404` never says why — an API that distinguished the reasons would enumerate your install's
structure. The list endpoint omits menus you can't read.

Every response carries an `ETag` and `Vary: Authorization, Origin`; send `If-None-Match` for a
`304`. `Cache-Control` is `no-store` until you set `cacheDuration`, then `public, max-age=N` for a
public-schema response and `private, max-age=N` for a token-authenticated one. CORS matching is
exact, with no credentials. Rate limiting is a fixed one-minute window per caller with
`X-RateLimit-*` headers. The URL carries the major version (`/v1/`); additive changes stay on it, so
write your consumer to ignore unknown fields.

## Extending

Two events, and they are the only extension points today.

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
invalidate menu caches itself.

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
`cacheDuration` is the upper bound, catching the one change no event announces: an entry going live
at its `postDate` or expiring. No manual cache clearing is needed in normal use.

## Storage and upgrading

Menus and items live only in the database (`menubuilder_groups`, `menubuilder_items`) — nothing is
written to `project.yaml`. Deploy them like content: with your database. The Navigation *field*
deploys through project config like any Craft field, carrying menu UIDs that only resolve where
those menus already exist.

**Import/export is not implemented** — no command, no interchange format, no CP screen. Within one
install, **Duplicate menu** clones a menu's settings and every item in one transaction.

Plugin version `1.0.0`, `schemaVersion` `1.0.0`, one migration (`src/migrations/Install.php`). Craft
runs it the usual way (the CP prompt, or `php craft up`); `safeUp()` creates the two tables guarded
by `tableExists()`, `safeDown()` drops them. **Back up the database before upgrading** — menus live
only there, and rollback is a database restore. `schemaVersion`, plus a digest of the cached
classes' shape, is hashed into every cache key, so an upgrade reads fresh keys.

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
| A referenced menu is missing after `project-config/apply` | Menus aren't in project config; only the field's settings are | Deploy the database — see [Storage and upgrading](#storage-and-upgrading) |

## Development

```sh
composer test              # PHPUnit — 1,138 unit tests, no booted Craft
composer test-integration  # PHPUnit — 446 integration tests, real Craft + real database
composer check-cs          # ECS
composer phpstan           # PHPStan (level 5)
```

The unit suite covers pure logic: link resolvers, visibility rules, mega-menu grouping, validation,
permission mapping, cache keys, link attributes. The integration suite boots a real Craft 5 app
against a real database for what only a real install can answer — the Navigation field end to end,
controller authorization, GraphQL against a real schema, and the REST API through the real
controller. It builds a throwaway install (`tests/integration-bootstrap.php`) under `tests/_craft`,
dropped and recreated each run, and refuses to start against a database whose name doesn't contain
`test`:

```sh
ddev exec composer test-integration
# or point it elsewhere:
MENUBUILDER_TEST_DB_SERVER=127.0.0.1 MENUBUILDER_TEST_DB_PORT=55012 composer test-integration
```

Internals, invariants and design decisions: **[ARCHITECTURE.md](ARCHITECTURE.md)**.
