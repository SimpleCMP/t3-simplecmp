<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use SimpleCMP\T3SimpleCmp\Domain\Repository\DetectionRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\Service\BridgeNonceService;
use SimpleCMP\T3SimpleCmp\Service\ClassifierLookup;
use SimpleCMP\T3SimpleCmp\Service\BridgeNonceVerification;
use SimpleCMP\T3SimpleCmp\Service\BridgeRateLimiter;
use SimpleCMP\T3SimpleCmp\Service\BridgeSecretProvider;
use SimpleCMP\T3SimpleCmp\Service\StoragePidResolver;
use SimpleCMP\T3SimpleCmp\Service\WebhookPayloadValidator;
use SimpleCMP\T3SimpleCmp\Service\WebhookRequestGuard;

/**
 * Implements the SimpleCMP Service-DB protocol
 * (`docs/service-db-protocol.md` in the upstream simplecmp repo) for
 * TYPO3-managed registries. Mounted as a frontend middleware so the
 * routes resolve without TYPO3's site resolver / TSFE bootstrap.
 *
 * Routes:
 *   GET  /api/simplecmp/v1/health           — protocol health response
 *   GET  /api/simplecmp/v1/services         — paginated registry listing
 *   POST /api/simplecmp/v1/lookup           — batch query: cookies + origins
 *
 * Non-matching paths fall through to the next middleware. Read-only in
 * v0; auth and write endpoints come with the admin UI in iteration 3.
 */
