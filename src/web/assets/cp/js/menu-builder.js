(function() {
    if (typeof Craft === 'undefined') {
        return;
    }

    /**
     * Shared namespace + notification/AJAX helpers used by every other
     * menu-builder-cp module. Kept dependency-free (no jQuery requirement
     * beyond what Craft/Garnish already load) so it can init before the DOM
     * is fully parsed.
     */
    window.MenuBuilder = window.MenuBuilder || {};

    /** Thin alias so every module shares one entry point for API calls. */
    window.MenuBuilder.request = function(method, action, options) {
        return Craft.sendActionRequest(method, action, options);
    };

    window.MenuBuilder.success = function(message) {
        Craft.cp.displaySuccess(message);
    };

    /**
     * Extracts a user-safe message from a failed Craft.sendActionRequest —
     * the spec's "don't fail silently, don't expose raw errors" rule (§21).
     */
    window.MenuBuilder.errorMessage = function(error, fallback) {
        var data = error && error.response && error.response.data;
        return (data && data.message) || fallback || Craft.t('menu-builder', 'A server error occurred. Your changes were not saved. Please try again.');
    };

    window.MenuBuilder.displayError = function(error, fallback) {
        Craft.cp.displayError(window.MenuBuilder.errorMessage(error, fallback));
    };

    /**
     * The `errors` bag every failed `asModelFailure()` returns, keyed by model
     * attribute.
     *
     * @param {Object} error a rejected Craft.sendActionRequest
     * @returns {Object} attribute → array of messages (empty when there were none)
     */
    window.MenuBuilder.errorsFor = function(error) {
        var data = error && error.response && error.response.data;
        return (data && data.errors) || {};
    };

    // ------------------------------------------------------------------
    // Inline validation errors
    //
    // Every save endpoint already returns per-attribute messages; before
    // this they were thrown away and the editor got only a generic "couldn't
    // save" banner with no way to tell which field was wrong. These two
    // helpers render them the way Craft's own `forms.field()` does
    // (`.field.has-errors` + a `ul.errors` the input is `aria-describedby`)
    // so they look and read natively, and are shared by the slide-out and
    // the quick-add panel rather than reimplemented per caller.
    // ------------------------------------------------------------------

    var ERROR_LIST_CLASS = 'menu-builder-error-list';
    var ERROR_SUMMARY_CLASS = 'menu-builder-error-summary';

    /** Removes every error message this module previously rendered under `root`. */
    window.MenuBuilder.clearFieldErrors = function(root) {
        if (!root) {
            return;
        }

        root.querySelectorAll('.' + ERROR_LIST_CLASS + ', .' + ERROR_SUMMARY_CLASS).forEach(function(el) {
            el.remove();
        });

        root.querySelectorAll('.field.has-errors').forEach(function(field) {
            field.classList.remove('has-errors');
        });

        root.querySelectorAll('[aria-invalid="true"]').forEach(function(input) {
            input.removeAttribute('aria-invalid');

            var describedBy = (input.getAttribute('aria-describedby') || '')
                .split(' ')
                .filter(function(id) {
                    return id && id.slice(-10) !== '-mb-errors';
                });

            if (describedBy.length) {
                input.setAttribute('aria-describedby', describedBy.join(' '));
            } else {
                input.removeAttribute('aria-describedby');
            }
        });
    };

    /**
     * Finds the input a model attribute was posted from. The editor forms
     * name fields after their attribute, so the name is the reliable hook;
     * the id is only a fallback for the couple of places where two sections
     * share one name (url/anchor both post `customUrl`). Disabled inputs are
     * skipped — those belong to a hidden, inapplicable section, so anchoring
     * an error to one would point at a field the editor can't even see.
     */
    function findInput(root, attribute) {
        var escaped = (window.CSS && CSS.escape) ? CSS.escape(attribute) : attribute;

        return root.querySelector('[name="' + attribute + '"]:not([disabled])') ||
            root.querySelector('#' + escaped + ':not([disabled])');
    }

    /**
     * Renders `errors` (attribute → messages) against the fields inside
     * `root`. Anything with no field of its own — `metadata` and `visibility`
     * are validated as whole bags, so their messages name no single input —
     * is collected into one summary at the top instead of being dropped,
     * which is what used to happen to them.
     *
     * @returns {number} how many messages were shown
     */
    window.MenuBuilder.applyFieldErrors = function(root, errors) {
        window.MenuBuilder.clearFieldErrors(root);

        if (!root || !errors) {
            return 0;
        }

        var orphaned = [];
        var shown = 0;

        Object.keys(errors).forEach(function(attribute) {
            var messages = [].concat(errors[attribute] || []);

            if (!messages.length) {
                return;
            }

            shown += messages.length;

            var input = findInput(root, attribute);
            var field = input && input.closest('.field');

            if (!field) {
                orphaned = orphaned.concat(messages);
                return;
            }

            field.classList.add('has-errors');

            var list = document.createElement('ul');
            list.className = 'errors ' + ERROR_LIST_CLASS;

            messages.forEach(function(message) {
                var li = document.createElement('li');
                li.textContent = message;
                list.appendChild(li);
            });

            (field.querySelector('.input') || field).appendChild(list);

            var describedBy = (input.getAttribute('aria-describedby') || '').split(' ').filter(Boolean);
            var listId = (input.id || attribute) + '-mb-errors';
            list.id = listId;

            if (describedBy.indexOf(listId) === -1) {
                describedBy.push(listId);
                input.setAttribute('aria-describedby', describedBy.join(' '));
            }

            input.setAttribute('aria-invalid', 'true');
        });

        if (orphaned.length) {
            var summary = document.createElement('ul');
            summary.className = 'errors ' + ERROR_SUMMARY_CLASS;

            orphaned.forEach(function(message) {
                var li = document.createElement('li');
                li.textContent = message;
                summary.appendChild(li);
            });

            root.insertBefore(summary, root.firstChild);
        }

        // Bring the first problem into view — in a long editor the offending
        // field is often well below the fold, and a banner alone left the
        // editor hunting for it.
        var firstError = root.querySelector('.field.has-errors, .' + ERROR_SUMMARY_CLASS);

        if (firstError && firstError.scrollIntoView) {
            firstError.scrollIntoView({ block: 'nearest' });
        }

        return shown;
    };
})();
