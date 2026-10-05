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

namespace PHPRegex\Tests\Unit\Internal;

use PHPRegex\Parser\Internal\LibraryPcre;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The library runs its own regexes under at least PHP's default PCRE
 * limits, 1 000 000 backtracks and 100 000 recursions, whatever the caller
 * set: a value above the floor is kept, one below it is raised for the
 * call only and set back after it. Nothing is written when both limits
 * already reach the floor, and a raise PHP refuses leaves the call under
 * the caller's limits, as before the floor existed.
 *
 * Engine facts (PHP 8.4.26, PCRE2 10.49, pcre.jit 0): "/abc/" on "abc"
 * fails with PREG_BACKTRACK_LIMIT_ERROR under a backtrack limit of 0 or 1;
 * "/(?:a|b)*c/" on "ab" x 10 . "c" fails under 19; a limit of -1 is
 * unlimited; "/(a)(b)c/" on "abc" fails with PREG_RECURSION_LIMIT_ERROR
 * under a recursion limit of 2.
 *
 * PHPUnit runs regexes of its own to report a failure: under a limit of 2
 * a failing assertSame() reports a pass. Every assertion here runs after
 * the default limits are back; only the work runs under the caller's.
 */
final class LibraryPcreTest extends TestCase
{
    private const PROBE = '/(?:a|b)*c/';

    /**
     * The probe off any JIT-compiled copy PHP may hold from an earlier test.
     */
    private const NO_JIT_PROBE = '/(*NO_JIT)(?:a|b)*c/';

