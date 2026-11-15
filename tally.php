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

use Tally\Configuration;

// Tally has a Composer project of its own under tools/tally: its
// autoloader knows Tally alone, the library comes from the root one.
require_once __DIR__.'/vendor/autoload.php';

return Configuration::create()
    ->withPaths([__DIR__.'/tests/Counted'])
    // As production runs the library: assertions compiled out. The cycle
    // collector is held, as the timed benchmarks hold it: when it runs
    // depends on what the process allocated before, not on the case.
    ->withIniEntries([
        'memory_limit=-1',
        'zend.assertions=-1',
        'zend.enable_gc=0',
        'pcre.jit=1',
    ]);
