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

use PHPUnit\Event\Test\PhpDeprecationTriggered;
use PHPUnit\Event\Test\PhpDeprecationTriggeredSubscriber;

/**
 * The engine half of the deprecation policy: E_DEPRECATED raised by the
 * PHP runtime itself, its frame under the guarded source.
 */
final class EngineDeprecationSubscriber implements PhpDeprecationTriggeredSubscriber
{
    public function __construct(private readonly string $sourceDirectory) {}

    public function notify(PhpDeprecationTriggered $event): void
    {
        if (!SourceDeprecationListener::isOwnSource($event->file(), $this->sourceDirectory)) {
            return;
        }

        throw new \RuntimeException(\sprintf(
            'This library deprecated itself: "%s" at %s:%d. A deprecation under src/ fails the suite.',
            $event->message(),
            $event->file(),
            $event->line(),
        ));
    }
}
