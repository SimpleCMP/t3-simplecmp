<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tracker;

/**
 * Matomo on-prem / Cloud tracker.
 *
 * Required YAML keys:
 *   - url     base URL of the Matomo install, with trailing slash
 *             (`https://matomo.example.com/`)
 *   - siteId  the integer ID Matomo assigns to this site
 *
 * Optional:
 *   - disableCookies  bool (default false) — emit
 *                     `_paq.push(['disableCookies'])` so Matomo runs
 *                     cookieless. When true, the registered
 *                     cookie-pattern list shrinks to empty too.
 *   - serviceId       string — override the default `service_id` for
 *                     setups with two Matomo instances.
 */
final readonly class MatomoProvider implements TrackerProviderInterface
{
    public function getType(): string
    {
        return 'matomo';
    }

    public function getDefaultServiceId(): string
    {
        return 'matomo';
    }

    public function buildServiceData(array $config): array
    {
        $url = $this->requireString($config, 'url');
        $siteId = $this->requireScalar($config, 'siteId');
        $disableCookies = (bool) ($config['disableCookies'] ?? false);

        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $serviceId = (string) ($config['serviceId'] ?? $this->getDefaultServiceId());

        $data = [
            'id' => $serviceId,
            'name' => 'Matomo Analytics',
            'vendor' => 'Matomo / InnoCraft',
            'vendorCountry' => 'NZ',
            'purposes' => ['analytics'],
            'privacyPolicyUrl' => 'https://matomo.org/privacy/',
            'description' => sprintf(
                'Self-hosted privacy-friendly web analytics (site id %s on %s).',
                $siteId,
                $host,
            ),
            'matches' => [
                'origins' => [$host],
                // No cookies in the matcher when the integrator opted
                // for cookieless mode — otherwise the recorder would
                // raise unknown-cookie alerts on every page view.
                'cookies' => $disableCookies ? [] : ['_pk_id*', '_pk_ses*', '_pk_ref*'],
            ],
            'retention' => $disableCookies ? null : [
                '_pk_id*' => '13 months',
                '_pk_ses*' => '30 minutes',
                '_pk_ref*' => '6 months',
            ],
        ];

        return array_filter($data, static fn($v) => $v !== null);
    }

    public function getLoaderUrl(array $config): ?string
    {
        $url = $this->requireString($config, 'url');
        return rtrim($url, '/') . '/matomo.js';
    }

    public function getBootstrapInlineScript(array $config): string
    {
        $url = $this->requireString($config, 'url');
        $siteId = $this->requireScalar($config, 'siteId');
        $disableCookies = (bool) ($config['disableCookies'] ?? false);

        $trackerUrlJson = json_encode(rtrim($url, '/') . '/matomo.php', JSON_THROW_ON_ERROR);
        $siteIdJson = json_encode((string) $siteId, JSON_THROW_ON_ERROR);

        $lines = [
            'window._paq = window._paq || [];',
            "window._paq.push(['setTrackerUrl', {$trackerUrlJson}]);",
            "window._paq.push(['setSiteId', {$siteIdJson}]);",
            "window._paq.push(['enableLinkTracking']);",
        ];
        if ($disableCookies) {
            $lines[] = "window._paq.push(['disableCookies']);";
        }
        // trackPageView pushes last so the setup is in place when the
        // loader actually fires it.
        $lines[] = "window._paq.push(['trackPageView']);";

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function requireString(array $config, string $key): string
    {
        if (!isset($config[$key]) || !is_string($config[$key]) || $config[$key] === '') {
            throw new \InvalidArgumentException(sprintf(
                'Matomo tracker config: required string key "%s" is missing or empty.',
                $key,
            ));
        }
        return $config[$key];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function requireScalar(array $config, string $key): int|string
    {
        if (!isset($config[$key]) || (!is_int($config[$key]) && !is_string($config[$key])) || $config[$key] === '') {
            throw new \InvalidArgumentException(sprintf(
                'Matomo tracker config: required scalar key "%s" is missing or empty.',
                $key,
            ));
        }
        return $config[$key];
    }
}
