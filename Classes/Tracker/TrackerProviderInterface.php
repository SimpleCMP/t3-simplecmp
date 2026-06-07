<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tracker;

/**
 * Tracker provider plug — turns one entry of `simplecmp.trackers`
 * site-setting into the three artefacts the FE needs:
 *
 *   1. a service-DB row (so the banner lists the tracker and the
 *      CSP bridge whitelists its origins)
 *   2. a loader URL (the remote tracker JS — `matomo.js`, `gtag.js`,
 *      `gtm.js`, …) which the asset collector emits with
 *      `data-name="<service_id>"` so the bundle's runtime patch gates
 *      it on consent
 *   3. an inline bootstrap snippet (`_paq.push(...)`, `dataLayer.push`,
 *      `gtag('config', ...)`) that pre-configures the queue before
 *      the loader runs
 *
 * Providers are stateless and discovered by service-tag — register
 * a new provider by `tags: [{ name: 'simplecmp.tracker_provider' }]`
 * in `Configuration/Services.yaml`. Site-setting entries reference
 * a provider via its `type` key.
 */
interface TrackerProviderInterface
{
    /**
     * Stable type key referenced from `simplecmp.trackers[].type`.
     * Lowercase, short, no whitespace. Example: `matomo`, `ga4`, `gtm`.
     */
    public function getType(): string;

    /**
     * Default `service_id` to use if the YAML entry doesn't carry an
     * explicit one. Should match the type for the common single-
     * instance case (`matomo`, `ga4`, `gtm`). Editors with two
     * Matomo instances on one site override via
     * `simplecmp.trackers[].service_id`.
     */
    public function getDefaultServiceId(): string;

    /**
     * Build the array shape `ServiceRepository::upsert()` expects.
     * MUST include at least `id`, `name`, `purposes`, and
     * `matches.origins` (otherwise the CSP bridge has nothing to
     * whitelist). Providers should also set `vendor`, `vendorCountry`,
     * `privacyPolicyUrl`, `description`, `matches.cookies`,
     * `retention`, and `i18n` for the banner UI to show useful info
     * without further admin curation.
     *
     * @param array<string, mixed> $config the raw YAML entry
     * @return array<string, mixed>
     * @throws \InvalidArgumentException when the config is missing
     *         required fields (e.g. Matomo without `siteId`)
     */
    public function buildServiceData(array $config): array;

    /**
     * The remote loader script URL — receives the
     * `data-name="<service_id>"` attribute when emitted, which is
     * what SimpleCMP's runtime intercepts to gate the load on
     * consent.
     *
     * Returns null if the tracker only needs an inline bootstrap and
     * no external loader (rare — Plausible's plain `<script defer>`
     * is the only common case in this category).
     *
     * @param array<string, mixed> $config
     */
    public function getLoaderUrl(array $config): ?string;

    /**
     * Inline JS that prepares the queue (`_paq`, `dataLayer`) and
     * pushes the per-site config (siteId / measurementId /
     * containerId). The asset collector emits this as a regular
     * inline script with `csp => true`, so it picks up the CSP nonce
     * and doesn't trigger a `script-src-elem inline` violation.
     *
     * Return an empty string if the provider has no inline bootstrap
     * (e.g. trackers that read their config from query-string params
     * on the loader URL alone).
     *
     * @param array<string, mixed> $config
     */
    public function getBootstrapInlineScript(array $config): string;
}
