# MenuBuilder edition audit

Audited 2026-09-13 against the repository at `v1.0.0`, Craft CMS 5.10.13.2,
PHP 8.4.22, and MySQL 8.0 in the existing DDEV environment. Integration tests
rebuild only `menubuilder_test`, not the development site's database.

## Result

The native edition declaration and package identity were already correct. The
Store's reported Standard edition is not explained by a missing `editions()`
implementation. Two menu-limit bypasses and a concurrency gap needed correction:

- `save()` checked only a null ID, but selected a new record for an ID of zero
  as well. A direct caller passing zero could create another Free menu.
- The allowance used `getAll()`'s request cache. A second service instance could
  create a menu without invalidating that cache, allowing another creation.
- Separate requests could both check the allowance before either saved. Both
  creation paths now acquire the same Craft mutex before counting and writing.
  Craft defers releasing this mutex until an enclosing transaction completes.

The limit now counts database rows, including disabled menus and menus restricted
to other sites. Existing-menu edits do not acquire the creation lock.

## Native Craft compatibility and metadata

`src/MenuBuilder.php` extends `craft\base\Plugin`; its constants and static
`editions()` return `['free', 'pro']`. Its component registration and feature
registration are independent of the active plugin edition. No schema migration
or custom edition configuration is necessary.

Craft owns `plugins.menu-builder.edition`, installation defaults, project-config
application, and `Plugins::switchEdition()`. MenuBuilder reads the native edition
and calls `Plugin::is('pro', '>=')`. Craft's `Plugins::createPlugin()` normalizes
an unsupported stored edition, including legacy `standard`, to the first declared
edition. MenuBuilder additionally treats unknown runtime values as Free for limits.

`composer.json` correctly declares `tahadudhiya/craft-menu-builder`, type
`craft-plugin`, handle `menu-builder`, the MenuBuilder class, PHP `>=8.2.0`, Craft
`^5.0`, and `proprietary` with `LICENSE.md` containing the Craft License. There is
no Standard edition or pricing declaration in package metadata. The checked-out
tag and changelog identify 1.0.0; no explicit Composer version is required.

