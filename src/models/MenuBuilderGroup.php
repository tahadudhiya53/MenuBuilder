<?php

namespace Tahadudhiya\MenuBuilder\models;

use craft\base\Model;

/**
 * A named navigation (e.g. "Main Navigation", "Footer Navigation"). Items
 * belong to exactly one group (see MenuBuilderItem::$groupId) and a parent
 * item must always belong to the same group — cross-group parenting is
 * rejected by MenuBuilderItemService.
 */
class MenuBuilderGroup extends Model
{
    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    public ?string $description = null;
    public bool $enabled = true;
    public int $sortOrder = 0;
    public ?int $maxDepth = null;
    public ?string $cssClass = null;

    /** @var array<string,string> Arbitrary HTML attributes for the rendered <nav>/wrapper. */
    public array $htmlAttributes = [];

    /** @var array<string,mixed> Reserved for future frontend-rendering configuration. */
    public array $settings = [];

    public ?string $uid = null;
    public ?string $dateCreated = null;
    public ?string $dateUpdated = null;

    public function rules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_]*$/', 'message' => 'Handle must start with a letter and contain only letters, numbers, and underscores.'],
            [['description', 'cssClass'], 'string'],
            [['enabled'], 'boolean'],
            [['sortOrder'], 'integer'],
            [['maxDepth'], 'integer', 'min' => 1, 'max' => 10],
            [['htmlAttributes', 'settings'], 'safe'],
        ];
    }

    /**
     * Whether a node at the given 1-based nesting level (1 = top level) is
     * still within this group's configured maximum depth. Always true when
     * no maximum is configured.
     */
    public function allowsDepth(int $level): bool
    {
        return $this->maxDepth === null || $level <= $this->maxDepth;
    }
}
