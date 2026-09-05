# Changelog

All notable changes to MenuBuilder are documented here. This project follows
[Semantic Versioning](https://semver.org/) and [Keep a Changelog](https://keepachangelog.com/).

## 1.0.0 - Unreleased

First release. Everything in the plugin is new, so this entry lists what 1.0.0 ships.

### Editions

- Two editions, one implementation: **Free** (1 menu) and **Pro** (unlimited menus). Every other
  feature is available in both — items, nesting, mega menus, dynamic navigation, visibility rules,
  scheduling, preview, the Twig API, GraphQL, the REST API, the Navigation field, multi-site,
  permissions and caching.
- Edition detection uses Craft's own commercial-plugin mechanism (`Plugin::editions()` and the
  project-config edition Craft's Plugin Store sets); the plugin stores no license data of its own.
- The limit is enforced in `MenuBuilderGroupService` — the only path a menu can be created through
  — so it holds for direct POSTs, console callers and duplicates alike, and never applies to
  editing, items or rendering.
- Non-destructive by design: a lapsed Pro license removes no menus and changes no data. Existing
  menus keep working; only creating another is refused until Pro returns.
- The menus index states the edition, the menu count against its ceiling, and — on Free — an
  upgrade link into Craft's Plugin Store.
- Licensed under [The Craft License](LICENSE.md); `composer.json` declares `proprietary`, as Craft
  requires for a commercial plugin.

### Menus

- One menu on Free, any number on Pro. Named menus, each with a handle, description,
  enable/disable switch and sort order.
- Optional max nesting depth (1–10), enforced server-side on every move.
- Per-menu site restriction, CSS class and validated HTML attributes.
- Duplicate a menu — settings and every item — in one transaction, with a uniqued handle.
- Menus and items are database-only (`menubuilder_groups`, `menubuilder_items`); nothing is written
  to project config.

### Menu items

- Eight types: entry, category, asset, custom URL, anchor, non-clickable heading, separator and
  dynamic navigation.
- Element links resolved live per request and per site — titles too, with a blank title inheriting
  the linked element's own.
- Explicit clickable flag, per-item fallback behaviour (hide, unlink, fallback URL), new-tab target
  with automatic `rel="noopener"`, `rel` presets and free-form `rel`.
- Presentation: icon (icon class or Craft asset), badge with five styles, description, image,
  featured flag, CSS class, HTML id and custom HTML attributes — all validated server-side.
- Accessibility fields: ARIA label and `title` attribute.
- Enable/disable and duplicate, both applying to the item's whole subtree.
- Custom fields per menu, on a real **Craft field layout** built in Craft's own field layout
  designer (menu → **Item Fields**): any installed field type, Matrix and relational fields
  included, in as many tabs as the editor wants, with Craft's field conditions. Content is stored on
  a `MenuBuilderItemContent` element beside each item, read fresh per request and batched into one
  query per tree.

### Visibility

- Seven rule types — `loggedIn`, `loggedOut`, `userGroup`, `site`, `dateRange`, `environment`,
  `always` — combined with AND, evaluated per request and never cached.
- Unknown, empty or malformed rules fail closed (the item is hidden) and are rejected at save time.

### Mega menus, mobile and dynamic navigation

- Mega menus: any item, 1–6 columns, children assigned per column, rendered as a native `<details>`
  disclosure.
- Mobile: per-item viewport, mobile order, collapsible children and mega-menu behaviour — one menu
  reshaped, never a second menu. No breakpoint, media query or user-agent sniffing is stored or
  emitted.
- Dynamic items: children generated from entries by section, categories by group or assets by
  volume, with a limit capped at 50 and a whitelisted order.

### Control panel

- Drag-and-drop tree with a drop indicator, full keyboard equivalents and server-side depth checks.
- Slide-out item editor with a full-page fallback, quick-add panel, search/filter, bulk
  enable/disable/delete, and child/disabled/mega badges.
- Link-health badges for internal links, with a menu-wide summary and a route into the editor to fix
  each cause. External URLs are never crawled.
- Preview screen rendering the saved menu through the production macros for a chosen site, audience,
  region and device, plus the rendered markup as text.

### Developer surface

- Twig: `craft.menuBuilder.get()`, `.breadcrumbs()`, `.getGroup()`, `.getItem()`, `.iconAsset()`.
- `MenuBuilderNode` as the stable public object; breadcrumbs derived from the menu hierarchy, never
  from URL segments.
- Optional macros: `_macros/tree.twig`, `_macros/breadcrumbs.twig`, and an optional `NavAsset` script
  for mega-menu keyboard extras.
- Navigation field for entries, Matrix blocks, categories and users, storing the menu's UID.
- GraphQL: `menuBuilder` and `menuBuilderNavigations` queries, read-only, off until a menu is ticked
  into a schema's scope.
- REST API: `GET /v1/navigations` and `/v1/navigations/{handle}`, read-only, off until enabled in
  `config/menu-builder.php`, with ETags, CORS allowlist and rate limiting.
- Two extension events: register a link type, register a visibility rule.

### Performance and caching

- Per menu, per site, per config-version caching of the link-resolution pass only; visibility and
  active state always run fresh.
- Targeted invalidation on menu, item, element, container and site changes; draft and revision saves
  are ignored.
- Batch-loaded element links and flat tree queries — a cache hit is one query at any menu size, and
  query-budget tests keep it that way.

### Security

- Five permissions (`menuBuilder:view`, `:create`, `:edit`, `:delete`, `:manageSettings`), enforced
  server-side on every action, on top of Craft's own control-panel permission.
- Every mutation is a POST behind Craft's CSRF token; every CP action requires a CP request.
- Attribute, URL, id and class validation rejects event handlers and executing schemes, matched
  after whitespace and control characters are stripped.
- GraphQL and REST resolve for the anonymous audience, so a shared cache entry can never carry one
  caller's visibility decision to another.

### Accessibility

- Bundled macros emit one named `<nav>` landmark per menu, real lists, `aria-current="page"` on the
  active link only, `<hr>` separators, a hidden "(opens in a new tab)" hint, and native `<details>`
  disclosures with no `aria-expanded` to fall out of step.
- Custom HTML attributes are filtered at render as well as validated on save.
- Guarantees are summarized in [README.md](README.md#accessibility); the reasoning and the manual
  release checklist are in [ARCHITECTURE.md](ARCHITECTURE.md#accessibility).

### Tests

- 1,160 unit tests (no booted Craft) and 464 integration tests against a real Craft install and
  database, plus PHPStan level 5 and ECS.

### Known limitations

- No before/after save/delete events on menus or items; the two registration events are the only
  extension points.
- No import/export command or interchange format. Menus move with the database; **Duplicate menu**
  copies one within an install.
- Menus can't be reordered from the control panel yet; they list by `sortOrder`, then name.
- A Navigation field resolves its menu for the current request's site, not the element's.
- Clock-driven entry status changes (`postDate`/`expiryDate`) have no event; Craft's `cacheDuration`
  bounds them.
- Commerce products and other third-party element types are not synced; a link type added through
  `EVENT_REGISTER_LINK_TYPES` must invalidate menu caches itself.
- `ElementLinkResolver`, `MenuBuilderElementService`, `MenuBuilderDynamicNavigationService` and the
  services' database writes are covered by integration and manual verification rather than the unit
  suite.
