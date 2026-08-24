# MenuBuilder architecture

This document describes the plugin's internals **as they exist today** — layers, invariants, and
the reasoning behind decisions that aren't obvious from the code. It is not a feature list (see
[README.md](README.md)) and not a history (see [CHANGELOG.md](CHANGELOG.md)).

Read this before changing the resolve pipeline, the cache boundary, hierarchy validation, or the
permission mapping. Those four places carry most of the plugin's invariants.

---

## Layers

```
CP request  → Controller → Service → Model ⇄ Record → DB → renderTemplate/redirect
Twig render → MenuBuilderVariable → MenuBuilderResolver → cache / links / visibility / active → MenuBuilderTree
```

| Layer | Classes | Rules it obeys |
|---|---|---|
| Controllers | `GroupsController`, `ItemsController`, `DashboardController` | The **only** classes that touch `craft\web\Request` or the session. Permission-check in `beforeAction()` before any service call. |
| Services | `MenuBuilderGroupService`, `MenuBuilderItemService`, `MenuBuilderResolver`, `MenuBuilderLinkResolver`, `MenuBuilderVisibilityService`, `MenuBuilderCacheService`, `MenuBuilderActiveResolver`, `MenuBuilderElementService`, `MenuBuilderDynamicNavigationService` | The only classes that query Records. Own all business logic, hierarchy integrity, and transactions. |
| Models | `MenuBuilderGroup`, `MenuBuilderItem`, `MenuBuilderNode`, `MenuBuilderTree`, `ResolvedLink`, `MenuBuilderMegaMenuConfig` | Validation lives here (`defineRules()`), never in Records or controllers. |
| Records | `MenuBuilderGroupRecord`, `MenuBuilderItemRecord` | Thin `ActiveRecord`, no business logic. Never visible to controllers or Twig. |

All services are registered as plugin components in `MenuBuilder::config()`, so they're reachable as
`MenuBuilder::getInstance()->items`, `->resolver`, and so on.

---

## Domain model

- **Group** (`MenuBuilderGroup` / `menubuilder_groups`) — a named navigation. Owns handle,
  `maxDepth`, `cssClass`, `htmlAttributes`, `enabled`, `sortOrder`, `siteIds`, and an open-ended
  `settings` bag. `siteIds` is persisted *inside* that `settings` JSON bag
  (`MenuBuilderGroupService::SITE_IDS_KEY`) rather than as a column, so it needed no migration; the
  service lifts it back out into `MenuBuilderGroup::$siteIds` on read.
- **Item** (`MenuBuilderItem` / `menubuilder_items`) — one navigation node. Belongs to exactly one
  group; `parentId` optionally points at another item in the **same** group. Owns title, link
  configuration, appearance, accessibility fields, fallback behaviour, `visibility` rule configs,
  and a `metadata` bag (mega-menu and dynamic-source config live there — see below).
- **Node** (`MenuBuilderNode`) — the Twig-facing, read-only projection of an Item after link
  resolution. Never persisted; hides the database entirely (no ids to join, no `parentId`, no sort
  columns).
- **Tree** (`MenuBuilderTree`) — `craft.menuBuilder.get('main')`'s return value: an
  iterable/countable wrapper around a group's top-level `MenuBuilderNode[]`, plus `flatten()`.
- **ResolvedLink** — the outcome of resolving one item's link (url, availability, element label).
- **MenuBuilderMegaMenuConfig** — validated mega-menu config for one node.

Items are the only entity with tree structure; Groups are always flat (one row per navigation).

---

## Rendering pipeline

`MenuBuilderResolver::getTree()` is the single entry point Twig talks to. In order:

1. Load the group by handle; bail (`null`) if missing or disabled.
2. Build the `VisibilityContext` once, and bail if `MenuBuilderGroup::isAvailableForSite()` rejects
   the current site — the group-level site restriction gates the whole menu *before* any item is
   loaded.
