<?php

namespace Tahadudhiya\MenuBuilder\models;

use Craft;
use craft\base\Model;
use Tahadudhiya\MenuBuilder\helpers\LinkAttributeHelper;

/**
 * A single navigation node. `clickable` is an explicit, independent flag —
 * never inferred from whether a URL/element is set (a "Products" heading can
 * have no link at all, or a link, editor's choice; see MenuBuilderLinkResolver).
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

    /** Phase 8: element sources a `dynamic` item's children can be pulled from. */
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
            // element's own title at render time (spec §14) — an explicit
            // title, once given, is never overwritten by that fallback.
            [['title'], 'required', 'when' => fn($model) => $model->type !== self::TYPE_SEPARATOR && !in_array($model->type, self::ELEMENT_TYPES, true)],
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
            [['rel', 'cssClass', 'htmlId', 'ariaLabel', 'titleAttribute', 'icon', 'badge', 'description'], 'string'],
            [['visibility'], 'validateVisibility', 'skipOnEmpty' => false],
            [['metadata'], 'validateMegaMenu', 'skipOnEmpty' => false],
            [['metadata'], 'validateDynamicSource', 'skipOnEmpty' => false],
            [['htmlAttributes', 'metadata'], 'safe'],
        ];
    }

    /**
     * Validates `metadata['megaMenu']` (mega-menu-enabled parent config) and
     * `metadata['megaMenuColumn']` (a child's column assignment) — same
     * fail-closed shape-validation pattern as {@see validateVisibility()}.
     * Both are independent of `type`: any item can be a mega-menu parent or
     * a column member, since mega menu is presentation on top of the
     * existing hierarchy, not a separate item type (spec §Phase 7).
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
     * Known visibility rule types and the shape of their config, kept in
     * sync with MenuBuilderVisibilityService's built-in rules. This is
     * defense-in-depth: the CP editor can only ever produce well-formed
     * configs via its checkboxes/selects (ItemsController::buildVisibilityRules),
     * but a directly-posted/imported `visibility` array must not be able to
     * persist a malformed rule that would later fail closed in a confusing
     * way — or, for third-party rule types registered via
     * MenuBuilderVisibilityService::EVENT_REGISTER_VISIBILITY_RULES, an
     * unknown type is accepted here (this model can't know about them) and
     * left to fail closed at evaluation time, per spec §10.
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
     * An empty (or absent) ID list is a deliberate no-op, not malformed
     * config: UserGroupRule/SiteRule both treat "no IDs configured" as an
     * unconditional pass, so rejecting an empty list here would make the CP
     * disagree with how the rule actually evaluates.
     */
    private function validateIdListRule(array $config, string $key, int|string $index): void
    {
        if (!isset($config[$key])) {
            return;
        }

        if (!is_array($config[$key])) {
            $this->addError('visibility', Craft::t('menu-builder', 'Visibility rule #{index}\'s "{key}" must be a list of numeric IDs.', ['index' => $index, 'key' => $key]));

            return;
        }

        foreach ($config[$key] as $value) {
            if (!self::isValidPositiveId($value)) {
                $this->addError('visibility', Craft::t('menu-builder', 'Visibility rule #{index}\'s "{key}" must be a list of numeric IDs.', ['index' => $index, 'key' => $key]));

                return;
            }
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

    /** An empty (or absent) list is a valid no-op, same reasoning as {@see validateIdListRule}. */
    private function validateStringListRule(array $config, string $key, int|string $index): void
    {
        if (!isset($config[$key])) {
            return;
        }

        if (!is_array($config[$key]) || array_filter($config[$key], fn($v) => !is_string($v) || trim($v) === '')) {
            $this->addError('visibility', Craft::t('menu-builder', 'Visibility rule #{index}\'s "{key}" must be a list of non-empty strings.', ['index' => $index, 'key' => $key]));
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

        if (!self::hasValidCalendarDate($value)) {
            return null;
        }

        try {
            return new \DateTime($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * DateTime's parser silently normalizes an out-of-range calendar date
     * (e.g. "2026-02-30" becomes March 2) instead of rejecting it — reject
     * any leading Y-m-d component that isn't a real calendar date up front
     * so the CP can't save a date that would later fail closed at render
     * time in a confusing way. Mirrors DateRangeRule::hasValidCalendarDate().
     */
    private static function hasValidCalendarDate(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return true;
        }

        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
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

    /** An anchor link resolves from handle, falling back to customUrl (see AnchorLinkResolver) — at least one must be set. */
    public function validateAnchorTarget(): void
    {
        if ($this->type !== self::TYPE_ANCHOR) {
            return;
        }

        $target = trim((string)$this->handle) !== '' ? trim((string)$this->handle) : trim((string)$this->customUrl);

        if ($target === '') {
            $this->addError('handle', 'An anchor target (handle) is required for this link type.');

            return;
        }

        if (!self::isValidAnchorTarget($target)) {
            $this->addError('handle', 'Enter a valid anchor target — no spaces or quote characters.');
        }
    }

    /**
     * Rejects the malformed fragments spec §7 calls out (quotes, whitespace,
     * angle brackets) while staying permissive about what's otherwise a
     * valid HTML id/fragment. A leading '#' is tolerated since the resolver
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
     * Accepts absolute URLs, root-relative paths, fragments, and mailto:/tel:
     * links without forcing a scheme onto internal paths (spec §12).
     */
    public static function isPermissiveUrl(string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
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
     * Defense-in-depth beyond Twig's own output escaping (spec §16): rejects
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

    public function isLinkable(): bool
    {
        return !in_array($this->type, [self::TYPE_NONCLICKABLE, self::TYPE_SEPARATOR], true);
    }

    public function hasChildren(): bool
    {
        return !empty($this->children);
    }
}
