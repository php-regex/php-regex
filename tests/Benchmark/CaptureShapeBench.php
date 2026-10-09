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
 * Each corpus keeps the patterns the PCRE engine compiles, and only those:
 * the list, and so the work, is the same whatever parser is measured. The
 * before-method reads every pattern once, untimed, with the parser under
 * measurement and errors when it rejects one, so a parser that drops a
 * pattern breaks the variant instead of timing a shorter list. That pass
 * also loads the code; there is no AST cache, but the revolutions after the
 * first find the library's process-wide caches filled, as the measured
 * revolutions of a warmup did. It ends by resetting the peak memory, so
 * mem_peak covers the measured revolutions only (phpbench's own warmup would
 * run after that reset). benchNoop runs the same before-method on the same
 * corpus, so its peak is the floor of that corpus.
 *
 * Run with: composer bench -- --group=capture-shape
 */
#[Groups(['capture-shape'])]
#[Iterations(5)]
#[Revs(3)]
#[Warmup(0)]
#[OutputTimeUnit('milliseconds')]
final class CaptureShapeBench
{
    private const PARITY_CORPUS = __DIR__.'/../Fixtures/CaptureShapeParity/cases.php';

    private const LINT_CORPUS = __DIR__.'/../Fixtures/Corpus/lint-expectations.json';

    private const FLAG_SETS = [0, \PREG_UNMATCHED_AS_NULL, \PREG_OFFSET_CAPTURE, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL];

    private RegexParser $parser;

    private CaptureShapeAnalyzer $analyzer;

    public function __construct()
    {
        $this->parser = RegexParser::create(['cache' => null]);
        $this->analyzer = new CaptureShapeAnalyzer();
    }

    /**
     * The memory floor: the same corpus read once untimed, no work.
     *
     * @param array{patterns: list<string>} $params
     */
    #[BeforeMethods('setUpCorpus')]
    #[ParamProviders('provideCorpora')]
    public function benchNoop(array $params): void {}

    /**
     * @param array{patterns: list<string>} $params
     */
    #[BeforeMethods('setUpCorpus')]
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
     * @param array{patterns: list<string>} $params
     *
     * @throws \UnexpectedValueException when the parser rejects a pattern PCRE compiles
     */
    public function setUpCorpus(array $params): void
    {
        $this->parser = RegexParser::create(['cache' => null]);
        $this->analyzer = new CaptureShapeAnalyzer();

        $rejected = [];
        foreach ($params['patterns'] as $pattern) {
            try {
                $shape = $this->analyzer->analyze($this->parser->parse($pattern));
                foreach (self::FLAG_SETS as $flags) {
                    $shape->matchShape($flags);
                }
            } catch (ExceptionInterface $e) {
                $rejected[] = $pattern.' ('.$e->getMessage().')';
            }
        }

        if ([] !== $rejected) {
            throw new \UnexpectedValueException(\sprintf('The parser rejects %d of %d patterns PCRE compiles: %s', \count($rejected), \count($params['patterns']), implode('; ', \array_slice($rejected, 0, 5))));
        }

        memory_reset_peak_usage();
    }

    /**
     * The parity and the lint corpus, each named for itself: the count of
     * patterns stays out of the name, so both sides of a comparison pair the
     * same variant.
     *
     * @return \Generator<string, array{patterns: list<string>}>
     */
    public function provideCorpora(): \Generator
    {
        /** @var list<array{pattern: string}> $parity */
        $parity = require self::PARITY_CORPUS;
        yield 'parity' => ['patterns' => self::compiled(array_column($parity, 'pattern'))];

        $lint = json_decode((string) file_get_contents(self::LINT_CORPUS), true, 512, \JSON_THROW_ON_ERROR);
        $lintPatterns = [];
        foreach (\is_array($lint) ? $lint : [] as $entry) {
            if (\is_array($entry) && \is_string($entry['pattern'] ?? null)) {
                $lintPatterns[] = $entry['pattern'];
            }
        }
        yield 'lint' => ['patterns' => self::compiled($lintPatterns)];
    }

    /**
     * The patterns the PCRE engine compiles: the one filter both sides share.
     *
     * @param list<mixed> $patterns
     *
     * @return list<string>
     */
    private static function compiled(array $patterns): array
    {
        $compiled = [];
        foreach ($patterns as $pattern) {
            if (\is_string($pattern) && false !== @preg_match($pattern, '')) {
                $compiled[] = $pattern;
            }
        }

        return $compiled;
    }
}
