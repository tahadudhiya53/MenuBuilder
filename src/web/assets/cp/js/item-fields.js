(function() {
    if (typeof Craft === 'undefined') {
        return;
    }

    /**
     * Drives the item editor's type-contingent field visibility.
     * Runs identically whether the fields markup came from a full page load
     * or was injected into a slide-out, since both paths call this on the
     * same `[data-menu-builder-item-fields]` root.
     */
    window.MenuBuilder = window.MenuBuilder || {};

    window.MenuBuilder.initItemFields = function(root) {
        if (!root || root.dataset.mbFieldsInitialized) {
            return;
        }
        root.dataset.mbFieldsInitialized = '1';

        var typeField = root.querySelector('#type');
        var sections = root.querySelectorAll('[data-link-section]');
        var clickableField = root.querySelector('#clickableField');
        var fallbackField = root.querySelector('#fallbackBehavior');
        var fallbackUrlWrap = root.querySelector('[data-fallback-url]');

        if (!typeField) {
            return;
        }

        function isHeadingType() {
            return typeField.value === 'nonclickable' || typeField.value === 'separator';
        }

        /**
         * Several sibling sections share the same field name (`customUrl` for
         * url/anchor, `elementId` for entry/category/asset) since only one is
         * ever meant to apply at a time. Hiding a section isn't
         * enough on its own — an unrelated section's stale/blank value would
         * still serialize and silently clobber the visible one's value on
         * submit. Disabling excludes a field from both serializeArray() and a
         * native form submission, so this must run for every visibility
         * toggle in this file, not just the type sections.
         */
        function setSectionDisabled(section, disabled) {
            section.querySelectorAll('input, select, textarea').forEach(function(field) {
                field.disabled = disabled;
            });
        }

        function updateSections() {
            sections.forEach(function(section) {
                var types = section.getAttribute('data-link-section').split(',');
                var visible = types.indexOf(typeField.value) !== -1;
                section.style.display = visible ? '' : 'none';
                setSectionDisabled(section, !visible);
            });
        }

        function updateClickableField() {
            if (!clickableField) {
                return;
            }
            clickableField.value = !isHeadingType() ? '1' : '';
        }

        function updateFallback() {
            if (!fallbackField || !fallbackUrlWrap) {
                return;
            }
            var visible = fallbackField.value === 'fallbackUrl';
            fallbackUrlWrap.style.display = visible ? '' : 'none';
            setSectionDisabled(fallbackUrlWrap, !visible);
        }

        typeField.addEventListener('change', function() {
            updateSections();
            updateClickableField();
        });
        updateSections();
        updateClickableField();

        if (fallbackField) {
            fallbackField.addEventListener('change', updateFallback);
            updateFallback();
        }
    };

    Garnish.$doc.ready(function() {
        document.querySelectorAll('[data-menu-builder-item-fields]').forEach(function(root) {
            window.MenuBuilder.initItemFields(root);
        });
    });
})();
