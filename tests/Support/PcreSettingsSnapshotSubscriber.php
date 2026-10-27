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

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;

/**
 * Before a test's setUp(), the PCRE settings it finds.
 */
final class PcreSettingsSnapshotSubscriber implements PreparationStartedSubscriber
{
    public function __construct(private readonly PcreSettingsGuard $guard) {}

    public function notify(PreparationStarted $event): void
    {
        $this->guard->snapshot();
    }
}
