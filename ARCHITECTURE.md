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
| Controllers | `BaseMenuBuilderController` + `GroupsController`, `ItemsController`, `DashboardController`, `PreviewController` | The **only** classes that touch `craft\web\Request` or the session. The permission check itself lives once, in the base class's `beforeAction()`; subclasses only declare *which* permission an action needs. |
| Services | `MenuBuilderGroupService`, `MenuBuilderItemService`, `MenuBuilderResolver`, `MenuBuilderLinkResolver`, `MenuBuilderVisibilityService`, `MenuBuilderCacheService`, `MenuBuilderActiveResolver`, `MenuBuilderElementService`, `MenuBuilderDynamicNavigationService`, `MenuBuilderPreviewService`, `MenuBuilderLinkHealthService` | The only classes that query Records. Own all business logic, hierarchy integrity, and transactions. |
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
- **MenuBuilderPreviewOptions** — the validated description of one CP preview (device, audience,
  user groups, site, "seen from" URI). Request-scoped, never persisted — see "Preview".
- **MenuBuilderLinkHealth** — why one item's link would or wouldn't work, plus the wording the CP
  shows for it. Request-scoped, never persisted, and deliberately holds no element data — see
  "Link health".

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
   — also per-request, never cached. See "Active state" below for the matching rules.

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

## Link health

`MenuBuilderLinkHealthService` answers, for the control panel only, "will this item's link work,
and if not, why". It is the read-side companion to the resolution table above: same availability
rule, opposite audience.

| Status | Means | The editor's fix |
|---|---|---|
| `healthy` | Resolves to a usable destination | — |
| `missing` | The linked element no longer exists (hard-deleted, trashed, or archived) | Relink, fallback URL, disable, delete |
| `notOnSite` | The element exists, but not on the site being looked at | Same, or propagate the element |
| `disabled` | The element is disabled — globally, or for this site | Re-enable it |
| `unpublished` | Enabled but not live: pending, expired, or a status this plugin doesn't know | Publish it / fix its dates |
| `noUrl` | Available, but has no public URL (no URI format, private volume) | Give the section/volume a URL, or link elsewhere |
| `invalidUrl` | A `customUrl` or anchor target that isn't a valid, safe link | Correct the field |
| `invalidSource` | A `dynamic` item whose source config is unusable, or whose section / category group / volume is gone | Re-point the source |

Five things about it are deliberate:

- **It decides nothing of its own about availability.** `MenuBuilderLinkHealth::forElementStatus()`
  asks `ElementLinkResolver::isPubliclyAvailable()` first and only classifies the *reason* when the
  answer is no. A second copy of that rule is how a CP that flags links the front end renders (or
  passes links it drops) comes about. The non-element checks reuse `isPermissiveUrl()`,
  `AnchorLinkResolver::anchorTarget()` and `MenuBuilderDynamicNavigationService::normalizeConfig()`
  for the same reason.
- **Nothing is stored, and nothing is invalidated.** Health is computed per request like
  `ResolvedLink`, so a restored entry is healthy again on the next page load with no event handler
  of its own, and no "broken" flag can outlive the breakage. It is also kept out of the cached
  front-end tree entirely: that cache is shared across users, and this is a per-site CP read.
- **The warning discloses nothing about the content.** `MenuBuilderLinkHealth` holds a status and
  the item's own fallback configuration — no title, URI, slug, ID, section or volume name — and its
  three output methods are built from those alone. The CP tree is visible to anyone with
  `menuBuilder:view`; a warning about a disabled or unpublished element must not become a way to
  read what that element is. `MenuBuilderLinkHealthTest` pins this structurally, by asserting the
  object's whole property list.
- **It never destroys anything.** For a missing element the row menu offers one entry, "Fix this
  link…", which opens the editor; the editor names all four ways out (relink, fallback URL,
  disable, delete) and performs none of them. Recovery affordances are offered only for `missing` /
  `notOnSite` — a disabled or unpublished element comes back on its own, and pushing "delete this
  item" at a state that resolves itself is how a menu loses items it should have kept.
- **Cost is bounded by element type, not item count.** Two queries per element type present in the
  menu: one `site('*')->unique()` existence check (gone vs. merely not here — different warnings,
  different fixes, and one query can't tell them apart), one for the current site's rows and
  statuses.

The item's `fallbackBehavior` decides the second sentence of every warning — hidden, plain text, or
its fallback URL — and a configured-but-unsafe fallback URL is described as unusable, because
`ElementLinkResolver::fallbackFor()` re-checks it and emits nothing.

`MenuBuilderItemService::getOrphanedItemIds()` remains as the narrow "which items lost their
element" accessor, but is now a thin delegation to this service rather than a second element
lookup.

External URLs are **not** checked. Nothing in this phase makes an HTTP request; an external link is
judged on its shape alone.

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

## Active state

`MenuBuilderActiveResolver::mark()` is the only place active state is decided, and it decides it
from the request alone — nothing about it is stored, on the node or in the cache. It runs last in
the pipeline, on the `withChildren()` copies that come out of visibility filtering with both flags
cleared, so a second `mark()` pass (or a `currentUri` override) fully recomputes the tree rather
than accumulating onto a previous answer.

Two flags, deliberately distinct:

| Flag | Meaning |
|---|---|
| `isActive` | This node's URL **is** the page being served. At most one node in a tree has it. |
| `isActiveAncestor` | A descendant is active. Propagates the whole way up, so a grandparent of the active node has it too. |
| `isActiveOrAncestor()` | Either — the "open branch" convenience for styling. |

That split is what makes `aria-current="page"` correct: the templates put it inside
`{% if node.isActive %}` only, so it lands on the one link for the current page and never on an
ancestor or a non-clickable heading. Styling uses `isActiveOrAncestor()` instead.

Matching compares **paths**, not strings. Both sides are reduced to a leading-slash,
no-trailing-slash path with the query string and fragment dropped, because the two sides are
spelled differently by construction: Craft's `Request::getFullUri()` has no leading slash, an
editor's custom URL usually does, and an element URL is absolute. So `/news`, `news`,
`https://example.test/news/`, and `/news?page=2#top` are all the same page. Nothing is matched by
prefix — `/news` is not active on `/newsletter`, and a parent item is an active *ancestor* of a
deeper page only when it actually has a child item for it.

What can never be the current page:

- A URL on a **host that isn't the site being served**. Absolute item URLs are compared host-first
  against the request host plus the *current* site's base-URL host
  (`MenuBuilderResolver::internalHosts()`), so a custom URL to
  `https://shop.elsewhere.test/products/shoes` — or a protocol-relative
  `//elsewhere.test/products/shoes` — is not marked active just because the local path matches. The
  base URL is in the list to cover the `www.` vs bare spelling mismatch between a site's base URL
  and the request; a *sibling* site's host is deliberately **not**, because sibling sites routinely
  share a path structure (`/contact` exists on English, German and French) and admitting their
  hosts marked the German link as the current page while English was being rendered. A cross-site
  link still resolves active state normally on the site it points at — the request host is that
  site's host then. When no host is knowable (a console request), host comparison is skipped rather
  than failing every absolute URL closed.
- A URL whose scheme doesn't navigate within a site (`mailto:`, `tel:`, anything a third-party link
  type registers). `parse_url()` reports a path for these (`a@b.com` for `mailto:a@b.com`), which
  would otherwise be comparable to a request.
