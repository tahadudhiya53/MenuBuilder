<?php

namespace Tahadudhiya\MenuBuilder\models;

use Craft;
use craft\base\Model;
use Tahadudhiya\MenuBuilder\elements\MenuBuilderItemContent;
use Tahadudhiya\MenuBuilder\helpers\BadgeHelper;
use Tahadudhiya\MenuBuilder\helpers\ConfigHelper;
use Tahadudhiya\MenuBuilder\helpers\DateValidationHelper;
use Tahadudhiya\MenuBuilder\helpers\IconHelper;
use Tahadudhiya\MenuBuilder\helpers\LinkAttributeHelper;
use Tahadudhiya\MenuBuilder\helpers\MobileHelper;
use Tahadudhiya\MenuBuilder\linktypes\AnchorLinkResolver;
use Tahadudhiya\MenuBuilder\MenuBuilder;

/**
 * A single navigation node. `clickable` is an explicit, independent flag —
 * never inferred from whether a URL/element is set, so an editor can keep a
 * resolvable link on an item and still render it as a plain label.
 *
 * It is not, however, a way to *add* a link to a structural type: for
 * `nonclickable` and `separator`, {@see isLinkable()} is authoritative and
 * NonClickableLinkResolver never produces a URL regardless of `clickable` or
 * a leftover `customUrl` — and the editor exposes no link field for them
 * (see items/_fields.twig).
 */
class MenuBuilderItem extends Model
{
    public const TYPE_ENTRY = 'entry';
    public const TYPE_CATEGORY = 'category';
    public const TYPE_ASSET = 'asset';
    public const TYPE_URL = 'url';
    public const TYPE_ANCHOR = 'anchor';
    public const TYPE_NONCLICKABLE = 'nonclickable';
    public const TYPE_SEPARATOR = 'separator';
    public const TYPE_DYNAMIC = 'dynamic';

    public const TYPES = [
        self::TYPE_ENTRY,
        self::TYPE_CATEGORY,
        self::TYPE_ASSET,
        self::TYPE_URL,
        self::TYPE_ANCHOR,
        self::TYPE_NONCLICKABLE,
        self::TYPE_SEPARATOR,
        self::TYPE_DYNAMIC,
    ];

    /** Element sources a `dynamic` item's children can be pulled from. */
    public const DYNAMIC_SOURCE_TYPES = ['entries', 'categories', 'assets'];

    /** Hard server-side cap, regardless of what's stored in metadata — see MenuBuilderDynamicNavigationService. */
    public const DYNAMIC_SOURCE_MAX_LIMIT = 50;

    /** Whitelisted sort orders for a dynamic source query — never raw editor-supplied SQL. */
    public const DYNAMIC_SOURCE_ORDER_BY = ['dateCreated desc', 'dateCreated asc', 'title asc', 'title desc'];

    /** Types that reference a Craft element by ID. */
    public const ELEMENT_TYPES = [self::TYPE_ENTRY, self::TYPE_CATEGORY, self::TYPE_ASSET];

    public const FALLBACK_HIDE = 'hide';
    public const FALLBACK_DISABLE_LINK = 'disableLink';
    public const FALLBACK_FALLBACK_URL = 'fallbackUrl';

    public const FALLBACK_BEHAVIORS = [
        self::FALLBACK_HIDE,
        self::FALLBACK_DISABLE_LINK,
        self::FALLBACK_FALLBACK_URL,
    ];

    public ?int $id = null;
    public ?int $groupId = null;
    public ?int $parentId = null;
    public string $type = self::TYPE_URL;
    public string $title = '';
    public ?string $handle = null;
    public bool $enabled = true;
    public int $sortOrder = 0;

    /** Explicit editor choice — see class docblock. */
    public bool $clickable = true;

    public ?int $elementId = null;
    public ?string $customUrl = null;
    public string $target = '_self';
    public ?string $rel = null;

    public ?string $cssClass = null;
    public ?string $htmlId = null;

    /** @var array<string,string> Arbitrary data attributes and other HTML attributes. */
    public array $htmlAttributes = [];

    public ?string $ariaLabel = null;
    public ?string $titleAttribute = null;
    public ?string $icon = null;
    public ?string $badge = null;
    public ?string $description = null;
    public ?int $image = null;
    public bool $featured = false;

    public string $fallbackBehavior = self::FALLBACK_HIDE;
    public ?string $fallbackUrl = null;

