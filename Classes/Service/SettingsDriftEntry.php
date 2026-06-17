<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

/**
 * Phase-5 DTO: one row in the BE Settings tab's drift list.
 *
 * `state` distinguishes:
 *   - `bootstrap-pending` — no row in active_settings yet for this
 *     site. YAML is effectively live.
 *   - `drift-yaml-newer` — active row exists, key adopted previously,
 *     YAML has since been changed to a different value.
 *   - `drift-custom` — editor has set a custom override that differs
 *     from the YAML default.
 *   - `in-sync` — active matches YAML; no action needed.
 */
final readonly class SettingsDriftEntry
{
    public const string STATE_BOOTSTRAP_PENDING = 'bootstrap-pending';
    public const string STATE_DRIFT_YAML_NEWER = 'drift-yaml-newer';
    public const string STATE_DRIFT_CUSTOM = 'drift-custom';
    public const string STATE_IN_SYNC = 'in-sync';

    public function __construct(
        public string $key,
        public mixed $activeValue,    // null if no active opinion yet
        public mixed $yamlValue,
        public string $state,
    ) {
    }

    public function needsAction(): bool
    {
        return $this->state === self::STATE_DRIFT_YAML_NEWER
            || $this->state === self::STATE_BOOTSTRAP_PENDING;
    }
}
