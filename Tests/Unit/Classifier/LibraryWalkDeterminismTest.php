<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Classifier;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\ServicesLibrary\ServicesLibrary;
use SimpleCMP\T3SimpleCmp\Service\DetectionListPresenter;

/**
 * Library-walk determinism — for cookies covered by multiple library
 * entries, the BE state derivation must pick the SAME service every
 * time. The walk order is the order of the `$library` array
 * (alphabetical by filename at the source — PHP's `glob()` default
 * + the library's `sort($files)`).
 *
 * Uses a synthetic library inline so the test isn't coupled to a
 * specific services-library version. Overlaps in the real library
 * come from the OCD import (e.g. `token` belongs to both Adform and
 * Xandr in OCD's view of the world).
 *
 * If determinism breaks (a future refactor switches the iterator,
 * adds caching with non-stable order, or similar), admins would see
 * the matched service flip between page loads. This test catches
 * that.
 */
final class LibraryWalkDeterminismTest extends TestCase
{
    /**
     * Synthetic two-service library with intentional overlaps on
     * `shared-cookie` and `*.example.com`. Order is intentional:
     * `aaa-service` is first; under "first match wins" semantics it
     * should always be the chosen match.
     *
     * @return list<array<string, mixed>>
     */
    private static function syntheticLibrary(): array
    {
        return [
            [
                'id' => 'aaa-service',
                'name' => 'AAA Service',
                'matches' => [
                    'cookies' => ['shared-cookie', 'aaa-only'],
                    'origins' => ['*.example.com'],
                ],
            ],
            [
                'id' => 'zzz-service',
                'name' => 'ZZZ Service',
                'matches' => [
                    'cookies' => ['shared-cookie', 'zzz-only'],
                    'origins' => ['*.example.com', 'unique.zzz.test'],
                ],
            ],
        ];
    }

    #[Test]
    public function firstMatchInArrayOrderWinsOnCookieCollision(): void
    {
        // `shared-cookie` is in both services; aaa-service is first
        // in the array → aaa-service wins.
        $result = DetectionListPresenter::deriveState(
            ['kind' => 'cookie', 'identifier' => 'shared-cookie'],
            [],
            self::syntheticLibrary(),
        );
        self::assertSame('aaa-service', $result['match']['id'] ?? null);
    }

    #[Test]
    public function firstMatchInArrayOrderWinsOnOriginCollision(): void
    {
        // `*.example.com` is in both services; aaa-service is first.
        $result = DetectionListPresenter::deriveState(
            ['kind' => 'request', 'identifier' => 'https://foo.example.com/x', 'origin' => 'foo.example.com'],
            [],
            self::syntheticLibrary(),
        );
        self::assertSame('aaa-service', $result['match']['id'] ?? null);
    }

    #[Test]
    public function repeatedStateDerivationReturnsTheSameMatch(): void
    {
        $detection = ['kind' => 'cookie', 'identifier' => 'shared-cookie'];
        $library = self::syntheticLibrary();
        $first = DetectionListPresenter::deriveState($detection, [], $library);
        for ($i = 0; $i < 5; $i++) {
            $next = DetectionListPresenter::deriveState($detection, [], $library);
            self::assertSame(
                $first['match']['id'] ?? null,
                $next['match']['id'] ?? null,
                "Match flipped on iteration {$i} — library walk is not deterministic.",
            );
        }
    }

    #[Test]
    public function realLibraryWalkIsAlphabeticalBySourceFile(): void
    {
        // Sanity check the real library iterator's order — entries come
        // back in alphabetical-by-source-filename order (the library
        // uses glob() which returns sorted-by-filename). This differs
        // from alphabetical-by-id for prefix pairs like
        // `akamai`/`akamai-botmanager`: by filename `-` (0x2D) <
        // `.` (0x2E), so `akamai-botmanager.json` sorts before
        // `akamai.json` even though by id `akamai` sorts before
        // `akamai-botmanager`. deriveState picks the first matching
        // entry in iteration order; this test pins the order is
        // stable + matches glob's filename sort.
        $ids = array_column(iterator_to_array(ServicesLibrary::services(), false), 'id');
        self::assertNotEmpty($ids, 'Real library should be non-empty.');
        $filenames = array_map(static fn (string $id): string => $id . '.json', $ids);
        $sortedFilenames = $filenames;
        sort($sortedFilenames, SORT_STRING);
        self::assertSame(
            $sortedFilenames,
            $filenames,
            'Library iteration order must match glob() filename sort.',
        );
    }
}
