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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\OverlappingAlternationRule;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without u, where PCRE judges no branch (one of them matches empty), the
 * bytes the branches start with decide, and under (*UCP) a byte read
 * caselessly takes the other case of the character of its value from
 * PCRE2 10.35 alone: "/(*UCP)(?:\xC9|\xE9|)+/i" overlaps for a target of
 * 10.35 and not of 10.34, where "\xC9" matches itself alone, and a class
 * leaving "\xE9" out takes "\xC9" on 10.34, which it leaves out from
 * 10.35. A byte with no other case, or whose other case is no byte, reads
 * alike on both. Under u the bytes do not decide: the rule asks PCRE, or
 * reads the ASCII part alone, and UTF reads Unicode's case on every
 * release.
 *
 * Engine: whether each branch matches the byte, "/(*UCP)^(?:branch)$/",
 * with the JIT and without it, on PCRE2 10.34 (php:7.4.10, under C) for
 * the rows of 10.34, which carry its answer, and on the running engine,
 * which the test asks, where it reads (*UCP) as the row's release does
 * (10.49, PHP 8.4.28 on macOS).
 */
final class AlternationOverlapUnderUcpTest extends TestCase
{
    private const ID = 'regex.lint.overlap.charset';

    /**
     * @param array{string, string} $branches
     * @param array{int, int}       $taken    whether each branch matches the byte, as the
     *                                        engine of the release answers
     */
    #[Test]
    #[DataProvider('provideRows')]
    public function test_under_ucp_a_caseless_byte_meets_its_other_case_from_pcre2_10_35(string $pattern, array $branches, string $byte, string $php, string $pcre, array $taken): void
    {
        $label = addcslashes($pattern, "\200..\377").' on PCRE2 '.$pcre;
        $target = new PcreTarget(\PHP_VERSION_ID, $pcre, CharacterTables::BUILT_IN);
        // Oracle: the running engine, under C, where it reads the row's
        // release alike.
        if (PcreTarget::runtime()->supports(PcreFeature::UcpFoldsPastAsciiWithoutUtf) === $target->supports(PcreFeature::UcpFoldsPastAsciiWithoutUtf)) {
            $answers = new \ArrayObject();
            CharacterTables::under(CharacterTables::BUILT_IN, static function () use ($branches, $byte, $answers): void {
                foreach ($branches as $branch) {
                    $answers[] = preg_match('/(*UCP)^(?:'.$branch.')$/', $byte);
                }
            });
            $this->assertSame($taken, $answers->getArrayCopy(), 'Oracle: '.$label);
        }

        $regex = Regex::create(['cache' => null, 'php_version' => $php, 'pcre_version' => $pcre, 'locale' => CharacterTables::BUILT_IN]);
        $linter = new PatternLinter([], $regex->target());
        $regex->parse($pattern)->accept($linter);

        $ids = array_map(static fn ($issue): string => $issue->id, $linter->getIssues());
        $this->assertSame([1, 1] === $taken ? [self::ID] : [], array_values(array_intersect([OverlappingAlternationRule::ID_OVERLAP, self::ID], $ids)), $label);
    }

    /**
     * @return iterable<string, array{pattern: string, branches: array{string, string}, byte: string, php: string, pcre: string, taken: array{int, int}}>
     */
    public static function provideRows(): iterable
    {
        foreach ([
            'a Latin-1 capital, then its small letter' => ['/(*UCP)(?:\xC9|\xE9|)+/i', ['(?i:\xC9)', '(?i:\xE9)'], "\xE9", [0, 1], [1, 1]],
            'a Latin-1 capital, then its small letter, written as they are' => ["/(*UCP)(?:\xC9|\xE9|)+/i", ["(?i:\xC9)", "(?i:\xE9)"], "\xE9", [0, 1], [1, 1]],
            'a Latin-1 small letter, then a class of its capital' => ['/(*UCP)(?:\xE9|[\xC9]|)+/i', ['(?i:\xE9)', '(?i:[\xC9])'], "\xC9", [0, 1], [1, 1]],
            'a range of Latin-1 capitals, then a small letter' => ['/(*UCP)(?:[\xC0-\xDE]|\xE0|)+/i', ['(?i:[\xC0-\xDE])', '(?i:\xE0)'], "\xE0", [0, 1], [1, 1]],
            'a class leaving a Latin-1 small letter out, then its capital' => ['/(*UCP)(?:[^\xE9]|\xC9|)+/i', ['(?i:[^\xE9])', '(?i:\xC9)'], "\xC9", [1, 1], [0, 1]],
            'a group reading a Latin-1 capital caselessly, then its small letter' => ['/(*UCP)(?:(?i:\xC9)|\xE9|)+/', ['(?i:\xC9)', '\xE9'], "\xE9", [0, 1], [1, 1]],
            'a Latin-1 capital, then its small letter, after an option setting' => ['/(*UCP)(?i)(?:\xC9|\xE9|)+/', ['(?i:\xC9)', '(?i:\xE9)'], "\xE9", [0, 1], [1, 1]],
            'a Latin-1 capital, then its small letter, case-sensitive' => ['/(*UCP)(?:\xC9|\xE9|)+/', ['\xC9', '\xE9'], "\xE9", [0, 1], [0, 1]],
            'two signs without a case' => ['/(*UCP)(?:\xD7|\xF7|)+/i', ['(?i:\xD7)', '(?i:\xF7)'], "\xF7", [0, 1], [0, 1]],
            'the small y with diaeresis, then the small letters before it' => ['/(*UCP)(?:\xFF|[\xF8-\xFE]|)+/i', ['(?i:\xFF)', '(?i:[\xF8-\xFE])'], "\xFF", [1, 0], [1, 0]],
            'the last ASCII character, then the first byte above it' => ['/(*UCP)(?:\x7F|\x80|)+/i', ['(?i:\x7F)', '(?i:\x80)'], "\x80", [0, 1], [0, 1]],
        ] as $name => [$pattern, $branches, $byte, $before, $from]) {
            yield $name.', on 10.34' => ['pattern' => $pattern, 'branches' => $branches, 'byte' => $byte, 'php' => '7.4', 'pcre' => '10.34', 'taken' => $before];
            yield $name.', on 10.35' => ['pattern' => $pattern, 'branches' => $branches, 'byte' => $byte, 'php' => '8.0', 'pcre' => '10.35', 'taken' => $from];
        }
    }
}
