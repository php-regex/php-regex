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

use PHPRegex\Parser\Hir\ClassSetProvider;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

/**
 * Once a test ends, the class scans are kept in memory only again.
 */
final class ClassSetStoreResetSubscriber implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        ClassSetProvider::persistIn(null);
    }
}