    /**
     * @var array<string, string|false>
     */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['pcre.backtrack_limit', 'pcre.recursion_limit', 'pcre.jit'] as $key) {
            $this->saved[$key] = ini_get($key);
        }
        ini_set('pcre.jit', '0');
        self::restoreDefaults();
        self::resetSeam();
    }

    protected function tearDown(): void
    {
        self::resetSeam();
        foreach ($this->saved as $key => $value) {
            if (false !== $value) {
                ini_set($key, $value);
            }
        }
    }

    #[Test]
    public function test_the_floor_is_phps_default(): void
    {
        $this->assertSame(1_000_000, LibraryPcre::BACKTRACK_LIMIT_FLOOR);
        $this->assertSame(100_000, LibraryPcre::RECURSION_LIMIT_FLOOR);
    }

    #[Test]
    public function test_engine_fact_small_limits_fail_and_minus_one_is_unlimited(): void
    {
        // "(*NO_JIT)" keeps each probe off a JIT-compiled copy PHP may hold from
        // an earlier test: the limits do not bound a JIT match the same way.
        $outcome = static fn (\Closure $call): array => [$call(), preg_last_error()];

        $one = self::underLimits('1', '100000', static fn (): array => $outcome(static fn (): int|false => @preg_match('/(*NO_JIT)abc/', 'abc')));
        $zero = self::underLimits('0', '100000', static fn (): array => $outcome(static fn (): int|false => @preg_match('/(*NO_JIT)abc/', 'abc')));
        $nineteen = self::underLimits('19', '100000', static fn (): array => $outcome(static fn (): int|false => @preg_match('/(*NO_JIT)(?:a|b)*c/', str_repeat('ab', 10).'c')));
        $unlimited = self::underLimits('-1', '-1', static fn (): array => $outcome(static fn (): int|false => @preg_match('/(*NO_JIT)^(\w+\s?)*$/', str_repeat('a', 16).'!')));
        $recursion = self::underLimits('1000000', '2', static fn (): array => $outcome(static fn (): int|false => @preg_match('/(*NO_JIT)(a)(b)c/', 'abc')));

        $this->assertSame([false, \PREG_BACKTRACK_LIMIT_ERROR], $one);
        $this->assertSame([false, \PREG_BACKTRACK_LIMIT_ERROR], $zero);
        $this->assertSame([false, \PREG_BACKTRACK_LIMIT_ERROR], $nineteen);
        $this->assertSame([0, \PREG_NO_ERROR], $unlimited);
        $this->assertSame([false, \PREG_RECURSION_LIMIT_ERROR], $recursion);
    }

    /**
     * Inside the call both limits reach the floor; after it, the caller's
     * values are back as they were written.
     */
    #[Test]
    #[DataProvider('provideLimitsBelowTheFloor')]
    public function test_run_raises_a_limit_below_the_floor_for_the_call_only(string $backtrack, string $recursion): void
    {
        [$inside, $after] = self::underLimits($backtrack, $recursion, static fn (): array => [
            LibraryPcre::run(static fn (): array => [ini_get('pcre.backtrack_limit'), ini_get('pcre.recursion_limit')]),
            [ini_get('pcre.backtrack_limit'), ini_get('pcre.recursion_limit')],
        ]);

        $this->assertGreaterThanOrEqual(LibraryPcre::BACKTRACK_LIMIT_FLOOR, (int) $inside[0]);
        $this->assertGreaterThanOrEqual(LibraryPcre::RECURSION_LIMIT_FLOOR, (int) $inside[1]);
        $this->assertSame([$backtrack, $recursion], $after);
    }

    /**
     * @return iterable<string, array{backtrack: string, recursion: string}>
     */
    public static function provideLimitsBelowTheFloor(): iterable
    {
        yield 'zero' => ['backtrack' => '0', 'recursion' => '0'];
        yield 'one' => ['backtrack' => '1', 'recursion' => '1'];
        yield 'two' => ['backtrack' => '2', 'recursion' => '2'];
        yield 'nineteen' => ['backtrack' => '19', 'recursion' => '19'];
        yield 'one below the floor' => ['backtrack' => '999999', 'recursion' => '99999'];
        yield 'backtrack below, recursion above' => ['backtrack' => '19', 'recursion' => '500000'];
        yield 'backtrack above, recursion below' => ['backtrack' => '5000000', 'recursion' => '2'];
    }

    /**
     * A caller's value at or above the floor, or unlimited, stays as it
     * is, inside the call too, and nothing is written.
     */
    #[Test]
    #[DataProvider('provideLimitsAtOrAboveTheFloor')]
    public function test_no_ini_set_when_already_above_the_floor(string $backtrack, string $recursion): void
    {
        $writes = [];
        LibraryPcre::useIniSetter(static function (string $key, string $value) use (&$writes): string|false {
            $writes[] = $key.'='.$value;

            return ini_set($key, $value);
        });

        [$inside, $after] = self::underLimits($backtrack, $recursion, static fn (): array => [
            LibraryPcre::run(static fn (): array => [ini_get('pcre.backtrack_limit'), ini_get('pcre.recursion_limit')]),
            [ini_get('pcre.backtrack_limit'), ini_get('pcre.recursion_limit')],
        ]);

        $this->assertSame([], $writes);
        $this->assertSame([$backtrack, $recursion], $inside);
        $this->assertSame([$backtrack, $recursion], $after);
    }

    /**
     * @return iterable<string, array{backtrack: string, recursion: string}>
     */
    public static function provideLimitsAtOrAboveTheFloor(): iterable
    {
        yield 'exactly the floor' => ['backtrack' => '1000000', 'recursion' => '100000'];
        yield 'above the floor' => ['backtrack' => '5000000', 'recursion' => '500000'];
        yield 'unlimited' => ['backtrack' => '-1', 'recursion' => '-1'];
        // The largest value PHP keeps whole: one more wraps around to 0.
        yield 'largest 32-bit value' => ['backtrack' => '4294967295', 'recursion' => '4294967295'];
        // Read as PHP reads a quantity: "2M" is 2 097 152, not 2.
        yield 'written with a suffix' => ['backtrack' => '2M', 'recursion' => '1M'];
    }

    #[Test]
    public function test_only_the_limit_below_the_floor_is_written(): void
    {
        $keys = [];
        LibraryPcre::useIniSetter(static function (string $key, string $value) use (&$keys): string|false {
            $keys[] = $key;

            return ini_set($key, $value);
        });

        [$result, $after] = self::underLimits('5000000', '2', static fn (): array => [
            LibraryPcre::run(static fn (): int|false => preg_match('/(a)(b)c/', 'abc')),
            [ini_get('pcre.backtrack_limit'), ini_get('pcre.recursion_limit')],
        ]);

        $this->assertSame(1, $result);
        $this->assertSame(['pcre.recursion_limit'], array_values(array_unique($keys)));
        $this->assertSame(['5000000', '2'], $after);
    }

    /**
     * A raise PHP refuses (a disabled ini_set, a php_admin_value) leaves
     * the call under the caller's limits: the same result and the same
     * last error as the bare call, and no exception.
     */
    #[Test]
    public function test_a_refused_raise_leaves_the_call_under_the_callers_limits(): void
    {
        $subject = str_repeat('ab', 10).'c';
        $bare = self::underLimits('19', '100000', static fn (): array => [@preg_match(self::PROBE, $subject), preg_last_error()]);

        $attempts = 0;
        LibraryPcre::useIniSetter(static function () use (&$attempts): false {
            $attempts++;

            return false;
        });

        [$refused, $after] = self::underLimits('19', '100000', static fn (): array => [
            [LibraryPcre::match(self::PROBE, $subject), preg_last_error()],
            ini_get('pcre.backtrack_limit'),
        ]);

        $this->assertSame([false, \PREG_BACKTRACK_LIMIT_ERROR], $bare);
        $this->assertGreaterThan(0, $attempts, 'The floor did not even try to raise the limit.');
        $this->assertSame($bare, $refused);
        $this->assertSame('19', $after);
    }

    #[Test]
    public function test_the_limits_are_restored_when_the_call_throws(): void
    {
        [$message, $after] = self::underLimits('19', '2', static function (): array {
            $message = null;

            try {
                LibraryPcre::run(static function (): void {
                    throw new \LogicException('inside the window');
                });
            } catch (\LogicException $exception) {
                $message = $exception->getMessage();
            }

            return [$message, [ini_get('pcre.backtrack_limit'), ini_get('pcre.recursion_limit')]];
        });

        $this->assertSame('inside the window', $message);
        $this->assertSame(['19', '2'], $after);
    }

    #[Test]
    public function test_nested_runs_restore_the_callers_value_once_the_outer_one_ends(): void
    {
        [$inner, $outer, $after] = self::underLimits('19', '100000', static function (): array {
            [$inner, $outer] = LibraryPcre::run(static fn (): array => [
                LibraryPcre::run(static fn (): string|false => ini_get('pcre.backtrack_limit')),
                ini_get('pcre.backtrack_limit'),
            ]);

            return [$inner, $outer, ini_get('pcre.backtrack_limit')];
        });

        $this->assertGreaterThanOrEqual(LibraryPcre::BACKTRACK_LIMIT_FLOOR, (int) $inner);
        $this->assertGreaterThanOrEqual(LibraryPcre::BACKTRACK_LIMIT_FLOOR, (int) $outer);
        $this->assertSame('19', $after);
    }

    /**
     * Each wrapper answers under a limit of 19 what the bare function
     * answers under the default, with the same by-reference results.
     */
    #[Test]
    public function test_the_wrappers_answer_as_under_the_default_limits(): void
    {
        $subject = str_repeat('ab', 10).'c-'.str_repeat('ab', 10).'c';
        $length = static fn (array $match): string => \is_string($match[0] ?? null) ? (string) \strlen($match[0]) : '';

        $matched = preg_match(self::PROBE, $subject, $matches, \PREG_OFFSET_CAPTURE, 1);
        $matchedAll = preg_match_all(self::PROBE, $subject, $all);
        $replaced = preg_replace(self::PROBE, 'x', $subject, -1, $count);
        $replacedByCallback = preg_replace_callback(self::PROBE, $length, $subject, -1, $callbackCount);
        $split = preg_split(self::PROBE, $subject, -1, \PREG_SPLIT_NO_EMPTY);
        $expected = [
            'match' => [$matched, $matches],
            'matchAll' => [$matchedAll, $all],
            'replace' => [$replaced, $count],
            'replaceCallback' => [$replacedByCallback, $callbackCount],
            'split' => [$split],
        ];

        [$bare, $actual, $after] = self::underLimits('19', '100000', static fn (): array => [
            @preg_match(self::PROBE, $subject),
            [
                'match' => [LibraryPcre::match(self::PROBE, $subject, $matches, \PREG_OFFSET_CAPTURE, 1), $matches],
                'matchAll' => [LibraryPcre::matchAll(self::PROBE, $subject, $all), $all],
                'replace' => [LibraryPcre::replace(self::PROBE, 'x', $subject, -1, $count), $count],
                'replaceCallback' => [LibraryPcre::replaceCallback(self::PROBE, $length, $subject, -1, $callbackCount), $callbackCount],
                'split' => [LibraryPcre::split(self::PROBE, $subject, -1, \PREG_SPLIT_NO_EMPTY)],
            ],
            ini_get('pcre.backtrack_limit'),
        ]);

        $this->assertFalse($bare, 'Oracle: the probe no longer exhausts a limit of 19.');
        $this->assertSame($expected, $actual);
        $this->assertSame('19', $after);
    }

    /**
     * At or above the floor, each wrapper calls the bare function as it is:
     * nothing is written, and the answers and by-reference results agree.
     */
    #[Test]
    public function test_the_wrappers_run_as_they_are_at_the_floor(): void
    {
        $subject = 'ab-abc-aab';
        $length = static fn (array $match): string => \is_string($match[0] ?? null) ? (string) \strlen($match[0]) : '';
        $writes = [];
        LibraryPcre::useIniSetter(static function (string $key, string $value) use (&$writes): string|false {
            $writes[] = $key.'='.$value;

            return ini_set($key, $value);
        });

        $matched = preg_match('/a+b/', $subject, $matches, \PREG_OFFSET_CAPTURE, 1);
        $matchedAll = preg_match_all('/a+b/', $subject, $all);
        $replaced = preg_replace('/a+b/', 'x', $subject, -1, $count);
        $replacedByCallback = preg_replace_callback('/a+b/', $length, $subject, -1, $callbackCount);
        $split = preg_split('/-/', $subject, -1, \PREG_SPLIT_NO_EMPTY);
        $expected = [
            'match' => [$matched, $matches],
            'matchAll' => [$matchedAll, $all],
            'replace' => [$replaced, $count],
            'replaceCallback' => [$replacedByCallback, $callbackCount],
            'split' => [$split],
        ];

        $actual = self::underLimits('1000000', '100000', static fn (): array => [
            'match' => [LibraryPcre::match('/a+b/', $subject, $matches, \PREG_OFFSET_CAPTURE, 1), $matches],
            'matchAll' => [LibraryPcre::matchAll('/a+b/', $subject, $all), $all],
            'replace' => [LibraryPcre::replace('/a+b/', 'x', $subject, -1, $count), $count],
            'replaceCallback' => [LibraryPcre::replaceCallback('/a+b/', $length, $subject, -1, $callbackCount), $callbackCount],
            'split' => [LibraryPcre::split('/-/', $subject, -1, \PREG_SPLIT_NO_EMPTY)],
        ]);

        $this->assertSame([], $writes);
        $this->assertSame($expected, $actual);
        $this->assertSame('x-xc-x', $actual['replace'][0]);
        $this->assertSame('2-2c-3', $actual['replaceCallback'][0]);
        $this->assertSame(3, $actual['replaceCallback'][1]);
    }

    /**
     * Called without their optional arguments, the wrappers take PHP's
     * defaults: no limit on the replacements or the pieces, no flags; and
     * a callback runs once per match.
     */
    #[Test]
    public function test_the_wrappers_take_phps_defaults(): void
    {
        $calls = 0;
        $upper = static function (array $match) use (&$calls): string {
            $calls++;

            return \is_string($match[0] ?? null) ? strtoupper($match[0]) : '';
        };

        $actual = self::underLimits('1000000', '100000', static fn (): array => [
            LibraryPcre::replace('/a/', 'b', 'aaa'),
            LibraryPcre::replaceCallback('/a/', $upper, 'a-a-a'),
            LibraryPcre::split('/,/', 'a,,b'),
        ]);

        $this->assertSame([preg_replace('/a/', 'b', 'aaa'), preg_replace_callback('/a/', static fn (array $match): string => strtoupper($match[0]), 'a-a-a'), preg_split('/,/', 'a,,b')], $actual);
        $this->assertSame(['bbb', 'A-A-A', ['a', '', 'b']], $actual);
        $this->assertSame(3, $calls);
    }

    /**
     * A restore that throws (a setter an error handler turned into an
     * exception) still ends the call: the next call raises the caller's
     * limit again, rather than run as if it were inside a call. The
     * library's state is process-wide, so this runs in a process of its
     * own.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function test_a_restore_that_throws_still_ends_the_call(): void
    {
        $subject = str_repeat('ab', 10).'c';
        $bare = self::underLimits('19', '100000', static fn (): array => [@preg_match(self::NO_JIT_PROBE, $subject), preg_last_error()]);

        LibraryPcre::useIniSetter(static function (string $key, string $value): string|false {
            if ('19' === $value) {
                throw new \RuntimeException('restore refused');
            }

            return ini_set($key, $value);
        });

        $thrown = null;

        try {
            self::underLimits('19', '100000', static function () use (&$thrown): void {
                try {
                    LibraryPcre::run(static fn (): int|false => preg_match(self::NO_JIT_PROBE, 'c'));
                } catch (\RuntimeException $exception) {
                    $thrown = $exception->getMessage();
                }
            });
        } finally {
            LibraryPcre::useIniSetter(null);
        }

        $next = self::underLimits('19', '100000', static fn (): array => [LibraryPcre::match(self::NO_JIT_PROBE, $subject), preg_last_error()]);

        $this->assertSame([false, \PREG_BACKTRACK_LIMIT_ERROR], $bare, 'Oracle: the probe no longer exhausts a limit of 19.');
        $this->assertSame('restore refused', $thrown);
        $this->assertSame([1, \PREG_NO_ERROR], $next, 'The call after a restore that threw ran under the caller\'s limit.');
    }

    /**
     * Each limit goes back on its own: a restore that throws for one still
     * sets the other back, and the exception reaches the caller.
     */
    #[Test]
    public function test_a_restore_that_throws_for_one_limit_still_restores_the_other(): void
    {
        LibraryPcre::useIniSetter(static function (string $key, string $value): string|false {
            if ('pcre.backtrack_limit' === $key && '5' === $value) {
                throw new \RuntimeException('restore refused');
            }

            return ini_set($key, $value);
        });

        [$thrown, $recursion] = self::underLimits('5', '7', static function (): array {
            $thrown = null;

            try {
                LibraryPcre::run(static fn (): int|false => preg_match('/a/', 'a'));
            } catch (\RuntimeException $exception) {
                $thrown = $exception->getMessage();
            }

            return [$thrown, ini_get('pcre.recursion_limit')];
        });

        $this->assertSame('restore refused', $thrown);
        $this->assertSame('7', $recursion);
    }

    /**
     * Setting the limits back runs no regex: preg_last_error() after a
     * wrapper is the error of the library's own regex.
     */
    #[Test]
    public function test_the_last_error_after_a_wrapper_is_its_own_regex(): void
    {
        [$result, $error, $after] = self::underLimits('19', '100000', static fn (): array => [
            LibraryPcre::match('/./u', "\xFF"),
            preg_last_error(),
            ini_get('pcre.backtrack_limit'),
        ]);

        $this->assertFalse($result);
        $this->assertSame(\PREG_BAD_UTF8_ERROR, $error);
        $this->assertSame('19', $after);
    }

    /**
     * A malformed caller value is read as PHP reads it ("1\xB2" is 1, with a
     * warning when it is set), so it is raised for the call; setting it back
     * must raise no warning of its own and leave the exact string the caller
     * wrote. Oracle (PHP 8.4.26): ini_set() of "1\xB2", "12x" or "abc" warns
     * 'Invalid "pcre.backtrack_limit" setting' and ini_get() returns the
     * string as written; "1k" (1 024) is well formed and warns nothing.
     * Each row asserts afterwards, under the default limits.
     */
    #[Test]
    #[DataProvider('provideMalformedLimits')]
    public function test_a_malformed_callers_value_is_restored_without_a_warning(string $key, string $value): void
    {
        [$caller, $inside, $warnings, $after] = self::underLimits('1000000', '100000', static function () use ($key, $value): array {
            set_error_handler(static fn (): bool => true);

            try {
                ini_set($key, $value);
            } finally {
                restore_error_handler();
            }
            $caller = ini_get($key);

            // A warning silenced with @ reaches no caller: PHPUnit's handler
            // and the frameworks' ones skip it, so only the others count.
            $warnings = [];
            set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
                if (0 !== (error_reporting() & $level)) {
                    $warnings[] = $message;
                }

                return true;
            });

            // Nor does one that falls through to PHP's own handler, which
            // still records it as the last error.
            error_clear_last();

            try {
                $inside = LibraryPcre::run(static fn (): string|false => ini_get($key));
            } finally {
                restore_error_handler();
            }
            $last = error_get_last();

            return [$caller, $inside, [...$warnings, ...(null === $last ? [] : [$last['message']])], ini_get($key)];
        });

        $floor = 'pcre.backtrack_limit' === $key ? LibraryPcre::BACKTRACK_LIMIT_FLOOR : LibraryPcre::RECURSION_LIMIT_FLOOR;
        $this->assertSame($value, $caller, 'Oracle: ini_get() no longer returns the value as written.');
        $this->assertGreaterThanOrEqual($floor, (int) $inside);
        $this->assertSame([], $warnings);
        $this->assertSame($caller, $after);
    }

    /**
     * @return iterable<string, array{key: string, value: string}>
     */
    public static function provideMalformedLimits(): iterable
    {
        yield 'backtrack limit with a high byte' => ['key' => 'pcre.backtrack_limit', 'value' => "1\xB2"];
        yield 'backtrack limit with an unknown multiplier' => ['key' => 'pcre.backtrack_limit', 'value' => '12x'];
        yield 'backtrack limit without digits' => ['key' => 'pcre.backtrack_limit', 'value' => 'abc'];
        yield 'recursion limit with a high byte' => ['key' => 'pcre.recursion_limit', 'value' => "1\xB2"];
        // Well formed, below the floor, no warning either way: kept as a guard.
        yield 'backtrack limit with a multiplier' => ['key' => 'pcre.backtrack_limit', 'value' => '1k'];
    }

    /**
     * A raise that throws (a setter an error handler turned into an
     * exception) is a call that never started: the next call still runs
     * under the floor, its result the one of the default limits. The
     * library's state is process-wide: when that call is wrongly counted,
     * every later test sees the floor gone, so this one runs in a process
     * of its own.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function test_a_raise_that_throws_leaves_the_floor_in_place_for_the_next_call(): void
    {
        $subject = str_repeat('ab', 10).'c';
        $bare = self::underLimits('19', '100000', static fn (): array => [@preg_match(self::NO_JIT_PROBE, $subject), preg_last_error()]);

        LibraryPcre::useIniSetter(static function (): never {
            throw new \RuntimeException('raise refused');
        });

        try {
            self::underLimits('19', '100000', static function (): void {
                try {
                    LibraryPcre::run(static fn (): int|false => preg_match(self::NO_JIT_PROBE, 'c'));
                } catch (\RuntimeException) {
                    // Whether the setter's exception reaches the caller is not the point.
                }
            });
        } finally {
            LibraryPcre::useIniSetter(null);
        }

        [$next, $after] = self::underLimits('19', '100000', static fn (): array => [
            [LibraryPcre::match(self::NO_JIT_PROBE, $subject), preg_last_error()],
            ini_get('pcre.backtrack_limit'),
        ]);

        $this->assertSame([false, \PREG_BACKTRACK_LIMIT_ERROR], $bare, 'Oracle: the probe no longer exhausts a limit of 19.');
        $this->assertSame([1, \PREG_NO_ERROR], $next, 'The call after a raise that threw ran under the caller\'s limit.');
        $this->assertSame('19', $after);
    }

    /**
     * PHP keeps the low 32 bits of a limit: ini_get() gives the string as
     * written, the engine reads "4294967296" as 0 and "4294967315" as 19.
     * Such a value is below the floor, and raised for the call.
     *
     * Oracle (PHP 8.4.26, PCRE2 10.49, JIT off): "/abc/" on "abc" fails
     * with PREG_BACKTRACK_LIMIT_ERROR under "4294967296" and "4294967297",
     * the probe under "4294967315"; "/(a)(b)c/" fails with
     * PREG_RECURSION_LIMIT_ERROR under a recursion limit of "4294967296";
     * under "4294967295" every one of them passes.
     */
    #[Test]
    #[DataProvider('provideLimitsPastThirtyTwoBits')]
    public function test_a_limit_past_32_bits_is_read_as_php_wraps_it(string $backtrack, string $recursion, string $pattern, string $subject, int $error): void
    {
        $bare = self::underLimits($backtrack, $recursion, static fn (): array => [@preg_match($pattern, $subject), preg_last_error()]);

        [$floored, $after] = self::underLimits($backtrack, $recursion, static fn (): array => [
            [LibraryPcre::match($pattern, $subject), preg_last_error()],
            [ini_get('pcre.backtrack_limit'), ini_get('pcre.recursion_limit')],
        ]);

        $this->assertSame([false, $error], $bare, 'Oracle: PHP no longer wraps the limit to 32 bits.');
        $this->assertSame([1, \PREG_NO_ERROR], $floored, 'A limit PHP wraps below the floor was kept for the call.');
        $this->assertSame([$backtrack, $recursion], $after);
    }

    /**
     * @return iterable<string, array{backtrack: string, recursion: string, pattern: string, subject: string, error: int}>
     */
    public static function provideLimitsPastThirtyTwoBits(): iterable
    {
        yield 'backtrack limit wrapping to 0' => ['backtrack' => '4294967296', 'recursion' => '100000', 'pattern' => '/(*NO_JIT)abc/', 'subject' => 'abc', 'error' => \PREG_BACKTRACK_LIMIT_ERROR];
        yield 'backtrack limit wrapping to 1' => ['backtrack' => '4294967297', 'recursion' => '100000', 'pattern' => '/(*NO_JIT)abc/', 'subject' => 'abc', 'error' => \PREG_BACKTRACK_LIMIT_ERROR];
        yield 'backtrack limit wrapping to 19' => ['backtrack' => '4294967315', 'recursion' => '100000', 'pattern' => self::NO_JIT_PROBE, 'subject' => str_repeat('ab', 10).'c', 'error' => \PREG_BACKTRACK_LIMIT_ERROR];
        yield 'backtrack limit wrapping to 0 twice over' => ['backtrack' => '8589934592', 'recursion' => '100000', 'pattern' => '/(*NO_JIT)abc/', 'subject' => 'abc', 'error' => \PREG_BACKTRACK_LIMIT_ERROR];
        yield 'recursion limit wrapping to 0' => ['backtrack' => '1000000', 'recursion' => '4294967296', 'pattern' => '/(*NO_JIT)(a)(b)c/', 'subject' => 'abc', 'error' => \PREG_RECURSION_LIMIT_ERROR];
    }

    /**
     * Where ini_get() is disabled (disable_functions) the limits cannot be
     * read: the library's regexes run as they are, under whatever limits
     * hold, and a parse answers as anywhere else. A disabled function is
     * set per process, so the parse runs in a PHP process of its own.
     */
    #[Test]
    public function test_a_disabled_ini_get_leaves_the_calls_as_they_are(): void
    {
        $code = 'require '.var_export(\dirname(__DIR__, 3).'/vendor/autoload.php', true).';'
            .'echo function_exists("ini_get") ? "ini_get enabled" : "ini_get disabled", "\n";'
            .'$ast = \PHPRegex\Toolkit\Regex::create(["cache" => null])->parse("/a+(b|c)\\\\d/");'
            .'echo $ast->accept(new \PHPRegex\Parser\Printer\PatternPrinter()), "\n";'
            .'echo \PHPRegex\Parser\Internal\LibraryPcre::match("/b+/", "abbc"), "\n";';

        $process = proc_open(
            [\PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'disable_functions=ini_get', '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame("ini_get disabled\n/a+(b|c)\\d/\n1\n", $output, $errors);
        $this->assertSame(0, $exitCode, $errors);
    }

    /**
     * Runs the work under the caller's limits and sets the defaults back
     * before any assertion.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private static function underLimits(string $backtrack, string $recursion, \Closure $work): mixed
    {
        ini_set('pcre.backtrack_limit', $backtrack);
        ini_set('pcre.recursion_limit', $recursion);

        try {
            return $work();
        } finally {
            self::restoreDefaults();
        }
    }

    private static function restoreDefaults(): void
    {
        ini_set('pcre.backtrack_limit', '1000000');
        ini_set('pcre.recursion_limit', '100000');
    }

    /**
     * The seam is static: a setter one test installs must never reach the
     * next one, whatever the order.
     */
    private static function resetSeam(): void
    {
        LibraryPcre::useIniSetter(null);
    }
}