3. **Cached step.** Load the raw item tree (`MenuBuilderItemService::getTree()` — one flat query,
   assembled in PHP, never N+1), resolve each item's link, synthesise dynamic children, and convert
   to `MenuBuilderNode[]`.
4. Re-filter those nodes against **current** visibility rules — never cached, since it depends on
   the current user, date, and environment.
5. Mark `isActive`/`isActiveAncestor` against the current request URI (`MenuBuilderActiveResolver`)
   — also per-request, never cached.

Disabled items and disabled groups are excluded before step 3 ever runs.

Step 4 re-reads the persisted items (`getFlatForGroup()`) because visibility rules live on the
`MenuBuilderItem`, not on the cached `MenuBuilderNode`. The item map is built once in `getTree()`
and passed down, not re-queried per node.

---

## Link resolution

One `LinkTypeResolverInterface` implementation per `MenuBuilderItem::TYPE_*`, registered in
`MenuBuilderLinkResolver` and keyed by type:

| Type | Resolver | Behaviour |
|---|---|---|
| `entry` / `category` / `asset` | `ElementLinkResolver` | Re-queries the element fresh (site-scoped, no stored URL) every resolve; applies the item's `fallbackBehavior` if the element is missing/disabled/unpublished. Carries the element's own title on `ResolvedLink::$label`. |
| `url` | `UrlLinkResolver` | Direct passthrough of `customUrl`. |
| `anchor` | `AnchorLinkResolver` | `#` + `handle`, falling back to `customUrl`. |
| `nonclickable` / `separator` | `NonClickableLinkResolver` | No link unless `clickable` is explicitly true and a `customUrl` is set. |
| `dynamic` | — | The item itself has no link; its *children* are synthesised (see below). |

Two invariants worth preserving:

- **`clickable` is explicit, never inferred.** A "Products" heading is a label or a link because the
  editor said so, not because a URL happens to be present. `MenuBuilderResolver` computes
  `isClickable` as `isLinkable() && clickable && url !== null` — all three must hold.
- **Titles fall back, never overwrite.** `LinkAttributeHelper::resolveTitle()` prefers the item's
  own title and only uses the element label when the item title is blank.

`LinkAttributeHelper::mergeRelForTarget()` merges `noopener` into `rel` whenever
`target="_blank"`, preserving editor-set `nofollow`/`sponsored`/custom values and collapsing
duplicates. It's applied in `MenuBuilderResolver::convert()`, so it holds regardless of which link
type produced the node.

---

## Visibility

Each Item carries a `visibility` array of rule configs (`[{"type": "...", ...}]`), evaluated as an
**AND** — visible only if every configured rule passes. `MenuBuilderVisibilityService` builds a
`VisibilityContext` once per render (plain scalars only — booleans, ints, a `DateTime`, the app
timezone, `CRAFT_ENVIRONMENT` — never live `User`/`Site` objects, which is what keeps rule
evaluation unit-testable without a booted Craft app) and dispatches each config to its
`VisibilityRuleInterface` by `type`.

Built-ins: `always`, `loggedIn`, `loggedOut`, `userGroup`, `site`, `dateRange`, `environment`.

**Fail closed is the rule, not a detail.** An unrecognised or misconfigured rule type hides the
item. `DateRangeRule` treats an unparseable bound, an impossible calendar date (`2026-02-30`, which
PHP's `DateTime` would silently normalise to March 2), or a start after its end as "hide", and
parses naive `datetime-local` strings against the application timezone carried on the context — not
PHP's ambient default — so a CP-entered value means the same instant on any server.

`MenuBuilderItem::validateVisibility()` mirrors those shapes server-side as defence-in-depth for
imports/direct API writes. Deliberate asymmetries in it:

- An **empty or absent** ID/string list is a valid no-op, because `UserGroupRule`/`SiteRule`/
  `EnvironmentRule` all treat "nothing configured" as an unconditional pass. Rejecting it here would
  make the CP disagree with evaluation.
- A rule `type` the model doesn't recognise (e.g. one registered via
  `EVENT_REGISTER_VISIBILITY_RULES`) is **accepted** here — the model can't know a third party's
  expected shape — and still fails closed at evaluation time.
