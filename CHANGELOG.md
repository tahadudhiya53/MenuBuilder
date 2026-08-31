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

### Added — custom fields

- Per-menu, editor-defined custom fields on menu items, for whatever a site needs beyond the
  built-in icon, badge, description, image and "featured" flag. Definitions are configured per menu
  (**Menus → a menu → Custom fields**); every item in that menu is then offered them.
- Seven types, and deliberately no more: `text`, `textarea`, `number`, `boolean`, `select`, `url`
  and `asset`. There is no HTML/markup/template type, so nothing an editor stores can become
  executable content — a `url` field is validated by the same reader as an item's own URL
  (`javascript:`, `data:` and `vbscript:` rejected), and every other value is a scalar escaped by
  Twig where it is printed.
- Read in Twig as `node.custom('handle')`, `node.hasCustom('handle')` and `node.customFields`;
  `craft.menuBuilder.customAsset(node, 'handle')` resolves an `asset` field's ID to the Asset,
  memoized per request alongside icon assets.
- No migration: definitions ride in the menu's existing `settings` bag and values in the item's
  existing `metadata` bag, so they duplicate with an item or a menu, delete with the item row, and
  export as ordinary JSON. There is no second metadata/settings system.
- Values are validated on write (type, dropdown options, required, and size caps of 255/2000
  characters that produce a field error rather than a database error) and re-checked on read: a
  field since deleted or retyped, a dropdown option since removed, or a value written straight into
  the database is dropped rather than rendered. Deleting a definition therefore takes effect
  immediately with no item rows rewritten.
- A menu can define at most 20 custom fields, and handles must be unique within a menu.

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
- Menu item icons are a defined model rather than a free-text field. One stored column, three
  forms: empty, `asset:<id>` for a Craft Asset (an uploaded SVG/PNG), or an icon handle / CSS
  class list (`icon-cart`, `fa fa-cart`). The icon field in the CP is now a source selector plus
  the matching input — a text field or an asset picker — and the icon is exposed to Twig through
  `node.iconType()`, `node.iconClass()`, `node.iconAssetId()` and `node.hasIcon()`, with
  `craft.menuBuilder.iconAsset(node)` resolving an asset icon per request (memoized, so repeated
  icons cost one query). The bundled `_macros/tree` renderer draws icons for links, headings and
  mega-menu triggers, decoratively (`aria-hidden` / `alt=""`).
- Icons cannot carry markup. Class values are allowlisted to letters, digits, spaces and
  `- _ . : /` — no angle brackets, quotes, `=` or `&` — so a stored icon can't inject HTML or
  break out of an attribute even in a template that renders it with `|raw`, and pasted `<svg>`
  is rejected with an error pointing at the asset picker. Reading fails closed the same way: a
  value that wouldn't validate today reads back as "no icon", so a legacy or hand-written
  database row can't reach a template either. SVG icons render through `<img src>`, never
  inlined. Free-typed icons saved previously keep working unchanged.
- Menu item badges — the short flag beside a label, "Products [NEW]". Badge text keeps its own
  column; an optional style (`default`, `info`, `success`, `warning`, `critical`) rides in the
  item's metadata, so there is no new column and no migration. Twig gets `node.badge`,
  `node.badgeStyle`, `node.hasBadge()` and `node.badgeClass()`, and the bundled `_macros/tree`
  renderer draws the badge inside the link, heading and mega-menu trigger — inside, so it is part
  of the item's accessible name ("Products NEW"). The CP tree row previews the badge, and the
  editor gets a badge-style select beside the text field.
- Badge text is escaped, never sanitized: markup typed into the field is rendered as the text it
  is (`<script>alert(1)</script>` shows as those characters), so legitimate badges like `<3` and
  `Tea & Coffee` survive intact. The style is the half that reaches a `class` attribute, so it is
  an allowlist that fails closed on both sides — an unknown style is rejected at validation and,
  if one ever reached the database another way, reads back as "no style" rather than as a class
  list. An empty or whitespace-only badge renders nothing at all, style or no style, and inner
  whitespace is collapsed so one badge has one stored spelling.

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
- Child-count, disabled, and mega-menu badges on tree rows.
- **Link health.** Every item in a menu is classified on each dashboard load — healthy, linked
  content missing, disabled, unpublished, not available on this site, no public URL, invalid
  custom URL / anchor, or a dynamic source that no longer exists — and flagged on its row with the
  reason plus what the front end is currently doing about it (hidden, plain text, or its fallback
  URL), derived from the item's own `fallbackBehavior`. A summary line above the tree counts how
  much of the menu is affected. Items whose linked element is gone get a "Fix this link…" route
  into the editor, which spells out the four safe ways forward — relink, fallback URL, disable,
  delete — and takes none of them by itself: **no menu item is ever removed or rewritten because
  the content it pointed at disappeared.** The classification reuses `ElementLinkResolver`'s own
  availability rule, so the CP can never flag a link the front end would render, or pass one it
  would drop. Warnings name no content: they are built from a status and the item's fallback
  setting, never from the linked element's title, URI or ID, so a warning about content the viewer
  can't see discloses nothing about it. Internal links only — nothing here makes an HTTP request,
  and an external URL is judged on its shape alone.
