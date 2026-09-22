<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tracker;

/**
 * The config keys each managed-tracker provider accepts, without any
 * presentation concern.
 *
 * Two consumers need the same answer and must not drift apart: the BE
 * `TrackerSetupController`, which decorates every field with labels and
 * help texts from `locallang_mod.xlf` before rendering the edit form,
 * and the CLI (`simplecmp:setup-tracker`), which validates `--set
 * key=value` pairs against it. Keeping the shape here means a new
 * provider field reaches the form and the command in one edit.
 *
 * Deliberately NOT on `TrackerProviderInterface`: that interface is
 * implemented by third-party providers, and adding a method to it would
 * be a breaking change for every one of them. A provider the spec does
 * not know simply returns an empty shape — the CLI then accepts its
 * keys unvalidated rather than refusing to configure it at all.
 */
final class TrackerFieldSpec
{
    /**
     * Config field shape for a provider type.
     *
     * `serviceId` appears for every provider: it is the consent key the
     * row is stored under and defaults to the provider's own type, so
     * it is always optional.
     *
     * @return list<array{name: string, kind: string, required: bool, options?: list<string>}>
     */
    public function shapeFor(string $type): array
    {
        return match ($type) {
            'matomo' => [
                ['name' => 'url', 'kind' => 'url', 'required' => true],
                ['name' => 'siteId', 'kind' => 'text', 'required' => true],
                ['name' => 'disableCookies', 'kind' => 'bool', 'required' => false],
                ['name' => 'serviceId', 'kind' => 'text', 'required' => false],
            ],
            'ga4' => [
                ['name' => 'measurementId', 'kind' => 'text', 'required' => true],
                ['name' => 'anonymizeIp', 'kind' => 'bool', 'required' => false],
                [
                    'name' => 'consentPosture',
                    'kind' => 'enum',
                    'required' => false,
                    'options' => ['block', 'signal-gate'],
                ],
                ['name' => 'serviceId', 'kind' => 'text', 'required' => false],
            ],
            'gtm' => [
                ['name' => 'containerId', 'kind' => 'text', 'required' => true],
                [
                    'name' => 'consentPosture',
                    'kind' => 'enum',
                    'required' => false,
                    'options' => ['block', 'signal-gate'],
                ],
                ['name' => 'serviceId', 'kind' => 'text', 'required' => false],
            ],
            'meta' => [
                // Meta Pixel is signal-only — no loader URL, no
                // bootstrap snippet. The customer's own pixel template
                // continues to load fbevents.js; this row registers the
                // Service-DB metadata (banner listing, CSP origins,
                // _fbp/_fbc cookie classification) and tells the engine
                // to dispatch `fbq('consent', 'grant'|'revoke')` via the
                // ADR-0017 vendor adapter.
                ['name' => 'pixelId', 'kind' => 'text', 'required' => true],
                ['name' => 'serviceId', 'kind' => 'text', 'required' => false],
            ],
            'microsoftUet' => [
                ['name' => 'tagId', 'kind' => 'text', 'required' => true],
                [
                    'name' => 'consentPosture',
                    'kind' => 'enum',
                    'required' => false,
                    'options' => ['block', 'signal-gate'],
                ],
                ['name' => 'serviceId', 'kind' => 'text', 'required' => false],
            ],
            default => [],
        };
    }

    /**
     * Validate a flat config map against a provider's shape.
     *
     * Returns human-readable problems, empty when the config is usable.
     * An unknown provider (empty shape) validates anything — see the
     * class docblock.
     *
     * @param array<string, scalar|null> $config
     * @return list<string>
     */
    public function validate(string $type, array $config): array
    {
        $shape = $this->shapeFor($type);
        if ($shape === []) {
            return [];
        }
        $known = array_column($shape, 'name');
        $problems = [];

        foreach (array_keys($config) as $key) {
            if (!in_array($key, $known, true)) {
                $problems[] = sprintf(
                    'Unknown field "%s" for tracker type "%s". Known: %s.',
                    $key,
                    $type,
                    implode(', ', $known),
                );
            }
        }

        foreach ($shape as $field) {
            $name = $field['name'];
            $value = $config[$name] ?? null;
            $missing = $value === null || $value === '';

            if ($field['required'] && $missing) {
                $problems[] = sprintf('Field "%s" is required for tracker type "%s".', $name, $type);
                continue;
            }
            if ($missing) {
                continue;
            }
            if ($field['kind'] === 'enum' && !in_array((string) $value, $field['options'] ?? [], true)) {
                $problems[] = sprintf(
                    'Field "%s" must be one of: %s (got "%s").',
                    $name,
                    implode(', ', $field['options'] ?? []),
                    (string) $value,
                );
            }
            if ($field['kind'] === 'url' && filter_var((string) $value, FILTER_VALIDATE_URL) === false) {
                $problems[] = sprintf('Field "%s" must be a URL (got "%s").', $name, (string) $value);
            }
        }

        return $problems;
    }
}
