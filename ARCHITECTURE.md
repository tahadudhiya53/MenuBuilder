# MenuBuilder Architecture

This document describes the plugin's internal architecture as it exists today. It is
Phase 0 documentation: architecture and foundation only, no new features.

## Domain model

- **Group** (`MenuBuilderGroup` / `menubuilder_groups`) — a named navigation (e.g. "Main
  Navigation"). Owns group-level settings: handle, `maxDepth`, `cssClass`,
  `htmlAttributes`, `enabled`, `sortOrder`, and an open-ended `settings` bag reserved for
  future frontend-rendering configuration.
- **Item** (`MenuBuilderItem` / `menubuilder_items`) — a single navigation node. Always
  belongs to exactly one group; `parentId` optionally points at another item in the
  *same* group. Owns title, link configuration (`type` + type-specific fields),
  appearance, accessibility fields, fallback behavior, visibility rule configs, and an
  open-ended `metadata` bag reserved for future field-like data (e.g. mega-menu columns).
- **Node** (`MenuBuilderNode`) — the Twig-facing, read-only projection of an Item after
  link resolution. Never persisted; hides the database entirely (no ids to join, no
  `parentId`, no sort columns).
- **Tree** (`MenuBuilderTree`) — `craft.menuBuilder.get('main')`'s return value: an
  iterable/countable wrapper around a group's top-level `MenuBuilderNode[]`.

Items are the only entity with tree structure; Groups are always flat (one row per
navigation).

## Request flow (CP)

```
CP request → Controller → Service → Model ⇄ Record → DB → renderTemplate/redirect
```

- `GroupsController` / `ItemsController` / `DashboardController` are the only classes
  that touch `craft\web\Request` / `Craft::$app->getSession()`. They translate HTTP
  input into `MenuBuilderGroup`/`MenuBuilderItem` models and permission-check via
  `beforeAction()` before any service call.
- `MenuBuilderGroupService` / `MenuBuilderItemService` own CRUD + hierarchy integrity.
  They are the only classes that query `MenuBuilderGroupRecord` / `MenuBuilderItemRecord`
  directly; controllers and Twig never see a Record.
- Records are thin `ActiveRecord` wrappers with no business logic — validation lives on
  the Model (`rules()`), hierarchy/circularity logic lives in the Service.

## Rendering flow (Twig)

```
Twig → MenuBuilderVariable → MenuBuilderResolver → cache / link resolvers / visibility / active-state → MenuBuilderTree
```

`MenuBuilderResolver::getTree()` is the single entry point Twig talks to, via
`craft.menuBuilder.get('handle')`. Its pipeline, in order:

1. Load the raw item tree for the group (`MenuBuilderItemService::getTree()` — one flat
   query per group, assembled into a tree in PHP; never N+1 recursive queries).
2. Resolve each item's link (`MenuBuilderLinkResolver`) and convert to `MenuBuilderNode[]`.
   **This step's result is what gets cached** (see Caching below).
3. Re-filter the cached nodes against **current** visibility rules
   (`MenuBuilderVisibilityService`) — never cached, since it depends on the current
   user/date/environment.
4. Mark `isActive`/`isActiveAncestor` against the current request URI
   (`MenuBuilderActiveResolver`) — also never cached, per-request.

Disabled items and disabled groups are excluded before step 2 ever runs.

## Link resolution

One `LinkTypeResolverInterface` implementation per `MenuBuilderItem::TYPE_*` value,
registered in `MenuBuilderLinkResolver` and keyed by type:

| Type | Resolver | Behavior |
|---|---|---|
| `entry` / `category` / `asset` | `ElementLinkResolver` | Re-queries the element fresh (site-scoped, no stored URL) every resolve; applies the item's `fallbackBehavior` if the element is missing/disabled/unpublished. |
| `url` | `UrlLinkResolver` | Direct passthrough of `customUrl`. |
| `anchor` | `AnchorLinkResolver` | `#` + `handle`, falling back to `customUrl`. |
| `nonclickable` / `separator` | `NonClickableLinkResolver` | No link unless `clickable` is explicitly true and a `customUrl` is set. |

New link types are added by listening for
`MenuBuilderLinkResolver::EVENT_REGISTER_LINK_TYPES` and adding to
`RegisterLinkTypesEvent::$resolvers` — no core class needs to change.

`clickable` is an independent, explicit flag on the Item — never inferred from whether a
URL/element happens to be set. A "Products" heading can be a pure label or a link,
editor's choice.

## Visibility

Each Item carries a `visibility` array of rule configs (`[{"type": "...", ...}]`),
evaluated as an **AND** — an item is visible only if every configured rule passes.
`MenuBuilderVisibilityService` builds a `VisibilityContext` once per render (plain
scalars only — booleans, ints, a `DateTime` — never live `User`/`Site` objects, which
keeps rule evaluation testable without a booted Craft app) and dispatches each rule
config to its `VisibilityRuleInterface` by `type`.

Built-in rules: `always`, `loggedIn`, `loggedOut`, `userGroup`, `site`, `dateRange`,
`environment`. An unrecognized/misconfigured rule type **fails closed** (hides the item)
rather than silently showing gated content. New rules register via
`MenuBuilderVisibilityService::EVENT_REGISTER_VISIBILITY_RULES` — visibility is
deliberately not coupled to the database schema.

## Tree / hierarchy

All hierarchy mutations run through `MenuBuilderItemService`:

- **`getTree()`** — one flat query per group, built into a nested structure in PHP.
- **`move()`** — reparents + resets sort order in a DB transaction; always re-validates
  server-side (circular reference, cross-group parenting, max-depth) regardless of what
  the CP's drag-and-drop already checked client-side.
- **`reorderSiblings()`** — persists an explicit sibling order without touching `parentId`.
- **`duplicate()`** — recursively clones an item and its full subtree in one transaction.
- **`deleteById()`** — relies on the `parentId` self-referencing foreign key's `CASCADE`
  to remove an entire subtree; no ORM-level recursion needed.

`validateHierarchy()` rejects: an item parenting itself, a non-existent parent, a parent
in a different group, any circular ancestor chain, and any move that would push a
subtree past the group's `maxDepth`.

## Persistence

Two tables, `menubuilder_groups` and `menubuilder_items` (see
`src/migrations/Install.php`). Notable constraints: unique index on group `handle`;
composite index on `(groupId, parentId, sortOrder)` for tree reads; `CASCADE` FKs on
`items.groupId` (deleting a group deletes its items) and `items.parentId`
(self-referencing, deletes a subtree). Open-ended data (`htmlAttributes`, `settings`,
`visibility`, `metadata`) is stored as JSON text columns rather than normalized tables —
this is what keeps future rule/field types from requiring a migration.

## Caching

`MenuBuilderCacheService` caches only the output of resolver step 2 above — the
link-resolved node tree, keyed per group handle, tagged for bulk invalidation. It
deliberately does **not** cache visibility filtering or active-state marking, since both
are per-request/per-user.

Invalidation is coarse by design: any group or item save/delete, or a matching
`MenuBuilder::getInstance()->cache->invalidateAll()`/`invalidateGroup()` call, flushes
the relevant cached tree(s). There is no per-item reverse index tracking which entries
reference which items — trees are cheap to rebuild, and coarse invalidation can never
leave stale navigation after an editor change. If entry/category/asset saves need to
invalidate menu caches too (e.g. a linked entry's slug changes), that hook currently
lives outside this plugin's `attachEventHandlers()` — see "Known gaps" below.

## Permissions & security

Five permissions: `menuBuilder:view`, `:create`, `:edit`, `:delete`, `:manageSettings`.
Every controller's `beforeAction()` requires a CP request and checks `admin` or the
specific permission for the action being taken — there is no action that trusts a
client-supplied ID without also checking permission first. `ItemsController::actionEdit`
and `actionSave` re-derive the owning group from the posted `groupId`/`groupHandle`
rather than trusting a client-asserted group; cross-group reparenting is rejected
server-side in `validateHierarchy()` regardless of what the UI sent. All state-changing
actions require POST (`requirePostRequest()`) and Craft's standard CSRF token
(`csrfInput()` in every form; `Craft.sendActionRequest` attaches it automatically for the
JS-driven actions).

## Extension points

- `MenuBuilderLinkResolver::EVENT_REGISTER_LINK_TYPES` — add a link type.
- `MenuBuilderVisibilityService::EVENT_REGISTER_VISIBILITY_RULES` — add a visibility rule.

These are the two points where third-party code is expected to plug in without touching
core classes. No other events exist yet (no before/after save/delete events on Group or
Item) — see "Known gaps."

## Public Twig API

```twig
{% set menu = craft.menuBuilder.get('main') %}
{% for item in menu %}...{% endfor %}
```

`MenuBuilderVariable::get()` → `MenuBuilderResolver::getTree()` → `MenuBuilderTree`,
which is directly iterable/countable over its top-level `MenuBuilderNode[]` and also
exposes `.group` and `.items` explicitly, plus `.flatten()` for a depth-first walk (e.g.
to find the active node without recursing in Twig). An optional recursive renderer macro
lives at `menu-builder/_macros/tree.twig` for anyone who doesn't want to hand-write
markup.

`MenuBuilderNode` is the only object Twig should treat as public/stable — it has no
database ids to join and no internal columns. `MenuBuilderItem`/`MenuBuilderGroup`
(Models) and the Records are CP-internal implementation details, even though the CP
templates and `craft.entries`-style direct queries in `items/_edit.twig` do reach into
them for the edit forms.

## Known gaps / Phase 1 should know

These are observations from the Phase 0 review, not fixes made in this pass (fixing them
would be a product decision or a larger change than "architecture and foundation"):

1. **No before/after save/delete events on Group or Item.** Only the link-type and
   visibility-rule extension points exist today. If Phase 1 needs third parties to react
   to menu changes (e.g. to invalidate an external cache, or to veto a save), those
   events don't exist yet and would need to be added deliberately (naming, cancelable or
   not, what data they carry).
2. **Cache invalidation is not wired to entry/category/asset save events.** Today,
   `MenuBuilderCacheService` is only invalidated by `MenuBuilderGroupService`/`ItemService`
   saves. If an editor changes a linked entry's slug or unpublishes it, the cached
   *resolved* tree can go stale until the next unrelated menu edit clears it (though
   `ElementLinkResolver` itself never uses a stored URL, so once the cache *is*
   rebuilt it's always correct — the risk is only a stale cache window, not a wrong URL
   forever). Wiring `Entry`/`Category`/`Asset` save/delete events to
   `cache->invalidateAll()` would close this, at the cost of more invalidation traffic;
   left as a decision for whoever owns the caching tradeoff going forward.
3. **`MenuBuilderItem::$metadata` and `MenuBuilderGroup::$settings`** are already
   present as open-ended JSON bags specifically so Phase 2+ features (mega-menu column
   data, per-menu frontend config) don't require a migration — but nothing validates or
   documents their shape yet. Whoever designs the first feature that uses them should
   also decide whether they need their own validated sub-model.
4. **`ElementLinkResolver` test coverage requires a booted Craft app/DB** and is
   currently only exercised via manual verification (see `LinkTypeResolverTest`'s
   docblock). The other three resolvers and all visibility rules are covered by
   PHPUnit. If Phase 1 sets up a Craft testing harness (e.g. `craftcms/ecs`/Codeception
   integration tests), extending coverage to `ElementLinkResolver` would be the natural
   next step.
