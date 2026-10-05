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

namespace PHPRegex\Parser\Internal;

/**
 * Runs the library's own regexes under at least PHP's default PCRE limits,
 * 1 000 000 backtracks and 100 000 recursions, whatever the caller set.
 *
 * A limit below its floor is raised for the call only and set back after
 * it, also when the call throws. A limit at or above its floor is kept and
 * nothing is written; a limit is read as the engine gets it, its low 32
 * bits, unsigned, so -1 is unlimited and "4294967296" is 0. A raise PHP
 * refuses (a disabled ini_set, a php_admin_value), or limits it cannot read
 * (a disabled ini_get), leave the call under the caller's limits. Setting
 * a limit back runs no regex, so preg_last_error() after a wrapper is the
 * error of the wrapped call.
 *
 * Only regexes the library owns go through here: a user's pattern keeps
 * the caller's limits.
 *
 * @internal
 */
final class LibraryPcre
{
    public const BACKTRACK_LIMIT_FLOOR = 1_000_000;

    public const RECURSION_LIMIT_FLOOR = 100_000;

    private const FLOORS = [
        'pcre.backtrack_limit' => self::BACKTRACK_LIMIT_FLOOR,
        'pcre.recursion_limit' => self::RECURSION_LIMIT_FLOOR,
    ];

    /**
     * @var (\Closure(string, string): (string|false))|null
     */
    private static ?\Closure $iniSetter = null;

    private static int $lastError = \PREG_NO_ERROR;

    /**
     * How many calls of run() are under way: a window, in which no user
     * code runs, so the limits stay where it put them.
     */
    private static int $depth = 0;

    /**
     * Replaces ini_set for the raise and the restore; null brings the
     * native function back.
     *
     * @param (\Closure(string, string): (string|false))|null $setter
     */
    public static function useIniSetter(?\Closure $setter): void
    {
        self::$iniSetter = $setter;
    }

