<?php

namespace Tahadudhiya\MenuBuilder\models;

use Craft;
use craft\base\Model;
use Tahadudhiya\MenuBuilder\helpers\CustomFieldHelper;

/**
 * One editor-defined field *definition* — the schema half of custom fields.
 *
 * Definitions belong to a menu, not to an item: every item in a menu is
 * offered the same set, the same way every entry in a section is offered the
 * same field layout. They are persisted inside the menu's existing
 * `settings` bag under {@see MenuBuilderGroupService::CUSTOM_FIELDS_KEY} and
 * lifted back out into `MenuBuilderGroup::$customFields` on read — the same
 * pattern `siteIds` already uses, so no schema change is involved.
 *
 * The *values* live on the item, in `metadata['custom']`. See
 * {@see CustomFieldHelper} for the split and for why nothing here can ever
 * become executable content.
 */
class MenuBuilderCustomField extends Model
{
    public string $handle = '';
    public string $name = '';
    public string $type = CustomFieldHelper::TYPE_TEXT;
    public ?string $instructions = null;
    public bool $required = false;

    /** @var string[] The allowed values for a `select` field; ignored for every other type. */
    public array $options = [];

    protected function defineRules(): array
    {
        return [
            [['handle', 'name'], 'required'],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_]*$/', 'message' => Craft::t('menu-builder', 'Custom field handles must start with a letter and contain only letters, numbers, and underscores.')],
            [['handle'], 'string', 'max' => CustomFieldHelper::MAX_HANDLE_LENGTH],
            [['name'], 'string', 'max' => CustomFieldHelper::MAX_NAME_LENGTH],
            [['instructions'], 'string', 'max' => CustomFieldHelper::MAX_TEXT_LENGTH],
            [['type'], 'in', 'range' => CustomFieldHelper::TYPES],
            [['required'], 'boolean'],
            [['options'], 'validateOptions', 'skipOnEmpty' => false],
            [['options'], 'safe'],
        ];
    }

    /**
     * A `select` with no options can never be satisfied — every value would
     * fail the allowlist — so it is rejected at definition time rather than
     * becoming a field that silently refuses every save. Other types simply
     * ignore whatever is in `options`.
     */
    public function validateOptions(): void
    {
        if (!is_array($this->options) || !array_is_list($this->options)) {
            $this->addError('options', Craft::t('menu-builder', 'Custom field options must be a list of values.'));

            return;
        }

        if (count($this->options) > CustomFieldHelper::MAX_OPTIONS) {
            $this->addError('options', Craft::t('menu-builder', 'A custom field can have at most {max} options.', ['max' => CustomFieldHelper::MAX_OPTIONS]));

            return;
        }

        foreach ($this->options as $option) {
            if (!is_string($option) || trim($option) === '' || mb_strlen($option) > CustomFieldHelper::MAX_OPTION_LENGTH) {
                $this->addError('options', Craft::t('menu-builder', 'Custom field options must be non-empty strings of at most {max} characters.', ['max' => CustomFieldHelper::MAX_OPTION_LENGTH]));

                return;
            }
        }

        if ($this->type === CustomFieldHelper::TYPE_SELECT && $this->options === []) {
            $this->addError('options', Craft::t('menu-builder', 'A dropdown custom field needs at least one option.'));
        }
    }

    /**
     * The persisted shape — a plain array, so a menu's definitions
     * round-trip through the `settings` JSON bag (and through any
     * import/export of it) with no class references in the payload.
     *
     * `options` is omitted for every type that ignores it, so a field
     * switched away from `select` doesn't carry a stale allowlist around.
     *
     * @return array<string,mixed>
     */
    public function toConfig(): array
    {
        $config = [
            'handle' => $this->handle,
            'name' => $this->name,
            'type' => $this->type,
        ];

        if ($this->instructions !== null && $this->instructions !== '') {
            $config['instructions'] = $this->instructions;
        }

        if ($this->required) {
            $config['required'] = true;
        }

        if ($this->type === CustomFieldHelper::TYPE_SELECT) {
            $config['options'] = array_values($this->options);
        }

        return $config;
    }

    /**
     * The inverse of {@see toConfig()}, fail-closed: anything that isn't a
     * well-formed definition reads back as null rather than as a field with
     * a guessed type. A stored bag is not trusted input — it can come from
     * an import or a hand-written database update.
     */
    public static function fromConfig(mixed $config): ?self
    {
        if (!is_array($config)) {
            return null;
        }

        $field = new self();
        $field->handle = is_string($config['handle'] ?? null) ? $config['handle'] : '';
        $field->name = is_string($config['name'] ?? null) ? $config['name'] : '';
        $field->type = is_string($config['type'] ?? null) ? $config['type'] : '';
        $field->instructions = is_string($config['instructions'] ?? null) ? $config['instructions'] : null;
        $field->required = ($config['required'] ?? false) === true;
        $field->options = CustomFieldHelper::normalizeOptions($config['options'] ?? null);

        return $field->validate() ? $field : null;
    }
}