- An **unavailable** link (`isLinkAvailable === false` — a deleted/disabled element on "disable
  link", a rejected custom URL), a blank URL, or no URL at all. Checked explicitly rather than
  relying on those paths also happening to produce `null`.
- A **fragment-only** anchor item (`#top`). It's a position on a page, not a page — and in
  particular it must not collapse to `/` and light up the homepage item.

Templates can override the URI they're matched against —
`craft.menuBuilder.get('main', '/products/shoes')` — which is what makes the whole thing testable
without a request and useful for previews.

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

`_macros/tree.twig`'s `renderMegaMenu(node, disclosure = 'details')` is an optional example
renderer: a native `<details>`/`<summary>` disclosure around one `<ul>` per column, or — with
`disclosure: 'none'` — the columns in flow with no disclosure at all. `render()` calls it
automatically for any node whose `megaMenu` is set. Hand-rolled Twig can ignore the macros and read
`node.megaMenu`/`megaMenuColumns()` directly. Why it is a native disclosure rather than a button
with `aria-expanded`, and why that is a correctness decision rather than a styling one, is in
"Accessibility" below.

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

`MenuBuilderCacheService` caches **only** the link-resolved node tree (pipeline step 3). Visibility
filtering and active-state marking are deliberately never cached.

The key is `menu-builder:tree:{siteId}:{handle}:{configVersion}`
(`MenuBuilderCacheService::cacheKey()`, a pure public static method so key construction is
unit-testable), because three things can make two payloads differ:

| Key component | Why it's there |
|---|---|
| **site** | `ElementLinkResolver` resolves elements against the current site, so the same menu legitimately resolves to different URLs, titles and availability per site. `menubuilder_groups.handle` is unique per install, not per site — a Group isn't itself site-scoped, its *resolved* tree is. Without the site in the key, two sites would read and clobber each other's tree |
| **menu**, by handle | The identity Twig asks for |
| **config version** (`configVersion()`) | A digest of everything outside the item rows the payload depends on: the payload *shape* (`payloadVersion()` — see below), the plugin's `schemaVersion`, and the menu's own `id`, `handle` and `dateUpdated`. The database moves `dateUpdated` on every menu save, so **editing a menu reads a fresh key by construction**, independently of the invalidation the save also runs; the `id` is what stops a *new* menu that reuses a freed handle from reading the old menu's tree; and the schema/payload version is what stops an upgrade from reading entries written by the previous version's code |

`payloadVersion()` is a digest of the declared property names of the classes a cache entry actually
*is* — `MenuBuilderNode` and `MenuBuilderMegaMenuConfig` (`PAYLOAD_CLASSES`, hashed by the pure
`shapeDigest()`). An entry is a serialized object graph, so adding, removing or renaming a property
on either class would otherwise unserialize an old entry into an object with uninitialized readonly
properties and hand Twig a half-built node. Deriving it by reflection rather than a hand-bumped
constant means the payload version can't be forgotten during an upgrade. It costs one reflection
pass per request at most, memoized, and only on a cache read.

A read also rejects anything at the key that isn't an array — a foreign value, a truncated entry —
and rebuilds instead of passing it to Twig. A menu with no `id` (never saved) is resolved fresh and
never cached at all.

Entries are normally refreshed by explicit invalidation, but they are written with Craft's own
`cacheDuration` as a **ceiling** (`resolveDuration()`; `0` still means "no expiry", as it does in
Craft). That bounds the one kind of staleness no event can announce: a pending entry going live at
its `postDate`, or a live entry expiring at its `expiryDate` — both are clock-driven, fire nothing,
and would otherwise be invisible to navigation indefinitely. It's the same bound Craft puts on its
element query caches.

### Invalidation

Every entry is tagged twice: with a **per-menu** tag (`groupTag()`, keyed by menu **ID**) and with
the global `menu-builder` tag. Targeted invalidation is therefore one tag invalidation for the
affected menu, and it reaches that menu's entry on *every* site and under *every* config version it
was ever written under — without enumerating site IDs and without knowing which versions exist.
Keying the tag by ID rather than handle is what makes a **rename** safe: the entries written under
the old handle are still reached.

That also removed a hazard the previous key-enumerating implementation had to work around:
`getAllSiteIds()` answers differently depending on where it is called from (a front-end request — a
web-triggered queue job running a structure move's URI updates, say — sees only enabled sites), so a
tree cached while a site was enabled could survive an invalidation and be served the moment the site
was switched back on. There is no site list in the invalidation path any more.

Invalidation is targeted:

| Change | Invalidates |
|---|---|
| Item create/save/move/reorder/duplicate/delete (single or bulk) | That item's menu only (`invalidateGroupId()`), across every site |
| Menu create/save/duplicate/delete | That menu only (`invalidateGroupId()`). A menu save can change the handle the cache key is built from, but the tag is keyed by ID, so the old handle's entries are reached anyway — and `dateUpdated` moving means the new key is a miss regardless. A delete invalidates because the freed handle can be taken by a new menu |
| Entry/category/asset save, delete, restore, slug/URI update | Only groups whose items reference that `elementId` (one indexed query), plus groups whose enabled `dynamic` items are sourced from that element's **own** section/category group/volume |
| Section / category group / volume save | Only groups referencing an element **inside that container** (one sub-query on the element's own table), plus dynamic items sourced from it |
| Site save or delete (including via `project-config/apply`) | **Everything** (`invalidateAll()`) — every cached tree was resolved against a site whose base URL, language or existence may have just changed |
| Draft / revision / provisional-draft save | Nothing — a menu item's `elementId` always points at a canonical element, so reacting to draft saves would invalidate live navigation for unpublished edits |
| Any element type a menu can't link to (users, global sets, …) | Nothing |

`MenuBuilderElementService` (attached once from `MenuBuilder::init()`) owns the element half of
this and calls `MenuBuilderCacheService::invalidateGroupIds()` with the menu IDs its lookups already
returned — no handle resolution on the path at all. It listens for:

| Event | Why it's needed |
|---|---|
| `Elements::EVENT_AFTER_SAVE_ELEMENT` | Title, slug, URI, publish/unpublish, enable/disable — including per-site status. Craft's own resaves (a section's URI format changing with `autoResaveEntries` on, `resave/entries`, a per-site delete's resave of the remaining sites) all run through `_saveElementInternal()` and fire it too |
| `Elements::EVENT_AFTER_DELETE_ELEMENT` | Soft **and** hard delete — Craft fires this for both |
| `Elements::EVENT_AFTER_RESTORE_ELEMENT` | Restore from the trash |
| `Elements::EVENT_AFTER_UPDATE_SLUG_AND_URI` | A **structure move** rewrites the moved entry's and its descendants' URIs through `Elements::updateElementSlugAndUri()` (usually via a queue job) and fires *no* save event. Without this listener a nested-URI menu link went stale after a drag in the entries index |
| `Entries::EVENT_AFTER_SAVE_SECTION`, `Categories::EVENT_AFTER_SAVE_GROUP`, `Volumes::EVENT_AFTER_SAVE_VOLUME` | Container-level URL changes. A URI-format change only resaves entries when `autoResaveEntries` is on, and a volume's base URL/filesystem change never resaves its assets — so no element event fires for either |
| `Sites::EVENT_AFTER_SAVE_SITE`, `Sites::EVENT_AFTER_DELETE_SITE` | Site-level change — the one case that flushes **everything** (`handleSiteChange()`). A site save can change the base URL every cached URL was built from, the language every cached title was read in, or disable the site; a site delete can transfer its content to another site, changing URLs on a site that was never itself edited. No element event fires for any of it. This is also the path a deploy takes: sites live in project config, and `project-config/apply` reaches them through `Sites::handleChangedSite()`/`handleDeletedSite()`, which fire exactly these two events |

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

`invalidateAll()` is the global tag, and `MenuBuilderElementService::handleSiteChange()` is its
**only** caller anywhere in the plugin — `MenuBuilderGroupTest` scans `src/` to keep it that way, so
a future change to one menu can't quietly start flushing the install.

`invalidateGroup(string $handle)` / `invalidateGroups(array $handles)` remain as the third-party
entry points (a handle is what an integrating plugin knows); they resolve to IDs through
`MenuBuilderGroupService::getByHandle()` and go through the same ID-keyed path.

### Invalidation and transactions

Invalidating *inside* an open transaction is a stale-cache hazard rather than a harmless early
flush: a concurrent front-end request can rebuild the tree from **pre-commit** data, re-cache it,
and nothing invalidates it again after the commit — the change stays invisible until something
unrelated happens to flush the menu. Every single-item write already invalidates after its own
`commit()` (`MenuBuilderItemLifecycleTest` pins the ordering), but the bulk paths can't be fixed by
ordering: `bulkSetEnabled()`/`bulkDelete()` wrap many per-item writes, each invalidating, in one
transaction that commits after all of them.

So an invalidation raised while a transaction is open is **queued** and flushed when the outermost
transaction ends (`flushPending()`, hung off `yii\db\Connection::EVENT_COMMIT_TRANSACTION` and
`EVENT_ROLLBACK_TRANSACTION`, which Yii fires only at transaction level 0 — a nested savepoint
release must not flush early). The queue collapses repeats, stays per-menu, and flushes on
**rollback** too: nothing changed in the database then, but a concurrent request may have re-cached
mid-transaction data either way, and a wasted rebuild is the safe error.

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
| `menuBuilder:view` | Reading the CP section and trees; the preview screen (`PreviewController::actionIndex`); rendering the group and item **edit forms** (`GroupsController::actionEdit`, `ItemsController::actionEdit`) |
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

`BaseMenuBuilderController::cpAffordances()` is the other half of the same rule, and is pure for the
same reason (`CpAffordanceTest`): it turns one user's admin flag and granted permissions into the
five `canView`/`canCreate`/`canEdit`/`canDelete`/`canManageSettings` booleans every CP template asks
before rendering a control. Every screen-rendering action spreads `currentUserAffordances()` into its
template variables. This is a UX guarantee, not a security one — `beforeAction()` enforces access
either way — but a Delete entry that answers 403 reads as a broken plugin rather than a restricted
role, and the two lists drifting apart is exactly what produced Delete under `edit` and "New menu"
under `create`. Note that `groups/edit` and `items/edit` require only `view`: both screens are
deep-linkable and must render **read-only** (a `disabled` fieldset, no Save, no destructive form
action) rather than offer a save the action would refuse.

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
| `preview.js` | The preview stage's interaction only: inert links, the mega-menu disclosure, the mobile toggle. Resolves nothing and requests nothing — see "Preview" |

Two details that have bitten this code before, worth not regressing:

- Rows must be looked up by `data-id`, **never** from the clicked anchor — Craft relocates an open
  disclosure `.menu` to near `<body>`, so the anchor is no longer inside its row.
- The Disabled badge needs its own `menu-builder-item-disabled-flag` class, because
  `.menu-builder-item-status` is shared with the mega-menu and link-health badges.
- **The tree's hierarchy connector needs every row to draw its *ancestors'* columns, not just its
  own.** The rows are a flat list, so a row can only paint over its own box — which means a rail
  down a sibling column cannot be drawn by those siblings: siblings at level N stop being adjacent
  rows the moment one of them has children, and the deeper rows in between draw at a deeper indent.
  The span between them has to be painted by those deeper rows. Each row therefore carries one
  `.menu-builder-item-rails > i` per ancestor column still open above it, plus a `::before` for its
  own column — an elbow when it is the last sibling, a rail straight through with a tick otherwise.
  `MenuBuilderTree.syncRails()` is the **single authority** on both, recomputing them in one forward
  pass on init and after every move; `dashboard/_items.twig` threads the same `openColumns` list
  down its recursion purely so there is no unrailed first paint. Because the JS pass also runs on
  init, a disagreement between the two corrects itself in the same tick rather than alternating —
  which is what the first version got wrong: Twig set `menu-builder-item-last` from `loop.last`
  while the post-drag JS recomputed it as "is the next row shallower", and those are different
  predicates for a last child that has children of its own, so a row was "last" on load and "not
  last" after any drag. `MenuBuilderTreeSorter.updateInsertionRails()` does the same for the drop
  slot, which no forward pass can reach because it isn't in the tree yet.

`item-fields.js` exports two helpers the dashboard's quick-add panel reuses rather than
reimplementing: `setFieldsDisabled()` and `syncDynamicSourcePickers()`. Several sibling sections
deliberately share one field name — `customUrl` (url/anchor), `elementId` (entry/category/asset),
`dynamicSourceId` (the three dynamic source pickers) — because only one applies at a time, so hiding
the others isn't enough: a hidden field still serializes and clobbers the visible one. Exactly one
may be *enabled*, and both screens ask the same function which. The pickers are the same
`data-dynamic-source` markup on both screens, and nothing about a dynamic source is decided in JS —
`MenuBuilderItem::validateDynamicSource()` and
`MenuBuilderDynamicNavigationService::normalizeConfig()` remain the only authorities.

`menu-builder.js` also owns `applyFieldErrors()`/`clearFieldErrors()`: one renderer for the
per-attribute `errors` bag every `asModelFailure()` returns, shared by the slide-out and the
quick-add panel. It reproduces Craft's own `.field.has-errors` + `ul.errors` markup and wires the
list to its input with `aria-describedby`, and it collects messages whose attribute has no field of
its own (`metadata` and `visibility` are validated as whole bags) into one summary rather than
dropping them. Fields are found by `name`, and disabled inputs are skipped — a hidden, inapplicable
section shares field names with the visible one by design (see `item-fields.js`), so anchoring an
error to one would point at a field the editor can't see.

**Anything that reloads the page must carry its confirmation with it.** Adding, duplicating, saving
and deleting an item all change the tree's shape, so they reload rather than patch; a toast raised
before `window.location` is assigned is wiped by the navigation. `MenuBuilderTree.reloadWith()`
puts a notice *key* (never text — the notice is rendered as a CP notification), an optional row id
and an optional count in the query string, and `highlightNewlyAdded()` reads them back on the next
load, strips them from the URL with `replaceState`, raises the notification and scrolls the row into
view. Toggling a row's enabled state is the exception that does *not* reload, so it toasts directly.

**Full-page CP screens must not declare a `<form>` of their own.** `_layouts/cp` wraps content *and*
details in one `<form>` when `fullPageForm` is set, so a nested one is invalid markup that the HTML
parser silently discards — which is how both edit screens' Delete buttons ended up submitting the
page form with their `onsubmit` confirmation gone. Destructive actions go in `formActions`
(`destructive: true`, `action`, `params`, `confirm`, hashed `redirect`), which Craft's `formsubmit`
posts through the page form with a real confirmation. `CpAffordanceTest` scans for both.

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

## Preview

`menu-builder/<handle>/preview` renders one saved menu the way a chosen audience, on a chosen site,
viewing a chosen page would receive it. It is a **simulation of the request**, not a second
renderer and not a sandbox for edits.

### What a preview is, exactly

The pipeline is unchanged — `MenuBuilderPreviewService::getTree()` calls the same
`MenuBuilderResolver::getTree()` Twig calls. Exactly two inputs are substituted:

| Substituted | Left alone |
|---|---|
| The `VisibilityContext`'s `isLoggedIn`, `userGroupIds` and `currentSiteId` | `now`, the timezone and `CRAFT_ENVIRONMENT` — so `dateRange` and `environment` rules answer what they answer for a visitor *right now*, and a scheduled item cannot be made to appear early |
| The current site, for the duration of one resolve (`withSite()`, restored in a `finally`) | Everything else: link resolution, the cache, hierarchy, mega menus, dynamic sources, active-state matching |
| The URI active state is matched against (the existing `currentUri` argument) | The `MenuBuilderNode` contract and the shipped `_macros/tree.twig` renderer, which the screen imports unchanged |

Consequences worth stating out loud, because the whole feature is only useful if its boundaries are
known — the screen states the same list to editors in its "What this preview represents" panel:

- **Saved data only.** There is no draft state to preview. Every CP mutation in this plugin writes
  immediately (drag, reorder, toggle, item save), so "saved" and "what the editor is looking at"
  are the same thing. A preview of unsaved changes would need a draft mechanism this plugin
  deliberately doesn't have, so the screen says what it shows rather than implying one.
- **Preview writes nothing.** No save, no delete, no transaction, no session state; the current
  site is put back before the response is rendered. `MenuBuilderPreviewTest` asserts this against
  the source, since there's no database in the unit suite to observe a write with.
- **The simulated audience enters after the cache**, at the same place the real one does. That is
  what keeps it safe: `buildResolvedNodes()` never sees a context, so a preview reads exactly the
  entry a visitor reads and cannot leave a previewer's audience behind in one. Pinned by
  `MenuBuilderPreviewTest` alongside the request-path ordering `MenuBuilderVisibilityTest` pins.
- **Disabled items and disabled menus are absent**, and a menu restricted away from the simulated
  site returns `null` — all three are front-end outcomes, reported as "renders nothing" rather
  than as an error.
- **Device is a width, not a user agent.** The mobile option constrains the preview surface so
  wrapping and depth can be judged; no UA is spoofed and the site's own CSS/JS is not loaded.

### Why the simulation can't be widened by the person previewing

Every knob is a query param, so `MenuBuilderPreviewOptions::normalize()` is the security surface,
and it fails closed in the same sense the visibility layer does:

- An unrecognised **audience** becomes `loggedOut` — the narrowest one — never a wider one.
- **User group IDs** go through `ConfigHelper::strictIdList()` (not `normalizeIdList()`: these IDs
  decide which group-restricted items are revealed, so a stray `true` must not `intval` into group
  1) and are then intersected with the groups the service offers. A `userGroup` audience left with
  no usable group collapses to `loggedIn`, which is what it actually means.
- The **site** is checked against `MenuBuilderPreviewService::allowedSiteIds()` — Craft's own
  editable-sites boundary, reused rather than reinvented, because previewing a site resolves that
  site's elements. A user with no site permissions gets their own current site.
- The **"seen from" URI** is reduced to a site-root-relative path: schemes (`javascript:`,
  `data:`, `https://…`), protocol-relative hosts, backslashes and control characters are discarded
  rather than repaired, and the query string and fragment are dropped because active matching drops
  them too.

The normalized options — never the raw query string — are what the screen echoes back into its
form, so it can only ever describe the audience it actually rendered.

Simulating a user group is not an escalation: it reveals which *menu items* are restricted to that
group, which the same user already sees in the item editor, and it grants nothing else. Element
links resolve through the same publicly-available-status boundary the front end uses
(`ElementLinkResolver`), whoever is previewing — **a preview cannot surface unpublished content**.
The screen is `menuBuilder:view` for the same reason the dashboard is: it renders a tree and
changes nothing. Its controls are a GET form (a particular preview is a shareable URL), and there
is no state-changing request on the screen for CSRF to protect.

### The stage, and why it isn't a second renderer

The preview surface is `_macros/tree.twig`'s own output, captured once in `preview/index.twig` and
used twice: rendered live inside the stage, and printed **escaped** in the "Rendered markup" panel
so `aria-current`, `aria-label`, the mega menu's `<details>` disclosure, `rel` and `target` can be
read without leaving the CP. A CP-only renderer would be a second thing to keep true, and the day it
drifted the preview would be confidently wrong about the one thing it exists to answer. Nothing on
the screen is printed with `|raw`.

The panel prints that capture through `MenuBuilderPreviewService::formatMarkup()` — a pure, static
re-indenter — because Twig emits readable *templates*, not readable *output*: the macro's
conditional attributes each sit on their own source line, so raw output arrives with an anchor's
attributes scattered down a dozen lines and long gaps where an `{% if %}` didn't match, which is
unusable as an inspection tool. It is **display only** (the stage renders the unformatted capture,
so a bug in it cannot change what is previewed) and purely textual: it re-spaces and never adds,
removes or reorders an element or an attribute, which `MenuBuilderPreviewRenderTest` pins by
comparing both strings with all whitespace stripped. It is safe to run because its input is
*rendered* HTML — editor text is already escaped, so a title reading `<script>` is `&lt;script&gt;`
and cannot be mistaken for a tag — and the formatted lines are still printed through `{{ }}`, so
autoescaping is what puts them on screen as text.

Around that markup sits `preview/_stage.twig`: a browser bar, a brand, a site header, a skeleton
page, a footer. It is **chrome only** — it takes the captured markup as a string and never touches a node.
It does not read `children`, ask whether something `isActive`, group a mega-menu column or resolve
an icon; `MenuBuilderPreviewTest` asserts that against the file, because the moment the stage starts
reasoning about menu data it becomes the second renderer this design exists to avoid. It is a
separate partial rather than inline markup so it can be rendered on its own, against real
`MenuBuilderNode` objects, in `MenuBuilderPreviewRenderTest`.

**Placement** — header or footer — is a preview *control*, not a stored setting. Nothing in
MenuBuilder records where a template renders a menu, deliberately: `craft.menuBuilder.get('footer')`
can be called in a masthead, so a stored placement would be a second, unenforceable truth. The
screen offers both, defaults to `MenuBuilderPreviewOptions::guessPlacement()`'s reading of the
menu's own handle and name (pure, unit-tested), and states which one it used — so a wrong guess
costs one click rather than misrepresenting anything. The region the menu is *not* in is drawn as
grey pills, which is what makes "this is the footer menu" legible at a glance. A footer navigation
is rendered fully expanded in columns, because that is what a footer is and it is also the fastest
way to read a whole menu's structure at once. Both placements emit the navigation through **one**
macro in the stage, so they cannot drift into two renderings.

Everything that makes it *look* like a navigation is CSS keyed to attributes and classes the macro
already emits — `li.is-active`, `a[aria-current="page"]`, `.menu-builder-megamenu-trigger`,
`.menu-builder-megamenu-panel`, `.menu-builder-megamenu-column`, `.menu-builder-icon`,
`.menu-builder-badge`, `li[role="separator"]`. **No preview-only class is added to a single
navigation element.** Desktop lays the top level out horizontally and opens level two as a dropdown
card on `:hover` *and* `:focus-within` (so the keyboard demonstrates the same state a pointer does),
with level three and deeper shown in place as an indented group. Mobile is a 390px device frame with
the same markup stacked and railed, and its own disclosure button — chrome, since a real site owns
that control. The stage uses a fixed light palette rather than Craft's CP variables: it stands in
for a website, and a navigation that inherits the CP's colours reads as another CP widget.

`web/assets/cp/js/preview.js` is the interaction layer, and it is deliberately tiny: make the
**whole parent item** the disclosure (hover on desktop, tap on mobile, `:focus-within` for the
keyboard) rather than the caret, intercept clicks so a preview link reports where it points instead
of navigating the editor away, and open/close the mobile navigation. Because a parent's label is the
disclosure, a parent that is also a link doesn't navigate on click here; the screen's explainer says
so. It contains no request of any kind and resolves nothing.

How "open" is expressed depends on what the item is, and the difference is the same one the
Accessibility section turns on. A **plain submenu** is an ordinary nested list with no disclosure
state to be wrong about, so it uses `data-mb-open` on the `<li>` as a preview-only styling hook
beside CSS `:hover`/`:focus-within` rules — the preview degrades to working hover and keyboard if
the script never runs. A **mega menu** is a native `<details>`, so `preview.js` opens one by setting
`open` and nothing else, and the stylesheet has no rule that reveals a panel whose `<details>` is
closed. That rule used to be there: the stage opened mega panels on `:hover`/`:focus-within` while
`aria-expanded` was maintained separately (and not for focus at all), which is precisely the
divergence the front-end macro was redesigned to make impossible. The preview now demonstrates the
same guarantee it documents.

**The site's own CSS and JS are deliberately never loaded.** Executing a project's front-end
JavaScript inside the control panel is not a preview, it's an execution surface — it could mutate CP
state, break Craft, or depend on a layout that doesn't exist here. So what the stage reproduces is
**structure, hierarchy, state and attributes**, not a theme's typography and colours, and both the
docs and the screen's own explainer say so rather than claiming a pixel-perfect preview. One visible
consequence worth knowing: an icon-font icon has no font loaded in the CP, so a class icon renders
as a neutral placeholder square while an asset icon renders as its real image.

---

## Accessibility

Accessibility is a property of the markup that leaves the plugin, so it is pinned the same way
escaping is: by rendering the real macros against real nodes and asserting on the DOM
(`MenuBuilderAccessibilityTest`, sharing the `NavMacroRendering` harness with
`MenuBuilderPreviewRenderTest`). [ACCESSIBILITY.md](ACCESSIBILITY.md) is the consumer-facing
version of this section, plus the manual release checklist.

**One rule underneath all of it: an attribute must describe something that is true.** Every ARIA
attribute the macros emit is backed by a decision made elsewhere in the pipeline, and where no such
decision exists the attribute is not emitted:

| Attribute | Backed by |
|---|---|
| `aria-current="page"` | `MenuBuilderActiveResolver`'s `isActive`, which at most one node in a tree carries. Ancestors get `isActiveAncestor` and the `is-active` *class* — never the attribute (see "Active state"). |
| *(no `aria-expanded`/`aria-controls`)* | A mega menu's open state is a native `<details open>` — see below. There is no ARIA copy of it to fall out of step. |
| `aria-label` (landmark) | The menu's name, so two navigations on a page are distinguishable. |
| `aria-label` (mega-menu button) | What the button *does* — "Explore submenu" — because the item's own label is already rendered beside it, and two controls with one name is a choice a screen-reader user can't make. |
| `aria-hidden` / `alt=""` | Icons, which are decorative by construction — the item's title is the name. |
| — | No `aria-haspopup`: the panel is ordinary links, not a `role="menu"` widget with roving focus. No `role="separator"` on the `<li>`: the `<hr>` inside it already *is* the separator, and the `<li>` must stay a `listitem`. No `tabindex` anywhere: every link is a Tab stop in document order. |

Three consequences worth keeping:

- **A disclosure has one state, and the browser owns it.** This is the load-bearing decision of the
  phase. A `<button aria-expanded="false">` beside a panel splits one fact across two owners: the
  attribute belongs to whatever script is running, the visibility to whatever CSS the site wrote.
  Any theme opening the panel on `:hover`/`:focus-within`, or any page where the optional script was
  never registered, then shows a panel the markup still calls closed — and no amount of scripting
  from inside the plugin can prevent it, because the plugin does not own the stylesheet. So the
  second state was **removed**, not synchronised: the mega menu is a native `<details>`, whose
  `open` attribute *is* the rendering, *is* what a pointer/Enter/Space toggles, and *is* what a
  screen reader announces. `web/assets/nav/NavAsset` is now an enhancement (Escape, arrows,
  Home/End, closing a sibling panel) that sets `details.open` and writes no attribute of its own;
  with it absent the menu still opens, closes and announces itself correctly. The plugin's own CP
  preview had the bug this removes — its stylesheet opened mega panels on `:hover`/`:focus-within`
  while `aria-expanded` stayed `false` — which is the clearest evidence that "just keep the
  attribute in step" is not a contract a plugin can hold. The single rule left for a theme is
  therefore expressible in one line: **never make a panel visible while its `<details>` is
  closed.**
- **Custom attributes are re-checked at render, not only on save.** An `htmlAttributes` bag is the
  one editor-authored value whose *keys* land where an attribute **name** goes, and Twig's escaping
  does not make that position safe (`x" onclick="…` as a key would emit a live handler).
  `LinkAttributeHelper::filterHtmlAttributes()` — used by `MenuBuilderNode::safeHtmlAttributes()`
  and `MenuBuilderGroup::safeHtmlAttributes()`, which is what the macros render — drops
  handler-shaped names, `javascript:`/`vbscript:` values, and everything in
  `LinkAttributeHelper::RESERVED_ATTRIBUTES`. This is the same fail-closed re-check
  `UrlLinkResolver` applies to a stored `customUrl`: a bag can reach the database without passing
  validation (import, direct write, a row older than the rule), and the cached tree can predate a
  tightened rule. It **filters** rather than erroring because it runs mid-render, where the useful
  answer is a menu missing one attribute; the save path still refuses the same value with a message.
- **The reserved list has two halves, one reason.** `href`/`target`/`rel`/`id`/`class`/`role` are
  emitted by the macros themselves, so a second copy is a duplicate attribute the browser silently
  drops — except on a heading's `<span>`, which has no `href` of its own and would become a link.
  `tabindex` and the `aria-*` states are refused because they describe behaviour a rendered menu
  does not implement: a typed `aria-current="page"` would announce the wrong page, `aria-hidden`
  would hide a visible link from half the audience, and a `tabindex` would reorder or remove the
  item from the keyboard path.

`opensInNewTab()` is on the node rather than in the template so both halves of "this opens a new
tab" come from one answer: the `target="_blank"` attribute, and the visually hidden "(opens in a
new tab)" that puts the change of context into the link's accessible name (WCAG 3.2.5). It is
`isClickable && target === '_blank'`, so a heading carrying a stale `target` announces nothing.
The hint carries an inline style as well as a class because the plugin ships no front-end CSS —
without it, a theme with no visually-hidden helper would print the phrase beside every external
link.

What the plugin deliberately does **not** own: focus indicators, whether a submenu opens on
`:focus-within` as well as `:hover`, contrast, and skip links. Those are the site's CSS and page
structure, and a plugin that shipped opinions about them would be overriding a design system it
cannot see.

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

### The icon model

Icons are one column (`icon`, varchar(255)) with a grammar rather than a column per form, so
there is no second place an icon can live and no migration when a new form is wanted:
`asset:<id>` is an Asset reference, and any other value is an icon handle / CSS class list. The
bare form is what the column always held, so existing rows keep their meaning; `class:` is
accepted on input and normalized off, so one icon has one stored spelling.

`helpers/IconHelper` owns the grammar and is the only place that parses it —
`MenuBuilderItem::validateIcon()` normalizes and rejects through it, and both
`MenuBuilderItem` and `MenuBuilderNode` expose `iconType()` / `iconClass()` / `iconAssetId()` as
*derived* accessors over the stored string rather than as extra state.

Two decisions are load-bearing:

- **Markup is not a storable form.** Class values are allowlisted to `[A-Za-z0-9 _.:/-]`, which
  contains no `<`, quote, `=` or `&` — so a stored class icon cannot carry markup or break out of
  an attribute even if a template renders it with `|raw`, which is the one thing this plugin
  cannot police. SVG icons are Assets, rendered through `<img src>` (script inside an SVG doesn't
  execute there), never inlined. The reader fails closed too: `classValue()` returns null for
  anything that wouldn't validate today, so a legacy row or a direct database write can't reach a
  template either.
- **The tree caches the reference, not the rendering.** An asset's URL can change with no menu
  item changing — nothing would invalidate a cached URL — so the node carries `asset:123` and
  `MenuBuilderVariable::iconAsset()` resolves it per request, memoized by asset id so repeated
  icons cost one query. Same boundary as visibility and active state: anything whose truth
  outlives the cache entry stays out of it.

### The badge model

A badge — the `[NEW]` beside "Products" — is two values on storage that already existed: free
text in the `badge` varchar(255) column, and an optional style in `metadata['badgeStyle']`, one
of `default | info | success | warning | critical`. No new column, and so no migration: the style
is a small closed enum, not queryable data, and `metadata` is the model's documented extension
point (mega menu and dynamic sources already live there). An item that never had a style set
simply has no key, which reads back as the default.

`helpers/BadgeHelper` owns both halves and is the only place either is parsed;
`MenuBuilderItem::validateBadge()` normalizes and rejects through it, and both `MenuBuilderItem`
and `MenuBuilderNode` expose `hasBadge()` / `badgeStyle()` / `badgeClass()` as derived accessors,
the same shape as the icon model above.

The load-bearing decision is that **the two halves get opposite treatment**, and conflating them
is exactly how a badge would become an injection vector:

- **Text is text and is never sanitized.** It is emitted as content, never as markup, so
  `<script>alert(1)</script>` typed into the field is a badge that *reads*
  `<script>alert(1)</script>`, escaped by Twig at the boundary. Stripping `<` here would silently
  mangle legitimate badges (`<3`, `Tea & Coffee`) while buying nothing — the safety is the
  escaping, not a denylist, and the bundled macro prints `{{ node.badge }}` with no `|raw`
  anywhere near it. Its only constraint is the column's `max` rule.
- **Style is an allowlist, because it reaches a `class` attribute.** `BadgeHelper::style()` fails
  closed — a legacy row, a direct database write or a crafted post reads back as "no style" — and
  `badgeClass()` is the only thing that builds the class list, so there is no path from editor
  input to a class attribute that isn't one of the five constants. Validation rejects an unknown
  style at the door as well, so it fails closed on write *and* on read.

An empty badge is not a badge: whitespace-only text normalizes to null, and a style with no text
renders nothing, so a style orphaned by a cleared badge can't emit an empty pill. The CP tree row
previews the badge through the same accessors and the same escaping.

A badge is presentation only. It is not read by the link resolvers, the active resolver or the
visibility rules (pinned by a test that greps those directories), so it cannot change a URL, an
active state or who sees an item. It *is* menu-wide rather than per-user, so it belongs in the
cached payload — and `badgeStyle` being a new `MenuBuilderNode` property rotates the cache key by
itself, because `payloadVersion()` digests that property list (see the caching section).

### Custom fields

Icon, badge, description, image, `featured` and the CSS/attribute fields are **built-in**
presentation, each with its own column. Custom fields are the extension point for everything a
particular site needs on top of those, defined by the editor rather than by the plugin — and they
are two things, stored in two places that already existed:

| | Where | Read back by |
|---|---|---|
| **Definition** — handle, name, type, options, required | the menu's `settings` bag, under `MenuBuilderGroupService::CUSTOM_FIELDS_KEY` | `MenuBuilderGroup::$customFields` |
| **Value** — one scalar per handle | the item's `metadata` bag, under `CustomFieldHelper::VALUES_KEY` (`custom`) | `MenuBuilderNode::$customFields` |

Neither is a new store, and there is deliberately no second metadata/settings system: `settings`
already carries the menu's site restriction the same way (lifted out on read so `$group->settings`
stays a plain user bag), and `metadata` already carries the badge style, the mega-menu config and
the dynamic source. So custom fields need **no migration**, duplicate for free (both bags are copied
verbatim by `duplicateRecord()` and `duplicate()`), delete with the item row, and export as ordinary
JSON.

Definitions belong to the menu, not the item — every item in a menu is offered the same set, the way
every entry in a section shares a field layout. `MenuBuilderItem::$customFieldDefinitions` is
injected, never persisted, so the model keeps validating without a database;
`MenuBuilderItemService::save()` fills it in for every write path that doesn't, so a save is always
definition-aware.

Three decisions are load-bearing:

- **The type list is closed and has no markup member.** `text | textarea | number | boolean |
  select | url | asset` — every storable value is a scalar that templates emit through Twig's
  autoescaping. There is no path from a custom field to raw markup, to a `class` attribute, or to a
  URL scheme that executes: the `url` type validates through
  `MenuBuilderItem::isPermissiveUrl()`, the same reader the item's own URL fields use. Text is text
  and is never sanitized, exactly as for the badge above — the safety is the escaping at the
  boundary, proven by rendering in `MenuBuilderCustomFieldTest`, not by a denylist.
- **Reads fail closed against the definitions of the day.**
  `CustomFieldHelper::valuesForOutput()` is the only way a stored value reaches a node, and it
  re-checks every one: a field since deleted or retyped, a `select` value whose option is gone, or
  anything written straight into the database is dropped rather than rendered. Deleting a
  definition therefore takes effect immediately without rewriting a single item row. Writes are
  validated too (`MenuBuilderItem::validateCustomFields()`, with a shape check that runs even when
  no definitions are known) — this is defence in depth, not the only line.
- **Values are item data, so they belong in the cached payload.** They are a function of the item
  and the menu, and of nothing about the visitor — unlike visibility and active state. An `asset`
  field stores the **ID**, never the URL, resolved per request by
  `craft.menuBuilder.customAsset(node, 'handle')` and memoized alongside icon assets, for the same
  reason the icon model gives. Changing a menu's definitions moves `dateUpdated`, which is already
  in the cache key, and `customFields` being a new `MenuBuilderNode` property rotates
  `payloadVersion()` by itself.

Both bags are bounded — at most 20 definitions per menu, 255 characters for a `text` value and 2000
for a `textarea` — so an over-long value is a field error rather than a silent database error on a
shared TEXT column.

Templates read values by handle:

```twig
{{ node.custom('subtitle') }}
{% if node.hasCustom('image') %}<img src="{{ craft.menuBuilder.customAsset(node, 'image').url }}">{% endif %}
```

The bundled `_macros/tree.twig` renders none of them: custom fields are for the consumer's own
templates, so nothing about them leaks into the shipped markup by default.

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

A plugin that registers its own link type owns its cache invalidation:
`MenuBuilder::getInstance()->cache->invalidateGroups(['main'])` (handles) or `invalidateGroupIds()`
(menu IDs) — both go through the same targeted, transaction-safe path the built-in callers use.

---

## Single path per behaviour

A deliberate constraint: each behaviour has exactly one affordance, so there's no second code path
to keep in sync and no second place for a hierarchy bug to hide.

- **Move / reparent an item** — the drag handle, by pointer or by keyboard. Row-menu
  `move-up`/`move-down`/`indent`/`outdent` were removed; they reimplemented the same DOM-shuffle +
  `persistReorder()` sequence from a *different* control. The handle's arrow keys
  (`MenuBuilderTree.handleHandleKeydown()` → `moveUp`/`moveDown`/`indent`/`outdent`) are not that
  second command set returning: they are the same control, and they end in the same
  `persistReorder()` → `persistMove()` call a drop does. What made the old commands a second path
  was the duplicated logic behind them, not the fact that a keyboard could reach them — and a
  `role="button"` handle that answers only to a mouse is simply an inaccessible control.
- **Deciding where a subtree may land** — `MenuBuilderTree.levelBounds()`, once.
  `MenuBuilderTreeSorter.getLevelBounds()` delegates to it and so do `indent()`/`outdent()`, so the
  pointer and the keyboard cannot disagree about the same slot. The removed drop-onto-a-row gesture
  computed max-depth admissibility a second, independent way, which is exactly the hazard this
  keeps closed. The server re-validates regardless.
- **Reparent while dragging** — horizontal pointer movement only (`updateIndent()` →
  `getLevelBounds()`).
- **Create an item — of any type** — the quick-add panel, then drag to adjust. Its "Nest under"
  select places a new item directly; `add-child`/`add-sibling` only pre-filled `parentId` on the
  same slideout. Because this is the *only* creation path, quick-add's type list is load-bearing: a
  `MenuBuilderItem::TYPES` entry missing from it is a type nobody can create, however completely the
  model, the editor and the resolver support it. That is exactly what happened to `dynamic`, so
  `QuickAddCreationTest` checks the list against the constant rather than against any one type.
  Quick-add asks only for what the model *requires* of a type — for `dynamic`, a source type and a
  source; limit and order-by are optional to `validateDynamicSource()`, defaulted by
  `MenuBuilderDynamicNavigationService::normalizeConfig()`, and set in the editor afterwards.
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

`composer test` runs 1,011 PHPUnit unit tests (`tests/Unit`) with no booted Craft app.
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
| Active-state marking: hierarchy (item / parent / grandparent / sibling), every URI shape (homepage, trailing slash, query, fragment), every link type, external hosts, non-navigable schemes, unavailable links, `currentUri` override, `aria-current` placement, cache boundary | `MenuBuilderActiveResolverTest` |
| Cache-key and config/payload-version construction, per-menu tags, element-sync targeting (class → source type, dynamic-source container matching, cache-duration ceiling) | `MenuBuilderCacheTest` |
| Cache behaviour against a real backend: hit, miss, targeted invalidation, per-site isolation, stale/foreign entries, transaction-deferred invalidation, and the two-user / two-day / two-page sharing of one entry | `MenuBuilderCacheIntegrationTest` |
| Controller permission mappings and the shared permission gate | `ControllerPermissionTest` |
| CP affordance flags, the permission each control is gated on, no nested `<form>` on full-page screens, filtered-tree row counts | `CpAffordanceTest` |
| Quick-add offering every `MenuBuilderItem::TYPES` entry, the dynamic source fields it posts, one shared picker toggle, the native-form fallback | `QuickAddCreationTest` |
| Preview: option normalization (audience, user-group and site allowlists, the "seen from" URI), the audience → `VisibilityContext` mapping, the no-writes and site-restore guarantees, and that the simulated audience is applied after the cache | `MenuBuilderPreviewTest` |
| Preview rendering, against a real DOM: nesting, separators, headings, unavailable links, every link shape, dynamic children, `aria-current` on the active node only, icons (class, asset, deleted asset, unsafe class), badges and styles, mega-menu disclosure/columns, attribute escaping, the markup panel's formatter, and the stage's chrome | `MenuBuilderPreviewRenderTest` |
| Attribute parsing, title fallback, rel merging, JSON bags, ID lists, calendar dates | `MenuBuilderHelpersTest` |
| Link health: the element-status → health mapping (live, no URL, disabled, pending/expired, archived, unknown), agreement with the resolver's availability rule, custom-URL / anchor / structural / dynamic-source checks, the front-end consequence per `fallbackBehavior`, the summary, which statuses offer recovery actions, and the no-disclosure guarantee | `MenuBuilderLinkHealthTest` |

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
   writes all query real element/DB state and are verified manually. The cache service is the one
   exception: it exposes its Craft touchpoints (cache component, current site, `cacheDuration`,
   transaction state) as overridable seams, so `MenuBuilderCacheIntegrationTest` drives the real
   key/tag/queue implementation against a real Yii cache backend. What remains manual there is only
   whether the *events* fire — the queue's Yii transaction hookup and the element listeners. For items that means the
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
6. **Preview has no unsaved-changes mode, and cannot have one as things stand.** Every CP mutation
   writes immediately, so there is no draft to preview; a "preview my pending edits" mode would
   need a draft/session mechanism this plugin doesn't have, and inventing one only for preview
   would put a second, unvalidated copy of a menu's shape next to the real one. The screen
   therefore states that it shows saved data rather than implying otherwise. Preview also does not
   simulate **time** — a "what will this look like next Monday" mode would need a simulated `now`
   on the `VisibilityContext`, which is a deliberate change to what `dateRange` means, not a
   preview option to add casually.
7. **`MenuBuilderGroup::$settings` is still open-ended.** `siteIds` is the only documented key in
   it. Whoever adds the next per-menu frontend setting should decide whether it deserves a validated
   sub-model rather than another loose key.
