<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use WapplerSystems\SimpleCmpTypo3\Domain\Repository\DetectionRepository;
use WapplerSystems\SimpleCmpTypo3\Domain\Repository\ServiceRepository;

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

    private function webhook(ServerRequestInterface $request): ResponseInterface
    {
        $body = (string) $request->getBody();
        try {
            $payload = $body === '' ? [] : json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $this->jsonError(400, 'Invalid JSON body: ' . $e->getMessage());
        }
        if (!is_array($payload)) {
            return $this->jsonError(400, 'Payload must be a JSON object');
        }
        if (($payload['schemaVersion'] ?? null) !== 1) {
            return $this->jsonError(
                400,
                'Unsupported schemaVersion (received: ' . var_export($payload['schemaVersion'] ?? null, true) . ')',
            );
        }

        $this->detections->ingest($payload);
        return new JsonResponse(['ok' => true]);
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

            $matches = $this->services->lookup($cookie, $origin);
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
