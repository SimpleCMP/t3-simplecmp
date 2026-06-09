<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SimpleCMP\T3SimpleCmp\Domain\Repository\DetectionRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\Middleware\ServiceDbApi;
use SimpleCMP\T3SimpleCmp\Service\BridgeNonceService;
use SimpleCMP\T3SimpleCmp\Service\BridgeNonceVerification;
use SimpleCMP\T3SimpleCmp\Service\BridgeRateLimiter;
use SimpleCMP\T3SimpleCmp\Service\BridgeSecretProvider;
use SimpleCMP\T3SimpleCmp\Service\ClassifierLookup;
use SimpleCMP\T3SimpleCmp\Service\StoragePidResolver;
use SimpleCMP\T3SimpleCmp\Service\WebhookPayloadValidator;
use SimpleCMP\T3SimpleCmp\Service\WebhookRequestGuard;
use SimpleCMP\T3SimpleCmp\Service\WebhookValidationResult;

/**
 * Locks the routing + orchestration behaviour of the SimpleCMP API
 * middleware. The inner services have their own dedicated unit tests
 * (`Tests/Unit/Service/*`); this file asserts what the middleware
 * does with their results — order of guards, status codes, which
 * methods it calls and which it bypasses.
 */
final class ServiceDbApiTest extends TestCase
{
    private ServiceRepository&MockObject $services;
    private DetectionRepository&MockObject $detections;
    private StoragePidResolver&MockObject $storagePidResolver;
    private WebhookRequestGuard&MockObject $requestGuard;
    private WebhookPayloadValidator&MockObject $validator;
    private BridgeRateLimiter&MockObject $rateLimiter;
    private BridgeSecretProvider&MockObject $secretProvider;
    private BridgeNonceService&MockObject $nonceService;
    private ClassifierLookup&MockObject $classifierLookup;
    private \TYPO3\CMS\Core\Site\SiteFinder&MockObject $siteFinder;
    private RequestHandlerInterface&MockObject $handler;

    protected function setUp(): void
    {
        $this->services = $this->createMock(ServiceRepository::class);
        $this->detections = $this->createMock(DetectionRepository::class);
        $this->storagePidResolver = $this->createMock(StoragePidResolver::class);
        $this->requestGuard = $this->createMock(WebhookRequestGuard::class);
        $this->validator = $this->createMock(WebhookPayloadValidator::class);
        $this->rateLimiter = $this->createMock(BridgeRateLimiter::class);
        $this->secretProvider = $this->createMock(BridgeSecretProvider::class);
        $this->nonceService = $this->createMock(BridgeNonceService::class);
        $this->classifierLookup = $this->createMock(ClassifierLookup::class);
        $this->siteFinder = $this->createMock(\TYPO3\CMS\Core\Site\SiteFinder::class);
        $this->siteFinder->method('getAllSites')->willReturn([]);
        $this->handler = $this->createMock(RequestHandlerInterface::class);

        // Defaults: everything passes — individual tests override what they need.
        $this->requestGuard->method('check')->willReturn(null);
        $this->rateLimiter->method('check')->willReturn([
            'allowed' => true, 'limit' => 500, 'count' => 1, 'retryAfter' => 0,
        ]);
        $this->rateLimiter->method('checkLookup')->willReturn([
            'allowed' => true, 'limit' => 5000, 'count' => 1, 'retryAfter' => 0,
        ]);
        $this->secretProvider->method('isConfigured')->willReturn(true);
        $this->nonceService->method('verify')->willReturn(BridgeNonceVerification::ok());
        $this->validator->method('validate')->willReturn(
            WebhookValidationResult::success($this->validPayload()),
        );
        $this->storagePidResolver->method('resolveForRequest')->willReturn(0);
    }

    #[Test]
    public function nonApiPathFallsThroughToNextHandler(): void
    {
        $fallthrough = $this->createMock(ResponseInterface::class);
        $this->handler->expects(self::once())->method('handle')->willReturn($fallthrough);
        $response = $this->middleware()->process($this->request('GET', '/some/other/path'), $this->handler);
        self::assertSame($fallthrough, $response);
    }

