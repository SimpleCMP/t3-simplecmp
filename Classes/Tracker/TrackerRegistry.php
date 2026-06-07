<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tracker;

/**
 * Service-tag-discovered registry of {@see TrackerProviderInterface}.
 *
 * Implementations declare themselves via the `simplecmp.tracker_provider`
 * service tag in `Configuration/Services.yaml`. Bundled providers
 * (Matomo, GA4, GTM) ship pre-tagged via autoconfigure; third-party
 * extensions can add more by tagging their own providers the same way.
 *
 * Lookup is by `getType()`. Duplicate types — same `getType()` twice
 * across providers — are not detected; last writer wins (the order
 * comes from the autowire pass and is therefore implementation-
 * defined, so don't rely on a specific provider taking precedence).
 */
final readonly class TrackerRegistry
{
    /**
     * @var array<string, TrackerProviderInterface>
     */
    private array $byType;

    /**
     * @param iterable<TrackerProviderInterface> $providers
     */
    public function __construct(iterable $providers)
    {
        $byType = [];
        foreach ($providers as $provider) {
            $byType[$provider->getType()] = $provider;
        }
        $this->byType = $byType;
    }

    public function get(string $type): ?TrackerProviderInterface
    {
        return $this->byType[$type] ?? null;
    }

    /**
     * @return list<string>
     */
    public function getKnownTypes(): array
    {
        return array_keys($this->byType);
    }
}
