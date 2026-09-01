<?php

namespace Tahadudhiya\MenuBuilder\Tests\Integration;

use Craft;
use craft\helpers\Json;
use craft\models\GqlSchema;
use craft\models\GqlToken;
use craft\web\Request;
use craft\web\Response;
use Tahadudhiya\MenuBuilder\controllers\ApiController;
use Tahadudhiya\MenuBuilder\helpers\MenuBuilderGqlHelper;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderApiConfig;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;

/**
 * The REST API through the **real controller**: a real `craft\web\Request`
 * built from real superglobals, a real `craft\web\Response`, real GraphQL
 * tokens in the database, and real menus resolved through the real pipeline.
 *
 * The unit suite (`Tests\Unit\MenuBuilderApiTest`) proves what the API
 * returns for a given tree and what it refuses for a given parameter. It
 * proves nothing about whether an HTTP request ever reaches any of that:
 * an `allowAnonymous` that locks the endpoint to logged-in users, a token
 * lookup that accepts an expired token, a scope check that passes
 * everything, or a 404 rendered as Craft's HTML error page would all sail
 * through every unit test and only fail against a real install.
 *
 * The suite's application is a console one (see `tests/integration-bootstrap.php`),
 * so the request and response are constructed and handed to the controller
 * explicitly rather than being routed to. That is the only simulated part;
 * everything from `beforeAction()` inward is the code a web request runs.
 */
class MenuBuilderApiTest extends CraftIntegrationTestCase
{
    /** In scope, enabled, with items. */
    private static MenuBuilderGroup $apiMenu;

    /** Real and enabled, deliberately left out of the schema's scope. */
    private static MenuBuilderGroup $unscopedMenu;

    /** In scope, but disabled. */
    private static MenuBuilderGroup $disabledApiMenu;

    /** In scope, but restricted to the second site. */
    private static MenuBuilderGroup $secondSiteMenu;

    private static ?GqlToken $token = null;

    /** A token whose schema names the menus but only the primary site. */
    private static ?GqlToken $primarySiteToken = null;

    private static ?GqlToken $expiredToken = null;