The official [Craft 5 edition documentation](https://craftcms.com/docs/5.x/extend/plugin-editions.html)
requires editions in ascending order, documents native comparisons and project
config, requires non-destructive edition changes, and directs developers to obtain
Pixel & Tonic's approval for multiple editions. MenuBuilder follows those rules.

As a secondary comparison, [Craft Commerce's 5.x plugin class](https://github.com/craftcms/commerce/blob/5.x/src/Plugin.php)
also declares constants and returns ordered handles from `editions()`. Local
sibling plugins were searched; they did not provide a multiple-edition example.
No feature restrictions were copied from another plugin.

## Complete production edition map

| File | Responsibility |
| --- | --- |
| `src/MenuBuilder.php` | Constants, native edition declaration, license and allowance components |
| `src/services/MenuBuilderLicenseService.php` | Native edition reads/comparison, display names, native license status, upgrade URL |
| `src/services/MenuBuilderMenuLimitService.php` | One-menu ceiling, current count, creation allowance, CP summary |
| `src/services/MenuBuilderGroupService.php` | Server-side save/duplicate enforcement; current row count and shared creation mutex |
| `src/controllers/GroupsController.php` | Creation/duplicate UI checks, limit feedback, edition summary |
| `src/controllers/DashboardController.php` | Sidebar edition summary |
| `src/templates/groups/_index.twig` | Edition/count/license display, upgrade link, creation/duplicate UI feedback |
| `src/templates/dashboard/_sidebar-footer.twig` | New-menu or upgrade affordance |
| `src/web/assets/cp/menu-builder-cp.css` | Edition presentation only |

No edition checks were found in rendering, Twig APIs, GraphQL, REST, the MenuBuilder
field, custom/Matrix fields, mega menus, dynamic menus, visibility, multisite,
mobile settings, previews, link health, active states, breadcrumbs, accessibility,
or caching. These remain available in Free, subject to their ordinary permissions
and Craft's own capabilities.

Tests referencing editions are principally `MenuBuilderLicensingTest`,
`MenuBuilderMenuLimitTest`, `MenuBuilderEditionSwitchTest`, and the integration
bootstrap. README and ARCHITECTURE describe the same native edition model.

## License service method review

All methods are used and retained; none implements separate license validation.

| Method | Caller/use and finding |
| --- | --- |
| `getEdition()` | Display-name helper and tests; reads native `Plugin::$edition` |
| `isPro()` | Allowance and CP summary; guarded native `is()` comparison |
| `isKnownEdition()` | `isPro()` and tests; limits unknown runtime values safely |
| `editionIsPro()` | `editionName()` and unit tests; display mapping only |
| `getEditionName()` | CP summary; delegates to display mapping |
| `editionName()` | Instance helper and tests; Pro or Free label |
| `getLicenseKeyStatus()` | `isLicenseActive()` and tests; calls Craft's public Plugins service, reads no key |
| `isLicenseActive()` | CP summary only; Valid/Trial informational indicator, never a feature gate |
| `getUpgradeUrl()` | CP summary; admin with allowed config changes gets the CP buy route; other users get the public listing |

The buy URL is not invented: installed Craft 5's `craft\helpers\App::licenseInfo()`
uses `plugin-store/buy/<handle>/<edition>` itself. The license status service reads
Craft's stored status and adds no HTTP request. No license service change was needed.

## Behavior verified

- Fresh explicit Free, default Free, and explicit Pro installs are recognized by
  Craft, including the project-config value.
- Free can create its first menu; second creation and duplication are refused at
  service and controller boundaries. Zero IDs and stale lists cannot bypass this.
- Pro can create and duplicate multiple menus with no plugin-imposed ceiling.
- Native Free → Pro restores creation. Pro → Free preserves menus, items, settings,
  rendering, and editing, while refusing additional menus.
- Project-config edition changes do not modify menu data or unrelated config.
- Every Craft license status is tested through a mocked status response in a real
  Craft app: Valid, Trial, Invalid, Mismatched, Astray, and Unknown do not change Pro's
  native edition or allowance. This does not simulate a real purchase or Craftnet response.
- Renewal expiration is not an edition downgrade. Existing Pro functionality remains;
  renewal concerns eligibility for subsequent updates.

## Store action required

The reported Console text, “To manage your editions, please contact us,” is consistent
with Store-side edition configuration/approval. Repository code cannot update that
private listing. This audit could not inspect the authenticated Console account or
independently reproduce its UI, so Pixel & Tonic must confirm the account/listing's
precise state. No claim is made that approval has already been granted.

The [official publishing guide](https://craftcms.com/docs/5.x/extend/plugin-store.html)
separates code/package metadata from Console pricing and approval. It describes
renewal pricing as access to updates after the first year, and permits adding a
commercial edition to a free plugin without removing crucial free functionality.
The one-menu allowance should be explicitly included in the approval request.

Send the following to Pixel & Tonic through [Craft's contact page](https://craftcms.com/contact)
or support@craftcms.com; no message was sent during this audit:

> Please approve and configure multiple editions for MenuBuilder, handle
> `menu-builder`, Composer package `tahadudhiya/craft-menu-builder`, repository
> https://github.com/tahadudhiya53/MenuBuilder. Console currently shows only
> Standard (`standard`) at $0 and says to contact you to manage editions.
> The Craft 5 plugin declares `['free', 'pro']`. Please configure Free (`free`)
> at $0 with $0 renewal, and Pro (`pro`) at $19 with $5 annual update renewal.
> Both editions include every feature; Free allows one menu and Pro unlimited menus.
> Please confirm this allowance model is approved, how existing Standard licenses
> should map to Free, and whether release ingestion or another approval step is required.

After approval, check both the Console edition records and Craft's in-app Store
listing, then manually verify the Upgrade to Pro purchase flow. Do not add a fake
Standard edition or embed Store prices/license keys in PHP to force the listing.

## Files changed

| File | Change |
| --- | --- |
| `src/services/MenuBuilderGroupService.php` | Fix zero-ID check; add current count and shared native mutex around both creation paths |
| `src/services/MenuBuilderMenuLimitService.php` | Use current database count |
| `tests/Integration/MenuBuilderMenuLimitTest.php` | Add zero-ID, stale-list, release, and separate-database-connection lock regressions; clarify downgrade wording |
| `tests/Integration/MenuBuilderEditionSwitchTest.php` | Add native comparisons, unknown-runtime-edition guard, and all native license-status checks |
| `tests/Unit/MenuBuilderGroupTest.php` | Follow the extracted persistence method while retaining all validation/uniqueness assertions and checking delegation |
| `tests/integration-bootstrap.php` | Support explicit/default edition install tests and verify native edition/project config before loading shared Pro fixtures |
| `ARCHITECTURE.md` | Document corrected creation boundary |
| `EDITION-AUDIT.md` | Findings, reference map, checks, and Store request |

## Validation and limits

- Full unit suite: **1,155 tests, 3,329 assertions, passed**.
- Final full integration suite, fresh Pro: **516 tests, 1,828 assertions, passed**.
- Final full integration suite, default Free: **516 tests, 1,828 assertions, passed**.
- Explicit Free run: **515 tests, 1,825 assertions, passed**, before the final
  cross-connection lock test was added. Shared feature fixtures switch to Pro after
  verifying installation; Free behavior is exercised in the dedicated limit/switch tests.
- PHPStan and ECS: passed. Dependencies emit PHP deprecation notices; these are
  not assertion failures or edition errors.
- `git diff --check`: passed.
- Composer schema validation: valid. The existing tracked lock file is out of sync
  with composer.json. Neither was changed by this audit. The normal validation
  command also encountered a local Composer plugin path error; validation with
  `--no-plugins` exposed the stale-lock warning. Refresh dependency metadata separately
  before release, without an unreviewed dependency upgrade.

The executed Craft version is 5.10.13.2, not a matrix of every Craft 5 minor release.
Live Store approval, pricing records, actual license purchases, and browser checkout
remain external/manual checks. Existing feature suites pass and no feature gate was
added. The changes have not been committed, tagged, pushed, or published.

Recommended commit message: `Fix Free menu creation limits and audit native Craft editions`.