- `isValidPositiveId()` rejects bools and floats explicitly; a naive `ctype_digit((string) $v)`
  would accept `true` (casts to `"1"`) and silently mean ID 1.

Site restriction exists at **two levels** on purpose. The per-item `site` rule filters individual
items and passes when there is no current site (console requests) because it's a *filter*.
`MenuBuilderGroup::$siteIds` is an *availability boundary* for a whole menu, checked before any item
loads, and a restricted menu is therefore unavailable when there is no current site at all.

---

## Tree / hierarchy

All hierarchy mutations run through `MenuBuilderItemService`:

- **`getTree()`** — one flat query per group, nested in PHP.
- **`move()`** — reparents and resets sort order in a transaction; always re-validates server-side
  (circularity, cross-group parenting, max depth) regardless of what the CP's drag-and-drop already
  checked client-side.
- **`reorderSiblings()`** — persists an explicit sibling order without touching `parentId`.
- **`duplicate()`** — recursively clones an item and its full subtree in one transaction.
- **`bulkSetEnabled()` / `bulkDelete()`** — wrap N per-item `save()`/`deleteById()` calls in one
  transaction. Bulk never bypasses per-item validation or permission checks; a mid-batch failure
  rolls the whole thing back.
- **`deleteById()`** — relies on the `parentId` self-referencing `CASCADE` FK to remove a subtree;
  no ORM-level recursion.

`validateHierarchy()` rejects: an item parenting itself, a non-existent parent, a parent in a
different group, any circular ancestor chain, and any move that would push a subtree past the
group's `maxDepth`. It is the **sole authority** on all five — the UI's checks are convenience, not
enforcement.

Related invariant: an existing item's `groupId` can never change.
`MenuBuilderItemService::save()` rejects it (`isGroupChangeAllowed()` is the pure, testable core)
and `ItemsController::actionSave()` never lets a posted value override an existing item's group in
the first place. Reassigning a group used to silently break the tree — a moved item's children keep
their own `groupId`, so they became orphaned roots in the old group and vanished from the new one,
with no error anywhere. New items still pick their group from the posted value.

---

## Mega menus

No second tree, no separate item type. An item becomes a mega-menu **parent** via
`metadata['megaMenu'] = {enabled: true, columns: 1-6}`; any child picks a column via its own
`metadata['megaMenuColumn']` (1–6, meaningless without an enabled mega-menu parent). Both are
validated fail-closed by `MenuBuilderItem::validateMegaMenu()`, and both are independent of `type`
— mega menu is presentation layered on the existing hierarchy.

`MenuBuilderResolver::buildMegaMenuConfig()` builds a `MenuBuilderMegaMenuConfig` per node;
`MenuBuilderNode::megaMenuColumns()` is pure grouping logic (no DB access) bucketing already-resolved
children by column, collapsing anything unset or out of range into column 1.

`_macros/tree.twig`'s `renderMegaMenu(node)` is an optional example renderer (disclosure button with
`aria-haspopup`/`aria-expanded`, one `<ul>` per column); `render()` calls it automatically for any
node whose `megaMenu` is set. Hand-rolled Twig can ignore the macros and read
`node.megaMenu`/`megaMenuColumns()` directly.

---

## Dynamic navigation

`MenuBuilderItem::TYPE_DYNAMIC` items have children **synthesised** at resolve time rather than
stored as rows. Config: `metadata['dynamicSource'] = {sourceType, sourceId, limit, orderBy}`,
validated fail-closed by `validateDynamicSource()` (unknown `sourceType`, non-positive `sourceId`,
or an `orderBy` outside `MenuBuilderItem::DYNAMIC_SOURCE_ORDER_BY` all reject the save).

