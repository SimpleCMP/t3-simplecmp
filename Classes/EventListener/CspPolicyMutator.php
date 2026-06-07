<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\EventListener;

use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Event\PolicyMutatedEvent;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;

/**
 * Bridges adopted SimpleCMP services into the site's Content-Security-Policy.
 *
 * Every service row in `tx_t3simplecmp_service` carries an `origins`
 * JSON array — the hosts the service reaches out to once consent is
 * granted (e.g. `*.google-analytics.com` for GA4, `matomo.example.com`
 * for a self-hosted Matomo). Without help from this listener, the
 * admin would have to maintain that same list a second time in
 * `config/sites/<id>/csp.yaml` — and every newly adopted service would
 * silently get blocked by CSP until they remember to update it.
 *
 * The listener queries every adopted service at policy-compile time
 * and extends the frontend CSP with their origins on the directives
 * tracker-style integrations typically consume:
 *
 *   - script-src-elem  (loader scripts: GA, Matomo, GTM, Pixel, …)
 *   - connect-src      (beacons, fetch/XHR endpoints)
 *   - img-src          (1×1 tracking pixels)
 *   - frame-src        (third-party iframes/embeds: YouTube, Maps, …)
 *   - style-src-elem   (external stylesheets, e.g. Google Fonts CSS)
 *   - font-src         (web fonts loaded from a CDN)
 *
 * The directive set is intentionally broad. The admin already decided
 * to onboard the service through the BE library browser; the CSP is
 * not where that decision should be re-litigated. Pre-consent
 * blocking remains the CMP runtime's job (universalBlock + per-
 * service consent checks); the CSP exists to ensure that the
 * runtime's blessed requests can actually leave the browser.
 *
 * Backend scope is intentionally untouched — the CMP only operates in
 * the frontend and adding tracker origins to the BE policy would only
 * weaken it.
 */
#[AsEventListener('simplecmp/csp-policy-mutator')]
final readonly class CspPolicyMutator
{
    /**
     * Directives that get the service origins. Order is irrelevant —
     * Policy::mutate() deduplicates within a directive.
     *
     * @var list<Directive>
     */
    private const TARGET_DIRECTIVES = [
        Directive::ScriptSrcElem,
        Directive::ConnectSrc,
        Directive::ImgSrc,
        Directive::FrameSrc,
        Directive::StyleSrcElem,
        Directive::FontSrc,
    ];

    public function __construct(
        private ServiceRepository $serviceRepository,
    ) {
    }

    public function __invoke(PolicyMutatedEvent $event): void
    {
        if ($event->scope->type !== ApplicationType::FRONTEND) {
            return;
        }

        $origins = $this->collectOrigins();
        if ($origins === []) {
            return;
        }

        $uriValues = array_map(
            static fn(string $origin): UriValue => new UriValue($origin),
            $origins,
        );

        $mutations = [];
        foreach (self::TARGET_DIRECTIVES as $directive) {
            $mutations[] = new Mutation(MutationMode::Extend, $directive, ...$uriValues);
        }

        // PolicyProvider returns `$event->getCurrentPolicy()` after the event
        // dispatch, NOT the mutation list — so we have to mutate the Policy
        // object directly and write it back. Updating `mutationCollections`
        // alone would be a no-op against the response.
        $event->setCurrentPolicy(
            $event->getCurrentPolicy()->mutate(new MutationCollection(...$mutations)),
        );
    }

    /**
     * Pull every distinct origin from every adopted service.
     * Services with empty / missing `matches.origins` are skipped —
     * those are cookie-only services (e.g. a first-party session
     * cookie classifier) and don't reach external hosts.
     *
     * @return list<string>
     */
    private function collectOrigins(): array
    {
        $seen = [];
        $out = [];
        foreach ($this->serviceRepository->findAll() as $row) {
            $origins = $row['matches']['origins'] ?? [];
            if (!is_array($origins) || $origins === []) {
                continue;
            }
            foreach ($origins as $origin) {
                if (!is_string($origin) || $origin === '' || isset($seen[$origin])) {
                    continue;
                }
                $seen[$origin] = true;
                $out[] = $origin;
            }
        }
        return $out;
    }
}
