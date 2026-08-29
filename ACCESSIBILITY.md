# Accessibility

What MenuBuilder guarantees about the navigation it renders, what it deliberately leaves to your
CSS, and the manual checks to run before a release.

The rule everything here follows: **an attribute must describe something that is true** — and,
where two things would have to be kept in step to stay true, **there must only be one of them.**
`aria-current="page"` sits on the one link that is the page being served. A mega menu carries no
`aria-expanded` at all, because its open/closed state is a native `<details open>` that the browser
renders *and* announces from the same attribute. No `aria-haspopup` on a panel of ordinary links.
An overstated attribute is worse than a missing one — it sends a screen-reader user looking for a
control that isn't there.

Everything below is asserted against rendered markup in
[`tests/Unit/MenuBuilderAccessibilityTest.php`](tests/Unit/MenuBuilderAccessibilityTest.php), not
against template source.

---

## What the bundled macros guarantee

| | |
|---|---|
| **Landmark** | `renderNav()` emits one `<nav>` per menu, named with `aria-label` (the menu's name, or your own label) so a page with more than one navigation is navigable by landmark. An empty menu renders nothing at all rather than an empty landmark. |
| **Lists** | A menu is a `<ul>` of `<li>`; children nest inside their parent's `<li>`. The hierarchy is real markup, so "3 of 7" is announced correctly. |
| **Links** | An ordinary `<a href>`. No `tabindex` is emitted anywhere — every link is a Tab stop in document order, and the macros never remove or reorder one. |
| **Headings** | A non-clickable item is a `<span>`: no `href`, no `role="link"`, not focusable. A focusable element that does nothing when activated is a dead stop on the Tab path. |
| **Separators** | An `<hr>` — whose role *is* separator — inside an ordinary list item. The role is not repeated on the `<li>`, which would both state it twice and put a non-`listitem` child into a list. |
| **Active state** | `aria-current="page"` on the active link only. An *ancestor* of it gets the `is-active` class for styling and no ARIA: "the page I am on" can only be true of one link. |
| **New tab** | A `target="_blank"` link carries a visually hidden "(opens in a new tab)" inside its accessible name (WCAG 3.2.5). `target` is emitted only when it is `_blank` — `_self` is the browser's default already. |
| **Mega menus** | A native `<details>` disclosure: `<summary>` as the control, the panel inside it. Open/closed is the browser's own `open` attribute — see [Who owns mega-menu state](#who-owns-mega-menu-state). No `aria-expanded`, no `aria-controls`, no `aria-haspopup`. The summary does not repeat the item's label (already on screen as the link or heading beside it) but is named for what it does — "Explore submenu" — over a decorative caret. The panel is `role="group"` with the item's own accessible name, holding ordinary links: no `role="menu"`/`menuitem`, which would promise roving focus and no Tab stops. |
| **Icons** | `aria-hidden="true"` on a class icon, `alt=""` on an asset icon. The item's title (or its ARIA label) is the accessible name; an icon repeating it is noise. An icon that is an item's *only* label needs an ARIA label on the item. |
| **Badges** | Rendered *inside* the link, so the name reads "Products New" rather than leaving "New" floating in the list. |
| **Custom attributes** | Filtered at render: no event handlers, no `javascript:`/`vbscript:` values, and none of the attributes the macros own or the ARIA states nothing implements. See below. |

## Who owns mega-menu state

**The browser does.** A mega menu renders as:

```html
<li>
  <a href="/explore">Explore</a>
  <details class="menu-builder-megamenu" data-mb-disclosure>
    <summary class="menu-builder-megamenu-trigger" aria-label="Explore submenu">…caret…</summary>
    <div class="menu-builder-megamenu-panel" role="group" aria-label="Explore">…columns…</div>
  </details>
</li>
```

`open` on that `<details>` is simultaneously:

- what makes the panel render,
- what a click, `Enter` or `Space` on the summary toggles,
- what a screen reader announces as expanded or collapsed.

One attribute, one owner. There is nothing for a script to keep in step and nothing for a
stylesheet to contradict — which is the whole reason for the choice. The previous design (a
`<button aria-expanded="false">` beside the panel) split visibility and state across the theme's
CSS and an optional script, so a `:hover` rule, or simply a page where the script hadn't been
registered, produced a panel on screen that the markup still called closed.

**MenuBuilder guarantees this with no JavaScript and no CSS from you.** `NavAsset` is an
enhancement that adds keys, never the thing that makes the markup honest.

### What your CSS must not do

One rule, and it is the only way to break the guarantee: **never make a panel visible while its
`<details>` is closed.** Concretely:

- ✅ `details[open] > .menu-builder-megamenu-panel { … }` — style the open state.
- ✅ `.menu-builder-megamenu-panel { position: absolute; … }` — style the panel itself.
- ✅ `.menu-builder-megamenu-panel--static { display: grid; }` — lay out the explicit
  `disclosure: 'none'` variant, which has no closed state (used by the preview footer at every viewport).
- ❌ `li:hover > details > .menu-builder-megamenu-panel { display: block }` — a lie: the browser
  and the accessibility tree both consider that panel closed.
- ❌ `display: contents` / `flex` / `grid` on the `<details>` element itself — some browsers stop
  hiding closed content when its `display` is changed. Lay the row out on the `<li>` instead
  (that's what the control panel's own preview does).

If you want a panel to open on hover, set the `open` property from your own script — that is the
same state a click sets, so it stays true.

## Keyboard

Native, with no bundle registered:

| Key | Behaviour |
|---|---|
| `Tab` / `Shift+Tab` | Moves through every link in document order — the summary is a Tab stop; a closed panel's links are not in the tab order, which is what "closed" means. Nothing is trapped. |
| `Enter` / `Space` | On a link, follows it. On a summary, opens or closes the panel. |

Registering the optional bundle adds the rest:

```twig
{% do view.registerAssetBundle('Tahadudhiya\\MenuBuilder\\web\\assets\\nav\\NavAsset') %}
```

| Key | Behaviour |
|---|---|
| `Escape` | Closes the panel you are in — your own if you opened it, otherwise the one you are standing inside — and returns focus to its summary. |
| `ArrowDown` | From a summary, opens that panel and moves to its first control; inside a panel, moves to the next one. |
| `ArrowUp` | Inside a panel, moves to the previous control. From a **closed top-level** summary it deliberately does nothing — opening upwards would move focus into a panel nobody asked for. From an open one, it jumps to the last control. |
| `Home` / `End` | First / last control in the panel you are in. |
| — | "Controls" means the panel's links *and* the summary of a nested mega menu — the arrow keys reach everything focusable in the panel. The links a nested disclosure is still hiding are skipped, because they cannot take focus. |
| `Tab` | Still untouched: tabbing out of an open panel closes it. |
| — | Opening one panel closes any other in the same navigation, however it was opened. |

The bundle sets `details.open` and nothing else — it writes no attribute of its own, so it cannot
introduce a state that disagrees with the browser's.

### If you don't use the bundled disclosure

Two supported ways out, both truthful:

1. **Style the `<details>` yourself** and add whatever keys you want on top — the state stays the
   browser's either way.
2. **`disclosure: 'none'`** — render the columns in flow with no `<details>`, no summary and no
   state claimed at all, then provide your own control:

```twig
{{ menuMacros.renderNav(craft.menuBuilder.get('main'), null, 'none') }}
```

What is *not* supported is a third state of your own — a class or `aria-expanded` you maintain
beside the panel — because that is the bug this design removed.

## Custom HTML attributes

An item's (or menu's) attributes bag is the one editor-authored value whose **keys** reach markup
where an attribute *name* goes — which is the one place Twig's escaping doesn't make safe. It is
validated on save *and* re-checked at render (`LinkAttributeHelper::filterHtmlAttributes()`), so a
row that never passed validation — an import, a direct database write, a row older than the rule —
still can't emit anything live. Dropped at render:

- event-handler names (`onclick`, `onerror`, anything starting `on`) and malformed names,
- values using a `javascript:` or `vbscript:` scheme, whitespace tricks included,
- `href`, `target`, `rel`, `id`, `class`, `role` — the attributes the macros emit themselves,
- `tabindex`, `aria-current`, `aria-expanded`, `aria-controls`, `aria-haspopup`, `aria-hidden` —
  ARIA and focus states that would describe behaviour the markup doesn't implement.

Ordinary `data-*`, `aria-describedby`, `title` and the like still render.

---

## Manual test checklist

Automated tests cover the markup; these are the things only a person can answer. Run them against
a real front-end template using the bundled macros, on a menu with at least: a mega-menu parent, a
plain submenu, a separator, a non-clickable heading, an icon, a badge, and an external `_blank`
link.

### Disclosure state — do this one first

The one thing automated tests cannot see: that what is on screen and what the markup says are the
same thing. Open the page, open DevTools on the mega-menu's `<details>` element, and watch the
`open` attribute while you drive it.

- [ ] **Closed:** panel not visible, `<details>` has no `open` attribute, its links are not
      reachable by `Tab`.
- [ ] **Open** (click the summary): panel visible, `<details open>` present.
- [ ] **Escape** with the panel open: panel hidden, `open` gone, focus back on the summary.
- [ ] The same three states hold when opened by **pointer**, by **Enter**, by **Space**, and by
      **ArrowDown** — the attribute follows every route in.