    /**
     * The error of the last regex run through here, as preg_last_error()
     * gave it right after that regex.
     */
    public static function lastError(): int
    {
        return self::$lastError;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     */
    public static function run(\Closure $call): mixed
    {
        $raised = [];
        // Counted before the raise, and uncounted in the same finally, so a
        // raise or a restore that throws leaves the count where it was.
        self::$depth++;

        try {
            // Inside a window the limits are already where they can be put.
            foreach (1 === self::$depth ? self::FLOORS : [] as $key => $floor) {
                $current = self::read($key);
                if (false === $current || !self::isBelow($current, $floor)) {
                    continue;
                }

                if (self::set($key, (string) $floor)) {
                    $raised[$key] = $current;
                }
            }

            return $call();
        } finally {
            self::$lastError = preg_last_error();

            try {
                // The caller's own value goes back as written: PHP already
                // warned when it was set, if it reads it loosely.
                self::restore($raised);
            } finally {
                self::$depth--;
            }
        }
    }

    /**
     * @param int-mask<\PREG_OFFSET_CAPTURE, \PREG_UNMATCHED_AS_NULL> $flags
     *
     * @param-out (
     *     $flags is 256
     *     ? array<array-key, array{string, 0|positive-int}|array{'', -1}>
     *     : ($flags is 512
     *         ? array<array-key, string|null>
     *         : ($flags is 768
     *             ? array<array-key, array{string, 0|positive-int}|array{null, -1}>
     *             : array<array-key, string>
     *         )
     *     )
     * ) $matches
     *
     * @return 0|1|false
     */
    public static function match(string $pattern, string $subject, mixed &$matches = null, int $flags = 0, int $offset = 0): int|false
    {
        if (self::atFloor()) {
            $result = preg_match($pattern, $subject, $matches, $flags, $offset);
            self::$lastError = preg_last_error();

            return $result;
        }

        [$result, $matches] = self::run(static function () use ($pattern, $subject, $flags, $offset): array {
            $result = preg_match($pattern, $subject, $found, $flags, $offset);

            return [$result, $found];
        });

        return $result;
    }

    /**
     * @param int-mask<\PREG_PATTERN_ORDER, \PREG_SET_ORDER, \PREG_OFFSET_CAPTURE, \PREG_UNMATCHED_AS_NULL> $flags
     *
     * @param-out (
     *     $flags is 0|1
     *     ? array<list<string>>
     *     : ($flags is 2
     *         ? list<array<string>>
     *         : ($flags is 256|257
     *             ? array<list<array{string, int}>>
     *             : ($flags is 258
     *                 ? list<array<array{string, int}>>
     *                 : ($flags is 512|513
     *                     ? array<list<?string>>
     *                     : ($flags is 514
     *                         ? list<array<?string>>
     *                         : ($flags is 770 ? list<array<array{?string, int}>> : array<mixed>)
     *                     )
     *                 )
     *             )
     *         )
     *     )
     * ) $matches
     *
     * @return 0|positive-int|false
     */
    public static function matchAll(string $pattern, string $subject, mixed &$matches = null, int $flags = 0, int $offset = 0): int|false
    {
        if (self::atFloor()) {
            $result = preg_match_all($pattern, $subject, $matches, $flags, $offset);
            self::$lastError = preg_last_error();

            return $result;
        }

        [$result, $matches] = self::run(static function () use ($pattern, $subject, $flags, $offset): array {
            $result = preg_match_all($pattern, $subject, $found, $flags, $offset);

            return [$result, $found];
        });

        return $result;
    }

    /**
     * @param string|array<string>           $pattern
     * @param string|array<string|int|float> $replacement
     * @param string|array<string|int|float> $subject
     *
     * @param-out 0|positive-int $count
     *
     * @return ($subject is array ? array<string>|null : string|null)
     */
    public static function replace(string|array $pattern, string|array $replacement, string|array $subject, int $limit = -1, mixed &$count = null): string|array|null
    {
        if (self::atFloor()) {
            $result = preg_replace($pattern, $replacement, $subject, $limit, $count);
            self::$lastError = preg_last_error();

            return $result;
        }

        [$result, $count] = self::run(static function () use ($pattern, $replacement, $subject, $limit): array {
            $result = preg_replace($pattern, $replacement, $subject, $limit, $found);

            return [$result, $found];
        });

        return $result;
    }

    /**
     * Only for a callback the library owns: no user code runs while the
     * limits are raised.
     *
     * @param string|array<string>                                    $pattern
     * @param callable(array<array-key, string>): string              $callback
     * @param string|array<string|int|float>                          $subject
     * @param int-mask<\PREG_OFFSET_CAPTURE, \PREG_UNMATCHED_AS_NULL> $flags
     *
     * @param-out 0|positive-int $count
     *
     * @return ($subject is array ? array<string>|null : string|null)
     */
    public static function replaceCallback(string|array $pattern, callable $callback, string|array $subject, int $limit = -1, mixed &$count = null, int $flags = 0): string|array|null
    {
        if (self::atFloor()) {
            $result = preg_replace_callback($pattern, $callback, $subject, $limit, $count, $flags);
            self::$lastError = preg_last_error();

            return $result;
        }

        [$result, $count] = self::run(static function () use ($pattern, $callback, $subject, $limit, $flags): array {
            $result = preg_replace_callback($pattern, $callback, $subject, $limit, $found, $flags);

            return [$result, $found];
        });

        return $result;
    }

    /**
     * @param int-mask<\PREG_SPLIT_NO_EMPTY, \PREG_SPLIT_DELIM_CAPTURE, \PREG_SPLIT_OFFSET_CAPTURE> $flags
     *
     * @return ($flags is 0|1|2|3 ? list<string>|false : list<array{string, int<0, max>}>|false)
     */
    public static function split(string $pattern, string $subject, int $limit = -1, int $flags = 0): array|false
    {
        if (self::atFloor()) {
            $result = preg_split($pattern, $subject, $limit, $flags);
            self::$lastError = preg_last_error();

            return $result;
        }

        return self::run(static fn (): array|false => preg_split($pattern, $subject, $limit, $flags));
    }

    /**
     * Sets each value back on its own: one that throws does not keep the
     * ones after it from being set back. The last exception goes on, with
     * the ones before it chained as its previous.
     *
     * @param array<string, string> $values
     */
    private static function restore(array $values): void
    {
        if ([] === $values) {
            return;
        }

        $key = array_key_first($values);
        $value = $values[$key];
        unset($values[$key]);

        try {
            self::quietly(static fn (): bool => self::set($key, $value));
        } finally {
            self::restore($values);
        }
    }

    /**
     * Whether a call runs as it is, without a closure around it: inside a
     * window, or with both limits already at their floor or above, or
     * unreadable. The limits are read on every call outside a window, since
     * the caller may change one between two.
     */
    private static function atFloor(): bool
    {
        if (self::$depth > 0) {
            return true;
        }

        $backtrack = self::read('pcre.backtrack_limit');
        $recursion = self::read('pcre.recursion_limit');

        return (false === $backtrack || !self::isBelow($backtrack, self::BACKTRACK_LIMIT_FLOOR))
            && (false === $recursion || !self::isBelow($recursion, self::RECURSION_LIMIT_FLOOR));
    }

    /**
     * The value read the way PHP reads an integer setting ("1M" is
     * 1 048 576), then cut to its low 32 bits, unsigned, as PHP hands it to
     * the engine: "4294967296" is 0, -1 is 4294967295. A plain number, the
     * common case, is read without the parser.
     */
    private static function isBelow(string $value, int $floor): bool
    {
        $limit = (string) (int) $value === $value ? (int) $value : self::quietly(static fn (): int => ini_parse_quantity($value));

        return ($limit & 0xFFFFFFFF) < $floor;
    }

    /**
     * The setting as ini_get() gives it; false when it cannot be read, as
     * where ini_get() is disabled.
     */
    private static function read(string $key): string|false
    {
        return \function_exists('ini_get') ? ini_get($key) : false;
    }

    /**
     * Runs the call without its warnings reaching any error handler: they
     * repeat the one PHP gave when the caller set a value it reads loosely.
     *
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     */
    private static function quietly(\Closure $call): mixed
    {
        set_error_handler(static fn (): bool => true, \E_WARNING);

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    private static function set(string $key, string $value): bool
    {
        if (null !== self::$iniSetter) {
            return false !== (self::$iniSetter)($key, $value);
        }

        return \function_exists('ini_set') && false !== ini_set($key, $value);
    }
}
