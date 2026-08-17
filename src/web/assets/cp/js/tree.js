(function($) {
    if (typeof Craft === 'undefined' || typeof Garnish === 'undefined') {
        return;
    }

    /** Horizontal pixels of mouse movement per indent level, matching Craft's own structure tables. */
    var LEVEL_INDENT = 24;
    /** Deliberate hover time before a row becomes a drop-as-child target. */
    var DROP_PARENT_DELAY = 300;

    /**
     * Menu tree controller. The tree is rendered as a single FLAT list of
     * `<li>` rows (each carrying its own `data-level`), exactly like Craft's
     * native Structure element tables (see Craft.ElementTableSorter) —
     * indentation is cosmetic (CSS padding), not real DOM nesting. A single
     * Garnish.DragSort (MenuBuilderTreeSorter) reorders vertically and
     * reparents by dragging horizontally, the same interaction the CP's own
     * Navigation node listing uses. A row can also be dropped directly onto
     * another row to make it a child. Indent/outdent/up/down remain available
     * from each row's "⋮" context menu as a keyboard-reachable fallback
     * (spec §6/§24). All server calls re-validate depth/circularity/
     * cross-group — the client only disables obviously-invalid actions for
     * UX, it never trusts itself as the source of truth.
     */
    var MenuBuilderTree = Garnish.Base.extend({
        $container: null,
        $list: null,
        groupId: null,
        groupHandle: null,
        maxDepth: null,
        canEdit: false,
        sorter: null,

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

        nextSibling: function($item) {
            var level = this.level($item);
            var $next = $item.next('li.menu-builder-item');

            while ($next.length && this.level($next) > level) {
                $next = $next.next('li.menu-builder-item');
            }

            return ($next.length && this.level($next) === level) ? $next : $();
        },

        prevSibling: function($item) {
            var level = this.level($item);
            var $prev = $item.prev('li.menu-builder-item');

            while ($prev.length && this.level($prev) > level) {
                $prev = $prev.prev('li.menu-builder-item');
            }

            return ($prev.length && this.level($prev) === level) ? $prev : $();
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

            $li.find('[data-mb-action="outdent"]').toggleClass('menu-builder-action-hidden', level <= 1);

            var atMaxDepth = this.maxDepth && level >= this.maxDepth;
            $li.find('[data-mb-action="indent"]').toggleClass(
                'menu-builder-action-hidden',
                atMaxDepth || $li.data('no-children') == '1'
            );
            $li.find('[data-mb-action="add-child"]').toggleClass(
                'menu-builder-action-hidden',
                atMaxDepth || $li.data('no-children') == '1'
            );
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

        /** Keeps parent ids, action-menu state, and child-count badges in sync without a page reload. */
        syncHierarchyMetadata: function($item, newParentId) {
            $item
                .data('parent-id', newParentId || '')
                .attr('data-parent-id', newParentId || '')
                .find('[data-mb-action="add-sibling"]')
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
        },

        persistMove: function(itemId, newParentId, siblingIds) {
            window.MenuBuilder.request('POST', 'menu-builder/items/reorder', {
                data: {
                    itemId: itemId,
                    groupId: this.groupId,
                    newParentId: newParentId,
                    siblingIds: siblingIds,
                },
            }).then(function() {
                window.MenuBuilder.success(Craft.t('menu-builder', 'Menu order updated.'));
            }).catch(function(error) {
                window.MenuBuilder.displayError(error, Craft.t('menu-builder', 'That move isn’t allowed.'));
                window.location.reload();
            });
        },

        handleClick: function(event) {
            var $target = $(event.target).closest('[data-mb-action]');

            if (!$target.length) {
                return;
            }

            event.preventDefault();

            var action = $target.data('mb-action');
            var id = $target.data('id');
            var $item = id != null
                ? this.$container.find('li.menu-builder-item[data-id="' + id + '"]')
                : $target.closest('li.menu-builder-item');

            switch (action) {
                case 'focus-quick-add':
                    return; // handled by the dashboard template itself
                case 'edit':
                    this.editItem(id);
                    break;
                case 'add-child':
                    this.addItem($target.data('parent-id'));
                    break;
                case 'add-sibling':
                    this.addItem($target.data('parent-id') || null);
                    break;
                case 'duplicate':
                    this.duplicate(id);
                    break;
                case 'toggle':
                    this.toggle(id, $target);
                    break;
                case 'move-up':
                    this.moveWithinSiblings($item, -1);
                    break;
                case 'move-down':
                    this.moveWithinSiblings($item, 1);
                    break;
                case 'indent':
                    this.indent($item);
                    break;
                case 'outdent':
                    this.outdent($item);
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

        addItem: function(parentId) {
            window.MenuBuilder.openItemSlideout({ groupHandle: this.groupHandle, parentId: parentId || '' }, function() {
                window.location.reload();
            });
        },

        toggle: function(id, $target) {
            window.MenuBuilder.request('POST', 'menu-builder/items/toggle', { data: { id: id } })
                .then(function(response) {
                    var enabled = response.data && response.data.enabled;
                    var $item = $target.closest('li.menu-builder-item');
                    $item.toggleClass('menu-builder-item-disabled', !enabled);
                    $target.text(enabled ? Craft.t('menu-builder', 'Disable') : Craft.t('menu-builder', 'Enable'));

                    var $status = $item.children('.menu-builder-item-row').find('.menu-builder-item-status');
                    if (enabled) {
                        $status.remove();
                    } else if (!$status.length) {
                        $('<span class="menu-builder-item-status badge disabled-badge">' + Craft.t('menu-builder', 'Disabled') + '</span>')
                            .insertAfter($item.children('.menu-builder-item-row').find('.menu-builder-item-type'));
                    }

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

        moveWithinSiblings: function($item, direction) {
            var $subtree = this.getSubtree($item);
            var $sibling = direction < 0 ? this.prevSibling($item) : this.nextSibling($item);

            if (!$sibling.length) {
                return;
            }

            if (direction < 0) {
                $subtree.insertBefore($sibling);
            } else {
                $subtree.insertAfter(this.getSubtree($sibling).last());
            }

            this.persistReorder($item);
        },

        /** Becomes the last child of its previous sibling. */
        indent: function($item) {
            var $prevSibling = this.prevSibling($item);

            if (!$prevSibling.length || $prevSibling.data('no-children') == '1') {
                return;
            }

            var $subtree = this.getSubtree($item);
            var levelDiff = (this.level($prevSibling) + 1) - this.level($item);

            this.shiftLevels($subtree, levelDiff);
            $subtree.insertAfter(this.getSubtree($prevSibling).last());

            this.persistReorder($item);
        },

        /** Moves up one level, placed immediately after its current parent's subtree. */
        outdent: function($item) {
            var level = this.level($item);

            if (level <= 1) {
                return; // already top-level
            }

            var $subtree = this.getSubtree($item);
            var parentId = this.findParentId($item, level);
            var $parentItem = this.$container.find('li.menu-builder-item[data-id="' + parentId + '"]');

            this.shiftLevels($subtree, -1);

            if ($parentItem.length) {
                var $lastNode = this.getSubtree($parentItem).last();

                // If $item was already the last thing in its parent's subtree, it's already in the right spot.
                if (!$subtree.filter($lastNode).length) {
                    $subtree.insertAfter($lastNode);
                }
            }

            this.persistReorder($item);
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
        _$dropParentCandidate: null,
        _$dropParent: null,
        _dropParentCandidateSince: null,
        _dropParentTimer: null,
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
            this.clearDropParent();
            this.setTargetLevelBounds();
            Garnish.$bod.addClass('menu-builder-is-dragging');
            this.base();
        },

        onDrag: function() {
            this.rememberInsertionPosition();
            this.base();
            this.updateIndent();
            this.updateDropParent();
        },

        onInsertionPointChange: function() {
            this.setTargetLevelBounds();
            this.updateIndent();
            this.updateDropParent();
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
         * Returns a valid row under the pointer's central drop zone. The top
         * and bottom 20% remain available for ordinary before/after sorting.
         */
        getDropParentCandidate: function() {
            var self = this;
            var $candidate = $();
            var pointerX = this.realMouseX;
            var pointerY = this.realMouseY;

            this.tree.$list.children('li.menu-builder-item').each(function() {
                var $item = $(this);

                if (self.$draggee.filter(this).length || $item.data('no-children') == '1') {
                    return;
                }

                var offset = $item.offset();
                var height = $item.outerHeight();
                var zoneTop = offset.top + height * 0.2;
                var zoneBottom = offset.top + height * 0.8;

                if (pointerX >= offset.left && pointerX <= offset.left + $item.outerWidth() &&
                    pointerY >= zoneTop && pointerY <= zoneBottom) {
                    var childLevel = self.tree.level($item) + 1;

                    if (!self.tree.maxDepth || childLevel + self._draggeeLevelDelta <= self.tree.maxDepth) {
                        $candidate = $item;
                        return false;
                    }
                }
            });

            return $candidate;
        },

        /** Highlights a row once it has been hovered long enough to show clear nesting intent. */
        updateDropParent: function() {
            var $candidate = this.getDropParentCandidate();
            var candidateChanged = !this._$dropParentCandidate ||
                !$candidate.length ||
                this._$dropParentCandidate[0] !== $candidate[0];

            if (candidateChanged) {
                this.clearDropParent();

                if ($candidate.length) {
                    this._$dropParentCandidate = $candidate;
                    this._dropParentCandidateSince = Date.now();
                    $candidate.addClass('menu-builder-drop-parent-pending');
                    this._dropParentTimer = setTimeout(this.activateDropParent.bind(this), DROP_PARENT_DELAY);
                }

                return;
            }

            if (!this._$dropParent && Date.now() - this._dropParentCandidateSince >= DROP_PARENT_DELAY) {
                this.activateDropParent();
            } else if (this._$dropParent) {
                this.positionDropParentInsertion();
            }
        },

        activateDropParent: function() {
            if (!this.dragging || !this._$dropParentCandidate || !this._$dropParentCandidate.length) {
                return;
            }

            this._$dropParent = this._$dropParentCandidate;
            this._$dropParent
                .removeClass('menu-builder-drop-parent-pending')
                .addClass('menu-builder-drop-parent');
            this.positionDropParentInsertion();
        },

        /** Places the destination slot after the parent's current children at the child indentation level. */
        positionDropParentInsertion: function() {
            if (!this._$dropParent || !this._$dropParent.length || !this.$insertion || !this.$insertion.length) {
                return;
            }

            var previousTop = this.$insertion.is(':visible') ? this.$insertion.offset().top : null;
            this.$insertion.detach();

            var $anchor = this.tree.getSubtree(this._$dropParent).not(this.$draggee).last();
            var parentTitle = $.trim(this._$dropParent.find('.menu-builder-item-title').first().text());
            var targetLevel = this.tree.level(this._$dropParent) + 1;

            this._targetLevel = targetLevel;
            this.updateHelperLevel(targetLevel);

            this.$insertion
                .css('--mb-level', targetLevel)
                .attr('data-level', targetLevel)
                .insertAfter($anchor.length ? $anchor : this._$dropParent);
            this.$insertion.find('.menu-builder-drop-position-label').text(
                Craft.t('menu-builder', 'Inside “{title}”', { title: parentTitle })
            );
            this.animateInsertionMove(previousTop);
        },

        clearDropParent: function() {
            if (this._dropParentTimer) {
                clearTimeout(this._dropParentTimer);
            }

            this.tree.$list.children('li.menu-builder-item')
                .removeClass('menu-builder-drop-parent menu-builder-drop-parent-pending');
            this._$dropParentCandidate = null;
            this._$dropParent = null;
            this._dropParentCandidateSince = null;
            this._dropParentTimer = null;

            if (this.$insertion && this.$insertion.length) {
                this.$insertion.find('.menu-builder-drop-position-label')
                    .text(Craft.t('menu-builder', 'Drop here'));
            }
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

            if (this.$insertion && this.$insertion.length && !this._$dropParent) {
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
            var $dropParent = this._$dropParent;
            Garnish.$bod.removeClass('menu-builder-is-dragging');
            this.clearDropParent();

            if ($dropParent && $dropParent.length) {
                // Move the real (currently hidden) rows before Garnish animates
                // the helper home, so the animation lands on the shown slot.
                if (this.$insertion && this.$insertion.length) {
                    this.$insertion.detach();
                }

                var $anchor = this.tree.getSubtree($dropParent).not(this.$draggee).last();
                var targetLevel = this.tree.level($dropParent) + 1;
                var directDropLevelDiff = targetLevel - this._draggeeLevel;

                if (directDropLevelDiff !== 0) {
                    this.tree.shiftLevels(this.$draggee, directDropLevelDiff);
                }

                this.$draggee.insertAfter($anchor.length ? $anchor : $dropParent);
                this.base();
                this.tree.persistReorder(this.$draggee.first());
                return;
            }

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
            new MenuBuilderTree(container);
        }
    });
})(jQuery);
