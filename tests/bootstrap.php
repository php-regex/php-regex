<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use PHPRegex\Tests\Support\HostRequirements;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/Support/PhpUnitCompat/bootstrap.php';
require __DIR__.'/Support/LintFunctionOverrides.php';
require __DIR__.'/Support/SelfUpdateFunctionOverrides.php';
require __DIR__.'/Support/SymfonyExtractorFunctionOverrides.php';
require __DIR__.'/Support/DependencyInjectionFunctionOverrides.php';
require __DIR__.'/Support/SecurityConfigFunctionOverrides.php';
require __DIR__.'/Support/CacheOwnerFunctionOverrides.php';

// phpunit.xml.dist lifts the memory limit, which the bootstrap runs after.
// Under the mutation run, a mutant that lifts a bound of the code would
// then take the memory of the machine, and the CI runner with it: the tests
// of each mutant get a limit, far above what the suite needs, that such a
// mutant fails on.
if ('1' === getenv('INFECTION')) {
    ini_set('memory_limit', '2G');
}

// What the suite needs of the host and Composer does not check, said before
// the failures it causes.
fwrite(\STDERR, HostRequirements::notice(HostRequirements::ofThisHost()));
