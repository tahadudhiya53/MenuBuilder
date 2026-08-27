(function($) {
    if (typeof Craft === 'undefined' || typeof Garnish === 'undefined') {
        return;
    }

    // Same guard as menu-builder.js/slideout.js/item-fields.js — whichever
    // file loads first creates the namespace.
    window.MenuBuilder = window.MenuBuilder || {};

    /** Horizontal pixels of mouse movement per indent level, matching Craft's own structure tables. */
    var LEVEL_INDENT = 24;

    /**
     * Menu tree controller. The tree is rendered as a single FLAT list of
     * `<li>` rows (each carrying its own `data-level`), exactly like Craft's
     * native Structure element tables (see Craft.ElementTableSorter) —
     * indentation is cosmetic (CSS padding), not real DOM nesting. A single
     * Garnish.DragSort (MenuBuilderTreeSorter) reorders vertically and
     * reparents by dragging horizontally, the same interaction the CP's own
     * Navigation node listing uses. That horizontal drag is the ONLY way to
     * reparent, just as drag/drop is the only way to reorder — there is
     * deliberately no duplicate up/down/indent/outdent command set and no
     * second drop-onto-a-row gesture. All server calls re-validate depth/
     * circularity/cross-group — the client never trusts itself as the source
     * of truth.
     */
    /** The row-menu actions this controller owns; anything else is someone else's. */
    var ROW_ACTIONS = ['edit', 'duplicate', 'toggle', 'delete'];

    var MenuBuilderTree = Garnish.Base.extend({
        $container: null,
        $list: null,
        groupId: null,
        groupHandle: null,
        maxDepth: null,
        canEdit: false,
        sorter: null,
        _pendingMove: null,
        _reloading: false,

        init: function(container) {
            this.$container = $(container);
            this.$list = this.$container.children('ul.menu-builder-tree-list');
            this.groupId = this.$container.data('group-id');
            this.groupHandle = this.$container.data('group-handle');
            this.maxDepth = this.$container.data('max-depth') || null;
            this.canEdit = this.$container.data('can-edit') == '1';

            this.initSorter();

            // Craft's disclosure-menu JS detaches an open `.menu` from its
            // trigger's original DOM position (it gets appended near
            // `<body>` so it isn't clipped by any ancestor's overflow), so a
            // listener scoped to `$container` would never see clicks on menu
            // items — bind on the document instead and look rows up by id.
            this.addListener(Garnish.$bod, 'click', this.handleClick.bind(this));
        },

        initSorter: function() {
            if (!this.canEdit) {
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

        /** Returns `$item` plus every row after it whose level is deeper (i.e. its descendants). */
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

        /** Walks backward from `$item` to find the nearest shallower row — that row is the parent. */
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

        /** Collects the ids of every row at `level` that belongs to `newParentId`, in current DOM order. */
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

        /** Keeps parent ids and child-count badges in sync without a page reload. */
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

                    $badge
                        .text(childCount)
                        .attr('title', Craft.t('menu-builder', '{count} child items', { count: childCount }));
                } else {
                    $badge.remove();
                }

                self.setLevel($parent, self.level($parent));
            });

            this.syncLastSiblingFlags();
        },

        /**
         * Recomputes which rows are the last child at their level (used by the
         * CSS connector lines to decide whether a row's rail elbows off or
         * continues straight into the next sibling's rail). Twig computes this
         * via `loop.last` on initial render, but nothing kept it in sync after
         * a client-side drag — leaving stale lines until the page was
         * reloaded.
         */
        syncLastSiblingFlags: function() {
            var $items = this.$list.children('li.menu-builder-item');
            var self = this;

            $items.each(function(index) {
                var $item = $(this);
                var $next = $item.next('li.menu-builder-item');
                var isLast = !$next.length || self.level($next) < self.level($item);

                $item.toggleClass('menu-builder-item-last', isLast);
            });
        },

        /**
         * Drags are optimistic — the row sits at its new depth and position
         * before the server has agreed to anything. Two rules keep that
         * honest.
         *
         * Requests are chained, never fired in parallel: a second drag begun
         * while the first is still in flight would otherwise race it, and
         * the server would resolve both against the same stale sibling
         * order. And a refused move reloads the page rather than trying to
         * invert the drag locally — after a rejection the server is the only
         * thing that knows the real tree, and the rejection message (which
         * rule was broken, straight from the service) is shown first.
         */
        persistMove: function(itemId, newParentId, siblingIds) {
            var self = this;
            var queue = this._pendingMove || Promise.resolve();

            this._pendingMove = queue.then(function() {
                // A reload is already on its way; anything still queued
                // would be posting positions from a page that's going away.
                if (self._reloading) {
                    return;
                }

                return window.MenuBuilder.request('POST', 'menu-builder/items/reorder', {
                    data: {
                        itemId: itemId,
                        groupId: self.groupId,
                        newParentId: newParentId,
                        siblingIds: siblingIds,
                    },
                }).then(function() {
                    window.MenuBuilder.success(Craft.t('menu-builder', 'Menu order updated.'));
                }).catch(function(error) {
                    self._reloading = true;
                    window.MenuBuilder.displayError(error, Craft.t('menu-builder', 'That move isn’t allowed.'));
                    window.location.reload();
                });
            });

            return this._pendingMove;
        },

        handleClick: function(event) {
            var $target = $(event.target).closest('[data-mb-action]');

            if (!$target.length) {
                return;
            }

            var action = $target.data('mb-action');

            // The listener is document-wide (see init()), so it also sees
            // `data-mb-action` links this controller doesn't own — the
            // dashboard template's own quick-add trigger, for one. Leave
            // those entirely alone, default behaviour included.
            if (ROW_ACTIONS.indexOf(action) === -1) {
                return;
            }

            event.preventDefault();

            var id = $target.data('id');

            switch (action) {
                case 'edit':
                    this.editItem(id);
                    break;
                case 'duplicate':
                    this.duplicate(id);
                    break;
                case 'toggle':
                    this.toggle(id);
                    break;
                case 'delete':
                    this.remove(id, $target.data('title'), $target.data('has-children') == '1');
                    break;
            }
        },

        editItem: function(id) {
            window.MenuBuilder.openItemSlideout({ groupHandle: this.groupHandle, itemId: id }, function() {
                window.location.reload();
            });
        },

        /**
         * The single place a row's enabled state is reflected in the UI —
         * called by the row menu's toggle and by the dashboard's bulk
         * enable/disable, so the two can't drift apart.
         *
         * Rows are looked up by id, NOT via the clicked element's ancestors:
         * Craft moves an open disclosure `.menu` out to near <body>, so the
         * clicked <a> has no `li.menu-builder-item` ancestor any more (same
         * reason init() binds its click listener document-wide). Deriving the
         * row from the anchor silently yielded an empty set, which is why the
         * status used to update only after a page reload.
         */
        setRowEnabled: function(id, enabled) {
            var $item = this.$container.find('li.menu-builder-item[data-id="' + id + '"]');

            if (!$item.length) {
                return;
            }

            var $row = $item.children('.menu-builder-item-row');

            $item.toggleClass('menu-builder-item-disabled', !enabled);

            // Scoped to this badge's own class — `.menu-builder-item-status`
            // alone would also match the mega-menu and orphaned-element
            // badges, removing them on enable and suppressing this one on
            // disable whenever either was already present.
            var $flag = $row.find('.menu-builder-item-disabled-flag');

            if (enabled) {
                $flag.remove();
            } else if (!$flag.length) {
                $('<span class="menu-builder-item-status menu-builder-item-disabled-flag badge disabled-badge">' + Craft.t('menu-builder', 'Disabled') + '</span>')
                    .insertAfter($row.find('.menu-builder-item-type'));
            }

            // The row menu itself may be detached (see above), so reach its
            // Disable/Enable entry by id rather than through the row.
            Garnish.$bod.find('[data-mb-action="toggle"][data-id="' + id + '"]')
                .text(enabled ? Craft.t('menu-builder', 'Disable') : Craft.t('menu-builder', 'Enable'));
        },

        toggle: function(id) {
            var self = this;

            window.MenuBuilder.request('POST', 'menu-builder/items/toggle', { data: { id: id } })
                .then(function(response) {
                    self.setRowEnabled(id, !!(response.data && response.data.enabled));
                    window.MenuBuilder.success(Craft.t('menu-builder', 'Updated.'));
                })
                .catch(function(error) {
                    window.MenuBuilder.displayError(error, Craft.t('menu-builder', 'Couldn’t update that menu item.'));
                });
        },

        duplicate: function(id) {
            window.MenuBuilder.request('POST', 'menu-builder/items/duplicate', { data: { id: id } })
                .then(function() {
                    window.MenuBuilder.success(Craft.t('menu-builder', 'Menu item duplicated.'));
                    window.location.reload();
                })
                .catch(function(error) {
                    window.MenuBuilder.displayError(error, Craft.t('menu-builder', 'Couldn’t duplicate that menu item.'));
                });
        },

        remove: function(id, title, hasChildren) {
            if (!hasChildren) {
                if (!confirm(Craft.t('menu-builder', 'Delete “{title}”? This cannot be undone.', { title: title }))) {
                    return;
                }
                this.performDelete(id, false);
                return;
            }

            this.confirmDeleteWithChildren(id, title);
        },

        /** Spec §16 — deleting a parent must be an explicit, non-destructive-by-default choice. */
        confirmDeleteWithChildren: function(id, title) {
            var self = this;

            window.MenuBuilder.request('POST', 'menu-builder/items/delete', { data: { id: id } })
                .then(function(response) {
                    var childCount = (response.data && response.data.childCount) || 0;
                    self.showDeleteChoiceModal(id, title, childCount);
                })
                .catch(function(error) {
                    window.MenuBuilder.displayError(error, Craft.t('menu-builder', 'Couldn’t delete that menu item.'));
                });
        },

        showDeleteChoiceModal: function(id, title, childCount) {
            var self = this;
            var message = Craft.t('menu-builder', 'Delete “{title}”?', { title: title }) +
                '\n\n' + Craft.t('menu-builder', 'This item has {count} child item(s).', { count: childCount }) +
                '\n\n' + Craft.t('menu-builder', 'OK = delete the item and its children. Cancel, then choose below to keep the children.');

            // A native confirm() can only offer two choices, so it is used to
            // gate the destructive option; a second prompt offers the safe one.
            if (confirm(message)) {
                this.performDelete(id, false);
                return;
            }

            if (confirm(Craft.t('menu-builder', 'Delete “{title}” but keep its {count} child item(s) (they move up one level)?', { title: title, count: childCount }))) {
                self.performDelete(id, true);
            }
        },

        performDelete: function(id, keepChildren) {
            window.MenuBuilder.request('POST', 'menu-builder/items/delete', { data: { id: id, keepChildren: keepChildren ? 1 : 0 } })
                .then(function() {
                    window.MenuBuilder.success(Craft.t('menu-builder', 'Menu item deleted.'));
                    window.location.reload();
                })
                .catch(function(error) {
                    window.MenuBuilder.displayError(error, Craft.t('menu-builder', 'Couldn’t delete that menu item.'));
                });
        },

    });

    /**
     * Drag-to-sort/reparent, adapted from Craft's own Craft.ElementTableSorter
     * (the class behind every structure section and the Navigation plugin's
     * node listing): dragging vertically reorders, dragging the helper
     * horizontally changes how deeply the row (and any descendants moving
     * with it) is nested. Holding the pointer over the middle of another row
     * also turns that row into an explicit parent drop target. Both methods
     * are bounded by the group's max depth.
     */
    var MenuBuilderTreeSorter = Garnish.DragSort.extend({
        tree: null,
        _draggeeLevel: null,
        _draggeeLevelDelta: null,
        _targetLevel: null,
        _targetLevelBounds: null,
        _insertionPreviousTop: null,

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

        /** The destination slot is the preview, so restore the hidden real row without a helper animation. */
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

        /** Moves the complete floating card to the indentation represented by the destination slot. */
        getHelperTargetX: function() {
            var targetX = this.base();
            var levelDiff = (this._targetLevel || this._draggeeLevel) - this._draggeeLevel;
            var offset = levelDiff * LEVEL_INDENT;

            if (Craft.orientation === 'rtl') {
                offset *= -1;
            }

            return targetX + offset;
        },

        /** A visible destination slot, separate from the floating drag helper. */
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
                .text(Craft.t('menu-builder', 'Drop here'))
                .end();
        },

        /** Draggee = the dragged row plus every deeper row after it (its descendants). */
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
         * Returns the min/max levels the draggee could occupy between two given
         * rows, or `false` if it can't fit there at all (max depth exceeded, or
         * the previous row can't have children).
         */
        getLevelBounds: function($prevRow, $nextRow) {
            var minLevel = ($nextRow && $nextRow.length) ? this.tree.level($nextRow) : 1;
            var maxLevel = ($prevRow && $prevRow.length) ? this.tree.level($prevRow) + 1 : 1;

            if (this.tree.maxDepth) {
                if (minLevel !== 1 && minLevel + this._draggeeLevelDelta > this.tree.maxDepth) {
                    return false;
                }

                if (maxLevel + this._draggeeLevelDelta > this.tree.maxDepth) {
                    maxLevel = this.tree.maxDepth - this._draggeeLevelDelta;

                    if (maxLevel < minLevel) {
                        maxLevel = minLevel;
                    }
                }
            }

            if ($prevRow && $prevRow.length && $prevRow.data('no-children') == '1' && maxLevel > this.tree.level($prevRow)) {
                maxLevel = this.tree.level($prevRow);
            }

            if (maxLevel < minLevel) {
                return false;
            }

            return { min: minLevel, max: maxLevel };
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

        /** Reads how far the mouse has moved horizontally since mousedown and turns that into a target level. */
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
            }

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

            // Apply the final level before the helper return animation so it
            // lands at the exact indentation shown by the destination slot.
            this.base();
            this.tree.persistReorder(this.$draggee.first());
        },
    });

    Garnish.$doc.ready(function() {
        var container = document.getElementById('menu-builder-tree');

        if (container) {
            // Exposed so the dashboard's bulk toolbar can reuse
            // setRowEnabled() instead of reimplementing it.
            window.MenuBuilder.tree = new MenuBuilderTree(container);
        }
    });
})(jQuery);