    private static bool $apiFixtureLoaded = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$apiFixtureLoaded) {
            return;
        }

        self::$apiMenu = self::createMenu('apinav', 'API Navigation');
        self::$unscopedMenu = self::createMenu('apisecret', 'Not In Scope');
        self::$disabledApiMenu = self::createMenu('apiretired', 'Retired', enabled: false);
        self::$secondSiteMenu = self::createMenu('apisecondary', 'Second Site Only', siteIds: [self::$secondSiteId]);

        self::addApiItem(self::$apiMenu, 'Home', '/');

        $about = self::addApiItem(self::$apiMenu, 'About', '/about');
        self::addApiItem(self::$apiMenu, 'Team', '/about/team', parentId: (int)$about->id);

        // The audience gates. An API response is resolved for nobody, so the
        // members-only item must never appear and the logged-out one always
        // must — whoever is asking.
        self::addApiItem(self::$apiMenu, 'Members', '/members', visibility: [['type' => 'loggedIn']]);
        self::addApiItem(self::$apiMenu, 'Sign in', '/login', visibility: [['type' => 'loggedOut']]);

        // Never resolved at all: the tree is built with includeDisabled: false.
        self::addApiItem(self::$apiMenu, 'Draft', '/draft', enabled: false);

        self::addApiItem(self::$unscopedMenu, 'Secret', '/secret');
        self::addApiItem(self::$disabledApiMenu, 'Old', '/old');
        self::addApiItem(self::$secondSiteMenu, 'Zweite', '/zweite');

        self::$token = self::createToken('api-test-token', self::menuScope(self::allSiteUids()));
        self::$primarySiteToken = self::createToken('api-primary-token', self::menuScope([
            Craft::$app->getSites()->getPrimarySite()->uid,
        ]));
        self::$expiredToken = self::createToken(
            'api-expired-token',
            self::menuScope(self::allSiteUids()),
            expired: true,
        );

        self::$apiFixtureLoaded = true;
    }

    protected function tearDown(): void
    {
        Craft::$app->getGql()->setActiveSchema(null);

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // A valid request
    // ---------------------------------------------------------------------

    public function testAValidRequestReturnsTheMenu(): void
    {
        $response = $this->get('apinav');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json; charset=UTF-8', $response->getHeaders()->get('Content-Type'));
        $this->assertSame(MenuBuilderApiConfig::RELEASE, $response->getHeaders()->get('X-MenuBuilder-Api-Version'));

        $body = $this->body($response);

        $this->assertSame(MenuBuilderApiConfig::RELEASE, $body['meta']['apiVersion']);
        $this->assertSame('default', $body['meta']['site']['handle']);
        $this->assertSame('apinav', $body['data']['handle']);
        $this->assertSame('API Navigation', $body['data']['name']);
        $this->assertSame(self::$apiMenu->uid, $body['data']['uid']);
    }

    public function testItemsCarryTheirHierarchyAndUrls(): void
    {
        $items = $this->body($this->get('apinav'))['data']['items'];
        $titles = array_column($items, 'title');

        $this->assertSame(['Home', 'About', 'Sign in'], $titles);

        $about = $items[1];
        $this->assertSame('/about', $about['url']);
        $this->assertTrue($about['hasChildren']);
        $this->assertSame('Team', $about['children'][0]['title']);
        $this->assertSame(2, $about['children'][0]['level']);
    }

    /** The row IDs the resolver carries for Twig's benefit must not be in the JSON. */
    public function testTheResponseCarriesNoRowIds(): void
    {
        $body = $this->body($this->get('apinav'));

        $this->assertArrayNotHasKey('id', $body['data']);

        foreach ($body['data']['items'] as $item) {
            $this->assertArrayNotHasKey('id', $item);
        }

        $this->assertStringNotContainsString('"id"', Json::encode($body['data']));
    }

    public function testTheListEndpointReturnsOnlyTheMenusInScope(): void
    {
        $body = $this->body($this->get());
        $handles = array_column($body['data'], 'handle');

        $this->assertContains('apinav', $handles);

        // Out of scope, disabled, and restricted to another site: three
        // different reasons, one indistinguishable absence.
        $this->assertNotContains('apisecret', $handles);
        $this->assertNotContains('apiretired', $handles);
        $this->assertNotContains('apisecondary', $handles);
    }

    // ---------------------------------------------------------------------
    // Every refusal is the same refusal
    // ---------------------------------------------------------------------

    /**
     * @dataProvider unservableMenus
     */
    public function testUnservableMenusAreAllTheSame404(string $handle): void
    {
        $response = $this->get($handle);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([
            'error' => ['status' => 404, 'code' => 'not_found', 'message' => 'No such navigation.'],
        ], $this->body($response));
    }

    public static function unservableMenus(): array
    {
        return [
            'never existed' => ['nosuchmenu'],
            'out of the schema’s scope' => ['apisecret'],
            'disabled' => ['apiretired'],
            'restricted to another site' => ['apisecondary'],
            'not a handle at all' => ['not-a-handle'],
            'a path traversal attempt' => ['..%2F..%2Fetc'],
            'an injection attempt' => ["main' OR 1=1--"],
        ];
    }

    /** A refusal is JSON, not Craft's HTML error template. */
    public function testEvenA404IsJson(): void
    {
        $response = $this->get('nosuchmenu');

        $this->assertSame('application/json; charset=UTF-8', $response->getHeaders()->get('Content-Type'));
        $this->assertSame('no-store', $response->getHeaders()->get('Cache-Control'));
    }

    // ---------------------------------------------------------------------
    // Authentication
    // ---------------------------------------------------------------------

    public function testAnInvalidTokenIsRefused(): void
    {
        $response = $this->get('apinav', token: 'not-a-real-token');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('unauthorized', $this->body($response)['error']['code']);
        $this->assertStringContainsString('Bearer', (string)$response->getHeaders()->get('WWW-Authenticate'));
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        $response = $this->get('apinav', token: (string)self::$expiredToken->accessToken);

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * A missing token and a wrong one are the same 401: the difference
     * between a typo and a guess only benefits the guesser.
     */
    public function testNoTokenIsRefusedWhenThePublicSchemaIsTurnedOff(): void
    {
        $response = $this->request('view', ['handle' => 'apinav'], config: self::config(['allowPublicSchema' => false]), token: '');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('unauthorized', $this->body($response)['error']['code']);
    }

    /** A valid token's `lastUsed` is recorded, so the CP's token list stays an audit trail. */
    public function testAValidTokenIsMarkedAsUsed(): void
    {
        $token = self::createToken('api-lastused-token', self::menuScope(self::allSiteUids()));
        $this->assertNull($token->lastUsed);

        $this->get('apinav', token: (string)$token->accessToken);

        $reloaded = Craft::$app->getGql()->getTokenById((int)$token->id);
        $this->assertNotNull($reloaded?->lastUsed);
    }

    /**
     * A token scoped to one site cannot read another site's navigation simply
     * by naming it — the same boundary Craft's own `site` argument enforces.
     */
    public function testATokenCannotReachASiteItsSchemaExcludes(): void
    {
        $response = $this->get('apinav', params: ['siteId' => (string)self::$secondSiteId], token: (string)self::$primarySiteToken->accessToken);

        $this->assertSame(404, $response->getStatusCode());
    }

    // ---------------------------------------------------------------------
    // Sites
    // ---------------------------------------------------------------------

    public function testTheSiteParameterSelectsTheSiteThatAnswers(): void
    {
        $body = $this->body($this->get('apisecondary', params: ['siteId' => (string)self::$secondSiteId]));

        $this->assertSame('apisecondary', $body['data']['handle']);
        $this->assertSame(self::$secondSiteId, $body['meta']['site']['id']);
        $this->assertSame('Zweite', $body['data']['items'][0]['title']);
    }

    public function testTheListEndpointRefusesASiteItCannotAnswerFor(): void
    {
        $response = $this->request('index', params: ['site' => 'nosuchsite']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('not_found', $this->body($response)['error']['code']);
    }

    public function testASiteThatDoesNotExistIsNotFound(): void
    {
        $this->assertSame(404, $this->get('apinav', params: ['site' => 'nosuchsite'])->getStatusCode());
        $this->assertSame(404, $this->get('apinav', params: ['siteId' => '99999'])->getStatusCode());
    }

    public function testASiteNamedTwiceMustAgreeWithItself(): void
    {
        $response = $this->get('apinav', params: [
            'site' => 'default',
            'siteId' => (string)self::$secondSiteId,
        ]);

        $this->assertSame(404, $response->getStatusCode());
    }

    // ---------------------------------------------------------------------
    // Visibility — the audience is nobody
    // ---------------------------------------------------------------------

    public function testTheAudienceIsAlwaysAnonymous(): void
    {
        $titles = array_column($this->body($this->get('apinav'))['data']['items'], 'title');

        // Restricted to logged-in users: absent, because a shared response
        // cannot carry one caller's visibility decision.
        $this->assertNotContains('Members', $titles);

        // Restricted to logged-out visitors: always present.
        $this->assertContains('Sign in', $titles);

        // Disabled items never reach the tree at all.
        $this->assertNotContains('Draft', $titles);
    }

    // ---------------------------------------------------------------------
    // Malformed input
    // ---------------------------------------------------------------------

    /**
     * @dataProvider malformedParams
     */
    public function testAMalformedParameterIsA400ThatNamesIt(array $params, string $expected): void
    {
        $response = $this->get('apinav', params: $params);
        $error = $this->body($response)['error'];

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('bad_request', $error['code']);
        $this->assertStringContainsString($expected, $error['message']);
    }

    public static function malformedParams(): array
    {
        return [
            'site is not a handle' => [['site' => 'de-DE'], 'site'],
            'site is blank' => [['site' => ''], 'site'],
            'siteId is not a number' => [['siteId' => 'two'], 'siteId'],
            'viewport is a typo' => [['viewport' => 'mobil'], 'viewport'],
            'currentUri is blank' => [['currentUri' => ''], 'currentUri'],
        ];
    }

    public function testUnknownParametersAreIgnored(): void
    {
        $response = $this->get('apinav', params: ['depth' => '2', 'callback' => 'alert(1)']);

        $this->assertSame(200, $response->getStatusCode());
    }

    // ---------------------------------------------------------------------
    // Reshaping and active state
    // ---------------------------------------------------------------------

    public function testCurrentUriMarksActiveState(): void
    {
        $body = $this->body($this->get('apinav', params: ['currentUri' => 'about']));
        $about = $body['data']['items'][1];

        $this->assertSame('about', $body['meta']['currentUri']);
        $this->assertTrue($about['isActive']);
        $this->assertFalse($body['data']['items'][0]['isActive']);
    }

    public function testActiveStateIsAllFalseWithoutACurrentUri(): void
    {
        $body = $this->body($this->get('apinav'));

        $this->assertNull($body['meta']['currentUri']);

        foreach ($body['data']['items'] as $item) {
            $this->assertFalse($item['isActive']);
            $this->assertFalse($item['isActiveAncestor']);
        }
    }

    public function testTheViewportParameterReshapesTheMenu(): void
    {
        $body = $this->body($this->get('apinav', params: ['viewport' => 'mobile']));

        $this->assertSame('mobile', $body['meta']['viewport']);
    }

    // ---------------------------------------------------------------------
    // Caching
    // ---------------------------------------------------------------------

    public function testAResponseIsRevalidatableAndNotStoredByDefault(): void
    {
        $response = $this->get('apinav');
        $headers = $response->getHeaders();

        $this->assertSame('no-store', $headers->get('Cache-Control'));
        $this->assertMatchesRegularExpression('/^"[0-9a-f]+"$/', (string)$headers->get('ETag'));
        $this->assertSame('Authorization, Origin', $headers->get('Vary'));
    }

    public function testAMatchingIfNoneMatchIsA304WithNoBody(): void
    {
        $etag = (string)$this->get('apinav')->getHeaders()->get('ETag');

        $response = $this->request('view', ['handle' => 'apinav'], headers: ['HTTP_IF_NONE_MATCH' => $etag]);

        $this->assertSame(304, $response->getStatusCode());
        $this->assertSame('', (string)$response->content);
    }

    public function testAStaleIfNoneMatchIsA200(): void
    {
        $response = $this->request('view', ['handle' => 'apinav'], headers: ['HTTP_IF_NONE_MATCH' => '"stale"']);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * A token's schema can name menus the public schema cannot, so a
     * token-authenticated response must never be storable by a shared cache.
     */
    public function testAConfiguredDurationIsPrivateForATokenAndPublicWithout(): void
    {
        $config = self::config(['cacheDuration' => 300]);

        $authenticated = $this->request('view', ['handle' => 'apinav'], config: $config, token: (string)self::$token->accessToken);
        $this->assertSame('private, max-age=300', $authenticated->getHeaders()->get('Cache-Control'));

        // No token: the public schema answers (or refuses) — either way the
        // response is not tied to a credential.
        $anonymous = $this->request('view', ['handle' => 'apinav'], config: $config, token: '');
        $this->assertStringNotContainsString('private', (string)$anonymous->getHeaders()->get('Cache-Control'));
    }

    /** The same menu, resolved twice, is byte-identical — which is what makes an ETag meaningful. */
    public function testTheSameRequestProducesTheSameEntityTag(): void
    {
        $this->assertSame(
            (string)$this->get('apinav')->getHeaders()->get('ETag'),
            (string)$this->get('apinav')->getHeaders()->get('ETag'),
        );
    }

    public function testADifferentMenuProducesADifferentEntityTag(): void
    {
        $this->assertNotSame(
            (string)$this->get('apinav')->getHeaders()->get('ETag'),
            (string)$this->get('apinav', params: ['currentUri' => 'about'])->getHeaders()->get('ETag'),
        );
    }

    // ---------------------------------------------------------------------
    // Methods, CORS and the master switch
    // ---------------------------------------------------------------------

    /**
     * @dataProvider writeMethods
     */
    public function testWriteMethodsAreRefusedBeforeAnythingElseRuns(string $method): void
    {
        $response = $this->request('view', ['handle' => 'apinav'], method: $method);

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('method_not_allowed', $this->body($response)['error']['code']);
        $this->assertSame('GET, HEAD, OPTIONS', $response->getHeaders()->get('Allow'));
    }

    public static function writeMethods(): array
    {
        return [['POST'], ['PUT'], ['PATCH'], ['DELETE']];
    }

    public function testHeadAnswersWithHeadersAndNoBody(): void
    {
        $response = $this->request('view', ['handle' => 'apinav'], method: 'HEAD');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', (string)$response->content);
        $this->assertNotEmpty($response->getHeaders()->get('ETag'));
    }

    public function testNoCorsHeadersWithoutAnAllowlist(): void
    {
        $response = $this->request('view', ['handle' => 'apinav'], headers: ['HTTP_ORIGIN' => 'https://app.test']);

        $this->assertNull($response->getHeaders()->get('Access-Control-Allow-Origin'));
        $this->assertSame('Authorization, Origin', $response->getHeaders()->get('Vary'));
    }

    public function testAnAllowlistedOriginGetsCorsHeaders(): void
    {
        $response = $this->request(
            'view',
            ['handle' => 'apinav'],
            config: self::config(['allowedOrigins' => ['https://app.test']]),
            headers: ['HTTP_ORIGIN' => 'https://app.test'],
        );

        $this->assertSame('https://app.test', $response->getHeaders()->get('Access-Control-Allow-Origin'));
        $this->assertNull($response->getHeaders()->get('Access-Control-Allow-Credentials'));
    }

    public function testAnUnlistedOriginGetsNone(): void
    {
        $response = $this->request(
            'view',
            ['handle' => 'apinav'],
            config: self::config(['allowedOrigins' => ['https://app.test']]),
            headers: ['HTTP_ORIGIN' => 'https://evil.test'],
        );

        $this->assertNull($response->getHeaders()->get('Access-Control-Allow-Origin'));
    }

    /** A preflight carries no credentials, so it must not need any. */
    public function testAPreflightIsAnsweredWithoutAuthentication(): void
    {
        $response = $this->request(
            'view',
            ['handle' => 'apinav'],
            method: 'OPTIONS',
            config: self::config(['allowedOrigins' => ['https://app.test'], 'allowPublicSchema' => false]),
            headers: ['HTTP_ORIGIN' => 'https://app.test'],
        );

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', (string)$response->content);
        $this->assertSame('https://app.test', $response->getHeaders()->get('Access-Control-Allow-Origin'));
        $this->assertSame('GET, HEAD, OPTIONS', $response->getHeaders()->get('Access-Control-Allow-Methods'));
    }

    /**
     * Defence in depth: with the API off there is no route to this
     * controller, but if one is reached anyway it answers as though the
     * endpoint doesn't exist — because as far as the install has said, it
     * doesn't.
     */
    public function testADisabledApiAnswersNothingAtAll(): void
    {
        $response = $this->request(
            'view',
            ['handle' => 'apinav'],
            config: MenuBuilderApiConfig::fromArray([]),
            token: (string)self::$token->accessToken,
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not found.', $this->body($response)['error']['message']);
    }

    // ---------------------------------------------------------------------
    // Rate limiting
    // ---------------------------------------------------------------------

    public function testTheRateLimitIsEnforcedAndAnnounced(): void
    {
        $config = self::config(['rateLimit' => 3]);
        $ip = '198.51.100.' . random_int(2, 254);

        for ($i = 1; $i <= 3; $i++) {
            $response = $this->request('view', ['handle' => 'apinav'], config: $config, ip: $ip);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('3', $response->getHeaders()->get('X-RateLimit-Limit'));
            $this->assertSame((string)(3 - $i), $response->getHeaders()->get('X-RateLimit-Remaining'));
        }

        $blocked = $this->request('view', ['handle' => 'apinav'], config: $config, ip: $ip);

        $this->assertSame(429, $blocked->getStatusCode());
        $this->assertSame('rate_limited', $this->body($blocked)['error']['code']);
        $this->assertNotEmpty($blocked->getHeaders()->get('Retry-After'));
    }

    public function testARateLimitOfZeroIsNoLimit(): void
    {
        $config = self::config(['rateLimit' => 0]);
        $ip = '198.51.100.' . random_int(2, 254);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->request('view', ['handle' => 'apinav'], config: $config, ip: $ip);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertNull($response->getHeaders()->get('X-RateLimit-Limit'));
        }
    }

    // ---------------------------------------------------------------------
    // Driving the controller
    // ---------------------------------------------------------------------

    /**
     * `GET {prefix}/navigations/{handle}`, or the list endpoint when no
     * handle is given.
     *
     * @param array<string,string> $params
     */
    private function get(?string $handle = null, array $params = [], ?string $token = null): Response
    {
        return $handle === null
            ? $this->request('index', [], $params)
            : $this->request('view', ['handle' => $handle], $params, token: $token);
    }

    /**
     * Builds a real `craft\web\Request` from real superglobals, hands it to a
     * real `ApiController`, and returns the response it produced.
     *
     * @param array<string,mixed> $actionParams
     * @param array<string,string> $params
     * @param array<string,string> $headers Raw `$_SERVER` header entries (`HTTP_*`).
     * @param string|null $token The bearer token to send; the shared fixture token by
     *                           default, and `''` for a request that carries none.
     */
    private function request(
        string $action,
        array $actionParams = [],
        array $params = [],
        string $method = 'GET',
        ?MenuBuilderApiConfig $config = null,
        ?string $token = null,
        array $headers = [],
        string $ip = '203.0.113.1',
    ): Response {
        $server = $_SERVER;
        $get = $_GET;

        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = '/api/menu-builder/v1/navigations';
        $_SERVER['SERVER_NAME'] = 'primary.test';
        $_SERVER['HTTP_HOST'] = 'primary.test';
        $_SERVER['REMOTE_ADDR'] = $ip;

        foreach (['HTTP_AUTHORIZATION', 'HTTP_ORIGIN', 'HTTP_IF_NONE_MATCH'] as $header) {
            unset($_SERVER[$header]);
        }

        $token ??= (string)self::$token->accessToken;

        if ($token !== '') {
            $_SERVER['HTTP_AUTHORIZATION'] = "Bearer $token";
        }

        foreach ($headers as $name => $value) {
            $_SERVER[$name] = $value;
        }

        $_GET = $params;

        try {
            $response = new Response();

            $controller = new ApiController('api', MenuBuilder::getInstance(), [
                'request' => new Request(['isConsoleRequest' => false]),
                'response' => $response,
                // Rate limiting is off unless a test is exercising it: the
                // limiter's counter is keyed by caller and window, so a
                // shared default budget would make every other test's result
                // depend on how many ran before it in the same minute.
                'apiConfig' => $config ?? self::config(['rateLimit' => 0]),
            ]);

            $controller->runAction($action, $actionParams);

            return $response;
        } finally {
            $_SERVER = $server;
            $_GET = $get;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function body(Response $response): array
    {
        return Json::decode((string)$response->content);
    }

    // ---------------------------------------------------------------------
    // Fixture
    // ---------------------------------------------------------------------

    /** @param array<string,mixed> $api */
    private static function config(array $api): MenuBuilderApiConfig
    {
        return MenuBuilderApiConfig::fromArray(['api' => ['enabled' => true] + $api]);
    }

    /**
     * A schema scoped the way a real token's would be: the API menus, and the
     * named sites. `apisecret` is deliberately absent — that omission is what
     * the scope tests assert on.
     *
     * @param string[] $siteUids
     * @return string[]
     */
    private static function menuScope(array $siteUids): array
    {
        $scope = [];

        foreach ([self::$apiMenu, self::$disabledApiMenu, self::$secondSiteMenu] as $menu) {
            $scope[] = MenuBuilderGqlHelper::scopeComponent((string)$menu->uid) . ':read';
        }

        foreach ($siteUids as $uid) {
            $scope[] = "sites.$uid:read";
        }

        return $scope;
    }

    /**
     * @return string[]
     */
    private static function allSiteUids(): array
    {
        return array_map(static fn($site) => (string)$site->uid, Craft::$app->getSites()->getAllSites());
    }

    /**
     * A real schema and a real token, saved through Craft's own service — the
     * only way `getTokenByAccessToken()` can find one.
     *
     * @param string[] $scope
     */
    private static function createToken(string $name, array $scope, bool $expired = false): GqlToken
    {
        $gql = Craft::$app->getGql();

        $schema = new GqlSchema(['name' => "$name schema", 'scope' => $scope]);

        if (!$gql->saveSchema($schema)) {
            throw new \RuntimeException("Could not save schema: " . json_encode($schema->getErrors()));
        }

        $token = new GqlToken([
            'name' => $name,
            'accessToken' => $name . '-' . bin2hex(random_bytes(8)),
            'enabled' => true,
            'schemaId' => $schema->id,
            'expiryDate' => $expired ? new \DateTime('-1 day') : null,
        ]);

        if (!$gql->saveToken($token)) {
            throw new \RuntimeException("Could not save token: " . json_encode($token->getErrors()));
        }

        return $token;
    }

    /**
     * @param array<int,array<string,mixed>> $visibility
     */
    private static function addApiItem(
        MenuBuilderGroup $group,
        string $title,
        string $url,
        array $visibility = [],
        bool $enabled = true,
        ?int $parentId = null,
    ): MenuBuilderItem {
        $item = new MenuBuilderItem();
        $item->groupId = (int)$group->id;
        $item->parentId = $parentId;
        $item->title = $title;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->customUrl = $url;
        $item->enabled = $enabled;
        $item->visibility = $visibility;

        if (!MenuBuilder::getInstance()->items->save($item)) {
            throw new \RuntimeException("Could not create item \"$title\": " . json_encode($item->getErrors()));
        }

        return $item;
    }
}
