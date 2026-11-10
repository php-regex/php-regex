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

namespace PHPRegex\Cli;

/**
 * Stands in for error_get_last() where Application::reportFatalError() reads
 * the error, on a host so loaded that the shutdown of a run takes longer
 * than its time limit: json_fatal_run.php loads it for its "timeout" mode,
 * in a process of its own.
 *
 * @return array{type: int, message: string, file: string, line: int}|null
 */
function error_get_last(): ?array
{
    // Busy, not asleep: on Linux and macOS the time limit counts the time
    // the process computes. PHP lets the shutdown of a run it stopped at a
    // limit of one second compute for two more.
    $until = hrtime(true) + 2_500_000_000;
    while (hrtime(true) < $until) {
        hash('crc32b', 'x');
    }

    return \error_get_last();
}
