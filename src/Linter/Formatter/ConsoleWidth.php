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

namespace PHPRegex\Linter\Formatter;

use PHPRegex\Parser\Engine\LibraryPcre;

/**
 * The width a console report folds its lines at: COLUMNS when the shell
 * exports it, the terminal's width on a TTY, 120 otherwise, never above
 * 120 nor below 40. A pipe, a file and a sweep of a corpus all fold at
 * 120, so their text is the same on every machine.
 *
 * @internal
 */
final class ConsoleWidth
{
    public const DEFAULT = 120;

    public const FLOOR = 40;

    public static function detect(): int
    {
        return self::resolve(getenv('COLUMNS'), self::stdoutIsTerminal() ? self::fromTerminal() : null);
    }

    /**
     * The width from what the shell says, else what the terminal says,
     * else the default.
     */
    public static function resolve(string|false $columns, ?int $terminal): int
    {
        return self::fromColumns($columns) ?? $terminal ?? self::DEFAULT;
    }

    /**
     * A COLUMNS value read as a width: a whole number above zero, clamped;
     * anything else is no width.
     */
    public static function fromColumns(string|false $columns): ?int
    {
        // Digits only, read without ctype: the C locale is not a given.
        if (false === $columns || \strlen($columns) !== strspn($columns, '0123456789')) {
            return null;
        }

        $width = (int) $columns;

        return $width > 0 ? self::clamp($width) : null;
    }

    public static function clamp(int $width): int
    {
        return max(self::FLOOR, min($width, self::DEFAULT));
    }

    /**
     * The columns `stty size` reports for the terminal, read from
     * /dev/tty so a redirected stdin does not matter, its errors
     * discarded; null when there is no stty, no terminal or no proc_open.
     * $tty names the terminal to ask and $command what to ask it, for a
     * run that asks something else.
     *
     * @param list<string> $command
     */
    public static function fromTerminal(string $tty = '/dev/tty', array $command = ['stty', 'size']): ?int
    {
        if (!\function_exists('proc_open')) {
            return null; // a build without proc_open: not reproducible under the suite, which runs through it
        }

        $process = @proc_open(
            $command,
            [0 => ['file', $tty, 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        if (!\is_resource($process)) {
            return null;
        }

        $size = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        return self::fromSttySize($size);
    }

    /**
     * The width in what `stty size` prints, "rows columns", clamped; null
     * for anything else.
     */
    public static function fromSttySize(string $size): ?int
    {
        if (!LibraryPcre::match('/^\d+ (\d+)\s*$/', $size, $matches)) {
            return null;
        }

        return self::fromColumns($matches[1]);
    }

    /**
     * Whether stdout is a terminal to ask: never under a test suite, which
     * writes to a pipe, so the probe is spared there.
     */
    private static function stdoutIsTerminal(): bool
    {
        return \defined('STDOUT') && \function_exists('posix_isatty') && @posix_isatty(\STDOUT);
    }
}
