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
 *   - consentDefault  bool (default true) — emit Consent Mode v2
 *                     `default` state with everything denied so any
 *                     tag in the container respects the CMP gate.
 *                     The CMP grants the matching state on accept.
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
final readonly class GtmProvider implements TrackerProviderInterface
{
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
        $containerId = $this->requireContainerId($config);
        $consentDefault = (bool) ($config['consentDefault'] ?? true);

        $containerJson = json_encode($containerId, JSON_THROW_ON_ERROR);

        $lines = [
            'window.dataLayer = window.dataLayer || [];',
        ];

        if ($consentDefault) {
            // Pre-deny every Consent-Mode-v2 storage type. GTM tags
            // that respect Consent Mode will then sit idle until the
            // CMP grants the storage they need.
            // @todo wire CMP consent events to emit
            //       gtag('consent', 'update', {...}) with granted state.
            //       Engine now ships this as the opt-in `consentMode` hook
            //       (REQ-N10 / ADR-0016 upstream). Integration + the
            //       block-vs-signal-gate posture:
            //       docs/decisions/2026-06-12-consent-mode-v2-tracker-wiring.md
            $lines[] = 'function gtag(){dataLayer.push(arguments);}';
            $lines[] = "gtag('consent', 'default', {"
                . "'ad_storage': 'denied',"
                . "'ad_user_data': 'denied',"
                . "'ad_personalization': 'denied',"
                . "'analytics_storage': 'denied',"
                . "'wait_for_update': 500"
                . "});";
        }

        // The GTM startup line. `dataLayer.push` triggers the
        // container init once the loader script has executed.
        $lines[] = "window.dataLayer.push({"
            . "'gtm.start': Date.now(),"
            . "'event': 'gtm.js'"
            . "});";

        return implode("\n", $lines);
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
