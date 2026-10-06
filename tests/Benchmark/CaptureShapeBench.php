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

namespace PHPRegex\Tests\Benchmark;

use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\OutputTimeUnit;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\RegexParser;

/**
 * The capture shape from the pattern string: parse, analyze, then write the
 * shape for the four flag sets, with no parse cache. One revolution reads a
 * whole corpus; the mean divided by the corpus size is the time per pattern.
 *
 * Run with: tools/phpbench/vendor/bin/phpbench run tests/Benchmark/CaptureShapeBench.php --report=default
 */
#[Groups(['capture-shape'])]
#[Iterations(5)]
#[Revs(3)]
#[Warmup(1)]
#[OutputTimeUnit('milliseconds')]
#[BeforeMethods('setUp')]
final class CaptureShapeBench
{
    private const PARITY_CORPUS = __DIR__.'/../Fixtures/CaptureShapeParity/cases.php';

    private const LINT_CORPUS = __DIR__.'/../Fixtures/Corpus/lint-expectations.json';

    private const FLAG_SETS = [0, \PREG_UNMATCHED_AS_NULL, \PREG_OFFSET_CAPTURE, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL];

    private RegexParser $parser;

    private CaptureShapeAnalyzer $analyzer;

    public function setUp(): void
    {
        $this->parser = RegexParser::create(['cache' => null]);
        $this->analyzer = new CaptureShapeAnalyzer();
    }

    /**
     * @param array{patterns: list<string>} $params
     */
    #[ParamProviders('provideCorpora')]
    public function benchMatchShape(array $params): void
    {
        foreach ($params['patterns'] as $pattern) {
            $shape = $this->analyzer->analyze($this->parser->parse($pattern));
            foreach (self::FLAG_SETS as $flags) {
                $shape->matchShape($flags);
            }
        }
    }

    /**
     * Each corpus keeps the patterns the parser reads; the key says how many.
     *
     * @return \Generator<string, array{patterns: list<string>}>
     */
    public function provideCorpora(): \Generator
    {
        /** @var list<array{pattern: string}> $parity */
        $parity = require self::PARITY_CORPUS;
        $patterns = self::readable(array_column($parity, 'pattern'));
        yield \sprintf('parity corpus (%d patterns)', \count($patterns)) => ['patterns' => $patterns];

        $lint = json_decode((string) file_get_contents(self::LINT_CORPUS), true, 512, \JSON_THROW_ON_ERROR);
        $lintPatterns = [];
        foreach (\is_array($lint) ? $lint : [] as $entry) {
            if (\is_array($entry) && \is_string($entry['pattern'] ?? null)) {
                $lintPatterns[] = $entry['pattern'];
            }
        }
        $patterns = self::readable($lintPatterns);
        yield \sprintf('lint corpus (%d patterns)', \count($patterns)) => ['patterns' => $patterns];
    }

    /**
     * @param list<mixed> $patterns
     *
     * @return list<string>
     */
    private static function readable(array $patterns): array
    {
        $parser = RegexParser::create(['cache' => null]);
        $analyzer = new CaptureShapeAnalyzer();
        $readable = [];
        foreach ($patterns as $pattern) {
            if (!\is_string($pattern)) {
                continue;
            }

            try {
                $analyzer->analyze($parser->parse($pattern))->matchShape();
            } catch (ExceptionInterface) {
                continue;
            }
            $readable[] = $pattern;
        }

        return $readable;
    }
}
