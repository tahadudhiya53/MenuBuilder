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
- Duplicate a menu from the menus list — settings plus every item, in one transaction, with an
  auto-uniqued handle. Cloned items always belong to the clone, and the whole clone rolls back if
  any part of it fails.
- Inline enable/disable toggle from the menus list (previously only possible by opening the edit form).
- Menu configuration is **database-backed only** — `menubuilder_groups` is the single source of
  truth. Menus are not written to, synchronized with, or rebuilt from Craft's project config, so
  there is no `project.yaml` copy to drift, no `project-config/apply` overwriting an editor's work,
  and no `sortOrder`/`uid` synchronization to get wrong. Items were never in project config either.

### Fixed — link types

- `dynamic` items (and every child they generate) no longer disappear from the rendered tree. The
  type had no registered link-type resolver, so it resolved as "unavailable", and an unavailable
  item whose fallback behaviour is `hide` — the default, and the only value a dynamic item's
  type-scoped editor fields can leave it with — is dropped. `DynamicLinkResolver` now resolves it as
  a container with no link of its own.
- Anchor links now use the editor's "Anchor handle" field in preference to the Advanced → Handle
  field, matching what that field's instructions promise. Previously `handle` won, so an item using
  `handle` for CSS targeting silently linked to the wrong fragment, and the anchor field's value was
  never validated.
- Entry/category/asset links now decide availability from the element's status rather than its
  `enabled` flag, so an element that is disabled *for the site being rendered* correctly falls back
  instead of linking. Disabled assets no longer resolve to a link (they already couldn't appear in a
  dynamic list).
- A `customUrl`, `fallbackUrl`, or anchor fragment that never passed validation — an imported row, a
  direct database edit — is re-checked at resolve time instead of being emitted into an `href`.
- `rel` de-duplication is now case-insensitive: an editor-typed `NOOPENER` satisfies the
  `target="_blank"` requirement instead of producing `NOOPENER noopener`.

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
- Unknown or malformed rules **fail closed** — gated navigation is hidden, never leaked. That covers
  an entry that isn't rule config at all, a missing or non-string `type`, an unregistered type, and
  a (third-party) rule that throws: the exception is caught and logged rather than escaping into the
  page render.
- `dateRange` parses naive `datetime-local` values against the app's configured timezone, so a
  value means the same instant regardless of server timezone; an unparseable bound, an impossible
  calendar date (e.g. `2026-02-30`), a start after its end, or neither bound configured hides the
  item.
- `userGroup`, `site`, and `environment` hide the item when their restriction list is empty, absent,
  or malformed, and when the current site or environment can't be identified — these rules exist
  only to restrict, so nothing to match against is missing configuration, not permission. "No
  restriction" is the absence of the rule, and the editor form only ever writes a rule that was
  actually filled in. Malformed IDs are rejected rather than coerced, so an imported `true` can
  never become group or site ID 1.
- Visibility is applied to the cached, link-resolved tree on every render and is never part of what
  gets cached: the cached payload carries no visibility data, filtering copies nodes instead of
  writing back onto them, and one cache entry therefore serves an anonymous visitor and a logged-in
  one without either seeing the other's answer.
- A cached node whose item is gone from the fresh read (deleted, or disabled since the tree was
  cached) is hidden rather than rendered unchecked — a backstop for a stale cache entry that
  outlived the rules it was supposed to be filtered by.
- Server-side shape validation of the whole `visibility` array, as defence-in-depth for imports and
  direct API writes that bypass the control-panel editor. An empty restriction list is rejected at
  save time rather than persisted as an item that silently never renders.

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
- Every drag is one transactional server call that locks the menu first, so two people rearranging
  the same menu take turns instead of racing — a move can no longer half-commit, and two
  individually valid moves can no longer combine into a loop. A refused move now says which rule it
  broke (depth, circularity, wrong menu) instead of failing generically, and the tree reloads to
  the server's version rather than keeping an optimistic position the server rejected. Drags posted
  while an earlier one is still in flight are queued rather than fired in parallel.
