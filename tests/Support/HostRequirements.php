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
     * The tests of the CI workflows and of bin/ci-answer stand in for gh and
     * filter its answers through jq, as `gh --jq` does: the runners of
     * GitHub and Homebrew hosts have it, a minimal Linux may not.
     *
     * @param bool $windows    the host is Windows, where neither is used
     * @param bool $ownerCheck posix_geteuid() can be called
     * @param bool $jq         jq is on the PATH
     *
     * @return list<string>
     */
    public static function missing(bool $windows, bool $ownerCheck, bool $jq): array
    {
        if ($windows) {
            return [];
        }

        $missing = [];
        if (!$ownerCheck) {
            $missing[] = 'posix_geteuid() is missing or disabled: the AST file cache and the class-set store keep nothing where they cannot check who owns their directory, and the tests of what they keep fail on this host.';
        }
        if (!$jq) {
            $missing[] = 'jq is not on the PATH: the tests of the CI workflows and of bin/ci-answer stand in for gh and filter its answers through jq, as gh --jq does, and fail on this host.';
        }

        return $missing;
    }

    /**
     * Whether a program of that name can be run from one of the directories
     * of the path.
     */
    public static function onPath(string $program, string $path): bool
    {
        foreach (explode(\PATH_SEPARATOR, $path) as $directory) {
            if ('' !== $directory && is_file($directory.'/'.$program) && is_executable($directory.'/'.$program)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the host running the suite lacks.
     *
     * @return list<string>
     */
    public static function ofThisHost(): array
    {
        return self::missing(Path::onWindows(), \function_exists('posix_geteuid'), self::onPath('jq', (string) getenv('PATH')));
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
