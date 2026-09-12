# Changelog

All notable changes to MenuBuilder are documented in this file.

This project follows [Semantic Versioning](https://semver.org/) and [Keep a Changelog](https://keepachangelog.com/).

## [1.0.0] - 2026-09-13

First stable release of MenuBuilder for Craft CMS 5.

### Added

#### Menus

* Create and manage multiple named menus with handles, descriptions, enable/disable status, and ordering.
* Free edition supports one menu; Pro supports unlimited menus.
* Configure maximum menu nesting depth from 1–10.
* Restrict menus to specific sites.
* Configure menu CSS classes and validated HTML attributes.
* Duplicate complete menus, including their settings and menu items.
* Reorder menus using drag-and-drop or keyboard controls.

#### Menu Items

* Eight menu item types:

  * Entry
  * Category
  * Asset
  * Custom URL
  * Anchor
  * Non-clickable heading
  * Separator
  * Dynamic navigation
* Live element-link resolution per request and site.
* Configurable fallback behavior for unavailable linked elements.
* New-tab targets with automatic `rel="noopener"` handling.
* Preset and custom `rel` attributes.
* Icons, badges, descriptions, images, featured status, CSS classes, HTML IDs, and custom HTML attributes.
* Accessibility fields including ARIA labels and `title` attributes.
* Enable/disable and duplicate menu items, including their complete subtrees.
* Custom fields for menu items using Craft field layouts, including Matrix and relational fields.
* Field conditions and multiple field-layout tabs are supported.

#### Visibility, Mega Menus, Mobile & Dynamic Navigation

* Visibility rules for:

  * Logged-in users
  * Logged-out users
  * User groups
  * Sites
  * Date ranges
  * Environments
  * Always-visible items
* Combine visibility rules using AND logic.
* Mega menus with 1–6 columns and configurable child-item placement.
* Mobile-specific menu presentation settings without maintaining a separate mobile menu.
* Dynamic navigation generated from:

  * Entries by section
  * Categories by group
  * Assets by volume
* Dynamic navigation limits and supported ordering are validated server-side.

#### Control Panel

* Drag-and-drop hierarchical menu editor.
* Full keyboard alternatives for menu and item reordering.
* Slide-out item editor with full-page fallback.
* Quick-add panel.
* Search and filtering.
* Bulk enable, disable, and delete actions.
* Visual indicators for children, disabled items, and mega menus.
* Link-health indicators for internal links.
* Menu-wide link-health summary with direct navigation to affected items.
* Preview screen for saved menus across selected site, audience, region, and device settings.
* Preview of rendered markup.

#### Developer Features

* Twig API:

  * `craft.menuBuilder.get()`
  * `.breadcrumbs()`
  * `.getGroup()`
  * `.getItem()`
  * `.iconAsset()`
* `MenuBuilderNode` as the stable public navigation object.
* Breadcrumbs based on the menu hierarchy rather than URL segments.
* Optional Twig macros for trees and breadcrumbs.
* Optional navigation asset for enhanced mega-menu keyboard behavior.
* Navigation field for integrating MenuBuilder navigation with Craft field layouts.
* GraphQL queries for MenuBuilder navigation.
* REST API endpoints for read-only navigation access.
* REST API support for ETags, CORS allowlists, and rate limiting.
* Extension events for registering custom link types and visibility rules.

#### Editions

* Free and Pro editions using Craft's commercial plugin licensing system.
* Free edition supports one menu.
* Pro edition supports unlimited menus.
* All other MenuBuilder features are available in both editions.
* Edition limits are enforced server-side through the menu service.
* Existing menus and data are preserved when Pro access expires or is downgraded.
* Menu rendering and existing menu editing remain available after downgrade.
* Commercial licensing uses Craft's licensing infrastructure; MenuBuilder does not maintain its own license system.

### Performance

* Resolved menu data is cached per menu, site, and configuration version.
* Visibility rules and active-state resolution are evaluated for each request.
* Targeted cache invalidation is used for menu, item, element, container, and site changes.
* Draft and revision saves do not unnecessarily invalidate menu caches.
* Element links and custom-field content are batch loaded.
* Menu trees use efficient flat queries and batch loading to maintain predictable query counts.

### Security

* Five MenuBuilder permissions:

  * `menuBuilder:view`
  * `menuBuilder:create`
  * `menuBuilder:edit`
  * `menuBuilder:delete`
  * `menuBuilder:manageSettings`
* Server-side permission enforcement for all protected actions.
* All control-panel mutations require POST requests and Craft CSRF protection.
* Control-panel actions require valid control-panel requests.
* URL, HTML attribute, ID, and CSS class validation rejects unsafe values and executable schemes.
* Custom HTML attributes are validated during saving and filtered again during rendering.
* GraphQL and REST visibility resolution is performed independently of the caller's identity to prevent cached visibility decisions from leaking between users.
* REST API request and failed-authentication rate limiting.
* Rate limiting can be controlled through the plugin's `rateLimit` configuration.

### Accessibility

* Navigation output uses named `<nav>` landmarks and semantic lists.
* Active links use `aria-current="page"`.
* Separators use semantic `<hr>` elements.
* New-tab links provide a hidden accessibility hint.
* Mega-menu disclosures use native `<details>` elements.
* Menu management provides keyboard alternatives to drag-and-drop interactions.
* Custom HTML attributes are filtered during rendering to maintain safe output.

### Known Limitations

The following limitations are accepted for the 1.0.0 release:

* Menu and item save/delete events are not currently provided. MenuBuilder exposes registration events for custom link types and visibility rules.
* There is no built-in menu import/export format or command.
* Navigation fields resolve their menu for the current request's site.
* Changes driven only by Craft entry `postDate` or `expiryDate` do not trigger an immediate menu cache invalidation; cache duration determines the maximum staleness.
* Third-party element types without a built-in MenuBuilder link type are not automatically supported.
* Custom link types registered through the extension API are responsible for invalidating affected menu caches when necessary.
* Orphaned menu items are reported rather than automatically repaired.
* Preview renders saved menu data and does not simulate future time-based changes.
* Some Craft-dependent behavior requires integration testing and manual verification in addition to unit tests.

For detailed architecture, implementation decisions, API documentation, testing procedures, and release guidance, see the documentation in the `docs/` directory.
