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

use PHPRegex\Parser\Text\Path;

/**
 * What the suite needs of the host beyond what Composer checks, said once
 * when the suite starts: a host that lacks it gets the reason before the
 * failures it causes.
 */
final class HostRequirements
{
    /**
     * What this host lacks, a sentence each.
     *
     * Outside Windows the AST file cache and the class-set store check who
     * owns their directory with posix_geteuid(), and keep nothing where they
     * cannot. That behaviour has tests of its own, run in a process that
     * disables the function; the tests of what the two keep fail without it.
     *
     * @param bool $windows    the host is Windows, where no owner is checked
     * @param bool $ownerCheck posix_geteuid() can be called
     *
     * @return list<string>
     */
    public static function missing(bool $windows, bool $ownerCheck): array
    {
        if ($windows || $ownerCheck) {
            return [];
        }

        return ['posix_geteuid() is missing or disabled: the AST file cache and the class-set store keep nothing where they cannot check who owns their directory, and the tests of what they keep fail on this host.'];
    }

    /**
     * What the host running the suite lacks.
     *
     * @return list<string>
     */
    public static function ofThisHost(): array
    {
        return self::missing(Path::onWindows(), \function_exists('posix_geteuid'));
    }

    /**
     * The lines the suite writes to the standard error when it starts, none
     * when nothing is missing.
     *
     * @param list<string> $missing
     */
    public static function notice(array $missing): string
    {
        if ([] === $missing) {
            return '';
        }

        return "The test suite needs more than this host has:\n - ".implode("\n - ", $missing)."\n";
    }
}
