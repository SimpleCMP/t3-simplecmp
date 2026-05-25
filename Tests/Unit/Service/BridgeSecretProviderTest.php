<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Service\BridgeSecretProvider;

final class BridgeSecretProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['t3_simplecmp']['bridgeSecret']);
    }

    #[Test]
    public function returnsNullWhenNotConfigured(): void
    {
        $provider = new BridgeSecretProvider();
        self::assertNull($provider->get());
        self::assertFalse($provider->isConfigured());
    }

    #[Test]
    public function returnsNullForEmptyString(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['t3_simplecmp']['bridgeSecret'] = '';
        $provider = new BridgeSecretProvider();
        self::assertNull($provider->get());
    }

    #[Test]
    public function returnsNullForNonString(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['t3_simplecmp']['bridgeSecret'] = 12345;
        $provider = new BridgeSecretProvider();
        self::assertNull($provider->get());
    }

    #[Test]
    public function returnsNullForTooShortSecret(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['t3_simplecmp']['bridgeSecret'] = 'too-short';
        $provider = new BridgeSecretProvider();
        self::assertNull($provider->get());
    }

    #[Test]
    public function returnsConfiguredSecret(): void
    {
        $secret = base64_encode(random_bytes(32));
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['t3_simplecmp']['bridgeSecret'] = $secret;
        $provider = new BridgeSecretProvider();
        self::assertSame($secret, $provider->get());
        self::assertTrue($provider->isConfigured());
    }
}
