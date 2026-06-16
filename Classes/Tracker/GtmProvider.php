<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tracker;

/**
 * Google Tag Manager.
 *
 * Required YAML keys:
 *   - containerId  `GTM-XXXXXXX` from the GTM console
 *
 * Optional:
 *   - consentPosture  enum (default `block`) — see {@see Ga4Provider}
 *                     for the trade-off. GTM has the additional wrinkle
 *                     that its tags are configured client-side in
 *                     Google's console: in `signal-gate` posture, each
 *                     tag inside the container still has to opt into
 *                     Consent Mode v2 (`Erforderliche Einwilligungen` /
 *                     "Built-in Consent Settings") for the gate to
 *                     bite. Tags missing that config WILL fire
 *                     pre-consent.
 *   - serviceId       string — override default `service_id`.
 *
 * NOTE on purposes: GTM itself doesn't track — it's a tag-host. But
 * since editors typically deploy it to ship advertising / marketing
 * tags through it, we mark it `marketing` as the broader / safer
 * default. Sites that ONLY use GTM for analytics tags can override:
 *
 *     simplecmp:
 *       trackers:
 *         - type: gtm
 *           containerId: GTM-XXXX
 *           purposes: ['analytics']    # honored via the YAML override
 */
final readonly class GtmProvider implements TrackerProviderInterface, ConsentVendorAware
{
    public function getConsentVendor(array $config): string
    {
        return 'google';
    }


    public function getType(): string
    {
        return 'gtm';
    }

    public function getDefaultServiceId(): string
    {
        return 'gtm';
    }

    public function buildServiceData(array $config): array
    {
        $containerId = $this->requireContainerId($config);
        $serviceId = (string) ($config['serviceId'] ?? $this->getDefaultServiceId());
        $purposes = $this->resolvePurposes($config);

        return [
            'id' => $serviceId,
            'name' => 'Google Tag Manager',
            'vendor' => 'Google LLC',
            'vendorCountry' => 'US',
            'purposes' => $purposes,
            'privacyPolicyUrl' => 'https://policies.google.com/privacy',
            'description' => sprintf(
                'Google Tag Manager (container %s). Loads further tags configured in the GTM console — each of those needs its own consent gate inside GTM.',
                $containerId,
            ),
            'matches' => [
                'origins' => ['www.googletagmanager.com'],
                // GTM itself doesn't write cookies — its tags do.
                // We leave cookies empty so the recorder doesn't blame
                // the GTM service for cookies set by downstream tags.
                'cookies' => [],
            ],
        ];
    }

    public function getLoaderUrl(array $config): ?string
    {
        $containerId = $this->requireContainerId($config);
        return 'https://www.googletagmanager.com/gtm.js?id=' . rawurlencode($containerId);
    }

    public function getBootstrapInlineScript(array $config): string
    {
        // Validate the container ID so a misconfigured row trips here
        // (same error surface as before) rather than silently emitting
        // a half-broken bootstrap.
        $this->requireContainerId($config);

        // No more hand-rolled `gtag('consent', 'default', {…denied…})`
        // here — same reasoning as Ga4Provider (resolved in #6 /
        // ADR-0016). In `block` posture the loader never fires
        // pre-consent so a default-deny is moot; in `signal-gate`
        // posture the engine's `consentMode` hook owns the full
        // default+update lifecycle. The container ID is already part of
        // the loader URL ({@see getLoaderUrl()}), so the inline payload
        // only has to install the dataLayer and push the `gtm.js` start
        // event the container reacts to once the loader runs.
        $lines = [
            'window.dataLayer = window.dataLayer || [];',
            "window.dataLayer.push({"
            . "'gtm.start': Date.now(),"
            . "'event': 'gtm.js'"
            . "});",
        ];

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
     * `consentDefault` boolean is intentionally NOT honored as a posture
     * switch — see {@see Ga4Provider::resolvePosture()} for the full
     * ADR-0016 reasoning.
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
    private function requireContainerId(array $config): string
    {
        $value = $config['containerId'] ?? null;
        if (!is_string($value) || !preg_match('/^GTM-[A-Z0-9]+$/', $value)) {
            throw new \InvalidArgumentException(
                'GTM tracker config: `containerId` is required and must match `GTM-XXXXXXX` (uppercase).',
            );
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private function resolvePurposes(array $config): array
    {
        $raw = $config['purposes'] ?? null;
        if (is_array($raw) && $raw !== []) {
            $clean = [];
            foreach ($raw as $value) {
                if (is_string($value) && $value !== '') {
                    $clean[] = $value;
                }
            }
            if ($clean !== []) {
                return array_values(array_unique($clean));
            }
        }
        return ['marketing'];
    }
}