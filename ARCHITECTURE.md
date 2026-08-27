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
| Controllers | `BaseMenuBuilderController` + `GroupsController`, `ItemsController`, `DashboardController` | The **only** classes that touch `craft\web\Request` or the session. The permission check itself lives once, in the base class's `beforeAction()`; subclasses only declare *which* permission an action needs. |
| Services | `MenuBuilderGroupService`, `MenuBuilderItemService`, `MenuBuilderResolver`, `MenuBuilderLinkResolver`, `MenuBuilderVisibilityService`, `MenuBuilderCacheService`, `MenuBuilderActiveResolver`, `MenuBuilderElementService`, `MenuBuilderDynamicNavigationService` | The only classes that query Records. Own all business logic, hierarchy integrity, and transactions. |
| Models | `MenuBuilderGroup`, `MenuBuilderItem`, `MenuBuilderNode`, `MenuBuilderTree`, `ResolvedLink`, `MenuBuilderMegaMenuConfig` | Validation lives here (`defineRules()`), never in Records or controllers. |
| Records | `MenuBuilderGroupRecord`, `MenuBuilderItemRecord` | Thin `ActiveRecord`: `tableName()` and nothing else. No business logic, and deliberately **no AR relations** — every join this plugin needs is an explicit service query, so there is no lazy-load path that could silently become an N+1. Never visible to controllers or Twig. |
| Helpers | `LinkAttributeHelper`, `ConfigHelper`, `DateValidationHelper` | Pure static functions, no Craft app required. Where logic that two layers both need lives, so there is one implementation rather than a copy per caller. |

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

**Disabling an item hides its whole subtree.** `getTree()` nests rows by presence, so with the
disabled rows already filtered out of the query, a disabled parent's enabled children would have
nothing to nest under and would be promoted to *top-level* menu items — appearing in a position
nobody placed them in, on a branch the editor believed they had switched off. Instead, rows whose
parent chain no longer reaches a root are dropped with the parent
(`MenuBuilderHierarchyHelper::idsReachableFromRoots()`, pure and cycle-guarded). This is the same
direction step 4 takes: `filterVisible()` skips a hidden node's children too. One rule, both
mechanisms — a branch is either reachable through its parent or it isn't rendered. The CP tree
(`includeDisabled: true`) still shows every row, disabled ones flagged, so nothing becomes
unreachable to edit.

Step 4 re-reads the persisted items (`getFlatForGroup()`) because visibility rules live on the
`MenuBuilderItem`, not on the cached `MenuBuilderNode`. The item map is built once in `getTree()`
and passed down, not re-queried per node.

---

## Link resolution

One `LinkTypeResolverInterface` implementation per `MenuBuilderItem::TYPE_*`, registered in
`MenuBuilderLinkResolver` and keyed by type:

| Type | Resolver | Behaviour |
|---|---|---|
| `entry` / `category` / `asset` | `ElementLinkResolver` | Re-queries the element fresh (site-scoped, no stored URL) every resolve; applies the item's `fallbackBehavior` if the element is missing/disabled/unpublished. Availability is read from the element's **status**, not its `enabled` flag, so an element disabled *for the site being rendered* falls back too. Carries the element's own title on `ResolvedLink::$label`. |
| `url` | `UrlLinkResolver` | Passthrough of `customUrl`, re-checked against `isPermissiveUrl()` first — a stored value that never went through validation (import, direct DB edit) resolves unavailable rather than reaching an `href`. |
| `anchor` | `AnchorLinkResolver` | `#` + the editor's anchor field (`customUrl`), falling back to `handle`. That precedence lives in `AnchorLinkResolver::anchorTarget()`, which `MenuBuilderItem::validateAnchorTarget()` also uses, so validation and resolution can't disagree about which field is the anchor. A malformed stored fragment resolves unavailable. |
| `nonclickable` / `separator` | `NonClickableLinkResolver` | Never a link. `isLinkable()` is authoritative; a leftover `clickable`/`customUrl` on the row can't produce one, and the editor exposes no link field for these types. |
| `dynamic` | `DynamicLinkResolver` | The item itself has no link (available, no URL); its *children* are synthesised (see below). It must still be **registered** — an unregistered type resolves `unavailable()`, and `convert()` drops unavailable items whose `fallbackBehavior` is `hide`, which is the only value the type-scoped editor fields leave a dynamic item with. `LinkTypeResolverTest` guards that. |

