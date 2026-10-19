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

namespace PHPRegex\Tests\Unit\Parser\Engine;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A parse and a validation run the library's regexes in one window of
 * raised limits each, read once; a pattern the library was given never runs
 * in one, but under the caller's limits.
 *
 * Engine fact (PHP 8.4.26, PCRE2 10.49, the interpreter): "/(?:a|b)*c/" on
 * "ab" x 10 . "c" fails with PREG_BACKTRACK_LIMIT_ERROR under a backtrack
 * limit of 19 and matches under PHP's default.
 *
 * PHPUnit runs regexes of its own to report a failure: every assertion runs
 * after the default limits are back.
 */
final class LibraryPcreWindowTest extends TestCase
{
    private const PROBE = '/(*NO_JIT)(?:a|b)*c/';

    private const SUBJECT = 'ababababababababababc';

    protected function tearDown(): void
    {
        LibraryPcre::useIniSetter(null);
        ini_set('pcre.backtrack_limit', '1000000');
        ini_set('pcre.recursion_limit', '100000');
    }

    #[Test]
    public function test_a_users_pattern_runs_under_the_callers_limits_inside_a_window(): void
    {
        ini_set('pcre.backtrack_limit', '19');

        try {
            [$verdict, $error, $inside] = LibraryPcre::run(static function (): array {
                $match = (new PcreEngine())->match(self::PROBE, self::SUBJECT);

                return [$match->matched, $match->errorCode, ini_get('pcre.backtrack_limit')];
            });
            $after = ini_get('pcre.backtrack_limit');
        } finally {
            ini_set('pcre.backtrack_limit', '1000000');
        }

        $this->assertNull($verdict);
        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, $error);
        $this->assertSame((string) LibraryPcre::BACKTRACK_LIMIT_FLOOR, $inside, 'The window is raised again once the pattern ran.');
        $this->assertSame('19', $after);
    }

    #[Test]
    public function test_the_librarys_own_regexes_keep_the_floor_while_a_users_pattern_runs(): void
    {
        ini_set('pcre.backtrack_limit', '19');

        try {
            [$caller, $result] = LibraryPcre::run(static function (): array {
                $left = LibraryPcre::leaveTheWindow();

                try {
                    return [ini_get('pcre.backtrack_limit'), LibraryPcre::match(self::PROBE, self::SUBJECT)];
                } finally {
                    LibraryPcre::reenterTheWindow($left);
                }
            });
        } finally {
            ini_set('pcre.backtrack_limit', '1000000');
        }

        $this->assertSame('19', $caller);
        $this->assertSame(1, $result);
    }

    #[Test]
    public function test_outside_any_window_leaving_it_changes_nothing(): void
    {
        $writes = [];
        LibraryPcre::useIniSetter(static function (string $key, string $value) use (&$writes): string|false {
            $writes[] = $key;

            return ini_set($key, $value);
        });
        ini_set('pcre.backtrack_limit', '19');

        try {
            $left = LibraryPcre::leaveTheWindow();
            $limit = ini_get('pcre.backtrack_limit');
            LibraryPcre::reenterTheWindow($left);
            $after = ini_get('pcre.backtrack_limit');
        } finally {
            ini_set('pcre.backtrack_limit', '1000000');
        }

        $this->assertSame('19', $limit);
        $this->assertSame('19', $after);
        $this->assertSame([], $writes);
    }

    #[Test]
    public function test_a_call_that_throws_leaves_the_window_raised_again(): void
    {
        ini_set('pcre.backtrack_limit', '19');

        try {
            $inside = LibraryPcre::run(static function (): string|false {
                $left = LibraryPcre::leaveTheWindow();

                try {
                    throw new \RuntimeException('user code failed');
                } catch (\RuntimeException) {
                } finally {
                    LibraryPcre::reenterTheWindow($left);
                }

                return ini_get('pcre.backtrack_limit');
            });
            $after = ini_get('pcre.backtrack_limit');
        } finally {
            ini_set('pcre.backtrack_limit', '1000000');
        }

        $this->assertSame((string) LibraryPcre::BACKTRACK_LIMIT_FLOOR, $inside);
        $this->assertSame('19', $after);
    }

    /**
     * Under a caller's tiny limit, the library still reads and judges a
     * pattern: its regexes run in the windows, the pattern itself nowhere.
     */
    #[Test]
    public function test_a_pattern_is_read_and_judged_under_a_callers_tiny_limit(): void
    {
        $parser = RegexParser::create(['cache' => null]);
        ini_set('pcre.backtrack_limit', '1');
        ini_set('pcre.recursion_limit', '1');

        try {
            $tokens = \count($parser->tokenize('/(?<year>\d{4})-\p{Lu}+/u'));
            $result = $parser->validate('/(?<year>\d{4})-\p{Lu}+/u');
            $refused = $parser->validate('/(?<year>\d{4})-\p{Nope}+/u');
            $limits = [ini_get('pcre.backtrack_limit'), ini_get('pcre.recursion_limit')];
        } finally {
            ini_set('pcre.backtrack_limit', '1000000');
            ini_set('pcre.recursion_limit', '100000');
        }

        $this->assertSame(14, $tokens);
        $this->assertTrue($result->isValid, (string) $result->error);
        $this->assertFalse($refused->isValid);
        $this->assertSame(['1', '1'], $limits);
    }

    /**
     * ini_get is counted through a function of the engine's namespace,
     * defined before the library first calls it, so in a process of its own.
     */
    #[Test]
    public function test_a_validation_reads_the_limits_once_for_the_parse_and_once_for_the_walk(): void
    {
        $code = 'namespace PHPRegex\Parser\Engine { function ini_get(string $key) { $GLOBALS["reads"] = ($GLOBALS["reads"] ?? 0) + 1; return \ini_get($key); } }'
            .'namespace { require '.var_export(\dirname(__DIR__, 4).'/vendor/autoload.php', true).';'
            .'$parser = \PHPRegex\Parser\RegexParser::create(["cache" => null]);'
            .'$parser->validate("/warm-up(?<n>a)\\\\d{2}/");'
            .'$GLOBALS["reads"] = 0;'
            .'$parser->validate("/(?<year>\\\\d{4})-(?<month>\\\\d{2})-(?<day>\\\\d{2})/");'
            .'echo $GLOBALS["reads"], "\n";'
            .'$GLOBALS["reads"] = 0;'
            .'$parser->parse("/(?<year>\\\\d{4})-(?<month>\\\\d{2})-(?<day>\\\\d{2})/");'
            .'echo $GLOBALS["reads"], "\n"; }';

        $process = proc_open(
            [\PHP_BINARY, '-d', 'xdebug.mode=off', '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, $errors);
        // Two limits read per window: the parse's, then the walk's.
        $this->assertSame("4\n2\n", $output, $errors);
    }
}
