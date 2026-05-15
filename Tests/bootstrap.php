<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * Loads composer autoload, then enables BypassFinals so test doubles
 * can stand in for production services declared `final readonly`.
 * The runtime behaviour of those classes is unchanged — bypass-finals
 * only modifies the class-loading path inside the test process.
 */

require __DIR__ . '/../vendor/autoload.php';

\DG\BypassFinals::enable();
