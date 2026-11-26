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

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Match\MatchRun;
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Options\SolverStrategy;
use PHPRegex\Automata\Ordered\FirstMatchCandidate;
use PHPRegex\Automata\Ordered\FirstMatchPartition;
use PHPRegex\Automata\Solver\DfaCacheInterface;
use PHPRegex\Automata\TrivialMatchClassifier;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pattern PCRE2 refuses to compile matches nothing an answer could
 * describe: every question of the solver refuses it with what validate()
 * says, where it read the tree and answered, "/a{2,1}/" meeting no string
 * and "/^a{2,1}$/" beginning with no prefix. Each pattern here parses, and
 * every PCRE2 from 10.39 refuses it.
 */
final class InvalidPatternRefusalTest extends TestCase
{
    /**
     * @param \Closure(LanguageSolver, string): mixed $question
     */
    #[Test]
    #[DataProvider('provideQuestions')]
    public function test_a_question_refuses_a_pattern_pcre_refuses_with_the_validation_error(\Closure $question): void
    {
        foreach (self::refusedPatterns() as $regex) {
            // The engine first: PCRE2 compiles none of them.
            $this->assertFalse(@preg_match($regex, 'aa'), 'Oracle: PCRE refuses '.$regex);

            $parser = RegexParser::create(['cache' => null]);
            $parser->parse($regex);
            $validation = $parser->validate($regex);
            $this->assertFalse($validation->isValid, $regex);

            try {
                $question(new LanguageSolver($parser), $regex);
                $this->fail('The solver answers for '.$regex);
            } catch (SemanticErrorException $refusal) {
                $this->assertSame($validation->error, $refusal->getMessage(), $regex);
                $this->assertSame($validation->errorCode, $refusal->errorCode, $regex);
                $this->assertSame($validation->offset, $refusal->offset, $regex);
                $this->assertSame($validation->caretSnippet, $refusal->caretSnippet, $regex);
                $this->assertSame($validation->hint, $refusal->hint, $regex);
            }
        }
    }

    /**
     * @return iterable<string, array{question: \Closure(LanguageSolver, string): mixed}>
     */
    public static function provideQuestions(): iterable
    {
        yield 'intersection, the left pattern' => ['question' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->intersection($regex, '/a/')];
        yield 'intersection, the right pattern' => ['question' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->intersection('/a/', $regex)];
        yield 'intersection, the eager search' => ['question' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->intersection($regex, '/a/', new SolverOptions(strategy: SolverStrategy::Eager))];
        yield 'subsetOf, the left pattern' => ['question' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->subsetOf($regex, '/a/')];
        yield 'subsetOf, the right pattern' => ['question' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->subsetOf('/a/', $regex)];
        yield 'subsetOf, a pattern beside itself' => ['question' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->subsetOf($regex, $regex)];
        yield 'equivalent, the left pattern' => ['question' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->equivalent($regex, '/a/')];
        yield 'equivalent, the right pattern' => ['question' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->equivalent('/a/', $regex)];
        yield 'equivalent, a pattern beside itself' => ['question' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->equivalent($regex, $regex)];
        yield 'matchEquivalent, the left pattern' => ['question' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->matchEquivalent($regex, '/a/')];
        yield 'matchEquivalent, the right pattern' => ['question' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->matchEquivalent('/a/', $regex)];
        yield 'matchEquivalentIn, the left pattern' => ['question' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->matchEquivalentIn(MatchRun::EmptyMatchRetry, $regex, '/a/')];
        yield 'matchEquivalentIn, the right pattern' => ['question' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->matchEquivalentIn(MatchRun::EmptyMatchRetry, '/a/', $regex)];
        yield 'runsOfEveryCall, the left pattern' => ['question' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->runsOfEveryCall($regex, '/a/')];
        yield 'runsOfEveryCall, the right pattern' => ['question' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->runsOfEveryCall('/a/', $regex)];
        yield 'readAlike, the left pattern' => ['question' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->readAlike($regex, '/a/')];
        yield 'readAlike, the right pattern' => ['question' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->readAlike('/a/', $regex)];
        yield 'readAlike, a pattern beside itself' => ['question' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->readAlike($regex, $regex)];
        yield 'rewritesAlikeInPlace, the left pattern' => ['question' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->rewritesAlikeInPlace($regex, '/a/')];
        yield 'rewritesAlikeInPlace, the right pattern' => ['question' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->rewritesAlikeInPlace('/a/', $regex)];
        yield 'acceptsPrefix' => ['question' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->acceptsPrefix($regex, 'a')];
        yield 'acceptsPrefix, in partial mode' => ['question' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->acceptsPrefix($regex, 'a', new SolverOptions(matchMode: MatchMode::Partial))];
        yield 'language' => ['question' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->language($regex)];
        yield 'compile' => ['question' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->compile($regex)];
        yield 'nfa' => ['question' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->nfa($regex)];
    }

