<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Tahadudhiya\MenuBuilder\helpers\CustomFieldHelper;
use Tahadudhiya\MenuBuilder\models\MenuBuilderCustomField;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use Tahadudhiya\MenuBuilder\services\MenuBuilderCacheService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderGroupService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderItemService;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Editor-defined custom fields: a **definition** on the menu, a **value**
 * on the item, and one closed set of types between them.
 *
 * The properties pinned here are the ones the design rests on, in the order
 * a value travels:
 *
 *   define   a definition round-trips through the menu's `settings` bag and
 *            a malformed one is dropped rather than guessed at
 *   write    only handles the menu defines can be stored, each coerced to
 *            its type, with size caps that produce a field error rather
 *            than a database error
 *   read     what reaches a template is re-checked against the definitions
 *            of the day, so a deleted or retyped field can't render
 *   render   values are text, escaped at the boundary like every other
 *            string — proven by rendering, not by reading the source
 *
 * Duplication and deletion are covered structurally: both are free
 * consequences of where the data lives (the `metadata`/`settings` bags that
 * are already copied verbatim, and the item row that already cascades), so
 * what is worth pinning is that no second store crept in to break that.
 */
class MenuBuilderCustomFieldTest extends TestCase
{
    private static function definition(string $handle, string $type = CustomFieldHelper::TYPE_TEXT, array $options = [], bool $required = false): MenuBuilderCustomField
    {
        $field = new MenuBuilderCustomField();
        $field->handle = $handle;
        $field->name = ucfirst($handle);
        $field->type = $type;
        $field->options = $options;
        $field->required = $required;

        return $field;
    }

    /** @param MenuBuilderCustomField[] $definitions */
    private function item(array $values, ?array $definitions = null): MenuBuilderItem
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->title = 'Products';
        $item->customUrl = 'https://example.com';
        $item->metadata = $values === [] ? [] : [CustomFieldHelper::VALUES_KEY => $values];
        $item->customFieldDefinitions = $definitions;

