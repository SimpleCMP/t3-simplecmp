<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Resolves the module-wide "active site" for the SimpleCMP backend
 * module and persists it in the BE user's module data.
 *
 * With the unified "one draft per site" model, every tab — even the
 * site-agnostic ones (Dienste / Detektionen) — needs to agree on which
 * site's draft is being worked on. This resolver is the single source of
 * that truth: a tab with a site picker passes its explicit choice (which
 * is persisted), the others pass null and inherit the persisted value.
 * Falls back to the first SimpleCMP-enabled site.
 */
final class ActiveSiteResolver
{
    /** Site set that marks a site as SimpleCMP-enabled. */
    private const string SET_IDENTIFIER = 'simplecmp/t3-simplecmp';

    /** BE-user module-data key holding the persisted active site. */
    private const string MODULE_DATA_KEY = 'simplecmp/activeSite';

    public function __construct(
        private readonly SiteFinder $siteFinder,
    ) {
    }

    /**
     * Identifiers of all SimpleCMP-enabled sites, sorted.
     *
     * @return list<string>
     */
    public function availableSites(): array
    {
        $ids = [];
        foreach ($this->siteFinder->getAllSites() as $identifier => $site) {
            if (in_array(self::SET_IDENTIFIER, $site->getSets(), true)) {
                $ids[] = $identifier;
            }
        }
        sort($ids);
        return $ids;
    }

    /**
     * The current active site. A valid, explicit `$requested` wins and is
     * persisted; otherwise the persisted choice is used; otherwise the
     * first available site. Returns '' when no SimpleCMP site exists.
     */
    public function resolve(?string $requested = null): string
    {
        $available = $this->availableSites();
        if ($available === []) {
            return '';
        }
        $beUser = $this->backendUser();
        if ($requested !== null && $requested !== '' && in_array($requested, $available, true)) {
            $this->persist($beUser, $requested);
            return $requested;
        }
        $stored = $this->stored($beUser);
        if ($stored !== '' && in_array($stored, $available, true)) {
            return $stored;
        }
        $first = $available[0];
        $this->persist($beUser, $first);
        return $first;
    }

    private function backendUser(): ?BackendUserAuthentication
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;
        return $beUser instanceof BackendUserAuthentication ? $beUser : null;
    }

    private function stored(?BackendUserAuthentication $beUser): string
    {
        if ($beUser === null) {
            return '';
        }
        $data = $beUser->getModuleData(self::MODULE_DATA_KEY);
        return is_string($data) ? $data : '';
    }

    private function persist(?BackendUserAuthentication $beUser, string $site): void
    {
        if ($beUser === null || $this->stored($beUser) === $site) {
            return;
        }
        $beUser->pushModuleData(self::MODULE_DATA_KEY, $site);
    }
}
