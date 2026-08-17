<?php

namespace Tahadudhiya\MenuBuilder\models;

use craft\base\Model;

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

    public const TYPES = [
        self::TYPE_ENTRY,
        self::TYPE_CATEGORY,
        self::TYPE_ASSET,
        self::TYPE_URL,
        self::TYPE_ANCHOR,
        self::TYPE_NONCLICKABLE,
        self::TYPE_SEPARATOR,
    ];

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

    public function rules(): array
    {
        return [
            [['groupId'], 'required'],
            [['type'], 'in', 'range' => self::TYPES],
            [['title'], 'required', 'when' => fn($model) => $model->type !== self::TYPE_SEPARATOR],
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
            [['rel', 'cssClass', 'htmlId', 'ariaLabel', 'titleAttribute', 'icon', 'badge', 'description'], 'string'],
            [['htmlAttributes', 'visibility', 'metadata'], 'safe'],
        ];
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

        if (trim((string)$this->handle) === '' && trim((string)$this->customUrl) === '') {
            $this->addError('handle', 'An anchor target (handle) is required for this link type.');
        }
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

    public function isLinkable(): bool
    {
        return !in_array($this->type, [self::TYPE_NONCLICKABLE, self::TYPE_SEPARATOR], true);
    }

    public function hasChildren(): bool
    {
        return !empty($this->children);
    }
}