`MenuBuilderDynamicNavigationService::resolveElements()` turns that into exactly **one** bounded
query per dynamic item — never one per child — site-scoped, status-scoped to normally-visible
elements (live entries, enabled categories, all assets: the same boundary `ElementLinkResolver`
uses), with `limit` hard-clamped to `DYNAMIC_SOURCE_MAX_LIMIT` (50) server-side regardless of what's
stored, and `orderBy` restricted to a fixed whitelist — never editor-supplied SQL. A dynamic item
can never surface content a direct link to the same element wouldn't.

`MenuBuilderResolver::buildDynamicChildren()` converts each result into a synthetic node
(`isDynamic: true`) appended to the dynamic item's `children`. These carry no visibility config of
their own, so `filterVisible()` **explicitly skips the `itemsById` lookup** for them: a synthetic
node's `id` is a Craft *element* ID, and item IDs and element IDs are both auto-increment PKs from
different tables, so a numeric collision would otherwise apply an unrelated item's visibility rules
to the wrong node. This is avoided by construction, not by luck.

Synthesis happens inside the cached step, so dynamic results cache and invalidate like static
content.

---

## Caching

`MenuBuilderCacheService` caches **only** the link-resolved node tree (pipeline step 3), keyed per
**group handle + current site ID** (`MenuBuilderCacheService::cacheKey()`, a pure public static
method so key construction is unit-testable), tagged for bulk invalidation, with no expiry — cached
until explicitly invalidated. Visibility filtering and active-state marking are deliberately never
cached.

The site is part of the key because `menubuilder_groups.handle` is unique per-install, not per-site
— a Group isn't itself site-scoped — but the *resolved* tree is: `ElementLinkResolver` resolves
elements against the current site, so the same handle can legitimately resolve to different URLs,
titles, and availability per site. Without the site in the key, two sites would read and clobber
each other's tree.

Invalidation is targeted:

| Change | Invalidates |
|---|---|
| Group or item save/move/reorder/duplicate/delete | That group only (`invalidateGroup()`), across every site |
| Entry/category/asset save, delete, restore | Only groups whose items reference that `elementId` (one indexed query), plus groups containing an enabled `dynamic` item |
| Draft / revision / provisional-draft save | Nothing — a menu item's `elementId` always points at a canonical element, so reacting to draft saves would invalidate live navigation for unpublished edits |

`MenuBuilderElementService` (attached once from `MenuBuilder::init()`) owns the element-lifecycle
half of this: it listens for `Elements::EVENT_AFTER_SAVE_ELEMENT` / `EVENT_AFTER_DELETE_ELEMENT` /
`EVENT_AFTER_RESTORE_ELEMENT` and calls `MenuBuilderCacheService::invalidateGroups()`. This covers
title, slug/URI, and status changes without re-saving the menu item.

`invalidateGroup()` clears the group's entry for **every** site (`getAllSiteIds()`), because none of
its callers know which site's resolved tree a given change actually affects. `invalidateAll()`
flushes by tag and remains for genuinely global changes.

The one thing an `elementId`-keyed lookup can't cover is a **brand-new** element that should now
appear in a dynamic list, which is why `getGroupIdsWithDynamicItems()` (one indexed query on `type`)
is also consulted on every watched element event. That's broader than a single-element
invalidation but still targeted, and a complete no-op — zero added queries, zero behaviour change —
when the install has no dynamic items.

Cost profile: a cache hit costs zero element queries for any item type, dynamic included; dynamic
queries only run during a rebuild.

---

## Persistence

Two tables (`src/migrations/Install.php`):

- **`menubuilder_groups`** — unique index on `handle`.
- **`menubuilder_items`** — composite index on `(groupId, parentId, sortOrder)` for tree reads, plus
  `(groupId, handle)` and `elementId` (the latter is what makes targeted element invalidation one
  indexed query). `CASCADE` FK on `groupId` (deleting a group deletes its items) and a
  self-referencing `CASCADE` FK on `parentId` (deleting an item deletes its subtree).

