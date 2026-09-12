# Changelog

All notable changes to MenuBuilder are documented here. This project follows
[Semantic Versioning](https://semver.org/) and [Keep a Changelog](https://keepachangelog.com/).

## 1.0.0 — Unreleased

**Status: release candidate.** The code is feature-complete for 1.0.0, but no Git tag or Plugin
Store release exists yet. The released version comes from the Git tag, not from `composer.json`.
This entry becomes `1.0.0 - YYYY-MM-DD` when the release is tagged.

First release, so everything is new. This entry lists what 1.0.0 ships rather than what changed.

### Added

**Menus**

- Named menus with a handle, description, enable/disable switch and sort order. One menu on the
  Free edition, any number on Pro.
- Optional maximum nesting depth (1–10), enforced server-side on every move.
- Per-menu site restriction, CSS class and validated HTML attributes.
- Duplicate a menu — settings and every item — in one transaction, with a uniqued handle.
- Reorder the menus list by drag or by keyboard, which sets the order menus are listed in across the
  control panel, GraphQL and the REST list endpoint.

**Menu items**

- Eight item types: entry, category, asset, custom URL, anchor, non-clickable heading, separator and
  dynamic navigation.
- Element links resolved live per request and per site — titles included, with a blank title
  inheriting the linked element's own.
- Explicit clickable flag, per-item fallback behaviour (hide, unlink, fallback URL), new-tab target
  with automatic `rel="noopener"`, `rel` presets and free-form `rel`.
- Presentation: icon (icon class or Craft asset), badge with five styles, description, image,
  featured flag, CSS class, HTML id and custom HTML attributes — all validated server-side.
- Accessibility fields: ARIA label and `title` attribute.
- Enable/disable and duplicate, both applying to the item's whole subtree.
- Custom fields per menu, on a real Craft field layout (menu → **Item Fields**): any installed field
  type, Matrix and relational fields included, in as many tabs as wanted, with Craft's field
  conditions. Content lives on a `MenuBuilderItemContent` element beside each item and is read fresh
  per request, batched into one query per tree.

**Visibility, mega menus, mobile and dynamic navigation**

- Seven visibility rule types — `loggedIn`, `loggedOut`, `userGroup`, `site`, `dateRange`,
  `environment`, `always` — combined with AND, evaluated per request and never cached. Unknown,
  empty or malformed rules fail closed and are rejected at save time.
- Mega menus on any item, 1–6 columns, children assigned per column, rendered as a native
  `<details>` disclosure.
- Mobile presentation per item: viewport, mobile order, collapsible children and mega-menu
  behaviour — one menu reshaped, never a second menu. No breakpoint, media query or user-agent
  sniffing is stored or emitted.
- Dynamic items whose children are generated from entries by section, categories by group or assets
  by volume, with a limit capped at 50 and a whitelisted order.

**Control panel**

- Drag-and-drop tree with a drop indicator, full keyboard equivalents and server-side depth checks.
- Slide-out item editor with a full-page fallback, quick-add panel, search/filter, bulk
  enable/disable/delete, and child/disabled/mega badges.
- Link-health badges for internal links, with a menu-wide summary and a route into the editor to fix
  each cause. External URLs are never crawled.
- Drag-and-drop and keyboard reordering of the menus list itself, gated by `menuBuilder:manageSettings`.
- Preview screen rendering the saved menu through the production macros for a chosen site, audience,
  region and device, plus the rendered markup as text.

**Developer surface**

- Twig: `craft.menuBuilder.get()`, `.breadcrumbs()`, `.getGroup()`, `.getItem()`, `.iconAsset()`.
- `MenuBuilderNode` as the stable public object; breadcrumbs derived from the menu hierarchy, never
  from URL segments.
- Optional macros: `_macros/tree.twig`, `_macros/breadcrumbs.twig`, and an optional `NavAsset` script
  for mega-menu keyboard extras.
- Navigation field for any Craft field layout (entries, Matrix blocks, categories, users), storing
  the menu's UID.
- GraphQL: `menuBuilder` and `menuBuilderNavigations` queries, read-only, absent until a menu is
  ticked into a schema's scope.
- REST API: `GET {basePath}/v1/navigations` and `/v1/navigations/{handle}`, read-only, off until
  enabled in `config/menu-builder.php`, with ETags, a CORS allowlist, and rate limiting of both
  requests and failed authentications.
- Two extension events: register a link type, register a visibility rule.

**Editions**

- Two editions, one implementation: **Free** (1 menu) and **Pro** (unlimited menus). Every other
  feature is available in both.
- Edition detection uses Craft's own commercial-plugin mechanism (`Plugin::editions()` and the
  project-config edition Craft's Plugin Store sets); the plugin stores no license data of its own.
- The limit is enforced in `MenuBuilderGroupService` — the only path a menu can be created through —
  so it holds for direct POSTs, console callers and duplicates alike, and never applies to editing,
  items or rendering.
- Non-destructive by design: a lapsed Pro license removes no menus and changes no data. Existing
  menus keep working; only creating another is refused until Pro returns.
- Licensed under [The Craft License](LICENSE.md); `composer.json` declares `proprietary`, as Craft
  requires for a commercial plugin.

### Performance

- Resolved menus cached per menu, per site, per configuration version — the link-resolution pass
  only. Visibility filtering and active-state marking always run fresh.
- Targeted invalidation on menu, item, element, container and site changes; draft and revision saves
  are ignored.
- Batch-loaded element links, batch-loaded custom field content and flat tree queries: a cache hit is
  one query at any menu size, and query-budget tests keep it that way.

### Security

- Five permissions (`menuBuilder:view`, `:create`, `:edit`, `:delete`, `:manageSettings`), enforced
  server-side on every action, on top of Craft's own control-panel permission.
- Every mutation is a POST behind Craft's CSRF token; every control-panel action requires a
  control-panel request.
- Attribute, URL, id and class validation rejects event handlers and executing schemes, matched after
  whitespace and control characters are stripped.
- GraphQL and REST resolve for the anonymous audience, so a shared cache entry can never carry one
  caller's visibility decision to another.
- The REST API rate-limits **failed authentications** on a second, address-keyed one-minute window
  that runs before the token is resolved, so repeated bad bearer tokens are refused rather than being
  free. Both limiters are switched by the one `rateLimit` setting; a valid token is never charged to
  the failure budget.

### Accessibility

- The bundled macros emit one named `<nav>` landmark per menu, real lists, `aria-current="page"` on
  the active link only, `<hr>` separators, a hidden "(opens in a new tab)" hint, and native
  `<details>` disclosures with no `aria-expanded` to fall out of step.
- Custom HTML attributes are filtered at render as well as validated on save.
- The guarantees are summarized in [README.md](README.md#accessibility); the reasoning and the manual
  release checklist are in [ARCHITECTURE.md](ARCHITECTURE.md#accessibility).

### Known limitations

These are accepted for 1.0.0, not planned work.

- **No before/after save/delete events on menus or items.** The two registration events are the only
  extension points.
- **No import/export command or interchange format.** Menus travel with the database; **Duplicate
  menu** copies one within an install.
- **A Navigation field resolves its menu for the current request's site**, not the element's.
- **Clock-driven entry status changes** (`postDate`/`expiryDate`) fire no event; Craft's
  `cacheDuration` bounds the staleness rather than eliminating it.
- **Third-party element types are not synced.** Commerce products and the like have no link type, and
  a link type added through `EVENT_REGISTER_LINK_TYPES` must invalidate menu caches itself.
- **Orphaned items are surfaced, not repaired.** An item whose linked element was hard-deleted is
  badged; nothing reassigns or cleans it up.
- **Preview shows saved data only**, and does not simulate time.
- **Control-panel template shape is verified manually.** The permission *gate* is covered by
  automated tests; whether a control is offered to someone the gate would refuse is not.
- **Some Craft-dependent code is covered by integration tests and manual verification rather than
  unit tests** — `ElementLinkResolver`, `MenuBuilderElementService`,
  `MenuBuilderDynamicNavigationService` and the services' database writes.

The reasoning behind each of these is in [ARCHITECTURE.md](ARCHITECTURE.md#known-limitations).
