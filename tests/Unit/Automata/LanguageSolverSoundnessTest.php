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

namespace PHPRegex\Tests\Unit\Automata;

use PHPRegex\Automata\Determinization\DeterminizationAlgorithm;
use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LanguageSolverSoundnessTest extends TestCase
{
    use InstalledLocales;
    use PcreGates;
    use PhpUnitForwardCompat;

    #[Test]
    #[DataProvider('provideDeterminizationAlgorithms')]
    public function test_solver_matches_pcre_for_bounded_language(DeterminizationAlgorithm $determinization): void
    {
        $solver = new LanguageSolver();
        $options = new SolverOptions(
            matchMode: MatchMode::Full,
            determinizationAlgorithm: $determinization,
        );
        $patterns = $this->supportedPatterns();
        $strings = $this->generateStrings(['a', 'b'], 4);

        foreach ($patterns as $left) {
            foreach ($patterns as $right) {
                $expectedIntersectionEmpty = $this->isIntersectionEmpty($left, $right, $strings);
                $intersection = $solver->intersection($left, $right, $options);
                $this->assertSame(
                    $expectedIntersectionEmpty,
                    $intersection->isEmpty,
                    \sprintf('Intersection mismatch for %s vs %s', $left, $right),
                );

                $expectedSubset = $this->isSubset($left, $right, $strings);
                $subset = $solver->subsetOf($left, $right, $options);
                $this->assertSame(
                    $expectedSubset,
                    $subset->isSubset,
                    \sprintf('Subset mismatch for %s <= %s', $left, $right),
                );

                $expectedEquivalent = $expectedSubset && $this->isSubset($right, $left, $strings);
                $equivalent = $solver->equivalent($left, $right, $options);
                $this->assertSame(
                    $expectedEquivalent,
                    $equivalent->isEquivalent,
                    \sprintf('Equivalence mismatch for %s <=> %s', $left, $right),
                );
            }
        }
    }

    public static function provideDeterminizationAlgorithms(): \Generator
    {
        yield 'subset' => [DeterminizationAlgorithm::Subset];
        yield 'subset-indexed' => [DeterminizationAlgorithm::SubsetIndexed];
    }

    /**
     * Oracle: PCRE's full-match language of "/a++(?:ab)?/" is plain "a+" —
     * "aaab" is rejected while "/a+(?:ab)?/" accepts it. The greedy spelling
     * would answer a language PCRE does not match, so the pattern is refused.
     */
    #[Test]
    public function test_a_possessive_before_a_nullable_last_follower_is_refused(): void
    {
        $this->assertFalse((bool) @preg_match('/^(?:a++(?:ab)?)$/', 'aaab'));
        $this->assertTrue((bool) @preg_match('/^(?:a+(?:ab)?)$/', 'aaab'));

        $this->expectException(ComplexityException::class);
        (new LanguageSolver())->intersection('/a++(?:ab)?/', '/aaab/');
    }

    /**
     * A possessive quantifier gives back nothing, an iteration included: a
     * body that may match more than one way, an optional letter or a count
     * between two bounds, keeps from the greedy spelling a string the
     * follower or the next iteration would take back. Oracle: "ab" is
     * matched by "/^(?:ab?)+b$/" alone, "aaaab" by "/^(?:a{2,3})+b$/" alone;
     * the solver called each pair equivalent.
     */
    #[Test]
    #[DataProvider('providePossessivesWhoseBodyGivesBack')]
    public function test_a_possessive_whose_body_gives_back_is_refused(string $possessive, string $greedy, string $subject): void
    {
        $this->assertSame(0, preg_match($possessive, $subject));
        $this->assertSame(1, preg_match($greedy, $subject));

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains('Possessive quantifiers never give back');

        (new LanguageSolver())->equivalent($possessive, $greedy);
    }

    /**
     * @return iterable<string, array{possessive: string, greedy: string, subject: string}>
     */
    public static function providePossessivesWhoseBodyGivesBack(): iterable
    {
        yield 'an optional letter in the body' => ['possessive' => '/^(?:ab?)++b$/', 'greedy' => '/^(?:ab?)+b$/', 'subject' => 'ab'];
        yield 'a count between two bounds in the body' => ['possessive' => '/^(?:a{2,3})++b$/', 'greedy' => '/^(?:a{2,3})+b$/', 'subject' => 'aaaab'];
        yield 'an alternation in a sequence of the body' => ['possessive' => '/^(?:a(?:bc|b))++c$/', 'greedy' => '/^(?:a(?:bc|b))+c$/', 'subject' => 'abc'];
        yield 'a lazy run in the body' => ['possessive' => '/^(?:a+?){2}+b$/', 'greedy' => '/^(?:a+?){2}b$/', 'subject' => 'aaab'];
    }

    /**
     * A body that gives back only whole iterations, or an unbounded run of
     * one class, before a follower it never starts like, reads the language
     * of its greedy spelling. Oracle: every string over "abc" up to five
     * characters.
     */
    #[Test]
    #[DataProvider('providePossessivesThatGiveNothingBack')]
    public function test_a_possessive_that_gives_nothing_back_reads_its_greedy_language(string $possessive, string $greedy): void
    {
        foreach ($this->generateStrings(['a', 'b', 'c'], 5) as $subject) {
            $this->assertSame(preg_match($greedy, $subject), preg_match($possessive, $subject), (string) json_encode($subject));
        }

        $this->assertTrue((new LanguageSolver())->equivalent($possessive, $greedy)->isEquivalent);
    }

    /**
     * @return iterable<string, array{possessive: string, greedy: string}>
     */
    public static function providePossessivesThatGiveNothingBack(): iterable
    {
        yield 'a fixed sequence' => ['possessive' => '/^(?:ab)++c$/', 'greedy' => '/^(?:ab)+c$/'];
        yield 'an unbounded run of a letter' => ['possessive' => '/^(?:a+)++b$/', 'greedy' => '/^(?:a+)+b$/'];
        yield 'a single class' => ['possessive' => '/^[ab]++c$/', 'greedy' => '/^[ab]+c$/'];
        yield 'a sequence holding a group' => ['possessive' => '/^(?:(a)b)++c$/', 'greedy' => '/^(?:(a)b)+c$/'];
        yield 'an unbounded run of a group' => ['possessive' => '/^(?:(a)+)++b$/', 'greedy' => '/^(?:(a)+)+b$/'];
    }

    /**
     * An atomic group commits to nothing where its body matches one fixed
     * way, where it holds a greedy run whose characters no follower starts
     * with, or where its branches never shadow one another before what
     * follows. It then reads the language of its body. Oracle: every
     * string over "abc-" up to five characters.
     */
    #[Test]
    #[DataProvider('provideAtomicGroupsThatCommitToNothing')]
    public function test_an_atomic_group_that_commits_to_nothing_reads_its_body(string $atomic, string $plain): void
    {
        foreach ($this->generateStrings(['a', 'b', 'c', '-'], 5) as $subject) {
            $this->assertSame(preg_match($plain, $subject), preg_match($atomic, $subject), $atomic.' on '.json_encode($subject));
        }

        $this->assertTrue((new LanguageSolver())->equivalent($atomic, $plain)->isEquivalent);
    }

    /**
     * @return iterable<string, array{atomic: string, plain: string}>
     */
    public static function provideAtomicGroupsThatCommitToNothing(): iterable
    {
        yield 'a fixed sequence' => ['atomic' => '/^(?>ab)c$/', 'plain' => '/^abc$/'];
        yield 'a run before a dash' => ['atomic' => '/^(?>a+)-c$/', 'plain' => '/^a+-c$/'];
        yield 'an optional run' => ['atomic' => '/^(?>a*)b$/', 'plain' => '/^a*b$/'];
        yield 'a bounded run' => ['atomic' => '/^(?>a{1,3})b$/', 'plain' => '/^a{1,3}b$/'];
        yield 'a run of a class at the end' => ['atomic' => '/^(?>[ab]+)$/', 'plain' => '/^[ab]+$/'];
        yield 'disjoint branches' => ['atomic' => '/^(?>a|b)c$/', 'plain' => '/^(?:a|b)c$/'];
        yield 'a longer branch first' => ['atomic' => '/^(?>ab|a)c$/', 'plain' => '/^(?:ab|a)c$/'];
        yield 'a capture inside' => ['atomic' => '/^(?>(a)b)c$/', 'plain' => '/^(a)bc$/'];
        yield 'inside a lookahead' => ['atomic' => '/^(?=(?>a+)-)a+-$/', 'plain' => '/^a+-$/'];
        yield 'unanchored' => ['atomic' => '/(?>a+)b/', 'plain' => '/a+b/'];
    }

    /**
     * An atomic group that would keep a shorter or longer match than a
     * follower needs reads another language: refused, never read as its
     * body. Each row names a subject the two spellings part on.
     */
    #[Test]
    #[DataProvider('provideAtomicGroupsThatCommit')]
    public function test_an_atomic_group_that_commits_is_refused(string $atomic, string $plain, string $subject): void
    {
        $this->assertSame(0, preg_match($atomic, $subject));
        $this->assertSame(1, preg_match($plain, $subject));

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains('Atomic groups commit to their first match');

        (new LanguageSolver())->equivalent($atomic, $plain);
    }

    /**
     * @return iterable<string, array{atomic: string, plain: string, subject: string}>
     */
    public static function provideAtomicGroupsThatCommit(): iterable
    {
        // A first-set criterion on the suffix accepts this one: "c" never
        // starts the body, yet "a" is kept where "ab" was needed.
        yield 'a shorter branch first' => ['atomic' => '/^(?>a|ab)c$/', 'plain' => '/^(?:a|ab)c$/', 'subject' => 'abc'];
        yield 'a run the follower shares' => ['atomic' => '/^(?>a+)a$/', 'plain' => '/^a+a$/', 'subject' => 'aa'];
        yield 'a lazy run' => ['atomic' => '/^(?>a+?)b$/', 'plain' => '/^a+?b$/', 'subject' => 'aab'];
        yield 'a lazy run repeated' => ['atomic' => '/^(?>(?:a+?){2})b$/', 'plain' => '/^(?:a+?){2}b$/', 'subject' => 'aaab'];
        yield 'a longer branch the follower needs short' => ['atomic' => '/^(?>ab|a)b$/', 'plain' => '/^(?:ab|a)b$/', 'subject' => 'ab'];
        yield 'a newline after \R' => ['atomic' => "/^\\R\n$/", 'plain' => "/^(?:\r\n|\r|\n)\n$/", 'subject' => "\r\n"];
    }

    /**
     * PCRE2 makes a character repeated before a possessive or atomic group
     * that may match nothing possessive, as if the group could not be
     * skipped: each committed spelling finds nothing in the subject, with
     * and without the JIT, where its plain spelling matches it whole.
     */
    #[Test]
    #[DataProvider('provideRepeatsBeforeSkippableCommitments')]
    public function test_a_repeat_before_a_commitment_that_may_match_nothing_is_refused(string $committed, string $plain, string $subject): void
    {
        $this->assertSame(0, preg_match($committed, $subject));
        $this->assertSame(0, preg_match(PcreInterpreter::pattern($committed), $subject));
        $this->assertSame(1, preg_match($plain, $subject));

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains('as if the group could not be skipped');

        (new LanguageSolver())->equivalent($committed, $plain);
    }

    /**
     * @return iterable<string, array{committed: string, plain: string, subject: string}>
     */
    public static function provideRepeatsBeforeSkippableCommitments(): iterable
    {
        yield 'an optional group repeated possessively' => ['committed' => '/^a+(?:b)?+a$/', 'plain' => '/^a+(?:b)?a$/', 'subject' => 'aa'];
        yield 'an optional capturing group repeated possessively' => ['committed' => '/^a+(b)?+a$/', 'plain' => '/^a+(b)?a$/', 'subject' => 'aa'];
        yield 'a group up to twice, possessively' => ['committed' => '/^a*(?:b){0,2}+a$/', 'plain' => '/^a*(?:b){0,2}a$/', 'subject' => 'aa'];
        yield 'an optional group in an atomic group' => ['committed' => '/^a+(?>(?:b)?)a$/', 'plain' => '/^a+b?a$/', 'subject' => 'aa'];
    }

    /**
     * Without "u", PHP hands PCRE2 the tables of the locale a program sets:
     * under en_IE.ISO8859-1 "\w" takes "\xE9", and the possessive keeps
     * what the follower needs. The target names no other locale than C, so
     * the solver cannot know the tables, and refuses.
     */
    #[Test]
    public function test_a_possessive_proven_past_ascii_against_a_class_of_the_locale_is_refused(): void
    {
        $this->requiresLocale('en_IE.ISO8859-1', 'The locale row');

        $found = new \ArrayObject();
        CharacterTables::under('en_IE.ISO8859-1', static function () use ($found): void {
            $found[] = [preg_match('/^c\xE9++b?\w$/', "c\xE9\xE9"), preg_match('/^c\xE9+b?\w$/', "c\xE9\xE9")];
        });
        $this->assertSame([[0, 1]], $found->getArrayCopy());

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains('the character tables of the locale');

        (new LanguageSolver(RegexParser::create(['cache' => null, 'locale' => 'C'])))->equivalent('/^c\xE9++b?\w$/', '/^c\xE9+b?\w$/');
    }

    /**
     * "\R" is "\r\n" or one vertical space, tried in that order, and kept:
     * unless a "\n" may follow it, it reads as the alternation.
     */
    #[Test]
    public function test_a_line_break_escape_reads_as_its_alternation(): void
    {
        foreach (["a\r\nb", "a\nb", "a\rb", "a\r\rb", "a\n\rb", 'ab', "a\x0Bb", "a\fb", "a\x85b"] as $subject) {
            $this->assertSame(preg_match('/^a(?:\r\n|[\n\x0B\f\r\x85])b$/', $subject), preg_match('/^a\Rb$/', $subject), (string) json_encode($subject));
        }

        foreach (["a\r\nb", "a\nb", "a\rb", "a\r\rb", 'ab', "a\x0Bb", "a\u{85}b", "a\u{2028}b", "a\u{2029}b", "a\u{2028}\nb"] as $subject) {
            $this->assertSame(preg_match('/^a(?:\r\n|[\n\x0B\f\r\x{85}\x{2028}\x{2029}])b$/u', $subject), preg_match('/^a\Rb$/u', $subject), (string) json_encode($subject));
        }

        $this->assertTrue((new LanguageSolver())->equivalent('/^a\Rb$/', '/^a(?:\r\n|[\n\x0B\f\r\x85])b$/')->isEquivalent);
        $this->assertTrue((new LanguageSolver())->equivalent('/^a\Rb$/u', '/^a(?:\r\n|[\n\x0B\f\r\x{85}\x{2028}\x{2029}])b$/u')->isEquivalent);
    }

    /**
     * A lookbehind's body must end where the lookbehind stands. The
     * interpreter lets "\R" take the "\n" after that place and fail, where
     * the alternation stops at the "\r"; the JIT does not. A commitment a
     * lookbehind ends with is refused unless it cannot run on: before a
     * letter, "\R" still reads as the alternation.
     */
    #[Test]
    public function test_a_commitment_a_lookbehind_ends_with_is_refused(): void
    {
        $this->requiresPcre2('10.43', 'A lookbehind branch of more than one length');

        $this->assertSame(0, preg_match('/(*NO_JIT)^\r(?<=\R)\n$/', "\r\n"));
        $this->assertSame(1, preg_match('/(*NO_JIT)^\r(?<=(?:\r\n|[\n\x0B\f\r\x85]))\n$/', "\r\n"));

        foreach (["\r\nx", "\nx", "\rx", "\r\rx", 'x'] as $subject) {
            $this->assertSame(preg_match('/(*NO_JIT)^[\r\n]*x(?<=(?:\r\n|[\n\x0B\f\r\x85])x)$/', $subject), preg_match('/(*NO_JIT)^[\r\n]*x(?<=\Rx)$/', $subject), (string) json_encode($subject));
        }

        $this->assertTrue((new LanguageSolver())->equivalent('/^[\r\n]*x(?<=\Rx)$/', '/^[\r\n]*x(?<=(?:\r\n|[\n\x0B\f\r\x85])x)$/')->isEquivalent);

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains('Atomic groups commit to their first match');

        (new LanguageSolver())->equivalent('/^\r(?<=\R)\n$/', '/^\r(?<=(?:\r\n|[\n\x0B\f\r\x85]))\n$/');
    }

    /**
     * @return list<string>
     */
    private function supportedPatterns(): array
    {
        return [
            '/a/',
            '/b/',
            '/a*ab/',
            '/ab/',
            '/ba/',
            '/a*/',
            '/a+/',
            '/a?/',
            '/a{2}/',
            '/a{0,2}/',
            '/(a|b)/',
            '/(ab|ba)/',
            '/[ab]/',
            '/[ab]+/',
        ];
    }

    /**
     * @param array<int, string> $alphabet
     *
     * @return array<int, string>
     */
    private function generateStrings(array $alphabet, int $maxLength): array
    {
        $results = [''];
        $current = [''];

        for ($length = 1; $length <= $maxLength; $length++) {
            $next = [];
            foreach ($current as $prefix) {
                foreach ($alphabet as $char) {
                    $next[] = $prefix.$char;
                }
            }

            foreach ($next as $value) {
                $results[] = $value;
            }

            $current = $next;
        }

        return $results;
    }

    /**
     * @param array<int, string> $strings
     */
    private function isSubset(string $left, string $right, array $strings): bool
    {
        foreach ($strings as $string) {
            if ($this->matchesFull($left, $string) && !$this->matchesFull($right, $string)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, string> $strings
     */
    private function isIntersectionEmpty(string $left, string $right, array $strings): bool
    {
        foreach ($strings as $string) {
            if ($this->matchesFull($left, $string) && $this->matchesFull($right, $string)) {
                return false;
            }
        }

        return true;
    }

    private function matchesFull(string $pattern, string $subject): bool
    {
        $matches = [];
        $result = \preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE);
        if (1 !== $result) {
            return false;
        }

        return $matches[0][0] === $subject && 0 === $matches[0][1];
    }
}
