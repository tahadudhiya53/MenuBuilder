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
     * whatever's already open (after confirming unsaved changes, spec §22).
     */
    window.MenuBuilder = window.MenuBuilder || {};

    var $shade, $container, $panel, $header, $title, $closeBtn, $body, $footer, $saveBtn, $spinner;
    var isOpen = false;
    var isDirty = false;
    var currentOnSaved = null;

    function build() {
        if ($panel) {
            return;
        }

        $shade = $('<div class="slideout-shade menu-builder-slideout-shade"></div>').appendTo(Garnish.$bod);
        $container = $('<div class="slideout-container"></div>').appendTo(Garnish.$bod);
        $panel = $('<div class="slideout menu-builder-slideout" id="menu-builder-slideout" role="dialog" aria-modal="true" tabindex="-1"></div>').appendTo($container);

        $header = $('<div class="menu-builder-slideout-header"></div>').appendTo($panel);
        $title = $('<h1></h1>').appendTo($header);
        $closeBtn = $('<button type="button" class="btn icon" data-icon="remove"></button>')
            .attr('aria-label', Craft.t('app', 'Close'))
            .appendTo($header);

        $body = $('<div class="menu-builder-slideout-body"></div>').appendTo($panel);

        $footer = $('<div class="menu-builder-slideout-footer flex"></div>').appendTo($panel);
        var $cancelBtn = $('<button type="button" class="btn">' + Craft.t('app', 'Cancel') + '</button>').appendTo($footer);
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
    }

    function requestClose() {
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
    }

    /**
     * @param {Object} params `groupHandle` plus either `itemId` or `parentId`.
     * @param {Function} [onSaved] called with the save response's data once the item is saved.
     */
    window.MenuBuilder.openItemSlideout = function(params, onSaved) {
        build();

        if (isOpen && isDirty && !confirm(Craft.t('menu-builder', 'You have unsaved changes. Leave without saving?'))) {
            return;
        }

        isOpen = true;
        isDirty = false;
        currentOnSaved = onSaved || null;
        $panel.addClass('is-open');
        $shade.addClass('is-open');
        Garnish.$bod.addClass('menu-builder-slideout-open');
        $body.html('<div class="spinner"></div>');
        $panel.attr('data-group-handle', params.groupHandle);
        $panel.focus();

        window.MenuBuilder.request('GET', 'menu-builder/items/edit', { params: params })
            .then(function(response) {
                $title.text(response.data.title);
                $body.html(response.data.html);

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

                isDirty = false;
            })
            .catch(function() {
                $body.html('<p class="error">' + Craft.t('menu-builder', 'Couldn’t load that menu item.') + '</p>');
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

        if (!$form.length) {
            return;
        }

        var payload = {};
        $form.find('input, select, textarea').serializeArray().forEach(function(field) {
            setBracketValue(payload, field.name, field.value);
        });

        $saveBtn.addClass('disabled').prop('disabled', true);
        $spinner.removeClass('hidden');

        window.MenuBuilder.request('POST', 'menu-builder/items/save', { data: payload })
            .then(function(response) {
                isDirty = false;
                window.MenuBuilder.success(Craft.t('menu-builder', 'Menu item saved.'));
                var callback = currentOnSaved;
                close();
                if (callback) {
                    callback(response.data);
                }
            })
            .catch(function(error) {
                window.MenuBuilder.displayError(error, Craft.t('menu-builder', 'Couldn’t save that menu item.'));
            })
            .finally(function() {
                $saveBtn.removeClass('disabled').prop('disabled', false);
                $spinner.addClass('hidden');
            });
    }
})();
