<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Tracker;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Tracker\TrackerFieldSpec;

final class TrackerFieldSpecTest extends TestCase
{
    #[Test]
    public function acceptsAMinimalValidConfig(): void
    {
        self::assertSame([], (new TrackerFieldSpec())->validate('gtm', ['containerId' => 'GTM-XXXXXXX']));
    }

    #[Test]
    public function reportsAMissingRequiredField(): void
    {
        $problems = (new TrackerFieldSpec())->validate('gtm', ['consentPosture' => 'block']);
        self::assertCount(1, $problems);
        self::assertStringContainsString('containerId', $problems[0]);
    }

    #[Test]
    public function reportsAnUnknownFieldAndNamesTheKnownOnes(): void
    {
        $problems = (new TrackerFieldSpec())->validate('gtm', [
            'containerId' => 'GTM-XXXXXXX',
            'measurementId' => 'G-123',   // a GA4 field, not a GTM one
        ]);
        self::assertCount(1, $problems);
        self::assertStringContainsString('measurementId', $problems[0]);
        self::assertStringContainsString('containerId', $problems[0]);
    }

    #[Test]
    public function reportsAValueOutsideAnEnum(): void
    {
        $problems = (new TrackerFieldSpec())->validate('gtm', [
            'containerId' => 'GTM-XXXXXXX',
            'consentPosture' => 'whenever',
        ]);
        self::assertCount(1, $problems);
        self::assertStringContainsString('block, signal-gate', $problems[0]);
    }

    #[Test]
    public function reportsAMalformedUrl(): void
    {
        $problems = (new TrackerFieldSpec())->validate('matomo', [
            'url' => 'not a url',
            'siteId' => '1',
        ]);
        self::assertCount(1, $problems);
        self::assertStringContainsString('must be a URL', $problems[0]);
    }

    /**
     * A provider shipped by a third party has no shape here, and
     * refusing to configure it would be worse than accepting its keys
     * unchecked — see the class docblock.
     */
    #[Test]
    public function acceptsAnythingForAProviderItDoesNotKnow(): void
    {
        self::assertSame(
            [],
            (new TrackerFieldSpec())->validate('someVendorTracker', ['whatever' => 'value']),
        );
    }

    #[Test]
    public function treatsAnEmptyOptionalFieldAsAbsent(): void
    {
        // `--set consentPosture=` should not trip the enum check.
        self::assertSame(
            [],
            (new TrackerFieldSpec())->validate('gtm', ['containerId' => 'GTM-X', 'consentPosture' => '']),
        );
    }

    #[Test]
    public function everyKnownProviderOffersAnOptionalServiceId(): void
    {
        $spec = new TrackerFieldSpec();
        foreach (['matomo', 'ga4', 'gtm', 'meta', 'microsoftUet'] as $type) {
            $names = array_column($spec->shapeFor($type), 'name');
            self::assertContains('serviceId', $names, $type . ' must expose serviceId');
        }
    }
}
