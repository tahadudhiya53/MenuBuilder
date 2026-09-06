<?php

namespace Tahadudhiya\MenuBuilder\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\Gql as GqlHelper;
use craft\helpers\Json;
use craft\models\GqlSchema;
use craft\models\GqlToken;
use craft\models\Site;
use craft\web\Controller;
use DateTimeZone;
use Tahadudhiya\MenuBuilder\helpers\MenuBuilderApiHelper;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderApiConfig;
use Throwable;
use yii\base\InvalidArgumentException;
use yii\web\Response;

/**
 * The read-only REST API: one resolved menu, or the list of menus this caller may read, as JSON.
*/
class ApiController extends Controller
{
    /**
     * A public menu is public.
    */
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE;

    /**
     * The API's configuration, memoized per request by {@see MenuBuilder::apiConfig()} — the same
     * value the URL rules were registered from, so the route and the controller cannot disagree
     * about whether the endpoint exists.
    */
    public MenuBuilderApiConfig $apiConfig;

    /**
     * The schema this request is answering under, once authenticated.
    */
    private ?GqlSchema $schema = null;

    /**
     * The token that authorized it, or null when the public schema did.
    */
    private ?GqlToken $token = null;

    /** @var array<string,mixed> The validated, normalized query arguments. */
    private array $arguments = [];

    public function beforeAction($action): bool
    {
        $this->apiConfig ??= MenuBuilder::apiConfig();

        $method = strtoupper($this->request->getMethod());

        // CORS first, so even a refusal is readable by the browser that provoked it — a 429 or a
        // 401 that a fetch() can only see as an opaque network error is a support ticket.
        $this->applyCorsHeaders();

        // Defence in depth: with `api.enabled` off, no route reaches this controller at all.
        if (!$this->apiConfig->enabled) {
            return $this->fail(404, MenuBuilderApiHelper::ERROR_NOT_FOUND, 'Not found.');
        }

        // A preflight carries no credentials and must not need any, so it is answered before
        // `parent::beforeAction()` runs.
        if ($method === 'OPTIONS') {
            return $this->preflight();
        }

        // The method gate runs before `parent::beforeAction()`, for a less obvious reason than
        // tidiness: `yii\web\Controller::beforeAction()` validates a CSRF token on every unsafe
        // method, so a POST that got this far would be refused by Craft with an HTML 400 before
        // this controller ever spoke.
        if ($method !== 'GET' && $method !== 'HEAD') {
            $this->response->getHeaders()->set('Allow', 'GET, HEAD, OPTIONS');

            return $this->fail(405, MenuBuilderApiHelper::ERROR_METHOD_NOT_ALLOWED, 'This endpoint is read-only.');
        }

        // Craft's own gates: the site being online, and anonymous access.
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!$this->authenticate()) {
            return false;
        }

        if (!$this->enforceSiteAccess()) {
            return false;
        }

        if (!$this->enforceRateLimit()) {
            return false;
        }