- Maximum nesting depth is measured against the deepest row of the subtree being moved, and is now
  enforced when moving to the top level too — previously a three-level branch could be dragged to
  the root of a two-level menu.
- Repositioning inside a large menu writes only the rows whose position actually changed, batched
  into a couple of queries rather than one per row, so a 500-item menu drags as cheaply as a
  10-item one.
- A reorder posted from a stale page (someone else added, deleted or moved an item in the meantime)
  is reconciled against the menu as it actually is: no resurrected rows, no dropped rows, no
  duplicate positions.
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
  save/delete/restore/URI-update invalidates only the menus that link to it (one indexed lookup)
  plus the menus whose dynamic items are sourced from that element's own section, category group,
  or volume. Draft, revision, and provisional-draft saves are ignored, and so are element types a
  menu can't link to. No blanket cache flush on any element change.
- A section, category group, or volume save invalidates only the menus referencing an element
  inside that container (one sub-query) — covering URI-format and asset base-URL changes, which
  fire no element event when `autoResaveEntries` is off (and never do for volumes).
- Cached trees are written with Craft's `cacheDuration` as a ceiling, so a clock-driven entry status
  change (`postDate` arriving, `expiryDate` passing) — the one change with no event to listen for —
  can't leave a menu stale indefinitely.
- Tree reads are one flat query per menu, assembled in PHP — never recursive per-node queries.

### Added — security

- `htmlId` and `cssClass` are validated like the custom-attributes bag rather than trusted for
  having their own column: an id must be a single token, and neither may contain quotes or angle
  brackets.
- Custom HTML attribute values reject `vbscript:` alongside `javascript:`, and both are matched
  after whitespace and control characters are stripped — browsers ignore those inside a scheme, so
  `java\tscript:` is the same URL to a browser and a different string to a naive check.

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

- 516 PHPUnit unit tests covering link resolvers, visibility rules and context, mega-menu grouping,
  dynamic-source and mega-menu validation, item/group model validation, cache-key construction,
  link-attribute helpers, controller permission mappings, executing-scheme URL rejection,
  cached-node immutability, and the shared helpers — all without booting Craft.
- Structural coverage of the menu (group) lifecycle: every CRUD action delegating to the service,
  handle uniqueness short-circuiting before the write, duplicate/reorder transactionality, cache
  invalidation on every frontend-affecting write, POST-only mutations, a fail-closed permission
  mapping, the unique handle index and `groupId` CASCADE in the migration, and the guarantee that
  group persistence never reaches for project config.
- `ecs.php` and `phpstan.neon`, so the long-declared `composer check-cs` and `composer phpstan`
  scripts actually run. Both are clean over `src` and `tests` (PHPStan level 5).

### Fixed — element synchronization

