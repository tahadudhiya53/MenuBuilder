/**
 * Preview-only interaction for the MenuBuilder preview stage.
 *
 * This file demonstrates behaviour; it never decides content. It does not
 * read a menu, resolve a link, evaluate visibility or group a mega-menu
 * column — all of that arrived as finished markup from the same Twig macros
 * a front-end template uses. Its jobs:
 *
 *   1. Make the **whole parent item** the control that opens its children,
 *      on desktop and on mobile alike. A caret you have to hit exactly is a
 *      control an editor has to discover; real navigations open from the
 *      item.
 *
 *      How that open state is expressed depends on what the item is, and the
 *      difference is the point. A **mega menu** is a native `<details>`: the
 *      only state is its `open` property, which is what the browser renders
 *      and what a screen reader announces, so this file opens one by setting
 *      `open` and never by adding a class or an attribute beside it. A
 *      **plain submenu** is an ordinary nested list with no disclosure state
 *      to be wrong about, so it uses `[data-mb-open]` on the `<li>` as a
 *      preview-only styling hook, alongside the CSS `:hover`/`:focus-within`
 *      rules that open it when this script never runs.
 *
 *      What is deliberately *not* here any more: CSS that reveals a mega
 *      panel on hover while its `<details>` is closed, and an `aria-expanded`
 *      kept in step by hand. Both were ways for the screen and the
 *      accessibility tree to end up saying different things.
 *   2. Keep the editor on the page. Preview links carry their real href so
 *      they can be inspected, so a click is intercepted; the hint line then
 *      reports where the link points.
 *   3. Open and close the mobile navigation from its own disclosure button.
 *
 * Because a parent's label opens its submenu here, a parent that is also a
 * link does not navigate on click in the preview — the screen says so, and a
 * real site decides its own disclosure behaviour.
 *
 * Nothing here mutates menu data — there is no request in this file at all.
 */
