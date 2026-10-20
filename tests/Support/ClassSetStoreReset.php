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

namespace PHPRegex\Tests\Support;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Wired in the PHPUnit configurations: the directory the class scans are
 * kept in is set for the whole process, by the PHPStan rule, the Psalm
 * plugin or the CLI a test builds, and each test leaves it unset for the
 * next, whatever the test it ends did.
 */
final class ClassSetStoreReset implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new ClassSetStoreResetSubscriber());
    }
}
