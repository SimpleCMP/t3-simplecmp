<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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

    #[Test]
    public function rotateAlwaysGeneratesFreshSecret(): void
    {
        // Pre-existing secret — unlike ensureExists, rotate must
        // overwrite it.
        $oldSecret = base64_encode(random_bytes(32));
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['t3_simplecmp']['bridgeSecret'] = $oldSecret;

        $newValue = null;
        $configurationManager = $this->createMock(ConfigurationManager::class);
        $configurationManager->expects(self::once())
            ->method('setLocalConfigurationValueByPath')
            ->with(
                'EXTENSIONS/t3_simplecmp/bridgeSecret',
                self::callback(static function (mixed $value) use ($oldSecret, &$newValue): bool {
                    if (!is_string($value) || strlen($value) < 32) {
                        return false;
                    }
                    if ($value === $oldSecret) {
                        return false;
                    }
                    $newValue = $value;
                    return true;
                }),
            );

        $provider = new BridgeSecretProvider($configurationManager);

        self::assertTrue($provider->rotate());
        self::assertSame($newValue, $provider->get(), 'globals are populated with the new secret');
    }

    #[Test]
    public function ensureExistsDoesNotGenerateOverATooShortSecret(): void
    {
        // A too-short value is rejected by get() but IS present — generating
        // over it would write a secret the (env-bound) too-short value
        // overrides next request → churn. Warn, don't write.
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['t3_simplecmp']['bridgeSecret'] = 'too-short';

        $configurationManager = $this->createMock(ConfigurationManager::class);
        $configurationManager->expects(self::never())->method('setLocalConfigurationValueByPath');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $provider = new BridgeSecretProvider($configurationManager, $logger);

        self::assertFalse($provider->ensureExists());
        self::assertFalse($provider->isConfigured());
    }

    #[Test]
    public function ensureExistsDoesNotRegenerateWhenPersistedSecretIsOverridden(): void
    {
        // Runtime value absent (e.g. additional.php binds an empty env var),
        // but LocalConfiguration.php already holds a valid secret → the runtime
        // override is masking it. Regenerating would just churn; warn instead.
        // (No $GLOBALS bridgeSecret set → runtime absent.)
        $configurationManager = $this->createMock(ConfigurationManager::class);
        $configurationManager->method('getLocalConfigurationValueByPath')
            ->with('EXTENSIONS/t3_simplecmp/bridgeSecret')
            ->willReturn(base64_encode(random_bytes(32)));
        $configurationManager->expects(self::never())->method('setLocalConfigurationValueByPath');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $provider = new BridgeSecretProvider($configurationManager, $logger);

        self::assertFalse($provider->ensureExists());
    }

    #[Test]
    public function ensureExistsGeneratesWhenNothingPersistedYet(): void
    {
        // Regression guard for the robust fix: runtime absent AND nothing
        // persisted → genuinely first-time → must still generate.
        $configurationManager = $this->createMock(ConfigurationManager::class);
        // Real ConfigurationManager throws when the path is absent; mimic that.
        $configurationManager->method('getLocalConfigurationValueByPath')
            ->willThrowException(new \RuntimeException('path missing'));
        $configurationManager->expects(self::once())->method('setLocalConfigurationValueByPath');

        $provider = new BridgeSecretProvider($configurationManager);

        self::assertTrue($provider->ensureExists());
        self::assertTrue($provider->isConfigured());
    }
}
