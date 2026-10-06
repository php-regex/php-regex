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

namespace PHPRegex\Tests\Integration\PatternInfo;

use PHPRegex\Parser\Analysis\PatternInfo;
use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The pattern facts against what PCRE2 itself computes: the "/I" output of
 * pcre2test, committed in tests/Fixtures/PatternInfo/pcre2test.out for the
 * patterns of patterns.txt (generate.php writes it; its header gives the
 * command).
 *
 * The exact facts must equal PCRE2's: capture count, names, max back
 * reference, \C, the three limits, newline, BSR. The sound bounds are only
 * held to what PCRE2's answer implies: a proven start anchor is one PCRE2
 * also reports, a match that cannot be empty is one PCRE2 does not say may
 * be, and the longest lookbehind never exceeds PCRE2's (which also counts
 * the character "\b", "\B" and "\A" look back at).
 */
final class PatternInfoPcre2ParityTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../../Fixtures/PatternInfo';

    /**
     * pcre2test's words for each newline convention, by enum value.
     */
    private const NEWLINES = [
        'CR' => 'CR',
        'LF' => 'LF',
        'CRLF' => 'CRLF',
        'any Unicode newline' => 'ANY',
        'CR, LF, or CRLF' => 'ANYCRLF',
        'NUL' => 'NUL',
    ];

    private const BSRS = [
        'any Unicode newline' => 'UNICODE',
        'CR, LF, or CRLF' => 'ANYCRLF',
    ];

    #[Test]
    public function test_fixture_holds_one_compiled_block_per_pattern(): void
    {
        $patterns = self::patterns();
        $blocks = self::blocks();

        $this->assertGreaterThanOrEqual(60, \count($patterns));
        $this->assertCount(\count($patterns), $blocks, 'pcre2test.out is stale: run generate.php.');

        foreach ($patterns as $index => $pattern) {
            $echo = $blocks[$index][0] ?? '';
            $this->assertStringStartsWith(substr($pattern, 0, (int) strrpos($pattern, '/') + 1).'I', $echo, 'pcre2test.out is out of order or stale.');
            foreach ($blocks[$index] as $line) {
                $this->assertStringStartsNotWith('Failed:', $line, $pattern.' does not compile in the fixture.');
            }
        }
    }

    /**
     * @param array{captureCount: int, names: array<string, list<int>>, maxBackreference: int, usesBackslashC: bool, matchLimit: int|null, depthLimit: int|null, heapLimit: int|null, newline: string|null, bsr: string|null, anchored: bool, mayMatchEmpty: bool, maxLookbehind: int} $pcre2
     */
    #[Test]
    #[DataProvider('provideFixture')]
    public function test_exact_facts_equal_pcre2(string $pattern, array $pcre2): void
    {
        $info = self::info($pattern);

        $this->assertSame($pcre2['captureCount'], $info->captureCount, 'capture count');
        // The same numbers per name; PHPRegex lists them ascending, while
        // PCRE2's table may list a duplicate name's numbers in another order.
        $this->assertSame(array_map(static function (array $numbers): array {
            sort($numbers);

            return $numbers;
        }, $pcre2['names']), $info->names, 'names');
        $this->assertSame($pcre2['maxBackreference'], $info->maxBackreference, 'max back reference');
        $this->assertSame($pcre2['usesBackslashC'], $info->usesBackslashC, '\C');
        $this->assertSame($pcre2['matchLimit'], $info->matchLimit, 'match limit');
        $this->assertSame($pcre2['depthLimit'], $info->depthLimit, 'depth limit');
        $this->assertSame($pcre2['heapLimit'], $info->heapLimit, 'heap limit');
        $this->assertSame($pcre2['newline'], $info->newline?->value, 'newline');
        $this->assertSame($pcre2['bsr'], $info->bsr?->value, 'BSR');
    }

    /**
     * @param array{captureCount: int, names: array<string, list<int>>, maxBackreference: int, usesBackslashC: bool, matchLimit: int|null, depthLimit: int|null, heapLimit: int|null, newline: string|null, bsr: string|null, anchored: bool, mayMatchEmpty: bool, maxLookbehind: int} $pcre2
     */
    #[Test]
    #[DataProvider('provideFixture')]
    public function test_sound_bounds_agree_with_what_pcre2_reports(string $pattern, array $pcre2): void
    {
        $info = self::info($pattern);

        if ($info->anchoredStart) {
            $this->assertTrue($pcre2['anchored'], 'anchoredStart is true where PCRE2 does not report "anchored".');
        }
        if ($info->minMatchLength > 0) {
            $this->assertFalse($pcre2['mayMatchEmpty'], 'minMatchLength is above 0 where PCRE2 says "May match empty string".');
        }
        $this->assertLessThanOrEqual($pcre2['maxLookbehind'], $info->maxLookbehind, 'maxLookbehind exceeds PCRE2\'s "Max lookbehind".');
    }

    /**
     * @return iterable<string, array{pattern: string, pcre2: array{captureCount: int, names: array<string, list<int>>, maxBackreference: int, usesBackslashC: bool, matchLimit: int|null, depthLimit: int|null, heapLimit: int|null, newline: string|null, bsr: string|null, anchored: bool, mayMatchEmpty: bool, maxLookbehind: int}}>
     */
    public static function provideFixture(): iterable
    {
        $blocks = self::blocks();
        foreach (self::patterns() as $index => $pattern) {
            yield $pattern => ['pattern' => $pattern, 'pcre2' => self::facts($blocks[$index] ?? [])];
        }
    }

    private static function info(string $pattern): PatternInfo
    {
        // The fixture is PHP 8.4's compile context on PCRE2 10.49.
        return Regex::create(['cache' => new NullCache(), 'php_version' => '8.4', 'pcre_version' => '10.49'])->info($pattern);
    }

    /**
     * @return list<string>
     */
    private static function patterns(): array
    {
        $patterns = [];
        foreach (file(self::FIXTURE.'/patterns.txt', \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if ('' !== trim($line) && !str_starts_with($line, '#')) {
                $patterns[] = $line;
            }
        }

        return $patterns;
    }

    /**
     * The output after its version line, one list of lines per pattern.
     *
     * @return list<list<string>>
     */
    private static function blocks(): array
    {
        $lines = file(self::FIXTURE.'/pcre2test.out', \FILE_IGNORE_NEW_LINES) ?: [];
        array_shift($lines);

        $blocks = [];
        $block = [];
        foreach ($lines as $line) {
            if ('' === $line) {
                if ([] !== $block) {
                    $blocks[] = $block;
                }
                $block = [];

                continue;
            }
            $block[] = $line;
        }
        if ([] !== $block) {
            $blocks[] = $block;
        }

        return $blocks;
    }

    /**
     * @param list<string> $block
     *
     * @return array{captureCount: int, names: array<string, list<int>>, maxBackreference: int, usesBackslashC: bool, matchLimit: int|null, depthLimit: int|null, heapLimit: int|null, newline: string|null, bsr: string|null, anchored: bool, mayMatchEmpty: bool, maxLookbehind: int}
     */
    private static function facts(array $block): array
    {
        $facts = [
            'captureCount' => -1,
            'names' => [],
            'maxBackreference' => 0,
            'usesBackslashC' => false,
            'matchLimit' => null,
            'depthLimit' => null,
            'heapLimit' => null,
            'newline' => null,
            'bsr' => null,
            'anchored' => false,
            'mayMatchEmpty' => false,
            'maxLookbehind' => 0,
        ];

        $inNames = false;
        foreach ($block as $line) {
            if ($inNames && 1 === preg_match('/^  (\S+)\s+(\d+)$/', $line, $name)) {
                $facts['names'][$name[1]][] = (int) $name[2];

                continue;
            }
            $inNames = 'Named capture groups:' === $line;

            match (true) {
                1 === preg_match('/^Capture group count = (\d+)$/', $line, $m) => $facts['captureCount'] = (int) $m[1],
                1 === preg_match('/^Max back reference = (\d+)$/', $line, $m) => $facts['maxBackreference'] = (int) $m[1],
                1 === preg_match('/^Max lookbehind = (\d+)$/', $line, $m) => $facts['maxLookbehind'] = (int) $m[1],
                1 === preg_match('/^Match limit = (\d+)$/', $line, $m) => $facts['matchLimit'] = (int) $m[1],
                1 === preg_match('/^Depth limit = (\d+)$/', $line, $m) => $facts['depthLimit'] = (int) $m[1],
                1 === preg_match('/^Heap limit = (\d+)$/', $line, $m) => $facts['heapLimit'] = (int) $m[1],
                1 === preg_match('/^Forced newline is (.+)$/', $line, $m) => $facts['newline'] = self::NEWLINES[$m[1]] ?? 'unknown: '.$m[1],
                1 === preg_match('/^\\\\R matches (.+)$/', $line, $m) => $facts['bsr'] = self::BSRS[$m[1]] ?? 'unknown: '.$m[1],
                1 === preg_match('/^(?:Overall options|Options): (.*)$/', $line, $m) => $facts['anchored'] = \in_array('anchored', explode(' ', $m[1]), true),
                'Contains \C' === $line => $facts['usesBackslashC'] = true,
                'May match empty string' === $line => $facts['mayMatchEmpty'] = true,
                default => null,
            };
        }

        return $facts;
    }
}
