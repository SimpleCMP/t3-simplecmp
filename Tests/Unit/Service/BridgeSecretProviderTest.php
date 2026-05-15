<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WapplerSystems\SimpleCmpTypo3\Service\BridgeSecretProvider;

final class BridgeSecretProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['simplecmp_typo3']['bridgeSecret']);
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
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['simplecmp_typo3']['bridgeSecret'] = '';
        $provider = new BridgeSecretProvider();
        self::assertNull($provider->get());
    }

    #[Test]
    public function returnsNullForNonString(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['simplecmp_typo3']['bridgeSecret'] = 12345;
        $provider = new BridgeSecretProvider();
        self::assertNull($provider->get());
    }

    #[Test]
    public function returnsNullForTooShortSecret(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['simplecmp_typo3']['bridgeSecret'] = 'too-short';
        $provider = new BridgeSecretProvider();
        self::assertNull($provider->get());
    }

    #[Test]
    public function returnsConfiguredSecret(): void
    {
        $secret = base64_encode(random_bytes(32));
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['simplecmp_typo3']['bridgeSecret'] = $secret;
        $provider = new BridgeSecretProvider();
        self::assertSame($secret, $provider->get());
        self::assertTrue($provider->isConfigured());
    }
}
