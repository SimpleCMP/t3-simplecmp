<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tracker;

/**
 * Meta Pixel (Facebook fbq) — ADR-0017.
 *
 * **Signal-only provider.** Unlike Google / Matomo / GTM there is no
 * loader URL or bootstrap snippet. Meta's own loader snippet
 * (`fbevents.js`) ships from the customer's pixel template, and
 * pre-defining `window.fbq` from PHP would make Meta's loader bail
 * with `if(f.fbq)return;` — breaking the pixel. So this provider
 * **only** registers the Service-DB row (banner listing, CSP origins,
 * cookie classifier) and signals the engine to dispatch
 * `fbq('consent', 'grant'|'revoke')` via Consent Mode v2's vendor
 * adapter system.
 *
 * Pre-consent hard suppression for Meta is **load-gating only**
 * (universal pre-consent blocking, ADR-0013) — the signal layer is
 * best-effort for the update / withdraw path and the case where
 * `fbq` is already present at engine init. Operators that want a
 * hard pre-consent guarantee should rely on Universal Blocking
 * (default-on in this extension when the matching site set is
 * enabled).
 *
 * Required YAML keys:
 *   - pixelId   The 15- or 16-digit Pixel ID from Meta Events Manager.
 *               Used only for the service-DB description; the engine
 *               does not need it to dispatch the signal.
 *
 * Optional:
 *   - serviceId   string — override default `service_id`.
 */
final readonly class MetaProvider implements TrackerProviderInterface, ConsentVendorAware
{
    public function getConsentVendor(array $config): string
    {
        return 'meta';
    }


    public function getType(): string
    {
        return 'meta';
    }

    public function getDefaultServiceId(): string
    {
        return 'meta-pixel';
    }

    public function buildServiceData(array $config): array
    {
        $pixelId = $this->requirePixelId($config);
        $serviceId = (string) ($config['serviceId'] ?? $this->getDefaultServiceId());

        return [
            'id' => $serviceId,
            'name' => 'Meta Pixel (Facebook)',
            'vendor' => 'Meta Platforms Ireland Ltd.',
            'vendorCountry' => 'IE',
            'purposes' => ['marketing'],
            'privacyPolicyUrl' => 'https://www.facebook.com/privacy/policy/',
            'description' => sprintf(
                'Meta Pixel (Facebook Conversions, pixel id %s). Loads from connect.facebook.net and posts conversion events to www.facebook.com. Required for Facebook / Instagram ad measurement.',
                $pixelId,
            ),
            'matches' => [
                // Engine + universal-blocking gate these origins. Meta's own
                // collect endpoint is `www.facebook.com/tr/`; the loader
                // script comes from `connect.facebook.net`.
                'origins' => [
                    'connect.facebook.net',
                    'www.facebook.com',
                ],
                'cookies' => [
                    // First-party browser cookie set by fbq.
                    '_fbp',
                    // Click-attribution cookie set when arriving via an fbclid URL.
                    '_fbc',
                ],
            ],
            'retention' => [
                '_fbp' => '90 days',
                '_fbc' => '90 days',
            ],
        ];
    }

    public function getLoaderUrl(array $config): ?string
    {
        // No loader. See class docblock — pre-defining `window.fbq` would
        // bail Meta's own loader. The customer's existing pixel snippet
        // remains the canonical loader; this provider only signals.
        return null;
    }

    public function getBootstrapInlineScript(array $config): string
    {
        // No bootstrap. Validate so a misconfigured row trips here rather
        // than at signal-emission time.
        $this->requirePixelId($config);
        return '';
    }

    public function wantsLoadGate(array $config): bool
    {
        // No loader to gate; the customer's own snippet decides when
        // `fbq` loads. Returning false also signals the materializer not
        // to inject a `data-name` attribute (none exists to inject onto).
        return false;
    }

    public function wantsConsentMode(array $config): bool
    {
        // Always — Meta's signal layer is this provider's only function.
        // The engine's vendor adapter sees `meta` in `consentMode.vendors`
        // and dispatches `fbq('consent', 'grant'|'revoke')` on accept /
        // withdraw.
        return true;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function requirePixelId(array $config): string
    {
        $value = $config['pixelId'] ?? null;
        // Meta pixel IDs are 15-16 digit numerics in the current console
        // (older legacy IDs can be 14). Stay permissive — 13-17 digits —
        // and reject obvious non-IDs (alphanumerics, whitespace, …) so
        // typos surface at the wizard rather than as a silent no-op.
        if (!is_string($value) || !preg_match('/^[0-9]{13,17}$/', $value)) {
            throw new \InvalidArgumentException(
                'Meta tracker config: `pixelId` is required and must be a 13–17 digit number from Meta Events Manager.',
            );
        }
        return $value;
    }
}