        return $this->validateParams();
    }

    /**
     * `GET {basePath}/v1/navigations` — every menu this caller may read, on the requested site.
    */
    public function actionIndex(): Response
    {
        $site = $this->answeringSite();

        // A `site` that named nothing this caller may have.
        if ($site === null) {
            return $this->errorResponse(404, MenuBuilderApiHelper::ERROR_NOT_FOUND, 'No such site.');
        }

        $trees = MenuBuilder::getInstance()->scope->resolveAll($this->arguments);

        return $this->json(array_map(MenuBuilderApiHelper::serializeTree(...), $trees), $site);
    }

    /**
     * `GET {basePath}/v1/navigations/{handle}` — one menu.
    */
    public function actionView(string $handle): Response
    {
        $site = $this->answeringSite();
        $tree = $site !== null
            ? MenuBuilder::getInstance()->scope->resolveByHandle($handle, $this->arguments)
            : null;

        if ($tree === null) {
            return $this->errorResponse(404, MenuBuilderApiHelper::ERROR_NOT_FOUND, 'No such navigation.');
        }

        return $this->json(MenuBuilderApiHelper::serializeTree($tree), $site);
    }

    // Request handling

    /**
     * The site that will answer this request: the one the `site`/`siteId` parameters named, or the
     * request's own when they named none — and `null` when they named one this caller may not
     * have.
    */
    private function answeringSite(): ?Site
    {
        $requested = MenuBuilder::getInstance()->scope->requestedSite($this->arguments);

        if ($requested === false) {
            return null;
        }

        return $requested ?? Craft::$app->getSites()->getCurrentSite();
    }

    /**
     * Resolves the schema this request answers under, or fails with 401.
    */
    private function authenticate(): bool
    {
        $gql = Craft::$app->getGql();
        $bearer = $this->request->getBearerToken();

        if ($bearer !== null && $bearer !== '') {
            try {
                $token = $gql->getTokenByAccessToken($bearer);
            } catch (InvalidArgumentException) {
                // What Craft's own `getTokenByAccessToken()` throws for a token nobody has — not
                // an exceptional condition here, just the answer.
                $token = null;
            }

            if ($token === null || !$token->getIsValid()) {
                return $this->unauthorized();
            }

            $this->token = $token;
            $this->schema = $token->getSchema();
            $this->touchToken($token);
        } else {
            if (!$this->apiConfig->allowPublicSchema) {
                return $this->unauthorized();
            }

            try {
                $publicToken = $gql->getPublicToken();
            } catch (Throwable $e) {
                // The same treatment Craft's own GraphQL controller gives a public token it can't
                // build: log it, and answer as though there isn't one.
                Craft::warning('Could not obtain the public token: ' . $e->getMessage(), __METHOD__);
                $publicToken = null;
            }

            if ($publicToken === null || !$publicToken->getIsValid()) {
                return $this->unauthorized();
            }

            $this->schema = $publicToken->getSchema();
        }

        if ($this->schema === null) {
            return $this->unauthorized();
        }

        // Everything downstream — MenuBuilderScopeService::canRead(),
        // GqlHelper::getAllowedSites() — reads the *active* schema, which is how one
        // implementation serves both transports.
        Craft::$app->getGql()->setActiveSchema($this->schema);

        return true;
    }

    /**
     * The `lastUsed` bookkeeping Craft's GraphQL controller performs, on the same once-a-minute
     * throttle: a token's last-used stamp is what makes the CP's token list an audit trail rather
     * than a list of names, and a token used only over REST would otherwise look abandoned.
    */
    private function touchToken(GqlToken $token): void
    {
        $now = DateTimeHelper::currentUTCDateTime();

        if (
            $token->lastUsed &&
            $token->lastUsed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i') === $now->format('Y-m-d H:i')
        ) {
            return;
        }

        $token->lastUsed = $now;

        try {
            Craft::$app->getGql()->saveToken($token);
        } catch (Throwable $e) {
            // Bookkeeping, not authorization.
            Craft::warning('Could not update the GraphQL token’s lastUsed date: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * The request's own site must be one the schema may query — the same check
     * `craft\controllers\GraphqlController::_enforceSiteAccess()` makes, and needed for the same
     * reason: without it, a token scoped to one site would get another site's navigation simply by
     * being sent to that site's URL.
    */
    private function enforceSiteAccess(): bool
    {
        $current = Craft::$app->getSites()->getCurrentSite();
        $allowed = array_map(static fn(Site $site) => (int)$site->id, GqlHelper::getAllowedSites($this->schema));

        if (in_array((int)$current->id, $allowed, true)) {
            return true;
        }

        // A fact about the caller's own credential against the URL they chose, not about the
        // install's menus — so this one says what it means rather than hiding behind a 404.
        return $this->fail(403, MenuBuilderApiHelper::ERROR_FORBIDDEN, 'This token cannot read the requested site.');
    }

    /**
     * A fixed-window counter per caller, in Craft's cache.
    */
    private function enforceRateLimit(): bool
    {
        $limit = $this->apiConfig->rateLimit;

        if ($limit <= 0) {
            return true;
        }

        $now = time();
        $window = MenuBuilderApiHelper::rateLimitWindow($now);
        $resetsIn = MenuBuilderApiHelper::rateLimitResetsIn($now);
        $key = MenuBuilderApiHelper::rateLimitKey($this->token?->uid, $this->request->getUserIP(), $window);

        $cache = Craft::$app->getCache();
        $used = (int)$cache->get($key);
        $used++;

        // The window's own remaining life as the TTL, so a key can't outlive the window it counts
        // and leak one caller's budget into the next.
        $cache->set($key, $used, $resetsIn);

        $headers = $this->response->getHeaders();
        $headers->set('X-RateLimit-Limit', (string)$limit);
        $headers->set('X-RateLimit-Remaining', (string)max(0, $limit - $used));
        $headers->set('X-RateLimit-Reset', (string)($now + $resetsIn));

        if ($used > $limit) {
            $headers->set('Retry-After', (string)$resetsIn);

            return $this->fail(429, MenuBuilderApiHelper::ERROR_RATE_LIMITED, 'Too many requests.');
        }

        return true;
    }

    /**
     * Rejects a query parameter that was given but isn't one — the only thing this API answers
     * 400 for.
    */
    private function validateParams(): bool
    {
        $params = $this->request->getQueryParams();
        $invalid = MenuBuilderApiHelper::invalidParam($params);

        if ($invalid !== null) {
            return $this->fail(400, MenuBuilderApiHelper::ERROR_BAD_REQUEST, "Invalid “{$invalid}” parameter.");
        }

        $this->arguments = MenuBuilderApiHelper::arguments($params);

        return true;
    }

    // Responses

    /**
     * A successful response: the envelope, an `ETag`, and the caching and `Vary` headers that make
     * it safe to store.
    */
    private function json(mixed $data, Site $site): Response
    {
        $body = Json::encode(MenuBuilderApiHelper::envelope($data, [
            'id' => (int)$site->id,
            'handle' => $site->handle,
            'language' => $site->language,
        ], $this->arguments));

        $etag = MenuBuilderApiHelper::etag($body);
        $response = $this->response;
        $headers = $response->getHeaders();

        $headers->set('ETag', $etag);
        $headers->set(
            'Cache-Control',
            MenuBuilderApiHelper::cacheControl($this->apiConfig->cacheDuration, $this->token !== null)
        );
        $this->applyVary();

        if (MenuBuilderApiHelper::etagMatches($this->request->getHeaders()->get('If-None-Match'), $etag)) {
            $response->setStatusCode(304);
            $response->format = Response::FORMAT_RAW;
            $response->content = '';

            return $response;
        }

        return $this->raw($body, 200);
    }

    /**
     * An error, as the same JSON envelope every other response uses.
    */
    private function errorResponse(int $status, string $code, string $message): Response
    {
        // An error is about this request, not about the menu — never store it, whatever the
        // configured cache duration is.
        $this->response->getHeaders()->set('Cache-Control', 'no-store');
        $this->applyVary();

        return $this->raw(Json::encode(MenuBuilderApiHelper::error($status, $code, $message)), $status);
    }

    /**
     * {@see errorResponse()} for the `beforeAction()` gates, which answer by populating the
     * response and returning false — the Yii idiom for "the action must not run, send this
     * instead".
    */
    private function fail(int $status, string $code, string $message): bool
    {
        $this->errorResponse($status, $code, $message);

        return false;
    }

    private function unauthorized(): bool
    {
        // RFC 9110 requires a challenge on a 401.
        $this->response->getHeaders()->set('WWW-Authenticate', 'Bearer realm="MenuBuilder API"');

        return $this->fail(401, MenuBuilderApiHelper::ERROR_UNAUTHORIZED, 'A valid access token is required.');
    }

    /**
     * Sends a body that is already encoded, as raw bytes with an explicit content type — and
     * empties it for a `HEAD`, which is the one place a response's headers are the whole answer.
    */
    private function raw(string $body, int $status): Response
    {
        $response = $this->response;
        $response->setStatusCode($status);
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()->set('Content-Type', 'application/json; charset=UTF-8');
        $response->getHeaders()->set('X-MenuBuilder-Api-Version', MenuBuilderApiConfig::RELEASE);
        $response->content = strtoupper($this->request->getMethod()) === 'HEAD' ? '' : $body;

        return $response;
    }

    /**
     * `Vary`, on every response this controller sends.
    */
    private function applyVary(): void
    {
        $this->response->getHeaders()->set('Vary', 'Authorization, Origin');
    }

    /**
     * CORS, strictly from the configured allowlist.
    */
    private function applyCorsHeaders(): void
    {
        $origin = $this->request->getHeaders()->get('Origin');
        $allow = $this->apiConfig->allowOriginHeader(is_string($origin) ? $origin : null);

        $this->applyVary();

        if ($allow === null) {
            return;
        }

        $headers = $this->response->getHeaders();
        $headers->set('Access-Control-Allow-Origin', $allow);
        $headers->set('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS');
        $headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, If-None-Match');
        $headers->set('Access-Control-Expose-Headers', 'ETag, X-MenuBuilder-Api-Version, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After');
        $headers->set('Access-Control-Max-Age', '3600');
    }

    /**
     * A preflight answer: 204, no body, and no authentication — a preflight carries no
     * credentials by definition, so requiring any would make every cross-origin call fail before
     * the real one was ever sent.
    */
    private function preflight(): bool
    {
        $this->response->setStatusCode(204);
        $this->response->format = Response::FORMAT_RAW;
        $this->response->content = '';

        return false;
    }
}