Two invariants worth preserving:

- **`clickable` is explicit, never inferred.** A "Products" heading is a label or a link because the
  editor said so, not because a URL happens to be present. `MenuBuilderResolver` computes
  `isClickable` as `isLinkable() && clickable && url !== null` — all three must hold.
- **Titles fall back, never overwrite.** `LinkAttributeHelper::resolveTitle()` prefers the item's
  own title and only uses the element label when the item title is blank.

`LinkAttributeHelper::mergeRelForTarget()` merges `noopener` into `rel` whenever
`target="_blank"`, preserving editor-set `nofollow`/`sponsored`/custom values and collapsing
duplicates. It's applied in `MenuBuilderResolver::convert()`, so it holds regardless of which link
type produced the node. `ItemsController::buildRel()` merges the *stored* value through the same
`LinkAttributeHelper::combineRel()`, so the two checkboxes and the free-text `rel` field can't
persist a duplicate token (`nofollow nofollow`) that the render-time merge would only paper over.

`isClickable` is `LinkAttributeHelper::isClickable()` — one definition, shared by persisted and
synthesised nodes, and it treats a blank URL as no URL (an `<a href="">` is a link back to the
current page, which is worse than the label the item was going to be).

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

Everything that is not an unambiguous pass hides the item:

- A `visibility` entry that isn't an array of config, or whose `type` is missing / not a string /
  not registered.
- A rule that **throws**. Third-party rules arrive via `EVENT_REGISTER_VISIBILITY_RULES` and are
  outside this plugin's control; the service catches, logs a warning, and hides — an escaping
  exception would take the whole page render with it.
- `DateRangeRule`: an unparseable bound, an impossible calendar date (`2026-02-30`, which PHP's
  `DateTime` would silently normalise to March 2), a start after its end, or **neither bound
  configured**. Naive `datetime-local` strings parse against the application timezone carried on
  the context — not PHP's ambient default — so a CP-entered value means the same instant on any
  server; an explicit offset in the value wins over the context timezone.
- `UserGroupRule` / `SiteRule` / `EnvironmentRule`: an **empty, absent, or malformed** restriction
  list, and — for `site`/`environment` — a request whose current site or environment can't be
  identified. These rules exist only to restrict, so "nothing to match against" is missing
  configuration, not permission. *"No restriction" is the absence of the rule*, which is what the CP
  form emits: `ItemsController::buildVisibilityRules()` only ever writes a rule the editor actually
  filled in. "Any logged-in user" is the `loggedIn` rule, not a `userGroup` rule with no groups.
- `MenuBuilderResolver::filterVisible()`: a cached node whose persisted item is gone from the fresh
  read (deleted, or disabled since the tree was cached). Its rules can no longer be evaluated, so it
  is dropped rather than passed through. Invalidation should prevent this; it's the backstop for a
  stale entry. Synthesized `dynamic` children are exempt — their `id` is a Craft element ID, not an
  item ID, and looking one up in the item map could apply an unrelated item's rules to it.

`ConfigHelper::strictIdList()` / `strictStringList()` are what "malformed" means in one place,
shared by the rules and by `MenuBuilderItem::validateVisibility()` so the two can't drift. They
return `null` rather than filtering junk out, and reject bools and floats explicitly — a naive
`ctype_digit((string) $v)` would accept `true` (casts to `"1"`) and silently mean group/site ID 1.
They are deliberately *not* `normalizeIdList()`, which is form-post oriented and `intval`s any
scalar: fine for a posted ID list, wrong for anything that gates access.

`MenuBuilderItem::validateVisibility()` mirrors those shapes server-side as defence-in-depth for
imports/direct API writes, and now **rejects** an empty restriction list rather than accepting it as
a no-op — otherwise a save would persist an item that silently never renders anywhere. One
deliberate asymmetry remains: a rule `type` the model doesn't recognise (e.g. one registered via
`EVENT_REGISTER_VISIBILITY_RULES`) is **accepted** here — the model can't know a third party's
expected shape — and still fails closed at evaluation time.

Site restriction exists at **two levels** on purpose. The per-item `site` rule filters individual
items and requires a known current site. `MenuBuilderGroup::$siteIds` is an *availability boundary*
for a whole menu, checked before any item loads, and a restricted menu is therefore unavailable when
there is no current site at all.

