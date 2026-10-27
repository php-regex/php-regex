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

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

/**
 * After a test's tearDown(), the PCRE settings put back as it found them;
 * the run fails naming the test when it left one changed. PHPUnit reports
 * the exception of a subscriber as a test runner warning, which exits the
 * run non-zero.
 */
final class PcreSettingsCheckSubscriber implements FinishedSubscriber
{
    public function __construct(private readonly PcreSettingsGuard $guard) {}

    public function notify(Finished $event): void
    {
        $changed = $this->guard->restore();
        if ([] === $changed) {
            return;
        }

        $rows = [];
        foreach ($changed as $setting => [$found, $left]) {
            $rows[] = \sprintf('%s: found %s, left %s', $setting, $found, $left);
        }

        throw new \RuntimeException(\sprintf(
            '%s left the PCRE settings changed for the next test (%s). Put them back in a finally block or in tearDown().',
            $event->test()->id(),
            implode('; ', $rows),
        ));
    }
}
