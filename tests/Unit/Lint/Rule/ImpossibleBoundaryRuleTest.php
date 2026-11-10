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
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "\b" between two word characters, or two non-word characters, never
 * holds; "\B" between a word and a non-word character never holds. Both
 * neighbours must be single-character atoms, and what a word character is
 * follows the effective flags: under /u or (*UCP) "é" is one, in byte mode
 * its bytes are not.
 */
final class ImpossibleBoundaryRuleTest extends TestCase
{
    use PcreGates;

    private const ID = 'regex.lint.anchor.impossible.boundary';

    private const SUBJECTS = ['', 'a', 'ab', 'a b', 'a!', '!a', ' !', '!', 'a1', '1a', 'aa', 'éx', 'é x', 'x', 'b', 'ba'];

    #[Test]
    #[DataProvider('provideImpossibleBoundaries')]
    public function test_a_boundary_its_neighbours_contradict_is_reported(string $pattern): void
    {
        // The ASCII word option is PCRE2 10.43.
        if ('/(?aW)a\bb/u' === $pattern) {
            $this->requiresPcre2('10.43', $pattern);
        }

        // Oracle, PHP 8.4.26 / PCRE2 10.49: no subject matches.
        foreach (self::SUBJECTS as $subject) {
            $this->assertSame(0, preg_match($pattern, $subject), \sprintf('%s matches %s', $pattern, json_encode($subject)));
        }

        $violation = $this->violation($pattern);
        $this->assertInstanceOf(RuleViolation::class, $violation, $pattern);
        $this->assertSame(LintSeverity::Warning, $violation->severity);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideImpossibleBoundaries(): iterable
    {
        yield 'boundary between two letters' => ['pattern' => '/a\bb/'];
        yield 'boundary between two non-word characters' => ['pattern' => '/ \b!/'];
        yield 'non-boundary between a letter and a non-word character' => ['pattern' => '/a\B!/'];
        yield 'non-boundary between a non-word character and a letter' => ['pattern' => '/!\Ba/'];
        yield 'boundary between two word classes' => ['pattern' => '/[a-z]\b\d/'];
        yield 'boundary between two word shorthands' => ['pattern' => '/\w\b\w/'];
        // Under /u PHP turns UCP on: "é" is a word character.
        yield 'letter under u' => ['pattern' => '/é\bx/u'];
        yield 'letter under the UTF and UCP verbs' => ['pattern' => '/(*UTF)(*UCP)é\bx/'];
        // ASCII letters are word characters under every option.
        yield 'ASCII letters under the ASCII word option' => ['pattern' => '/(?aW)a\bb/u'];
        yield 'escaped ASCII letter' => ['pattern' => '/\x61\bb/u'];
        yield 'space class beside a non-word character' => ['pattern' => '/\s\b!/'];
        yield 'non-word classes' => ['pattern' => '/[!?]\b\W/'];
        yield 'non-ASCII non-word characters under u' => ['pattern' => '/«\b»/u'];
    }

    #[Test]
    #[DataProvider('providePossibleBoundaries')]
    public function test_a_boundary_that_can_hold_is_not_reported(string $pattern, string $subject): void
    {
        // The ASCII options are PCRE2 10.43.
        $this->requiresPcre2For($pattern, [
            '10.43' => ['/(?aW)é\bx/u', '/(?a)(?-aD)é\bx/u'],
        ]);

        // Oracle, PHP 8.4.26 / PCRE2 10.49: the subject matches.
        $this->assertSame(1, preg_match($pattern, $subject), $pattern);

        $this->assertNull($this->violation($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function providePossibleBoundaries(): iterable
    {
        yield 'non-boundary between two non-word characters' => ['pattern' => '/ \B!/', 'subject' => ' !'];
        yield 'boundary between a letter and a non-word character' => ['pattern' => '/a\b!/', 'subject' => 'a!'];
        yield 'non-boundary between two letters' => ['pattern' => '/a\Bb/', 'subject' => 'ab'];
        // In byte mode the last byte of "é" is no word character.
        yield 'letter in byte mode' => ['pattern' => '/é\bx/', 'subject' => 'éx'];
        // The optional neighbour may be absent: the boundary then sits at the start.
        yield 'optional neighbour' => ['pattern' => '/a?\bb/', 'subject' => 'b'];
        yield 'neighbour of either kind' => ['pattern' => '/\w\b./', 'subject' => 'a!'];
        yield 'class of either kind' => ['pattern' => '/[a!]\bb/', 'subject' => '!b'];
        // The ASCII word option keeps "é" out of "\w" under /u.
        yield 'ASCII word option under u' => ['pattern' => '/(?aW)é\bx/u', 'subject' => 'éx'];
        // "(?-aD)" turns the digit option off, the word option stays on.
        yield 'ASCII options turned off in part' => ['pattern' => '/(?a)(?-aD)é\bx/u', 'subject' => 'éx'];
    }

    #[Test]
    public function test_the_engine_reads_the_letter_as_a_word_character_only_under_u(): void
    {
        $this->assertSame(1, preg_match('/^\w$/u', 'é'));
        $this->assertSame(1, preg_match('/é\bx/', 'éx'));
        $this->assertSame(0, preg_match('/é\bx/u', 'éx'));
    }

    /**
     * Under /u the automata read "\w" over the whole of Unicode, which
     * takes time to build: an ASCII literal or a dot beside a boundary is
     * decided without them, and an atom met again is not asked twice.
     *
     * The questions are counted where each of them lands: it looks its two
     * patterns up in the DFA cache of the run. Two hundred boundaries ask
     * what one asks, and each is reported when the engine matches nothing:
     * $subject is what one boundary matches, null when it never holds.
     */
    #[Test]
    #[DataProvider('provideRepeatedBoundaries')]
    public function test_many_boundaries_under_u_ask_the_automata_what_one_boundary_asks(string $boundary, int $questions, ?string $subject): void
    {
        foreach ([1, 50, 200] as $times) {
            $pattern = '/'.str_repeat($boundary, $times).'/u';

            // Oracle, PHP 8.4.27 / PCRE2 10.49, and 10.39 to 10.44: the
            // subject matches, or no subject does.
            if (null !== $subject) {
                $this->assertSame(1, preg_match($pattern, str_repeat($subject, $times)), $pattern);
            }
            foreach (null === $subject ? [...self::SUBJECTS, '«»'] : [] as $candidate) {
                $this->assertSame(0, preg_match($pattern, str_repeat($candidate, $times)), \sprintf('%s matches %s', $pattern, json_encode($candidate)));
            }

            $cache = self::countingCache();
            $linter = PatternLinter::withDfaCache([], $cache);

            Regex::create(['cache' => null])->parse($pattern)->accept($linter);

            $this->assertSame(2 * $questions, $cache->lookups, \sprintf('The lookups of %d boundaries, two per question.', $times));
            $this->assertCount(null === $subject ? $times : 0, array_filter($linter->getIssues(), static fn (RuleViolation $violation): bool => self::ID === $violation->id));
        }
    }

    /**
     * @return iterable<string, array{boundary: string, questions: int, subject: string|null}>
     */
    public static function provideRepeatedBoundaries(): iterable
    {
        yield 'ASCII letter beside a dot' => ['boundary' => 'a\b.', 'questions' => 0, 'subject' => 'a!'];
        yield 'two ASCII letters' => ['boundary' => 'a\bb', 'questions' => 0, 'subject' => null];
        // Whether "é" is within "\w": it is.
        yield 'non-ASCII letter met again' => ['boundary' => 'é\bx', 'questions' => 1, 'subject' => null];
        // Whether each is within "\w", then whether it shares nothing with it.
        yield 'non-ASCII non-word characters met again' => ['boundary' => '«\b»', 'questions' => 4, 'subject' => null];
    }

    /**
     * A DFA cache that counts the times it is looked into.
     *
     * @return DfaCacheInterface&object{lookups: int}
     */
    private static function countingCache(): DfaCacheInterface
    {
        return new class implements DfaCacheInterface {
            public int $lookups = 0;

            /**
             * @var array<string, Dfa>
             */
            private array $dfas = [];

            public function get(string $key): ?Dfa
            {
                $this->lookups++;

                return $this->dfas[$key] ?? null;
            }

            public function set(string $key, Dfa $dfa): void
            {
                $this->dfas[$key] = $dfa;
            }
        };
    }

    private function violation(string $pattern): ?RuleViolation
    {
        $linter = new PatternLinter();
        Regex::create()->parse($pattern)->accept($linter);

        foreach ($linter->getIssues() as $violation) {
            if (self::ID === $violation->id) {
                return $violation;
            }
        }

        return null;
    }
}
