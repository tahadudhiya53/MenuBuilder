<?php

namespace Tahadudhiya\MenuBuilder\Tests\Integration;

use Craft;
use craft\gql\GqlEntityRegistry;
use craft\models\GqlSchema;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Tahadudhiya\MenuBuilder\gql\MenuBuilderMenuType;

/**
 * The Navigation field through Craft's **real GraphQL layer**: a schema built
 * from the real section, a query executed by Craft's own `Gql` service against
 * the real database.
 *
 * The unit suite asserts what `MenuBuilderMenuType::fieldDefinitions()`
 * returns. That proves the resolvers are right and proves nothing about
 * whether Craft can build a schema from them at all — a type that fails
 * webonyx's own validation, or a field Craft can't attach to an entry type,
 * would pass every unit test and 500 on the first real query.
 */
class MenuBuilderFieldGqlTest extends CraftIntegrationTestCase
{
    private static ?GqlSchema $schema = null;

    /**
     * A schema scoped to the fixture's section, as a real token's schema would
     * be. Craft caches GraphQL types globally in `GqlEntityRegistry`, so the
     * registry is flushed first: a type left behind by another test's schema
     * is the classic cause of an integration GraphQL suite that passes alone
     * and fails in sequence.
     */
    private static function schema(): GqlSchema
    {
        if (self::$schema !== null) {
            return self::$schema;
        }

        GqlEntityRegistry::flush();

        $section = Craft::$app->getEntries()->getSectionByHandle(self::SECTION_HANDLE);

        return self::$schema = new GqlSchema([
            'id' => 1,
            'name' => 'Integration',
            'scope' => [
                "sections.$section->uid:read",
                'entrytypes.' . self::$entryType->uid . ':read',
            ],
        ]);
    }

    /**
     * The selection query every "what comes back" test runs.
     *
     * Deliberately unscoped by site: Craft's GraphQL layer already returns one
     * row per entry rather than one per site (unlike a bare `Entry::find()`),
     * so adding a site argument would only put another of Craft's moving parts
     * between the test and the field. The per-site behaviour is covered
     * separately, against element queries, in MenuBuilderFieldMultiSiteTest.
     */
    private const SELECTION_QUERY = <<<'GQL'
    {
      entries(section: "pages") {
        slug
        ... on page_Entry {
          navigation { uid handle name exists enabled }
        }
      }
    }
    GQL;

    private function selection(): array
    {
        return $this->execute(self::SELECTION_QUERY);
    }

    /** @return array<string,mixed> */
    private function execute(string $query, ?array $variables = null): array
    {
        $result = self::runQuery($query, $variables);

        $this->assertArrayNotHasKey('errors', $result, 'GraphQL errors: ' . json_encode($result['errors'] ?? []));

        return $result;
    }

    /**
     * Craft's element resolvers read the **active** schema, not the one handed
     * to `executeQuery()` — that is what a real `/graphql` request sets from
     * the token. Without setting it, every entry query resolves against an
     * empty scope and returns nothing, which would make each assertion below
     * pass or fail for the wrong reason.
     *
     * @return array<string,mixed>
     */
    private static function runQuery(string $query, ?array $variables = null): array
    {
        $gql = Craft::$app->getGql();
        $gql->setActiveSchema(self::schema());

        try {
            return $gql->executeQuery(self::schema(), $query, $variables, null, true);
        } finally {
            $gql->setActiveSchema(null);
        }
    }

    /** @return array<string,mixed>|null */
    private function navigationFor(string $slug, array $result): ?array
    {
        foreach ($result['data']['entries'] as $entry) {
            if ($entry['slug'] === $slug) {
                return $entry['navigation'];
            }
        }

        $this->fail("No entry with slug \"$slug\" in the GraphQL result.");
    }

    // ---------------------------------------------------------------------
    // Querying the field
    // ---------------------------------------------------------------------

    public function testTheFieldIsQueryableAndReturnsTheSelection(): void
    {
        $result = $this->selection();

        $this->assertSame([
            'uid' => (string)self::$mainMenu->uid,
            'handle' => 'main',
            'name' => 'Main Navigation',
            'exists' => true,
            'enabled' => true,
        ], $this->navigationFor('picks-main', $result));
    }

    public function testAnEmptySelectionComesBackAsNull(): void
    {
        $result = $this->selection();

        $this->assertNull(
            $this->navigationFor('picks-nothing', $result),
            'Nothing selected is null, not an object with null fields.'
        );
    }

    /**
     * A deleted menu is the case a GraphQL consumer most needs to be able to
     * tell apart from "nothing selected", so the UID survives and `exists`
     * reports the truth rather than the whole object collapsing to null.
     */
    public function testADeletedMenuReportsItselfRatherThanVanishing(): void
    {
        $result = $this->selection();

        $this->assertSame([
            'uid' => self::$deletedMenuUid,
            'handle' => null,
            'name' => null,
            'exists' => false,
            'enabled' => false,
        ], $this->navigationFor('picks-doomed', $result));
    }

