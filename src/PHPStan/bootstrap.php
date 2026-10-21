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

namespace PHPRegex\PHPStan;

use PHPRegex\Parser\Hir\ClassSetProvider;
use PHPStan\DependencyInjection\Container;

// What the engine answers about Unicode classes is kept under PHPStan's
// tmpDir, for the next run and the other workers. PHPStan runs this file
// once in each process, the main one and every worker, with its container
// in scope; the setting is process-wide, so no rule sets it.
$container = get_defined_vars()['container'] ?? null;
$tmpDir = $container instanceof Container ? $container->getParameter('tmpDir') : null;
ClassSetProvider::persistIn(\is_string($tmpDir) ? $tmpDir.'/php-regex' : null);