    /**
     * The answers the solver gave: PCRE2 refuses both patterns, PHP warns
     * and preg_match() returns false, and validate() says so.
     */
    #[Test]
    public function test_the_answers_once_given_for_a_refused_pattern_are_refusals(): void
    {
        $this->assertFalse(self::compiles('/a{2,1}/'));
        $this->assertFalse(self::compiles('/^a{2,1}$/'));
        $this->assertFalse(RegexParser::create()->validate('/a{2,1}/')->isValid);

        $solver = new LanguageSolver();

        try {
            $solver->intersection('/a{2,1}/', '/a/');
            $this->fail('The intersection of a refused pattern was read as empty.');
        } catch (SemanticErrorException $refusal) {
            $this->assertSame('Invalid quantifier range "{2,1}": min > max.', $refusal->getMessage());
            $this->assertSame(5, $refusal->offset);
        }

        try {
            $solver->acceptsPrefix('/^a{2,1}$/', 'a');
            $this->fail('A refused pattern was read as beginning with no prefix.');
        } catch (SemanticErrorException $refusal) {
            $this->assertSame('Invalid quantifier range "{2,1}": min > max.', $refusal->getMessage());
            $this->assertSame(6, $refusal->offset);
        }
    }

    /**
     * A DFA cache changes nothing: the pattern is refused before the cache
     * is read, and nothing of it is kept.
     */
    #[Test]
    public function test_a_refused_pattern_is_refused_with_a_dfa_cache_and_never_reaches_it(): void
    {
        $this->assertFalse(self::compiles('/[z-a]/'));

        $cache = new class implements DfaCacheInterface {
            /**
             * @var list<string>
             */
            public array $calls = [];

            public function get(string $key): ?Dfa
            {
                $this->calls[] = 'get';

                return null;
            }

            public function set(string $key, Dfa $dfa): void
            {
                $this->calls[] = 'set';
            }
        };
        $solver = new LanguageSolver(null, $cache);

        foreach (['compile', 'language'] as $method) {
            try {
                $solver->{$method}('/[z-a]/');
                $this->fail($method.'() reads /[z-a]/');
            } catch (SemanticErrorException $refusal) {
                $this->assertSame('Invalid range "z-a": start character comes after end character.', $refusal->getMessage());
            }
        }

        $this->assertSame([], $cache->calls);

        // A pattern the engine compiles goes through the cache as before.
        $this->assertSame(1, preg_match('/[a-z]/', 'a'));
        $solver->compile('/[a-z]/');
        $this->assertSame(['get', 'set'], $cache->calls);
    }

