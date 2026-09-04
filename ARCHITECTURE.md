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
| Controllers | `BaseMenuBuilderController` + `GroupsController`, `ItemsController`, `DashboardController`, `PreviewController`; `ApiController` stands apart | The **only** classes that touch `craft\web\Request` or the session. The CP permission check itself lives once, in the base class's `beforeAction()`; subclasses only declare *which* permission an action needs. `ApiController` deliberately does **not** extend that base: it is a front-end endpoint with no CP request, no session and no MenuBuilder permissions — its gate is a GraphQL schema, not a user (see "REST API"). |
| Services | `MenuBuilderGroupService`, `MenuBuilderItemService`, `MenuBuilderResolver`, `MenuBuilderScopeService`, `MenuBuilderLinkResolver`, `MenuBuilderVisibilityService`, `MenuBuilderCacheService`, `MenuBuilderActiveResolver`, `MenuBuilderElementService`, `MenuBuilderDynamicNavigationService`, `MenuBuilderPreviewService`, `MenuBuilderLinkHealthService`, `MenuBuilderBreadcrumbService`, `MenuBuilderLicenseService`, `MenuBuilderMenuLimitService` | The only classes that query Records. Own all business logic, hierarchy integrity, and transactions. |
| Models | `MenuBuilderGroup`, `MenuBuilderItem`, `MenuBuilderNode`, `MenuBuilderTree`, `MenuBuilderBreadcrumbTrail`, `ResolvedLink`, `MenuBuilderMegaMenuConfig`, `MenuBuilderApiConfig` | Validation lives here (`defineRules()`), never in Records or controllers. |
| Records | `MenuBuilderGroupRecord`, `MenuBuilderItemRecord` | Thin `ActiveRecord`: `tableName()` and nothing else. No business logic, and deliberately **no AR relations** — every join this plugin needs is an explicit service query, so there is no lazy-load path that could silently become an N+1. Never visible to controllers or Twig. |
| Helpers | `LinkAttributeHelper`, `ConfigHelper`, `DateValidationHelper`, `MenuBuilderGqlHelper`, `MenuBuilderApiHelper` | Pure static functions, no Craft app required. Where logic that two layers both need lives, so there is one implementation rather than a copy per caller. |

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
- **MenuBuilderPreviewOptions** — the validated description of one CP preview (device, placement,
  audience, user groups and site). Request-scoped, never persisted — see "Preview".
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
   assembled in PHP, never N+1), preload every element the tree links to (see "Link resolution"),
   resolve each item's link, synthesise dynamic children, and convert to `MenuBuilderNode[]`.
4. Re-filter those nodes against **current** visibility rules — never cached, since it depends on
   the current user, date, and environment.
5. Mark `isActive`/`isActiveAncestor` against the current request URI (`MenuBuilderActiveResolver`)
   — also per-request, never cached. See "Active state" below for the matching rules.

`craft.menuBuilder.breadcrumbs()` adds an optional **step 6** on top of the finished tree:
`MenuBuilderBreadcrumbService` walks it for the node step 5 marked active and returns the path
taken to reach it. It is a separate call rather than part of `getTree()` because most pages render
a menu without a breadcrumb, and it reads the tree without re-resolving, re-filtering or
re-marking anything. See "Breadcrumbs" below.

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

Step 4 re-reads the persisted rows because visibility rules live on the item, not on the cached
`MenuBuilderNode`. It reads **only** `id` and `visibility`
(`MenuBuilderItemService::getVisibilityRulesForGroup()`), built once in `getTree()` and passed
down, never re-queried per node. Two columns rather than a hydrated `MenuBuilderItem` per row
because this is the one query every cache *hit* still pays, and the pass reads nothing else from
the row: measured on a 1000-item menu, hydrating models was 10.4 ms of a 13.1 ms cached request
against 0.55 ms for the projection.

The map's keys carry meaning as well as its values: an item with no rules is a **present key
holding an empty bag**, and a *missing* key means the row is gone or has been disabled since the
tree was cached — which is what makes `filterVisible()` fail closed on a stale entry that outlived
its item. The `enabled` predicate is therefore deliberately identical to `getFlatForGroup()`'s.

`MenuBuilderVisibilityService::passes()` is the rule evaluation over that raw bag;
`isVisible(MenuBuilderItem)` delegates to it. One decision, two entry points — not two
implementations to keep in step.

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

### Element preloading

`ElementLinkResolver` re-queries the element on every resolve, which — one item at a time — is a
textbook N+1: a 100-item entry menu cost 100 element queries to build (measured at 102 queries /
17.3 ms of SQL, against 3 queries / 1.7 ms once batched).

Before `convert()` runs, `MenuBuilderResolver::buildResolvedNodes()` walks the item tree, groups
its `elementId`s **by item type**, and hands each group to the matching resolver via
`MenuBuilderLinkResolver::preload()`. Grouped by type rather than pooled because each type resolves
against its own element class — an entry ID handed to the category resolver simply isn't there.

Four things keep it honest:

- **Opt-in.** Preloading is `PreloadingLinkTypeResolverInterface`, a *separate* interface extending
  `LinkTypeResolverInterface`. Most link types resolve from the item's own columns and have nothing
  to preload, and a third-party resolver registered through `EVENT_REGISTER_LINK_TYPES` keeps
  working untouched — `preload()` skips anything that doesn't implement it.
- **Identical query.** The batch query is the per-item query with `id($ids)` instead of `id($id)`:
  same site scoping, same `status(null)`. A preloaded element is the element `resolve()` would have
  fetched for itself, which is what makes this invisible to every other layer.
- **Absence is recorded.** Every requested ID is seeded as `null` before the query runs, so an ID
  the query doesn't return (deleted, trashed, not enabled for this site) stays recorded and reaches
  the fallback path without a second look — otherwise the N+1 would survive in precisely its worst
  case.
- **Site-keyed, and released.** The map is `[siteId][elementId]`, because the same element
  legitimately resolves to a different URL, title and availability per site and a request that
  resolves for more than one (the preview, a console command) must not be served another site's
  answer. It is dropped in a `finally` once the tree is built: the resolvers are memoized for the
  whole request, the elements are wanted only for the build.

This is a cache-*miss* optimisation. A warm menu never touches the elements table at all — resolved
URLs are in the cached payload.

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
element" accessor, and is a thin delegation to this service rather than a second element
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
imports/direct API writes, and **rejects** an empty restriction list rather than accepting it as
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

## Breadcrumbs

`MenuBuilderBreadcrumbService::trailForTree()` turns a resolved tree into a
`MenuBuilderBreadcrumbTrail`: the root-to-current chain of the node active state marked, root
first, current page last. `getTrail()` is the same thing from a handle, resolving the menu first.

**The trail is the menu's hierarchy, not a parsed URL.** The tempting cheaper implementation —
split the request path on `/`, make a crumb per segment, title-case the slugs — is deliberately
absent, in any form, including as a fallback for when nothing matches:

- a path segment is not a page (`/products/2024/shoes` yields a "2024" crumb linking to a 404),
- URL structure and navigation structure are routinely different (a shoe at `/products/shoes`
  hanging under "Footwear"),
- and inventing titles from slugs makes a wrong answer indistinguishable from a right one.

So when the menu can't answer, the trail is **empty** and the caller renders nothing. Empty is a
result, not an error: an unpublished/disabled/deleted item is not a page anyone is on, and a page
no item points at is exactly the case a URL-splitting implementation gets wrong. `null` is kept
distinct from empty and means *no such menu* — missing, disabled, or not on this site, the same
three outcomes as `getTree()`, so `get()` and `breadcrumbs()` can never disagree about whether a
menu exists.

Three decisions worth knowing:

| Decision | Why |
|---|---|
| **Crumbs are `MenuBuilderNode`s** — there is no breadcrumb item type | Title, URL, clickability, active state and depth are already facts of the resolved node. A parallel representation would be a second copy to keep true and a second thing to invalidate, and would have to re-state the icon/badge/custom-field contract. Anything a node exposes, a crumb exposes. |
| **The chain comes from the DFS path**, not from `MenuBuilderNode::$parent` | The walk descends the tree carrying the path it took, so the ancestors are this tree's own nesting. Walking `parent` upward would trust a pointer a caller could leave unwired and would have to be cycle-guarded. |
| **First active node in document order wins** | The same URL can legitimately be in one menu twice ("Contact" in the header and in a utility strip). Document order is the order the CP shows and the menu renders, so the choice is stable across requests and explainable without reading the code. |

