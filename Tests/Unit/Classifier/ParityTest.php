<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Classifier;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\Service\DetectionListPresenter;

/**
 * Cross-classifier parity — PHP half. Companion to the JS test at
 * `simplecmp/tests/classifier-parity.test.ts`. Both load the same
 * fixture JSON and verify that the matching logic produces the same
 * result for each `(cookieName, matcher, observedOrigins)` triple.
 *
 * The PHP middleware doesn't track observed origins (it runs
 * server-side, with no per-session state); for host-qualified
 * matchers, PHP returns `true` whenever the *name* part matches —
 * the FE recorder enforces the `requireOrigin` filter. The fixture's
 * `phpAlwaysTrue` field marks the cases that diverge intentionally.
 *
 * Two PHP entry points share the matching logic: ServiceRepository
 * (Service-DB lookup) and DetectionListPresenter (BE state
 * derivation). Both must agree with each other AND with the fixture's
 * `phpAlwaysTrue ?? expected` value. The reflection trick lets us
 * call the private methods without re-implementing them.
 *
 * If a case fails here but passes in JS (or vice versa), one
 * implementation has drifted from the other. Fix the divergence
 * before the next ADR ships.
 */
final class ParityTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: string, 2: mixed, 3: bool}>
     */
    public static function fixtureProvider(): array
    {
        $raw = (string) file_get_contents(__DIR__ . '/classifier-parity-fixture.json');
        $cases = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $out = [];
        foreach ($cases as $c) {
            // PHP expects the name part to match; the requireOrigin
            // filter is FE-side. Use `phpAlwaysTrue` when defined,
            // otherwise the JS `expected`.
            $phpExpected = $c['phpAlwaysTrue'] ?? $c['expected'];
            $out[$c['id']] = [
                $c['id'],
                $c['cookie'],
                $c['matcher'],
                (bool) $phpExpected,
            ];
        }
        return $out;
    }

    #[Test]
    #[DataProvider('fixtureProvider')]
    public function serviceRepositoryAgreesWithFixture(
        string $id,
        string $cookie,
        mixed $matcher,
        bool $expected,
    ): void {
        $repo = new \ReflectionClass(ServiceRepository::class);
        $method = $repo->getMethod('cookieMatches');
        $method->setAccessible(true);
        // ServiceRepository is final readonly; instantiate via the
        // reflection API to bypass the constructor (we only need
        // the matching logic, not the database fields).
        $instance = $repo->newInstanceWithoutConstructor();
        $actual = $method->invoke($instance, $cookie, [$matcher]);
        self::assertSame($expected, $actual, "ServiceRepository {$id}");
    }

    #[Test]
    #[DataProvider('fixtureProvider')]
    public function detectionListPresenterAgreesWithFixture(
        string $id,
        string $cookie,
        mixed $matcher,
        bool $expected,
    ): void {
        $cls = new \ReflectionClass(DetectionListPresenter::class);
        $method = $cls->getMethod('cookieMatches');
        $method->setAccessible(true);
        // The method is private and static — invokeArgs with null instance.
        $actual = $method->invokeArgs(null, [$cookie, [$matcher]]);
        self::assertSame($expected, $actual, "DetectionListPresenter {$id}");
    }
}
