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

namespace PHPRegex\Tests\Unit\Parser\Printer;

use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Printer\PatternFormatter;
use PHPRegex\Tests\Support\PatternEquivalence;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Formatting and compacting never change a pattern. Four independent checks
 * run on format at widths 80 and 1, on compact, and on compact of format:
 *
 *  1. PCRE compiles the output, as it compiles the input;
 *  2. the output parses back to the input's tree, comments, positions and
 *     literal splits aside;
 *  3. PCRE matches every subject the same way, groups and offsets included;
 *  4. formatting the output again gives it back unchanged.
 *
 * The hand-written edge cases run with the suite. The PCRE2 test suites and
 * the lint fixture, some five thousand patterns, take about a minute and run
 * on their own: tools/phpunit/vendor/bin/phpunit --group formatter-corpus
 */
final class PatternFormatterEquivalenceTest extends TestCase
{
    private const WIDTHS = [80, 1];

    private const EDGE_CASES = __DIR__.'/../../../Fixtures/Formatter/edge-cases.json';

    private const SUITE_CASES = __DIR__.'/../../../Fixtures/Pcre2/suite-cases.json';

    private const LINT_CASES = __DIR__.'/../../../Fixtures/Corpus/lint-expectations.json';

    private const BRACKET_CLOSERS = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];

    private string|false $backtrackLimit = false;

    protected function setUp(): void
    {
        // A catastrophic subject fails fast, the same way on both sides.
        $this->backtrackLimit = ini_set('pcre.backtrack_limit', '200000');
    }

    protected function tearDown(): void
    {
        if (false !== $this->backtrackLimit) {
            ini_set('pcre.backtrack_limit', $this->backtrackLimit);
        }
    }

    #[Test]
    #[DataProvider('provideEdgeCases')]
    public function test_format_and_compact_keep_an_edge_case_the_same_pattern(string $pattern): void
    {
        $this->assertEveryOutputIsTheSamePattern($pattern);
    }

    #[Test]
    #[Group('formatter-corpus')]
    #[DataProvider('provideSuiteAndFixturePatterns')]
    public function test_format_and_compact_keep_a_suite_pattern_the_same_pattern(string $pattern): void
    {
        $this->assertEveryOutputIsTheSamePattern($pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideEdgeCases(): iterable
    {
        foreach (self::readJson(self::EDGE_CASES) as $name => $pattern) {
            if (\is_string($name) && \is_string($pattern)) {
                yield $name => ['pattern' => $pattern];
            }
        }
    }

    /**
     * The accepted cases of the PCRE2 test suites and every pattern of the
     * lint fixture, as far as the running PCRE compiles them.
     *
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideSuiteAndFixturePatterns(): iterable
    {
        foreach (self::readJson(self::SUITE_CASES) as $file => $cases) {
            if ('meta' === $file || !\is_array($cases)) {
                continue;
            }

            foreach ($cases as $case) {
                if (!\is_array($case) || 'accept' !== ($case['verdict'] ?? null)
                    || !\is_string($case['id'] ?? null) || !\is_string($case['delimiter'] ?? null)
                    || !\is_string($case['pattern'] ?? null) || !\is_string($case['flags'] ?? null)) {
                    continue;
                }

                $delimiter = $case['delimiter'];
                $pattern = $delimiter.$case['pattern'].(self::BRACKET_CLOSERS[$delimiter] ?? $delimiter).$case['flags'];
                if (false !== @preg_match($pattern, '')) {
                    yield $case['id'] => ['pattern' => $pattern];
                }
            }
        }

        $seen = [];
        foreach (self::readJson(self::LINT_CASES) as $entry) {
            $pattern = \is_array($entry) ? $entry['pattern'] ?? null : null;
            if (\is_string($pattern) && !isset($seen[$pattern]) && false !== @preg_match($pattern, '')) {
                $seen[$pattern] = true;

                yield 'lint '.md5($pattern) => ['pattern' => $pattern];
            }
        }
    }

    private function assertEveryOutputIsTheSamePattern(string $pattern): void
    {
        $regex = $this->parse($pattern);
        $compactor = new PatternFormatter();

        $outputs = ['compact' => $compactor->compact($regex)->pattern];
        foreach (self::WIDTHS as $width) {
            $formatter = new PatternFormatter($width);
            $formatted = $formatter->format($regex)->pattern;
            $outputs['format@'.$width] = $formatted;
            $outputs['compact(format@'.$width.')'] = $compactor->compact($this->parse($formatted))->pattern;

            $this->assertSame($formatted, $formatter->format($this->parse($formatted))->pattern, \sprintf('format@%d of %s is not idempotent.', $width, $pattern));
        }

        $structure = PatternEquivalence::structure($regex);
        $flags = str_replace('x', '', $regex->flags);
        $subjects = PatternEquivalence::crashesTheEngine($pattern) ? [] : PatternEquivalence::subjects($regex);
        $expected = array_map(static fn (string $subject): string => PatternEquivalence::engineRun($pattern, $subject), $subjects);

        foreach ($outputs as $kind => $output) {
            $this->assertNotFalse(@preg_match($output, ''), \sprintf('%s of %s does not compile: %s', $kind, $pattern, $output));

            $tree = $this->parse($output);
            $this->assertSame($structure, PatternEquivalence::structure($tree), \sprintf('%s of %s parses to another tree: %s', $kind, $pattern, $output));
            $this->assertSame($flags, str_replace('x', '', $tree->flags), \sprintf('%s of %s changes the flags: %s', $kind, $pattern, $output));

            foreach ($subjects as $index => $subject) {
                $this->assertSame(
                    $expected[$index],
                    PatternEquivalence::engineRun($output, $subject),
                    \sprintf('%s of %s matches %s differently: %s', $kind, $pattern, json_encode($subject, \JSON_INVALID_UTF8_SUBSTITUTE), $output),
                );
            }
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }

    private function parse(string $pattern): RegexNode
    {
        return Regex::create(['cache' => new NullCache()])->parse($pattern);
    }
}
