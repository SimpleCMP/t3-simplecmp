<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Service\BridgeSecretProvider;
use TYPO3\CMS\Core\Configuration\ConfigurationManager;

final class BridgeSecretProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['t3_simplecmp']['bridgeSecret']);
    }

    #[Test]
    public function returnsNullWhenNotConfigured(): void
    {
        $provider = new BridgeSecretProvider(null);
        self::assertNull($provider->get());
        self::assertFalse($provider->isConfigured());
    }

    #[Test]
    public function returnsNullForEmptyString(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['t3_simplecmp']['bridgeSecret'] = '';
        $provider = new BridgeSecretProvider(null);
        self::assertNull($provider->get());
    }

    #[Test]
    public function returnsNullForNonString(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['t3_simplecmp']['bridgeSecret'] = 12345;
        $provider = new BridgeSecretProvider(null);
        self::assertNull($provider->get());
    }

    #[Test]
    public function returnsNullForTooShortSecret(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['t3_simplecmp']['bridgeSecret'] = 'too-short';
        $provider = new BridgeSecretProvider(null);
        self::assertNull($provider->get());
    }

    #[Test]
    public function returnsConfiguredSecret(): void
    {
        $secret = base64_encode(random_bytes(32));
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['t3_simplecmp']['bridgeSecret'] = $secret;
        $provider = new BridgeSecretProvider(null);
        self::assertSame($secret, $provider->get());
        self::assertTrue($provider->isConfigured());
    }

    #[Test]
    public function ensureExistsGeneratesWhenMissing(): void
    {
        $configurationManager = $this->createMock(ConfigurationManager::class);
        $configurationManager->expects(self::once())
            ->method('setLocalConfigurationValueByPath')
            ->with(
                'EXTENSIONS/t3_simplecmp/bridgeSecret',
                self::callback(static fn (mixed $value): bool => is_string($value) && strlen($value) >= 32),
            );

        $provider = new BridgeSecretProvider($configurationManager);

        self::assertTrue($provider->ensureExists(), 'first call generates and returns true');
        self::assertTrue($provider->isConfigured(), 'globals are populated after generation');
    }

    #[Test]
    public function ensureExistsIsNoOpWhenAlreadyConfigured(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['t3_simplecmp']['bridgeSecret']
            = base64_encode(random_bytes(32));
        $configurationManager = $this->createMock(ConfigurationManager::class);
        $configurationManager->expects(self::never())
            ->method('setLocalConfigurationValueByPath');

        $provider = new BridgeSecretProvider($configurationManager);

        self::assertFalse($provider->ensureExists());
    }
}