Open-ended data (`htmlAttributes`, `settings`, `visibility`, `metadata`) is stored as JSON text
columns rather than normalised tables. That's what lets new rule types, mega-menu config, and
dynamic-source config ship without a migration — and why every one of those bags has explicit
server-side shape validation on its model instead of a schema to lean on.

There is no settings model and no `config/` directory: `hasCpSettings` is `false` and every
editor-managed value lives in these two tables (or, for groups, is mirrored to project config).

---

## Permissions & security

Five permissions, registered in `MenuBuilder::attachEventHandlers()`:

| Permission | Applies to |
|---|---|
| `menuBuilder:view` | Reading the CP section and trees; rendering the group and item **edit forms** (`GroupsController::actionEdit`, `ItemsController::actionEdit`) |
| `menuBuilder:create` | `ItemsController::actionSave` when creating a **new** item; `actionDuplicate` |
| `menuBuilder:edit` | `actionSave` on an existing item; `actionToggle`, `actionReorder`, bulk enable/disable |
| `menuBuilder:delete` | Item/group deletion, bulk delete |
| `menuBuilder:manageSettings` | Every group **mutation**: save, duplicate, toggle (`GroupsController`'s `default` arm) |

Groups are structural/settings-level entities (name, handle, `maxDepth`, `cssClass`,
`htmlAttributes`), not content — hence `manageSettings` rather than `create`/`edit`, which are
reserved for items and mean what their labels say.

Each controller exposes its mapping as a pure static `requiredPermissionForAction()` specifically so
it's unit-testable without a booted app (`PermissionEnforcementTest`, `PhaseSixToTenPermissionTest`).
`ItemsController`'s takes `$isNewSave` and `$bulkOp` for the create-vs-edit and per-op distinctions.

Other guarantees:

- Every `beforeAction()` requires a CP request and checks `admin` or the specific permission before
  any client-supplied ID is used.
- `ItemsController::actionEdit`/`actionSave` re-derive the owning group from the posted
  `groupId`/`groupHandle` rather than trusting a client-asserted group; cross-group reparenting is
  rejected in `validateHierarchy()` regardless of what the UI sent.
- All state-changing actions require POST and Craft's CSRF token (`csrfInput()` in every form;
  `Craft.sendActionRequest` attaches it for JS-driven actions).
- `LinkAttributeHelper::validateHtmlAttributes()` (shared by both models — one implementation, not
  two) rejects malformed and event-handler-shaped keys and `javascript:`-scheme values, as
  defence-in-depth beyond Twig's escaping, because a custom template may render those bags as
  markup.
- `MenuBuilderItem::isPermissiveUrl()` accepts absolute URLs, root-relative paths, fragments,
  `mailto:`, and `tel:` without forcing a scheme onto internal paths;
  `isValidAnchorTarget()` rejects whitespace and quote/angle characters.

---

## Project config

`MenuBuilderGroupService` stays **DB-authoritative** for every locally initiated save/delete. After
a successful write it *mirrors* the record state into `Craft::$app->getProjectConfig()` under
`menuBuilder.groups.{uid}` (`MenuBuilderGroupService::CONFIG_PATH`), so menus are captured in
`project.yaml` and are diffable/portable like Craft's own structural resources.

The other direction — a change arriving *from* project.yaml on deploy — is handled by
`handleChangedConfig()`/`handleDeletedConfig()`, registered via `onAdd`/`onUpdate`/`onRemove` in
`MenuBuilder::attachProjectConfigHandlers()`. The service guards against re-applying a change this
same request just mirrored out.

Items are intentionally **not** in project config, for the same reason Craft entries aren't: they're
per-environment content, not structure.

---

## Control panel front end

No JS framework, no build step. Four plain-JS bundles registered by `CpAsset`:

