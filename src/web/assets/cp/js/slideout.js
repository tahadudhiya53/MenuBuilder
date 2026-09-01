(function() {
    if (typeof Craft === 'undefined') {
        return;
    }

    /**
     * A small self-contained slide-out panel, built on Craft's own
     * `.slideout`/`.slideout-container`/`.slideout-shade` CSS (so it looks
     * native) but with its own JS — Craft.CpScreenSlideout expects a
     * private "CP screen" response contract from its target action that
     * isn't practical to reverse-engineer reliably, so this instead talks to
     * MenuBuilder's own `items/edit` and `items/save` actions using a JSON
     * shape we control end to end (see ItemsController::actionEdit/actionSave).
     *
     * Only one instance exists at a time — opening a new item closes
     * whatever's already open, after confirming unsaved changes.
     */
    window.MenuBuilder = window.MenuBuilder || {};

    var $shade, $container, $panel, $header, $title, $closeBtn, $body, $footer, $cancelBtn, $saveBtn, $spinner;
    var isOpen = false;
    var isDirty = false;
    var isSaving = false;
    var currentOnSaved = null;
    var $returnFocusTo = null;

    var TITLE_ID = 'menu-builder-slideout-title';

    function build() {
        if ($panel) {
            return;
        }

        $shade = $('<div class="slideout-shade menu-builder-slideout-shade"></div>').appendTo(Garnish.$bod);
        $container = $('<div class="slideout-container"></div>').appendTo(Garnish.$bod);
        // `aria-labelledby` rather than a bare dialog role: without it the
        // panel announces itself as an unnamed dialog, so the item being
        // edited was never spoken.
        $panel = $('<div class="slideout menu-builder-slideout" id="menu-builder-slideout" role="dialog" aria-modal="true" tabindex="-1"></div>')
            .attr('aria-labelledby', TITLE_ID)
            .appendTo($container);

        $header = $('<div class="menu-builder-slideout-header"></div>').appendTo($panel);
        $title = $('<h1></h1>').attr('id', TITLE_ID).appendTo($header);
        $closeBtn = $('<button type="button" class="btn icon" data-icon="remove"></button>')
            .attr('aria-label', Craft.t('app', 'Close'))
            .appendTo($header);

        $body = $('<div class="menu-builder-slideout-body"></div>').appendTo($panel);

        $footer = $('<div class="menu-builder-slideout-footer flex"></div>').appendTo($panel);
        $cancelBtn = $('<button type="button" class="btn">' + Craft.t('app', 'Cancel') + '</button>').appendTo($footer);
        $saveBtn = $('<button type="button" class="btn submit">' + Craft.t('app', 'Save') + '</button>').appendTo($footer);
        $spinner = $('<div class="spinner hidden"></div>').appendTo($footer);

        $closeBtn.on('click', requestClose);
        $cancelBtn.on('click', requestClose);
        $shade.on('click', requestClose);
        $saveBtn.on('click', save);

        Garnish.$doc.on('keydown', function(event) {
            if (isOpen && event.keyCode === Garnish.ESC_KEY) {
                requestClose();
            }
        });

        $body.on('input change', '[data-menu-builder-item-fields]', function() {
            isDirty = true;
        });

        // Ctrl/Cmd-S saves, the same shortcut every other CP editor honours.
        $panel.on('keydown', function(event) {
            if (event.keyCode === Garnish.S_KEY && (event.metaKey || event.ctrlKey)) {
                event.preventDefault();
                save();
            }
        });
    }

    function requestClose() {
        if (isSaving) {
            return;
        }

        if (isDirty && !confirm(Craft.t('menu-builder', 'You have unsaved changes. Leave without saving?'))) {
            return;
        }

        close();
    }

    function close() {
        isOpen = false;
        isDirty = false;
        currentOnSaved = null;
        $panel.removeClass('is-open');
        $shade.removeClass('is-open');
        Garnish.$bod.removeClass('menu-builder-slideout-open');
        Craft.releaseFocusWithin($panel[0]);

        // Focus was inside a panel that no longer exists on screen; without
        // this it falls back to <body> and the keyboard user is dropped at
        // the top of the document instead of on the row they came from.
        if ($returnFocusTo && $returnFocusTo.length && document.contains($returnFocusTo[0])) {
            $returnFocusTo.trigger('focus');
        }

        $returnFocusTo = null;
    }

    /**
     * @param {Object} params `groupHandle` and `itemId`. Edit-only — new items
     *               are created by the dashboard's quick-add panel.
     * @param {Function} [onSaved] called with the save response's data once the item is saved.
     */
    window.MenuBuilder.openItemSlideout = function(params, onSaved) {
        build();

        if (isOpen && isDirty && !confirm(Craft.t('menu-builder', 'You have unsaved changes. Leave without saving?'))) {
            return;
        }

        $returnFocusTo = $(document.activeElement);

        isOpen = true;
        isDirty = false;
        currentOnSaved = onSaved || null;
        $panel.addClass('is-open');
        $shade.addClass('is-open');
        Garnish.$bod.addClass('menu-builder-slideout-open');
        $title.text(Craft.t('menu-builder', 'Loading…'));
        // A bare spinner announces nothing; screen readers were told only that
        // the dialog was empty.
        $body
            .attr('aria-busy', 'true')
            .html('<div class="menu-builder-slideout-loading"><div class="spinner"></div><p class="light">' +
                Craft.t('menu-builder', 'Loading menu item…') + '</p></div>');
        $panel.attr('data-group-handle', params.groupHandle);
        $saveBtn.removeClass('hidden').prop('disabled', true);
        $panel.trigger('focus');
        // Tab must not walk out of an `aria-modal` dialog into the page behind
        // it — Garnish already implements the trap Craft's own slideouts use.
        Craft.trapFocusWithin($panel[0]);

        window.MenuBuilder.request('GET', 'menu-builder/items/edit', { params: params })
            .then(function(response) {
                $title.text(response.data.title);
                $body.removeAttr('aria-busy').html(response.data.html);

                if (response.data.headHtml) {
                    Craft.appendHeadHtml(response.data.headHtml);
                }
                if (response.data.footHtml) {
                    Craft.appendBodyHtml(response.data.footHtml);
                }

                var root = $body.find('[data-menu-builder-item-fields]')[0];
                if (root && window.MenuBuilder.initItemFields) {
                    window.MenuBuilder.initItemFields(root);
                }

                // `items/edit` only needs `view`, so this panel can legitimately
                // be open for someone who may not save. Offering a Save the
                // save action would refuse is worse than not offering one.
                var canSave = response.data.canSave !== false;
                $saveBtn.toggleClass('hidden', !canSave).prop('disabled', !canSave);
                $cancelBtn.text(canSave ? Craft.t('app', 'Cancel') : Craft.t('app', 'Close'));

                isDirty = false;
                Craft.setFocusWithin($body[0]);
            })
            .catch(function(error) {
                $title.text(Craft.t('menu-builder', 'Menu item'));
                $body
                    .removeAttr('aria-busy')
                    .html('')
                    .append(
                        $('<p class="error" role="alert"></p>').text(
                            window.MenuBuilder.errorMessage(error, Craft.t('menu-builder', 'Couldn’t load that menu item.'))
                        )
                    );
                $saveBtn.addClass('hidden');
                $cancelBtn.text(Craft.t('app', 'Close'));
            });
    };

    /**
     * Rebuilds a PHP-style bracket-notation payload (`foo[bar][]`) from a
     * jQuery serializeArray() list — Craft.sendActionRequest posts our data
     * as-is rather than through a real `<form>` submission, so we have to
     * reconstruct the nested/array shape the controller expects ourselves.
     * A later `foo[bar][]` always wins over an earlier `foo[bar]` scalar —
     * checkboxSelectField renders a zero-value padding input ahead of its
     * checkboxes precisely so the key exists even when nothing is checked,
     * and that padding value must not survive once real items are collected.
     */
    function setBracketValue(root, rawName, value) {
        var segments = [];
        var re = /^[^\[\]]+|\[([^\]]*)\]/g;
        var m;
        while ((m = re.exec(rawName)) !== null) {
            segments.push(m[1] !== undefined ? m[1] : m[0]);
        }

        var parent = root;
        for (var i = 0; i < segments.length; i++) {
            var key = segments[i];
            var isLast = i === segments.length - 1;

            if (key === '') {
                if (isLast) {
                    parent.push(value);
                    return;
                }
                var obj = {};
                parent.push(obj);
                parent = obj;
                continue;
            }

            if (isLast) {
                parent[key] = value;
                return;
            }

            if (segments[i + 1] === '') {
                if (!Array.isArray(parent[key])) {
                    parent[key] = [];
                }
            } else if (typeof parent[key] !== 'object' || parent[key] === null || Array.isArray(parent[key])) {
                parent[key] = {};
            }

            parent = parent[key];
        }
    }

    function save() {
        var $form = $body.find('[data-menu-builder-item-fields]');

        if (!$form.length || isSaving || $saveBtn.prop('disabled')) {
            return;
        }

        var payload = {};
        $form.find('input, select, textarea').serializeArray().forEach(function(field) {
            setBracketValue(payload, field.name, field.value);
        });

        isSaving = true;
        window.MenuBuilder.clearFieldErrors($form[0]);
        $saveBtn.addClass('disabled').prop('disabled', true);
        $cancelBtn.prop('disabled', true);
        $spinner.removeClass('hidden');
        $body.attr('aria-busy', 'true');

        window.MenuBuilder.request('POST', 'menu-builder/items/save', { data: payload })
            .then(function(response) {
                isDirty = false;
                var callback = currentOnSaved;
                close();
                if (callback) {
                    callback(response.data);
                }
            })
            .catch(function(error) {
                // The save endpoint returns which fields were rejected and
                // why; showing only a banner left the editor to guess, in a
                // form with six collapsible sections.
                var shown = window.MenuBuilder.applyFieldErrors($form[0], window.MenuBuilder.errorsFor(error));

                if (!shown) {
                    window.MenuBuilder.displayError(error, Craft.t('menu-builder', 'Couldn’t save that menu item.'));
                }
            })
            .finally(function() {
                isSaving = false;
                $saveBtn.removeClass('disabled').prop('disabled', false);
                $cancelBtn.prop('disabled', false);
                $spinner.addClass('hidden');
                $body.removeAttr('aria-busy');
            });
    }
})();