**Where visibility runs in the pipeline matters as much as what it decides.** `getTree()` reads the
link-resolved tree from the shared per-group/per-site cache *first*, then filters it against the
current `VisibilityContext`, then marks active state. The method that builds the cached payload
(`buildResolvedNodes()`) makes no visibility decision at all, `MenuBuilderNode` carries no
visibility data, and filtering copies through `withChildren()` instead of writing back onto the
cached nodes — so one cache entry serves an anonymous visitor and a logged-in one without either
seeing the other's answer. `MenuBuilderVisibilityTest` pins that ordering, including against the
source, because getting it wrong is a security bug rather than a behavioural nuance.

---

## Tree / hierarchy

There is exactly **one** hierarchy system: adjacency rows in `menubuilder_items` (`parentId` +
`sortOrder`), assembled into a tree by `getTree()`. No nested sets, no materialized paths, no
second ordering column.

All hierarchy mutations run through `MenuBuilderItemService`:

- **`getTree()`** — one flat query per group, nested in PHP. Siblings order by `sortOrder`, then
  `id` — rows left sharing a `sortOrder` still render in the same order on every request.
- **`move()`** — the single write path behind every drag, drop and reorder. One transaction that
  starts by locking the group row, re-reads the item inside that lock, re-validates server-side
  (circularity, cross-group parenting, max depth) regardless of what the CP already checked, then
  writes one `parentId` and the `sortOrder`s that actually changed. Descendants are never
  rewritten — they point at their parent by id, so the subtree travels with the one row.
- **`reorderSiblings()`** — persists an explicit sibling order without touching `parentId`, through
  the same reconciliation and renumbering `move()` uses.
- **`duplicate()`** — recursively clones an item and its full subtree in one transaction.
- **`bulkSetEnabled()` / `bulkDelete()`** — wrap N per-item `save()`/`deleteById()` calls in one
  transaction. Bulk never bypasses per-item validation or permission checks; a mid-batch failure
  rolls the whole thing back.
- **`deleteById()`** — relies on the `parentId` self-referencing `CASCADE` FK to remove a subtree;
  no ORM-level recursion.

`validateHierarchy()` rejects: an item parenting itself, a non-existent parent, a parent in a
different group, any circular ancestor chain, and any move that would push a subtree past the
group's `maxDepth`. It is the **sole authority** on all five — the UI's checks are convenience, not
enforcement. Depth is measured against the **deepest row of the moving subtree**, not the moved
item's own level, and the check applies to a move to the root as well: a three-level subtree lifted
to the top of a two-level menu still busts the limit.

The tree maths behind those rules lives in `helpers/MenuBuilderHierarchyHelper` as pure functions
over a snapshot of one group's `(id, parentId, sortOrder)` rows — ancestor walks, subtree height,
cycle detection, sibling-order reconciliation, and `planMove()`, which returns the complete set of
writes a move needs. The service only validates, locks and executes that plan, so every ordering
and depth decision is exercised for real in `MenuBuilderTreeMoveTest` without a booted Craft app.
The helper stores nothing; it is not a second hierarchy.

**Concurrency.** Validation that ran before a transaction only proves a move was legal against a
state that may already be gone — two concurrent drags in one menu are enough to build a cycle out
of two individually valid moves. Every path that can change where a row sits (`move()`,
`reorderSiblings()`, a reparenting `save()`, a keep-children `deleteById()`) therefore takes a
`SELECT … FOR UPDATE` row lock on the **group** first (`lockGroup()`) and re-reads inside it, so
those mutations take turns. A field-only `save()` changes no structure and takes no lock.

**Stale orders.** The `siblingIds` a drag posts are a snapshot of one editor's screen. They are
treated as a preference, never as truth: reconciled against the set's real membership, so a stale
payload can't resurrect a deleted row, drop a row it never saw, or pull in a row from another
parent. The result is always a permutation of what is actually there, which is what keeps
renumbering gap-free. `sortOrder` is written as `CASE id WHEN … THEN …` in chunks, and only for
rows whose position actually changed — a drag in a 500-item set costs a couple of queries, not 500.

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

`MenuBuilderDynamicNavigationService::normalizeConfig()` (pure, unit-tested) reduces the stored
config to the only values a query may use — or `null`, meaning "no children" — and
`resolveElements()` turns that into exactly **one** bounded
query per dynamic item — never one per child — site-scoped, status-scoped to normally-visible
elements (live entries, enabled categories, enabled assets: the same boundary `ElementLinkResolver`
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
method so key construction is unit-testable), tagged for bulk invalidation. Visibility filtering and
active-state marking are deliberately never cached.

