<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use SimpleCMP\T3SimpleCmp\Service\WebhookRequestGuard;

final class WebhookRequestGuardTest extends TestCase
{
    #[Test]
    public function acceptsRequestWithoutFetchOrOriginHeaders(): void
    {
        $guard = new WebhookRequestGuard($this->siteFinder(['dev14.ddev.site']));
        self::assertNull($guard->check($this->request([])));
    }

    #[Test]
    public function acceptsSameOriginFetch(): void
    {
        $guard = new WebhookRequestGuard($this->siteFinder(['dev14.ddev.site']));
        self::assertNull($guard->check($this->request(['Sec-Fetch-Site' => 'same-origin'])));
    }

    #[Test]
    public function acceptsSameSiteFetch(): void
    {
        $guard = new WebhookRequestGuard($this->siteFinder(['dev14.ddev.site']));
        self::assertNull($guard->check($this->request(['Sec-Fetch-Site' => 'same-site'])));
    }

    #[Test]
    public function rejectsCrossSiteFetch(): void
    {
        $guard = new WebhookRequestGuard($this->siteFinder(['dev14.ddev.site']));
        $err = $guard->check($this->request(['Sec-Fetch-Site' => 'cross-site']));
        self::assertNotNull($err);
        self::assertStringContainsString('Cross-site', $err);
    }

    #[Test]
    public function rejectsNoneFetch(): void
    {
        $guard = new WebhookRequestGuard($this->siteFinder(['dev14.ddev.site']));
        self::assertNotNull($guard->check($this->request(['Sec-Fetch-Site' => 'none'])));
    }

    #[Test]
    public function acceptsKnownOrigin(): void
    {
        $guard = new WebhookRequestGuard($this->siteFinder(['dev14.ddev.site', 'other.example']));
        self::assertNull($guard->check($this->request(['Origin' => 'https://dev14.ddev.site'])));
        self::assertNull($guard->check($this->request(['Origin' => 'https://other.example'])));
    }

    #[Test]
    public function rejectsUnknownOrigin(): void
    {
        $guard = new WebhookRequestGuard($this->siteFinder(['dev14.ddev.site']));
        $err = $guard->check($this->request(['Origin' => 'https://attacker.example.com']));
        self::assertNotNull($err);
        self::assertStringContainsString('origin', strtolower($err));
    }

    #[Test]
    public function tolerantOfNullOriginLiteral(): void
    {
        // Some browsers send `Origin: null` for sandboxed contexts. We
        // tolerate it (no host to validate) — Sec-Fetch-Site would still
        // catch a sandboxed cross-site case.
        $guard = new WebhookRequestGuard($this->siteFinder(['dev14.ddev.site']));
        self::assertNull($guard->check($this->request(['Origin' => 'null'])));
    }

    #[Test]
    public function caseInsensitiveOriginHostMatch(): void
    {
        $guard = new WebhookRequestGuard($this->siteFinder(['dev14.ddev.site']));
        self::assertNull($guard->check($this->request(['Origin' => 'https://Dev14.DDEV.site'])));
    }

    /** @param array<string,string> $headers */
    private function request(array $headers): ServerRequestInterface
    {
        $req = $this->createMock(ServerRequestInterface::class);
        $req->method('getHeader')->willReturnCallback(
            static fn (string $name) => isset($headers[$name]) ? [$headers[$name]] : []
        );
        return $req;
    }

    /** @param list<string> $hosts */
    private function siteFinder(array $hosts): SiteFinder
    {
        $sites = array_map(
            fn (string $host) => $this->site($host),
            $hosts,
        );
        $finder = $this->createMock(SiteFinder::class);
        $finder->method('getAllSites')->willReturn($sites);
        return $finder;
    }

    private function site(string $host): Site
    {
        return new Site($host, 1, ['base' => 'https://' . $host . '/']);
    }
}
