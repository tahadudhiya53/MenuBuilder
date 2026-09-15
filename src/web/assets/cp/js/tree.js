(function($) {
    if (typeof Craft === 'undefined' || typeof Garnish === 'undefined') {
        return;
    }

    // Same guard as menu-builder.js/slideout.js/item-fields.js — whichever file loads first
    // creates the namespace.
    window.MenuBuilder = window.MenuBuilder || {};

    /**
     * Horizontal pixels of mouse movement per indent level, matching Craft's own structure tables.
    */
    var LEVEL_INDENT = 24;

    /**
     * Menu tree controller.
    */
    /**
     * The row-menu actions this controller owns; anything else is someone else's.
    */
    var ROW_ACTIONS = ['edit', 'duplicate', 'toggle', 'delete'];

    var MenuBuilderTree = Garnish.Base.extend({
        $container: null,
        $list: null,
        $liveRegion: null,
        groupId: null,
        groupHandle: null,
        maxDepth: null,
        canEdit: false,
        isFiltered: false,
        sorter: null,
        _pendingMove: null,
        _reloading: false,
        _busyRows: null,

        init: function(container) {
            this.$container = $(container);
            this.$list = this.$container.children('ul.menu-builder-tree-list');
            this.groupId = this.$container.data('group-id');
            this.groupHandle = this.$container.data('group-handle');
            this.maxDepth = this.$container.data('max-depth') || null;
            this.canEdit = this.$container.data('can-edit') == '1';
            // A filtered tree shows a subset in an order that isn't the menu's own, so the sibling
            // list a reorder would post is a list the editor never saw.
            this.isFiltered = this.$container.data('filtered') == '1';
            this._busyRows = {};

            this.syncRails();
            this.initSorter();
            this.initKeyboardReordering();
            this.initLiveRegion();
            this.highlightNewlyAdded();

            // Craft's disclosure-menu JS detaches an open `.menu` from its trigger's original DOM
            // position (it gets appended near `<body>` so it isn't clipped by any ancestor's
            // overflow), so a listener scoped to `$container` would never see clicks on menu items
            // — bind on the document instead and look rows up by id.
            this.addListener(Garnish.$bod, 'click', this.handleClick.bind(this));
        },

        initSorter: function() {
            if (!this.canEdit || this.isFiltered) {
                return;
            }

            var $items = this.$list.children('li.menu-builder-item');

            if ($items.length < 2) {
                return;
            }

            this.sorter = new MenuBuilderTreeSorter(this, $items.toArray());
        },

        level: function($li) {
            return $li.data('level') || 1;
        },

        /**
         * Returns `$item` plus every row after it whose level is deeper (i.e.
        */
        getSubtree: function($item) {
            var level = this.level($item);
            var $subtree = $item;
            var $next = $item.next('li.menu-builder-item');

            while ($next.length && this.level($next) > level) {
                $subtree = $subtree.add($next);
                $next = $next.next('li.menu-builder-item');
            }

            return $subtree;
        },

        /**
         * Walks backward from `$item` to find the nearest shallower row — that row is the parent.
        */
        findParentId: function($item, level) {
            var $scan = $item.prev('li.menu-builder-item');

            while ($scan.length) {
                if (this.level($scan) < level) {
                    return $scan.data('id');
                }
                $scan = $scan.prev('li.menu-builder-item');
            }

            return null;
        },

        /**
         * Collects the ids of every row at `level` that belongs to `newParentId`, in current DOM
         * order.
        */
        getSiblingIds: function(newParentId, level) {
            var ids = [];
            var collecting = newParentId === null;

            this.$list.children('li.menu-builder-item').each(function() {
                var $li = $(this);
                var lvl = $li.data('level') || 1;

                if (newParentId !== null) {
                    if (!collecting) {
                        if (String($li.data('id')) === String(newParentId)) {
                            collecting = true;
                        }
                        return;
                    }

                    if (lvl <= level - 1) {
                        collecting = false;
                        return;
                    }
                }

                if (lvl === level) {
                    ids.push($li.data('id'));
                }
            });

            return ids;
        },

        setLevel: function($li, level) {
            $li
                .data('level', level)
                .attr('data-level', level)
                .css('--mb-level', level);
        },

        shiftLevels: function($subtree, diff) {
            var self = this;
            $subtree.each(function() {
                var $li = $(this);
                self.setLevel($li, self.level($li) + diff);
            });
        },

        persistReorder: function($item) {
            var level = this.level($item);
            var newParentId = this.findParentId($item, level);
            var siblingIds = this.getSiblingIds(newParentId, level);

            this.syncHierarchyMetadata($item, newParentId);
            this.persistMove($item.data('id'), newParentId, siblingIds);
        },

        /**
         * Keeps parent ids and child-count badges in sync without a page reload.
        */
        syncHierarchyMetadata: function($item, newParentId) {
            $item
                .data('parent-id', newParentId || '')
                .attr('data-parent-id', newParentId || '');

            var self = this;
            var $items = this.$list.children('li.menu-builder-item');

            $items.each(function() {
                var $parent = $(this);
                var parentId = String($parent.data('id'));
                var childCount = 0;

                $items.each(function() {
                    var itemParentId = $(this).attr('data-parent-id');
                    if (itemParentId && String(itemParentId) === parentId) {
                        childCount++;
                    }
                });

                $parent
                    .data('child-count', childCount)
                    .attr('data-child-count', childCount)
                    .toggleClass('menu-builder-item-has-children', childCount > 0);
                $parent.find('[data-mb-action="delete"]')
                    .data('has-children', childCount > 0 ? '1' : '0')
                    .attr('data-has-children', childCount > 0 ? '1' : '0');

                var $row = $parent.children('.menu-builder-item-row');
                var $badge = $row.find('.menu-builder-item-childcount');

                if (childCount > 0) {
                    if (!$badge.length) {
                        $badge = $('<span class="menu-builder-item-childcount light"/>')
                            .insertBefore($row.find('.menu-builder-item-actions'));
                    }

                    var label = Craft.t('menubuilder', '{count} child items', { count: childCount });

                    // The badge is a number plus a visually-hidden reading of what it counts (see
                    // dashboard/_items.twig).
                    $badge
                        .empty()
                        .append($('<span aria-hidden="true"/>').text(childCount))
                        .append($('<span class="visually-hidden"/>').text(label))
                        .attr('title', label);
                } else {
                    $badge.remove();
                }

                self.setLevel($parent, self.level($parent));
            });

            this.syncRails();
        },

        /**
         * Rebuilds the hierarchy connector for every row, and is the single authority on it —
         * Twig renders the same thing for first paint, and this runs on init as well as after every
         * move, so any disagreement self-corrects in the same tick.
        */
        syncRails: function() {
            var self = this;
            var hasNext = {};

            this.$list.children('li.menu-builder-item').each(function() {
                var $item = $(this);
                var level = self.level($item);
                var isLast = self.nextSibling($item).length === 0;

                $item.toggleClass('menu-builder-item-last', isLast);

                var markup = '';

                for (var column = 1; column <= level - 2; column++) {
                    if (hasNext[column + 1]) {
                        markup += '<i style="--mb-rail: ' + column + '"></i>';
                    }
                }

                var $rails = $item.children('.menu-builder-item-rails');

                if (!$rails.length) {
                    $rails = $('<span class="menu-builder-item-rails" aria-hidden="true"/>').prependTo($item);
                }

                $rails.html(markup);

                hasNext[level] = !isLast;

                Object.keys(hasNext).forEach(function(openLevel) {
                    if (Number(openLevel) > level) {
                        delete hasNext[openLevel];
                    }
                });
            });
        },

        /**
         * Drags are optimistic — the row sits at its new depth and position before the server has
         * agreed to anything.
        */
        persistMove: function(itemId, newParentId, siblingIds) {
            var self = this;
            var queue = this._pendingMove || Promise.resolve();

            this._pendingMove = queue.then(function() {
                // A reload is already on its way; anything still queued would be posting positions
                // from a page that's going away.
                if (self._reloading) {
                    return;
                }

                return window.MenuBuilder.request('POST', 'menubuilder/items/reorder', {
                    data: {
                        itemId: itemId,
                        groupId: self.groupId,
                        newParentId: newParentId,
                        siblingIds: siblingIds,
                    },
                }).then(function() {
                    window.MenuBuilder.success(Craft.t('menubuilder', 'Menu order updated.'));
                }).catch(function(error) {
                    self._reloading = true;
                    window.MenuBuilder.displayError(error, Craft.t('menubuilder', 'That move isn’t allowed.'));
                    window.location.reload();
                });
            });

            return this._pendingMove;
        },

        /**
         * The min/max levels a subtree of height `subtreeDelta` could occupy between two rows, or
         * `false` if it can't sit there at all (past the group's max depth, or under a row that
         * can't take children).
        */
        levelBounds: function($prevRow, $nextRow, subtreeDelta) {
            subtreeDelta = subtreeDelta || 0;

            var minLevel = ($nextRow && $nextRow.length) ? this.level($nextRow) : 1;
            var maxLevel = ($prevRow && $prevRow.length) ? this.level($prevRow) + 1 : 1;

            if (this.maxDepth) {
                if (minLevel !== 1 && minLevel + subtreeDelta > this.maxDepth) {
                    return false;
                }

                if (maxLevel + subtreeDelta > this.maxDepth) {
                    maxLevel = this.maxDepth - subtreeDelta;

                    if (maxLevel < minLevel) {
                        maxLevel = minLevel;
                    }
                }
            }

            if ($prevRow && $prevRow.length && $prevRow.data('no-children') == '1' && maxLevel > this.level($prevRow)) {
                maxLevel = this.level($prevRow);
            }

            if (maxLevel < minLevel) {
                return false;
            }

            return { min: minLevel, max: maxLevel };
        },

        /**
         * How many levels deeper than `$item` its deepest descendant sits.
        */
        subtreeDelta: function($item) {
            var level = this.level($item);
            var delta = 0;
            var self = this;

            this.getSubtree($item).each(function() {
                delta = Math.max(delta, self.level($(this)) - level);
            });

            return delta;
        },

        /**
         * The row before `$item` at the same level under the same parent, if any.
        */
        prevSibling: function($item) {
            var level = this.level($item);
            var $scan = $item.prev('li.menu-builder-item');

            while ($scan.length) {
                var scanLevel = this.level($scan);

                if (scanLevel < level) {
                    return $();
                }

                if (scanLevel === level) {
                    return $scan;
                }

                $scan = $scan.prev('li.menu-builder-item');
            }

            return $();
        },

        /**
         * The row after `$item`'s subtree at the same level under the same parent, if any.
        */
        nextSibling: function($item) {
            var level = this.level($item);
            var $next = this.getSubtree($item).last().next('li.menu-builder-item');

            if (!$next.length || this.level($next) !== level) {
                return $();
            }

            return $next;
        },

        // Keyboard reordering

        initKeyboardReordering: function() {
            if (!this.canEdit || this.isFiltered) {
                return;
            }

            this.addListener(this.$container, 'keydown', 'a.menu-builder-drag-handle', this.handleHandleKeydown.bind(this));
        },

        handleHandleKeydown: function(event) {
            var $item = $(event.currentTarget).closest('li.menu-builder-item');

            if (!$item.length) {
                return;
            }

            // Cmd/Ctrl/Alt + arrow belongs to the browser (history navigation, word jumps);
            // claiming it would break shortcuts the editor uses everywhere else.
            if (event.metaKey || event.ctrlKey || event.altKey) {
                return;
            }

            var moved;

            switch (event.keyCode) {
                case Garnish.UP_KEY:
                    moved = this.moveUp($item);
                    break;
                case Garnish.DOWN_KEY:
                    moved = this.moveDown($item);
                    break;
                case Garnish.LEFT_KEY:
                    moved = Craft.orientation === 'rtl' ? this.indent($item) : this.outdent($item);
                    break;
                case Garnish.RIGHT_KEY:
                    moved = Craft.orientation === 'rtl' ? this.outdent($item) : this.indent($item);
                    break;
                default:
                    return;
            }

            // Claim the key either way: an unhandled arrow scrolls the page, which reads as "the
            // control did something" when it did not.
            event.preventDefault();

            if (moved) {
                this.afterKeyboardMove($item);
            }
        },

        /**
         * Re-focuses the handle (jQuery's move detaches and re-inserts the row, which drops focus),
         * announces the new position, and persists.
        */
        afterKeyboardMove: function($item) {
            $item.find('a.menu-builder-drag-handle').first().trigger('focus');
            $item.addClass('menu-builder-just-dropped');
            setTimeout(function() {
                $item.removeClass('menu-builder-just-dropped');
            }, 180);

            this.announcePosition($item);
            this.persistReorder($item);
        },

        moveUp: function($item) {
            var $prev = this.prevSibling($item);

            if (!$prev.length) {
                return false;
            }

            this.getSubtree($item).insertBefore($prev);

            return true;
        },

        moveDown: function($item) {
            var $next = this.nextSibling($item);

            if (!$next.length) {
                return false;
            }

            this.getSubtree($item).insertAfter(this.getSubtree($next).last());

            return true;
        },

        /**
         * Makes the row the last child of the sibling above it, if that's admissible.
        */
        indent: function($item) {
            var $prev = this.prevSibling($item);

            if (!$prev.length) {
                return false;
            }

            // The row already sits immediately after its previous sibling's subtree, so nesting is
            // purely a level change — no DOM move.
            var bounds = this.levelBounds($prev, this.getSubtree($item).last().next('li.menu-builder-item'), this.subtreeDelta($item));

            if (!bounds || bounds.max < this.level($item) + 1) {
                this.announce(Craft.t('menubuilder', 'Can’t nest this item any deeper here.'));

                return false;
            }

            this.shiftLevels(this.getSubtree($item), 1);

            return true;
        },

        /**
         * Lifts the row out to sit after its parent, one level shallower.
        */
        outdent: function($item) {
            var level = this.level($item);

            if (level <= 1) {
                return false;
            }

            var parentId = this.findParentId($item, level);
            var $parent = this.$list.children('li.menu-builder-item[data-id="' + parentId + '"]');

            if (!$parent.length) {
                return false;
            }

            var $subtree = this.getSubtree($item);
            var $parentSubtreeEnd = this.getSubtree($parent).last();

            if ($subtree.last()[0] !== $parentSubtreeEnd[0]) {
                $subtree.insertAfter($parentSubtreeEnd);
            }

            this.shiftLevels($subtree, -1);

            return true;
        },

        initLiveRegion: function() {
            if (!this.canEdit || this.isFiltered) {
                return;
            }

            this.$liveRegion = $('<p class="visually-hidden" role="status" aria-live="polite"></p>')
                .appendTo(this.$container);
        },

        announce: function(message) {
            if (this.$liveRegion) {
                this.$liveRegion.text(message);
            }
        },

        /**
         * "Item 3 of 7, level 2" — the feedback a sighted editor gets from the row simply moving.
        */
        announcePosition: function($item) {
            var level = this.level($item);
            var parentId = this.findParentId($item, level);
            var siblingIds = this.getSiblingIds(parentId, level).map(String);
            var position = siblingIds.indexOf(String($item.data('id'))) + 1;

            this.announce(Craft.t('menubuilder', '{title}: item {position} of {total}, level {level}.', {
                title: $item.data('title') || '',
                position: position,
                total: siblingIds.length,
                level: level,
            }));
        },

        /**
         * The quick-add panel appends new items at the end of their level, which in a long menu is
         * well past the fold.
        */
        highlightNewlyAdded: function() {
            var params = new URLSearchParams(window.location.search);
            var highlightId = params.get('mb-highlight');
            var notice = params.get('mb-notice');
            // Parsed as a number, never echoed as text — see the note on the message map below.
            var count = Math.max(1, parseInt(params.get('mb-count'), 10) || 1);

            if (!highlightId && !notice) {
                return;
            }

            // Drop the markers so a refresh (or a shared URL) doesn't replay them, and so nothing
            // but the plain menu URL stays in the bar.
            params.delete('mb-highlight');
            params.delete('mb-notice');
            params.delete('mb-count');
            var query = params.toString();
            window.history.replaceState({}, '', window.location.pathname + (query ? '?' + query : ''));

            var $item = highlightId
                ? this.$container.find('li.menu-builder-item[data-id="' + highlightId + '"]')
                : $();

            // A fixed key → message map, never text carried in the URL: these are rendered as CP
            // notifications, so a link must not be able to put words into one.
            var title = $item.length ? ($item.data('title') || '') : '';
            var messages = {
                added: Craft.t('menubuilder', '“{title}” added.', { title: title }),
                duplicated: Craft.t('menubuilder', '“{title}” duplicated.', { title: title }),
                saved: Craft.t('menubuilder', 'Menu item saved.'),
                deleted: Craft.t('menubuilder', '{count} menu item(s) deleted.', { count: count }),
            };

            if (messages[notice]) {
                window.MenuBuilder.success(messages[notice]);
            }

            if (!$item.length) {
                return;
            }

            $item[0].scrollIntoView({ block: 'center' });
            $item.addClass('menu-builder-item-added');

            setTimeout(function() {
                $item.removeClass('menu-builder-item-added');
            }, 2500);
        },

        /**
         * Reloads the dashboard carrying what happened, so the confirmation survives.
         *
         * @param {String} notice one of the keys highlightNewlyAdded() knows
         * @param {Number|String} [highlightId] a row to scroll to and flag
         * @param {Number} [count] how many items the notice is about
        */
        reloadWith: function(notice, highlightId, count) {
            this._reloading = true;

            var url = new URL(window.location.href);
            url.searchParams.set('mb-notice', notice);

            if (highlightId) {
                url.searchParams.set('mb-highlight', highlightId);
            } else {
                url.searchParams.delete('mb-highlight');
            }

            if (count) {
                url.searchParams.set('mb-count', count);
            } else {
                url.searchParams.delete('mb-count');
            }

            window.location = url.toString();
        },

        /**
         * Row actions are one request each, and none of them was guarded — a second click while
         * the first was still in flight duplicated an item twice, or fired two toggles that raced
         * each other.
        */
        withRowBusy: function(id, run) {
            if (this._busyRows[id]) {
                return;
            }

            var self = this;
            var $item = this.$container.find('li.menu-builder-item[data-id="' + id + '"]');

            this._busyRows[id] = true;
            $item.addClass('menu-builder-item-busy').attr('aria-busy', 'true');

            var done = function() {
                delete self._busyRows[id];
                $item.removeClass('menu-builder-item-busy').removeAttr('aria-busy');
            };

            var result = run();

            if (result && typeof result.then === 'function') {
                result.then(done, done);
            } else {
                done();
            }
        },

        /**
         * Ancestor rails for the transient drop slot, so the columns it sits inside carry on
         * through it instead of breaking for its height.
         *
         * @return {Number[]} column numbers, ascending
        */
        insertionRails: function($insertion, level) {
            var $prev = $insertion.prevAll('li.menu-builder-item').first();

            if (!$prev.length || level < 3) {
                return [];
            }

            var deepest = level - 2;
            var columns = $prev.children('.menu-builder-item-rails').children('i').toArray()
                .map(function(rail) {
                    return parseInt(rail.style.getPropertyValue('--mb-rail'), 10);
                })
                .filter(function(column) {
                    return column <= deepest;
                });

            var prevColumn = this.level($prev) - 1;

            if (prevColumn >= 1 && prevColumn <= deepest && this.nextSibling($prev).length) {
                columns.push(prevColumn);
            }

            return columns.sort(function(a, b) {
                return a - b;
            });
        },

        handleClick: function(event) {
            var $target = $(event.target).closest('[data-mb-action]');

            if (!$target.length) {
                return;
            }

            var action = $target.data('mb-action');

            // The listener is document-wide (see init()), so it also sees `data-mb-action` links
            // this controller doesn't own — the dashboard template's own quick-add trigger, for
            // one.
            if (ROW_ACTIONS.indexOf(action) === -1) {
                return;
            }

            event.preventDefault();

            var id = $target.data('id');

            var self = this;

            switch (action) {
                case 'edit':
                    this.editItem(id);
                    break;
                case 'duplicate':
                    this.withRowBusy(id, function() {
                        return self.duplicate(id);
                    });
                    break;
                case 'toggle':
                    this.withRowBusy(id, function() {
                        return self.toggle(id);
                    });
                    break;
                case 'delete':
                    this.remove(id, $target.data('title'), $target.data('has-children') == '1');
                    break;
            }
        },

        editItem: function(id) {
            var self = this;

            window.MenuBuilder.openItemSlideout({ groupHandle: this.groupHandle, itemId: id }, function() {
                // A saved item can change its own title, type badge and enabled state, so the row
                // is re-rendered by the server rather than patched — and the reload carries both
                // the confirmation and the row to scroll back to.
                self.reloadWith('saved', id);
            });
        },

        /**
         * The single place a row's enabled state is reflected in the UI — called by the row
         * menu's toggle and by the dashboard's bulk enable/disable, so the two can't drift apart.
        */
        setRowEnabled: function(id, enabled) {
            var $item = this.$container.find('li.menu-builder-item[data-id="' + id + '"]');

            if (!$item.length) {
                return;
            }

            var $row = $item.children('.menu-builder-item-row');

            $item.toggleClass('menu-builder-item-disabled', !enabled);

            // Scoped to this badge's own class — `.menu-builder-item-status` alone would also
            // match the mega-menu and link-health badges, removing them on enable and suppressing
            // this one on disable whenever either was already present.
            var $flag = $row.find('.menu-builder-item-disabled-flag');

            if (enabled) {
                $flag.remove();
            } else if (!$flag.length) {
                $('<span class="menu-builder-item-status menu-builder-item-disabled-flag badge disabled-badge">' + Craft.t('menubuilder', 'Disabled') + '</span>')
                    .insertAfter($row.find('.menu-builder-item-type'));
            }

            // The row menu itself may be detached (see above), so reach its Disable/Enable entry by
            // id rather than through the row.
            Garnish.$bod.find('[data-mb-action="toggle"][data-id="' + id + '"]')
                .text(enabled ? Craft.t('menubuilder', 'Disable') : Craft.t('menubuilder', 'Enable'));
        },

        toggle: function(id) {
            var self = this;

            return window.MenuBuilder.request('POST', 'menubuilder/items/toggle', { data: { id: id } })
                .then(function(response) {
                    var enabled = !!(response.data && response.data.enabled);
                    self.setRowEnabled(id, enabled);
                    // "Updated." said nothing about which way it went, which matters most for the
                    // one editor who can't see the row's badge change.
                    window.MenuBuilder.success(enabled
                        ? Craft.t('menubuilder', 'Menu item enabled.')
                        : Craft.t('menubuilder', 'Menu item disabled.'));
                })
                .catch(function(error) {
                    window.MenuBuilder.displayError(error, Craft.t('menubuilder', 'Couldn’t update that menu item.'));
                });
        },

        duplicate: function(id) {
            var self = this;

            return window.MenuBuilder.request('POST', 'menubuilder/items/duplicate', { data: { id: id } })
                .then(function(response) {
                    // The copy lands at the end of its level; reload straight to it rather than
                    // leaving the editor to find it.
                    self.reloadWith('duplicated', response.data && response.data.id);
                })
                .catch(function(error) {
                    window.MenuBuilder.displayError(error, Craft.t('menubuilder', 'Couldn’t duplicate that menu item.'));
                });
        },

        remove: function(id, title, hasChildren) {
            if (!hasChildren) {
                if (!confirm(Craft.t('menubuilder', 'Delete “{title}”? This cannot be undone.', { title: title }))) {
                    return;
                }

                this.performDelete(id, false);

                return;
            }

            this.showDeleteChoiceModal(id, title, this.childCountOf(id));
        },

        /**
         * Direct children of a row, from the DOM the tree already keeps in sync.
        */
        childCountOf: function(id) {
            return parseInt(this.$container
                .find('li.menu-builder-item[data-id="' + id + '"]')
                .attr('data-child-count'), 10) || 0;
        },

        /**
         * Deleting a parent has to be an explicit, non-destructive-by-default choice — and a
         * choice between three outcomes, which a native confirm() can't offer.
        */
        showDeleteChoiceModal: function(id, title, childCount) {
            var self = this;

            var $modal = $(
                '<div class="modal fitted menu-builder-delete-modal">' +
                    '<div class="body"></div>' +
                    '<div class="footer">' +
                        '<div class="buttons right"></div>' +
                    '</div>' +
                '</div>'
            ).appendTo(Garnish.$bod);

            $('<h2></h2>')
                .text(Craft.t('menubuilder', 'Delete “{title}”?', { title: title }))
                .appendTo($modal.find('.body'));
            $('<p></p>')
                .text(Craft.t('menubuilder', 'This item has {count} child item(s). Choose what happens to them. This cannot be undone.', { count: childCount }))
                .appendTo($modal.find('.body'));

            var $buttons = $modal.find('.buttons');
            var $cancel = $('<button type="button" class="btn"></button>')
                .text(Craft.t('app', 'Cancel'))
                .appendTo($buttons);
            var $keep = $('<button type="button" class="btn"></button>')
                .text(Craft.t('menubuilder', 'Keep children'))
                .attr('title', Craft.t('menubuilder', 'The children move up one level.'))
                .appendTo($buttons);
            var $deleteAll = $('<button type="button" class="btn submit delete"></button>')
                .text(Craft.t('menubuilder', 'Delete all {count}', { count: childCount + 1 }))
                .appendTo($buttons);

            var modal = new Garnish.Modal($modal, {
                onHide: function() {
                    // Garnish keeps a hidden modal in the DOM; this one is built per delete, so it
                    // is thrown away with its buttons.
                    setTimeout(function() {
                        $modal.remove();
                    }, 0);
                },
            });

            $cancel.on('click', function() {
                modal.hide();
            });

            $keep.on('click', function() {
                modal.hide();
                self.performDelete(id, true);
            });

            $deleteAll.on('click', function() {
                modal.hide();
                self.performDelete(id, false);
            });

            $keep.trigger('focus');
        },

        performDelete: function(id, keepChildren) {
            var self = this;

            return this.withRowBusy(id, function() {
                return window.MenuBuilder.request('POST', 'menubuilder/items/delete', { data: { id: id, keepChildren: keepChildren ? 1 : 0 } })
                    .then(function() {
                        // Deleting changes child counts, connector lines and sort order across the
                        // tree, so the server's answer is the only trustworthy one.
                        self.reloadWith('deleted');
                    })
                    .catch(function(error) {
                        window.MenuBuilder.displayError(error, Craft.t('menubuilder', 'Couldn’t delete that menu item.'));
                    });
            });
        },

    });

    /**
     * Drag-to-sort/reparent, adapted from Craft's own Craft.ElementTableSorter (the class behind
     * every structure section and the Navigation plugin's node listing): dragging vertically
     * reorders, dragging the helper horizontally changes how deeply the row (and any descendants
     * moving with it) is nested.
    */
    var MenuBuilderTreeSorter = Garnish.DragSort.extend({
        tree: null,
        _draggeeLevel: null,
        _draggeeLevelDelta: null,
        _targetLevel: null,
        _targetLevelBounds: null,
        _insertionPreviousTop: null,
        _insertionRailsMarkup: null,

        init: function(tree, items) {
            this.tree = tree;

            this.base(items, {
                handle: '.menu-builder-drag-handle',
                axis: Garnish.Y_AXIS,
                container: tree.$list,
                removeDraggee: true,
                singleHelper: true,
                helper: this.getHelper.bind(this),
                insertion: this.getInsertion.bind(this),
                helperOpacity: 0,
                helperLagBase: 1.5,
                helperSpacingY: 2,
                magnetStrength: 4,
                canInsertBefore: this.canInsertBefore.bind(this),
                canInsertAfter: this.canInsertAfter.bind(this),
            });
        },

        getHelper: function($helper) {
            return $('<ul class="menu-builder-tree-list menu-builder-draghelper"/>').append($helper);
        },

        /**
         * The destination slot is the preview, so restore the hidden real row without a helper
         * animation.
        */
        returnHelpersToDraggees: function() {
            this._returningHelpersToDraggees = true;

            if (this.helpers) {
                for (var i = 0; i < this.helpers.length; i++) {
                    this.helpers[i].remove();
                }
            }

            this.helpers = null;
            var $droppedItems = this.$draggee;

            $droppedItems
                .show()
                .css({ display: this.draggeeDisplay, visibility: '' })
                .addClass('menu-builder-just-dropped');
            setTimeout(function() {
                $droppedItems.removeClass('menu-builder-just-dropped');
            }, 180);
            this.onReturnHelpersToDraggees();
            this._returningHelpersToDraggees = false;
        },

        /**
         * Moves the complete floating card to the indentation represented by the destination slot.
        */
        getHelperTargetX: function() {
            var targetX = this.base();
            var levelDiff = (this._targetLevel || this._draggeeLevel) - this._draggeeLevel;
            var offset = levelDiff * LEVEL_INDENT;

            if (Craft.orientation === 'rtl') {
                offset *= -1;
            }

            return targetX + offset;
        },

        /**
         * A visible destination slot, separate from the floating drag helper.
        */
        getInsertion: function() {
            return $('<li class="menu-builder-drop-position" aria-hidden="true">' +
                '<div class="menu-builder-drop-position-inner">' +
                '<span class="menu-builder-drop-position-icon">&#8627;</span>' +
                '<span class="menu-builder-drop-position-label"></span>' +
                '</div>' +
                '</li>')
                .attr('data-level', this._draggeeLevel)
                .css('--mb-level', this._draggeeLevel)
                .find('.menu-builder-drop-position-label')
                .text(Craft.t('menubuilder', 'Drop here'))
                .end();
        },

        /**
         * Draggee = the dragged row plus every deeper row after it (its descendants).
        */
        findDraggee: function() {
            var draggeeLevel = this.tree.level(this.$targetItem);
            var $draggee = $(this.$targetItem);
            var $next = this.$targetItem.next();
            var maxDelta = 0;

            while ($next.length) {
                var nextLevel = this.tree.level($next);

                if (nextLevel <= draggeeLevel) {
                    break;
                }

                maxDelta = Math.max(maxDelta, nextLevel - draggeeLevel);
                $draggee = $draggee.add($next);
                $next = $next.next();
            }

            this._draggeeLevel = this._targetLevel = draggeeLevel;
            this._draggeeLevelDelta = maxDelta;

            return $draggee;
        },

        /**
         * Delegates to the tree's single implementation — see MenuBuilderTree.levelBounds().
        */
        getLevelBounds: function($prevRow, $nextRow) {
            return this.tree.levelBounds($prevRow, $nextRow, this._draggeeLevelDelta);
        },

        setTargetLevelBounds: function() {
            this._targetLevelBounds = this.getLevelBounds(
                this.$draggee.first().prevAll('li.menu-builder-item').first(),
                this.$draggee.last().nextAll('li.menu-builder-item').first()
            );
        },

        canInsertBefore: function($item) {
            return this.getLevelBounds($item.prev(), $item) !== false;
        },

        canInsertAfter: function($item) {
            return this.getLevelBounds($item, $item.next()) !== false;
        },

        onDragStart: function() {
            this.setTargetLevelBounds();
            Garnish.$bod.addClass('menu-builder-is-dragging');
            this.base();
        },

        onDrag: function() {
            this.rememberInsertionPosition();
            this.base();
            this.updateIndent();
        },

        onInsertionPointChange: function() {
            this.setTargetLevelBounds();
            this.updateIndent();
            this.animateInsertionMove();
            this.base();
        },

        rememberInsertionPosition: function() {
            if (this.$insertion && this.$insertion.length && this.$insertion.is(':visible')) {
                this._insertionPreviousTop = this.$insertion.offset().top;
            }
        },

        animateInsertionMove: function(previousTop) {
            if ((Garnish.prefersReducedMotion && Garnish.prefersReducedMotion()) ||
                !this.$insertion || !this.$insertion.length || !this.$insertion[0].animate) {
                this._insertionPreviousTop = null;
                return;
            }

            previousTop = previousTop == null ? this._insertionPreviousTop : previousTop;
            this._insertionPreviousTop = null;

            if (previousTop == null) {
                return;
            }

            var distance = previousTop - this.$insertion.offset().top;

            if (Math.abs(distance) < 2) {
                return;
            }

            if (this.$insertion[0].getAnimations) {
                this.$insertion[0].getAnimations().forEach(function(animation) {
                    animation.cancel();
                });
            }
            this.$insertion[0].animate([
                { transform: 'translateY(' + distance + 'px)' },
                { transform: 'translateY(0)' },
            ], {
                duration: 140,
                easing: 'cubic-bezier(0.2, 0, 0, 1)',
            });
        },

        /**
         * Reads how far the mouse has moved horizontally since mousedown and turns that into a
         * target level.
        */
        updateIndent: function() {
            var mouseDist = this.realMouseX - this.mousedownX;

            if (Craft.orientation === 'rtl') {
                mouseDist *= -1;
            }

            var targetLevel = this._draggeeLevel + Math.round(mouseDist / LEVEL_INDENT);

            if (this._targetLevelBounds) {
                if (targetLevel < this._targetLevelBounds.min) {
                    targetLevel = this._targetLevelBounds.min;
                } else if (targetLevel > this._targetLevelBounds.max) {
                    targetLevel = this._targetLevelBounds.max;
                }
            }

            this._targetLevel = targetLevel;
            this.updateHelperLevel(targetLevel);

            if (this.$insertion && this.$insertion.length) {
                this.$insertion
                    .attr('data-level', targetLevel)
                    .css('--mb-level', targetLevel);

                this.updateInsertionRails(targetLevel);
            }

        },

        /**
         * Keeps the drop slot's ancestor rails in step with where it currently sits.
        */
        updateInsertionRails: function(targetLevel) {
            var markup = this.tree.insertionRails(this.$insertion, targetLevel)
                .map(function(column) {
                    return '<i style="--mb-rail: ' + column + '"></i>';
                })
                .join('');

            // updateIndent() runs on every pointer move; only touch the DOM when the answer
            // actually changed.
            if (markup === this._insertionRailsMarkup) {
                return;
            }

            this._insertionRailsMarkup = markup;

            var $rails = this.$insertion.children('.menu-builder-item-rails');

            if (!$rails.length) {
                $rails = $('<span class="menu-builder-item-rails" aria-hidden="true"/>').prependTo(this.$insertion);
            }

            $rails.html(markup);
        },

        updateHelperLevel: function(targetLevel) {
            if (this.helpers && this.helpers[0]) {
                var levelDiff = targetLevel - this._draggeeLevel;

                this.helpers[0].find('li.menu-builder-item').first()
                    .attr('data-level', targetLevel)
                    .css('--mb-level', targetLevel)
                    .outerWidth(Math.max(160, this.targetItemWidth - levelDiff * LEVEL_INDENT));
            }
        },

        onDragStop: function() {
            Garnish.$bod.removeClass('menu-builder-is-dragging');

            var levelDiff = this._targetLevel - this._draggeeLevel;

            if (levelDiff !== 0) {
                this.tree.shiftLevels(this.$draggee, levelDiff);
            }

            // Apply the final level before the helper return animation so it lands at the exact
            // indentation shown by the destination slot.
            this.base();
            this.tree.persistReorder(this.$draggee.first());
        },
    });

    Garnish.$doc.ready(function() {
        var container = document.getElementById('menu-builder-tree');

        if (container) {
            // Exposed so the dashboard's bulk toolbar can reuse setRowEnabled() instead of
            // reimplementing it.
            window.MenuBuilder.tree = new MenuBuilderTree(container);
        }
    });
})(jQuery);