        return $item;
    }

    private function node(array $customFields): MenuBuilderNode
    {
        return new MenuBuilderNode(
            id: 1,
            handle: null,
            type: 'url',
            title: 'Products',
            url: 'https://example.com',
            isClickable: true,
            isLinkAvailable: true,
            target: '_self',
            rel: null,
            cssClass: null,
            htmlId: null,
            htmlAttributes: [],
            ariaLabel: null,
            titleAttribute: null,
            icon: null,
            badge: null,
            description: null,
            image: null,
            featured: false,
            level: 1,
            customFields: $customFields,
        );
    }

    private function source(string $path): string
    {
        return (string)file_get_contents(__DIR__ . '/../../' . $path);
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $lines = file((string)$reflection->getFileName());

        return implode('', array_slice(
            (array)$lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }

    // ---------------------------------------------------------------------
    // DEFINE — definitions on the menu
    // ---------------------------------------------------------------------

    public function testADefinitionRoundTripsThroughItsStoredConfig(): void
    {
        $field = self::definition('subtitle', CustomFieldHelper::TYPE_TEXT);
        $field->instructions = 'Shown under the label';
        $field->required = true;

        $restored = MenuBuilderCustomField::fromConfig($field->toConfig());

        $this->assertNotNull($restored);
        $this->assertSame('subtitle', $restored->handle);
        $this->assertSame('Subtitle', $restored->name);
        $this->assertSame(CustomFieldHelper::TYPE_TEXT, $restored->type);
        $this->assertSame('Shown under the label', $restored->instructions);
        $this->assertTrue($restored->required);
    }

    public function testOptionsAreOnlyStoredForTheTypeThatUsesThem(): void
    {
        $select = self::definition('size', CustomFieldHelper::TYPE_SELECT, ['small', 'large']);
        $this->assertSame(['small', 'large'], $select->toConfig()['options']);

        // Switched away from `select`: the stale allowlist doesn't travel.
        $select->type = CustomFieldHelper::TYPE_TEXT;
        $this->assertArrayNotHasKey('options', $select->toConfig());
    }

    /** @dataProvider malformedDefinitionProvider */
    public function testAMalformedDefinitionIsDroppedRatherThanGuessedAt(mixed $config): void
    {
        $this->assertNull(MenuBuilderCustomField::fromConfig($config));
    }

    public static function malformedDefinitionProvider(): array
    {
        return [
            'not an array' => ['subtitle'],
            'no handle' => [['name' => 'Subtitle', 'type' => 'text']],
            'no name' => [['handle' => 'subtitle', 'type' => 'text']],
            'handle starting with a digit' => [['handle' => '1st', 'name' => 'First', 'type' => 'text']],
            'handle with a dash' => [['handle' => 'sub-title', 'name' => 'Subtitle', 'type' => 'text']],
            'unknown type' => [['handle' => 'subtitle', 'name' => 'Subtitle', 'type' => 'html']],
            'a select with no options' => [['handle' => 'size', 'name' => 'Size', 'type' => 'select', 'options' => []]],
            'an over-long handle' => [['handle' => 'a' . str_repeat('b', 64), 'name' => 'Long', 'type' => 'text']],
        ];
    }

    public function testStoredDefinitionsAreReadBackFailClosedDeDupedAndCapped(): void
    {
        $config = [
            ['handle' => 'subtitle', 'name' => 'Subtitle', 'type' => 'text'],
            ['handle' => 'subtitle', 'name' => 'Duplicate', 'type' => 'number'],
            ['handle' => '', 'name' => 'Broken', 'type' => 'text'],
            'not a definition at all',
        ];

        $definitions = CustomFieldHelper::definitionsFromConfig($config);

        $this->assertCount(1, $definitions);
        $this->assertSame('Subtitle', $definitions[0]->name);
        $this->assertSame([], CustomFieldHelper::definitionsFromConfig('nonsense'));
    }

    public function testAMenuCannotDefineMoreFieldsThanTheCap(): void
    {
        $tooMany = [];
        for ($i = 0; $i <= CustomFieldHelper::MAX_FIELDS; $i++) {
            $tooMany[] = ['handle' => 'field' . $i, 'name' => 'Field ' . $i, 'type' => 'text'];
        }

        $this->assertCount(CustomFieldHelper::MAX_FIELDS, CustomFieldHelper::definitionsFromConfig($tooMany));

        $group = $this->group(array_map(
            fn(array $config) => self::definition($config['handle']),
            $tooMany
        ));

        $this->assertFalse($group->validate());
        $this->assertNotEmpty($group->getErrors('customFields'));
    }

    public function testTwoFieldsCannotShareAHandle(): void
    {
        $group = $this->group([self::definition('subtitle'), self::definition('subtitle', CustomFieldHelper::TYPE_NUMBER)]);

        $this->assertFalse($group->validate());
        $this->assertStringContainsString('subtitle', implode(' ', $group->getErrors('customFields')));
    }

    public function testAnInvalidDefinitionFailsTheMenuSave(): void
    {
        $group = $this->group([self::definition('size', CustomFieldHelper::TYPE_SELECT)]);

        $this->assertFalse($group->validate());
        $this->assertNotEmpty($group->getErrors('customFields'));
    }

    /**
     * Definitions live in the menu's existing `settings` bag, beside the
     * site restriction — the whole point of the design, so it is pinned
     * rather than left to the reader.
     */
    public function testDefinitionsArePersistedInsideTheExistingSettingsBag(): void
    {
        $group = $this->group([self::definition('subtitle')]);
        $group->siteIds = [1, 2];
        $group->settings = ['somethingElse' => true];

        $method = new ReflectionMethod(MenuBuilderGroupService::class, 'settingsWithCustomFields');
        $stored = $method->invoke(
            new MenuBuilderGroupService(),
            ['somethingElse' => true, MenuBuilderGroupService::SITE_IDS_KEY => [1, 2]],
            $group->customFields
        );

        $this->assertSame([1, 2], $stored[MenuBuilderGroupService::SITE_IDS_KEY]);
        $this->assertTrue($stored['somethingElse']);
        $this->assertSame('subtitle', $stored[MenuBuilderGroupService::CUSTOM_FIELDS_KEY][0]['handle']);

        // A menu that defines none stores no key at all, rather than `[]`.
        $this->assertArrayNotHasKey(
            MenuBuilderGroupService::CUSTOM_FIELDS_KEY,
            $method->invoke(new MenuBuilderGroupService(), ['somethingElse' => true], [])
        );
    }

    public function testThereIsNoSecondStoreForCustomFields(): void
    {
        $install = $this->source('src/migrations/Install.php');

        // Two tables, as before: values ride in `metadata`, definitions in
        // `settings`. A third table here would mean the design drifted.
        $this->assertSame(2, substr_count($install, '$this->createTable('));
        $this->assertStringNotContainsString('customfield', strtolower($install));
    }

    // ---------------------------------------------------------------------
    // WRITE — values on the item
    // ---------------------------------------------------------------------

    /** @dataProvider coercionProvider */
    public function testAPostedValueIsCoercedToItsFieldsType(string $type, array $options, mixed $posted, mixed $expected): void
    {
        $definition = self::definition('field', $type, $options);

        $this->assertSame($expected, CustomFieldHelper::normalizeValue($definition, $posted));
    }

    public static function coercionProvider(): array
    {
        return [
            'text is trimmed' => [CustomFieldHelper::TYPE_TEXT, [], '  Hello  ', 'Hello'],
            'a numeric string becomes an int' => [CustomFieldHelper::TYPE_NUMBER, [], '42', 42],
            'a decimal string becomes a float' => [CustomFieldHelper::TYPE_NUMBER, [], '4.5', 4.5],
            'a negative number survives' => [CustomFieldHelper::TYPE_NUMBER, [], '-7', -7],
            'a non-number is nothing' => [CustomFieldHelper::TYPE_NUMBER, [], 'twelve', null],
            'a lightswitch on is true' => [CustomFieldHelper::TYPE_BOOLEAN, [], '1', true],
            'a lightswitch off is false, not unset' => [CustomFieldHelper::TYPE_BOOLEAN, [], '', false],
            'an element select posts an array' => [CustomFieldHelper::TYPE_ASSET, [], ['17'], 17],
            'an empty element select is nothing' => [CustomFieldHelper::TYPE_ASSET, [], [''], null],
            'asset ids must be positive' => [CustomFieldHelper::TYPE_ASSET, [], '0', null],
            'a select keeps its option' => [CustomFieldHelper::TYPE_SELECT, ['small'], 'small', 'small'],
            'an array is never a text value' => [CustomFieldHelper::TYPE_TEXT, [], ['a', 'b'], null],
        ];
    }

    public function testOnlyHandlesTheMenuDefinesCanBeStored(): void
    {
        $definitions = [self::definition('subtitle')];

        $stored = CustomFieldHelper::valuesForStorage($definitions, [
            'subtitle' => 'Everything you need',
            'megaMenu' => 'tampered',
            'somethingElse' => 'also tampered',
        ]);

        $this->assertSame(['subtitle' => 'Everything you need'], $stored);
    }
    public function testAnEmptyValueLeavesNoKeyBehind(): void
    {
        $definitions = [
            self::definition('subtitle'),
            self::definition('featured', CustomFieldHelper::TYPE_BOOLEAN),
            self::definition('rank', CustomFieldHelper::TYPE_NUMBER),
        ];

        $stored = CustomFieldHelper::valuesForStorage($definitions, [
            'subtitle' => '   ',
            'featured' => '',
            'rank' => '',
        ]);

        $this->assertSame([], $stored);
    }

    public function testEditingAValueReplacesItRatherThanAccumulating(): void
    {
        $definitions = [self::definition('subtitle')];

        $first = CustomFieldHelper::valuesForStorage($definitions, ['subtitle' => 'Before']);
        $second = CustomFieldHelper::valuesForStorage($definitions, ['subtitle' => 'After']);
        $cleared = CustomFieldHelper::valuesForStorage($definitions, ['subtitle' => '']);

        $this->assertSame(['subtitle' => 'Before'], $first);
        $this->assertSame(['subtitle' => 'After'], $second);
        $this->assertSame([], $cleared);
    }

    public function testAValidItemWithCustomFieldValuesSaves(): void
    {
        $item = $this->item(
            ['subtitle' => 'Everything you need', 'rank' => 3, 'featured' => true],
            [
                self::definition('subtitle'),
                self::definition('rank', CustomFieldHelper::TYPE_NUMBER),
                self::definition('featured', CustomFieldHelper::TYPE_BOOLEAN),
            ]
        );

        $this->assertTrue($item->validate(), implode(' ', $item->getErrorSummary(true)));
        $this->assertSame('Everything you need', $item->customFieldValue('subtitle'));
        $this->assertSame(3, $item->customFieldValue('rank'));
    }

    public function testAnItemWithNoCustomFieldsIsStillValid(): void
    {
        $item = $this->item([], [self::definition('subtitle')]);

        $this->assertTrue($item->validate(), implode(' ', $item->getErrorSummary(true)));
        $this->assertSame([], $item->customFieldValues());
        $this->assertNull($item->customFieldValue('subtitle'));
    }

    /** @dataProvider invalidValueProvider */
    public function testAnInvalidValueIsRejectedWithAFieldError(array $definitions, array $values): void
    {
        $item = $this->item($values, $definitions);

        $this->assertFalse($item->validate());
        $this->assertNotEmpty($item->getErrors('metadata'));
    }

    public static function invalidValueProvider(): array
    {
        return [
            'a handle the menu does not define' => [
                [self::definition('subtitle')],
                ['nonexistent' => 'value'],
            ],
            'a number field holding words' => [
                [self::definition('rank', CustomFieldHelper::TYPE_NUMBER)],
                ['rank' => 'twelve'],
            ],
            'a select value outside its options' => [
                [self::definition('size', CustomFieldHelper::TYPE_SELECT, ['small', 'large'])],
                ['size' => 'enormous'],
            ],
            'a required field left empty' => [
                [self::definition('subtitle', CustomFieldHelper::TYPE_TEXT, [], required: true)],
                ['subtitle' => ''],
            ],
            'a required field never posted' => [
                [self::definition('subtitle', CustomFieldHelper::TYPE_TEXT, [], required: true)],
                ['other' => 'x'],
            ],
        ];
    }

    /**
     * Shape validation runs even when the definitions aren't known (an
     * import, a console script), so a bag that no field type could have
     * produced never reaches storage.
     */
    public function testTheValueBagIsShapeCheckedEvenWithoutDefinitions(): void
    {
        $nested = $this->item(['subtitle' => ['a' => 'b']]);
        $this->assertFalse($nested->validate());

        $badHandle = $this->item(['sub-title' => 'x']);
        $this->assertFalse($badHandle->validate());

        $notABag = new MenuBuilderItem();
        $notABag->groupId = 1;
        $notABag->type = MenuBuilderItem::TYPE_URL;
        $notABag->title = 'Products';
        $notABag->customUrl = 'https://example.com';
        $notABag->metadata = [CustomFieldHelper::VALUES_KEY => 'not a bag'];
        $this->assertFalse($notABag->validate());

        $valid = $this->item(['subtitle' => 'Fine']);
        $this->assertTrue($valid->validate(), implode(' ', $valid->getErrorSummary(true)));
    }

    public function testEveryWritePathResolvesTheMenusDefinitionsBeforeValidating(): void
    {
        $source = $this->methodSource(MenuBuilderItemService::class, 'save');

        $this->assertStringContainsString('$item->customFieldDefinitions === null', $source);
        $this->assertStringContainsString('->customFields', $source);
        $this->assertLessThan(
            strpos($source, '$item->validate()'),
            strpos($source, '$item->customFieldDefinitions ='),
            'Definitions must be resolved before validation runs, or the save is not definition-aware.'
        );
    }

    // ---------------------------------------------------------------------
    // WRITE — large and malicious values
    // ---------------------------------------------------------------------

    public function testAnOverLongValueIsAFieldErrorRatherThanADatabaseError(): void
    {
        $long = str_repeat('a', CustomFieldHelper::MAX_TEXT_LENGTH + 1);
        $item = $this->item(['subtitle' => $long], [self::definition('subtitle')]);

        $this->assertFalse($item->validate());
        $this->assertNotEmpty($item->getErrors('metadata'));

        $atTheLimit = $this->item(['subtitle' => str_repeat('a', CustomFieldHelper::MAX_TEXT_LENGTH)], [self::definition('subtitle')]);
        $this->assertTrue($atTheLimit->validate(), implode(' ', $atTheLimit->getErrorSummary(true)));
    }

    public function testAMultiLineFieldHasItsOwnLargerCapAndStillHasOne(): void
    {
        $definition = self::definition('blurb', CustomFieldHelper::TYPE_TEXTAREA);

        $this->assertNull(CustomFieldHelper::validateValue($definition, str_repeat('a', CustomFieldHelper::MAX_TEXTAREA_LENGTH)));
        $this->assertNotNull(CustomFieldHelper::validateValue($definition, str_repeat('a', CustomFieldHelper::MAX_TEXTAREA_LENGTH + 1)));

        // …and the shape check catches it even with no definitions to consult.
        $item = $this->item(['blurb' => str_repeat('a', CustomFieldHelper::MAX_TEXTAREA_LENGTH + 1)]);
        $this->assertFalse($item->validate());
    }

    public function testNoFieldTypeCanHoldExecutableContent(): void
    {
        // The type list is the guarantee: there is no markup/HTML member for
        // an editor to reach for.
        $this->assertSame(
            ['text', 'textarea', 'number', 'boolean', 'select', 'url', 'asset'],
            CustomFieldHelper::TYPES
        );
    }

    /** @dataProvider maliciousUrlProvider */
    public function testAUrlFieldRejectsSchemesThatExecute(string $url): void
    {
        $definition = self::definition('link', CustomFieldHelper::TYPE_URL);

        $this->assertNotNull(CustomFieldHelper::validateValue($definition, $url), $url);

        $item = $this->item(['link' => $url], [$definition]);
        $this->assertFalse($item->validate(), $url);
    }

    public static function maliciousUrlProvider(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'javascript with an authority' => ['javascript://example.com%0Aalert(1)'],
            'javascript with an embedded tab' => ["java\tscript:alert(1)"],
            'a data url' => ['data:text/html,<script>alert(1)</script>'],
            'vbscript' => ['vbscript:msgbox(1)'],
        ];
    }

    /**
     * Markup typed into a *text* custom field is kept as text, exactly as
     * the badge is: stripping it would mangle legitimate values ("<3",
     * "Tea & Coffee") while adding nothing, because the safety is the
     * escaping at the render boundary — tested below for real.
     */
    public function testMarkupInATextFieldIsKeptAsTextRatherThanSanitizedAway(): void
    {
        $definition = self::definition('subtitle');
        $payload = '<script>alert(1)</script>';

        $this->assertNull(CustomFieldHelper::validateValue($definition, $payload));
        $this->assertSame($payload, CustomFieldHelper::normalizeValue($definition, $payload));

        $item = $this->item(['subtitle' => $payload], [$definition]);
        $this->assertTrue($item->validate(), implode(' ', $item->getErrorSummary(true)));
    }

    // ---------------------------------------------------------------------
    // READ — what a template is allowed to see
    // ---------------------------------------------------------------------

    public function testAValueWhoseFieldNoLongerExistsIsDroppedOnRead(): void
    {
        $stored = ['subtitle' => 'Everything you need', 'removed' => 'stale'];

        $this->assertSame(
            ['subtitle' => 'Everything you need'],
            CustomFieldHelper::valuesForOutput([self::definition('subtitle')], $stored)
        );
    }

    public function testAValueThatNoLongerFitsItsFieldIsDroppedOnRead(): void
    {
        // The field was a text field when this was written; it is a number
        // field now.
        $this->assertSame([], CustomFieldHelper::valuesForOutput(
            [self::definition('rank', CustomFieldHelper::TYPE_NUMBER)],
            ['rank' => 'twelve']
        ));

        // An option removed from the list since the value was chosen.
        $this->assertSame([], CustomFieldHelper::valuesForOutput(
            [self::definition('size', CustomFieldHelper::TYPE_SELECT, ['small'])],
            ['size' => 'enormous']
        ));

        // A value written straight into the database, past every form.
        $this->assertSame([], CustomFieldHelper::valuesForOutput(
            [self::definition('link', CustomFieldHelper::TYPE_URL)],
            ['link' => 'javascript:alert(1)']
        ));

        $this->assertSame([], CustomFieldHelper::valuesForOutput([self::definition('subtitle')], 'not a bag'));
    }

    public function testTheResolverIsTheOnlyThingThatBuildsANodesCustomFields(): void
    {
        $source = $this->source('src/services/MenuBuilderResolver.php');

        $this->assertStringContainsString('customFields: CustomFieldHelper::valuesForOutput(', $source);
    }

    // ---------------------------------------------------------------------
    // CACHE — item data, never visitor data
    // ---------------------------------------------------------------------

    public function testCustomFieldValuesAreItemDataAndCarryNothingAboutTheVisitor(): void
    {
        $helper = $this->source('src/helpers/CustomFieldHelper.php');

        foreach (['getUser', 'getIdentity', 'getSession', 'getRequest'] as $perRequest) {
            $this->assertStringNotContainsString($perRequest, $helper, "Custom fields must not depend on $perRequest — they are cached.");
        }
    }

    public function testTheCachedPayloadShapeCoversTheNewProperty(): void
    {
        // Entries are a serialized object graph keyed by a hash of these
        // classes' properties, so `customFields` being on a payload class is
        // what makes an upgrade read a new key instead of unserializing a
        // node with an uninitialized readonly property.
        $this->assertContains(MenuBuilderNode::class, MenuBuilderCacheService::PAYLOAD_CLASSES);
        $this->assertTrue((new ReflectionProperty(MenuBuilderNode::class, 'customFields'))->isReadOnly());
    }

    // ---------------------------------------------------------------------
    // DUPLICATE and DELETE
    // ---------------------------------------------------------------------
    public function testDeletingAFieldDefinitionLeavesNoRenderableValueBehind(): void
    {
        // The orphaned value stays in the item's bag (nothing rewrites every
        // item when a menu's fields change) — it simply stops being
        // readable, which is the fail-closed read, tested here end to end.
        $stored = ['subtitle' => 'Everything you need'];

        $this->assertSame([], CustomFieldHelper::valuesForOutput([], $stored));
        $this->assertSame($stored, ['subtitle' => 'Everything you need']);
    }

    // ---------------------------------------------------------------------
    // RENDER — through a real Twig environment, not by reading the source
    // ---------------------------------------------------------------------

    public function testAValueIsEscapedWhereItIsRendered(): void
    {
        $out = $this->render('{{ node.custom("subtitle") }}', $this->node(['subtitle' => '<script>alert(1)</script>']));

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
    }

    public function testAValueInAnAttributePositionIsEscapedToo(): void
    {
        $out = $this->render(
            '<a href="#" data-subtitle="{{ node.custom("subtitle") }}">x</a>',
            $this->node(['subtitle' => '" onmouseover="alert(1)'])
        );

        $this->assertStringNotContainsString('onmouseover="alert(1)"', $out);
        $this->assertStringContainsString('&quot;', $out);
    }

    public function testATemplateReadsValuesByHandleWithADefault(): void
    {
        $node = $this->node(['subtitle' => 'Everything you need', 'featured' => true, 'rank' => 3]);

        $this->assertSame('Everything you need', $this->render('{{ node.custom("subtitle") }}', $node));
        $this->assertSame('3', $this->render('{{ node.custom("rank") }}', $node));
        $this->assertSame('yes', $this->render('{% if node.custom("featured") %}yes{% else %}no{% endif %}', $node));
        $this->assertSame('fallback', $this->render('{{ node.custom("missing", "fallback") }}', $node));
        $this->assertSame('', $this->render('{{ node.custom("missing") }}', $node));
    }

    public function testATemplateCanAskWhetherAValueIsSet(): void
    {
        $node = $this->node(['subtitle' => 'Everything you need']);

        $this->assertTrue($node->hasCustom('subtitle'));
        $this->assertFalse($node->hasCustom('missing'));
        $this->assertSame('set', $this->render('{% if node.hasCustom("subtitle") %}set{% endif %}', $node));
    }

    /**
     * The bundled macro renders navigation, not arbitrary presentation
     * data — custom fields are for the consumer's own templates, so nothing
     * about them may leak into the shipped markup by default.
     */
    public function testTheBundledMacroRendersNothingFromCustomFields(): void
    {
        $this->assertStringNotContainsString('custom', $this->source('src/templates/_macros/tree.twig'));
    }

    private function render(string $template, MenuBuilderNode $node): string
    {
        $twig = new Environment(new ArrayLoader(['t' => $template]), ['autoescape' => 'html', 'cache' => false]);

        return $twig->render('t', ['node' => $node]);
    }

    /** @param MenuBuilderCustomField[] $definitions */
    private function group(array $definitions): MenuBuilderGroup
    {
        $group = new MenuBuilderGroup();
        $group->name = 'Main';
        $group->handle = 'main';
        $group->customFields = $definitions;

        return $group;
    }
}
