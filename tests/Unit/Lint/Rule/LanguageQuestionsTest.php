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

use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Solver\DfaCacheInterface;
use PHPRegex\Linter\Rule\GroupIndex;
use PHPRegex\Linter\Rule\LintContext;
use PHPRegex\Linter\Rule\PatternInfo;
use PHPRegex\Linter\Rule\Support\LanguageQuestions;
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The questions lint rules put to the automata: the inline options they
 * spell out, and the DFA cache a rule passes along.
 */
final class LanguageQuestionsTest extends TestCase
{
    #[Test]
    public function test_a_question_asked_again_reads_the_dfas_the_cache_kept(): void
    {
        $cache = new class implements DfaCacheInterface {
            public int $hits = 0;

            /**
             * @var array<string, Dfa>
             */
            private array $dfas = [];

            public function get(string $key): ?Dfa
            {
                $dfa = $this->dfas[$key] ?? null;
                $this->hits += null === $dfa ? 0 : 1;

                return $dfa;
            }

            public function set(string $key, Dfa $dfa): void
            {
                $this->dfas[$key] = $dfa;
            }
        };

        $this->assertTrue(LanguageQuestions::isSubset('/a/', '/[ab]/', $cache));
        $this->assertSame(0, $cache->hits);

        $this->assertFalse(LanguageQuestions::areDisjoint('/a/', '/[ab]/', $cache));
        $this->assertSame(2, $cache->hits, 'the second question finds both DFAs the first one built');
    }

    /**
     * The inline options a question spells out follow the flags in force;
     * where the flags cannot say which option is in force, no question is
     * asked (null).
     */
    #[Test]
    #[DataProvider('provideSpelledFlags')]
    public function test_the_options_spelled_out_follow_what_the_pattern_sets(string $pattern, ?string $spelled): void
    {
        // The "r" modifier and the ASCII options are PCRE2 10.43: a parser
        // for an older release refuses the pattern before the flags are read.
        $verifiedAgainst = match ($pattern) {
            '/k/ir', '/(?r)k/i', '/(?-r:k)/ir', '/(?r)(?^i)k/', '/(?^i)k/r',
            '/(?a)\w/u', '/(?aD:\d)/u', '/(?-a)\w/u' => '10.43',
            default => null,
        };
        if (null !== $verifiedAgainst && !PcreTarget::runtime()->pcreAtLeast($verifiedAgainst)) {
            $this->markTestSkipped(sprintf('%s is verified against PCRE2 %s and later; PCRE2 %s reports it differently.', $pattern, $verifiedAgainst, \PCRE_VERSION));
        }
        // The "r" modifier is read from PHP 8.4 only: an older PHP refuses
        // the flag, whatever its engine. The inline "(?r)" needs no PHP.
        $flags = substr($pattern, (int) strrpos($pattern, $pattern[0]) + 1);
        if (str_contains($flags, 'r') && \PHP_VERSION_ID < 80400) {
            $this->markTestSkipped(sprintf('%s is verified against PHP 8.4 and later: PHP %s does not read the r modifier.', $pattern, \PHP_VERSION));
        }

        $tree = Regex::create()->parse($pattern);
        $context = new LintContext(
            new PatternInfo($tree->flags, $tree->delimiter, '', str_contains($tree->flags, 'u')),
            new GroupIndex(0, [], [], [], false),
            new CharSetAnalyzer($tree->flags),
        );

        $this->assertSame($spelled, LanguageQuestions::spelledFlags($context, $tree->pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, spelled: ?string}>
     */
    public static function provideSpelledFlags(): iterable
    {
        yield 'no option' => ['pattern' => '/a/', 'spelled' => 'imsx'];
        yield 'extended mode' => ['pattern' => '/(?x)a/', 'spelled' => 'imsx'];
        // A parser for a PCRE2 before 10.43 refuses "r": spelled out only
        // where the pattern uses it.
        yield 'caseless restrict modifier' => ['pattern' => '/k/ir', 'spelled' => 'imsxr'];
        yield 'caseless restrict group' => ['pattern' => '/(?r)k/i', 'spelled' => 'imsxr'];
        yield 'caseless restrict turned off' => ['pattern' => '/(?-r:k)/ir', 'spelled' => 'imsxr'];
        // "(?^)" turns "r" off, as the flags in force do.
        yield 'caseless restrict beside a caret' => ['pattern' => '/(?r)(?^i)k/', 'spelled' => 'imsxr'];
        yield 'caseless restrict modifier beside a caret' => ['pattern' => '/(?^i)k/r', 'spelled' => 'imsxr'];
        yield 'caret without caseless restrict' => ['pattern' => '/(?^i)k/', 'spelled' => 'imsx'];
        // The flags in force keep none of the ASCII options, and "xx" as "x".
        yield 'ASCII options' => ['pattern' => '/(?a)\w/u', 'spelled' => null];
        yield 'ASCII digit option' => ['pattern' => '/(?aD:\d)/u', 'spelled' => null];
        yield 'ASCII options turned off' => ['pattern' => '/(?-a)\w/u', 'spelled' => null];
        yield 'double extended mode' => ['pattern' => '/(?xx)[a b]/', 'spelled' => null];
        yield 'double extended mode beside other options' => ['pattern' => '/(?ixx-s:[a b])/', 'spelled' => null];
        // Only "xx" side by side among the options set turns it on.
        yield 'extended mode turned off twice' => ['pattern' => '/(?x)(?-xx)[a b]/', 'spelled' => 'imsx'];
    }
}