- [ ] Hovering a mega-menu item does **not** reveal the panel unless something sets `open`
      (if your theme opens on hover, confirm it sets `open` and not just CSS).
- [ ] Clicking outside, and tabbing out of the panel, both close it — and the attribute goes with it.
- [ ] With a second mega menu on the page, opening one closes the other, attribute included.
- [ ] Repeat the whole list with the `NavAsset` bundle **not registered**: everything except the
      extra keys must still hold.

### Keyboard, no mouse

- [ ] `Tab` from the top of the page reaches every menu link, once each, in the order they appear.
- [ ] The focused item is **visibly** focused at every step, including inside an open panel.
- [ ] `Shift+Tab` walks back out in the same order; focus is never trapped and never jumps to the top.
- [ ] `Enter` on a link navigates. `Space` on a link does not (it scrolls) — that's correct.
- [ ] `Enter` **and** `Space` both toggle a mega-menu summary; the panel appears and disappears.
- [ ] `Escape` with a panel open closes it and returns focus to its summary — not to the top of the page.
- [ ] `ArrowDown` on a closed summary opens the panel and lands on its first link; `ArrowUp`/`ArrowDown` walk the panel; `Home`/`End` jump to its ends. `ArrowUp` on a closed summary does nothing, on purpose.
- [ ] Tabbing past the last link in an open panel closes it and continues into the rest of the page.
- [ ] Nothing in the menu is reachable *only* by pointer (hover-only submenus are the usual offender).

### Screen reader (VoiceOver + Safari, NVDA + Firefox, or your target pair)

- [ ] The landmark list names each menu — "Main", "Footer" — and not "navigation navigation".
- [ ] The list is announced with the right item count at each level.
- [ ] The current page's link is announced as **current page**, and no other link is.
- [ ] An external link's name ends with "opens in a new tab".
- [ ] A mega-menu summary announces "collapsed"/"expanded" (VoiceOver may say "disclosure
      triangle") and the state changes when you activate it — verify in **both** VoiceOver + Safari
      and NVDA + Firefox if you can, since the state comes from the browser's native mapping.
- [ ] With the panel closed, its links are absent from the screen reader's link list; with it open,
      they are present.
- [ ] A heading item is read as text, not as a link or a button.
- [ ] Icons add nothing to any item's name; a badge reads as part of it ("Products New").
- [ ] A separator is either announced as a separator or skipped — never as an empty item.

### Zoom, motion and appearance

- [ ] At 200% zoom, and at a 320px-wide viewport, every item is reachable and nothing is clipped.
- [ ] With `prefers-reduced-motion: reduce`, no menu animation is required to see the panel open.
- [ ] In Windows High Contrast / forced-colors mode, the active item and focus are still distinguishable.
- [ ] Focus indicators meet contrast against their background; so do the link, badge and active-item colours.

### Content, in the control panel

- [ ] Every item has a meaningful title, or an ARIA label where the visible label is an icon alone.
- [ ] No item's label is "click here", "read more", or the same text as a different destination.
- [ ] `aria-current` appears on exactly one link on each real front-end page you check. The generic
      control-panel presentation preview deliberately does not invent a current page.