| File | Responsibility |
|---|---|
| `tree.js` | `MenuBuilderTree` — the drag-and-drop tree on `Garnish.DragSort`, row actions (`edit`, `duplicate`, `toggle`, `delete`), reorder persistence, row state updates |
| `slideout.js` | A self-contained slide-out panel built on Craft's own `.slideout` CSS but with its own JS, talking to MenuBuilder's `items/edit`/`items/save` actions over a JSON shape this plugin controls end to end (Craft's `CpScreenSlideout` expects a private CP-screen response contract) |
| `item-fields.js` | Type-dependent field show/hide inside the editor form |
| `menu-builder.js` | Bootstrapping |

Two details that have bitten this code before, worth not regressing:

- Rows must be looked up by `data-id`, **never** from the clicked anchor — Craft relocates an open
  disclosure `.menu` to near `<body>`, so the anchor is no longer inside its row.
- The Disabled badge needs its own `menu-builder-item-disabled-flag` class, because
  `.menu-builder-item-status` is shared with the mega-menu and orphaned-element badges.

The tree controller's document-wide click listener handles only the actions it owns (`ROW_ACTIONS`)
and returns *before* `preventDefault()` for anything else, so the dashboard template stays the sole
owner of `focus-quick-add` and the groups page keeps its own `*-group` actions.

The quick-add panel's "Nest under" options come from `DashboardController::parentOptions()`, which
flattens the group's tree into indented options, skips separators (they can't hold children) and any
item whose children would exceed `MenuBuilderGroup::allowsDepth()`. They're built from the
**unfiltered** tree — so an active search never narrows the choices — and *before* `filterTree()`
runs, since that method mutates `->children` in place. "Top level" submits `''`, which
`actionSave()` normalises to `null`.

---

## Public Twig API

```twig
{% set menu = craft.menuBuilder.get('main') %}
{% for node in menu %}...{% endfor %}
```

`MenuBuilderVariable::get()` → `MenuBuilderResolver::getTree()` → `MenuBuilderTree`, iterable and
countable over its top-level nodes, with `.group`, `.items`, and `.flatten()` (depth-first walk, so
templates never recurse to find the active node). `getGroup()`/`getItem()` are thin read-only
service passthroughs with no logic of their own.

`MenuBuilderNode` is the only object Twig should treat as public and stable — no database ids to
join, no internal columns, and dynamic children merged transparently into `children` so there's one
node contract rather than a separate "dynamic tree" or "mega-menu tree" API.
`MenuBuilderItem`/`MenuBuilderGroup` and the Records are CP-internal, even though the CP templates
reach into them for the edit forms.

The site (front-end) template root is registered explicitly in `attachEventHandlers()` —
`craft\base\Plugin` auto-registers only the CP root, and `menu-builder/_macros/tree` is meant to be
importable from front-end templates.

---

## Extension points

- `MenuBuilderLinkResolver::EVENT_REGISTER_LINK_TYPES` → add to `RegisterLinkTypesEvent::$resolvers`.
- `MenuBuilderVisibilityService::EVENT_REGISTER_VISIBILITY_RULES` → add to
  `RegisterVisibilityRulesEvent::$rules`.

These are the only two. Adding a link type or visibility rule requires no core change; anything else
does. See "Known limitations" for what's deliberately absent.

---

## Single path per behaviour

A deliberate constraint: each behaviour has exactly one affordance, so there's no second code path
to keep in sync and no second place for a hierarchy bug to hide.

- **Move / reparent an item** — drag-and-drop only (`MenuBuilderTreeSorter`). Row-menu
  `move-up`/`move-down`/`indent`/`outdent` were removed; they reimplemented the same DOM-shuffle +
  `persistReorder()` sequence.
- **Reparent while dragging** — horizontal pointer movement only (`updateIndent()` →
  `getLevelBounds()`). The removed drop-onto-a-row gesture computed max-depth admissibility a
  second, independent way, so the two could disagree about the same drop.