Entries are normally refreshed by explicit invalidation, but they are written with Craft's own
`cacheDuration` as a **ceiling** (`resolveDuration()`; `0` still means "no expiry", as it does in
Craft). That bounds the one kind of staleness no event can announce: a pending entry going live at
its `postDate`, or a live entry expiring at its `expiryDate` — both are clock-driven, fire nothing,
and would otherwise be invisible to navigation indefinitely. It's the same bound Craft puts on its
element query caches.

The site is part of the key because `menubuilder_groups.handle` is unique per-install, not per-site
— a Group isn't itself site-scoped — but the *resolved* tree is: `ElementLinkResolver` resolves
elements against the current site, so the same handle can legitimately resolve to different URLs,
titles, and availability per site. Without the site in the key, two sites would read and clobber
each other's tree.

Invalidation is targeted:

| Change | Invalidates |
|---|---|
| Item save/move/reorder/duplicate/delete | That item's group only (`invalidateGroup()`), across every site |
| Group save/duplicate/delete | **Everything** (`invalidateAll()`). Deliberately coarse: a group save can change the very handle its cache key is built from, so a targeted invalidation would orphan the old key's entry. Group writes are rare and editor-initiated; item writes, which are not, stay targeted |
| Entry/category/asset save, delete, restore, slug/URI update | Only groups whose items reference that `elementId` (one indexed query), plus groups whose enabled `dynamic` items are sourced from that element's **own** section/category group/volume |
| Section / category group / volume save | Only groups referencing an element **inside that container** (one sub-query on the element's own table), plus dynamic items sourced from it |
| Draft / revision / provisional-draft save | Nothing — a menu item's `elementId` always points at a canonical element, so reacting to draft saves would invalidate live navigation for unpublished edits |
| Any element type a menu can't link to (users, global sets, …) | Nothing |

`MenuBuilderElementService` (attached once from `MenuBuilder::init()`) owns the element half of
this and calls `MenuBuilderCacheService::invalidateGroups()`. It listens for:

| Event | Why it's needed |
|---|---|
| `Elements::EVENT_AFTER_SAVE_ELEMENT` | Title, slug, URI, publish/unpublish, enable/disable — including per-site status. Craft's own resaves (a section's URI format changing with `autoResaveEntries` on, `resave/entries`, a per-site delete's resave of the remaining sites) all run through `_saveElementInternal()` and fire it too |
| `Elements::EVENT_AFTER_DELETE_ELEMENT` | Soft **and** hard delete — Craft fires this for both |
| `Elements::EVENT_AFTER_RESTORE_ELEMENT` | Restore from the trash |
| `Elements::EVENT_AFTER_UPDATE_SLUG_AND_URI` | A **structure move** rewrites the moved entry's and its descendants' URIs through `Elements::updateElementSlugAndUri()` (usually via a queue job) and fires *no* save event. Without this listener a nested-URI menu link went stale after a drag in the entries index |
| `Entries::EVENT_AFTER_SAVE_SECTION`, `Categories::EVENT_AFTER_SAVE_GROUP`, `Volumes::EVENT_AFTER_SAVE_VOLUME` | Container-level URL changes. A URI-format change only resaves entries when `autoResaveEntries` is on, and a volume's base URL/filesystem change never resaves its assets — so no element event fires for either |

Dynamic items are matched by *source*, not merely by existence: `getDynamicSourceConfigsByGroup()`
reads each enabled `dynamic` item's stored config and
`MenuBuilderElementService::dynamicSourceMatches()` (pure, unit-tested) normalizes it through
`MenuBuilderDynamicNavigationService::normalizeConfig()` — the same clamping the render-time query
uses — before comparing source type and container ID. So saving an asset no longer flushes a menu
whose only dynamic item lists entries, and a config the dynamic service would refuse to run can
never justify an invalidation. An element with no determinable container (a nested entry has no
`sectionId`) fails *open* to the coarser `getGroupIdsWithDynamicItems()` list rather than risking a
stale menu.

Nothing about a linked element is ever *stored* on a menu item — no URL, no title. `title` is the
editor's own override and stays blank when the element's title should be inherited (see
[Link resolution](#link-resolution)), so "stale" can only ever mean "a cached tree that should have
been rebuilt", never a persisted value that has to be migrated.

`invalidateGroup()` clears the group's entry for **every** site (`getAllSiteIds()`), because none of
its callers know which site's resolved tree a given change actually affects. `invalidateAll()`
flushes by tag.

Resolving a group handle to invalidate goes through `MenuBuilderGroupService::getHandleById()`,
which reads the request-level `getAll()` cache rather than issuing a fresh query. That matters
because the invalidation callers run in loops: a bulk enable/disable calls it once per item, and
every entry/category/asset save calls it once per affected group — one query each before, none now.
It returns the handle rather than the model so a caller can't mutate the shared cached instance.

**The cached tree is immutable.** Steps 4 and 5 of the pipeline write per-request state
(`children` pruning, `isActive`/`isActiveAncestor`), and the nodes they receive came straight out of
the cache. `MenuBuilderResolver::filterVisible()` therefore rebuilds each level via
`MenuBuilderNode::withChildren()` — a copy with the surviving children and cleared active flags,
child `parent` pointers rewired to the copy — instead of assigning to `$node->children`. With
Craft's serializing cache backends the in-place version happened to be safe (every read returns a
fresh object graph); this makes it safe by construction instead of by backend, so one user's
visibility decision can never be what another user reads back.

The one thing an `elementId`-keyed lookup can't cover is a **brand-new** element that should now
appear in a dynamic list, which is why dynamic sources are consulted on every watched element event.
That's a complete no-op — zero behaviour change — when the install has no dynamic items.

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
editor-managed value lives in these two tables.

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
it's unit-testable without a booted app (`ControllerPermissionTest`).
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
  two) rejects malformed and event-handler-shaped keys (anything starting `on`) and
  `javascript:`/`vbscript:`-scheme values — matched after stripping whitespace and control
  characters, since browsers ignore those inside a scheme — as defence-in-depth beyond Twig's
  escaping, because a custom template may render those bags as markup. `data:` is deliberately
  *not* denied there: it has ordinary uses on a `data-*` attribute, and a resolved *link*'s `data:`
  URL is already refused by `isPermissiveUrl()`.
- An item's `htmlId` and `cssClass` land in the same attribute position as that bag, so they get
  the same treatment rather than being trusted for having their own column
  (`LinkAttributeHelper::isValidHtmlId()` — a single token, no whitespace/quotes/angle brackets;
  `isValidCssClassList()` — a token list, whitespace allowed, same character denylist).
- Every varchar(255) option column (`rel`, `cssClass`, `htmlId`, `ariaLabel`, `titleAttribute`,
  `icon`, `badge`) has a matching `max` in `defineRules()`. Without it an over-long value passed
  validation and failed at the database instead, surfacing as a save that silently didn't work.
- `MenuBuilderItem::isPermissiveUrl()` accepts absolute URLs, root-relative paths, fragments,
  `mailto:`, and `tel:` without forcing a scheme onto internal paths;
  `isValidAnchorTarget()` rejects whitespace and quote/angle characters.
- **`isPermissiveUrl()` rejects executing schemes explicitly** (`javascript:`, `data:`,
  `vbscript:`), comparing against the value with whitespace and control characters stripped and
  case folded. `filter_var(FILTER_VALIDATE_URL)` is not a safety check and must never be treated as
  one: it rejects `javascript:alert(1)` only because that has no authority component, and *accepts*
  `javascript://host%0Aalert(1)`, which browsers execute. A resolved URL is rendered straight into
  `href`, where Twig's escaping stops injection but not scheme execution, so the scheme is settled
  at validation time. This guards `customUrl` and `fallbackUrl` alike.

---

## Group persistence — database only

**MenuBuilder Group configuration is database-backed and the database is the single source of
truth. Groups are not persisted in Craft Project Config.**

The whole write path is:

```
CP request → GroupsController → MenuBuilderGroupService → MenuBuilderGroup (validation)
           → MenuBuilderGroupRecord → menubuilder_groups
```

and the whole read path is `menubuilder_groups` → `MenuBuilderGroupService` → resolver/cache. There
is no mirroring into `project.yaml`, no `onAdd`/`onUpdate`/`onRemove` handlers applying config back
to the database, and no `ProjectConfig::EVENT_REBUILD` participation.

This is deliberate. A second store would have to be kept honest against the first, which means
owning database/YAML drift, `project-config/apply` overwriting edits made in the CP, `sortOrder` and
`uid` synchronization, stale config after a partial deploy, and a rebuild path — all for data that
editors manage in the control panel and that is already covered by ordinary database backups.

Concretely, that means:

- `sortOrder` is database-only. Create assigns it, `move()`/`reorderSiblings()` update it in one
  transaction, duplicate gets the next value; nothing else holds a copy.
- Site restrictions live in the group's `settings` JSON column under `siteIds` (see
  `MenuBuilderGroupService::SITE_IDS_KEY`) — database-backed like everything else, and no migration
  was needed to add them.