- **Preview** (`menu-builder/<handle>/preview`, `menuBuilder:view`): render a saved menu as a
  logged-out visitor, a logged-in one, or a member of chosen user groups — on any site you can
  access, at desktop or mobile width, seen from any page. Preview runs the *same* pipeline a
  front-end request runs (same link resolution, same cached tree, same visibility rules, same
  active-state matching) and renders through the shipped `_macros/tree.twig`, with a "Rendered
  markup" panel that shows the output as escaped text so accessibility and link attributes can be
  checked without leaving the control panel.
- The preview renders on a **stage** — a mock browser window with a site header — so a menu is seen
  as navigation rather than as an indented list: a horizontal bar with dropdown cards and mega-menu
  panels on desktop, and a 390px device frame with a stacked, railed, toggleable navigation on
  mobile. Dropdowns open on hover *and* on keyboard focus; mega menus open from the disclosure
  button the macro already emits, so the visible state and `aria-expanded` cannot disagree. Active
  state is visually distinct from an active *ancestor*, and `aria-current="page"` still lands only
  on the one link that is the page. Links are inert: clicking one reports its href, target and rel
  instead of navigating away.
- The "Rendered markup" panel is now readable: the macro's output is re-indented to one element per
  line, numbered, and copyable. Twig emits readable templates rather than readable output, so the
  raw markup arrived with an anchor's attributes spread down a dozen lines. The re-indenting is
  display-only and purely textual — the stage renders the unformatted output, and nothing is added,
  removed or reordered.
- The screen states what it rendered as chips (site, audience, device and width, seen-from, item
  counts) instead of a run-on sentence, and the stage gained a mock page — hero, cards, a phone
  notch and home indicator on mobile — plus open/close animation on dropdowns and mega panels.
- Preview gained a **Shown in** control: header or footer. The menu renders in that region of the
  mock page — a footer menu fully expanded in columns, the way footers are — and the other region is
  drawn as grey placeholder shapes, so which part of the page a menu stands in for is obvious at a
  glance. It defaults to a guess from the menu's own handle and name and says which it picked.
  Placement is presentation only: nothing in MenuBuilder records where a template renders a menu,
  and inventing such a record would be a second, unenforceable truth.
- Fixed a mega-menu panel opening as an empty rail on mobile: the rule that collapses submenus was
  also hiding the panel's own column lists.
- The **whole parent item** opens its children in the preview — hover it on desktop, tap it on
  mobile, or reach it with the keyboard — rather than a small caret that had to be found and hit.
  Open state is one attribute on the item, and a mega menu's `aria-expanded` is kept in step with
  it, so the visible state and the accessible state cannot disagree. A parent that is also a link
  therefore opens its submenu instead of following the link, which the screen says out loud.
- On mobile, submenus now start closed and expand from the row, the way a phone menu does, and the
  phone is a fixed 720px device: closing the menu no longer shrinks it, and a long menu scrolls
  inside the frame.
- Mobile preview fixes: a mega-menu parent's disclosure button now sits at the end of its row
  instead of wrapping onto a line of its own, the current page (and its open branch) is marked with
  an accent bar down the side rather than a full-width underline that read as a divider, separators
  render as a rule rather than picking up item padding, and the phone is a fixed viewport that
  scrolls a long menu inside itself.
- The stage is presentation only. It adds no class or attribute to any navigation element — every
  visual is keyed to what the production macro already emits — and it never loads the site's own CSS
  or JavaScript, which would be an execution surface inside the control panel rather than a preview.
  What it reproduces is structure, hierarchy, state and attributes; a theme's typography and colours
  are explicitly not claimed, on the screen and in the docs.
