<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

/**
 * Phase-5 DTO: one YAML-defined tracker waiting for editor adoption.
 *
 * The BE Settings tab uses these to render the "create as
 * managed_tracker draft" buttons. A proposal is "already adopted"
 * if a managed_tracker row with the same `tracker_type` + `service_id`
 * already exists for the site (live OR draft).
 */
final readonly class TrackerProposal
{
    /**
     * @param array<string, mixed> $config  remaining YAML-tracker fields
     *                                       (url / siteId / measurementId / …)
     */
    public function __construct(
        public string $type,
        public string $serviceId,
        public array $config,
        public bool $alreadyAdopted,
        public ?int $existingLiveUid,
        public ?int $existingDraftUid,
    ) {
    }
}
