<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tracker;

/**
 * Microsoft Universal Event Tracking (UET, Bing Ads / Microsoft Advertising) —
 * ADR-0017.
 *
 * Required YAML keys:
 *   - tagId  The 8-digit UET Tag ID from the Microsoft Advertising UI
 *            (Tools → UET Tag → Tag ID). Drives both the bootstrap
 *            `window.uetq.push('init', '<tagId>')` and the service-DB
 *            description.
 *
 * Optional:
 *   - consentPosture  enum (default `block`) — Consent Mode wiring.
 *                     `block`: the bundle's runtime patch defers
 *                     `bat.js` until the visitor accepts; the
 *                     `window.uetq` queue is safe to pre-create (Microsoft
 *                     designed it as a queue array like `dataLayer`,
 *                     `bat.js` drains it on load), so future calls from
 *                     the customer's tag also get queued correctly.
 *                     `signal-gate`: the loader runs pre-consent; the
 *                     engine's Consent Mode v2 vendor adapter dispatches
 *                     `uetq.push('consent', 'default'/'update',
 *                     { ad_storage })` so Microsoft honours the visitor's
 *                     decision. As of May 2025 Microsoft mandated the
 *                     signal — running UET without it risks the account
 *                     being flagged as non-compliant.
 *   - serviceId       string — override default `service_id`.
 */
final readonly class MicrosoftUetProvider implements TrackerProviderInterface, ConsentVendorAware
{
    public function getConsentVendor(array $config): string
    {
        return 'microsoftUet';
    }


    public function getType(): string
    {
        return 'microsoftUet';
    }

    public function getDefaultServiceId(): string
    {
        return 'microsoft-uet';
    }

    public function buildServiceData(array $config): array
    {
        $tagId = $this->requireTagId($config);
        $serviceId = (string) ($config['serviceId'] ?? $this->getDefaultServiceId());

        return [
            'id' => $serviceId,
            'name' => 'Microsoft UET (Bing Ads)',
            'vendor' => 'Microsoft Corporation',
            'vendorCountry' => 'US',
            'purposes' => ['marketing'],
            'privacyPolicyUrl' => 'https://privacy.microsoft.com/privacystatement',
            'description' => sprintf(
                'Microsoft Universal Event Tracking (UET tag %s). Loads bat.js from bat.bing.com and posts conversion + audience signals back to Microsoft Advertising.',
                $tagId,
            ),
            'matches' => [
                'origins' => [
                    'bat.bing.com',
                ],
                'cookies' => [
                    // Microsoft anonymous user identifier.
                    'MUID',
                    // UET conversion + audience cookies.
                    '_uetsid',
                    '_uetsid_exp',
                    '_uetvid',
                    '_uetvid_exp',
                ],
            ],
            'retention' => [
                'MUID' => '13 months',
                '_uetsid' => '1 day',
                '_uetvid' => '13 months',
            ],
        ];
    }

    public function getLoaderUrl(array $config): ?string
    {
        // bat.js drains the `uetq` queue when it loads. No tag-id in the
        // URL — UET reads it from the queue's init push instead.
        return 'https://bat.bing.com/bat.js';
    }

    public function getBootstrapInlineScript(array $config): string
    {
        $tagId = $this->requireTagId($config);
        $tagJson = json_encode($tagId, JSON_THROW_ON_ERROR);

        // Pre-create the queue and seed it with the init push. Safe to
        // call even pre-loader: bat.js iterates `uetq` on load, draining
        // any queued pushes including this `init`. Same pattern as
        // `dataLayer` for gtag.
        $lines = [
            'window.uetq = window.uetq || [];',
            // Microsoft's recommended seed: an `init` push with the tag id
            // (and an optional config object — left empty here, all UET
            // tunables ship from the customer's bat.js install).
            'window.uetq.push("event", "init", {ti: ' . $tagJson . '});',
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
     * Same `block` / `signal-gate` enum as the Google providers — see
     * {@see Ga4Provider::resolvePosture()} for the full ADR-0016
     * reasoning on why these postures are mutually exclusive.
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
    private function requireTagId(array $config): string
    {
        $value = $config['tagId'] ?? null;
        // Current UET tag IDs are 8-digit numerics. Permit a small range
        // (7-10) in case Microsoft changes the format; reject anything
        // non-numeric so config typos trip at the wizard.
        if (!is_string($value) || !preg_match('/^[0-9]{7,10}$/', $value)) {
            throw new \InvalidArgumentException(
                'Microsoft UET tracker config: `tagId` is required and must be a 7–10 digit number from Microsoft Advertising → UET Tag → Tag ID.',
            );
        }
        return $value;
    }
}