    /**
     * The target of the solver's parser judges: a lookbehind of several
     * lengths compiles from PCRE2 10.43, which PHP bundles from 8.4, and is
     * refused for PHP 8.3. The running engine says the same of its own
     * release.
     */
    #[Test]
    public function test_the_target_of_the_parser_judges_the_pattern(): void
    {
        $regex = '/(?<=a{1,3})b/';
        $older = RegexParser::create(['php_version' => '8.3', 'cache' => null]);
        $newer = RegexParser::create(['php_version' => '8.4', 'cache' => null]);

        $this->assertSame(RegexParser::create()->validate($regex)->isValid, false !== @preg_match($regex, 'ab'), 'Oracle: the running engine and its own target agree.');
        $this->assertFalse($older->validate($regex)->isValid);
        $this->assertTrue($newer->validate($regex)->isValid);

        try {
            (new LanguageSolver($older))->language($regex);
            $this->fail('A pattern PCRE2 10.42 refuses was read for it.');
        } catch (SemanticErrorException $refusal) {
            $this->assertSame('Variable-length lookbehind needs PCRE2 10.43, which PHP bundles from 8.4.', $refusal->getMessage());
        }

        $this->assertTrue((new LanguageSolver($newer))->subsetOf($regex, '/b/', new SolverOptions(matchMode: MatchMode::Partial))->isSubset);
    }

    /**
     * What is built on the solver refuses with it: the classifier, which
     * throws nothing, names no string function for a pattern PCRE2 refuses,
     * where "/^\N{U+41}/" was read as str_starts_with($s, 'A').
     */
    #[Test]
    #[DataProvider('provideRefusedTrivialMatches')]
    public function test_the_classifier_names_no_function_for_a_pattern_pcre_refuses(string $pattern): void
    {
        $this->assertFalse(@preg_match($pattern, 'A'), 'Oracle: PCRE refuses '.$pattern);

        $classifier = new TrivialMatchClassifier();

        $this->assertNull($classifier->classify($pattern));
        $this->assertNull($classifier->matchedLiteral($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRefusedTrivialMatches(): iterable
    {
        yield 'a named character without u, anchored' => ['pattern' => '/^\N{U+41}/'];
        yield 'a named character without u' => ['pattern' => '/\N{U+41}/'];
        yield 'a named character without u, after a literal' => ['pattern' => '/abc\N{U+41}/'];
        yield 'a named character without u, anchored at both ends' => ['pattern' => '/^\N{U+41}\z/'];
    }

    /**
     * A first-match list reads its patterns through the solver: a candidate
     * holding one PCRE2 refuses takes no string, as the engine matches none
     * with it. Its cell is refused with the validation error, it shadows no
     * later candidate, and the others are still read.
     */
    #[Test]
    public function test_a_first_match_list_refuses_the_candidate_of_a_pattern_pcre_refuses(): void
    {
        $this->assertFalse(self::compiles('/^a{2,1}$/'));
        $this->assertSame(1, preg_match('/^a$/', 'a'));

        $cells = (new FirstMatchPartition())->partition([
            new FirstMatchCandidate([['/^a{2,1}$/']]),
            new FirstMatchCandidate([['/^a$/']]),
            new FirstMatchCandidate([['/^a$/']]),
        ]);

        $this->assertNull($cells[0]->reachable);
        $this->assertNull($cells[0]->example);
        $this->assertSame('Invalid quantifier range "{2,1}": min > max.', $cells[0]->refused);
        $this->assertTrue($cells[1]->reachable);
        $this->assertSame(['a'], $cells[1]->example);
        $this->assertSame([], $cells[1]->takenBy);
        $this->assertFalse($cells[2]->reachable);
        $this->assertSame(1, $cells[2]->shadowedBy);
    }

    /**
     * Whether the running engine compiles the pattern: PHP warns and
     * preg_match() returns false when it does not.
     */
    private static function compiles(string $regex): bool
    {
        return false !== @preg_match($regex, '');
    }

    /**
     * @return list<string>
     */
    private static function refusedPatterns(): array
    {
        return ['/a{2,1}/', '/^a{2,1}$/', '/[z-a]/', '/(?<=a+)b/', '/\N{U+41}/', '#x[z-a]#i'];
    }
}
