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
| Custom fields | Per-menu, editor-defined extra fields on every item — text, multi-line text, number, boolean, dropdown, URL or asset — read in Twig as `node.custom('handle')` (see [Custom fields](#custom-fields)) |
| Appearance fields | Icon (an icon handle/CSS class or a Craft Asset — never raw SVG, see [Icons](#icons)), badge + badge style (see [Badges](#badges)), description, image, "featured" flag, CSS class, HTML id — CSS class and id are validated like the attributes bag |
| Accessibility fields | ARIA label, `title` attribute; `aria-current="page"` on the active item, and an accessible bundled renderer — see [ACCESSIBILITY.md](ACCESSIBILITY.md) |
| Custom HTML attributes | Arbitrary `data-*`/HTML attributes, validated against event-handler keys (`onclick`, `onload`, `onerror`, anything starting `on`) and `javascript:`/`vbscript:` values, obfuscation included |
| Enable / disable | Per item, inline from the row menu. Disabling an item hides **its whole subtree** — enabled children are never promoted to top-level items |
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

**"No restriction" means no rule, not an empty one.** `userGroup`, `site`, `environment` and
`dateRange` exist only to restrict, so a rule with nothing to match against (an empty or malformed
list, a date range with neither bound, an unidentifiable current site or environment) hides the item
and is rejected at save time. The editor form only writes a rule you actually filled in, so this
only comes up for imports and direct API writes. "Any signed-in user" is `loggedIn`, not a
`userGroup` rule with no groups selected — and ticking both `loggedIn` and `loggedOut` on one item
can never be satisfied, so it hides the item for everyone.

### Mega menus

Mark any item as a mega-menu parent (1–6 columns) and assign each child to a column. No second
tree, no separate item type — it's presentation layered onto the hierarchy you already built.
`node.megaMenu` and `node.megaMenuColumns()` expose it in Twig, and the bundled macro renders it as
a native `<details>` disclosure — accessible with no JavaScript at all.

### Dynamic navigation

A `dynamic` item synthesises its children at render time from a source instead of storing rows:

- Source: entries by section, categories by group, or assets by volume
- Limit (hard-capped at 50 server-side) and order (newest, oldest, title A–Z, title Z–A — a fixed whitelist, never editor SQL)
- Scoped to the current site and to normally-visible elements only
- Cached and invalidated like static content, including when a brand-new element should now appear

### Control panel

- Drag-and-drop tree: reorder and reparent in one gesture, with a live drop-position indicator and max-depth enforcement
- Fully keyboard-operable: focus a row's handle and use the arrow keys to move it up, down, or in and out one level
- Slide-out item editor (with a full-page URL as a deep-link/no-JS fallback), with validation errors shown against the field that caused them
- Quick-add panel covering every item type, with a "Nest under" parent picker, which keeps what you typed if a save is rejected
- Search/filter within a menu (keeps matching items' ancestors)
- Bulk enable / disable / delete with a sticky selection toolbar and select-all
- Child-count badges, disabled badges, mega-menu badges, and **link-health badges** — every item whose link doesn't work says which way it's broken (linked content missing, disabled, unpublished, not available on this site, no URL, invalid link, dead dynamic source), with a menu-wide summary at the top and, for content that's genuinely gone, a route into the editor to relink it, give it a fallback URL, or disable it. Internal links only — MenuBuilder never crawls external URLs, and never deletes an item because the thing it pointed at went away
- Visual preview: see the menu in polished header and footer treatments — horizontal dropdowns and mega-menu panels on desktop, a stacked 390px viewport on mobile — as a logged-out visitor, a logged-in one, or a member of specific user groups, on any site you can access — see [Preview](#preview)
- Five granular permissions (below) — every control is hidden from anyone whose permissions wouldn't allow it

### Developer surface

- `craft.menuBuilder.get()` / `.breadcrumbs()` / `.getGroup()` / `.getItem()`
- **Breadcrumbs** derived from the menu's own hierarchy — never guessed from the URL's segments — see [Breadcrumbs](#breadcrumbs)
- Optional recursive render macros (`renderNav`, `render`, `renderMegaMenu`), an optional breadcrumb macro, and an optional front-end asset bundle for mega-menu keyboard behaviour
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

1. Go to **MenuBuilder → Menus** in the control panel and click **New menu**.
2. Give it a name and handle (e.g. `Main Navigation` / `main`), optionally set a max depth and site restriction, and save.
3. Open the menu and add items with the **Add menu item** panel, then drag them into shape (or move them with the arrow keys).
4. Render it:

```twig
{% set menu = craft.menuBuilder.get('main') %}

{% if menu %}
    <nav class="{{ menu.group.cssClass }}" aria-label="{{ menu.group.name }}">
        <ul>
            {% for node in menu %}
                <li class="{{ node.isActiveOrAncestor() ? 'is-active' : '' }}">
                    {% if node.isClickable %}
                        <a href="{{ node.url }}"
                           {% if node.opensInNewTab() %}target="_blank"{% endif %}
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

Prefer not to hand-write markup? Use the bundled macros:

```twig
{% import "menu-builder/_macros/tree" as menuMacros %}

{# The whole navigation, landmark included #}
{{ menuMacros.renderNav(craft.menuBuilder.get('main')) }}

{# …or just the list, if you own the wrapper #}
{{ menuMacros.render(craft.menuBuilder.get('main').items) }}

{# Optional: extra mega-menu keys (Escape, arrow keys, Home/End).
   Not required — the disclosure itself is a native <details>. #}
{% do view.registerAssetBundle('Tahadudhiya\\MenuBuilder\\web\\assets\\nav\\NavAsset') %}
```

`renderNav()` names the `<nav>` after the menu (pass a second argument for your own label) and puts
the menu's CSS class and HTML attributes on it; `render()` calls `renderMegaMenu()` automatically
for any mega-menu-enabled node. A third argument, `'none'`, renders mega-menu columns in flow with
no disclosure at all, for a theme that provides its own. Every accessibility attribute they emit describes something the
markup actually does — see **[ACCESSIBILITY.md](ACCESSIBILITY.md)**. The macros are a starting
point, not a requirement: copy them into your own templates and edit freely.

---

## Twig API

### `craft.menuBuilder.get(handle, currentUri = null)`

Returns a `MenuBuilderTree`, or `null` when the menu doesn't exist, is disabled, or isn't
available on the current site. Pass `currentUri` to override active-state matching (defaults to
the current request URI) when building a specialized front-end render for another page.

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
| `target`, `rel`, `opensInNewTab()` | `rel` already includes `noopener` for new-tab links. `opensInNewTab()` is true only for a *link* that opens one — emit `target` and the "opens in a new tab" hint from it |
| `cssClass`, `htmlId`, `htmlAttributes`, `safeHtmlAttributes()` | Editor-supplied, server-validated. Render `safeHtmlAttributes()` — it re-checks the stored bag and drops event handlers, `javascript:` values and reserved/ARIA attribute names ([ACCESSIBILITY.md](ACCESSIBILITY.md)) |
| `ariaLabel`, `titleAttribute` | Accessibility |
| `icon`, `iconType()`, `iconClass()`, `iconAssetId()`, `hasIcon()` | The icon — see [Icons](#icons). `icon` is the raw stored reference; the accessors are the safe way to read it |
| `badge`, `badgeStyle`, `hasBadge()`, `badgeClass()` | The badge — see [Badges](#badges). `badge` is the text, `badgeClass()` the safe class list |
| `description`, `image`, `featured` | Presentation extras; `image` is an asset ID |
| `level` | 1-based depth |
| `children`, `hasChildren()`, `parent` | Hierarchy; dynamic children are merged into `children` transparently |
| `isActive`, `isActiveAncestor`, `isActiveOrAncestor()` | Per-request, never cached |
| `megaMenu`, `megaMenuColumns()` | `megaMenu` is null unless this node is a mega-menu parent; `megaMenuColumns()` returns `{column: nodes}` |
| `megaMenuColumn` | This node's column under its mega-menu parent |
| `isDynamic` | True for nodes synthesised from a dynamic source |
| `customFields`, `custom(handle, default = null)`, `hasCustom(handle)` | Editor-defined fields — see [Custom fields](#custom-fields) |

### Badges

A badge is the short flag beside an item's label — **Products [NEW]**. It is two values, on
storage that already existed:

| Value | Where | What it is |
|---|---|---|
| text | the `badge` column | Free text, exactly as the editor typed it. Blank means no badge |
| style | `metadata['badgeStyle']` | One of `default`, `info`, `success`, `warning`, `critical` |

The two halves are treated in opposite ways, and that difference is the whole safety model:

- **Text is text.** It is never sanitized into something else, because it is never emitted as
  markup: `<script>alert(1)</script>` typed into the badge field is a badge that *reads*
  `<script>alert(1)</script>`, escaped by Twig at render time like any other string. Stripping
  `<` would mangle legitimate badges (`<3`, `Tea & Coffee`, `50% off`) and buy nothing. Never
  print a badge with `|raw`.
- **Style is a closed enum**, because it is the half that reaches a `class` attribute. Reading it
  fails closed: a value that isn't one of the five — a legacy row, a hand-written database
  update, a crafted post — reads back as "no style", and only the base class is emitted. An
  unknown style is also rejected at validation, so it never gets stored in the first place.

An **empty badge is not a badge**: whitespace-only text normalizes to `null`, and a style with no
text renders nothing at all, so a style left behind by a cleared badge can't produce an empty
pill. Inner whitespace is collapsed, so a pasted `"JUST\n\tIN"` and a typed `"JUST IN"` are one
stored value. Text is capped at 255 characters, with a field error rather than a database error.

#### Recommended rendering

```twig
{% if node.hasBadge() %}
    <span class="{{ node.badgeClass() }}">{{ node.badge }}</span>
{% endif %}
```

`badgeClass()` returns `menu-builder-badge`, plus `menu-builder-badge--info` (and so on) for a
styled badge — the modifier comes from the allowlist, never from stored text, so it is safe in an
attribute. The plugin ships no front-end CSS for those classes; style them to match your site.

Render the badge **inside** the link or label, as the bundled macro does, so it becomes part of
the item's accessible name ("Products NEW") rather than a word a screen reader meets out of
context. A badge is presentation only: it has no effect on an item's URL, its active state, its
visibility, or how its tree is cached.

### Custom fields

Icon, badge, description, image and "featured" are built in. **Custom fields** are for whatever
else a particular site needs on a menu item — a subtitle, a promo flag, a colour swatch, a
teaser image. Each menu defines its own set under **Menus → (a menu) → Custom fields**; every
item in that menu is then offered them, the way every entry in a section shares a field layout.

A definition is a name, a handle, and one of seven types:

| Type | Stored as | Notes |
|---|---|---|
| `text` | string | Up to 255 characters |
| `textarea` | string | Up to 2000 characters |
| `number` | int / float | A numeric string posted by the form becomes a number |
| `boolean` | bool | An off switch stores nothing |
| `select` | string | Must be one of the definition's own options |
| `url` | string | Validated exactly like an item's URL — `javascript:`, `data:` and `vbscript:` are rejected |
| `asset` | int | A Craft Asset **ID**, resolved at render time |

There is deliberately no "HTML" or "template" type: every value is a plain scalar, escaped by Twig
where you print it, so nothing an editor types here can become executable content. As with badges,
text is kept exactly as typed rather than sanitized — never print it with `|raw`.

```twig
{{ node.custom('subtitle') }}
{{ node.custom('rank', 0) }}

{% if node.hasCustom('teaser') %}
    {% set teaser = craft.menuBuilder.customAsset(node, 'teaser') %}
    {% if teaser %}<img src="{{ teaser.url }}" alt="">{% endif %}
{% endif %}
```

`custom()` returns `null` (or the default you pass) for a field the item hasn't filled in.
`customAsset()` resolves an `asset` field's ID to the Asset, memoized per request, and returns
`null` if the asset has since been deleted.

Reads **fail closed against the menu's current definitions**: delete a field, change its type, or
remove a dropdown option, and values that no longer fit simply stop being returned — no item rows
are rewritten, and nothing stale can render. Values travel with the item when it is duplicated,
and with the menu when the menu is duplicated; deleting an item deletes them with it.

A menu can define up to 20 custom fields. Custom fields are presentation only: they have no
effect on an item's URL, its active state, its visibility, or how its tree is cached, and the
bundled macro renders none of them — they are for your own templates.

### Icons

An item's icon lives in one column and has exactly three forms:

| Stored | Means |
|---|---|
| empty / `null` | No icon |
| `asset:123` | A Craft Asset — an uploaded SVG or PNG |
| anything else | An icon handle or CSS class list: `icon-cart`, `fa fa-cart`, `heroicons/outline/home` |

The third form is what an icon has always been in this plugin, so icons saved before the other
two existed keep working unchanged. `class:icon-cart` is accepted on input and normalized to
`icon-cart`, so one icon never has two stored spellings.

**Raw SVG markup is not a storable form, deliberately.** A pasted `<svg>` is an
arbitrary-script vector that no length limit makes safe, and the templates that render it aren't
ones this plugin owns. Class values are allowlisted to letters, digits, spaces and `- _ . : /`,
so a stored icon *cannot* contain markup or break out of an attribute — even if a template
renders it with `|raw`. Upload the SVG and pick it as an asset instead.

Reading is fail-closed as well: `iconClass()` returns `null` for any value that wouldn't validate
today, so a row written straight into the database can't reach a template either.

#### Recommended rendering

Read the icon through the accessors, never through the raw `icon` string:

```twig
{% for node in menu %}
    <a href="{{ node.url }}">
        {% if node.iconType() == 'class' %}
            {# Icon fonts and CSS sprite sets: the class does the drawing. #}
            <span class="icon {{ node.iconClass() }}" aria-hidden="true"></span>
        {% elseif node.iconType() == 'asset' %}
            {% set icon = craft.menuBuilder.iconAsset(node) %}
            {% if icon %}
                {# <img> and not inlined markup: an SVG loaded through src can't run script. #}
                <img src="{{ icon.url }}" alt="" width="24" height="24" loading="lazy">
            {% endif %}
        {% endif %}
        {{ node.title }}
    </a>
{% endfor %}
```

Three things that matter:

- **Icons are decorative.** `aria-hidden="true"` on the span, `alt=""` on the image — the item's
  title (or `ariaLabel`) is the accessible name, and an icon repeating it is noise for a screen
  reader. If an icon is ever an item's *only* label, give that item an `ariaLabel`.
- **Resolve assets through `craft.menuBuilder.iconAsset(node)`.** The cached tree stores the
  reference, never the URL — an asset can be re-uploaded or moved without any menu item changing,
  so a cached URL would go stale with nothing to invalidate it. That accessor memoizes per
  request, so twenty items sharing one icon cost one query. A deleted asset returns `null`; the
  menu item still renders, just without its icon.
- **Never inline an SVG asset's file contents.** `<img src>` is the safe rendering; reading the
  file and printing it with `|raw` puts the uploader's markup into your page.

The bundled macro (`{% import "menu-builder/_macros/tree" as menuMacros %}`) already does all of
this — see `src/templates/_macros/tree.twig` for the reference implementation.

### Active state

`isActive` is true for the one node whose URL **is** the page being served; every ancestor of it
gets `isActiveAncestor` (including grandparents and above), and `isActiveOrAncestor()` is the
"open branch" convenience for styling. Put `aria-current="page"` on `isActive` only — it must
identify the current page, not the branch leading to it.

Matching compares paths, so all of these are the same page: `/news`, `news`,
`https://example.test/news/`, `/news?page=2#top`. Nothing matches by prefix, so `/news` is not
active on `/newsletter`, and a parent item lights up as an ancestor only when it actually has a
child item for the current page.

Never active: a URL on a host that isn't part of this Craft install (an external custom URL with a
coincidentally matching path), a `mailto:`/`tel:` link, an unavailable or blank link, and a
fragment-only anchor item. Active state is recomputed on every request and is never cached.

### Breadcrumbs

```twig
{% set trail = craft.menuBuilder.breadcrumbs('main') %}
```

`craft.menuBuilder.breadcrumbs(menu, currentUri = null)` returns the **root-to-current chain** of
the menu item that *is* the page being served:

```
Home  →  Products  →  Shoes  →  Running Shoes
```

`menu` is a handle, or a `MenuBuilderTree` you already resolved — pass the tree when the page also
renders the menu, and the page resolves it once:

```twig
{% set menu  = craft.menuBuilder.get('main') %}
{% set trail = craft.menuBuilder.breadcrumbs(menu) %}
```

#### The trail

| Member | Description |
|---|---|
| `{% for crumb in trail %}` | The crumbs, **root first, current page last** |
| `trail.crumbs` | The same list, explicitly |
| `trail.current()` | The node for the page being served — the last crumb — or `null` |
| `trail.root()` | The top-level node the trail descends from, or `null` |
| `trail.ancestors()` | Every crumb *except* the last |
| `trail.isEmpty()` / `trail|length` | Whether there is a trail at all, and how long it is |
| `trail.group` | The `MenuBuilderGroup` the trail came from |

Each crumb **is** a `MenuBuilderNode` — the very same object the menu renders, not a parallel
breadcrumb type. So a crumb has everything a node has: `title`, `url`, `isClickable`,
`isActive`, `level` (its 1-based depth, which for a trail is also its position), `custom()`,
`safeHtmlAttributes()`, and the rest of [Node properties](#node-properties).

#### Exact behaviour

The trail is **the menu hierarchy**, and nothing else:

- The last crumb is the node with `isActive` — the same single node the menu puts
  `aria-current="page"` on, matched the same way ([Active state](#active-state)). The crumbs before
  it are the items it is nested under **in the menu**, which is often not what its URL looks like:
  a page at `/products/2024/shoes` placed under "Footwear" gets *Footwear → Running Shoes*.
- **Breadcrumbs are never assembled from the URL.** MenuBuilder does not split the request path
  into segments, not even as a fallback, because a path segment is not a page (`/products/2024/…`
  would produce a "2024" crumb linking to a 404) and a slug is not a title. When the menu can't
  answer, it says so.
- `null` means **there is no such menu** — it doesn't exist, is disabled, or isn't available on
  this site (the same three outcomes as `get()`). Usually a typo in a template.
- An **empty trail** (`trail.isEmpty()`) means the menu is there but this page isn't in it.
  Ordinary, and the correct answer for: a page no item points at, an item that is **disabled**
  (a disabled item, and everything under it, is not in the menu at all), an item whose linked
  entry is **unpublished, disabled or deleted** (there is no page to be on), an item that was
  deleted, an external custom URL that merely shares a path with the request, a `mailto:`/`tel:`
  item, and a fragment-only anchor. Render nothing.
- An **ancestor** whose own link is unavailable stays in the trail as an unlinked crumb — the path
  the editor built is not silently shortened. Check `crumb.isClickable` before emitting an `<a>`;
  a heading item behaves the same way.
- The same URL placed **twice** in one menu (a "Contact" in the header and in a utility strip)
  resolves to the **first in document order** — the order the control panel shows and the menu
  renders in.
- **Multi-site** follows active state exactly: an item pointing at a sibling site is not the page
  being served while this site is being rendered, so it starts no trail here; on its own site it
  does. Site-restricted menus and per-item visibility rules apply first, so a crumb an audience
  can't see is not in their trail.
- Nothing about a trail is cached — it is derived from active state, which is per-request by
  definition.

#### Rendering

```twig
{% import "menu-builder/_macros/breadcrumbs" as crumbs %}

{{ crumbs.render(craft.menuBuilder.breadcrumbs('main')) }}
{{ crumbs.render(trail, 'You are here'|t, false) }}  {# own label; last crumb as text, not a link #}
```

The macro emits a named `<nav aria-label="Breadcrumb">` landmark around an `<ol>`, with
`aria-current="page"` on the last crumb only, non-clickable crumbs as text rather than fake links,
and **no separator characters** — a literal `›` between items is read out by a screen reader on
every crumb. Draw it in CSS instead:

```css
.menu-builder-breadcrumbs li + li::before { content: "›"; margin: 0 .5em }
```

A missing menu and an empty trail both render nothing at all. Hand-rolling it is fine too:

```twig
{% set trail = craft.menuBuilder.breadcrumbs('main') %}
{% if trail is not empty %}
    <nav aria-label="{{ "Breadcrumb"|t }}">
        <ol>
            {% for crumb in trail %}
                <li>
                    {% if crumb.isClickable and not loop.last %}
                        <a href="{{ crumb.url }}">{{ crumb.title }}</a>
                    {% else %}
                        <span{% if loop.last %} aria-current="page"{% endif %}>{{ crumb.title }}</span>
                    {% endif %}
                </li>
            {% endfor %}
        </ol>
    </nav>
{% endif %}
```

### Accessibility

The bundled macros are built to be shipped as they are: one named `<nav>` landmark per menu, real
lists, `aria-current="page"` on the active link and nowhere else, headings that are labels rather
than focusable non-links, separators that are `<hr>`s, and a hidden "(opens in a new tab)" in the
name of any `_blank` link.

A mega menu is a **native `<details>` disclosure**. Its `open` attribute is at once what renders
the panel, what a click or Enter/Space toggles, and what a screen reader announces — so there is no
`aria-expanded` to keep in step, and no way for the screen and the accessibility tree to disagree.
It works with no JavaScript and no CSS from you. The one thing your CSS must never do is reveal a
panel whose `<details>` is closed; open it by setting `open` instead.

`NavAsset` (above) is an optional *enhancement*, not a requirement: Escape to close and return
focus, arrow keys and Home/End inside an open panel, and closing one panel when another opens.

Custom HTML attributes are filtered at render as well as validated on save — no event handlers, no
`javascript:` values, and none of the ARIA states or macro-owned attributes an item could otherwise
overstate itself with.

Your CSS still owns a visible focus indicator, submenus that open on `:focus-within` and not only
`:hover`, and contrast. Full detail, plus a manual test checklist for a release:
**[ACCESSIBILITY.md](ACCESSIBILITY.md)**.

### Secondary accessors

```twig
{% set group = craft.menuBuilder.getGroup('main') %}  {# settings only, no tree #}
{% set item  = craft.menuBuilder.getItem(42) %}       {# raw, unresolved item — admin/debug use #}
{% set icon  = craft.menuBuilder.iconAsset(node) %}   {# the Asset behind an `asset:` icon, or null #}
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

## Preview

Every menu has a **Preview** button on its tree screen (`menu-builder/<handle>/preview`, permission:
`menuBuilder:view`). It renders the menu the way a chosen visitor would receive it:

| Option | What it changes |
|---|---|
| **Site** | Which site's content the links resolve against — limited to the sites you can access |
| **Audience** | Logged out, logged in, or logged in as a member of chosen user groups. This is what the per-item visibility rules are evaluated against |
| **Shown in** | Header and footer by default, or either region on its own. The footer uses a polished column treatment. MenuBuilder doesn't record where your templates render a menu, so this is a presentation control rather than a saved setting |
| **Device** | Desktop or mobile — a width for the preview surface, so wrapping and depth can be judged |

The preview renders on a **stage**: a complete illustrative company website, so the navigation is
seen the way a visitor meets it rather than as an indented list. Desktop lays the header menu out
as a horizontal bar with viewport-safe, full-width mega panels and the footer as a stable column
grid (including permanently visible mega-menu columns without hover or click flyouts). Mobile uses
a clean 390px viewport with the header navigation closed behind its hamburger initially and footer
mega groups kept visible in responsive columns. Links stay in the preview — clicking one reports where it points instead of
navigating you away.

### What preview represents — exactly

Preview runs the **same content pipeline a front-end request runs**: the same link resolution, the same
cached tree, the same visibility rules, and the same
`_macros/tree.twig` renderer this plugin ships. The navigation markup on the stage *is* that macro's
output — the stage adds a page around it and styles it. The preview deliberately does not invent a
current page, so active-state marking remains a front-end request concern. The "Rendered markup"
panel at the bottom shows the same markup as text — re-indented one element per line and numbered,
with a copy button — so `aria-label`, the `<details>`
disclosure, `rel` and `target` can be checked without leaving the control panel. The
re-indenting is for reading only: nothing is added, removed or reordered, and the preview above is
rendered from the unformatted output.

- **It shows structure, state and attributes — not your theme.** Your site's CSS and JavaScript are
  deliberately not loaded (running a site's front-end JS inside the control panel would be an
  execution surface, not a preview), so the stage uses MenuBuilder's own neutral navigation styling.
  Hierarchy, dropdowns, mega-menu columns, active state, icons, badges, separators and accessibility
  attributes are real; your fonts, colours and animations are not reproduced. One visible
  consequence: an icon-font icon shows as a neutral placeholder because that font isn't loaded in
  the control panel, while an asset icon shows its real image.

- **It shows saved data.** MenuBuilder has no draft state — every change in the CP (drag, reorder,
  enable, save) is written immediately — so there are no unsaved changes to preview, and the screen
  never pretends otherwise.
- **It changes nothing.** Previewing performs no writes: not the menu, not its items, not your
  session. Switching audience or site changes only what that page renders.
- **Disabled items and disabled menus are absent**, because they render nowhere on the front end. A
  menu restricted away from the selected site reports that it renders nothing there.
- **Time and environment are real, never simulated.** `dateRange` and `environment` visibility rules
  answer what they answer for a visitor at that moment, so a scheduled item appears in preview when
  it would appear for real — not before.
- **It cannot expose unpublished content.** Links resolve through exactly the publicly-available
  boundary the front end uses; a draft, disabled or expired element falls back or disappears here
  the same way it would for a visitor. Simulating an audience reveals which *menu items* are
  restricted to a user group — something the item editor already shows the same user — and nothing
  else.
- **Device is a width, not a user agent.** No UA is spoofed. The mobile frame is a 390px viewport
  with a stacked navigation; the toggle above it is preview chrome, because a real site owns its own
  mobile disclosure.

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
Unknown rule types fail closed, so a rule that isn't registered hides its item rather than showing
it — and so does a rule that throws: the exception is caught and logged rather than escaping into
the page render. Return `false` for config your rule doesn't recognise; don't guess.

---

## Caching

Resolved menus are cached per **menu + site + configuration version**, and only the link-resolution
pass is cached — visibility filtering and active-state marking always run fresh per request, so
nothing user-specific, date-specific or page-specific is ever shared between visitors. Two visitors
with different permissions safely share one cache entry; so do yesterday's and today's visitors, and
two different pages on the site.

The configuration version means an edited menu, or a plugin upgrade, reads a *different* key rather
than an entry built under the old one.

Invalidation is targeted rather than a blanket flush:

- Saving/moving/reordering/duplicating/deleting a menu or item invalidates that menu only — on every
  site, in one step, including sites that are currently disabled.
- Bulk item actions invalidate once the whole batch has committed, so a visitor mid-batch can't
  leave a half-applied menu in the cache.
- Saving, deleting, or restoring an entry/category/asset invalidates only the menus that actually
  link to it (one indexed lookup), plus menus whose dynamic items are sourced from that element's own
  section/category group/volume.
- Draft, revision, and provisional-draft element saves are ignored on purpose — unpublished edits
  never disturb live navigation.
- Saving or deleting a **site** invalidates everything — a site's base URL, language or existence
  is what every cached URL and title was resolved against. This covers a `project-config/apply` on
  deploy, which is how a site change usually arrives in production.

No manual cache clearing is needed in normal use. Craft's `cacheDuration` still applies as an upper
bound, which is what catches the two changes no event announces: an entry going live at its
`postDate`, or expiring at its `expiryDate`.

---

## Where menus are stored

Menus and their items live in the database (`menubuilder_groups`, `menubuilder_items`), and the
database is the single source of truth. Nothing about a menu is written to `project.yaml` — menus
are editor-managed data, not deployable structure, so there's no config to drift, apply, or rebuild.
Deploy them like any other content: with your database.

---

## Development

```sh
composer test        # PHPUnit — 682 unit tests
composer check-cs    # ECS
composer phpstan     # PHPStan (level 5)
```

The unit suite covers pure logic without booting Craft: link resolvers, visibility rules and
context, mega-menu grouping, validation, permission mapping, cache keys, and link-attribute
helpers. Code that needs a live Craft app/DB (`ElementLinkResolver`, `MenuBuilderElementService`,
`MenuBuilderDynamicNavigationService`, the group/item services' database writes) is verified
manually — see
[ARCHITECTURE.md](ARCHITECTURE.md#known-limitations).

Internals, invariants, and design decisions: **[ARCHITECTURE.md](ARCHITECTURE.md)**.
Accessibility guarantees and the manual release checklist: **[ACCESSIBILITY.md](ACCESSIBILITY.md)**.
Release history: **[CHANGELOG.md](CHANGELOG.md)**.
