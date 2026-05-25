<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * - Loads composer autoload.
 * - Enables BypassFinals so the unit test suite can mock the
 *   `final readonly` service classes. Runtime behaviour of those
 *   classes is unchanged — bypass-finals only modifies the
 *   class-loading path inside the test process.
 * - Sets DB-connection env-var defaults for the functional test
 *   suite (typo3/testing-framework reads `typo3Database*` from the
 *   environment). The defaults point at DDEV's bundled MariaDB so
 *   `composer test:functional` runs locally without ceremony; CI
 *   sets its own env vars before phpunit starts, which take
 *   precedence.
 */

require __DIR__ . '/../vendor/autoload.php';

\DG\BypassFinals::enable();

$functionalDbDefaults = [
    'typo3DatabaseDriver' => 'mysqli',
    'typo3DatabaseHost' => 'db',
    'typo3DatabaseUsername' => 'root',
    'typo3DatabasePassword' => 'root',
    'typo3DatabaseName' => 'func_t3_simplecmp',
];
foreach ($functionalDbDefaults as $name => $value) {
    if (getenv($name) === false || getenv($name) === '') {
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

// typo3/testing-framework requires ORIGINAL_ROOT pointing at a TYPO3
// document root (the dir containing index.php). The composer
// autoload-include from cms-composer-installers already sets
// TYPO3_PATH_ROOT to the extension's own `public/`, so this just
// promotes that to the constant the framework expects and pre-creates
// the temp dirs it writes to. Unit-only test runs short-circuit
// inside defineOriginalRootPath() if the framework isn't installed.
if (class_exists(\TYPO3\TestingFramework\Core\Testbase::class)) {
    $testbase = new \TYPO3\TestingFramework\Core\Testbase();
    $testbase->defineOriginalRootPath();
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/tests');
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/transient');
}