    public function testADisabledMenuReportsEnabledFalse(): void
    {
        $result = $this->selection();

        $this->assertSame([
            'uid' => (string)self::$disabledMenu->uid,
            'handle' => 'retired',
            'name' => 'Retired Navigation',
            'exists' => true,
            'enabled' => false,
        ], $this->navigationFor('picks-disabled', $result));
    }

    /** The field is queryable as a filter argument too, by UID. */
    public function testEntriesCanBeFilteredByTheSelectedMenusUid(): void
    {
        $result = $this->execute(<<<'GQL'
        query ($uid: [String]) {
          entries(section: "pages", navigation: $uid) {
            slug
          }
        }
        GQL, ['uid' => [(string)self::$mainMenu->uid]]);

        $slugs = array_column($result['data']['entries'], 'slug');
        sort($slugs);

        $this->assertSame(['picks-main', 'picks-main-too'], $slugs);
    }

    // ---------------------------------------------------------------------
    // No tree is exposed
    // ---------------------------------------------------------------------

    /**
     * The load-bearing guarantee. A resolved tree is per-site, per-visitor and
     * per-page; a GraphQL response is shared and cached. Asserting it against
     * the **real, built schema** (not the PHP array the unit test checks) is
     * what proves nothing else in the pipeline — an interface, an event, a
     * Craft default — put one back.
     */
    public function testTheBuiltSchemaExposesNoResolvedTree(): void
    {
        $this->selection();

        $type = GqlEntityRegistry::getEntity(MenuBuilderMenuType::NAME);

        $this->assertInstanceOf(ObjectType::class, $type, 'The type must be in the built schema.');
        $this->assertSame(
            ['uid', 'handle', 'name', 'exists', 'enabled'],
            array_keys($type->getFields()),
            'Exactly the selection — nothing more.'
        );

        foreach (['tree', 'items', 'nodes', 'children', 'menu'] as $forbidden) {
            $this->assertFalse(
                $type->hasField($forbidden),
                "The GraphQL type must not expose \"$forbidden\" — a shared, cached response cannot carry a per-visitor tree."
            );
        }
    }

    /** Asking for a tree has to be an error, not an empty success. */
    public function testAskingForATreeIsARejectedQuery(): void
    {
        $result = self::runQuery(<<<'GQL'
        {
          entries(section: "pages") {
            ... on page_Entry {
              navigation { tree { title } }
            }
          }
        }
        GQL);

        $this->assertArrayHasKey('errors', $result);
        $this->assertStringContainsString('tree', json_encode($result['errors']));
    }

    // ---------------------------------------------------------------------
    // Mutation
    // ---------------------------------------------------------------------

    /**
     * The mutation argument is a plain `String` carrying a menu UID — the same
     * identity the field stores. Asserted off the field instance in the real
     * layout, so it is the type Craft would actually put in a mutation schema.
     */
    public function testTheMutationArgumentTakesAMenuUid(): void
    {
        $argument = self::$fieldInstance->getContentGqlMutationArgumentType();

        $this->assertIsArray($argument);
        $this->assertSame(self::FIELD_HANDLE, $argument['name']);
        $this->assertSame(Type::string(), $argument['type']);
    }

    /**
     * And it round-trips: a UID sent through Craft's own mutation resolution
     * for this field lands in the content column and reads back as the menu.
     *
     * The field's `normalizeValue()`/`serializeValue()` pair is what a mutation
     * goes through, so this drives that pair the way a mutation does rather
     * than re-testing the GraphQL transport Craft owns.
     */
    public function testAUidSentAsAMutationValueIsStoredAndReadBack(): void
    {
        $entry = self::pages()->id(self::$entryIds['picks-nothing'])->one();

        $entry->setFieldValue(self::FIELD_HANDLE, (string)self::$footerMenu->uid);
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry));

        $reloaded = self::pages()->id(self::$entryIds['picks-nothing'])->one();
        $value = $reloaded->getFieldValue(self::FIELD_HANDLE);

        $this->assertSame((string)self::$footerMenu->uid, $value->groupUid);
        $this->assertSame('footer', $value->getHandle());
        $this->assertSame(
            (string)self::$footerMenu->uid,
            self::$field->serializeValue($value, $reloaded),
            'What GraphQL would read back out is the UID it was given.'
        );

        // Restore the fixture.
        $reloaded->setFieldValue(self::FIELD_HANDLE, null);
        $this->assertTrue(Craft::$app->getElements()->saveElement($reloaded));
        $this->assertNull(self::pages()->id(self::$entryIds['picks-nothing'])->one()->getFieldValue(self::FIELD_HANDLE));
    }

    /** A non-UID mutation value is rejected at normalization, not stored. */
    public function testANonUidMutationValueIsNotStored(): void
    {
        $entry = self::pages()->id(self::$entryIds['picks-nothing'])->one();

        // A handle is exactly what a client might send by mistake.
        $entry->setFieldValue(self::FIELD_HANDLE, 'main');
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry));

        $reloaded = self::pages()->id(self::$entryIds['picks-nothing'])->one();

        $this->assertNull(
            $reloaded->getFieldValue(self::FIELD_HANDLE),
            'A raw handle is not an identity this field accepts, so nothing is stored.'
        );
    }
}