- **A structure move no longer leaves a stale URL in a cached menu.** Dragging an entry in the
  entries index rewrites its (and its descendants') URIs through `Elements::updateElementSlugAndUri()`,
  which fires no save event — so a menu linking to an entry in a nested-URI section kept serving the
  old path until something else invalidated it. `EVENT_AFTER_UPDATE_SLUG_AND_URI` is now listened
  for as well.
- **Editing one element no longer flushes unrelated menus.** Every watched element change used to
  invalidate every menu containing any enabled dynamic item; saving an asset now only touches menus
  whose dynamic items actually list that volume.
- **An element-backed item that outlives its element can no longer be label-less.** Leaving the
  title blank to inherit the linked element's title is still allowed — but only with the "hide the
  menu item" fallback. The other two fallbacks keep the item on the page after the element is gone,
  where there is nothing left to inherit from, so they now require an explicit title.

### Fixed

- The **environments field in the visibility editor no longer renders a raw PHP array**. `??` binds
  looser than a filter in Twig, so `envRule.environments ?? [] | join(', ')` parsed as
  `envRule.environments ?? ([]|join(', '))` — an item that actually *had* an environment rule showed
  `Array` in the field instead of its environment names.
- A tampered `visibility[dateStart][]` / `visibility[environments][]` post is ignored rather than
  reaching `explode()` as a `TypeError` (a 500) or putting a non-scalar into a persisted rule config.
- A **disabled parent no longer leaks its enabled children into the rendered menu**. The tree is
  nested by row presence, so once the disabled parent was filtered out of the query its children
  had nothing to nest under and were promoted to *top-level* items — appearing at the top of the
  navigation on a branch the editor believed they had switched off. Disabling an item now hides its
  whole subtree, matching how a failed visibility rule already behaved. The control panel tree is
  unaffected: it still lists every row, disabled ones flagged.
- The stored `rel` value no longer keeps duplicate tokens. The `nofollow`/`sponsored` checkboxes and
  the free-text `rel` field are merged token-by-token, case-insensitively — the same merge that adds
  `noopener` at render time — so ticking `nofollow` next to a typed `nofollow noreferrer` stores
  `nofollow noreferrer` rather than `nofollow nofollow noreferrer`.
- An over-long `rel`, `cssClass`, `htmlId`, `ariaLabel`, `titleAttribute`, `icon` or `badge` now
  fails with a field error instead of passing validation and then failing at the database, which
  surfaced as a save that silently didn't work.
- An item whose link resolved to an empty string is now rendered as a label rather than as
  `<a href="">`, which is a link back to the current page.

- Menu `handle` and `cssClass` had no length rule while their columns are `varchar(255)`, so an
  over-long value got past validation and failed at the database — or, on a non-strict MySQL, was
  silently truncated into a handle the editor never typed. Both are now capped at 255 in the model.
- Duplicating a menu whose handle already filled the column produced an over-long handle once the
  uniquifying numeric suffix was appended (and, after a silent truncation, a collision with the
  original). The base handle is now trimmed to make room for the suffix, and the cloned name is
  trimmed the same way.
- Saving a menu with validation errors surfaced the same error notice twice, because the controller
  set an error flash of its own on top of the one `asModelFailure()` already sets.
- **Security: `javascript:` URLs could be saved and rendered into an `href`.**
  `MenuBuilderItem::isPermissiveUrl()` leaned on `filter_var(FILTER_VALIDATE_URL)`, which rejects
  `javascript:alert(1)` (no authority component) but *accepts* `javascript://host%0Aalert(1)` — a
  form browsers execute, the `%0A` ending the `//` comment. Any user who could save a menu item
  could therefore store script that ran for every visitor of every page rendering that menu.
  Executing schemes (`javascript:`, `data:`, `vbscript:`) are now rejected outright, matched
  case-insensitively against the value with whitespace and control characters stripped, so
  `"java\tscript:"` and a leading `\x01` don't slip past either. Applies to `customUrl` and
  `fallbackUrl`.
- Per-request visibility filtering and active-state marking wrote directly onto the nodes handed
  back by the cache. Craft's serializing cache backends made that harmless in practice — every read
  returns a fresh object graph — but it meant the cache boundary held because of the backend rather
  than by construction. `MenuBuilderResolver::filterVisible()` now rebuilds each level through the
  new `MenuBuilderNode::withChildren()`, leaving the cached tree untouched.
- Duplicating a menu item could commit an unusable copy: `duplicateRecord()` ignored the result of
  its own insert, so a failed clone left `id` null and every descendant below it was then written
  with `parentId = null` — the copied subtree landing as a pile of orphaned root items, committed.
  A failed insert now aborts so the surrounding transaction rolls the whole copy back.
- A duplicated subtree could come out in a different sibling order than the original. Child rows
  were fetched unordered while `nextSortOrder()` numbers each one as it's written, so the copy
  inherited whatever order the database happened to return. Children are now copied in `sortOrder`.
- Reparenting an item through the edit form kept its old `sortOrder`, which is meaningless in a
  sibling set it never had a position in — it collided with whichever sibling already held that
  number, leaving the tie broken by row order. A reparent now appends to the new parent.
- Saving a new item whose `groupId` named a group that no longer existed (deleted in another tab, or
  an imported/tampered payload) hit the foreign key and surfaced as a 500 instead of a field error.
- The ancestor walks behind circular-reference and max-depth validation had no cycle guard. They
  only detected a cycle running *through the item being saved*, so a cycle already present in the
  stored rows — two concurrent moves each validated against the other's pre-commit state, or a
  hand-edited row — walked forever, exhausting memory (`validateHierarchy()`) or the stack
  (`subtreeHeight()`). Both now carry a visited set and fail closed.
- Active-state matching never fired for any item. `MenuBuilderActiveResolver` compared an item's
  path (which carries a leading slash — either typed by the editor or left by `parse_url()` on an
  absolute element URL) against Craft's `Request::getFullUri()`, which does not, so `/news` was
  never equal to `news`. Both sides are now normalized to a leading-slash path, and the suite
  covers the no-leading-slash shape every real request actually takes.

### Changed — architecture

- The CP permission check has one implementation. All three controllers repeated the same
  `beforeAction()` body (require a CP request, then allow admins or holders of one permission);
  they now inherit it from `BaseMenuBuilderController` and only declare *which* permission an
  action needs. The per-controller `requiredPermissionForAction()` mappings and their tests are
  unchanged; `DashboardController` gained one for consistency.
- Logic that two layers both needed is shared rather than copied: `LinkAttributeHelper::parseAttributeLines()`
  (was private and identical in both controllers), `ConfigHelper::decodeJsonBag()` /
  `normalizeIdList()` (was in both services plus `GroupsController`), and
  `DateValidationHelper::hasValidCalendarDate()` (was in `MenuBuilderItem` and `DateRangeRule`,
  each carrying a comment saying it mirrored the other). All four are now directly unit-tested,
  which none of them were as private copies.
- Cache invalidation no longer queries per item or per group. `MenuBuilderItemService` and
  `MenuBuilderElementService` resolve a group handle via the new
  `MenuBuilderGroupService::getHandleById()`, which reads the existing request-level cache — a bulk
  enable/disable of N items used to issue N extra group queries, and every entry/category/asset
  save issued one per referencing group.
- Removed dead code: the three unused ActiveRecord relations (`getItems()`, `getGroup()`,
  `getParent()`) — nothing referenced them and they were the only lazy-load N+1 path in the record
  layer — and an unreachable `match` arm and null guard in
  `MenuBuilderDynamicNavigationService::resolveElements()`, where `sourceType` is already
  constrained by the guard clause above them.
- `Install::safeDown()` no longer calls `MigrationHelper::dropAllForeignKeysOnTable()` (deprecated
  in Craft 4.0, and redundant — `menubuilder_items` owns both foreign keys and is dropped first).
  Dropping the two tables is now the entire uninstall.

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
  group/item services' actual database writes require a booted Craft app/database and are covered
  by manual verification rather than the unit suite. The unit suite pins those services'
  *structural* guarantees (transactions, cache invalidation, delegation) instead.
- Menus can't be reordered from the control panel yet. `MenuBuilderGroupService::reorder()` exists
  and is transactional, but no action or UI calls it; menus list in `sortOrder`, then name.
- Two element changes have no event to hook: a clock-driven entry status change (`postDate` /
  `expiryDate`), bounded by the `cacheDuration` ceiling rather than eliminated, and garbage
  collection hard-deleting an already-trashed element (harmless — the cache was invalidated when it
  was trashed, and the item resolves to its fallback either way).
- Commerce products and other third-party element types are not synced. There is no `product` link
  type and no Commerce dependency; a link type added through `EVENT_REGISTER_LINK_TYPES` resolves
  correctly but must invalidate menu caches itself.
