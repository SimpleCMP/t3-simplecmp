<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tracker;

/**
 * Google Analytics 4 tracker (gtag.js).
 *
 * Required YAML keys:
 *   - measurementId  `G-XXXXXXXXXX` from the GA4 admin
 *
 * Optional:
 *   - anonymizeIp     bool (default true) — emits `gtag('set', { ip_anonymization: true })`
 *                     before `config`. Default ON because in DACH the
 *                     IP-anonymization is part of the BfDI / DSK
 *                     "minimum reasonable" interpretation.
 *   - consentPosture  enum (default `block`) — Consent Mode v2 wiring.
 *                     `block`: the bundle's runtime patch defers
 *                     `gtag/js` until the visitor accepts. No third-
 *                     party traffic pre-consent. DACH-safest default.
 *                     `signal-gate`: the loader runs pre-consent and
 *                     sends Consent-Mode-v2 cookieless pings; the
 *                     engine's `consentMode` hook (signalled to
 *                     {@see RegisterAssets} via {@see TrackerRuntimeState})
 *                     owns both `default (denied)` and the matching
 *                     `update (granted)` on accept — the missing half
 *                     of GA4's consent contract, formerly a `@todo`.
 *                     Trade-off: the cookieless ping is still a Google
 *                     network call several DACH/EU regulators contest
 *                     (see `docs/decisions/2026-06-12-consent-mode-v2-
 *                     tracker-wiring.md`).
 *   - serviceId       string — override the default `service_id`.
 */
final readonly class Ga4Provider implements TrackerProviderInterface, ConsentVendorAware
{
    public function getConsentVendor(array $config): string
    {
        return 'google';
    }


    public function getType(): string
    {
        return 'ga4';
    }

    public function getDefaultServiceId(): string
    {
        return 'ga4';
    }

    public function buildServiceData(array $config): array
    {
        $measurementId = $this->requireMeasurementId($config);
        $serviceId = (string) ($config['serviceId'] ?? $this->getDefaultServiceId());

        return [
            'id' => $serviceId,
            'name' => 'Google Analytics 4',
            'vendor' => 'Google LLC',
            'vendorCountry' => 'US',
            'vendorOptOutUrl' => 'https://tools.google.com/dlpage/gaoptout',
            'purposes' => ['analytics'],
            'privacyPolicyUrl' => 'https://policies.google.com/privacy',
            'description' => sprintf(
                'Google Analytics 4 (measurement id %s). Sends pseudonymous traffic data to Google in the US.',
                $measurementId,
            ),
            'matches' => [
                // The loader and the collect-beacons share these origins.
                // *.analytics.google.com is the modern endpoint, the
                // www.google-analytics.com hostname remains for legacy
                // gtag.js install flows.
                'origins' => [
                    'www.googletagmanager.com',
                    'www.google-analytics.com',
                    '*.analytics.google.com',
                    '*.google-analytics.com',
                ],
                'cookies' => [
                    '_ga',
                    '_ga_*',
                    '_gid',
                    '_gat',
                    '_gat_*',
                ],
            ],
            'retention' => [
                '_ga' => '2 years',
                '_ga_*' => '2 years',
                '_gid' => '24 hours',
                '_gat*' => '1 minute',
            ],
        ];
    }

    public function getLoaderUrl(array $config): ?string
    {
        $measurementId = $this->requireMeasurementId($config);
        return 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode($measurementId);
    }

    public function getBootstrapInlineScript(array $config): string
    {
        $measurementId = $this->requireMeasurementId($config);
        $anonymizeIp = (bool) ($config['anonymizeIp'] ?? true);

        $measurementJson = json_encode($measurementId, JSON_THROW_ON_ERROR);

        // No more hand-rolled `gtag('consent', 'default', {…denied…})` here.
        //   - posture=block:        the loader never fires pre-consent, so
        //                           a `default: denied` is moot AND it
        //                           would silently suppress GA4 *after*
        //                           consent because we never emit the
        //                           matching `update (granted)`
        //                           (resolved in #6 / ADR-0016).
        //   - posture=signal-gate:  the engine's `consentMode` hook owns
        //                           both `default` AND `update` — letting
        //                           the provider emit a competing
        //                           `default` here would race the engine.
        $lines = [
            'window.dataLayer = window.dataLayer || [];',
            'function gtag(){dataLayer.push(arguments);}',
            "gtag('js', new Date());",
        ];

        $configOptions = [];
        if ($anonymizeIp) {
            $configOptions['anonymize_ip'] = true;
        }
        if ($configOptions !== []) {
            $configOptionsJson = json_encode((object) $configOptions, JSON_THROW_ON_ERROR);
            $lines[] = "gtag('config', {$measurementJson}, {$configOptionsJson});";
        } else {
            $lines[] = "gtag('config', {$measurementJson});";
        }

        return implode("\n", $lines);
    }

    public function wantsLoadGate(array $config): bool
    {
        return $this->resolvePosture($config) === 'block';
    }

    public function wantsConsentMode(array $config): bool
    {
        return $this->resolvePosture($config) === 'signal-gate';
    }

    /**
     * Resolve the configured posture, defaulting to `block` (the safe
     * DACH default) for any missing / unknown / legacy value. The legacy
     * `consentMode` boolean is intentionally NOT honored as a posture
     * switch: in the old code that flag toggled the now-removed
     * hand-rolled `gtag('consent', 'default', …denied…)` block, which
     * combined with the load gate produced the ADR-0016 anti-pattern.
     * Operators who want signal-gate must opt in explicitly via
     * `consentPosture: signal-gate`.
     *
     * @param array<string, mixed> $config
     */
    private function resolvePosture(array $config): string
    {
        $raw = $config['consentPosture'] ?? null;
        if (is_string($raw) && in_array($raw, ['block', 'signal-gate'], true)) {
            return $raw;
        }
        return 'block';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function requireMeasurementId(array $config): string
    {
        $value = $config['measurementId'] ?? null;
        if (!is_string($value) || !preg_match('/^G-[A-Z0-9]+$/', $value)) {
            throw new \InvalidArgumentException(
                'GA4 tracker config: `measurementId` is required and must match `G-XXXXXXXX` (uppercase).',
            );
        }
        return $value;
    }
}