- **Create an item under a parent** — the quick-add panel's "Nest under" select, then drag to
  adjust. `add-child`/`add-sibling` only pre-filled `parentId` on the same slideout.
  `ItemsController::actionEdit()` is now edit-only: its new-item branch and the
  `menu-builder/<groupHandle>/items/new` route are gone, and `openItemSlideout()` takes `itemId`
  only. The full-page `menu-builder/<groupHandle>/items/<itemId>` route stays — it renders the same
  `items/_fields` partial the slideout loads (one form, two wrappers) and is the no-JS/deep-link
  fallback, not a second editor.
- **Reflecting an item's enabled state** — `MenuBuilderTree.setRowEnabled(id, enabled)`, called by
  both the row menu and the bulk toolbar (via `window.MenuBuilder.tree`).

One deliberate **exception**: per-row toggle/delete (`items/toggle`, `items/delete`) and the bulk
toolbar (`items/bulk`) both exist on purpose. They differ in cardinality, not behaviour — per-row is
"flip *this* item", bulk is "*set* state on N items" — they already converge on the same
`MenuBuilderItemService` `save()`/`deleteById()` calls, and the two toolbars are never usable at the
same moment. Don't collapse them.

---

## Testing

`composer test` runs 113 PHPUnit unit tests (`tests/Unit`) with no booted Craft app. Covered:

| Area | Test |
|---|---|
| Link resolvers (non-element) | `LinkTypeResolverTest` |
| Visibility rules and context | `VisibilityRulesTest`, `MenuBuilderVisibilityServiceTest` |
| Item validation, URLs, anchors, attributes | `MenuBuilderItemModelTest` |
| Mega-menu / dynamic-source validation | `MenuBuilderItemMegaMenuDynamicTest` |
| Mega-menu column grouping | `MenuBuilderNodeMegaMenuTest` |
| Group model, site availability, depth | `MenuBuilderGroupModelTest` |
| Title fallback, rel merging, attribute validation | `LinkAttributeHelperTest` |
| Cache-key construction | `MenuBuilderCacheServiceTest` |
| Controller permission mappings | `PermissionEnforcementTest`, `PhaseSixToTenPermissionTest` |

The pattern this suite relies on: anything worth asserting is factored into a **pure static or
context-taking method** (`cacheKey()`, `requiredPermissionForAction()`, `isGroupChangeAllowed()`,
`resolveTitle()`, `mergeRelForTarget()`, `validateHtmlAttributes()`, `megaMenuColumns()`,
`isPermissiveUrl()`, `isValidAnchorTarget()`). Keep new logic in that shape.

Writing these tests caught bugs inspection hadn't: the three `metadata`/`visibility` inline
validators were silently skipped on an empty array, because Yii's inline-validator `skipOnEmpty`
defaults to `true` — every rule on `MenuBuilderItem` now sets it to `false` explicitly.

---

## Known limitations

1. **No before/after save/delete events on Group or Item.** Only the two registration events exist.
   Third-party code can't react to menu changes (e.g. to bust an external cache) or veto a save. Any
   such event needs a deliberate design (naming, cancelable or not, payload).
2. **Craft-dependent code isn't unit-covered.** `ElementLinkResolver`, `MenuBuilderElementService`
   (element listeners), `MenuBuilderItemService::getOrphanedItemIds()`,
   `MenuBuilderDynamicNavigationService::resolveElements()`, and the project-config handlers all
   query real element/DB state and are verified manually. A Craft testing harness (Codeception
   integration tests) is the natural next step.
3. **Static analysis and code style are unconfigured.** `composer check-cs` (ECS) and
   `composer phpstan` are declared in `composer.json`, but no `ecs.php` or `phpstan.neon` is checked
   in, so neither has enforced rules.
4. **Orphaned items are surfaced, not repaired.** The dashboard badges an item whose linked element
   was hard-deleted; nothing reassigns or cleans it up.
5. **`MenuBuilderGroup::$settings` is still open-ended.** `siteIds` is the only documented key in
   it. Whoever adds the next per-menu frontend setting should decide whether it deserves a validated
   sub-model rather than another loose key.
