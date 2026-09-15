(function() {
    if (typeof Craft === 'undefined') {
        return;
    }

    /**
     * Shared namespace + notification/AJAX helpers used by every other menu-builder-cp module.
    */
    window.MenuBuilder = window.MenuBuilder || {};

    /**
     * Thin alias so every module shares one entry point for API calls.
    */
    window.MenuBuilder.request = function(method, action, options) {
        return Craft.sendActionRequest(method, action, options);
    };

    window.MenuBuilder.success = function(message) {
        Craft.cp.displaySuccess(message);
    };

    /**
     * Extracts a user-safe message from a failed Craft.sendActionRequest — the spec's "don't fail
     * silently, don't expose raw errors" rule (§21).
    */
    window.MenuBuilder.errorMessage = function(error, fallback) {
        var data = error && error.response && error.response.data;
        return (data && data.message) || fallback || Craft.t('menubuilder', 'A server error occurred. Your changes were not saved. Please try again.');
    };

    window.MenuBuilder.displayError = function(error, fallback) {
        Craft.cp.displayError(window.MenuBuilder.errorMessage(error, fallback));
    };

    /**
     * The `errors` bag every failed `asModelFailure()` returns, keyed by model attribute.
     *
     * @param {Object} error a rejected Craft.sendActionRequest
     * @returns {Object} attribute → array of messages (empty when there were none)
    */
    window.MenuBuilder.errorsFor = function(error) {
        var data = error && error.response && error.response.data;
        return (data && data.errors) || {};
    };

    // Inline validation errors

    var ERROR_LIST_CLASS = 'menu-builder-error-list';
    var ERROR_SUMMARY_CLASS = 'menu-builder-error-summary';

    /**
     * Removes every error message this module previously rendered under `root`.
    */
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
     * Finds the input a model attribute was posted from.
    */
    function findInput(root, attribute) {
        var escaped = (window.CSS && CSS.escape) ? CSS.escape(attribute) : attribute;

        return root.querySelector('[name="' + attribute + '"]:not([disabled])') ||
            root.querySelector('#' + escaped + ':not([disabled])');
    }

    /**
     * Renders `errors` (attribute → messages) against the fields inside `root`.
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

        // Bring the first problem into view — in a long editor the offending field is often well
        // below the fold, and a banner alone left the editor hunting for it.
        var firstError = root.querySelector('.field.has-errors, .' + ERROR_SUMMARY_CLASS);

        if (firstError && firstError.scrollIntoView) {
            firstError.scrollIntoView({ block: 'nearest' });
        }

        return shown;
    };
})();
