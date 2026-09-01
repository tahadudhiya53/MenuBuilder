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

    /**
     * Several sibling sections deliberately share one field name (`customUrl`
     * for url/anchor, `elementId` for entry/category/asset, `dynamicSourceId`
     * for the three dynamic source pickers) since only one is ever meant to
     * apply at a time. Hiding a section isn't enough on its own — an
     * unrelated section's stale/blank value would still serialize and
     * silently clobber the visible one's on submit. Disabling excludes a
     * field from both serializeArray() and a native form submission, so this
     * must run for every visibility toggle, not just the type sections.
     */
    window.MenuBuilder.setFieldsDisabled = function(section, disabled) {
        section.querySelectorAll('input, select, textarea').forEach(function(field) {
            field.disabled = disabled;
        });
    };

    /**
     * Shows the one `[data-dynamic-source]` picker matching `activeSourceType`
     * and disables the rest. Pass `null` when the dynamic section itself isn't
     * showing, which disables all of them.
     *
     * The full item editor and the dashboard's quick-add panel render the same
     * `data-dynamic-source` hook over the same section / category-group /
     * volume pickers, so they share this rather than each deciding for
     * themselves which one may post `dynamicSourceId`. Nothing about a dynamic
     * source is *decided* here — sourceType, sourceId, limit and orderBy are
     * validated by MenuBuilderItem and normalized by
     * MenuBuilderDynamicNavigationService, which remain the only authorities.
     */
    window.MenuBuilder.syncDynamicSourcePickers = function(root, activeSourceType) {
        root.querySelectorAll('[data-dynamic-source]').forEach(function(wrap) {
            var visible = activeSourceType !== null &&
                wrap.getAttribute('data-dynamic-source') === activeSourceType;

            wrap.style.display = visible ? '' : 'none';
            window.MenuBuilder.setFieldsDisabled(wrap, !visible);
        });
    };

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
        var iconSourceField = root.querySelector('#iconSource');
        var iconClassWrap = root.querySelector('[data-icon-class]');
        var iconAssetWrap = root.querySelector('[data-icon-asset]');
        var dynamicSourceTypeField = root.querySelector('#dynamicSourceType');
        var dynamicSourceWraps = root.querySelectorAll('[data-dynamic-source]');

        if (!typeField) {
            return;
        }

        function isHeadingType() {
            return typeField.value === 'nonclickable' || typeField.value === 'separator';
        }

        var setSectionDisabled = window.MenuBuilder.setFieldsDisabled;

        function updateSections() {
            sections.forEach(function(section) {
                var types = section.getAttribute('data-link-section').split(',');
                var visible = types.indexOf(typeField.value) !== -1;
                section.style.display = visible ? '' : 'none';
                setSectionDisabled(section, !visible);
            });

            // Must run after the loop above: the dynamic section's own
            // re-enable would otherwise switch all three source pickers back
            // on, and they all post `dynamicSourceId`.
            updateDynamicSource();
        }

        function updateDynamicSource() {
            if (!dynamicSourceTypeField || !dynamicSourceWraps.length) {
                return;
            }

            window.MenuBuilder.syncDynamicSourcePickers(
                root,
                typeField.value === 'dynamic' ? dynamicSourceTypeField.value : null
            );
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

        /**
         * The icon source select owns which of the two icon inputs is
         * shown. The hidden one is disabled as well as hidden for the same
         * reason the link sections are: a stale value left in it would
         * still serialize, and the server would have two candidate icons
         * for one column.
         */
        function updateIconSource() {
            if (!iconSourceField || !iconClassWrap || !iconAssetWrap) {
                return;
            }
            var source = iconSourceField.value;
            [[iconClassWrap, 'class'], [iconAssetWrap, 'asset']].forEach(function(pair) {
                var visible = source === pair[1];
                pair[0].style.display = visible ? '' : 'none';
                setSectionDisabled(pair[0], !visible);
            });
        }

        typeField.addEventListener('change', function() {
            updateSections();
            updateClickableField();
        });

        if (dynamicSourceTypeField) {
            dynamicSourceTypeField.addEventListener('change', updateDynamicSource);
        }

        updateSections();
        updateClickableField();

        if (fallbackField) {
            fallbackField.addEventListener('change', updateFallback);
            updateFallback();
        }

        if (iconSourceField) {
            iconSourceField.addEventListener('change', updateIconSource);
            updateIconSource();
        }
    };

    Garnish.$doc.ready(function() {
        document.querySelectorAll('[data-menu-builder-item-fields]').forEach(function(root) {
            window.MenuBuilder.initItemFields(root);
        });
    });
})();
