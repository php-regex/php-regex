<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Linter\PatternLinter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the linter says about the patterns of real projects.
 *
 * The expectations are a committed fixture, not something read back out of
 * the rendered report: deriving them from the report meant re-implementing
 * the rules to decide which warnings still applied, and a rule could then
 * never disagree with itself. A change in what the linter reports now shows
 * up as a diff to tests/Fixtures/Corpus/lint-expectations.json, which
 * tests/Tools/generate_corpus_lint_expectations.php rebuilds.
 */
final class LinterNodeVisitorCorpusTest extends TestCase
{
    /**
     * @param array<int, string> $issues
     */
    #[Test]
    #[DataProvider('provideCorpusPatterns')]
    public function test_a_corpus_pattern_raises_the_rules_it_used_to(string $pattern, array $issues): void
    {
        $visitor = new PatternLinter();
        Regex::create(['max_recursion_depth' => 4096])->parse($pattern)->accept($visitor);

        $reported = array_values(array_unique(array_map(
            static fn (object $issue): string => $issue->id,
            $visitor->getIssues(),
        )));
        sort($reported);

        $this->assertSame($issues, $reported, 'Reported rules changed for '.$pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, issues: array<int, string>}>
     */
    public static function provideCorpusPatterns(): iterable
    {
        $path = \dirname(__DIR__, 2).'/Fixtures/Corpus/lint-expectations.json';
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new \RuntimeException(\sprintf('Missing corpus fixture at %s', $path));
        }

        /** @var array<int, array{pattern: string, issues: array<int, string>}> $cases */
        $cases = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);

        foreach ($cases as $index => $case) {
            yield \sprintf('%d: %s', $index, $case['pattern']) => $case;
        }
    }
}
