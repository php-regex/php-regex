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

// The monorepo's own autoloader checks for PHP 8.2, the floor of its
// Symfony and Laravel bridges: on 8.1 the classes are loaded from the
// sources directly, as each package's autoloader would.
$loader = new Composer\Autoload\ClassLoader();
$loader->addPsr4('PHPRegex\\', __DIR__.'/../../src/');
$loader->addPsr4('PHPRegex\\Tests\\', __DIR__.'/../../tests/');
$loader->register(true);

require __DIR__.'/../../tests/Support/LintFunctionOverrides.php';
require __DIR__.'/../../tests/Support/SelfUpdateFunctionOverrides.php';
require __DIR__.'/../../tests/Support/SymfonyExtractorFunctionOverrides.php';
