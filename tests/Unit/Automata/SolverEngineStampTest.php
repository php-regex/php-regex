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
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Options\SolverStrategy;
use PHPRegex\Automata\Solver\EquivalenceResult;
use PHPRegex\Automata\Solver\IntersectionResult;
use PHPRegex\Automata\Solver\MatchEquivalenceResult;
use PHPRegex\Automata\Solver\SubsetResult;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A verdict is a fact about one PCRE2 release: every solver result carries
 * the release it was computed with, the same stamp the ReDoS analysis
 * carries (RedosAnalysis defaults pcreVersion to explode(' ', PCRE_VERSION)[0]).
 */
final class SolverEngineStampTest extends TestCase
{
    #[Test]
    #[DataProvider('provideQuestions')]
    public function test_every_result_carries_the_running_engine(string $question): void
    {
        $solver = new LanguageSolver();

        $result = match ($question) {
            'intersection' => $solver->intersection('/a/', '/a/'),
            'subset' => $solver->subsetOf('/a/', '/a*/'),
            'equivalence' => $solver->equivalent('/a/', '/a/'),
            default => $this->fail($question.' is not a solver question.'),
        };

        // The value shape the ReDoS result pins: the release of the running
        // engine, "10.49" out of "10.49 2026-09-28".
        $this->assertSame(explode(' ', \PCRE_VERSION)[0], $result->pcreVersion, $question);
    }

    /**
     * @return iterable<string, array{question: string}>
     */
    public static function provideQuestions(): iterable
    {
        yield 'intersection result' => ['question' => 'intersection'];
        yield 'subset result' => ['question' => 'subset'];
        yield 'equivalence result' => ['question' => 'equivalence'];
    }

    /**
     * The sets every answer is computed from come from the running engine,
     * also when an older PCRE2 is judged: each result, the match
     * equivalence included, carries the running engine's release.
     */
    #[Test]
    #[DataProvider('provideQuestionsUnderAnOlderTarget')]
    public function test_every_result_carries_the_running_engine_under_an_older_target(string $question): void
    {
        $solver = new LanguageSolver(RegexParser::create(['pcre_version' => '10.42']));

        $result = match ($question) {
            'intersection' => $solver->intersection('/a/', '/a/'),
            'subset' => $solver->subsetOf('/a/', '/a*/'),
            'equivalence' => $solver->equivalent('/a/', '/a/'),
            'match equivalence' => $solver->matchEquivalent('/a/', '/a/'),
            default => $this->fail($question.' is not a solver question.'),
        };

        $this->assertSame(explode(' ', \PCRE_VERSION)[0], $result->pcreVersion, $question);
    }

    /**
     * @return iterable<string, array{question: string}>
     */
    public static function provideQuestionsUnderAnOlderTarget(): iterable
    {
        yield 'intersection result' => ['question' => 'intersection'];
        yield 'subset result' => ['question' => 'subset'];
        yield 'equivalence result' => ['question' => 'equivalence'];
        yield 'match equivalence result' => ['question' => 'match equivalence'];
    }

    /**
     * The engine that ran is one fact, the target judged another: each
     * result carries the target of the solver's parser, which decides what
     * the patterns mean. "a{,2}" repeats from PCRE2 10.43 and is text for
     * PHP 8.3, whose PCRE2 10.42 the running engine may not be.
     */
    #[Test]
    #[DataProvider('provideQuestionsAndStrategies')]
    public function test_every_result_carries_the_target_judged(string $question, SolverStrategy $strategy): void
    {
        $this->assertSame(1, preg_match('/^a\{,2\}$/', 'a{,2}'));

        $parser = RegexParser::create(['php_version' => '8.3']);
        $solver = new LanguageSolver($parser);
        $options = new SolverOptions(strategy: $strategy);

        $result = match ($question) {
            'intersection' => $solver->intersection('/a{,2}/', '/a\{,2\}/', $options),
            'subset' => $solver->subsetOf('/a{,2}/', '/a\{,2\}/', $options),
            'equivalence' => $solver->equivalent('/a{,2}/', '/a\{,2\}/', $options),
            'match equivalence' => $solver->matchEquivalent('/a{,2}/', '/a\{,2\}/', $options),
            default => $this->fail($question.' is not a solver question.'),
        };

        $this->assertSame($parser->target(), $result->target, $question);
        $this->assertSame(['php' => '8.3', 'pcre' => '10.42', 'locale' => $parser->target()->locale], $result->target->toArray());
        $this->assertSame(explode(' ', \PCRE_VERSION)[0], $result->pcreVersion, 'The engine that ran stays the running one.');

        // The answer is the target's: for PHP 8.3 the braces are text.
        $this->assertSame('a{,2}', match (true) {
            $result instanceof IntersectionResult => $result->example,
            $result instanceof SubsetResult => $result->isSubset ? 'a{,2}' : null,
            default => $result->isEquivalent ? 'a{,2}' : null,
        });
    }