    #[Test]
    public function optionsRequestReturnsCorsPreflight(): void
    {
        $this->handler->expects(self::never())->method('handle');
        $response = $this->middleware()->process(
            $this->request('OPTIONS', '/api/simplecmp/v1/lookup'),
            $this->handler,
        );
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function healthEndpointReturnsSchemaAndCount(): void
    {
        $this->services->method('count')->willReturn(42);
        $response = $this->middleware()->process(
            $this->request('GET', '/api/simplecmp/v1/health'),
            $this->handler,
        );
        self::assertSame(200, $response->getStatusCode());
        // Liveness probe must not be cached.
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(true, $body['ok']);
        self::assertSame(1, $body['schemaVersion']);
        self::assertSame(42, $body['count']);
    }

    #[Test]
    public function unknownV1RouteReturns404(): void
    {
        $response = $this->middleware()->process(
            $this->request('GET', '/api/simplecmp/v1/nope'),
            $this->handler,
        );
        self::assertSame(404, $response->getStatusCode());
        // Errors must not be cached (a transient 404 would otherwise replay).
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function unknownApiRootReturns404(): void
    {
        $response = $this->middleware()->process(
            $this->request('GET', '/api/simplecmp/anything'),
            $this->handler,
        );
        self::assertSame(404, $response->getStatusCode());
    }

    // --- /v1/services -------------------------------------------------------

    #[Test]
    public function servicesEndpointReturnsPaginatedRegistry(): void
    {
        $this->services->expects(self::once())
            ->method('paginate')
            ->with(0, 100)
            ->willReturn(['items' => [['id' => 'svc-a']], 'total' => 1]);
        $response = $this->middleware()->process(
            $this->request('GET', '/api/simplecmp/v1/services'),
            $this->handler,
        );
        self::assertSame(200, $response->getStatusCode());
        // The registry listing is the only publicly cacheable response.
        self::assertSame('public, max-age=3600', $response->getHeaderLine('Cache-Control'));
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(1, $body['total']);
        self::assertSame(100, $body['limit']);
        self::assertSame(0, $body['offset']);
        self::assertSame([['id' => 'svc-a']], $body['items']);
    }

    #[Test]
    public function servicesEndpointClampsLimitAndOffset(): void
    {
        $this->services->expects(self::once())
            ->method('paginate')
            ->with(0, 500)
            ->willReturn(['items' => [], 'total' => 0]);
        $this->middleware()->process(
            $this->request('GET', '/api/simplecmp/v1/services', ['limit' => '9999', 'offset' => '-5']),
            $this->handler,
        );
    }

    // --- /v1/lookup ---------------------------------------------------------

    #[Test]
    public function lookupReturnsEmptyForMissingItems(): void
    {
        $response = $this->middleware()->process(
            $this->request('POST', '/api/simplecmp/v1/lookup', body: '{}'),
            $this->handler,
        );
        // POST results must never be cached/replayed by a shared cache.
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame([], $body['items']);
    }

    #[Test]
    public function lookupReturns429WhenRateLimited(): void
    {
        $limiter = $this->createMock(BridgeRateLimiter::class);
        $limiter->method('checkLookup')->willReturn([
            'allowed' => false, 'limit' => 5000, 'count' => 5000, 'retryAfter' => 137,
        ]);
        // A rate-limited request must not reach the classifier at all.
        $this->classifierLookup->expects(self::never())->method('lookup');

        $response = $this->middleware(['rateLimiter' => $limiter])->process(
            $this->request('POST', '/api/simplecmp/v1/lookup', body: json_encode([
                'items' => [['cookie' => '_ga']],
            ])),
            $this->handler,
        );

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('137', $response->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function lookupRejectsTooManyItems(): void
    {
        $items = array_fill(0, 101, ['cookie' => '_ga']);
        $this->classifierLookup->expects(self::never())->method('lookup');

        $response = $this->middleware()->process(
            $this->request('POST', '/api/simplecmp/v1/lookup', body: json_encode(['items' => $items])),
            $this->handler,
        );

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function lookupDropsOverlongQueryStringsBeforeMatching(): void
    {
        // An over-long, attacker-controlled cookie name must not reach the
        // regex matcher (ReDoS guard) — the field is dropped to null.
        $this->classifierLookup->expects(self::once())
            ->method('lookup')
            ->with(null, 'ok.example.com', self::anything(), self::anything())
            ->willReturn([]);

        $this->middleware()->process(
            $this->request('POST', '/api/simplecmp/v1/lookup', body: json_encode([
                'items' => [['cookie' => str_repeat('a', 600), 'origin' => 'ok.example.com']],
            ])),
            $this->handler,
        );
    }

    #[Test]
    public function lookupCallsRepositoryForEachQueryItem(): void
    {
        $this->classifierLookup->expects(self::exactly(2))
            ->method('lookup')
            ->willReturnOnConsecutiveCalls([['id' => 'm1']], []);
        $request = $this->request('POST', '/api/simplecmp/v1/lookup', body: json_encode([
            'items' => [
                ['cookie' => '_ga'],
                ['origin' => 'example.com'],
            ],
        ]));
        $response = $this->middleware()->process($request, $this->handler);
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(2, $body['items']);
        self::assertSame([['id' => 'm1']], $body['items'][0]['matches']);
        self::assertSame([], $body['items'][1]['matches']);
    }

    #[Test]
    public function lookupRejectsInvalidJson(): void
    {
        $response = $this->middleware()->process(
            $this->request('POST', '/api/simplecmp/v1/lookup', body: 'not-json'),
            $this->handler,
        );
        self::assertSame(400, $response->getStatusCode());
    }

    // --- /webhook end-to-end -----------------------------------------------

    #[Test]
    public function webhookHappyPathIngestsAndReturns200(): void
    {
        $this->detections->expects(self::once())->method('ingest');
        $response = $this->middleware()->process(
            $this->webhookRequest(),
            $this->handler,
        );
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function webhookGuardErrorReturns403(): void
    {
        $guard = $this->createMock(WebhookRequestGuard::class);
        $guard->method('check')->willReturn('Unknown origin');
        $this->detections->expects(self::never())->method('ingest');
        $response = $this->middleware(['requestGuard' => $guard])->process(
            $this->webhookRequest(),
            $this->handler,
        );
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function webhookRateLimitExceededReturns429WithRetryAfter(): void
    {
        $limiter = $this->createMock(BridgeRateLimiter::class);
        $limiter->method('check')->willReturn([
            'allowed' => false, 'limit' => 500, 'count' => 500, 'retryAfter' => 1800,
        ]);
        $this->detections->expects(self::never())->method('ingest');
        $response = $this->middleware(['rateLimiter' => $limiter])->process(
            $this->webhookRequest(),
            $this->handler,
        );
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('1800', $response->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function webhookValidationFailureBypassesIngest(): void
    {
        $validator = $this->createMock(WebhookPayloadValidator::class);
        $validator->method('validate')->willReturn(
            WebhookValidationResult::failure(413, 'Payload too large'),
        );
        $this->detections->expects(self::never())->method('ingest');
        $response = $this->middleware(['validator' => $validator])->process(
            $this->webhookRequest(),
            $this->handler,
        );
        self::assertSame(413, $response->getStatusCode());
    }

    #[Test]
    public function webhookMissingSecretReturns503(): void
    {
        $secretProvider = $this->createMock(BridgeSecretProvider::class);
        $secretProvider->method('isConfigured')->willReturn(false);
        $this->detections->expects(self::never())->method('ingest');
        $response = $this->middleware(['secretProvider' => $secretProvider])->process(
            $this->webhookRequest(),
            $this->handler,
        );
        self::assertSame(503, $response->getStatusCode());
    }

    #[Test]
    public function webhookMissingAuthHeaderReturns401(): void
    {
        $this->detections->expects(self::never())->method('ingest');
        $response = $this->middleware()->process(
            $this->webhookRequest(authHeader: null),
            $this->handler,
        );
        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function webhookInvalidNonceReturns401(): void
    {
        $nonce = $this->createMock(BridgeNonceService::class);
        $nonce->method('verify')->willReturn(BridgeNonceVerification::invalid());
        $this->detections->expects(self::never())->method('ingest');
        $response = $this->middleware(['nonceService' => $nonce])->process(
            $this->webhookRequest(),
            $this->handler,
        );
        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function webhookExpiredNonceReturns401WithExpiredMessage(): void
    {
        $nonce = $this->createMock(BridgeNonceService::class);
        $nonce->method('verify')->willReturn(BridgeNonceVerification::expired());
        $response = $this->middleware(['nonceService' => $nonce])->process(
            $this->webhookRequest(),
            $this->handler,
        );
        self::assertSame(401, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('Expired', $body['error']);
    }

    #[Test]
    public function webhookSourceMismatchNonceReturns401WithMismatchMessage(): void
    {
        $nonce = $this->createMock(BridgeNonceService::class);
        $nonce->method('verify')->willReturn(BridgeNonceVerification::sourceMismatch());
        $response = $this->middleware(['nonceService' => $nonce])->process(
            $this->webhookRequest(),
            $this->handler,
        );
        self::assertSame(401, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('source', strtolower($body['error']));
    }

    #[Test]
    public function webhookPassesValidatedSourceToNonceVerify(): void
    {
        $nonce = $this->createMock(BridgeNonceService::class);
        $nonce->expects(self::once())
            ->method('verify')
            ->with(self::anything(), 'simplecmp-acme')
            ->willReturn(BridgeNonceVerification::ok());

        $validator = $this->createMock(WebhookPayloadValidator::class);
        $validator->method('validate')->willReturn(
            WebhookValidationResult::success($this->validPayload(['source' => 'simplecmp-acme'])),
        );

        $this->middleware(['nonceService' => $nonce, 'validator' => $validator])->process(
            $this->webhookRequest(),
            $this->handler,
        );
    }

    // --- helpers ------------------------------------------------------------

    /** @param array<string, object> $overrides */
    private function middleware(array $overrides = []): ServiceDbApi
    {
        return new ServiceDbApi(
            $overrides['services'] ?? $this->services,
            $overrides['detections'] ?? $this->detections,
            $overrides['storagePidResolver'] ?? $this->storagePidResolver,
            $overrides['requestGuard'] ?? $this->requestGuard,
            $overrides['validator'] ?? $this->validator,
            $overrides['rateLimiter'] ?? $this->rateLimiter,
            $overrides['secretProvider'] ?? $this->secretProvider,
            $overrides['nonceService'] ?? $this->nonceService,
            $overrides['classifierLookup'] ?? $this->classifierLookup,
            $overrides['siteFinder'] ?? $this->siteFinder,
        );
    }

    /**
     * @param array<string, string> $queryParams
     * @param array<string, string> $headers
     */
    private function request(
        string $method,
        string $path,
        array $queryParams = [],
        string $body = '',
        array $headers = [],
    ): ServerRequestInterface {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn($path);
        $uri->method('getHost')->willReturn('dev14.ddev.site');

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $req = $this->createMock(ServerRequestInterface::class);
        $req->method('getUri')->willReturn($uri);
        $req->method('getMethod')->willReturn($method);
        $req->method('getQueryParams')->willReturn($queryParams);
        $req->method('getBody')->willReturn($stream);
        $req->method('getHeader')->willReturnCallback(
            static fn (string $name) => isset($headers[$name]) ? [$headers[$name]] : []
        );
        return $req;
    }

    private function webhookRequest(?string $authHeader = 'Bearer some-nonce'): ServerRequestInterface
    {
        $headers = [];
        if ($authHeader !== null) {
            $headers['Authorization'] = $authHeader;
        }
        return $this->request(
            'POST',
            '/api/simplecmp/webhook',
            body: '{"…":"validated by mock"}',
            headers: $headers,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'schemaVersion' => 1,
            'source' => 'simplecmp-default',
            'sentAt' => '2026-05-15T00:00:00.000Z',
            'page' => ['url' => 'https://dev14.ddev.site/'],
            'library' => ['name' => 'simplecmp', 'version' => '0.0.1'],
            'detection' => [
                'kind' => 'cookie',
                'identifier' => '_ga',
                'firstSeen' => 1715591051000,
                'lastSeen' => 1715591051000,
                'count' => 1,
                'status' => 'unknown',
            ],
        ], $overrides);
    }
}
