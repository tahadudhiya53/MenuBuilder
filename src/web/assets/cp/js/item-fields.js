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
     * The item types whose title falls back to the linked element's own title.
    */
    var ELEMENT_TYPES = ['entry', 'category', 'asset'];

    /**
     * Shows the linked element's own title in the Title box, as a placeholder.
     *
     * MenuBuilderResolver has always fallen back to the linked element's title when an item's own
     * is blank, but the editor only ever saw that as an absence — an empty box, and
     * "(uses linked element's title)" on the row. This puts the real title in front of the editor
     * without changing what is stored.
     *
     * A placeholder rather than a value, deliberately. `menubuilder_items.title` is one column for
     * every site; the per-site title comes from leaving it blank, because that is what makes
     * MenuBuilderLinkResolver fall through to the element as loaded for the *current* site. Writing
     * the CP's current-site label into that column would freeze one site's wording across all of
     * them, silently and with no way back. A placeholder is visible only while the box is empty, so
     * it also needs no notion of "derived vs. typed": type anything and the placeholder is simply
     * covered by the value.
     *
     * No request is made: Craft renders each selected element's site-specific label onto its chip
     * as `data-label`, and the element select keeps a live handle on its own selection, so the
     * title is already on the page by the time this runs.
     *
     * @param {Element} root the form or panel to search within
     * @param {Object} config `titleInput`, `typeField`, and `sectionAttribute` — the attribute
     *                        naming each link-type section, which differs between the two screens.
    */
    window.MenuBuilder.initTitleSync = function(root, config) {
        var $ = window.jQuery;
        var titleInput = config.titleInput;
        var typeField = config.typeField;
        var sectionAttribute = config.sectionAttribute;

        if (!$ || !root || !titleInput || !typeField) {
            return;
        }

        // Whatever the field already offered before any element was picked.
        var originalPlaceholder = titleInput.getAttribute('placeholder') || '';

        /**
         * The label of the element currently selected in the section matching the chosen type, or
         * null when there is no such selection (or it has no usable title).
        */
        function selectedLabel() {
            if (ELEMENT_TYPES.indexOf(typeField.value) === -1) {
                return null;
            }

            var section = root.querySelector('[' + sectionAttribute + '="' + typeField.value + '"]');
            var container = section && section.querySelector('.elementselect');

            if (!container) {
                return null;
            }

            // The input's own `$elements` is updated before it announces the change, whereas the
            // chip it drops lingers in the DOM for the length of its removal animation — so ask
            // the input, and fall back to the markup only before it has been instantiated.
            var input = $(container).data('elementSelect');
            var $element = (input && input.$elements)
                ? input.$elements.first()
                : $(container).find('.element').first();

            if (!$element || !$element.length) {
                return null;
            }

            var label = String($element.data('label') || '').trim();

            return label !== '' ? label : null;
        }

        function sync() {
            titleInput.setAttribute('placeholder', selectedLabel() || originalPlaceholder);
        }

        // Craft announces a selection, an addition and a removal all as a `change` on the
        // `.elementselect` container — and as a jQuery event, so it has to be heard with jQuery.
        $(root).on('change', '.elementselect', sync);

        // Every type change re-derives: switching to another element type reads that type's own
        // picker, and switching to one that has no element clears the placeholder again.
        typeField.addEventListener('change', sync);

        sync();
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
                    '<span class="menu-builder-section-title-row">' +
                        '<span class="menu-builder-section-title"></span>' +
                    '</span>' +
                    '<span class="menu-builder-section-description"></span>' +
                '</span>' +
                '<span class="menu-builder-section-chevron" aria-hidden="true"></span>';

            button.querySelector('.menu-builder-section-title').textContent = title;

            // Craft field layout tabs carry a badge, so a menu's own settings and the site's
            // custom fields stay tellable apart at a glance.
            if (section.dataset.mbSectionBadge) {
                var sectionBadge = document.createElement('span');
                sectionBadge.className = 'menu-builder-tag';
                sectionBadge.textContent = section.dataset.mbSectionBadge;
                button.querySelector('.menu-builder-section-title-row').appendChild(sectionBadge);
            }
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

    /**
     * Badges every field Craft renders from the menu's field layout, so it is obvious which
     * inputs come from the site's own custom fields rather than from Menu Builder.
    */
    function initCustomFieldTags(root) {
        root.querySelectorAll('[data-mb-custom-fields]').forEach(function(container) {
            var label = container.dataset.mbCustomFields || 'Custom field';

            container.querySelectorAll(':scope > .field').forEach(function(field) {
                if (field.dataset.mbCustomFieldTagged) {
                    return;
                }

                var heading = field.querySelector(':scope > .heading');
                if (!heading) {
                    return;
                }

                field.dataset.mbCustomFieldTagged = '1';

                var tag = document.createElement('span');
                tag.className = 'menu-builder-tag';
                tag.textContent = label;

                // Sit beside the label rather than after Craft's field action menu.
                var labelEl = heading.querySelector(':scope > label, :scope > legend');
                if (labelEl) {
                    labelEl.insertAdjacentElement('afterend', tag);
                } else {
                    heading.insertBefore(tag, heading.firstChild);
                }
            });
        });
    }

    window.MenuBuilder.initItemFields = function(root) {
        if (!root || root.dataset.mbFieldsInitialized) {
            return;
        }
        root.dataset.mbFieldsInitialized = '1';

        initSectionCards(root);
        initCustomFieldTags(root);

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

        window.MenuBuilder.initTitleSync(root, {
            titleInput: root.querySelector('#title'),
            typeField: typeField,
            sectionAttribute: 'data-link-section',
        });

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