final readonly class ServiceDbApi implements MiddlewareInterface
{
    private const string API_PREFIX = '/api/simplecmp';
    private const string V1_PREFIX = '/api/simplecmp/v1';
    private const string WEBHOOK_PATH = '/api/simplecmp/webhook';
    private const int SCHEMA_VERSION = 1;
    private const int DEFAULT_LIMIT = 100;
    private const int MAX_LIMIT = 500;

    public function __construct(
        private ServiceRepository $services,
        private DetectionRepository $detections,
        private StoragePidResolver $storagePidResolver,
        private WebhookRequestGuard $requestGuard,
        private WebhookPayloadValidator $validator,
        private BridgeRateLimiter $rateLimiter,
        private BridgeSecretProvider $secretProvider,
        private BridgeNonceService $nonceService,
        private ClassifierLookup $classifierLookup,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (!str_starts_with($path, self::API_PREFIX)) {
            return $handler->handle($request);
        }

        $method = $request->getMethod();
        if ($method === 'OPTIONS') {
            return $this->corsPreflight();
        }

        // CMS-bridge webhook receiver (REQ-9 / docs/cms-bridge-webhook.md).
        if ($path === self::WEBHOOK_PATH && $method === 'POST') {
            return $this->withCors($this->webhook($request));
        }

        // Service-DB protocol routes (v1).
        if (str_starts_with($path, self::V1_PREFIX)) {
            $sub = substr($path, strlen(self::V1_PREFIX));
            return match (true) {
                $sub === '/health' && $method === 'GET'   => $this->withCors($this->health()),
                $sub === '/services' && $method === 'GET' => $this->withCors($this->listServices($request)),
                $sub === '/lookup' && $method === 'POST'  => $this->withCors($this->lookup($request)),
                default => $this->withCors($this->jsonError(404, 'No route for ' . $method . ' ' . $path)),
            };
        }

        return $this->withCors($this->jsonError(404, 'No route for ' . $method . ' ' . $path));
    }

    /**
     * Defense-in-depth for the webhook endpoint:
     *
     *   1. Sec-Fetch-Site + Origin guards    (Phase 1; cheap, no DB hit)
     *   2. Per-IP rate limit                  (Phase 1; one cache read)
     *   3. Strict payload validation          (Phase 1; length cap, type/enum/epoch)
     *   4. HMAC nonce verification            (Phase 2; source-bound, 1h TTL)
     *   5. Repository ingest
     *
     * Nonce step is enforced when `bridgeSecret` is configured. When
     * the secret is missing the receiver refuses outright (503) so we
     * never silently fall back to the looser Phase-1-only mode. See
     * memory `webhook_browser_secret_constraint` for why the nonce is
     * a "raise the bar" defense, not real authentication.
     *
     * Why rate limit (step 2) runs BEFORE nonce verification (step 4):
     * `BridgeRateLimiter::check()` increments the per-IP counter on
     * every allowed request, regardless of whether the request later
     * fails nonce verification. Putting the rate limit early ensures
     * an attacker probing nonces (sending many requests with garbage
     * nonces to enumerate timing signatures) burns one rate-limit
     * count per probe and gets capped at the configured per-IP rate
     * (default 500/hour). If nonce verification ran first, invalid
     * nonces would short-circuit and never touch the rate limiter,
     * giving an attacker unlimited probes. The nonce HMAC compare
     * itself is constant-time (`hash_equals()` in
     * `BridgeNonceService::verify()`), so timing within an individual
     * probe doesn't leak useful state — the rate limit caps the
     * probe COUNT, which is the practical defense.
     */
    private function webhook(ServerRequestInterface $request): ResponseInterface
    {
        $guardError = $this->requestGuard->check($request);
        if ($guardError !== null) {
            return $this->jsonError(403, $guardError);
        }

        $rate = $this->rateLimiter->check($request);
        if (!$rate['allowed']) {
            return $this->jsonError(429, 'Rate limit exceeded')
                ->withHeader('Retry-After', (string) $rate['retryAfter']);
        }

        $result = $this->validator->validate((string) $request->getBody());
        if (!$result->valid) {
            return $this->jsonError($result->status, $result->message);
        }

        $nonceError = $this->verifyBridgeNonce($request, $result->payload);
        if ($nonceError !== null) {
            return $nonceError;
        }

        $this->detections->ingest($result->payload, $this->storagePidResolver->resolveForRequest($request));
        return new JsonResponse(['ok' => true]);
    }

    /**
     * @param array<string, mixed> $payload validated payload (has `source`)
     */
    private function verifyBridgeNonce(ServerRequestInterface $request, array $payload): ?ResponseInterface
    {
        if (!$this->secretProvider->isConfigured()) {
            return $this->jsonError(503, 'Bridge secret not configured');
        }
        $authHeader = $request->getHeader('Authorization');
        $nonce = $authHeader === [] ? '' : (string) $authHeader[0];
        if (str_starts_with($nonce, 'Bearer ')) {
            $nonce = substr($nonce, 7);
        }
        if ($nonce === '') {
            return $this->jsonError(401, 'Missing bridge nonce');
        }
        $verification = $this->nonceService->verify($nonce, (string) $payload['source']);
        return match ($verification->status) {
            BridgeNonceVerification::OK => null,
            BridgeNonceVerification::EXPIRED => $this->jsonError(401, 'Expired bridge nonce'),
            BridgeNonceVerification::SOURCE_MISMATCH => $this->jsonError(401, 'Nonce / source mismatch'),
            default => $this->jsonError(401, 'Invalid bridge nonce'),
        };
    }

    private function health(): ResponseInterface
    {
        return new JsonResponse([
            'ok' => true,
            'schemaVersion' => self::SCHEMA_VERSION,
            'count' => $this->services->count(),
        ]);
    }

    private function listServices(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $limit = $this->clamp((int) ($params['limit'] ?? self::DEFAULT_LIMIT), 1, self::MAX_LIMIT);
        $offset = max(0, (int) ($params['offset'] ?? 0));
        $page = $this->services->paginate($offset, $limit);

        return new JsonResponse([
            'items' => $page['items'],
            'total' => $page['total'],
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    private function lookup(ServerRequestInterface $request): ResponseInterface
    {
        $body = (string) $request->getBody();
        try {
            $decoded = $body === '' ? [] : json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $this->jsonError(400, 'Invalid JSON body: ' . $e->getMessage());
        }

        $items = is_array($decoded) && isset($decoded['items']) && is_array($decoded['items'])
            ? $decoded['items']
            : [];
        if ($items === []) {
            return new JsonResponse(['items' => []]);
        }

        $results = [];
        foreach ($items as $query) {
            if (!is_array($query)) {
                $results[] = ['query' => new \stdClass(), 'matches' => []];
                continue;
            }
            $cookie = isset($query['cookie']) && is_string($query['cookie']) ? $query['cookie'] : null;
            $origin = isset($query['origin']) && is_string($query['origin']) ? $query['origin'] : null;

            $matches = $this->classifierLookup->lookup($cookie, $origin);
            $cleanQuery = [];
            if ($cookie !== null) {
                $cleanQuery['cookie'] = $cookie;
            }
            if ($origin !== null) {
                $cleanQuery['origin'] = $origin;
            }
            $results[] = [
                'query' => $cleanQuery,
                'matches' => $matches,
            ];
        }

        return new JsonResponse(['items' => $results]);
    }

    private function jsonError(int $status, string $message): ResponseInterface
    {
        $response = new JsonResponse(['error' => $message], $status);
        return $response;
    }

    private function corsPreflight(): ResponseInterface
    {
        $response = new Response('php://temp', 204);
        return $this->withCors($response);
    }

    private function withCors(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
