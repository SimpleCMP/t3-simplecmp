<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Domain\Repository;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Raw DBAL repository for SimpleCMP service registry entries.
 *
 * Matches the JSON shape from `docs/service-db-protocol.md` upstream:
 * id, name, vendor, vendorCountry, purposes[], privacyPolicyUrl,
 * description, retention, i18n, matches { cookies?, origins? }, extensions.
 *
 * Storage is denormalized JSON columns for the polymorphic bits
 * (`purposes`, `retention`, `i18n`, `cookies`, `origins`, `extensions`) —
 * the registry is read-heavy, schema-flat, and matches the protocol
 * one-to-one.
 */
final readonly class ServiceRepository
{
    private const string TABLE = 'tx_simplecmptypo3_service';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Look up services matching one or both criteria. A service matches if
     * any of its cookie matchers matches the queried cookie name, OR any
     * of its origin matchers matches the queried origin (host). Cookie
     * matchers are exact-string or `/regex/` (slashes optional); origin
     * matchers are exact-host or `*.suffix.example`.
     *
     * @return array<array<string, mixed>> raw rows decoded to the protocol shape
     */
    public function lookup(?string $cookie, ?string $origin): array
    {
        if ($cookie === null && $origin === null) {
            return [];
        }

        // Loading all rows and filtering in PHP is fine at the expected
        // scale (hundreds of services); SQL-side matching for regex
        // cookies and wildcard origins isn't worth the index-design pain.
        $all = $this->findAll();
        $matches = [];
        foreach ($all as $row) {
            if ($cookie !== null && $this->cookieMatches($cookie, $row['matches']['cookies'] ?? [])) {
                $matches[] = $row;
                continue;
            }
            if ($origin !== null && $this->originMatches($origin, $row['matches']['origins'] ?? [])) {
                $matches[] = $row;
            }
        }
        return $matches;
    }

    /**
     * Paginated list for `GET /services`.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function paginate(int $offset, int $limit): array
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);

        // DBAL's QueryBuilder quotes identifiers in select(), so `COUNT(*)`
        // would become `` `COUNT(*)` `` and fail. Use a literal SQL count.
        $total = (int) $conn->executeQuery('SELECT COUNT(*) FROM ' . self::TABLE)->fetchOne();

        $rows = $conn->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('service_id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return [
            'items' => array_map($this->rowToProtocol(...), $rows),
            'total' => $total,
        ];
    }

    public function findOne(string $serviceId): ?array
    {
        $row = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('service_id = :id')
            ->setParameter('id', $serviceId)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $this->rowToProtocol($row);
    }

    public function count(): int
    {
        return (int) $this->connectionPool->getConnectionForTable(self::TABLE)
            ->executeQuery('SELECT COUNT(*) FROM ' . self::TABLE)
            ->fetchOne();
    }

    /**
     * Insert-or-update a service.
     *
     * `$feVisibleOnInsert` controls the FE banner visibility of *newly
     * created* rows only:
     * - `true` for sources whose entries are by definition on every site
     *   (e.g. `simplecmp:seed`).
     * - `false` for classifier pre-fills like `simplecmp:import-known-trackers`
     *   — admin promotes them via the Übernehmen flow.
     *
     * On UPDATE we never overwrite `fe_visible` — the admin's prior choice
     * is the source of truth.
     */
    public function upsert(array $serviceData, int $pid = 0, bool $feVisibleOnInsert = false): void
    {
        $row = [
            'pid' => $pid,
            'tstamp' => time(),
            'service_id' => $serviceData['id'],
            'name' => $serviceData['name'],
            'vendor' => $serviceData['vendor'] ?? null,
            'vendor_country' => $serviceData['vendorCountry'] ?? null,
            'purposes' => json_encode($serviceData['purposes'] ?? [], JSON_THROW_ON_ERROR),
            'privacy_policy_url' => $serviceData['privacyPolicyUrl'] ?? null,
            'description' => $serviceData['description'] ?? null,
            'retention' => isset($serviceData['retention'])
                ? json_encode($serviceData['retention'], JSON_THROW_ON_ERROR)
                : null,
            'i18n' => isset($serviceData['i18n'])
                ? json_encode($serviceData['i18n'], JSON_THROW_ON_ERROR)
                : null,
            'cookies' => isset($serviceData['matches']['cookies'])
                ? json_encode($serviceData['matches']['cookies'], JSON_THROW_ON_ERROR)
                : null,
            'origins' => isset($serviceData['matches']['origins'])
                ? json_encode($serviceData['matches']['origins'], JSON_THROW_ON_ERROR)
                : null,
            'extensions' => isset($serviceData['extensions'])
                ? json_encode($serviceData['extensions'], JSON_THROW_ON_ERROR)
                : null,
        ];

        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $existing = $conn->createQueryBuilder()
            ->select('uid')
            ->from(self::TABLE)
            ->where('service_id = :id')
            ->setParameter('id', $serviceData['id'])
            ->executeQuery()
            ->fetchOne();

        if ($existing === false) {
            $row['crdate'] = time();
            $row['fe_visible'] = $feVisibleOnInsert ? 1 : 0;
            $conn->insert(self::TABLE, $row);
        } else {
            $conn->update(self::TABLE, $row, ['uid' => (int) $existing]);
        }
    }

    /**
     * Promote a service to the FE banner. Idempotent — repeated calls on
     * an already-visible service are no-ops. The Übernehmen flow on the
     * BE detection table is the typical caller.
     */
    public function markVisibleOnFe(string $serviceId): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $conn->update(
            self::TABLE,
            ['fe_visible' => 1, 'tstamp' => time()],
            ['service_id' => $serviceId],
        );
    }

    /**
     * Load every service in protocol shape. Used by callers that need
     * to match a batch of detections against the registry without
     * paying N+1 query cost per match.
     *
     * @return array<array<string, mixed>>
     */
    public function findAll(): array
    {
        $rows = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->rowToProtocol(...), $rows);
    }

    /**
     * Load services flagged for FE banner display (`fe_visible = 1`).
     *
     * Used by `RegisterAssets::buildRuntimeServices()`. Library imports
     * via `simplecmp:import-known-trackers` default to `fe_visible = 0`
     * so they seed the classifier without bloating the FE config; admin
     * promotes them via Übernehmen (the BE detection-approve flow) or
     * the TCA toggle on the service record.
     *
     * @return array<array<string, mixed>>
     */
    public function findAllVisibleOnFe(): array
    {
        $qb = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->createQueryBuilder();
        $rows = $qb
            ->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('fe_visible', $qb->createNamedParameter(1, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->rowToProtocol(...), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowToProtocol(array $row): array
    {
        $out = [
            'id' => (string) $row['service_id'],
            'name' => (string) $row['name'],
            'purposes' => $this->decodeJson($row['purposes']) ?? [],
        ];
        if ($row['vendor'] !== null && $row['vendor'] !== '') {
            $out['vendor'] = (string) $row['vendor'];
        }
        if ($row['vendor_country'] !== null && $row['vendor_country'] !== '') {
            $out['vendorCountry'] = (string) $row['vendor_country'];
        }
        if ($row['privacy_policy_url'] !== null && $row['privacy_policy_url'] !== '') {
            $out['privacyPolicyUrl'] = (string) $row['privacy_policy_url'];
        }
        if ($row['description'] !== null && $row['description'] !== '') {
            $out['description'] = (string) $row['description'];
        }
        $retention = $this->decodeJson($row['retention']);
        if ($retention !== null) {
            $out['retention'] = $retention;
        }
        $i18n = $this->decodeJson($row['i18n']);
        if ($i18n !== null) {
            $out['i18n'] = $i18n;
        }
        $cookies = $this->decodeJson($row['cookies']);
        $origins = $this->decodeJson($row['origins']);
        if ($cookies !== null || $origins !== null) {
            $matches = [];
            if ($cookies !== null) {
                $matches['cookies'] = $cookies;
            }
            if ($origins !== null) {
                $matches['origins'] = $origins;
            }
            $out['matches'] = $matches;
        }
        $extensions = $this->decodeJson($row['extensions']);
        if ($extensions !== null) {
            $out['extensions'] = $extensions;
        }
        return $out;
    }

    private function decodeJson(?string $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    private function cookieMatches(string $cookieName, array $matchers): bool
    {
        foreach ($matchers as $matcher) {
            if (is_string($matcher)) {
                if ($this->cookieNameMatches($cookieName, $matcher)) {
                    return true;
                }
                continue;
            }
            // ADR-0010 host-qualified form: array with `name` + `requireOrigin`.
            // The middleware can only check the *name* part — whether the
            // qualifying origin has been observed is a runtime decision made
            // by the FE recorder. We surface the service as a candidate; the
            // recorder applies the requireOrigin filter when classifying.
            if (
                is_array($matcher)
                && isset($matcher['name'], $matcher['requireOrigin'])
                && is_string($matcher['name'])
                && $this->cookieNameMatches($cookieName, $matcher['name'])
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Slash-bounded → regex (matches the JS LocalClassifier shape).
     * Otherwise → exact name match.
     */
    private function cookieNameMatches(string $cookieName, string $matcher): bool
    {
        if (strlen($matcher) >= 2 && $matcher[0] === '/' && $matcher[-1] === '/') {
            $pattern = '/' . substr($matcher, 1, -1) . '/';
            return @preg_match($pattern, $cookieName) === 1;
        }
        return $matcher === $cookieName;
    }

    private function originMatches(string $origin, array $matchers): bool
    {
        foreach ($matchers as $matcher) {
            if (!is_string($matcher)) {
                continue;
            }
            if (str_starts_with($matcher, '*.')) {
                $suffix = substr($matcher, 1); // ".example.com"
                if (str_ends_with($origin, $suffix) || $origin === substr($suffix, 1)) {
                    return true;
                }
                continue;
            }
            if ($matcher === $origin) {
                return true;
            }
        }
        return false;
    }
}