- Preview **writes nothing** and simulates only the visitor: the audience and the site. Time and
  environment stay real, so a `dateRange` or `environment` rule answers what it answers for a
  visitor at that moment. It shows saved data — MenuBuilder has no draft state, and the screen says
  so rather than implying one — and it cannot expose unpublished content: links resolve through the
  same publicly-available boundary the front end uses. Every option is validated fail-closed
  (unknown audience → logged out, sites limited to the ones you can access, group IDs strictly
  parsed, the "seen from" URI reduced to a site-relative path), and the simulated audience is
  applied *after* the shared cache, exactly where a real visitor's is, so a preview can never leave
  its audience in an entry other visitors read.

### Added — control panel UX

- **Keyboard reordering.** The tree's drag handle is now operable from the keyboard: arrow up/down
  move a row within its level, left/right change how deeply it is nested, and each move announces
  the row's new position through a live region. It had always carried `role="button"` and
  `tabindex="0"` while responding to nothing but a mouse, so a keyboard or screen-reader user could
  not reorder a menu at all. Both gestures ask one shared admissibility check and go through the
  one `persistReorder()` path, so drag and keyboard can't disagree about where a subtree may land.
- **Validation errors are shown against the field that caused them.** Both the slide-out editor and
  the quick-add panel already received per-attribute messages from the save endpoint and discarded
  them, leaving a generic "couldn't save" banner and a six-section form to search. Messages now
  render as Craft's own `.field.has-errors` markup, wired to the input with `aria-describedby`;
  anything validated as a whole bag (`metadata`, `visibility`) is summarized at the top rather than
  dropped, and the first problem is scrolled into view.
- **The quick-add panel no longer loses what you typed.** A failed plain-form post re-rendered the
  dashboard, which knows nothing about the rejected item, so every field came back blank. It now
  submits over AJAX and keeps its state; the plain `<form>` is untouched, so a no-JS post still
  behaves exactly as before.
- **Deleting a parent item is one dialog with three named outcomes** — cancel, delete everything, or
  keep the children — instead of two stacked `confirm()`s where "Cancel" meant "continue" and
  dismissing the second one silently did nothing.
- **Reordering is switched off while a search is filtering the tree**, with the reason stated. The
  rows on screen are a subset in an order that is not the menu's own, so a drag posted a sibling
  list the editor had never seen.
- **Dynamic-navigation items pick their source from a list.** The "Source ID" field asked editors to
  go and look an internal section, category-group or volume ID up under Settings first; it is now a
  picker per source type. The stored config is unchanged.
- Success notices survive the reloads that follow adding, duplicating, saving and deleting an item —
  several of them used to be raised and then wiped by the very reload they were announcing. Newly
  added and duplicated items are scrolled to and highlighted, rather than appended out of sight at
  the end of a long menu.
- Row and menu-list actions guard against double submission: a second click on Duplicate while the
  first request was in flight used to create two copies.
- The bulk-selection toolbar sticks to the top of the viewport and gained a select-all checkbox
  (with an indeterminate state) and a Clear selection button, so a selection made near the bottom of
  a 100-item menu can be acted on without scrolling back.
- A disabled menu now says so on its own tree screen — it renders nothing on the front end whatever
  its items say, which previously looked like a template bug.
- The slide-out editor is a properly labelled dialog: `aria-labelledby` on its heading, a focus trap,
  focus returned to the row it was opened from, an announced loading state, and Ctrl/Cmd-S to save.
- Both edit screens render read-only for a viewer who holds `menuBuilder:view` but not the
  permission to save — the routes only ever needed `view`, so they were reachable while offering a
  Save that answered 403.
- Menus, menu items and their controls are named consistently across every screen and notification.
  Saving a menu *item* used to report "Navigation menu saved", and creating a menu was offered as
  "New group" on one screen and "New menu" on another.

### Fixed — control panel

- **The Delete button on both edit screens ran no confirmation.** It lived in a `<form>` nested
  inside the page form Craft's `_layouts/cp` opens for `fullPageForm`, which is invalid HTML: the
  parser drops the inner start tag, taking its `onsubmit` confirmation with it and leaving the
  button submitting the page form. Both are now Craft `formActions` entries, which post the page
  form to a different action *with* a confirmation and without nesting anything.
