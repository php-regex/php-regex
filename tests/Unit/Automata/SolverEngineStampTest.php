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
use PHPRegex\Automata\Solver\EquivalenceResult;
use PHPRegex\Automata\Solver\IntersectionResult;
use PHPRegex\Automata\Solver\SubsetResult;
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