(function() {
    'use strict';

    var STAGE = '[data-mb-preview]';
    var MEGA_TRIGGER = '.menu-builder-megamenu-trigger';

    /** The `<li>` a click landed on, but only when that item actually has children to open. */
    function parentItem(target, stage) {
        // The trigger first: its own content is a caret <span>, which would
        // otherwise match as the "label" and report the button — not the
        // row — as the thing that was clicked.
        var label = target.closest(MEGA_TRIGGER) || target.closest('a, span, button');

        if (!label || !stage.contains(label)) {
            return null;
        }

        // The label must be the item's own, not one belonging to a child list
        // that happens to sit inside it.
        var item = label.parentElement;

        if (!item || item.tagName !== 'LI') {
            return null;
        }

        var children = item.querySelector(':scope > ul, :scope > details');

        if (!children) {
            return null;
        }

        // A <details> box is displayed whether or not it is open — `open` is
        // what says so — while a plain submenu is measured the only way it
        // can be, from its computed display.
        var visible = children.tagName === 'DETAILS'
            ? children.open
            : window.getComputedStyle(children).display !== 'none';

        // Already open without any disclosure — a desktop dropdown under the
        // pointer, or a nested group inside an open card. Clicking it should
        // report its link rather than pretend to toggle something that is
        // visible either way.
        if (!isOpen(item) && visible) {
            return null;
        }

        return item;
    }

    /** The mega menu's own <details>, when this item is one. */
    function disclosure(item) {
        return item.querySelector(':scope > details');
    }

    function isOpen(item) {
        var details = disclosure(item);

        return details ? details.open : item.hasAttribute('data-mb-open');
    }

    /**
     * Opens or closes one item. For a mega menu that is the `<details>`
     * element's own state — one property that is at once the rendering, the
     * keyboard behaviour and what assistive technology reads — so there is
     * nothing to keep in step with it. For a plain submenu it is a styling
     * hook on the <li>, which claims nothing about a disclosure because
     * there isn't one.
     */
    function setOpen(item, open) {
        var details = disclosure(item);

        if (details) {
            details.open = open;

            return;
        }

        if (open) {
            item.setAttribute('data-mb-open', '');
        } else {
            item.removeAttribute('data-mb-open');
        }
    }

    /** Closes everything except the branch the given item sits on. */
    function closeOthers(stage, keep) {
        stage.querySelectorAll('[data-mb-open], details[open]').forEach(function(node) {
            var item = node.tagName === 'DETAILS' ? node.parentElement : node;

            if (item && item !== keep && (!keep || !item.contains(keep))) {
                setOpen(item, false);
            }
        });
    }

    function setHint(stage, message) {
        var hint = stage.querySelector('[data-mb-preview-hint]');

        if (hint) {
            // Assigned as text, never as markup: the value is a resolved URL,
            // which is editor-authored data like anything else on this screen.
            hint.textContent = message;
        }
    }

    function describeLink(link) {
        var href = link.getAttribute('href');
        var parts = [];

        if (!href) {
            return Craft.t('menu-builder', 'This item is a heading, not a link.');
        }

        parts.push(Craft.t('menu-builder', 'Links to {url}', { url: href }));

        if (link.getAttribute('target') === '_blank') {
            parts.push(Craft.t('menu-builder', 'opens in a new tab'));
        }

        var rel = link.getAttribute('rel');

        if (rel) {
            parts.push('rel="' + rel + '"');
        }

        if (link.getAttribute('aria-current') === 'page') {
            parts.push(Craft.t('menu-builder', 'this is the page being previewed'));
        }

        return parts.join(' · ');
    }

    function initStage(stage) {
        var burger = stage.querySelector('[data-mb-preview-burger]');
        var scrim = stage.querySelector('[data-mb-preview-scrim]');
        var nav = burger
            ? stage.querySelector('#' + burger.getAttribute('aria-controls'))
            : stage.querySelector('[data-mb-preview-nav]');

        function setMobileNavigation(open) {
            if (!burger || !nav) {
                return;
            }

            burger.setAttribute('aria-expanded', open ? 'true' : 'false');
            nav.hidden = !open;

            if (scrim) {
                scrim.hidden = !open;
            }

            stage.toggleAttribute('data-mb-mobile-nav-open', open);
            setHint(
                stage,
                open
                    ? Craft.t('menu-builder', 'Mobile navigation opened.')
                    : Craft.t('menu-builder', 'Mobile navigation closed.')
            );
        }

        stage.addEventListener('click', function(event) {
            if (burger && event.target.closest('[data-mb-preview-burger]')) {
                var navOpen = burger.getAttribute('aria-expanded') === 'true';
                setMobileNavigation(!navOpen);

                event.preventDefault();

                return;
            }

            if (scrim && event.target.closest('[data-mb-preview-scrim]')) {
                setMobileNavigation(false);
                burger.focus();
                event.preventDefault();

                return;
            }

            var item = parentItem(event.target, stage);

            if (item) {
                var open = isOpen(item);

                closeOthers(stage, open ? null : item);
                setOpen(item, !open);
                event.preventDefault();

                // A parent that is also a link still reports where it points,
                // since that is the other thing an editor comes here to check.
                var parentLink = event.target.closest('a[href]');

                if (parentLink) {
                    setHint(stage, describeLink(parentLink));
                }

                return;
            }

            var link = event.target.closest('a[href]');

            if (link && stage.contains(link)) {
                // The one thing standing between an editor and an accidental
                // trip to the front end (or to a mailto: client).
                event.preventDefault();
                setHint(stage, describeLink(link));

                return;
            }

            closeOthers(stage, null);
        });

        // Hover and keyboard focus open a desktop dropdown. For a plain
        // submenu that is a CSS rule (`:hover`/`:focus-within`) and needs no
        // JavaScript. For a mega menu it has to happen here, by opening the
        // <details> for real: a CSS rule that revealed the panel while the
        // <details> was closed would show an editor something the browser and
        // a screen reader both consider hidden.
        // Only the masthead behaves like a dropdown navigation. Footer mega
        // groups are permanently visible columns at every viewport, so hover
        // must never manufacture a footer disclosure state.
        stage.querySelectorAll('.menu-builder-preview-siteheader li:has(> details)').forEach(function(item) {
            var details = disclosure(item);
            var closeTimer = null;

            ['mouseenter', 'focusin'].forEach(function(type) {
                item.addEventListener(type, function() {
                    window.clearTimeout(closeTimer);
                    closeOthers(stage, item);
                    details.open = true;
                });
            });

            item.addEventListener('mouseleave', function() {
                // Not while the keyboard is inside it — closing a panel that
                // holds focus throws focus back to the top of the page.
                if (!item.contains(document.activeElement)) {
                    // A short grace period makes diagonal movement into the
                    // wide panel forgiving without making it feel sticky.
                    closeTimer = window.setTimeout(function() {
                        if (!item.matches(':hover')) {
                            details.open = false;
                        }
                    }, 140);
                }
            });

            item.addEventListener('focusout', function(event) {
                if (!item.contains(event.relatedTarget) && !item.matches(':hover')) {
                    details.open = false;
                }
            });
        });

        // A plain submenu has no separate disclosure control in the shipped
        // markup. On desktop :focus-within reveals it; in the compact preview
        // the same keyboard focus needs an explicit open styling hook because
        // nested lists otherwise start collapsed. Non-clickable parents stay
        // expanded through CSS, so their child links are never unreachable.
        stage.querySelectorAll('.menu-builder-preview-siteheader li:has(> ul)').forEach(function(item) {
            item.addEventListener('focusin', function(event) {
                if (event.target.closest('li') !== item) {
                    return;
                }

                closeOthers(stage, item);
                setOpen(item, true);
            });

            item.addEventListener('focusout', function(event) {
                if (!item.contains(event.relatedTarget) && !item.matches(':hover')) {
                    setOpen(item, false);
                }
            });
        });

        // A mega menu opened natively — a click or Enter/Space on its own
        // summary — still closes whatever else was open. `toggle` doesn't
        // bubble, hence the capture phase.
        stage.addEventListener('toggle', function(event) {
            var details = event.target;

            if (details.open && details.parentElement) {
                closeOthers(stage, details.parentElement);
            }
        }, true);

        stage.addEventListener('keydown', function(event) {
            if (event.key !== 'Escape') {
                return;
            }

            if (burger && burger.getAttribute('aria-expanded') === 'true') {
                setMobileNavigation(false);
                burger.focus();
                event.preventDefault();

                return;
            }

            var openNode = stage.querySelector('[data-mb-open], details[open]');
            var open = openNode && openNode.tagName === 'DETAILS' ? openNode.parentElement : openNode;

            if (open) {
                setOpen(open, false);
                closeOthers(stage, null);

                var label = open.querySelector(':scope > a, :scope > details > summary');

                if (label) {
                    label.focus();
                }
            }
        });

        // A click anywhere else closes what is open, the way a real disclosure
        // behaves.
        document.addEventListener('click', function(event) {
            if (!stage.contains(event.target)) {
                closeOthers(stage, null);
            }
        });
    }

    /**
     * The "Rendered markup" panel's copy button. It reads the panel's own
     * text — the escaped source already on the page — and puts it on the
     * clipboard; nothing is fetched, re-rendered or sent anywhere.
     */
    function initCopyButtons() {
        document.querySelectorAll('[data-mb-preview-copy]').forEach(function(button) {
            button.addEventListener('click', function() {
                var code = button.parentNode.querySelector('[data-mb-preview-code]');

                if (!code || !navigator.clipboard) {
                    return;
                }

                navigator.clipboard.writeText(code.innerText).then(function() {
                    var original = button.textContent;
                    button.textContent = Craft.t('menu-builder', 'Copied');
                    window.setTimeout(function() {
                        button.textContent = original;
                    }, 1600);
                });
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll(STAGE).forEach(initStage);
        initCopyButtons();
    });
})();
