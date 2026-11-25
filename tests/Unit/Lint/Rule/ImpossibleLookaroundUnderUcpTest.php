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
use PHPRegex\Linter\Rule\ImpossibleLookaroundRule;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without u, (*UCP) gives a byte above ASCII read caselessly the other
 * case of the character of its value from PCRE2 10.35 alone: before it,
 * the tables give it, none above ASCII under C. A lookahead is judged by
 * the target's release: "/(*UCP)(?!\xC9)\xE9/i" matches "\xE9" on 10.34
 * and nothing from 10.35, "/(*UCP)(?=\xC9)\xE9/i" the reverse.
 *
 * Engine: the one-byte subjects each pattern matches, with the JIT and
 * without it, on PCRE2 10.34 (php:7.4.10, under C) for the rows of 10.34,
 * which carry its answer, and on the running engine, which the test asks,
 * where it reads (*UCP) as the row's release does (10.49, PHP 8.4.28 on
 * macOS).
 */
final class ImpossibleLookaroundUnderUcpTest extends TestCase
{
    /**
     * @param list<int> $matched the one-byte subjects the release's engine matches
     */
    #[Test]
    #[DataProvider('provideRows')]
    public function test_under_ucp_a_lookahead_reads_the_case_of_a_byte_as_the_release_of_the_target(string $pattern, string $php, string $pcre, array $matched): void
    {
        $label = addcslashes($pattern, "\200..\377").' on PCRE2 '.$pcre;
        $target = new PcreTarget(\PHP_VERSION_ID, $pcre, CharacterTables::BUILT_IN);
        // Oracle: the running engine, under C, where it reads the row's
        // release alike.
        if (PcreTarget::runtime()->supports(PcreFeature::UcpFoldsPastAsciiWithoutUtf) === $target->supports(PcreFeature::UcpFoldsPastAsciiWithoutUtf)) {
            foreach ([$pattern, PcreInterpreter::pattern($pattern)] as $engine) {
                $taken = new \ArrayObject();
                CharacterTables::under(CharacterTables::BUILT_IN, static function () use ($engine, $taken): void {
                    for ($byte = 0; $byte <= 0xFF; $byte++) {
                        if (1 === preg_match($engine, \chr($byte))) {
                            $taken[] = $byte;
                        }
                    }
                });
                $this->assertSame($matched, $taken->getArrayCopy(), 'Oracle: '.addcslashes($engine, "\200..\377"));
            }
        }

        $regex = Regex::create(['cache' => null, 'php_version' => $php, 'pcre_version' => $pcre, 'locale' => CharacterTables::BUILT_IN]);
        $linter = new PatternLinter([], $regex->target());
        $regex->parse($pattern)->accept($linter);

        $ids = array_map(static fn ($issue): string => $issue->id, $linter->getIssues());
        $this->assertSame([] === $matched, \in_array(ImpossibleLookaroundRule::ID, $ids, true), $label);
    }

    /**
     * @return iterable<string, array{pattern: string, php: string, pcre: string, matched: list<int>}>
     */
    public static function provideRows(): iterable
    {
        foreach ([
            'no Latin-1 capital, then its small letter' => ['/(*UCP)(?!\xC9)\xE9/i', [0xE9], []],
            'a Latin-1 capital, then its small letter' => ['/(*UCP)(?=\xC9)\xE9/i', [], [0xC9, 0xE9]],
            'a Latin-1 capital, then its small letter, after an option setting' => ['/(*UCP)(?i)(?=\xC9)\xE9/', [], [0xC9, 0xE9]],
            'a class leaving a Latin-1 small letter out, then its capital' => ['/(*UCP)(?=[^\xE9])\xC9/i', [0xC9], []],
            'a Latin-1 capital, then an ASCII letter' => ['/(*UCP)(?=\xC9)a/i', [], []],
        ] as $name => [$pattern, $before, $from]) {
            yield $name.', on 10.34' => ['pattern' => $pattern, 'php' => '7.4', 'pcre' => '10.34', 'matched' => $before];
            yield $name.', on 10.35' => ['pattern' => $pattern, 'php' => '8.0', 'pcre' => '10.35', 'matched' => $from];
        }
    }
}
