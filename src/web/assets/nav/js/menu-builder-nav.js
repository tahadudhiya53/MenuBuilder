/**
 * Optional front-end enhancement for the navigation the bundled Twig macros
 * render.
 *
 * **It is not what makes the markup honest.** A mega menu's open/closed state
 * is a native `<details open>` — one attribute, owned by the browser, which
 * is simultaneously what renders the panel, what a pointer/Enter/Space
 * toggles, and what a screen reader announces. There is no `aria-expanded`
 * here to keep in step with anything, and nothing this file does can put the
 * markup into a state that misdescribes the page: every line below either
 * reads `details.open` or sets it, and setting it *is* opening or closing the
 * panel.
 *
 * With this script absent the mega menu still works: click, Enter and Space
 * open and close it, the panel's links are reachable, and the state is
 * announced correctly. What this adds is the rest of the disclosure
 * behaviour a real navigation wants:
 *
 *   Escape          close the open panel, focus back on its summary
 *   ArrowDown       from the summary, open and move into the panel;
 *                   inside it, move to the next link
 *   ArrowUp         inside the panel, move to the previous link; from a
 *                   *closed* summary it deliberately does nothing (opening
 *                   upwards would move focus into a panel nobody asked for)
 *   Home / End      first / last link in the open panel
 *   Tab             untouched — moving focus out of an open panel closes it
 *   click outside   closes what is open
 *   opening one     closes any other panel in the same navigation
 *
 * It reads no menu data, resolves no link, decides no visibility, and adds no
 * class or attribute of its own — `details[open]` is the only state, and the
 * only styling hook it needs to be.
 */
(function() {
    'use strict';

    var NAV = '[data-menu-builder-nav]';
    var DISCLOSURE = 'details[data-mb-disclosure]';
    // A nested mega menu's own summary is a control *in* the panel, so the
    // arrow keys have to walk onto it like any link; leaving it out made it
    // reachable only by Tab.
    var FOCUSABLE = 'a[href], button:not([disabled]), summary';

    function summaryFor(details) {
        return details.querySelector(':scope > summary');
    }

    function panelFor(details) {
        return details.querySelector(':scope > .menu-builder-megamenu-panel');
    }

    /**
     * Every *reachable* control in a panel, in document order: its links, and
     * the summary of any nested disclosure. What is left out is the content
     * of a nested disclosure that is still closed — in the DOM, but unable to
     * take focus, so walking onto it would leave the arrow keys stuck on an
     * element nobody can see. A closed disclosure's own summary is not part
     * of its content and stays reachable.
     */
    function panelLinks(details) {
        var panel = panelFor(details);

        if (!panel) {
            return [];
        }

        return Array.prototype.slice.call(panel.querySelectorAll(FOCUSABLE)).filter(function(control) {
            var closed = control.closest('details:not([open])');

            return !closed || !panel.contains(closed) || control === summaryFor(closed);
        });
    }

    /**
     * Closes every disclosure in `nav` except `keep` and its ancestors — a
     * nested mega menu must not close the panel it is sitting inside.
     */
    function closeOthers(nav, keep) {
        nav.querySelectorAll(DISCLOSURE).forEach(function(details) {
            if (details.open && details !== keep && !(keep && details.contains(keep))) {
                details.open = false;
            }
        });
    }

    /**
     * Which disclosure(s) a key press concerns. Two of them, because a
     * control can sit in both roles at once — a nested mega menu's summary is
     * a disclosure of its own *and* a control inside its parent's panel:
     *
     *   own        the disclosure whose summary is focused, if any
     *   container  the nearest enclosing open disclosure whose panel the
     *              focused element sits in
     *
     * Keeping them apart is what stops Escape and Home/End becoming dead keys
     * on a closed nested summary: opening belongs to `own`, while "close the
     * panel I am in" and "walk the panel I am in" belong to `container`.
     */
    function context(target, nav) {
        var summary = target.closest('summary');
        var own = summary ? summary.parentElement : null;

        if (own && !(own.matches(DISCLOSURE) && nav.contains(own))) {
            own = null;
        }

        var panel = target.closest('.menu-builder-megamenu-panel');
        var container = null;

        while (panel && !container) {
            var candidate = panel.parentElement;

            if (candidate && candidate.matches(DISCLOSURE) && nav.contains(candidate)) {
                container = candidate;
                break;
            }

            panel = candidate && candidate.parentElement ? candidate.parentElement.closest('.menu-builder-megamenu-panel') : null;
        }

        return own || container ? { own: own, container: container } : null;
    }

    /**
     * Moves focus within an open panel. `from` is the element focus is on
     * now, or null when it is coming from the summary.
     */
    function focusLink(details, from, step) {
        var links = panelLinks(details);

        if (!links.length) {
            return;
        }

        var index = from ? links.indexOf(from) : -1;
        var next;

        if (step === 'first') {
            next = links[0];
        } else if (step === 'last') {
            next = links[links.length - 1];
        } else if (index === -1) {
            next = step === 1 ? links[0] : links[links.length - 1];
        } else {
            // Stops at the ends rather than wrapping: these are links, and
            // Tab — which still works — is how you leave them.
            next = links[Math.min(Math.max(index + step, 0), links.length - 1)];
        }

        next.focus();
    }

    function initNav(nav) {
        // `toggle` doesn't bubble, so it is caught in the capture phase.
        // Reacting to the event rather than to a click means a panel opened
        // any other way — the keyboard, find-in-page, a theme's own script
        // setting `open` — still closes its siblings.
        nav.addEventListener('toggle', function(event) {
            var details = event.target;

            if (details.open && details.matches(DISCLOSURE)) {
                closeOthers(nav, details);
            }
        }, true);

        nav.addEventListener('keydown', function(event) {
            var where = context(event.target, nav);

            if (!where) {
                return;
            }

            var own = where.own;
            var container = where.container;

            switch (event.key) {
                case 'Escape':
                    // The innermost panel that is actually open: your own if
                    // you opened it, otherwise the one you are standing in.
                    var open = own && own.open ? own : container;

                    if (open && open.open) {
                        open.open = false;
                        summaryFor(open).focus();
                        event.preventDefault();
                    }

                    break;

                case 'ArrowDown':
                    if (own) {
                        own.open = true;
                        focusLink(own, null, 'first');
                    } else {
                        focusLink(container, event.target, 1);
                    }

                    event.preventDefault();

                    break;

                case 'ArrowUp':
                    if (own && own.open) {
                        focusLink(own, null, 'last');
                    } else if (container) {
                        // Includes a *closed* nested summary: there it is one
                        // more control in the panel you are walking.
                        focusLink(container, event.target, -1);
                    } else {
                        // A closed top-level summary. Deliberately inert:
                        // opening upwards would move focus into a panel
                        // nobody asked for.
                        break;
                    }

                    event.preventDefault();

                    break;

                case 'Home':
                case 'End':
                    if (container) {
                        focusLink(container, null, event.key === 'Home' ? 'first' : 'last');
                        event.preventDefault();
                    }

                    break;
            }
        });

        // Tabbing (or clicking) out of an open panel closes it — a disclosure
        // does not stay open behind you.
        nav.addEventListener('focusout', function(event) {
            var next = event.relatedTarget;

            nav.querySelectorAll(DISCLOSURE).forEach(function(details) {
                if (details.open && (!next || !details.contains(next))) {
                    details.open = false;
                }
            });
        });

        document.addEventListener('click', function(event) {
            if (!nav.contains(event.target)) {
                closeOthers(nav, null);
            }
        });
    }

    function init() {
        document.querySelectorAll(NAV).forEach(initNav);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
