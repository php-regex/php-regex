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
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The caseless restriction "(*CASELESS_RESTRICT)" sets holds as r does,
 * until an option setting lifts it: PCRE is asked about each branch under
 * the options in force where it stands. Under iu the Kelvin sign matches
 * "k" and "K" once the restriction is lifted, and "[^k]" refuses all
 * three; under the restriction both take the Kelvin sign.
 */
final class AlternationOverlapCaselessRestrictTest extends TestCase
{
    use PcreGates;

    private const CHARACTERS = ['k', 'K', "\u{212A}", 'a'];

    #[Test]
    #[DataProvider('provideAlternations')]
    public function test_the_branches_are_read_under_the_restriction_in_force_where_they_stand(string $pattern, string $first, string $second, bool $reported): void
    {
        $this->requiresCompile($pattern);

        $shared = array_values(array_filter(self::CHARACTERS, static fn (string $character): bool => 1 === preg_match($first, $character) && 1 === preg_match($second, $character)));
        $this->assertSame($reported, [] !== $shared, \sprintf('Oracle: the branches of %s both take %s.', $pattern, json_encode($shared)));

        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);
        $ids = array_map(static fn (object $issue): string => $issue->id, $linter->getIssues());

        $this->assertSame($reported, \in_array('regex.lint.overlap.charset', $ids, true), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, first: string, second: string, reported: bool}>
     */
    public static function provideAlternations(): iterable
    {
        yield 'lifted around the branches' => [
            'pattern' => '/(*CASELESS_RESTRICT)(?-r:\x{212A}|[^k])+/iu',
            'first' => '/(*CASELESS_RESTRICT)\A(?-r:\x{212A})\z/iu',
            'second' => '/(*CASELESS_RESTRICT)\A(?-r:[^k])\z/iu',
            'reported' => false,
        ];
        yield 'lifted around the branches, past a lookahead' => [
            'pattern' => '/(*CASELESS_RESTRICT)(?-r:(?=.)\x{212A}|[^k])+/iu',
            'first' => '/(*CASELESS_RESTRICT)\A(?-r:(?=.)\x{212A})\z/iu',
            'second' => '/(*CASELESS_RESTRICT)\A(?-r:[^k])\z/iu',
            'reported' => false,
        ];
        yield 'lifted before the branches' => [
            'pattern' => '/(*CASELESS_RESTRICT)(?-r)(?:\x{212A}|[^k])+/iu',
            'first' => '/(*CASELESS_RESTRICT)(?-r)\A\x{212A}\z/iu',
            'second' => '/(*CASELESS_RESTRICT)(?-r)\A[^k]\z/iu',
            'reported' => false,
        ];
        yield 'in force' => [
            'pattern' => '/(*CASELESS_RESTRICT)(?:\x{212A}|[^k])+/iu',
            'first' => '/(*CASELESS_RESTRICT)\A\x{212A}\z/iu',
            'second' => '/(*CASELESS_RESTRICT)\A[^k]\z/iu',
            'reported' => true,
        ];
        yield 'lifted, then set again around the branches' => [
            'pattern' => '/(*CASELESS_RESTRICT)(?-r)(?r:\x{212A}|[^k])+/iu',
            'first' => '/(*CASELESS_RESTRICT)(?-r)\A(?r:\x{212A})\z/iu',
            'second' => '/(*CASELESS_RESTRICT)(?-r)\A(?r:[^k])\z/iu',
            'reported' => true,
        ];
        // The class under the restriction still takes the Kelvin sign.
        yield 'lifted for one branch alone' => [
            'pattern' => '/(*CASELESS_RESTRICT)(?:(?-r:\x{212A})|[^k])+/iu',
            'first' => '/(*CASELESS_RESTRICT)\A(?-r:\x{212A})\z/iu',
            'second' => '/(*CASELESS_RESTRICT)\A[^k]\z/iu',
            'reported' => true,
        ];
        yield 'lifted for the class alone' => [
            'pattern' => '/(*CASELESS_RESTRICT)(?:\x{212A}|(?-r:[^k]))+/iu',
            'first' => '/(*CASELESS_RESTRICT)\A\x{212A}\z/iu',
            'second' => '/(*CASELESS_RESTRICT)\A(?-r:[^k])\z/iu',
            'reported' => false,
        ];
        yield 'no restriction' => [
            'pattern' => '/(?:\x{212A}|[^k])+/iu',
            'first' => '/\A\x{212A}\z/iu',
            'second' => '/\A[^k]\z/iu',
            'reported' => false,
        ];
    }
}