Nothing here is cached: the trail is a function of active state, which is per-request by
definition, and the walk is over a tree that is already cached where it can be. A tree resolved
with `markActive: false` (the CP preview, which doesn't simulate a page) has no current page and
therefore yields an empty trail — the honest answer rather than an invented one.

`_macros/breadcrumbs.twig` is the optional renderer: a named `<nav>` landmark around an `<ol>`,
`aria-current="page"` on the last crumb only, non-clickable crumbs as text, the "opens in a new
tab" hint imported from `_macros/tree.twig` rather than restated, and no separator characters in
the markup — a literal `›` is text a screen reader reads on every crumb, so it belongs to CSS.

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
and depth decision is exercised for real in `MenuBuilderTreeTest` without a booted Craft app.
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
the first place. Reassigning a group would silently break the tree — a moved item's children keep
their own `groupId`, so they would become orphaned roots in the old group and vanish from the new
one, with no error anywhere. New items pick their group from the posted value.

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
automatically for any node whose `megaMenu` is set **and that has children left after visibility
filtering** — a parent with nothing to show renders no disclosure and no empty `role="group"`, in
either mode and whether the macro is reached through `render()` or called directly. Since grouping
runs on the per-request, visibility-filtered children (`withChildren()` clones carry the config
across), a column whose only member is hidden from this visitor simply isn't rendered for them,
while the shared cached node keeps it for everyone else. Hand-rolled Twig can ignore the macros and read
`node.megaMenu`/`megaMenuColumns()` directly. Why it is a native disclosure rather than a button
with `aria-expanded`, and why that is a correctness decision rather than a styling one, is in
"Accessibility" below.

---

## Mobile navigation

**No second menu, and the decision is load-bearing.** A `mobileGroupId` with its own items would
duplicate the tree, the cache entry, the invalidation, the visibility rules, the link health and the
syncing — and would give one site two hierarchies, so `MenuBuilderActiveResolver` and
`MenuBuilderBreadcrumbService` would produce two different answers to "where am I", only one of
which matches the navigation on screen. What actually varies by viewport is how much of the menu is
shown, in what order, and how deep a level is disclosed: presentation facts about items that already
exist.

They live in `metadata['mobile']`, the same extension point as `megaMenu`, `badgeStyle` and
`dynamicSource`:

```
metadata['mobile'] = {
    visibility:  'both' | 'desktopOnly' | 'mobileOnly',   // absent = both
    order:       0-9999,                                   // absent = no override
    collapsible: bool,                                     // absent = derived from hasChildren()
    megaMenu:    'stack' | 'columns' | 'hide',             // absent = stack
}
```

`helpers/MobileHelper.php` owns the grammar, in the "one reader, fail closed" shape of `IconHelper`
and `BadgeHelper`. Two invariants:

- **Every read fails closed toward keeping the link.** An unrecognised visibility reads as `both`;
  an unrecognised mega behaviour reads as `stack` and *never* as `hide`; an unknown viewport passed
  to `isVisibleOn()` keeps the item. The opposite failure — links silently vanishing from the only
  navigation a phone has — is the one nobody notices until a customer can't find the shop.
- **A default is never stored.** `MobileHelper::config()` and `fromForm()` omit every key at its
  default, so an item nobody has configured carries no `mobile` key at all and "empty means
  unconfigured" stays true in the column, in the cache and in the form. Changing a default later is
  then a code change, not a migration.

`MenuBuilderItem::validateMobile()` rejects values of the wrong *kind* (an unknown visibility, a
non-numeric order, a non-boolean `collapsible`) rather than defaulting them silently — the
fail-closed reads remain the backstop for rows this validator never saw. An out-of-range order is
the one exception: it clamps, because a sequence hint isn't worth refusing to save an item over.

### Where a viewport is decided — and where it is not

Nothing in this plugin sniffs a user agent, guesses a width or stores a breakpoint. The `mobile` bag
is a property of the **item**, decided by an editor, and of nothing about the visitor or the device,
which is why `MenuBuilderResolver` puts it in the cached `MenuBuilderNode` alongside the mega-menu
config: **one cache entry serves both viewports**. A viewport is chosen, downstream of the cache
boundary, in exactly two places — the template calling `MenuBuilderTree::forViewport()`, or the
stylesheet reacting to `data-mb-viewport`.

`MenuBuilderTree::forViewport($viewport)` is a pure re-shaping of an already-resolved tree: it
filters restricted items at every depth and, for `mobile`, stably sorts each sibling list by
`mobileOrder()` (numbered items at their number; the rest keep their editor-dragged order and follow
after). No query, no cache read, no link resolution, no visibility evaluation — rendering both
navigations costs one resolve. Nodes are copied through `MenuBuilderNode::withChildren()` for the
same reason the resolver copies (these can be the cached objects), with `preserveActiveState: true`
— the tree it re-shapes has already been active-marked, and losing that would drop
`aria-current="page"` from the mobile navigation.

**Ordering is a re-sort, never a CSS `order`.** A CSS `order` changes the visual sequence and leaves
the DOM, the Tab order and the screen-reader reading order alone: WCAG 1.3.2 and 2.4.3, broken. The
macros emit no `order` property, and mobile order is therefore only meaningful in a separately
rendered mobile navigation.

### Rendering

`_macros/tree.twig`'s three macros take an optional `viewport`, threaded like `disclosure`:

| `viewport` | Behaviour |
|---|---|
| `'both'` (default) | One navigation for every screen. Restricted items still render, carrying `data-mb-viewport="desktop\|mobile"` on their `<li>`. The whole CSS contract. |
| `'desktop'` | For a tree already narrowed by `forViewport('desktop')`: no per-item attributes, `data-mb-viewport` on the `<nav>` instead. |
| `'mobile'` | As above, plus: collapsible branches render inside a native `<details data-mb-submenu>`, and mega parents follow `mobileMegaMenuBehavior()` (stack into one list / keep columns / render no panel). |

`disclosure: 'none'` still wins on mobile — it means "claim no state", and that promise holds in
every viewport.

A collapsed branch is the same native `<details>`/`<summary>` the mega menu uses, for the same
reason: `open` is simultaneously the visibility and the accessible state, so there is no
`aria-expanded` to drift. It is marked `data-mb-submenu`, **not** `data-mb-disclosure`, so
`NavAsset` leaves it alone — "open one, close the siblings" and "close on focusout" are right for a
desktop flyout and wrong for a drawer, where several sections stay open and focus moving must not
collapse the one being read. No JavaScript is involved in the mobile navigation at all.

Known gap: the control-panel preview's device toggle is still a **width** only (see "Preview") and
does not apply mobile item metadata.

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
uses — before comparing source type and container ID. So saving an asset never flushes a menu
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
`commit()` (`MenuBuilderCacheTest` pins the ordering), but the bulk paths can't be fixed by
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
same reason (though it is not itself unit-covered — see "Known limitations"): it turns one user's
admin flag and granted permissions into the
five `canView`/`canCreate`/`canEdit`/`canDelete`/`canManageSettings` booleans every CP template asks
before rendering a control. Every screen-rendering action spreads `currentUserAffordances()` into its
template variables. This is a UX guarantee, not a security one — `beforeAction()` enforces access
either way — but a Delete entry that answers 403 reads as a broken plugin rather than a restricted
role, and the two lists drifting apart is exactly what produced Delete under `edit` and "New menu"
under `create`. Note that `groups/edit` and `items/edit` require only `view`: both screens are
deep-linkable and must render **read-only** (a `disabled` fieldset, no Save, no destructive form
action) rather than offer a save the action would refuse.

### What the gate actually enforces

`ControllerPermissionTest` pins the *decisions* — it is pure and boots
nothing. Neither runs `beforeAction()`, which is the code that actually stands between a
hand-written POST and the database, so neither would notice a permission Craft never registered
(making `can()` answer false for everyone), a CSRF check switched off somewhere in the inheritance
chain, or an action added without a mapping.

`ControllerAuthorizationTest` (integration suite) runs the gate itself: seven real users — one per
permission, one with none, and one admin — in real user groups holding real permission rows,
against a real CP `craft\web\Request`, for **every action of every controller**. For each action,
exactly one of the five single-permission users gets through and the other four are refused; the
same matrix is re-run as an AJAX/JSON request, as a logged-out request, and as a front-end request.
Nothing in it consults the UI, which is the point: hiding a button is not a security boundary, and
every case is the request that arrives when someone ignores the hidden button and posts anyway.

Three preconditions the permission audit pins with tests:

- **Craft's `accessCp` comes first.** `craft\web\Controller::_enforceAllowAnonymous()` requires a
  login and the `accessCp` permission on every CP request, before this plugin's gate runs. A user
  granted `menuBuilder:edit` alone therefore still reaches nothing — MenuBuilder's permissions are
  an additional check, never a way into the control panel.
- **Admin bypass is deliberate and is proven by an admin holding no permissions at all.** If
  `$currentUser->admin` stopped short-circuiting the check, that fixture would fail every row.
- **The two request-shaped decisions read the request exactly once.** `items/save` needs `create`
  or `edit` depending on the posted `itemId`, and `items/bulk` needs `delete` or `edit` depending on
  the posted `op` — so both are places where a request could be *checked* as one thing and
  *executed* as another. The gate and the action read each value from the same place
  (`getBodyParam()`), and the tests post the mismatching shapes to prove it: an `op` supplied only
  in the query string is admitted as a non-delete and stays one, and neither omitting nor supplying
  an `itemId` lets an editor create or a creator edit.

Other guarantees:

- Every `beforeAction()` requires a CP request and checks `admin` or the specific permission before
  any client-supplied ID is used. `requireCpRequest()`, the `null`-identity refusal, the absence of
  any `enableCsrfValidation` or `allowAnonymous` override, and `requirePostRequest()` on every
  writing action are all asserted structurally in the unit suite as well, so a regression surfaces
  in the fast suite rather than only in the one that needs a database.
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

**One read per request.** `getAll()` memoizes the menu list, and `getById()`, `getByHandle()` and
`getByUid()` all answer from that memo rather than issuing their own query. The write path is why:
saving one item asks for its menu twice — once for its custom field definitions, once for its
`maxDepth` — so a 100-item bulk edit was costing 200 menu queries. The memo is safe because every
mutator in the service (`save()`, `duplicate()`, `deleteById()`, `reorder()`) clears `$allCache`,
so it can never outlive the state it describes; `MenuBuilderPerformanceTest` pins both halves of
that. Callers therefore share model instances within a request — the pre-existing behaviour of
`getByHandle()`/`getByUid()` — uniform across all three.

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

## Editions and the menu limit

MenuBuilder ships two editions — `free` and `pro` (`MenuBuilder::editions()`, Free first because
Craft installs the first edition when none is named and `Plugin::is()` compares editions by index).
They are one implementation. There is no Pro build, no Pro subclass, no feature flag beyond the one
below, and no license check anywhere in the resolve pipeline.

**The whole product rule:**

| | Menus | Everything else |
|---|---|---|
| Free | 1 | all of it |
| Pro | unlimited | all of it |

### Two services, one question each

| Class | Answers | Notes |
|---|---|---|
| `MenuBuilderLicenseService` | "Which edition is running?" | Compares editions with Craft's documented `Plugin::is($edition, '>=')`, guarded by a declared-edition check because `is()` throws on an edition it doesn't know and the value comes from project config. Reads `Plugin::$edition`, which Craft sets from project config (`plugins.menu-builder.edition`) and changes via `Plugins::switchEdition()`. Adds no second mechanism, stores nothing, and never reads the license *key*. Also derives the upgrade URL — Craft's in-CP `plugin-store/buy/<handle>/pro` for an admin who may change things, the public plugin listing otherwise, both the way `craft\helpers\App::licenseInfo()` derives them |
| `MenuBuilderMenuLimitService` | "May this install have another menu?" | Owns `FREE_MAX_MENUS = 1` — the only place the number appears — plus the count, the refusal wording and the CP summary |

**An install that predates editions.** Craft stores `edition: standard` for a plugin that declares
none. If such an install upgrades into a version that does declare editions, Craft normalizes the
unrecognized value to the *first* declared edition at boot (`Plugins::createPlugin()`), so it comes
up on Free without a migration and without a fatal — and `Plugins::switchEdition()` overwrites the
stale value the first time the edition is set. `MenuBuilderLicenseService::isKnownEdition()` is the
same guard applied a second time, for the window in which project config holds something Craft
hasn't normalized.

`isPro()` gates on the **edition only**, never on the license key status. An unpaid, expired or
mismatched key is Craft's business: it produces a loud CP warning and a Plugin Store prompt, and
does not silently downgrade a running site. A plugin that took that into its own hands would turn a
lapsed invoice — or a failed license *check*, which is what `unknown` means on a site with no
outbound network — into an editor who suddenly can't manage their navigation. The key status is
shown on the menus index as information, and is not a gate.

### One enforcement point

```
GroupsController::actionSave()/actionEdit()/actionDuplicate()   ← says why (UX)
        │
        ▼
MenuBuilderGroupService::save()  (new menus only) / duplicate()  ← refuses (the boundary)
        │
        ▼
menubuilder_groups
```

The service is where it holds, because the service is the only way a row reaches
`menubuilder_groups` (see "Group persistence — database only"): a hand-written POST, a console
command and third-party code all arrive there. `save()` checks it *after* validation and only when
`$group->id === null`, so editing, renaming, toggling, reordering and deleting an existing menu are
never limited — including on an install that is over the limit. The controller asks the same
question a moment earlier for one reason only: so the interface can state the limit and offer the
upgrade instead of rendering a button whose request would be refused. Hiding the button is not the
boundary, and `MenuBuilderMenuLimitTest` posts straight at both actions to prove it.

### Non-destructive by construction

Nothing about editions reads, writes, hides or deletes menu data:

- No column, flag or migration records which edition created a menu. A menu doesn't know.
- `canCreate()` is `$count < $max`, so an install holding five menus on Free is refused a sixth and
  asked for nothing. Drop to Free and all five stay, stay editable, and keep resolving; restore Pro
  and creation resumes. `MenuBuilderMenuLimitTest` runs exactly that sequence, and
  `MenuBuilderEditionSwitchTest` runs it again through Craft's own `Plugins::switchEdition()` while
  *fingerprinting every menu row* before and after — a row count alone would pass a downgrade that
  silently rewrote a column.
- Switching the edition writes exactly one project-config value (`plugins.menu-builder.edition`) and
  touches nothing else, in either direction; a `project-config/apply` therefore carries no menu data
  and can neither create nor delete a menu, however the editions differ between environments.
- The resolve pipeline (`MenuBuilderResolver`, the cache, the Twig API, GraphQL, the REST API, the
  Navigation field) contains no edition check at all. Front-end rendering cannot be affected by a
  license state because it never asks about one.

### Multi-site

The limit counts menus per **install**. A menu is a single global row in `menubuilder_groups` that
may optionally be restricted to a set of sites (`MenuBuilderGroup::$siteIds`, stored in the
`settings` bag); it is not a per-site entity and has no per-site copies. "One menu per site" would
therefore not describe anything in the data model — there is one menu list, whatever the site count.

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

Two details this code depends on:

- Rows must be looked up by `data-id`, **never** from the clicked anchor — Craft relocates an open
  disclosure `.menu` to near `<body>`, so the anchor is not inside its row.
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
posts through the page form with a real confirmation. Neither is scanned for automatically any more; both are on the manual list.

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
| The current site, for the duration of one resolve (`withSite()`, restored in a `finally`) | Everything else: link resolution, the cache, hierarchy, mega menus and dynamic sources. Active-state matching is disabled because the presentation preview has no current page |
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
- An unrecognised **placement** becomes `both`, so the preview always presents the menu somewhere
  useful rather than rendering an empty or misleading region.

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

The preview surface is `_macros/tree.twig`'s own output. `preview/index.twig` captures a canonical
copy for the escaped "Rendered markup" panel and two presentation instances, with fixed header and
footer ID prefixes, for the stage. The prefixes keep IDs unique when the default `both` placement
renders one saved menu twice; the macro's normal front-end output remains unchanged when no prefix
is supplied. A CP-only renderer would be a second thing to keep true, and the day it drifted the
preview would be confidently wrong about the one thing it exists to answer. Nothing on the screen
is printed with `|raw`.

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

Around that markup sits `preview/_stage.twig`: a complete illustrative company page with a brand,
site header, representative content and a full footer. It is **chrome only** — it takes captured
markup as strings and never touches a node.
It does not read `children`, ask whether something `isActive`, group a mega-menu column or resolve
an icon; `MenuBuilderPreviewTest` asserts that against the file, because the moment the stage starts
reasoning about menu data it becomes the second renderer this design exists to avoid. It is a
separate partial rather than inline markup so it can be rendered on its own, against real
`MenuBuilderNode` objects, in `MenuBuilderPreviewRenderTest`.

**Placement** — both, header or footer — is a preview *control*, not a stored setting. Nothing in
MenuBuilder records where a template renders a menu, deliberately: `craft.menuBuilder.get('footer')`
can be called in a masthead, so a stored placement would be a second, unenforceable truth. The
screen defaults to showing both common treatments and can isolate either one. A footer navigation
is rendered as a polished column grid, because that is both familiar to visitors and the fastest
way to read a whole menu's structure at once.

The preview does not simulate a current URI. `MenuBuilderResolver::getTree()` keeps active-state
marking enabled by default for every front-end caller, while the preview passes `markActive: false`.
This prevents the control-panel request URL—or an invented page—from placing `aria-current` on a
generic presentation preview.

Everything that makes it *look* like a navigation is CSS keyed to attributes and classes the macro
already emits — `li.is-active`, `a[aria-current="page"]`, `.menu-builder-megamenu-trigger`,
`.menu-builder-megamenu-panel`, `.menu-builder-megamenu-column`, `.menu-builder-icon`,
`.menu-builder-badge`, `li[role="separator"]`. **No preview-only class is added to a single
navigation element.** Desktop lays the top level out horizontally and opens level two as a dropdown
card on `:hover` *and* `:focus-within` (so the keyboard demonstrates the same state a pointer does),
with level three and deeper shown in place as an indented group. Mobile is a clean 390px viewport:
the header navigation starts closed behind its hamburger and opens as an overlaid panel, while the
footer becomes stacked disclosure rows instead of compressed desktop columns. The toggle and
backdrop are chrome, since a real site owns those controls. The stage uses a fixed light palette rather than Craft's CP variables: it stands in
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
closed. Revealing a panel on `:hover`/`:focus-within` while `aria-expanded` was maintained
separately would be exactly the divergence the front-end macro is built to make impossible, so the
preview demonstrates the same guarantee it documents.

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
`MenuBuilderPreviewRenderTest`). [README.md](README.md#accessibility) is the consumer-facing summary
of this section; the manual release checklist is at the end of it.

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
  screen reader announces. `web/assets/nav/NavAsset` is an enhancement (Escape, arrows,
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

### Manual release checklist

Automated tests cover the markup; these are the checks only a person can make. Run them against a
front-end template using the bundled macros, on a menu holding a mega-menu parent, a plain submenu,
a separator, a heading, an icon, a badge and an external `_blank` link.

**Disclosure state — first, with DevTools open on the `<details>`:**

- [ ] Closed: panel not visible, no `open` attribute, its links unreachable by `Tab`.
- [ ] Open: panel visible, `<details open>` present — by pointer, `Enter`, `Space` and `ArrowDown`.
- [ ] `Escape` closes it, removes `open`, and returns focus to the summary.
- [ ] Hover alone never reveals a panel unless something sets `open`.
- [ ] Clicking outside and tabbing out both close it; opening one panel closes the other.
- [ ] Everything except the extra keys still holds with `NavAsset` **not** registered.

**Keyboard, no mouse:**

- [ ] `Tab` reaches every link once, in document order, with a visible focus indicator; `Shift+Tab`
      walks back out and focus is never trapped.
- [ ] `Enter` and `Space` both toggle a summary; `ArrowUp`/`ArrowDown`/`Home`/`End` walk an open
      panel; `ArrowUp` on a closed summary does nothing, on purpose.
- [ ] Nothing is reachable only by pointer.

**Mobile, at 390px:**

- [ ] Mobile-only items are present and desktop-only ones are absent — from `Tab` and the screen
      reader's link list, not merely invisible. Then the reverse at desktop width.
- [ ] With two navigations rendered, only one is on screen and only its links are tabbable; the
      landmark list shows no two navigations with the same name.
- [ ] Exactly one link has `aria-current="page"`.
- [ ] A collapsed branch's `<summary>` is a Tab stop and gains `open`; a closed branch's links are
      out of the tab order; opening one branch does not close another.
- [ ] The mobile order you set is the order you `Tab` through.
- [ ] A mega parent set to "Hide the panel" still leads somewhere those links can be reached from.

**Screen reader (VoiceOver + Safari, NVDA + Firefox):**

- [ ] Each menu's landmark is named; lists announce the right counts; only the current page's link
      is announced as current.
- [ ] An external link's name ends with "opens in a new tab".
- [ ] A mega-menu summary announces collapsed/expanded and its links are absent while closed.
- [ ] A heading reads as text, icons add nothing to a name, a badge reads as part of one, and a
      separator is announced as a separator or skipped.
- [ ] A breadcrumb trail is a separate named landmark, read as an ordered list, with no separator
      characters between crumbs.

**Zoom, motion, appearance, content:**

- [ ] At 200% zoom and at 320px wide, everything is reachable and nothing is clipped.
- [ ] `prefers-reduced-motion: reduce` needs no animation to see a panel open.
- [ ] Forced-colors mode keeps the active item and focus distinguishable; focus, link, badge and
      active-item colours meet contrast.
- [ ] Every item has a meaningful title, or an ARIA label where the visible label is an icon alone —
      and no two items share a label pointing at different destinations.

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

`MenuBuilderVariable` is six methods and nothing else: `get()`, `breadcrumbs()`, `getGroup()`,
`getItem()`, `iconAsset()` and `customAsset()`. There is no HTML-rendering entry point — the macros
are templates, not API — so the variable's whole contract is "hand back resolved data".

`breadcrumbs()` → `MenuBuilderBreadcrumbService` → `MenuBuilderBreadcrumbTrail`, iterable and
countable over crumbs that are themselves `MenuBuilderNode`s (see "Breadcrumbs"). It takes either a
handle or an already-resolved `MenuBuilderTree`, so a page that renders the menu *and* a breadcrumb
resolves the menu once — the only overload in the variable, and it exists to stop templates paying
twice for the same tree.

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

{# customAsset() is null for an empty field, a non-asset field, *and* an asset that has since been
   deleted — so test the resolved asset, not just hasCustom(). #}
{% set image = craft.menuBuilder.customAsset(node, 'image') %}
{% if image %}<img src="{{ image.url }}" alt="">{% endif %}
```

The bundled `_macros/tree.twig` renders none of them: custom fields are for the consumer's own
templates, so nothing about them leaks into the shipped markup by default.

The site (front-end) template root is registered explicitly in `attachEventHandlers()` —
`craft\base\Plugin` auto-registers only the CP root, and `menu-builder/_macros/tree` is meant to be
importable from front-end templates.

---

## The Navigation field

`MenuBuilderField` (`src/fields/`) lets a content author attach one menu to an element and read it
back as `entry.navigation`. It is the only place in the plugin where a menu is referenced from
*outside* MenuBuilder's own tables, which is what makes its three decisions load-bearing.

### What is stored: a UID

One menu **UID**, in a `varchar` content column.

- Not the **handle** — renaming a menu's handle would silently repoint every entry that selected it,
  with no error and no audit trail.
- Not the **row ID** — an auto-increment ID is assigned by whichever database created the row, so
  the same menu can legitimately have different IDs in two databases, and a stored ID could point at
  an unrelated menu in the other one.

A UID is a **stable reference, not a portable menu.** Menus are database-only and are *not*
project-config entities (see "Group persistence — database only"), so nothing about the field makes
a menu travel between environments: a stored UID resolves only in a database that already contains
that menu row. In practice that means deploying the database, exactly as it does for the menus
themselves. What the UID buys is that the reference stays correct across a handle rename and cannot
be confused with a different menu — not that the target is guaranteed to be there.

`MenuBuilderFieldHelper::normalizeUid()` is the gate: anything that isn't UID-shaped collapses to
"nothing selected" before it can reach a lookup. That is deliberately strict — a raw handle is
precisely the shape this field is specified *not* to accept, so it must fail at normalization rather
than resolve.

### What Twig gets: a value object, resolved lazily

`MenuBuilderFieldValue` — never a record, never a bare string. It carries the selection and resolves
the menu on demand, through `MenuBuilderResolver::getTree()`: the same single path
`craft.menuBuilder.get()` uses, so a field-rendered menu is cached, visibility-filtered and
active-state-marked identically. Resolution is lazy (an element index normalizing a hundred values
must not resolve a hundred menus) and memoized per instance (a page rendering a menu and then its
breadcrumbs pays for one resolve).

The field normalizes an empty column to `null`, so `{% if entry.navigation %}` answers the question
templates actually ask. A value object therefore always means a real selection, which can still fail
to render for three distinguishable reasons:

| | `exists()` | `.tree` | meaning |
|---|---|---|---|
| menu deleted | `false` | `null` | the selection outlived the menu |
| menu disabled, or not on this site | `true` | `null` | selection fine, menu not rendering here |
| menu resolves | `true` | tree | normal |

Iterating yields nothing in all three, so a template that only loops needs none of this.

### Validation

`MenuBuilderFieldHelper::validationError()` owns the whole rule, pure and unit-tested, and the field
only translates its result into an error message. It runs on `SCENARIO_LIVE` only — as
`BaseRelationField` does — so a draft holding a now-broken selection still saves; the author is told
at publish time, not blocked from typing.

Three things are errors: a selection whose menu was **deleted**, a selection **outside the field's
allow-list**, and — only on a translatable field — a menu **restricted away from the element's
site**.

Three things deliberately are *not*:

- **Nothing selected.** Emptiness is the field layout's "required" flag to decide, not this field's.
- **A disabled menu.** `enabled` is a publishing state an editor flips independently of any entry.
  Treating it as a content error would make every entry pointing at a menu unsavable the moment
  somebody turned that menu off. It resolves to no tree — which is what disabling it means.
- **A site mismatch on an untranslatable field.** One value then covers every site, so a
  site-restricted menu could never satisfy it; the error would be unfixable, which is worse than no
  error.

### Sites

Craft's standard translation methods, unchanged. Untranslatable (the default) means one selection for
every site, which is what a single shared navigation wants; per-site means each site gets its own
selection — and only then does the site-mismatch error above become reportable, because only then can
the author act on it.

`isAvailableForSite()` asks about the **element's** site. `getTree()` resolves for the site of the
**current request** — the site whose links, titles and cache entry a page is being built from. The
two coincide except when a template renders an element fetched from another site; see "Known
limitations".

### Settings, and project config

`allowedGroupUids` (empty = every menu, the same convention `MenuBuilderGroup::$siteIds` uses) and
`includeDisabledMenus`. Craft writes both into project config as part of the field.

Both are **safe to apply**: a list of UIDs and a boolean — no row IDs, no site IDs — so
`project-config/apply` can never fail on them, and a hand-edited YAML can't leave a handle or an ID
in the allow-list (it is re-normalized in the constructor as well as on save).

Safe to apply is not the same as self-sufficient, and this is the one place the distinction matters:

> **MenuBuilder menus are not project-config entities.** The UIDs in `allowedGroupUids` — and the
> UID an entry stores as its value — are references into `menubuilder_groups`, a table project config
> knows nothing about. Applying this field's config to another environment does **not** create the
> menus it names. They must already exist in that environment's **database**, which means deploying
> the database.

A UID naming a menu the target database doesn't have degrades quietly and safely: the picker offers
one fewer option, and an entry whose stored UID isn't there reads as a selection that doesn't
resolve (`exists()` false) rather than as a wrong menu. It cannot widen the field and cannot break
an apply.

The picker always keeps the **currently stored** menu, even when it has since been disabled or
dropped from the allow-list. Otherwise opening an unrelated entry and saving it would rewrite the
selection to whatever the select box fell back to. Whether that stored value is still *valid* is the
separate question answered above.

### Permissions

Selecting a menu is content authoring and needs no MenuBuilder permission — the field is content, not
menu management. Reaching the menu *editor* does, so the input's "Edit this navigation" link is
rendered only for a user who could follow it (`menuBuilder:view`, or admin). Same rule the CP
templates follow everywhere else: never render an affordance a permission check would then reject.

### GraphQL

`MenuBuilderMenuType` exposes the **selection** — `uid`, `handle`, `name`, `exists`, `enabled` — and
not the resolved tree. A tree is per-site, per-visitor and per-page; a GraphQL response is cached and
shared, so returning one would be exactly the "visitor-specific state in a shared cache" mistake the
resolve pipeline is arranged to avoid. Querying a menu itself is its own schema-scoped surface, and
belongs to the GraphQL phase rather than to this field.

The type is registered through `GqlEntityRegistry`, so two navigation fields in one schema share one
type definition rather than colliding on the name. Its field definitions are a separate pure method
(`fieldDefinitions()`) so what each resolver returns is testable without a booted app.

Querying the menu *itself* is the separate, schema-scoped surface described under
[GraphQL](#graphql) below.

---

## GraphQL

Two distinct surfaces, deliberately not merged:

| Type | What it is | Where |
|---|---|---|
| `MenuBuilderMenu` | The Navigation **field's value** — a selection: `uid`, `handle`, `name`, `exists`, `enabled` | `MenuBuilderMenuType`, reached from `MenuBuilderField::getContentGqlType()` |
| `MenuBuilderNavigation` | A **resolved menu** — the tree, with its items | `MenuBuilderNavigationType`, reached from the root queries |

A field's value can't be a tree, for the reason stated under the field above: a tree is per-site,
per-visitor and per-page, and an entry query has no way to say which. The root queries exist so those
three things can be *stated* instead of inherited.

```
menuBuilder(handle:, site:, siteId:, currentUri:, viewport:)  →  MenuBuilderNavigation
menuBuilderNavigations(site:, siteId:, currentUri:, viewport:) →  [MenuBuilderNavigation!]!
```

### Classes

| Class | Responsibility |
|---|---|
| `gql/MenuBuilderNavigationQuery` | The two root fields, their arguments, and the per-menu schema components |
| `gql/MenuBuilderNavigationResolver` | webonyx's resolver signatures, and nothing else — it delegates every decision to `MenuBuilderScopeService` |
| `gql/MenuBuilderNavigationType` | Projection of `MenuBuilderTree` |
| `gql/MenuBuilderNavigationItemType` | Projection of `MenuBuilderNode`, recursive on `children` |
| `helpers/MenuBuilderGqlHelper` | The decidable half: argument normalization, scope component names, the audience, value shaping |

Registration is two events on `craft\services\Gql`, both in `MenuBuilder::attachEventHandlers()`:
`EVENT_REGISTER_GQL_SCHEMA_COMPONENTS` (one `menuBuilderGroups.{uid}:read` per menu) and
`EVENT_REGISTER_GQL_QUERIES`. No mutations — see "No write surface" below.

Types go through `GqlEntityRegistry::getOrCreate()`, as the field's type does, so the install's type
prefix is applied once and two references to a type share one definition rather than colliding on
the name. `MenuBuilderNavigationItemType` is self-referential (`children`), so its field list is
built lazily — webonyx has to be able to hand back the type object before the list exists, or the
recursion never terminates.

The split between `getType()` (needs a booted app, for the prefix) and `fieldDefinitions()` (pure) is
the same one `MenuBuilderMenuType` makes, and for the same reason: what each resolver returns for a
given node is unit-testable.

### The five gates

Everything security-relevant happens in `MenuBuilderScopeService`, which the REST API shares; the
types are projection only, and `MenuBuilderNavigationResolver` is an adapter for webonyx's calling
convention.

1. **Schema scope.** The active schema must name the menu by UID (`menuBuilderGroups.{uid}:read`).
   Scoped by UID rather than handle or ID because a schema is long-lived, hand-edited configuration
   and a UID is the only identifier that survives a rename — the same reasoning the field's stored
   value follows.
2. **Existence and enabled state**, and 3. **group site availability** — both enforced by
   `MenuBuilderResolver::getTree()`, which is not bypassed. There is no second path to a tree.
4. **Site scope.** An explicitly requested site must be in `GqlHelper::getAllowedSites()`, the same
   boundary Craft's own `site` argument enforces. With no site argument the request's current site is
   used, which Craft's `GraphqlController` has already checked against the schema.
5. **Visibility**, evaluated against the anonymous audience — below.

Every failure returns the **same `null`**: unknown handle, malformed handle, disabled menu,
out-of-scope menu, unavailable-on-this-site menu. Distinguishing them would be an enumeration oracle
for the install's structure. `menuBuilderNavigations` omits rather than null-pads, so its length says
nothing either. A schema naming no menu gets no fields at all — `getQueries()` returns `[]` — so the
surface is absent from introspection too, which is what makes GraphQL genuinely optional here.

### The audience is a constant, and has to be

`MenuBuilderGqlHelper::anonymousContext()` builds a `VisibilityContext` with `isLoggedIn: false` and
no user groups. This is a correctness requirement, not a convenience.

Craft's `Gql::executeQuery()` caches a result under *(current site, schema UID, query hash, serialized
variables, config version)* — and nothing about the caller. A tree resolved for whoever sent the
request would therefore be served to every later caller sharing that key: an admin's query would fill
the entry with the items only logged-in users may see, and the next anonymous request would read them
out of it. That is exactly the "user-specific state in a shared cache" failure the resolve pipeline is
arranged around (see "Caching").

Making the audience a constant is what makes the response a pure function of its arguments, which is
what makes it cacheable at all. Consequences, documented rather than hidden: `loggedIn` and
`userGroup` rules never pass over GraphQL, `loggedOut` always does, and `dateRange` / `environment` /
`site` are unaffected — none of them are about who is asking.

Active state follows the same logic. A GraphQL request is served from the API endpoint, not from the
page whose navigation is being built, so `currentUri` is an **argument**: marking against the request
would be both meaningless and outside Craft's cache key. Without it, `markActive: false` and both
flags stay false everywhere.

### Site switching

`resolveTree()` switches the current site for the duration of the resolve and restores it in a
`finally`. Passing a site ID into the visibility context alone would not be enough: two things
downstream read the *current* site and cannot be reached from the call — `MenuBuilderCacheService`
keys entries by it, and `ElementLinkResolver` resolves element URLs against it. Filtering for one
site while resolving URLs for another is the bug this avoids.

Craft computes its result-cache key before execution, so the temporary switch doesn't corrupt it; the
`site` / `siteId` arguments are already part of the key by virtue of being arguments.

### What is not exposed

- **Row IDs.** `menubuilder_items` primary keys are internal, and on a dynamic item's synthesized
  children the node's `id` holds a Craft *element* ID instead (`buildDynamicNode()`) — one field
  meaning two things. `handle` is the public identity.
- **Editor-side configuration**: visibility rules, fallback behaviour, sort columns, the raw
  `metadata` bag, a menu's `siteIds` and `settings`. A visitor can't see them in HTML, and a GraphQL
  consumer is a visitor. A menu unavailable on the requested site is simply absent, so the surface
  can't be used to learn which *other* sites a menu exists on.
- **Resolved asset URLs.** `iconAssetId` / `imageId` / an asset custom field's `intValue` are IDs:
  the node is what gets cached, and an asset's URL can change without the menu changing — the same
  reasoning `MenuBuilderNode::iconType()` documents.

Every exposed value reads through the node's own accessors (`safeHtmlAttributes()`, `iconClass()`,
`badgeClass()`), so their fail-closed behaviour applies to a GraphQL response exactly as it applies to
rendered markup. A rule tightened in a later release applies to trees cached before it.

### No write surface

Mutations are deliberately absent. Menu editing is control-panel work gated by MenuBuilder's own
permissions and CSRF protection (`BaseMenuBuilderController`); a GraphQL token is not a user and has
no MenuBuilder permissions to check, so a write surface would have nothing to enforce beyond the
token itself. The schema components are read-only for the same reason.

### Schema-build safety

`MenuBuilderNavigationQuery::allMenus()` swallows throwables. A GraphQL schema is built in contexts
where the plugin's tables may not be readable — mid-install, mid-migration, pending migrations — and a
schema that fails to build takes down every query on the site. An unreadable menu list means "no menus
to offer", not a 500.

---

## REST API

A read-only JSON transport over the *same* surface GraphQL exposes, for consumers that can't run
Twig: headless Craft behind Next.js or Nuxt, native mobile applications, external front ends.

```
GET {basePath}/v1/navigations            → menu-builder/api/index
GET {basePath}/v1/navigations/{handle}   → menu-builder/api/view
```

### Why it exists at all, given GraphQL

A navigation is a fixed document every page needs. GraphQL's advantage — asking for exactly the
fields you want — buys a consumer that will render the whole tree almost nothing, and it costs a
`POST` with a JSON body, which no HTTP cache, CDN or mobile URL loader will store. A `GET` of a
fixed URL is cacheable by every layer in between. That is the entire justification; nothing else
about the data differs.

### It is not a second API

`ApiController` decides nothing about access. Every gate — scope, existence, enabled state, site
availability, site scope, the anonymous audience, the resolve itself — is `MenuBuilderScopeService`,
which `MenuBuilderNavigationResolver` also goes through. The two transports differ in **how a
refusal is spelled** (GraphQL: `null`; REST: a status code) and in nothing else. When comparing
their behaviour, read the service.

That service is an extraction, not a new implementation: the logic was `MenuBuilderNavigationResolver`'s
private methods, moved so there is one copy of it rather than two ("Single path per behaviour"). The
GraphQL resolver keeps `canRead()`/`readableMenus()` as delegating statics because
`MenuBuilderNavigationQuery` asks those questions while *building a schema*, which is a GraphQL
concern.

### Classes

| Class | Responsibility |
|---|---|
| `services/MenuBuilderScopeService` | The five gates and the resolve, shared with GraphQL |
| `controllers/ApiController` | HTTP: methods, authentication, CORS, rate limiting, status codes, headers |
| `helpers/MenuBuilderApiHelper` | The decidable half: parameter validation, JSON shapes, the error envelope, ETag/cache-control/rate-limit arithmetic |
| `models/MenuBuilderApiConfig` | `config/menu-builder.php`, normalized by one pure static that never throws |

### Two switches, both off

| Switch | What it decides | Default |
|---|---|---|
| `api.enabled` in `config/menu-builder.php` | Whether the API **exists**. When off, `MenuBuilder::attachEventHandlers()` registers no URL rule at all, and `ApiController::beforeAction()` answers 404 regardless | Off |
| The GraphQL schema component `menuBuilderGroups.{uid}:read` | Which menus it can ever serve | Unticked |

The master switch is not redundant with the scope. Reusing the GraphQL scope is what keeps "menus
this caller may read" a single list — but an install that ticked a menu into the *public* schema
chose to expose it over GraphQL at Craft's `/api` endpoint. It did not thereby ask for a second
unauthenticated URL to appear on its site the day it upgraded. The scope decides *which* menus; the
config decides *whether there is an endpoint*.

`MenuBuilderApiConfig::fromArray()` is pure, never throws, and replaces anything malformed with the
default for that key rather than with "permissive" — a config file is hand-edited and deployed, so a
typo must fail closed. `enabled` takes a literal `true` and nothing else: publishing an endpoint is
not something a truthy string should be able to do. `basePath` is validated against a URI-path
grammar (no empty segments, no dot segments, no regex metacharacters) because it is concatenated
into a Yii URL rule.

### The audience is nobody, and has to be

Same constant as GraphQL's, for a second reason on top of the shared-cache one: a `GET` that answered
differently for a browser carrying a session cookie is a `GET` a third-party page can use to read a
logged-in user's data cross-origin. `ApiController` never reads the session, and Craft's admin-only
`X-Craft-Gql-Schema` header — which turns an admin's cookie into schema selection — is deliberately
not honoured.

### Authentication

`Authorization: Bearer {token}`, resolved and validated exactly as `craft\controllers\GraphqlController`
does (enabled, unexpired, has a schema), with the same once-a-minute `lastUsed` bookkeeping so the
CP's token list is an audit trail for REST callers too. `lastUsed` failures are logged, never fatal:
a read-only endpoint that 500s because it couldn't record a timestamp is worse than a minute-stale
audit trail.

With no header, the request falls back to Craft's public schema unless `allowPublicSchema` is off. A
bad token and a missing one are the same 401.

`enforceSiteAccess()` mirrors `GraphqlController::_enforceSiteAccess()`: the request's *own* site
must be one the schema may query, or a token scoped to one site would get another site's navigation
simply by being sent to that site's URL. The `site`/`siteId` parameters are a separate check, in
`MenuBuilderScopeService::requestedSite()`.

### Status codes

| Status | Code | Meaning |
|---|---|---|
| 400 | `bad_request` | A recognized query parameter was given but isn't valid. Names it |
| 401 | `unauthorized` | No usable token and no public schema fallback |
| 403 | `forbidden` | The schema doesn't cover the site whose URL was called |
| 404 | `not_found` | Unknown handle, malformed handle, disabled menu, out-of-scope menu, or menu unavailable on the requested site — indistinguishably. Also a disabled API |
| 405 | `method_not_allowed` | Anything but `GET`, `HEAD`, `OPTIONS` |
| 429 | `rate_limited` | Over the window's budget |

The five-way 404 is the same non-oracle property GraphQL's uniform `null` has. A **path segment**
that isn't a handle is 404 rather than 400: it identifies a resource, and one that can't name a
resource is not found. Only a malformed **query parameter** is a 400 — and unlike GraphQL, which
folds "absent" and "malformed" into one, REST distinguishes them, because a query string is untyped
where a GraphQL argument is typed by the schema before a resolver sees it. The normalizers are
shared (`MenuBuilderGqlHelper`); only the presence check is added, so the transports can't drift
about what *counts* as a handle, a site ID, a URI or a viewport.

Every refusal is the JSON envelope, never Craft's HTML error template. That is also why the method
gate runs **before** `parent::beforeAction()`: `yii\web\Controller::beforeAction()` validates a CSRF
token on unsafe methods, so a `POST` reaching it would be answered with Craft's HTML 400 before this
controller spoke.

### Order of the gates

`beforeAction()`, in order: CORS headers → `api.enabled` → `OPTIONS` preflight → method →
`parent::beforeAction()` (site online, anonymous access) → authentication → site access → rate limit
→ parameter validation. CORS first so a browser can *read* a refusal; the preflight before
authentication because a preflight carries no credentials by definition, and before the method gate
because `OPTIONS` is not `GET`.

The action then resolves the **answering site** once (`answeringSite()`, through the same
`requestedSite()` the resolve uses) and reports it as `meta.site`. A `site` parameter naming
something the caller may not have is a 404 on *both* endpoints — the list endpoint does not return an
empty 200 labelled with the current site, which would make `meta.site` a lie.

### What the response is

`{meta, data}`. `meta` carries `apiVersion`, the site that actually answered, and the honoured
`currentUri`/`viewport` — the last two because active state is silently all-false without a
`currentUri`, and a consumer shouldn't have to parse its own URL to notice.

Item fields mirror `MenuBuilderNavigationItemType` one for one, deliberately: two transports over one
menu must not disagree about what a menu *is*. The same things are absent for the same reasons — row
IDs, editor-only configuration, the menu's site-restriction list, resolved asset URLs — and every
value reads through the node's own fail-closed accessors.

Where JSON has a better representation it is used rather than transcribed: `htmlAttributes` and
`customFields` are **objects** (GraphQL has no map type, so it needs `{name, value}` pairs and four
typed accessors); related fields are grouped into `icon`, `badge`, `megaMenu` and `mobile`. An empty
bag is serialized through `stdClass` so it encodes as `{}` — a bag whose JSON *type* changed with its
contents is what breaks a typed consumer.

### Caching

No response cache is added. The expensive half is already the tree cache, keyed by menu, site and
config version; a second cache of derived data would be a second invalidation problem for no gain.
What the API adds is transport caching:

- `ETag` over the exact bytes sent (which is why the body is encoded in the controller rather than by
  `asJson()` — an ETag over a re-encoding can disagree with the body), and `304` on a matching
  `If-None-Match`.
- `Vary: Authorization, Origin` on every response.
- `Cache-Control`: `no-store` until `cacheDuration` is set, then `public, max-age=N` for a
  public-schema response and **`private`** for a token-authenticated one. A token's schema can name
  menus the public schema cannot, so its response must never be storable by a shared cache;
  `Vary: Authorization` says the same thing, but only to caches that honour it.

### CORS

Exact-match allowlist only — no suffix or subdomain matching, the classic way an allowlist admits
`evil-example.com` for `example.com`. `['*']` is honoured and echoed as `*` (not as the caller's
origin, which would let one origin's cached response answer for all of them);
`Access-Control-Allow-Credentials` is never sent and no cookie is ever read, so a wildcard cannot be
used to read a browsing user's data. An empty allowlist sends no CORS headers at all, which is
correct for a server-side consumer.

### Rate limiting

A fixed one-minute window in Craft's cache, keyed by `hash(tokenUid|ip)` so neither an access token
nor an address is stored in the clear, and TTL'd to the window's own remaining life so a key can't
leak one caller's budget into the next window. The token is part of the key so one noisy anonymous
network can't spend an authenticated integration's budget. `rateLimit => 0` turns it off.

### No write surface

Same answer as GraphQL's, and for the same reason: a GraphQL token is not a user and holds no
MenuBuilder permissions, so a write endpoint authenticated this way would have nothing to enforce
beyond the token itself. The first question a write API has to answer is what identity a change is
attributed to — for the audit trail, the permission check and `Edited by` in the CP. Adding one is
not a matter of adding a verb, and it would not belong on this controller.

### Versioning

`/v1/` in the URL is the major version; `meta.apiVersion` and `X-MenuBuilder-Api-Version` carry the
precise one (`MenuBuilderApiConfig::RELEASE`). Additive changes move the minor half and stay on
`/v1/`; a removed field, a changed type, a reshaped envelope or a moved gate takes a new major and a
new URL.

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

## Performance

The rule the whole pipeline is built to: **a menu's query count must not grow with its item
count**, at any stage. Everything below was measured against a real Craft install (MySQL 8, PHP
8.4) at 10 / 100 / 500 / 1000 items, flat and nested ten deep.

Where the time actually goes, per request, on a **cache hit** (1000 items):

| Stage | Cost |
|---|---|
| Read the cached payload (unserialize `MenuBuilderNode[]`) | ~1.6 ms |
| Re-read visibility (`id` + `visibility`, one query) | ~0.5 ms |
| Evaluate visibility rules | ~0.2 ms |
| Mark active state | ~0.3 ms |
| **Total `getTree()`** | **~3.5 ms, 1 query** |

Unserializing the cached tree is the dominant cost, which is the right shape: it is the work of
handing over the answer, not of computing it. Twig rendering of a 1000-item menu adds ~16 ms and no
queries.

On a **cache miss** the tree is built once: one flat item query, one query per element type for the
links (see "Element preloading"), plus one query per distinct dynamic-navigation source. 1000 flat
items build in ~16 ms / 2 queries; 500 items nested ten deep in ~8 ms / 2 queries; a 100-item entry
menu in ~8 ms / 3 queries.

Fixed costs, deliberately not per-item:

- **Sites** — Craft memoizes the current site; nothing here re-queries it.
- **Menus** — one memoized list per request (see "Group persistence — database only").
- **Custom field definitions** — read once per tree from the menu, passed down, never per item.
- **Link-type and visibility-rule registries** — built once per request behind their events.

What is *not* optimised, on purpose:

- Visibility is evaluated per request, never cached. It depends on the current user, date and
  environment, and a shared cache entry that knew about any of them would be a correctness bug, not
  a speedup. It is also cheap: 0.2 ms for 1000 items.
- The cached payload holds resolved URLs but never resolved *visibility* or *active state*. See
  "Caching".
- `MenuBuilderNode::withChildren()` copies rather than mutating during filtering. That allocation is
  what keeps the cached tree immutable by construction, and it does not show up as a cost worth
  reclaiming.

`MenuBuilderPerformanceTest` exists in both suites and guards the shape rather than the clock:
wall-clock assertions would flap between machines, so the integration suite counts queries and
asserts they are *identical* at 5 items and at 40–60, and the unit suite pins the structural
decisions those counts depend on (which read the pipeline uses, that preloading happens before
conversion and is released after, that the menu memo is dropped by every mutator).

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
  model, the editor and the resolver support it. That is exactly what happened to `dynamic`, so the
  list must be checked against the constant rather than against any one type — a manual check today,
  since the template-shape test that did it automatically was removed.
  Quick-add asks only for what the model *requires* of a type — for `dynamic`, a source type and a
  source; limit and order-by are optional to `validateDynamicSource()`, defaulted by
  `MenuBuilderDynamicNavigationService::normalizeConfig()`, and set in the editor afterwards.
  `ItemsController::actionEdit()` is edit-only: its new-item branch and the
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

Two suites, with different bootstraps and different jobs.

**`composer test`** — 1,160 unit tests in 24 files (`tests/Unit`), no booted Craft app. Fast, and covers the
pure logic every layer is factored into.

**`composer test-integration`** — 464 integration tests in 15 files (`tests/Integration`) against a **real booted
Craft 5 application and a real database**. `tests/integration-bootstrap.php` stands up a throwaway
install: its own database (`MENUBUILDER_TEST_DB_DATABASE`, default `menubuilder_test`), its own
`config`/`storage` under `tests/_craft`, and the plugin's own `vendor/` — which registers MenuBuilder
as a real Craft plugin — so the surrounding project is never involved and its `config/project/` is
never read. It drops and recreates every table on each run, and *refuses to start* against a database
whose name doesn't contain `test`. Connection defaults suit DDEV from inside the web container
(`ddev exec composer test-integration`); override with `MENUBUILDER_TEST_DB_*`.

The split matters. The unit suite can prove what the Navigation field *decides*; only the integration
suite can prove it is **wired into Craft** — that a value reaches `elements_sites.content`, that
`Entry::find()->navigation($uid)` compiles to a condition that matches it, that Craft can build a
GraphQL schema from the field's type. A wrong `dbType()`, a `serializeValue()` returning the wrong
shape, or a type webonyx rejects would pass every unit test and fail on the first real request.

`composer check-cs` (ECS, `ecs.php`, Craft's own set) and `composer phpstan` (`phpstan.neon`,
level 5, Craft's extension) both run clean over `src` and `tests`; both skip
`tests/_craft/storage`, which holds Craft's own generated code, and PHPStan ignores the
per-field-handle magic query methods (`->navigation()`) it cannot see. Covered:

| Area | Test |
|---|---|
| Link resolvers, element availability/fallback decisions, resolver registry | `LinkTypeResolverTest` |
| Dynamic-source clamping / order-by whitelist | `LinkTypeResolverTest` |
| Visibility rules, rule combination, CP form → rule configs, cache boundary and pipeline order | `MenuBuilderVisibilityTest` |
| Item validation: per-type fields, options, URLs, anchors, attributes, mega-menu / dynamic-source metadata, executing-scheme rejection | `MenuBuilderItemModelTest` |
| **Integration** — item CRUD, hierarchy and ordering against the real database: the rows a move actually writes, a refused move rolling back, a delete taking the subtree, and a contiguous sibling set afterwards | `MenuBuilderItemCrudTest` |
| Drag-and-drop moves, depth and ordering | `MenuBuilderTreeTest` |
| Group model validation, site availability, depth, group lifecycle | `MenuBuilderGroupTest` |
| Cached-node immutability, `flatten()`, mega-menu column grouping | `MenuBuilderTreeTest` |
| Active-state marking: hierarchy (item / parent / grandparent / sibling), every URI shape (homepage, trailing slash, query, fragment), every link type, external hosts, non-navigable schemes, unavailable links, `currentUri` override, `aria-current` placement, cache boundary | `MenuBuilderActiveResolverTest` |
| Cache-key and config/payload-version construction, per-menu tags, element-sync targeting (class → source type, dynamic-source container matching, cache-duration ceiling) | `MenuBuilderCacheTest` |
| Cache behaviour against a real Yii backend (`ArrayCache`, through the service's own seams): hit, miss, targeted invalidation, per-site isolation, stale/foreign entries, transaction-deferred invalidation, and the two-user / two-day / two-page sharing of one entry | `MenuBuilderCacheTest` |
| Controller permission mappings and the shared permission gate | `ControllerPermissionTest` |
| CP affordance flags, no nested `<form>` on full-page screens, filtered-tree row counts, and quick-add's type list | *manual only* — the template-shape tests that covered these were removed; `ControllerAuthorizationTest` (integration) proves the gate itself with real requests |
| Preview: option normalization (placement, audience, user-group and site allowlists), the audience → `VisibilityContext` mapping, the no-writes and site-restore guarantees, disabled active state, and that the simulated audience is applied after the cache | `MenuBuilderPreviewTest` |
| Preview rendering, against a real DOM: nesting, separators, headings, unavailable links, every link shape, dynamic children, icons (class, asset, deleted asset, unsafe class), badges and styles, mega-menu disclosure/columns, unique IDs in the dual-placement view, attribute escaping, the markup panel's formatter, and the illustrative stage | `MenuBuilderPreviewRenderTest` |
| Navigation field: UID-only value normalization, allow-list normalization and project-config portability, the picker (allow-list, disabled menus, keeping the current selection), every validation outcome, the translatable/untranslatable site rule, the manage-link permission, the Twig value's laziness, memoization and three failure modes, and the GraphQL selection-not-tree shape | `MenuBuilderFieldTest` |
| **Integration** — Navigation field storage and query API against real Craft: the UID landing in `elements_sites.content` under the field layout element's key, the value round-tripping as the value object, an empty selection reading as null, a real tree resolving, changing a selection, and `->navigation()` / `->ids()` matching by UID, by a different UID, by an unused UID, by `:empty:` / `not :empty:` / `['or', …]`, and by a deleted or disabled menu's UID — plus that the condition is built by Craft's own content-column pipeline | `MenuBuilderFieldQueryTest` |
| **Integration** — Navigation field through Craft's real GraphQL layer: querying the selection, an empty selection as null, a deleted menu reporting `exists: false` rather than vanishing, a disabled menu reporting `enabled: false`, filtering entries by the selected UID, the mutation argument taking a UID and round-tripping, a non-UID mutation value being rejected, and — against the **built** schema — that no resolved tree is exposed and asking for one is a rejected query | `MenuBuilderFieldGqlTest` |
| **Integration** — Navigation field on a real two-site install: shared vs per-site (translatable) selections and their independent querying, availability reported for the element's own site, a site-restricted menu resolving to no tree on a site it isn't available on, site-mismatch failing validation on a translatable field and not on a shared one, a deleted menu failing on every site, and the cross-site rendering limitation pinned as a regression test | `MenuBuilderFieldMultiSiteTest` |
| GraphQL surface: argument normalization (handle, site ID, viewport, `currentUri` bound), scope component naming, the anonymous audience, custom-field value shaping, and what every field of both types returns — including the absence of row IDs and editor-side config | `MenuBuilderGqlTest` |
| **Integration** — the navigation query against Craft's real GraphQL layer and a real scope: a valid query, nested children, disabled items and menus, an unknown / malformed / missing handle, an out-of-scope menu being indistinguishable from a missing one, a schema with no menus having no fields at all, site selection (explicit, disallowed, unknown, disagreeing arguments, a site-restricted menu), viewport reshaping, active state with and without `currentUri`, that a logged-in caller gets the same tree as an anonymous one, and that row IDs and mutations are not queryable | `MenuBuilderNavigationGqlTest` |
| REST API surface: the master switch (only a literal `true` turns it on), base-path and origin-allowlist normalization, exact origin matching and the wildcard, query-parameter validation and the allowlisted argument set, the JSON shapes (grouped presentation, native custom-field types, `{}` for an empty bag, no row IDs, no install structure), the envelope and error envelope, ETag / `If-None-Match` / `Cache-Control` (`private` for a token), and the rate limiter's key and window arithmetic | `MenuBuilderApiTest` (unit) |
| **Integration** — the REST API through the real controller, with real `craft\web\Request`s, real GraphQL tokens and real menus: a valid request and its headers, hierarchy and URLs, the list endpoint returning only in-scope menus, the five-way indistinguishable 404 (including path traversal and injection attempts) as JSON rather than Craft's HTML, an invalid / expired / missing token, `lastUsed` bookkeeping, a token reaching past its schema's sites, site selection and disagreement, the anonymous audience, malformed parameters naming themselves, active state with and without `currentUri`, ETag stability and `304`, `private` vs `public` caching, write methods refused before CSRF validation can pre-empt them, `HEAD`, CORS (absent, allowlisted, unlisted, preflight without credentials), the disabled API, and the rate limiter | `MenuBuilderApiTest` (integration) |
| Attribute parsing, title fallback, rel merging, JSON bags, ID lists, calendar dates | `MenuBuilderHelpersTest` |
| Editions: the edition list and its order, an unrecognized edition being recognized as such and falling back to Free, the Free ceiling, the create/refuse arithmetic (including an install already over the limit), and the refusal wording | `MenuBuilderLicensingTest` |
| **Integration** — the limit enforced for real: Free creating its first menu and being refused a second, a refused duplicate, one Free menu taking 50 items nested ten deep, Pro creating twelve menus and duplicating one, a Pro install dropping to Free keeping all five menus, their items and their rendering — then regaining creation when Pro returns — direct POSTs to `groups/save` and `groups/duplicate` creating nothing, a fully configured Free menu (depth cap, CSS class, attributes, site restriction, visibility rules) resolving, and the ceiling counting per install rather than per site | `MenuBuilderMenuLimitTest` |
| **Integration** — the edition switched through Craft's own `Plugins::switchEdition()`: the edition landing in project config and nowhere else, a Pro→Free downgrade leaving every menu row byte-for-byte identical (fingerprinted, not counted) with its items and its rendering intact, Free→Pro restoring creation without touching what was there, a project-config apply changing no menu data, and the plugin's per-request memos still agreeing afterwards | `MenuBuilderEditionSwitchTest` |
| Link health: the element-status → health mapping (live, no URL, disabled, pending/expired, archived, unknown), agreement with the resolver's availability rule, custom-URL / anchor / structural / dynamic-source checks, the front-end consequence per `fallbackBehavior`, the summary, which statuses offer recovery actions, and the no-disclosure guarantee | `MenuBuilderLinkHealthTest` |

The pattern this suite relies on: anything worth asserting is factored into a **pure static or
context-taking method** (`cacheKey()`, `requiredPermissionForAction()`, `isGroupChangeAllowed()`,
`resolveTitle()`, `mergeRelForTarget()`, `validateHtmlAttributes()`, `megaMenuColumns()`,
`isPermissiveUrl()`, `isValidAnchorTarget()`). Keep new logic in that shape.

Writing these tests caught bugs inspection hadn't: the three `metadata`/`visibility` inline
validators were silently skipped on an empty array, because Yii's inline-validator `skipOnEmpty`
defaults to `true` — every rule on `MenuBuilderItem` sets it to `false` explicitly.

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
   transaction state) as overridable seams, so `MenuBuilderCacheTest` drives the real
   key/tag/queue implementation against a real Yii cache backend (`ArrayCache`) from the unit
   suite. What remains manual there is only
   whether the *events* fire — the queue's Yii transaction hookup and the element listeners.
   The Navigation field is the second exception: `tests/Integration` drives it against a real booted
   Craft application and a real database (see "Testing"), so its storage, query API, GraphQL surface
   and multi-site behaviour are covered rather than verified by hand. For items that means the
   hierarchy rules specifically: the `parentId` cascade firing on a parent delete, a real
   duplicated subtree, and a real circular/cross-group/max-depth move rejection are covered by
   `MenuBuilderItemCrudTest` and `MenuBuilderMaxDepthTest` against the real database.
3. **Control-panel template shape is manual only.** `BaseMenuBuilderController::cpAffordances()`
   is pure and was written to be testable, but nothing asserts it any more: the template-shape
   tests (affordance flags per control, no nested `<form>` on full-page screens, destructive
   actions going through `formActions`, quick-add listing every `MenuBuilderItem::TYPES` entry,
   filtered-tree row counts) were removed as source-text assertions and have no replacement.
   `ControllerAuthorizationTest` proves the *gate* with real requests, which is the security
   boundary; what is unverified is whether a control is *offered* to someone the gate would then
   refuse — the UX regression most worth watching for. On the manual list.
4. **Orphaned items are surfaced, not repaired.** The dashboard badges an item whose linked element
   was hard-deleted; nothing reassigns or cleans it up.
5. **Two element changes fire no event at all.** (a) A **time-based entry status change** — a
   pending entry reaching its `postDate`, a live entry passing its `expiryDate`. Bounded by the
   `cacheDuration` ceiling above, not eliminated. (b) **Garbage collection hard-deleting a
   trashed element** after `trashDuration`: `Gc` deletes rows directly. Harmless in practice — the
   cache was already invalidated when the element was soft-deleted, and the element resolves to
   the item's fallback either way — but it is not event-driven.
6. **Commerce products (and any other element type) aren't synced.** MenuBuilder has no Commerce
   dependency and no `product` link type; `MenuBuilderElementService::WATCHED_CLASSES` is
   entry/category/asset. A third-party link type registered through
   `MenuBuilderLinkResolver::EVENT_REGISTER_LINK_TYPES` resolves correctly but gets **no automatic
   cache invalidation** — its own plugin has to call
   `MenuBuilder::getInstance()->cache->invalidateGroups()`. Making the watched-class list itself
   extensible is the natural fix and is not implemented yet.
7. **Preview has no unsaved-changes mode, and cannot have one as things stand.** Every CP mutation
   writes immediately, so there is no draft to preview; a "preview my pending edits" mode would
   need a draft/session mechanism this plugin doesn't have, and inventing one only for preview
   would put a second, unvalidated copy of a menu's shape next to the real one. The screen
   therefore states that it shows saved data rather than implying otherwise. Preview also does not
   simulate **time** — a "what will this look like next Monday" mode would need a simulated `now`
   on the `VisibilityContext`, which is a deliberate change to what `dateRange` means, not a
   preview option to add casually.
8. **A Navigation field resolves for the request's site, not the element's.** `MenuBuilderFieldValue::getTree()`
   goes through `MenuBuilderResolver`, which is scoped to the current site by design — that is the
   site whose element URLs, titles and cache entry a page is being built from. Rendering an element
   fetched from *another* site (`craft.entries.site('de')` inside an English request) therefore
   resolves its navigation against the English site. `isAvailableForSite()` still reports the
   element's own site correctly. Resolving for an arbitrary site would mean plumbing a site ID
   through the resolver, the link resolvers and the cache key, which is a change to the resolve
   pipeline rather than to this field.

   This is **pinned by a regression test** (`MenuBuilderFieldMultiSiteTest::testATreeResolvesForTheRequestsSiteNotTheElementsSite`)
   rather than left implicit, so changing the behaviour has to be a deliberate decision that
   updates the test, not an accident nobody notices.
9. **`MenuBuilderGroup::$settings` is still open-ended.** Two keys live in it —
   `siteIds` (`MenuBuilderGroupService::SITE_IDS_KEY`) and `customFields`
   (`CUSTOM_FIELDS_KEY`), both lifted back out on read so `$settings` stays a plain bag. Whoever
   adds the next per-menu frontend setting should decide whether it deserves a validated sub-model
   rather than another loose key.
