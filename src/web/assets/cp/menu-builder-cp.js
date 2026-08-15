(function($) {
    if (typeof Craft === 'undefined' || typeof Garnish === 'undefined') {
        return;
    }

    /**
     * Menu tree controller: Garnish.DragSort for same-parent reordering,
     * plus fully keyboard/mouse-button-driven indent/outdent/up/down controls
     * so hierarchy editing never depends on drag-and-drop alone. All server
     * calls re-validate depth/circularity/cross-group — the client only
     * disables obviously-invalid buttons for a better UX, it never trusts
     * itself as the source of truth.
     */
    var MenuBuilderTree = Garnish.Base.extend({
        $container: null,
        groupId: null,
        maxDepth: null,
        canEdit: false,
        sorters: [],

        init: function(container) {
            this.$container = $(container);
            this.groupId = this.$container.data('group-id');
            this.maxDepth = this.$container.data('max-depth') || null;
            this.canEdit = this.$container.data('can-edit') == '1';

            this.initSorters();
            this.addListener(this.$container, 'click', this.handleClick.bind(this));
        },

        initSorters: function() {
            if (!this.canEdit) {
                return;
            }

            var self = this;
            this.$container.find('ul.menu-builder-tree-list').each(function() {
                var $list = $(this);
                var $items = $list.children('li.menu-builder-item');

                if ($items.length < 2) {
                    return;
                }

                var sorter = new Garnish.DragSort($items.toArray(), {
                    handle: '.menu-builder-drag-handle',
                    axis: 'y',
                    container: $list,
                    onSortChange: function() {
                        self.persistSiblingOrder($list);
                    },
                });

                self.sorters.push(sorter);
            });
        },

        persistSiblingOrder: function($list) {
            var parentId = $list.data('parent-id') || null;
            var ids = $list.children('li.menu-builder-item').map(function() {
                return $(this).data('id');
            }).get();

            if (!ids.length) {
                return;
            }

            Craft.sendActionRequest('POST', 'menu-builder/items/reorder', {
                data: {
                    itemId: ids[0],
                    groupId: this.groupId,
                    newParentId: parentId,
                    siblingIds: ids,
                },
            }).catch(function() {
                Craft.cp.displayError(Craft.t('app', 'A server error occurred.'));
                window.location.reload();
            });
        },

        handleClick: function(event) {
            var $target = $(event.target);
            var $item = $target.closest('li.menu-builder-item');

            if ($target.hasClass('menu-builder-toggle')) {
                this.toggle($target.data('id'), $target);
            } else if ($target.hasClass('menu-builder-duplicate')) {
                this.duplicate($target.data('id'));
            } else if ($target.hasClass('menu-builder-delete')) {
                this.remove($target.data('id'), $target.data('title'));
            } else if ($target.hasClass('menu-builder-move-up')) {
                this.moveWithinSiblings($item, -1);
            } else if ($target.hasClass('menu-builder-move-down')) {
                this.moveWithinSiblings($item, 1);
            } else if ($target.hasClass('menu-builder-indent')) {
                this.indent($item);
            } else if ($target.hasClass('menu-builder-outdent')) {
                this.outdent($item);
            }
        },

        toggle: function(id, $button) {
            Craft.sendActionRequest('POST', 'menu-builder/items/toggle', {data: {id: id}})
                .then(function(response) {
                    var enabled = response.data && response.data.enabled;
                    $button.closest('li.menu-builder-item').toggleClass('menu-builder-item-disabled', !enabled);
                    $button.text(enabled ? Craft.t('menu-builder', 'Disable') : Craft.t('menu-builder', 'Enable'));
                })
                .catch(function() {
                    Craft.cp.displayError(Craft.t('app', 'A server error occurred.'));
                });
        },

        duplicate: function(id) {
            Craft.sendActionRequest('POST', 'menu-builder/items/duplicate', {data: {id: id}})
                .then(function() {
                    window.location.reload();
                })
                .catch(function() {
                    Craft.cp.displayError(Craft.t('app', 'A server error occurred.'));
                });
        },

        remove: function(id, title) {
            if (!confirm(Craft.t('menu-builder', 'Delete “{title}”? This also deletes any child items.', {title: title}))) {
                return;
            }

            Craft.sendActionRequest('POST', 'menu-builder/items/delete', {data: {id: id}})
                .then(function() {
                    window.location.reload();
                })
                .catch(function() {
                    Craft.cp.displayError(Craft.t('app', 'A server error occurred.'));
                });
        },

        moveWithinSiblings: function($item, direction) {
            var $list = $item.parent('ul.menu-builder-tree-list');
            var $sibling = direction < 0 ? $item.prev('li.menu-builder-item') : $item.next('li.menu-builder-item');

            if (!$sibling.length) {
                return;
            }

            if (direction < 0) {
                $item.insertBefore($sibling);
            } else {
                $item.insertAfter($sibling);
            }

            this.persistSiblingOrder($list);
        },

        /** Becomes the last child of its previous sibling. */
        indent: function($item) {
            var $prev = $item.prev('li.menu-builder-item');

            if (!$prev.length) {
                return;
            }

            var newParentId = $prev.data('id');
            var $targetList = $prev.children('ul.menu-builder-tree-list');

            if (!$targetList.length) {
                $targetList = $('<ul class="menu-builder-tree-list"></ul>').attr('data-parent-id', newParentId);
                $prev.append($targetList);
            }

            $targetList.append($item);
            this.persistMove($item.data('id'), newParentId, $targetList);
        },

        /** Moves up one level, placed immediately after its current parent. */
        outdent: function($item) {
            var $list = $item.parent('ul.menu-builder-tree-list');
            var currentParentId = $list.data('parent-id');

            if (!currentParentId) {
                return; // already top-level
            }

            var $parentItem = this.$container.find('li.menu-builder-item[data-id="' + currentParentId + '"]');
            var $grandParentList = $parentItem.parent('ul.menu-builder-tree-list');
            var newParentId = $grandParentList.data('parent-id') || null;

            $item.insertAfter($parentItem);
            this.persistMove($item.data('id'), newParentId, $grandParentList);
        },

        persistMove: function(itemId, newParentId, $list) {
            var ids = $list.children('li.menu-builder-item').map(function() {
                return $(this).data('id');
            }).get();

            Craft.sendActionRequest('POST', 'menu-builder/items/reorder', {
                data: {
                    itemId: itemId,
                    groupId: this.groupId,
                    newParentId: newParentId,
                    siblingIds: ids,
                },
            }).catch(function() {
                Craft.cp.displayError(Craft.t('app', 'That move isn’t allowed.'));
                window.location.reload();
            });
        },
    });

    Garnish.$doc.ready(function() {
        var container = document.getElementById('menu-builder-tree');

        if (container) {
            new MenuBuilderTree(container);
        }
    });
})(jQuery);