    /**
     * @return iterable<string, array{question: string, strategy: SolverStrategy}>
     */
    public static function provideQuestionsAndStrategies(): iterable
    {
        foreach (SolverStrategy::cases() as $strategy) {
            foreach (['intersection', 'subset', 'equivalence', 'match equivalence'] as $question) {
                yield $question.', '.$strategy->value => ['question' => $question, 'strategy' => $strategy];
            }
        }
    }

    /**
     * A call that runs its own group again leaves one proof of the same
     * matches, the possessive giving nothing back, which builds its result
     * apart: it carries the target too.
     */
    #[Test]
    public function test_the_result_of_a_proof_through_calls_carries_the_target_judged(): void
    {
        $parser = RegexParser::create(['php_version' => '8.3']);
        foreach (['aabxy', 'aabxxyy', 'abx', 'b'] as $subject) {
            $this->assertSame(preg_match('/a+b(x(?1)?y)/', $subject, $greedy), preg_match('/a++b(x(?1)?y)/', $subject, $possessive), $subject);
            $this->assertSame($greedy, $possessive, $subject);
        }

        $result = (new LanguageSolver($parser))->matchEquivalent('/a+b(x(?1)?y)/', '/a++b(x(?1)?y)/');

        $this->assertTrue($result->isEquivalent);
        $this->assertSame($parser->target(), $result->target);
    }

    /**
     * Built without a target, a result is one of the running engine; built
     * with one, it keeps it.
     */
    #[Test]
    public function test_a_result_built_without_a_target_carries_the_running_one(): void
    {
        $target = new PcreTarget(80200, '10.40', 'C');
        $running = PcreTarget::runtime()->key();

        $this->assertNotSame($running, $target->key());

        $this->assertSame($running, (new IntersectionResult(false, 'ab'))->target->key());
        $this->assertSame($running, (new SubsetResult(false, 'ab'))->target->key());
        $this->assertSame($running, (new EquivalenceResult(false, 'ab'))->target->key());
        $this->assertSame($running, (new MatchEquivalenceResult(false, 'ab'))->target->key());

        $this->assertSame($target, (new IntersectionResult(false, 'ab', target: $target))->target);
        $this->assertSame($target, (new SubsetResult(false, 'ab', target: $target))->target);
        $this->assertSame($target, (new EquivalenceResult(false, 'ab', target: $target))->target);
        $this->assertSame($target, (new MatchEquivalenceResult(false, 'ab', target: $target))->target);
    }

    #[Test]
    public function test_a_result_keeps_the_version_it_was_built_with(): void
    {
        $stamped = '10.42-stamp';

        $intersection = new IntersectionResult(false, 'ab', $stamped);
        $subset = new SubsetResult(false, 'ab', $stamped);
        $equivalence = new EquivalenceResult(false, 'ab', null, $stamped);

        // The stamp names the engine that computed the verdict: a version
        // handed in is kept, not replaced by the running one.
        $this->assertSame($stamped, $intersection->pcreVersion);
        $this->assertSame($stamped, $subset->pcreVersion);
        $this->assertSame($stamped, $equivalence->pcreVersion);
    }
}