- **Saving an item over AJAX raised two notifications, one of them wrong.** The controller queued a
  session flash before returning its JSON response, so the toast the slide-out raised was followed,
  on the next page load, by a second differently-worded notice about the same save. A failed save
  likewise set an error flash that `asModelFailure()` was already setting.
- Controls are no longer offered to users whose permissions would refuse them: Delete and Duplicate
  on a tree row were gated on `menuBuilder:edit` (they need `delete` and `create`), the tree
  sidebar's "New menu" button on `menuBuilder:create` (creating a menu needs `manageSettings`), and
  the bulk toolbar's Delete on `edit`. Server-side authorization was never affected — see
  `CpAffordanceTest`.
- **The tree's hierarchy lines came apart.** Three separate faults: the vertical rail was drawn per
  row at that row's own indent, so it could not span a sibling's subtree and visibly stopped and
  restarted around every nested branch; the "last child" class meant *last in its sibling list* in
  Twig and *the next row is shallower* in the JS that re-synced it after a drag, which disagree for
  a last child that has children, so that row's corner turned into a line running down through its
  own children the moment anything was dragged; and the parent accent was a 3px left **border**,
  which is layout, so parent rows' contents sat 2px right of their own siblings'. The connector is
  now drawn by every row for its *ancestors'* columns as well as its own, which is the only way a
  flat list can paint a continuous rail across an intervening subtree, and
  `MenuBuilderTree.syncRails()` is the single authority that recomputes all of it — on init as well
  as after every move, so the server-rendered first paint can only ever be corrected, never fought.
  The parent accent is an inset shadow that doesn't move anything, the drop slot keeps the columns
  it sits inside running through it, and the floating drag helper no longer trails a connector
  attached to nothing.
- The child-count badge lost its accessible name after any drag, leaving a bare digit; the menus
  list's Disabled status rendered as an empty circle Craft styles as "no status"; and the tree's
  search field had a placeholder but no label.
- **Dynamic navigation items could not be created.** The type was offered by the full item editor
  but not by the quick-add panel — and quick-add is the only creation path, the separate
  `items/new` route having been removed — so a dynamic item could be configured, duplicated and
  rendered, but never made. Quick-add now offers every `MenuBuilderItem::TYPES` entry and asks for
  the source type and source a dynamic item requires, using the same human-readable section /
  category-group / volume pickers and the same posted field names as the full editor. Limit and
  order-by stay in the editor, where the rest of the item is configured: both are optional to
  `validateDynamicSource()` and defaulted by `MenuBuilderDynamicNavigationService`. No new route,
  controller or second creation flow was added, and the dynamic-source rules are unchanged.
- The editor's dynamic **Limit** field showed `10` for an item with no limit stored, which is not
  what such an item renders — `normalizeConfig()` reads an absent limit as the maximum. It now
  shows the stored value or blank, and says what blank means. Its cap comes from
  `MenuBuilderItem::DYNAMIC_SOURCE_MAX_LIMIT` instead of the number being written out again.

### Added — developer API

- `craft.menuBuilder.get(handle, currentUri = null)` returning an iterable, countable
  `MenuBuilderTree` with `.group`, `.items`, and `.flatten()`.
- `craft.menuBuilder.getGroup(handle)` and `craft.menuBuilder.getItem(id)` as thin read-only
  accessors.
- `MenuBuilderNode` as the single stable Twig contract — no database ids, no internal columns;
  dynamic children merged transparently into `children`.
- Optional `menu-builder/_macros/tree` render macros, importable from front-end templates.
- `craft.menuBuilder.breadcrumbs(menu, currentUri = null)` returning an iterable, countable
  `MenuBuilderBreadcrumbTrail` — the root-to-current chain of the item that *is* the page being
  served, with `.crumbs`, `.current()`, `.root()`, `.ancestors()`, `.isEmpty()` and `.group`.
  Crumbs are the same `MenuBuilderNode` objects the menu renders, so they carry title, URL,
  clickability, active state, depth and custom fields with no second contract to learn. Accepts an
  already-resolved `MenuBuilderTree` as well as a handle, so a page that renders both the menu and
  a breadcrumb resolves the menu once.
- Breadcrumbs are derived from the **menu hierarchy** and never from the request URL's segments —
  not even as a fallback. A page the menu doesn't cover (including one whose item is disabled, or
  whose linked entry is unpublished or deleted) gets an **empty** trail to render nothing, rather
  than crumbs invented from path segments and slugs. `null` is reserved for "no such menu",
  matching `get()`.
