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

use PhpParser\ParserFactory;
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintRunner;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\Parallel\ForkedWorkerPool;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PhpFilePatternSource;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\ArgumentCarriers;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every pattern argument a run anchors ends in one state, and the stats
 * of the run count them: the five counts sum to the arguments, whichever
 * strategy reads the files and however many workers share them.
 *
 * The unit is the argument, not the pattern: each item of an array
 * written at the call is one, an array read through a name is one per
 * item, a constant two calls read is two arguments and one pattern.
 */
final class ArgumentStateCountsTest extends TestCase
{
    use TemporaryProject;

    /**
     * Seventeen arguments: eight end in one pattern (a literal, a
     * constant, a variable, a concatenation of two literals, the two items
     * of an array written at the call and the two of an array constant),
     * two in a set (a ternary, a constant of PHP that depends on the
     * system), two around preg_quote(), two around another part built at
     * run time, three with nothing static.
     */
    private const SHAPES = <<<'PHP'
        <?php

        namespace App;

        final class Shapes
        {
            private const WORD = '/^\w+$/';
            private const SEPARATORS = ['/,/', '/;/'];

            /**
             * @return list<mixed>
             */
            public function each(string $s, string $word, string $re, bool $strict, int $n): array
            {
                $local = '/^local$/';
                $prefix = '/^pre';

                return [
                    preg_match('/^literal$/', $s),
                    preg_match(self::WORD, $s),
                    preg_match($local, $s),
                    preg_match($prefix . 'fix$/', $s),
                    preg_match($strict ? '/^strict$/' : '/^loose$/', $s),
                    preg_match('~^' . DIRECTORY_SEPARATOR . 'tmp~', $s),
                    preg_replace(['/a/', '/b/'], '', $s),
                    preg_replace(self::SEPARATORS, ' ', $s),
                    preg_match('/^' . preg_quote($word, '/') . '$/', $s),
                    preg_match(sprintf('/^%s$/', preg_quote($word, '/')), $s),
                    preg_match('/^' . $word . '$/', $s),
                    preg_match("/^{$word}{$n}$/", $s),
                    preg_match($re, $s),
                    preg_match($this->pattern(), $s),
                    preg_split($re . 'x', $s),
                ];
            }

            private function pattern(): string
            {
                return '/x/';
            }
        }

        PHP;

    /**
     * Two more, in a file of its own: one pattern, and nothing static.
     */
    private const CALLS = "<?php\n\npreg_match('/^a\$/', \$s);\npreg_match(\$re, \$s);\n";

    private const COUNTS = ['constant' => 9, 'finite_set' => 2, 'quoted_shell' => 2, 'raw_shell' => 2, 'opaque' => 4];

    /**
     * @return iterable<string, array{strategy: class-string<ExtractorInterface>, workers: int}>
     */
    public static function provideStrategiesAndWorkers(): iterable
    {
        foreach ([1, 2] as $workers) {
            yield 'tokens, '.$workers.' worker(s)' => ['strategy' => TokenBasedExtractionStrategy::class, 'workers' => $workers];
            if (class_exists(ParserFactory::class)) {
                yield 'php-parser, '.$workers.' worker(s)' => ['strategy' => PhpParserExtractionStrategy::class, 'workers' => $workers];
            }
        }
    }

    /**
     * @param class-string<ExtractorInterface> $strategy
     */
    #[Test]
    #[DataProvider('provideStrategiesAndWorkers')]
    public function test_every_argument_has_one_state(string $strategy, int $workers): void
    {
        if ($workers > 1 && !ForkedWorkerPool::canFork()) {
            $workers = 1;
        }

        $project = $this->makeProject(['src/Shapes.php' => self::SHAPES, 'src/calls.php' => self::CALLS]);
        $runner = new LintRunner(new LintService(
            new AnalysisService(RegexParser::create(['cache' => null])),
            new PatternSourceCollection([new PhpFilePatternSource(new PatternExtractor(new $strategy([], PatternFunctionRegistry::native())))]),
        ));
        $request = new LintRequest([$project.'/src'], [], 0, checkOptimizations: false, analysisWorkers: $workers);

        $collected = $runner->collect($request);
        $arguments = array_values(array_filter($collected, static fn (PatternOccurrence $occurrence): bool => null !== $occurrence->state));
        $this->assertCount(19, $arguments);
        $this->assertSame(self::COUNTS, self::tally($arguments));

        $counts = ArgumentCarriers::counts($runner->analyze($collected, $request)->stats);

        $this->assertSame(self::COUNTS, $counts);
        $this->assertSame(\count($arguments), array_sum($counts));
    }

    /**
     * A file that holds no call reading a pattern anchors no argument:
     * the five counts are there, all zero.
     *
     * @param class-string<ExtractorInterface> $strategy
     */
    #[Test]
    #[DataProvider('provideStrategiesAndWorkers')]
    public function test_a_run_that_anchors_no_argument_counts_none(string $strategy, int $workers): void
    {
        if ($workers > 1 && !ForkedWorkerPool::canFork()) {
            $workers = 1;
        }

        $project = $this->makeProject(['src/plain.php' => "<?php\n\necho strlen('/not a pattern/');\n"]);
        $runner = new LintRunner(new LintService(
            new AnalysisService(RegexParser::create(['cache' => null])),
            new PatternSourceCollection([new PhpFilePatternSource(new PatternExtractor(new $strategy([], PatternFunctionRegistry::native())))]),
        ));
        $request = new LintRequest([$project.'/src'], [], 0, checkOptimizations: false, analysisWorkers: $workers);

        $counts = ArgumentCarriers::counts($runner->analyze($runner->collect($request), $request)->stats);

        $this->assertSame(ArgumentCarriers::none(), $counts);
    }

    /**
     * The arguments by state, in the order of the states.
     *
     * @param list<PatternOccurrence> $arguments
     *
     * @return array<string, int>
     */
    private static function tally(array $arguments): array
    {
        $tally = ArgumentCarriers::none();
        foreach ($arguments as $argument) {
            self::assertArrayHasKey((string) $argument->state, $tally);
            $tally[(string) $argument->state]++;
        }

        return $tally;
    }
}
