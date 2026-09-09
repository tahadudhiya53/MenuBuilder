(function() {
    if (typeof Craft === 'undefined') {
        return;
    }

    /**
     * Drives the item editor's type-contingent field visibility.
    */
    window.MenuBuilder = window.MenuBuilder || {};

    /**
     * Several sibling sections deliberately share one field name (`customUrl` for url/anchor,
     * `elementId` for entry/category/asset, `dynamicSourceId` for the three dynamic source pickers)
     * since only one is ever meant to apply at a time.
    */
    window.MenuBuilder.setFieldsDisabled = function(section, disabled) {
        section.querySelectorAll('input, select, textarea').forEach(function(field) {
            field.disabled = disabled;
        });
    };

    /**
     * Shows the one `[data-dynamic-source]` picker matching `activeSourceType` and disables the
     * rest.
    */
    window.MenuBuilder.syncDynamicSourcePickers = function(root, activeSourceType) {
        root.querySelectorAll('[data-dynamic-source]').forEach(function(wrap) {
            var visible = activeSourceType !== null &&
                wrap.getAttribute('data-dynamic-source') === activeSourceType;

            wrap.style.display = visible ? '' : 'none';
            window.MenuBuilder.setFieldsDisabled(wrap, !visible);
        });
    };

    /**
     * Turns the editor's long stack of settings into scannable, independently collapsible cards.
    */
    function initSectionCards(root) {
        root.querySelectorAll('.menu-builder-fieldset > [data-mb-section]').forEach(function(section, index) {
            if (section.dataset.mbSectionInitialized) {
                return;
            }

            var heading = section.querySelector(':scope > h2');
            if (!heading) {
                return;
            }

            section.dataset.mbSectionInitialized = '1';
            section.classList.add('menu-builder-editor-section');

            var title = heading.textContent.trim();
            var body = document.createElement('div');
            var bodyId = 'menu-builder-section-' + section.dataset.mbSection + '-' + index + '-' + Math.random().toString(36).slice(2, 8);
            body.className = 'menu-builder-section-body';
            body.id = bodyId;

            while (heading.nextSibling) {
                body.appendChild(heading.nextSibling);
            }
            section.appendChild(body);

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'menu-builder-section-toggle';
            button.setAttribute('aria-controls', bodyId);
            button.innerHTML =
                '<span class="menu-builder-section-heading">' +
                    '<span class="menu-builder-section-title"></span>' +
                    '<span class="menu-builder-section-description"></span>' +
                '</span>' +
                '<span class="menu-builder-section-chevron" aria-hidden="true"></span>';

            button.querySelector('.menu-builder-section-title').textContent = title;
            button.querySelector('.menu-builder-section-description').textContent = section.dataset.mbDescription || '';
            heading.textContent = '';
            heading.appendChild(button);

            // Start with the essential link settings open.
            var fieldset = section.closest('.menu-builder-fieldset');
            var expanded = section.dataset.mbSection === 'basic' ||
                !!section.querySelector('.errors, .error') ||
                !!(fieldset && fieldset.disabled);

            function setExpanded(nextExpanded) {
                section.classList.toggle('is-expanded', nextExpanded);
                button.setAttribute('aria-expanded', nextExpanded ? 'true' : 'false');
                body.hidden = !nextExpanded;
            }

            button.addEventListener('click', function() {
                setExpanded(button.getAttribute('aria-expanded') !== 'true');
            });

            setExpanded(expanded);
        });
    }

    window.MenuBuilder.initItemFields = function(root) {
        if (!root || root.dataset.mbFieldsInitialized) {
            return;
        }
        root.dataset.mbFieldsInitialized = '1';

        initSectionCards(root);

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

            // Must run after the loop above: the dynamic section's own re-enable would otherwise
            // switch all three source pickers back on, and they all post `dynamicSourceId`.
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
         * The icon source select owns which of the two icon inputs is shown.
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