- Optional `menu-builder/_macros/breadcrumbs` renderer: a named `<nav>` landmark around an `<ol>`,
  `aria-current="page"` on the last crumb only, non-clickable crumbs as text, and no separator
  characters in the markup (they belong to CSS).
- Two extension events: `MenuBuilderLinkResolver::EVENT_REGISTER_LINK_TYPES` and
  `MenuBuilderVisibilityService::EVENT_REGISTER_VISIBILITY_RULES`.

### Added — accessibility

- `renderNav(menu, label = null)` macro: the whole navigation including its `<nav>` landmark, named
  after the menu (or with your own label) so a page with more than one navigation is navigable by
  landmark, carrying the menu's CSS class and its safe HTML attributes. An empty menu renders
  nothing rather than an empty landmark.
- A `target="_blank"` link now says "(opens in a new tab)" in its accessible name (WCAG 3.2.5),
  visually hidden by the macro itself so it stays invisible in a theme with no visually-hidden
  helper. `MenuBuilderNode::opensInNewTab()` is the single answer both that hint and the `target`
  attribute come from; `target` is no longer printed when it is the browser's own default
  (`_self`).
- Mega menus render as a **native `<details>` disclosure**: `open` is at once what renders the
  panel, what a click or Enter/Space toggles, and what a screen reader announces, so the plugin's
  markup is correct and operable with no JavaScript and no CSS from the site. A `disclosure: 'none'`
  mode renders the columns in flow with no disclosure and no state claimed, for a theme that
  provides its own; the mode is threaded through `renderNav()`/`render()`/`renderMegaMenu()`.