- Uninstall is just `Install::safeDown()` dropping the two tables. Nothing is stranded in
  `project.yaml` for a reinstall to replay.

Menu **items** were never in project config either, for the same reason Craft entries aren't:
they're per-environment content, not structure.

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

`composer test` runs 516 PHPUnit unit tests (`tests/Unit`) with no booted Craft app.
`composer check-cs` (ECS, `ecs.php`, Craft's own set) and `composer phpstan` (`phpstan.neon`,
level 5, Craft's extension) both run clean over `src` and `tests`. Covered:

| Area | Test |
|---|---|
| Link resolvers, element availability/fallback decisions, resolver registry | `LinkTypeResolverTest` |
| Dynamic-source clamping / order-by whitelist | `DynamicNavigationConfigTest` |
| Visibility rules, rule combination, CP form → rule configs, cache boundary and pipeline order | `MenuBuilderVisibilityTest` |
| Item validation: per-type fields, options, URLs, anchors, attributes, mega-menu / dynamic-source metadata, executing-scheme rejection | `MenuBuilderItemModelTest` |
| Item CRUD, hierarchy and cache invalidation | `MenuBuilderItemLifecycleTest` |
| Drag-and-drop moves, depth and ordering | `MenuBuilderTreeMoveTest` |
| Group model validation, site availability, depth, group lifecycle | `MenuBuilderGroupTest` |
| Cached-node immutability, `flatten()`, mega-menu column grouping | `MenuBuilderNodeTest` |
| Active-state marking | `MenuBuilderActiveResolverTest` |
| Cache-key construction, element-sync targeting (class → source type, dynamic-source container matching, cache-duration ceiling) | `MenuBuilderCacheTest` |
| Controller permission mappings and the shared permission gate | `ControllerPermissionTest` |
| Attribute parsing, title fallback, rel merging, JSON bags, ID lists, calendar dates | `MenuBuilderHelpersTest` |

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
   `MenuBuilderDynamicNavigationService::resolveElements()`, and both services' own database
   writes all query real element/DB state and are verified manually. For items that means the
   hierarchy rules specifically: the `parentId` cascade firing on a parent delete, a real
   duplicated subtree, and a real circular/cross-group/max-depth move rejection are covered
   structurally in `MenuBuilderItemLifecycleTest` and behaviourally only by hand. A Craft testing harness (Codeception
   integration tests) is the natural next step.
3. **Orphaned items are surfaced, not repaired.** The dashboard badges an item whose linked element
   was hard-deleted; nothing reassigns or cleans it up.
4. **Two element changes fire no event at all.** (a) A **time-based entry status change** — a
   pending entry reaching its `postDate`, a live entry passing its `expiryDate`. Bounded by the
   `cacheDuration` ceiling above, not eliminated. (b) **Garbage collection hard-deleting a
   trashed element** after `trashDuration`: `Gc` deletes rows directly. Harmless in practice — the
   cache was already invalidated when the element was soft-deleted, and the element resolves to
   the item's fallback either way — but it is not event-driven.
5. **Commerce products (and any other element type) aren't synced.** MenuBuilder has no Commerce
   dependency and no `product` link type; `MenuBuilderElementService::WATCHED_CLASSES` is
   entry/category/asset. A third-party link type registered through
   `MenuBuilderLinkResolver::EVENT_REGISTER_LINK_TYPES` resolves correctly but gets **no automatic
   cache invalidation** — its own plugin has to call
   `MenuBuilder::getInstance()->cache->invalidateGroups()`. Making the watched-class list itself
   extensible is the natural fix and is not implemented yet.
4. **`MenuBuilderGroup::$settings` is still open-ended.** `siteIds` is the only documented key in
   it. Whoever adds the next per-menu frontend setting should decide whether it deserves a validated
   sub-model rather than another loose key.
