# Changelog

All notable changes to MenuBuilder are documented here. This project follows
[Semantic Versioning](https://semver.org/) and [Keep a Changelog](https://keepachangelog.com/).

## 1.0.0 - Unreleased

First release. Everything below is new; the "Removed" and "Changed" entries record decisions made
during 1.0.0 development that affect anyone who tracked the plugin pre-release.

### Added — menus (groups)

- Multiple named menus with unique handles, description, enable/disable, and sort order.
- Optional maximum nesting depth (1–10), enforced server-side on every move — not just client-side.
- Per-menu site restriction for multi-site installs; an unavailable menu returns no tree at all
  rather than an empty one.
- Menu-level CSS class and custom HTML attributes for the rendered `<nav>`/wrapper.
- Duplicate a menu from the menus list — settings plus every item, in one transaction, with an auto-uniqued handle.
- Inline enable/disable toggle from the menus list (previously only possible by opening the edit form).
- Menus are mirrored into `project.yaml` (`menuBuilder.groups.<uid>`) and applied back to the
  database when a config change arrives from a deploy. Items stay out of project config on purpose.

### Added — menu items

- Eight item types: `entry`, `category`, `asset`, `url`, `anchor`, `nonclickable` (heading),
  `separator`, and `dynamic`.
- Element-backed links (entry/category/asset) are re-resolved live per request and per site — no
  stored URLs to go stale.
- Title fallback: leaving the title blank on an element-backed item inherits the linked element's
  own title. An explicit title is never overwritten.
- Explicit `clickable` flag, independent of whether a URL or element is set.
- Per-item fallback behaviour when a linked element is missing/disabled/unpublished: hide the item,
  keep it but disable the link, or use a fallback URL.
- `target="_blank"` support with `rel="noopener"` merged in automatically, preserving any editor-set
  `nofollow`/`sponsored`/custom `rel` values (duplicates collapsed).
- Appearance and accessibility fields: icon, badge, description, image, featured flag, CSS class,
  HTML id, ARIA label, `title` attribute, and an arbitrary HTML-attributes bag.
- Duplicate an item together with its entire subtree, in one transaction.
- Deleting an item removes its subtree via a database-level cascade.
- Orphaned-element badge in the tree when a linked element has been hard-deleted, found in at most
  one query per element type per menu.

### Added — visibility

- Per-item visibility rules combined with AND: `always`, `loggedIn`, `loggedOut`, `userGroup`,
  `site`, `dateRange`, `environment`.
- Rules evaluate against a plain-scalar context (booleans, ints, a `DateTime`, the app timezone,
  `CRAFT_ENVIRONMENT`) built once per render, so evaluation stays testable and cheap.
- Unknown or malformed rules **fail closed** — gated navigation is hidden, never leaked.
- `dateRange` parses naive `datetime-local` values against the app's configured timezone, so a
  value means the same instant regardless of server timezone; an unparseable bound, an impossible
  calendar date (e.g. `2026-02-30`), or a start after its end hides the item.
- Server-side shape validation of the whole `visibility` array, as defence-in-depth for imports and
  direct API writes that bypass the control-panel editor.

### Added — mega menus

- Any item can become a mega-menu parent (1–6 columns) via `metadata['megaMenu']`; each child picks
  a column via `metadata['megaMenuColumn']`. No second tree and no separate item type.
- `MenuBuilderNode::megaMenuColumns()` buckets already-resolved children by column (out-of-range or
  unset collapses into column 1) — pure logic, no queries.
- Optional accessible mega-menu renderer in `_macros/tree.twig` (`renderMegaMenu()`), called
  automatically by `render()`.

### Added — dynamic navigation

- `dynamic` item type whose children are synthesised at resolve time from entries by section,
  categories by group, or assets by volume.
- One bounded query per dynamic item (never per child), scoped to the current site and to
  normally-visible elements only.
- `limit` hard-clamped to 50 server-side regardless of what's stored, and `orderBy` restricted to a
  fixed whitelist — never editor-supplied SQL.
- Malformed dynamic-source config is rejected at save time rather than failing oddly at render time.

### Added — control panel

- Drag-and-drop tree built on Garnish's `DragSort`: reorder and reparent in one gesture, with a
  live drop-position indicator and depth limits enforced as you drag and again on the server.
- Slide-out item editor sharing one field partial with the full-page editor URL, which remains as a
  deep-link and no-JS fallback.
- Quick-add panel with a "Nest under" parent picker built from the unfiltered tree (search never
  narrows your parent choices), skipping separators and any parent that would exceed max depth.
- Search/filter within a menu that keeps a matching item's ancestors visible.
- Bulk enable / disable / delete via row checkboxes and a selection toolbar, routed through the same
  per-item save/delete path (so hierarchy and permission checks are never bypassed) inside one
  transaction.
- Child-count, disabled, mega-menu, and orphaned-element badges on tree rows.

### Added — developer API

- `craft.menuBuilder.get(handle, currentUri = null)` returning an iterable, countable
  `MenuBuilderTree` with `.group`, `.items`, and `.flatten()`.
- `craft.menuBuilder.getGroup(handle)` and `craft.menuBuilder.getItem(id)` as thin read-only
  accessors.
- `MenuBuilderNode` as the single stable Twig contract — no database ids, no internal columns;
  dynamic children merged transparently into `children`.
- Optional `menu-builder/_macros/tree` render macros, importable from front-end templates.
- Two extension events: `MenuBuilderLinkResolver::EVENT_REGISTER_LINK_TYPES` and
  `MenuBuilderVisibilityService::EVENT_REGISTER_VISIBILITY_RULES`.

### Added — performance

- Per-menu, per-site caching of the link-resolution pass only; visibility and active state always
  run fresh, so nothing user- or time-specific is ever shared between visitors.
- Targeted invalidation: a menu/item change invalidates that menu; an entry/category/asset
  save/delete/restore invalidates only the menus that link to it (one indexed lookup) plus menus
  containing a dynamic item. Draft, revision, and provisional-draft saves are ignored.
- Tree reads are one flat query per menu, assembled in PHP — never recursive per-node queries.

### Added — security

- Five permissions: `menuBuilder:view`, `:create`, `:edit`, `:delete`, `:manageSettings`, each
  mapped to actions by a pure static method per controller so the mapping is unit-tested.
- Every action requires a control-panel request and a permission check before touching a
  client-supplied ID; every mutation requires POST plus Craft's CSRF token.
- HTML-attribute validation on both items and menus rejects event-handler-shaped keys (`onclick`)
  and `javascript:` values as defence-in-depth beyond Twig escaping.
- URL validation accepts absolute URLs, root-relative paths, fragments, `mailto:`, and `tel:`
  without forcing a scheme onto internal paths; anchor targets reject whitespace and quote/angle
  characters.
- An existing item's `groupId` can no longer be changed, by POST tampering or otherwise — moving an
  item between menus used to silently orphan its children.

### Added — tests

- 113 PHPUnit unit tests covering link resolvers, visibility rules and context, mega-menu grouping,
  dynamic-source and mega-menu validation, item/group model validation, cache-key construction,
  link-attribute helpers, and controller permission mappings — all without booting Craft.

### Changed

- Group mutations are gated by `menuBuilder:manageSettings` instead of `menuBuilder:create`, so
  editing an existing menu no longer requires a permission labelled "create" and
  `manageSettings` is no longer dead. `create`/`edit` now mean what their labels say for items:
  `create` covers new items and duplication, `edit` covers saves/toggles/reorders of existing ones.
- `MenuBuilderGroup` uses `defineRules()` rather than overriding `rules()`, restoring Craft's
  `EVENT_DEFINE_RULES` extension point.
- Element-change cache invalidation replaced a blanket cache flush with a targeted per-menu lookup.
- The quick-add form's Title field is now optional for element-backed types, matching the full
  editor and the model, with a hint explaining the fallback.
- The quick-add form gained a real parent picker; previously new items could only be created at the
  top level and dragged into place.
- Group site restrictions are stored inside the existing `settings` JSON bag, so no migration is
  needed for them.

### Removed

Each of these was a *second* UI or code path to a behaviour that already had one — see
"Single path per behaviour" in ARCHITECTURE.md.

- Row-menu **Move up / Move down / Indent / Outdent** commands. Drag-and-drop already reparents and
  reorders through the same persistence call.
- Row-menu **Add child / Add sibling** commands. The quick-add panel's "Nest under" picker places a
  new item, and drag adjusts it.
- The **new-item route** (`menu-builder/<groupHandle>/items/new`) and `ItemsController::actionEdit`'s
  new-item branch. That action is now edit-only.
- The **drop-onto-a-row** reparenting gesture (and its hover timer). Horizontal drag movement is now
  the only reparent gesture; the removed one computed depth admissibility a second, independent way
  that could disagree with the first.
- `src/web/assets/cp/menu-builder-cp.js`, replaced by the focused `tree.js`, `slideout.js`,
  `item-fields.js`, and `menu-builder.js` bundles.

### Known limitations

- No before/after save/delete events on menus or items yet; the two registration events above are
  the only extension points.
- `ElementLinkResolver`, `MenuBuilderElementService`, `MenuBuilderDynamicNavigationService`, and the
  project-config handlers require a booted Craft app/database and are covered by manual
  verification rather than the unit suite.
- `composer check-cs` and `composer phpstan` are wired up but have no `ecs.php` / `phpstan.neon`
  checked in, so neither has an enforced configuration yet.