- Optional front-end asset bundle `web\assets\nav\NavAsset` (`menu-builder-nav.js`, no
  dependencies) as an **enhancement** over that native disclosure: Escape to close and return focus
  to the summary, arrow keys and Home/End within an open panel (reaching its links *and* a nested
  mega menu's own summary, skipping the links a nested disclosure is still hiding), closing one
  panel when another opens, `Tab` left alone throughout. Keys resolve against the disclosure you
  are actually in, so none of them go dead on a closed nested summary. It sets `details.open` and writes no attribute of its own, so
  it cannot introduce a state that disagrees with the browser's.
- `MenuBuilderNode::safeHtmlAttributes()` and `MenuBuilderGroup::safeHtmlAttributes()` — the bag
  the macros render, re-checked at render time by `LinkAttributeHelper::filterHtmlAttributes()`
  rather than trusted because it once passed validation. Event-handler names,
  `javascript:`/`vbscript:` values, and everything in `LinkAttributeHelper::RESERVED_ATTRIBUTES`
  are dropped, so a bag written by an import or a direct database edit can no longer emit a live
  handler, forge `aria-current="page"` on the wrong item, hide a visible link with `aria-hidden`,
  reorder the keyboard path with `tabindex`, or turn a heading into a link with `href`.
- [ACCESSIBILITY.md](ACCESSIBILITY.md): what the bundled macros guarantee, the keyboard map, what
  your CSS still owns, and a manual accessibility checklist to run before a release.
- `MenuBuilderAccessibilityTest`, asserting all of the above against rendered DOM rather than
  template source, on a Twig harness now shared with `MenuBuilderPreviewRenderTest`.

### Changed — accessibility

- The mega-menu trigger is a `<summary>`, not a `<button aria-expanded>`. A button's expanded state
  and the panel's visibility had two different owners — a script that may never have been
  registered, and the theme's CSS — so a `:hover`/`:focus-within` rule, or a page without the
  bundle, left `aria-expanded="false"` on a panel that was on screen. The state is now the
  browser's, and there is no ARIA copy of it to drift: no `aria-expanded`, no `aria-controls`, and
  still no `aria-haspopup` (these are ordinary links, not a `role="menu"` widget). The plugin's own
  control-panel preview had exactly this bug and no longer can: its stylesheet has no rule that
  reveals a closed panel, and `preview.js` opens one by setting `open`.
- The mega-menu trigger no longer repeats the item's title, icon and badge. The item's own label is
  already rendered beside it, so the trigger was a second control with the same accessible name and
  no way to tell which one opened the panel; it is now a decorative caret named for what it does
  ("Explore submenu"). The control panel's preview already hid that duplicated text with CSS —
  the markup now matches.
- A separator renders as `<li><hr></li>` instead of `<li role="separator"><hr></li>`. The `<hr>`
  already *is* a separator; the role on the `<li>` stated it twice and put a non-`listitem` child
  into a list. CP preview styling keys off `li:has(> hr)` accordingly.

### Added — performance

- Per-menu, per-site caching of the link-resolution pass only; visibility and active state always
  run fresh, so nothing user-, time- or page-specific is ever shared between visitors.
- Cache keys carry the menu, the site, **and** a configuration/version digest: the plugin's schema
  version, a reflection-derived digest of the cached classes' own shape, and the menu's id, handle
  and `dateUpdated`. So an edited menu, a menu whose handle was freed and reused by a different
  menu, and an upgrade that changed the cached payload each read a *different* key instead of an
  entry built under the old one — no migration, and nothing to remember to bump by hand.
- Targeted invalidation: a menu/item change invalidates that menu; an entry/category/asset
  save/delete/restore/URI-update invalidates only the menus that link to it (one indexed lookup)
  plus the menus whose dynamic items are sourced from that element's own section, category group,
  or volume. Draft, revision, and provisional-draft saves are ignored, and so are element types a
  menu can't link to. No blanket cache flush on any element change.
- A section, category group, or volume save invalidates only the menus referencing an element
  inside that container (one sub-query) — covering URI-format and asset base-URL changes, which
  fire no element event when `autoResaveEntries` is off (and never do for volumes).
- Invalidation is one tag invalidation per affected menu (tagged by menu **ID**), which reaches that
  menu's entry on every site and under every config version it was ever cached under. No site-ID
  enumeration, no handle lookup, and a menu rename can't orphan an entry.
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

- 627 PHPUnit unit tests covering link resolvers, visibility rules and context, mega-menu grouping,
  dynamic-source and mega-menu validation, item/group model validation, cache keying and versioning,
  link-attribute helpers, controller permission mappings, executing-scheme URL rejection,
  cached-node immutability, and the shared helpers — all without booting Craft.
- Full active-state coverage: the item / parent / grandparent / sibling hierarchy, every URI shape
  (homepage, missing or extra trailing slash, query string, fragment, absolute vs relative), every
  link type (custom URL, entry, category, asset, anchor, unavailable), external hosts and
  non-navigable schemes, the `currentUri` override recomputing a previously marked tree, the
  guarantee that exactly one node is `isActive` (and that `aria-current="page"` is emitted only
  under that flag), and that marking never writes back onto the cached nodes.
- Structural coverage of the menu (group) lifecycle: every CRUD action delegating to the service,
  handle uniqueness short-circuiting before the write, duplicate/reorder transactionality, cache
  invalidation on every frontend-affecting write, POST-only mutations, a fail-closed permission
  mapping, the unique handle index and `groupId` CASCADE in the migration, and the guarantee that
  group persistence never reaches for project config.
- Multi-site coverage across three sites (English, German, French): per-(menu, site) cache keying
  and the guarantee that one site's cached tree is never readable on another, the group-level site
  gate returning no tree at all and running before anything is loaded or cached, site-specific
  entry/category/asset availability and fallback, per-site titles and URIs, per-item site
  visibility, one cached tree filtering to a different menu on each site without being mutated,
  active state scoped to the site being served, dynamic navigation's reliance on the site-keyed
  cache, and the four lifecycle cases — site disabled, removed, renamed, and project-config
  deployment.
- Cache integration coverage against a real Yii cache backend (`MenuBuilderCacheIntegrationTest`),
  driving the production key/tag/queue code with only Craft's cache component, current site,
  `cacheDuration` and transaction state stubbed: a miss builds once and stores, a hit serves without
  rebuilding and hands back its own object graph, invalidating one menu leaves every other menu
  cached, one invalidation clears a menu on every site (a disabled one included) and under every past
  config version, site A never reads site B's tree, an edited menu / reused handle / upgraded plugin
  / foreign value at the key all rebuild rather than serve stale data, an invalidation inside a
  transaction lands only after it ends (commit and rollback), and one entry safely serves two users
  with different permissions, two different days across a date-range rule, and two different pages'
  active state.
- `ecs.php` and `phpstan.neon`, so the long-declared `composer check-cs` and `composer phpstan`
  scripts actually run. Both are clean over `src` and `tests` (PHPStan level 5).

### Fixed — multi-site

- **A site save or delete now invalidates cached menus.** Cached trees hold URLs and titles resolved
  against the site being rendered, and a site's base URL, language or existence can change without
  any element being touched — Craft resaves nothing for it, so no element event fires. Changing a
  site's base URL left every menu on that site serving the old domain until something unrelated
  happened to invalidate it. `Sites::EVENT_AFTER_SAVE_SITE` and `EVENT_AFTER_DELETE_SITE` are now
  listened for; both also fire during `project-config/apply`, so a deployment that changes sites
  invalidates too.
- **Invalidation no longer depends on a site list at all.** `getAllSiteIds()` answers differently
  depending on where it is called from: a front-end request — a web-triggered queue job running a
  structure move's URI updates, for instance — sees only enabled sites. A tree cached while a site
  was enabled outlives that site being disabled, and its key was then never cleared, so the stale
  tree was served the moment the site was switched back on. Entries are now invalidated by a
  per-menu tag, which reaches every site's entry in one call — a disabled site, a site added after
  the entry was written, or a site the caller never knew about included.
- **A menu save/duplicate/delete no longer flushes every cached menu on the install.** The cache key
  was built from the handle a save could change, so a targeted invalidation would have orphaned the
  old key's entry and the code flushed everything instead — one editor saving a menu's CSS class
  discarded every other menu's cache. Tags are keyed by menu ID, which a rename can't move, so the
  invalidation is now targeted; `MenuBuilderGroupTest` scans `src/` to assert that the whole-cache
  flush has exactly one caller left (a site save or delete).
- **A bulk item action can no longer leave stale data in the cache.** Bulk enable/disable and bulk
  delete wrap many per-item writes — each of which invalidates — in one transaction that commits
  after all of them, so the invalidations ran *before* the commit. A front-end request landing in
  that window rebuilt the tree from pre-commit data and re-cached it, and nothing invalidated it
  again afterwards: the bulk change stayed invisible on the front end until something unrelated
  flushed the menu. Invalidation raised inside a transaction is now queued and flushed when the
  outermost transaction ends — on rollback as well as commit, since the concurrent re-cache happens
  either way.
- **A link to a sibling site is no longer marked as the current page.** Absolute URLs were compared
  against every site's base-URL host, but sibling sites routinely share a path structure — with
  `/contact` on English, German and French, serving the English page marked all three active, so
  `aria-current="page"` landed on more than one link and the wrong branch styled open. The
  internal-host list is now the request host plus the *current* site's base URL. A cross-site link
  still resolves active state normally on the site it points at, and the `www.` vs bare spelling
  mismatch the site base URL was there for is still covered.

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
- **A menu item linking to another site was marked active whenever the local path matched.**
  Active-state matching compared paths only, so a custom URL to
  `https://shop.elsewhere.test/products/shoes` (or a protocol-relative
  `//elsewhere.test/products/shoes`) was reported as the current page — and rendered with
  `aria-current="page"` — while serving `/products/shoes`. Absolute URLs are now compared host-first
  against the request host plus every site's base-URL host, so other sites of the same install (and
  a `www.` vs bare mismatch between a site's base URL and the request) still match, and genuinely
  external hosts never do.
- `mailto:`/`tel:` items could be marked active. `parse_url()` reports a path for them
  (`a@b.com` for `mailto:a@b.com`), which was comparable to a request URI; only `http`/`https`
  URLs can now be the current page.
- An item whose link is unavailable (`isLinkAvailable === false` — a deleted or disabled element
  on "disable link", a rejected custom URL) or blank is now explicitly excluded from active-state
  matching rather than relying on those paths also happening to produce a `null` URL.
- A fragment-only anchor item (`#top`) could collapse to `/` and light up the homepage item. A
  bare fragment is a position on a page, not a page, and is never active.

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
- Cache invalidation issues no group lookup at all. It is keyed by menu ID — which every caller
  already has — so a bulk enable/disable of N items no longer costs N group queries, and an
  entry/category/asset save no longer costs one per referencing menu. `invalidateGroup(handle)` and
  `invalidateGroups(handles)` remain for third-party callers, who know a handle rather than an ID.
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
  reorders through the same persistence call. (The drag handle's own arrow-key operation, added
  later for accessibility, is that same gesture made keyboard-operable — it shares the handle, the
  admissibility check and the persistence call, and is not a second command set.)
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
