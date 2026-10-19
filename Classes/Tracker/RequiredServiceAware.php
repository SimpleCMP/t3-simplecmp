<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tracker;

/**
 * Opt-in companion to {@see TrackerProviderInterface} — providers whose
 * banner service is technically necessary in a given posture.
 *
 * A required service is always consented: the engine ignores the
 * visitor's toggle and the banner shows it locked. Use it only for a
 * loader that runs regardless of consent anyway, e.g. Google Tag
 * Manager in `signal-gate` posture, where the container must load (and
 * load its sub-scripts from the same host, which the runtime patches
 * would otherwise block) so that Consent Mode can gate the tags inside.
 *
 * The service must not carry a purpose that is mapped to a Consent Mode
 * signal — a required service with `marketing` would grant `ad_storage`
 * to every visitor.
 */
interface RequiredServiceAware
{
    /**
     * @param array<string, mixed> $config
     */
    public function isRequiredService(array $config): bool;
}
