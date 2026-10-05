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

namespace PHPRegex\Tests\Unit\ReDoS\Proven;

use PHPRegex\Generator\SampleGenerationException;
use PHPRegex\Parser\Cache\ArrayCache;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\Internal\StaticCaches;
use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Parsing, validation and the verdict do not depend on the caller's PCRE
 * limits: the library's own regexes run under at least PHP's defaults,
 * while the user's pattern keeps the caller's limits exactly.
 *
 * Today, under pcre.backtrack_limit 2 or 19 the Lexer fails ("PCRE Error
 * during tokenization: Backtrack limit exhausted"), validate() with JIT
 * off at 19 reports "/(?:.*\n)+x/" invalid with "Invalid group modifier
 * syntax at position 0", a recursion limit of 1 makes the parser report
 * "Unknown regex flag(s) found", and a class scan that failed under a
 * small limit stays cached for the process.
 *
 * The probe of every row, "/^(\w+\s?)*$/" on "a" x 12 . "!", shows the
 * caller's limit is in force: false with PREG_BACKTRACK_LIMIT_ERROR under
 * 0, 1, 2 and 19, JIT on or off; 0 under -1 and 1 000 000. "/(a)(b)c/" on
 * "abc" fails with PREG_RECURSION_LIMIT_ERROR under a recursion limit of 1
 * or 2, JIT off. Engine: PHP 8.4.26, PCRE2 10.49.
 */
final class RedosCallerPcreLimitsTest extends TestCase
{
    private const PROBE = '/^(\w+\s?)*$/';

    /**
     * @var array<string, string|false>
     */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['pcre.backtrack_limit', 'pcre.recursion_limit', 'pcre.jit'] as $key) {
            $this->saved[$key] = ini_get($key);
        }
        ini_set('pcre.backtrack_limit', '1000000');
        ini_set('pcre.recursion_limit', '100000');
        StaticCaches::clear();
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
        StaticCaches::clear();
    }

    /**
     * The jit column is the caller's setting. PHP keeps a compiled regex for
     * the process and runs it with the JIT whenever it was first compiled
     * with the JIT: how the library's own regexes run under a small limit
     * depends on which call compiled them first. Without the floor, the
     * "/abc/imsu" rows at 19 fail in some orders and pass in others; the
     * rows at 0, 1 and 2 and the recursion rows fail in every order. With
     * the floor every row holds whatever ran before.
     */
    #[Test]
    #[DataProvider('provideCallerLimits')]
    public function test_parse_is_independent_of_the_callers_backtrack_limit(string $pattern, string $backtrack, string $recursion, string $jit, bool $limitBites): void
    {
        ini_set('pcre.jit', $jit);
        $baseline = self::observe($pattern);
        StaticCaches::clear();

        [$bites, $observed, $after] = self::underLimits($backtrack, $recursion, static fn (): array => [
            self::limitBites(),
            self::observe($pattern),
            [ini_get('pcre.backtrack_limit'), ini_get('pcre.recursion_limit')],
        ]);

        $this->assertSame($limitBites, $bites, 'Oracle: the row no longer runs under a limit that bites.');
        $this->assertSame([$backtrack, $recursion], $after, 'The caller\'s limits changed.');
        $this->assertSame($baseline, $observed);
    }

    /**
     * @return iterable<string, array{pattern: string, backtrack: string, recursion: string, jit: string, limitBites: bool}>
     */
    public static function provideCallerLimits(): iterable
    {
        foreach (['/(?:.*\n)+x/', '/abc/imsu'] as $pattern) {
            foreach (['2', '19'] as $backtrack) {
                foreach (['0', '1'] as $jit) {
                    yield \sprintf('%s, limit %s, jit %s', $pattern, $backtrack, $jit) => ['pattern' => $pattern, 'backtrack' => $backtrack, 'recursion' => '100000', 'jit' => $jit, 'limitBites' => true];
                }
            }
        }

        yield 'limit zero' => ['pattern' => '/(?:.*\n)+x/', 'backtrack' => '0', 'recursion' => '100000', 'jit' => '0', 'limitBites' => true];
        yield 'limit one' => ['pattern' => '/(?:.*\n)+x/', 'backtrack' => '1', 'recursion' => '100000', 'jit' => '0', 'limitBites' => true];
        yield 'unlimited' => ['pattern' => '/(?:.*\n)+x/', 'backtrack' => '-1', 'recursion' => '-1', 'jit' => '0', 'limitBites' => false];
        yield 'exactly the floor' => ['pattern' => '/(?:.*\n)+x/', 'backtrack' => '1000000', 'recursion' => '100000', 'jit' => '0', 'limitBites' => false];
        yield 'recursion limit one' => ['pattern' => '/(?:.*\n)+x/', 'backtrack' => '1000000', 'recursion' => '1', 'jit' => '0', 'limitBites' => true];
        yield 'recursion limit two' => ['pattern' => '/abc/imsu', 'backtrack' => '1000000', 'recursion' => '2', 'jit' => '0', 'limitBites' => true];
    }

    /**
     * A class scan that failed under the caller's small limit is not kept:
     * the next analysis, under the default limit, proves the pattern as a
     * fresh process does. Today it stays "Potential backtracking
     * (heuristic)" until the caches are emptied.
     *
     * The tree comes from the parser's cache, so that only the scan meets
     * the small limit: the Lexer may or may not pass 19 depending on
     * whether PHP compiled its regex with the JIT earlier in the process.
     */
    #[Test]
    public function test_a_failed_scan_is_not_cached(): void
    {
        $pattern = '/[a-z]+\d/u';
        $parser = RegexParser::create(['cache' => new ArrayCache()]);
        ini_set('pcre.jit', '0');
        $fresh = (new RedosAnalyzer($parser))->analyze($pattern);
        StaticCaches::clear();

        $bites = self::underLimits('19', '100000', static function () use ($parser, $pattern): bool {
            (new RedosAnalyzer($parser))->analyze($pattern);

            return self::limitBites();
        });
        $after = (new RedosAnalyzer($parser))->analyze($pattern);

        $this->assertTrue($fresh->isProvenSafe(), 'The row no longer names the verdict a fresh process gives.');
        $this->assertTrue($bites);
        $this->assertSame($fresh->headline(), $after->headline());
        $this->assertTrue($after->isProvenSafe());
    }

    /**
     * The same, with every raise refused: the scan may then fail under the
     * caller's limit as it does today, and that failure is not kept either.
     */
    #[Test]
    public function test_a_failed_scan_is_not_cached_when_the_raise_is_refused(): void
    {
        $pattern = '/[a-z]+\d/u';
        $parser = RegexParser::create(['cache' => new ArrayCache()]);
        ini_set('pcre.jit', '0');
        $fresh = (new RedosAnalyzer($parser))->analyze($pattern);
        StaticCaches::clear();

        LibraryPcre::useIniSetter(static fn (): false => false);

        try {
            self::underLimits('19', '100000', static fn (): RedosAnalysis => (new RedosAnalyzer($parser))->analyze($pattern));
        } finally {
            LibraryPcre::useIniSetter(null);
        }
        $after = (new RedosAnalyzer($parser))->analyze($pattern);

        $this->assertTrue($fresh->isProvenSafe());
        $this->assertSame($fresh->headline(), $after->headline());
    }

    /**
     * The user's pattern runs under the caller's limits, the floor never
     * reaches it: generate() parses and validates under the floor, then
     * checks each sample of "/abc/" through the engine, which gives up
     * under a limit of 1. The analysis leaves no raised limit behind for
     * the engine either.
     */
    #[Test]
    public function test_the_users_pattern_keeps_the_callers_limit(): void
    {
        ini_set('pcre.jit', '0');
        $observed = self::underLimits('1', '100000', static function (): array {
            // "(*NO_JIT)": PHP may hold a JIT-compiled "/abc/" from an earlier test.
            $bare = [@preg_match('/(*NO_JIT)abc/', 'abc'), preg_last_error()];

            (new RedosAnalyzer())->analyze('/abc/');
            $afterAnalysis = (new PcreEngine())->test('/abc/', 'abc')->matched;

            try {
                $generated = Regex::create(['cache' => null])->generate('/abc/');
            } catch (SampleGenerationException $exception) {
                $generated = $exception->getMessage();
            }

            return [$bare, $afterAnalysis, $generated, ini_get('pcre.backtrack_limit')];
        });

        $this->assertSame([false, \PREG_BACKTRACK_LIMIT_ERROR], $observed[0]);
        $this->assertNull($observed[1], 'The user\'s pattern ran above the caller\'s limit after an analysis.');
        $this->assertIsString($observed[2]);
        $this->assertStringContainsString('the engine gave up checking', $observed[2], 'A sample of the user\'s pattern was checked above the caller\'s limit.');
        $this->assertSame('1', $observed[3]);
    }

    /**
     * Under the floor a hostile pattern may spend up to 1 000 000 steps per
     * internal call: the internal regexes must be linear, proven by the
     * library's own analysis.
     *
     * Today the tokenizer outside a class holds a subroutine call
     * "(?P>verbBody)", outside the model ("Potential backtracking
     * (heuristic)"), and the tokenizer inside a class is "Polynomial
     * backtracking, degree 2 (proven)" with the witness "\N{0," . " " x n
     * . "!}" (the "[\x20\t]* \d* [\x20\t]*" of a repeat count in a
     * lookahead). The engine agrees on wall time, JIT off: 12 ms, 48 ms,
     * 191 ms, 767 ms at n = 5 000, 10 000, 20 000, 40 000, though its
     * backtrack counter grows linearly (n + 18): the limit does not bound
     * that cost at all.
     *
     * @param \Closure(): list<string> $regexes
     */
    #[Test]
    #[DataProvider('provideInternalRegexes')]
    public function test_the_internal_regexes_are_linear(\Closure $regexes): void
    {
        $analyzer = new RedosAnalyzer();
        $list = $regexes();
        $this->assertNotEmpty($list);

        $notLinear = [];
        foreach ($list as $regex) {
            $this->assertNotFalse(@preg_match($regex, ''), 'The internal regex does not compile: '.$regex);
            $analysis = $analyzer->analyze($regex);
            if (!$analysis->isProvenSafe()) {
                $notLinear[] = $analysis->headline().': '.$regex;
            }
        }

        $this->assertSame([], $notLinear);
    }

    /**
     * @return iterable<string, array{regexes: \Closure(): list<string>}>
     */
    public static function provideInternalRegexes(): iterable
    {
        yield 'the lexer tokenizer' => ['regexes' => static function (): array {
            StaticCaches::clear();
            // One pattern per mode: UTF-8 or bytes, with and without x. The
            // alphabetic assertion has its body read by a regex of its own.
            foreach (['/a[b]c(*pla:d)/', '/a[b]c(*pla:d)/x', "/\xFF[\xFE](*pla:\xFD)/", "/\xFF[\xFE](*pla:\xFD)/x"] as $pattern) {
                RegexParser::create()->parse($pattern);
            }
            $lexer = new \ReflectionClass(Lexer::class);
            $regexes = [];
            foreach (['regexOutside', 'regexInside', 'regexBodyItem'] as $property) {
                $compiled = $lexer->getStaticPropertyValue($property);
                $modes = 0;
                foreach (\is_array($compiled) ? $compiled : [] as $regex) {
                    if (\is_string($regex)) {
                        $regexes[] = $regex;
                        $modes++;
                    }
                }
                // The body regex varies with the byte mode and x: four.
                if (0 === $modes || ('regexBodyItem' === $property && 4 !== $modes)) {
                    throw new \LogicException(\sprintf('Lexer::$%s holds %d regexes after one parse per mode.', $property, $modes));
                }
            }

            return $regexes;
        }];
        // As PatternParser builds them: every modifier PHP accepts, then the
        // same set to find the first one it refuses.
        yield 'the pattern parser flag checks' => ['regexes' => static function (): array {
            $regexes = [];
            foreach (['imsxADSUXJu', 'imsxADSUXJun', 'imsxADSUXJunr'] as $allowed) {
                $regexes[] = '/^['.preg_quote($allowed, '/').']*+$/';
                $regexes[] = '/['.preg_quote($allowed, '/').']/';
            }

            return $regexes;
        }];
    }

    /**
     * Everything the library tells about a pattern that a caller's limit
     * could change: the tree, the validation, the verdict.
     *
     * @return array{tree: string, valid: bool, error: ?string, verdict: string, proof: string, complexity: string}
     */
    private static function observe(string $pattern): array
    {
        $parser = RegexParser::create();

        try {
            $tree = serialize($parser->parse($pattern));
        } catch (RegexException $exception) {
            $tree = $exception::class.': '.$exception->getMessage();
        }
        $validation = $parser->validate($pattern);
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        return [
            'tree' => $tree,
            'valid' => $validation->isValid,
            'error' => $validation->error,
            'verdict' => $analysis->headline(),
            'proof' => $analysis->proof->value,
            'complexity' => self::complexity($analysis),
        ];
    }

    private static function complexity(RedosAnalysis $analysis): string
    {
        return $analysis->complexity->value.(null === $analysis->degree ? '' : ':'.$analysis->degree);
    }

    /**
     * Whether the caller's limits stop a regex the library could run.
     */
    private static function limitBites(): bool
    {
        if (false === @preg_match(self::PROBE, str_repeat('a', 12).'!')) {
            return true;
        }

        return '0' === ini_get('pcre.jit') && false === @preg_match('/(a)(b)c/', 'abc');
    }

    /**
     * Runs the work under the caller's limits and sets the defaults back
     * before any assertion: PHPUnit runs regexes of its own to report a
     * failure, and under a limit of 2 a failing assertSame() reports a pass.
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
            ini_set('pcre.backtrack_limit', '1000000');
            ini_set('pcre.recursion_limit', '100000');
        }
    }

    private static function resetSeam(): void
    {
        if (class_exists(LibraryPcre::class)) {
            LibraryPcre::useIniSetter(null);
        }
    }
}