    /** @var array<int,array<string,mixed>> Visibility rule configs, see MenuBuilderVisibilityService. */
    public array $visibility = [];

    /** @var array<string,mixed> Open-ended extension point (e.g. future mega-menu column data). */
    public array $metadata = [];

    /**
     * @var int|null The `elements` row carrying this item's custom field
     *      content — a {@see MenuBuilderItemContent}.
     *
     * Null until the owning menu has a field layout with at least one field
     * in it; {@see MenuBuilderItemService::saveContent()} creates the
     * element on the first save after that, so an install that uses no
     * custom fields never writes an `elements` row at all.
     */
    public ?int $contentId = null;

    /**
     * @var MenuBuilderItemContent|null Lazily loaded, never persisted here —
     *      see {@see getContent()}.
     */
    private ?MenuBuilderItemContent $content = null;
    private bool $contentLoaded = false;

    public ?string $uid = null;
    public ?string $dateCreated = null;
    public ?string $dateUpdated = null;

    /** @var MenuBuilderItem[] Populated by MenuBuilderItemService::getTree(), not persisted. */
    public array $children = [];

    protected function defineRules(): array
    {
        return [
            [['groupId'], 'required'],
            [['type'], 'in', 'range' => self::TYPES],
            // Element-backed types may leave title blank to inherit the linked
            // element's own title at render time — an explicit
            // title, once given, is never overwritten by that fallback.
            [['title'], 'required', 'when' => fn($model) => $model->type !== self::TYPE_SEPARATOR && !in_array($model->type, self::ELEMENT_TYPES, true)],
            // …but an element-backed item that is meant to *survive* its
            // element (fallback "keep the item" / "use a fallback URL") has
            // nothing left to inherit a title from once that element is
            // gone, and would render as an empty label. The title is
            // therefore required up front for those two behaviours; only
            // FALLBACK_HIDE can safely leave it blank.
            [
                ['title'], 'required',
                'message' => Craft::t('menu-builder', 'A title is required unless the item is hidden when its linked element becomes unavailable.'),
                'when' => fn($model) => in_array($model->type, self::ELEMENT_TYPES, true) && $model->fallbackBehavior !== self::FALLBACK_HIDE,
            ],
            [['title'], 'string', 'max' => 255],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_-]*$/', 'message' => 'Handle must start with a letter and contain only letters, numbers, underscores, and hyphens.', 'skipOnEmpty' => true],
            [['enabled', 'clickable', 'featured'], 'boolean'],
            [['sortOrder', 'groupId', 'parentId', 'elementId', 'image'], 'integer'],
            [['target'], 'in', 'range' => ['_self', '_blank']],
            [['fallbackBehavior'], 'in', 'range' => self::FALLBACK_BEHAVIORS],
            [['elementId'], 'required', 'when' => fn() => in_array($this->type, self::ELEMENT_TYPES, true)],
            [['customUrl'], 'validateCustomUrl', 'skipOnEmpty' => false],
            [['fallbackUrl'], 'validateFallbackUrl', 'skipOnEmpty' => false],
            [['handle'], 'validateAnchorTarget', 'skipOnEmpty' => false],
            [['htmlAttributes'], 'validateHtmlAttributes', 'skipOnEmpty' => false],
            // `description` is the only one of these on a TEXT column; the
            // rest are varchar(255) (see migrations/Install.php), and without
            // a matching max here an over-long value passed validation and
            // then failed at the database, surfacing as a save that "didn't
            // work" with no field error to explain it.
            [['rel', 'cssClass', 'htmlId', 'ariaLabel', 'titleAttribute', 'icon', 'badge'], 'string', 'max' => 255],
            [['description'], 'string'],
            [['htmlId'], 'validateHtmlId', 'skipOnEmpty' => true],
            [['cssClass'], 'validateCssClass', 'skipOnEmpty' => true],
            [['icon'], 'validateIcon', 'skipOnEmpty' => true],
            // Not skipOnEmpty: clearing the badge has to normalize an
            // all-whitespace value to null, and the style lives in
            // `metadata`, which is validated whether or not `badge` is set.
            [['badge'], 'validateBadge', 'skipOnEmpty' => false],
            [['visibility'], 'validateVisibility', 'skipOnEmpty' => false],
            [['metadata'], 'validateMegaMenu', 'skipOnEmpty' => false],
            [['metadata'], 'validateDynamicSource', 'skipOnEmpty' => false],
            [['metadata'], 'validateMobile', 'skipOnEmpty' => false],
            [['htmlAttributes', 'metadata'], 'safe'],
        ];
    }

    /**
     * Validates `metadata['megaMenu']` (mega-menu-enabled parent config) and
     * `metadata['megaMenuColumn']` (a child's column assignment) — same
     * fail-closed shape-validation pattern as {@see validateVisibility()}.
     * Both are independent of `type`: any item can be a mega-menu parent or
     * a column member, since mega menu is presentation on top of the
     * existing hierarchy, not a separate item type.
     */
    public function validateMegaMenu(): void
    {
        if (!is_array($this->metadata)) {
            return;
        }

        $megaMenu = $this->metadata['megaMenu'] ?? null;

        if ($megaMenu !== null) {
            if (!is_array($megaMenu)) {
                $this->addError('metadata', Craft::t('menu-builder', 'Mega menu configuration must be an object.'));
            } else {
                if (isset($megaMenu['enabled']) && !is_bool($megaMenu['enabled'])) {
                    $this->addError('metadata', Craft::t('menu-builder', 'Mega menu "enabled" must be a boolean.'));
                }

                if (isset($megaMenu['columns']) && (!is_int($megaMenu['columns']) || $megaMenu['columns'] < 1 || $megaMenu['columns'] > 6)) {
                    $this->addError('metadata', Craft::t('menu-builder', 'Mega menu columns must be an integer between 1 and 6.'));
                }
            }
        }

        $column = $this->metadata['megaMenuColumn'] ?? null;

        if ($column !== null && (!is_int($column) || $column < 1 || $column > 6)) {
            $this->addError('metadata', Craft::t('menu-builder', 'Mega menu column must be an integer between 1 and 6.'));
        }
    }

    /**
     * Validates `metadata['mobile']` — the item's mobile-presentation
     * config (see {@see MobileHelper}).
     *
     * Validated even though every reader of it fails closed, for the same
     * reason `megaMenu` is: a value the editor typed and that silently
     * becomes something else is a bug report, not a safe default. The
     * fail-closed reads stay as the backstop for rows this validator never
     * saw — an import, a direct database write, an older release.
     *
     * The order is deliberately *not* rejected for being out of range —
     * {@see MobileHelper::order()} clamps it. A sequence hint is not worth
     * refusing to save an item over; a value of the wrong *kind* is, because
     * it means the form sent something nobody meant.
     */
    public function validateMobile(): void
    {
        if (!is_array($this->metadata)) {
            return;
        }

        $mobile = $this->metadata[MobileHelper::METADATA_KEY] ?? null;

        if ($mobile === null) {
            return;
        }

        if (!is_array($mobile)) {
            $this->addError('metadata', Craft::t('menu-builder', 'Mobile configuration must be an object.'));

            return;
        }

        if (!MobileHelper::isValidVisibility($mobile['visibility'] ?? null)) {
            $this->addError('metadata', Craft::t('menu-builder', 'Mobile visibility must be one of: {values}.', ['values' => implode(', ', MobileHelper::VISIBILITIES)]));
        }

        if (!MobileHelper::isValidOrder($mobile['order'] ?? null)) {
            $this->addError('metadata', Craft::t('menu-builder', 'Mobile order must be a whole number.'));
        }

        if (array_key_exists('collapsible', $mobile) && MobileHelper::collapsible($mobile['collapsible']) === null && $mobile['collapsible'] !== null && $mobile['collapsible'] !== '') {
            $this->addError('metadata', Craft::t('menu-builder', 'Mobile "collapsible" must be a boolean.'));
        }

        if (!MobileHelper::isValidMegaMenuBehavior($mobile['megaMenu'] ?? null)) {
            $this->addError('metadata', Craft::t('menu-builder', 'Mobile mega menu behaviour must be one of: {values}.', ['values' => implode(', ', MobileHelper::MEGA_BEHAVIORS)]));
        }
    }

    /**
     * The item's normalized mobile config, as the CP form and the resolver
     * both read it — `[]` when nothing is configured.
     *
     * @return array{visibility?: string, order?: int, collapsible?: bool, megaMenu?: string}
     */
    public function mobileConfig(): array
    {
        return MobileHelper::config($this->metadata);
    }

    /** The stored mobile visibility, defaulted — what the CP select shows. */
    public function mobileVisibility(): string
    {
        return MobileHelper::visibility($this->mobileConfig()['visibility'] ?? null);
    }

    /** The stored mobile mega-menu behaviour, defaulted — what the CP select shows. */
    public function mobileMegaMenuBehavior(): string
    {
        return MobileHelper::megaMenuBehavior($this->mobileConfig()['megaMenu'] ?? null);
    }

    /**
     * `metadata['dynamicSource']` is required and validated only for
     * `type === TYPE_DYNAMIC` — fails closed (rejects the save) on any
     * malformed shape rather than letting a dynamic item persist with a
     * config `MenuBuilderDynamicNavigationService` can't safely act on.
     */
    public function validateDynamicSource(): void
    {
        if ($this->type !== self::TYPE_DYNAMIC) {
            return;
        }

        if (!is_array($this->metadata) || !is_array($this->metadata['dynamicSource'] ?? null)) {
            $this->addError('metadata', Craft::t('menu-builder', 'A dynamic navigation source configuration is required for this item type.'));

            return;
        }

        $config = $this->metadata['dynamicSource'];

        if (!in_array($config['sourceType'] ?? null, self::DYNAMIC_SOURCE_TYPES, true)) {
            $this->addError('metadata', Craft::t('menu-builder', 'Dynamic source "sourceType" must be one of: {types}.', ['types' => implode(', ', self::DYNAMIC_SOURCE_TYPES)]));
        }

        if (!self::isValidPositiveId($config['sourceId'] ?? null)) {
            $this->addError('metadata', Craft::t('menu-builder', 'Dynamic source "sourceId" must be a positive integer.'));
        }

        if (isset($config['limit']) && (!is_int($config['limit']) || $config['limit'] < 1)) {
            $this->addError('metadata', Craft::t('menu-builder', 'Dynamic source "limit" must be a positive integer.'));
        }

        if (isset($config['orderBy']) && !in_array($config['orderBy'], self::DYNAMIC_SOURCE_ORDER_BY, true)) {
            $this->addError('metadata', Craft::t('menu-builder', 'Dynamic source "orderBy" must be one of: {values}.', ['values' => implode(', ', self::DYNAMIC_SOURCE_ORDER_BY)]));
        }
    }

    /**
     * This item's custom field content element, or null when the owning
     * menu defines no fields.
     *
     * Lazy and memoized: most reads of an item (the CP tree, a hierarchy
     * check, a bulk enable) never touch its fields, and the tree view loads
     * hundreds of items at once — fetching an element per item eagerly
     * would be an N+1 on every one of those paths. The render path does not
     * come through here at all; it batches through
     * {@see \Tahadudhiya\MenuBuilder\services\MenuBuilderItemContentService}.
     *
     * A menu with no field layout returns null rather than an empty element:
     * there is nothing to edit, nothing to save, and no `elements` row to
     * write.
     */
    public function getContent(): ?MenuBuilderItemContent
    {
        if ($this->contentLoaded) {
            return $this->content;
        }

        $this->contentLoaded = true;
        $this->content = MenuBuilder::getInstance()->itemContent->contentForItem($this);

        return $this->content;
    }

    /**
     * Replaces the loaded content element — used by the save path, which
     * builds one from the posted form before the item itself is written.
     */
    public function setContent(?MenuBuilderItemContent $content): void
    {
        $this->content = $content;
        $this->contentLoaded = true;
    }

    /**
     * One custom field value by handle, or null when the menu defines no
     * such field. The CP editor's read; the render path's equivalent is
     * {@see \Tahadudhiya\MenuBuilder\models\MenuBuilderNode::custom()}.
     */
    public function customFieldValue(string $handle): mixed
    {
        $content = $this->getContent();

        if ($content === null || $content->getFieldLayout()?->getFieldByHandle($handle) === null) {
            return null;
        }

        return $content->getFieldValue($handle);
    }

    /**
     * Known visibility rule types and the shape of their config, kept in
     * sync with MenuBuilderVisibilityService's built-in rules. This is
     * defense-in-depth: the CP editor can only ever produce well-formed
     * configs via its checkboxes/selects (ItemsController::buildVisibilityRules),
     * but a directly-posted/imported `visibility` array must not be able to
     * persist a malformed rule that would later fail closed in a confusing
     * way — or, for third-party rule types registered via
     * MenuBuilderVisibilityService::EVENT_REGISTER_VISIBILITY_RULES, an
     * unknown type is accepted here (this model can't know about them) and
     * left to fail closed at evaluation time.
     */
    private const BUILTIN_VISIBILITY_RULE_TYPES = [
        'always', 'loggedIn', 'loggedOut', 'userGroup', 'site', 'dateRange', 'environment',
    ];

    public function validateVisibility(): void
    {
        if (!is_array($this->visibility)) {
            $this->addError('visibility', Craft::t('menu-builder', 'Visibility configuration must be an array of rules.'));

            return;
        }

        foreach ($this->visibility as $index => $ruleConfig) {
            if (!is_array($ruleConfig) || !isset($ruleConfig['type']) || !is_string($ruleConfig['type'])) {
                $this->addError('visibility', Craft::t('menu-builder', 'Visibility rule #{index} is missing a valid "type".', ['index' => $index]));

                continue;
            }

            $type = $ruleConfig['type'];

            // Only the built-in types' shapes are known here; a
            // third-party-registered type is validated by its own rule
            // class at evaluation time instead (fails closed if wrong).
            if (!in_array($type, self::BUILTIN_VISIBILITY_RULE_TYPES, true)) {
                continue;
            }

            match ($type) {
                'userGroup' => $this->validateIdListRule($ruleConfig, 'groupIds', $index),
                'site' => $this->validateIdListRule($ruleConfig, 'siteIds', $index),
                'dateRange' => $this->validateDateRangeRule($ruleConfig, $index),
                'environment' => $this->validateStringListRule($ruleConfig, 'environments', $index),
                default => null,
            };
        }
    }

    /**
     * An empty (or absent) ID list is rejected, not treated as a no-op:
     * UserGroupRule/SiteRule exist only to restrict, so they fail closed on
     * one (an item with nothing to match against would silently vanish from
     * every menu). Erroring at save time turns that into a message the
     * editor or importer can act on, and keeps the model in agreement with
     * how the rule actually evaluates.
     *
     * Shares ConfigHelper::strictIdList() with the rules themselves so the
     * two can't drift on what counts as a valid ID.
     */
    private function validateIdListRule(array $config, string $key, int|string $index): void
    {
        if (empty(ConfigHelper::strictIdList($config[$key] ?? null))) {
            $this->addError('visibility', Craft::t('menu-builder', 'Visibility rule #{index}\'s "{key}" must be a non-empty list of numeric IDs.', ['index' => $index, 'key' => $key]));
        }
    }

    /**
     * Deliberately excludes bool/float — a naive `ctype_digit((string) $v)`
     * cast would accept `true` (casts to `"1"`), silently treating a
     * malformed boolean as ID 1.
     */
    private static function isValidPositiveId(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        return is_string($value) && $value !== '' && ctype_digit($value) && (int)$value > 0;
    }

    /** An empty (or absent) list is rejected, same reasoning as {@see validateIdListRule}. */
    private function validateStringListRule(array $config, string $key, int|string $index): void
    {
        if (empty(ConfigHelper::strictStringList($config[$key] ?? null))) {
            $this->addError('visibility', Craft::t('menu-builder', 'Visibility rule #{index}\'s "{key}" must be a non-empty list of non-empty strings.', ['index' => $index, 'key' => $key]));
        }
    }

    private function validateDateRangeRule(array $config, int|string $index): void
    {
        $start = $config['start'] ?? null;
        $end = $config['end'] ?? null;

        $hasStart = $start !== null && $start !== '';
        $hasEnd = $end !== null && $end !== '';

        if (!$hasStart && !$hasEnd) {
            $this->addError('visibility', Craft::t('menu-builder', 'Visibility rule #{index} needs a start date, an end date, or both.', ['index' => $index]));

            return;
        }

        $startDate = $this->parseDateOrNull($start);
        $endDate = $this->parseDateOrNull($end);

        if ($hasStart && $startDate === null) {
            $this->addError('visibility', Craft::t('menu-builder', 'Visibility rule #{index}\'s start date is invalid.', ['index' => $index]));
        }

        if ($hasEnd && $endDate === null) {
            $this->addError('visibility', Craft::t('menu-builder', 'Visibility rule #{index}\'s end date is invalid.', ['index' => $index]));
        }

        if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
            $this->addError('visibility', Craft::t('menu-builder', 'Visibility rule #{index}\'s start date must be before its end date.', ['index' => $index]));
        }
    }

    /**
     * `mixed` on purpose — a directly-posted/imported `visibility` array
     * isn't guaranteed to even contain strings here, so this is the
     * defensive boundary: anything that isn't a well-formed date string
     * fails closed (null) rather than risking a TypeError, matching
     * DateRangeRule's evaluation-time behavior.
     */
    private function parseDateOrNull(mixed $value): ?\DateTime
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        if (!DateValidationHelper::hasValidCalendarDate($value)) {
            return null;
        }

        try {
            return new \DateTime($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function validateCustomUrl(): void
    {
        if ($this->type !== self::TYPE_URL) {
            return;
        }

        if ($this->customUrl === null || trim($this->customUrl) === '') {
            $this->addError('customUrl', 'A URL is required for this link type.');

            return;
        }

        if (!self::isPermissiveUrl($this->customUrl)) {
            $this->addError('customUrl', 'Enter a valid URL, path, fragment, mailto:, or tel: link.');
        }
    }

    /**
     * An anchor link resolves from the editor's anchor field (`customUrl`),
     * falling back to `handle` — the single source of that precedence is
     * AnchorLinkResolver::anchorTarget(), so validation can never accept a
     * different field than the one that ends up rendered. At least one must
     * be set.
     */
    public function validateAnchorTarget(): void
    {
        if ($this->type !== self::TYPE_ANCHOR) {
            return;
        }

        $target = AnchorLinkResolver::anchorTarget($this);

        if ($target === null) {
            $this->addError('handle', 'An anchor target (handle) is required for this link type.');

            return;
        }

        if (!self::isValidAnchorTarget($target)) {
            $this->addError('handle', 'Enter a valid anchor target — no spaces or quote characters.');
        }
    }

    /**
     * Rejects malformed fragments (quotes, whitespace, angle brackets) while
     * staying permissive about what's otherwise a valid HTML id/fragment. A leading '#' is tolerated since the resolver
     * strips it before use.
     */
    public static function isValidAnchorTarget(string $value): bool
    {
        $value = trim($value);

        if ($value === '' || $value === '#') {
            return false;
        }

        return preg_match('/^#?[^\s"\'<>]+$/', $value) === 1;
    }

    public function validateFallbackUrl(): void
    {
        if ($this->fallbackBehavior === self::FALLBACK_FALLBACK_URL) {
            if ($this->fallbackUrl === null || trim($this->fallbackUrl) === '') {
                $this->addError('fallbackUrl', 'A fallback URL is required for this fallback behavior.');

                return;
            }

            if (!self::isPermissiveUrl($this->fallbackUrl)) {
                $this->addError('fallbackUrl', 'Enter a valid fallback URL.');
            }
        }
    }

    /**
     * Schemes that execute rather than navigate. `filter_var()` is not a
     * safety check: it rejects `javascript:alert(1)` only because that has
     * no authority component, and happily accepts
     * `javascript://example.com%0Aalert(1)` — which browsers do execute,
     * the `%0A` ending the `//` comment. Since a resolved URL is rendered
     * straight into `href` (see `_macros/tree.twig`), where Twig's escaping
     * stops injection but not scheme execution, the scheme is rejected up
     * front instead.
     */
    private const DENIED_URL_SCHEMES = ['javascript', 'data', 'vbscript'];

    /**
     * Accepts absolute URLs, root-relative paths, fragments, and mailto:/tel:
     * links without forcing a scheme onto internal paths.
     */
    public static function isPermissiveUrl(string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
            return false;
        }

        if (self::hasDeniedScheme($value)) {
            return false;
        }

        if (str_starts_with($value, '#')) {
            return strlen($value) > 1;
        }

        if (preg_match('/^(mailto|tel):.+/i', $value)) {
            return true;
        }

        if (str_starts_with($value, '/')) {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Browsers ignore whitespace and control characters embedded in a
     * scheme ("java\tscript:", "\x01javascript:"), so both are stripped
     * before the prefix comparison rather than trusting the literal string.
     */
    private static function hasDeniedScheme(string $value): bool
    {
        $normalized = strtolower((string)preg_replace('/[\s\x00-\x1f\x7f]+/', '', $value));

        foreach (self::DENIED_URL_SCHEMES as $scheme) {
            if (str_starts_with($normalized, $scheme . ':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Defense-in-depth beyond Twig's own output escaping: rejects
     * event-handler-shaped attribute keys and `javascript:`-scheme values so
     * a custom Twig loop that renders `htmlAttributes` unescaped-as-markup
     * can't be turned into script execution by editor input.
     */
    public function validateHtmlAttributes(): void
    {
        if (!is_array($this->htmlAttributes)) {
            $this->addError('htmlAttributes', 'Invalid attributes.');

            return;
        }

        foreach (LinkAttributeHelper::validateHtmlAttributes($this->htmlAttributes) as $error) {
            $this->addError('htmlAttributes', $error);
        }
    }

    /**
     * `htmlId` and `cssClass` end up in exactly the same attribute position
     * as the `htmlAttributes` bag, so they get the same treatment
     * ({@see validateHtmlAttributes()}) rather than being trusted because
     * they happen to have their own column. Twig escapes both on the way
     * out; this is the defence-in-depth half, for the custom template that
     * interpolates them somewhere Twig isn't looking.
     */
    public function validateHtmlId(): void
    {
        if ($this->htmlId !== null && !LinkAttributeHelper::isValidHtmlId($this->htmlId)) {
            $this->addError('htmlId', Craft::t('menu-builder', 'Enter a valid HTML id — no spaces, quotes, or angle brackets.'));
        }
    }

    public function validateCssClass(): void
    {
        if ($this->cssClass !== null && !LinkAttributeHelper::isValidCssClassList($this->cssClass)) {
            $this->addError('cssClass', Craft::t('menu-builder', 'Enter a valid CSS class list — no quotes or angle brackets.'));
        }
    }

    /**
     * Normalizes the icon into its canonical stored form and rejects
     * anything outside the grammar — see {@see IconHelper}. Normalizing
     * here rather than in ItemsController means every write path (CP,
     * console, a future import) stores the same shape, and the rejection
     * is what keeps markup out of the column in the first place.
     */
    public function validateIcon(): void
    {
        $this->icon = IconHelper::normalize($this->icon);

        if ($this->icon !== null && !IconHelper::isValid($this->icon)) {
            $this->addError('icon', Craft::t('menu-builder', 'Enter an icon handle or CSS class list (letters, numbers, spaces and - _ . : /), or pick an asset. Markup isn’t accepted — use an SVG asset instead.'));
        }
    }

    /**
     * Normalizes the badge text and validates `metadata['badgeStyle']`
     * against {@see BadgeHelper::STYLES} — the enum half fails closed at
     * the door, the same way an unknown style would fail closed on read.
     *
     * The badge *text* is never rejected for its characters: it is plain
     * text, escaped where it is rendered (see BadgeHelper's docblock).
     * Its only limit is the varchar(255) one already declared above.
     */
    public function validateBadge(): void
    {
        $this->badge = BadgeHelper::normalizeText($this->badge);

        if (!is_array($this->metadata)) {
            return;
        }

        $style = $this->metadata['badgeStyle'] ?? null;

        if (!BadgeHelper::isValidStyle($style)) {
            $this->addError('badge', Craft::t('menu-builder', 'Badge style must be one of: {styles}.', ['styles' => implode(', ', BadgeHelper::STYLES)]));
        }
    }

    /** The badge's style, or null for the default/none — fail-closed, see {@see BadgeHelper::style()}. */
    public function badgeStyle(): ?string
    {
        return BadgeHelper::style($this->metadata['badgeStyle'] ?? null);
    }

    /** True when this item has badge text to render; a style on its own is not a badge. */
    public function hasBadge(): bool
    {
        return BadgeHelper::hasBadge($this->badge);
    }

    /** `IconHelper::TYPE_CLASS` / `TYPE_ASSET`, or null when there is no usable icon. */
    public function iconType(): ?string
    {
        return IconHelper::type($this->icon);
    }

    /** The icon's class list, or null when the icon is empty, an asset, or (fail-closed) unsafe. */
    public function iconClass(): ?string
    {
        return IconHelper::classValue($this->icon);
    }

    /** The icon's asset id, or null when the icon is empty or a class. */
    public function iconAssetId(): ?int
    {
        return IconHelper::assetId($this->icon);
    }

    public function isLinkable(): bool
    {
        return !in_array($this->type, [self::TYPE_NONCLICKABLE, self::TYPE_SEPARATOR], true);
    }

    public function hasChildren(): bool
    {
        return !empty($this->children);
    }
}
