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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The lint rules that ask the automata read their questions for the PCRE2
 * release the analysis judges, not for the running one: "a{,2}" repeats
 * from PCRE2 10.43 and is the text "a{,2}" before.
 */
final class AnalysisServiceTargetTest extends TestCase
{
    #[Test]
    #[DataProvider('provideTargets')]
    public function test_the_questions_are_read_for_the_judged_release(string $pcreVersion, bool $impossible): void
    {
        $analysis = new AnalysisService(RegexParser::create(['pcre_version' => $pcreVersion]));

        $issues = $analysis->lint([new PatternOccurrence('/(?=a\{)a{,2}$/', 'file.php', 1, 'preg_match')]);

        $this->assertSame($impossible, \in_array('regex.lint.lookaround.impossible', array_column($issues, 'issueId'), true));
    }

    /**
     * @return iterable<string, array{pcreVersion: string, impossible: bool}>
     */
    public static function provideTargets(): iterable
    {
        yield 'a quantifier from 10.43: at most "aa" before the end' => ['pcreVersion' => '10.43', 'impossible' => true];
        yield 'the text "a{,2}" before 10.43: the lookahead reads its start' => ['pcreVersion' => '10.42', 'impossible' => false];
    }
}
