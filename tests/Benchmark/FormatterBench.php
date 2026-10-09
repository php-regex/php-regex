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
use PHPRegex\Linter\Diagnostic;
use PHPRegex\Linter\DiagnosticType;
use PHPRegex\Linter\Formatter\CheckstyleFormatter;
use PHPRegex\Linter\Formatter\ConsoleFormatter;
use PHPRegex\Linter\Formatter\GithubFormatter;
use PHPRegex\Linter\Formatter\JsonFormatter;
use PHPRegex\Linter\Formatter\JunitFormatter;
use PHPRegex\Linter\Formatter\OutputConfiguration;
use PHPRegex\Linter\Formatter\OutputFormatterInterface;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Optimizer\OptimizationResult;

/**
 * Writing a lint report of a thousand results, one issue, one optimization
 * and one diagnostic each, in every output format. The formatters keep no
 * cache: the before-method formats the report once, untimed, only to load
 * the code, then resets the peak memory, so mem_peak covers the measured run
 * only (phpbench's own warmup would run after that reset). benchNoop runs the
 * same before-method for each format, so its peak is the floor of that
 * format.
 *
 * Run with: composer bench -- --group=formatter
 */
#[Groups(['formatter'])]
#[Revs(1)]
#[Iterations(10)]
#[Warmup(0)]
#[OutputTimeUnit('milliseconds')]
final class FormatterBench
{
    private const RESULTS = 1000;

    private ?LintReport $report = null;

    private ?OutputFormatterInterface $formatter = null;

    /**
     * The memory floor: the same report and formatter built and the code
     * loaded, no work.
     *
     * @param array{format: string} $params
     */
    #[BeforeMethods('setUpFormatter')]
    #[ParamProviders('provideFormats')]
    public function benchNoop(array $params): void {}

    /**
     * @param array{format: string} $params
     */
    #[BeforeMethods('setUpFormatter')]
    #[ParamProviders('provideFormats')]
    public function benchFormat(array $params): void
    {
        if (null === $this->formatter || null === $this->report) {
            throw new \LogicException('The formatter and the report are built before the run.');
        }

        $this->formatter->format($this->report);
    }

    /**
     * @param array{format: string} $params
     */
    public function setUpFormatter(array $params): void
    {
        $this->report = self::report(self::RESULTS);
        $this->formatter = match ($params['format']) {
            'console' => new ConsoleFormatter(null, new OutputConfiguration(ansi: false)),
            'github' => new GithubFormatter(),
            'json' => new JsonFormatter(),
            'checkstyle' => new CheckstyleFormatter(),
            'junit' => new JunitFormatter(),
            default => throw new \OutOfBoundsException(\sprintf('No format "%s".', $params['format'])),
        };
        $this->benchFormat($params);
        memory_reset_peak_usage();
    }

    /**
     * @return array<string, array{format: string}>
     */
    public function provideFormats(): array
    {
        $params = [];
        foreach (['console', 'github', 'json', 'checkstyle', 'junit'] as $format) {
            $params[$format] = ['format' => $format];
        }

        return $params;
    }

    private static function report(int $count): LintReport
    {
        $results = [];
        $errors = 0;
        $warnings = 0;

        $pattern = '/(foo|bar)+/';
        $optimization = new OptimizationResult($pattern, '/(?:foo|bar)+/', ['group']);
        $problem = new Diagnostic(
            DiagnosticType::Lint,
            LintSeverity::Warning,
            'Nested quantifier detected',
            'regex.lint.quantifier.nested',
            1,
            '^',
            'Consider making the quantifier possessive',
        );

        for ($i = 1; $i <= $count; $i++) {
            $type = 0 === $i % 2 ? 'warning' : 'error';
            if ('error' === $type) {
                $errors++;
            } else {
                $warnings++;
            }

            $file = 'src/File'.$i.'.php';
            $results[] = [
                'file' => $file,
                'line' => $i,
                'source' => 'preg_match',
                'pattern' => $pattern,
                'location' => 'in function call',
                'issues' => [[
                    'type' => $type,
                    'message' => 'Issue '.$i,
                    'file' => $file,
                    'line' => $i,
                    'issueId' => 'regex.lint.demo',
                    'hint' => 'Use a non-capturing group',
                ]],
                'optimizations' => [[
                    'file' => $file,
                    'line' => $i,
                    'optimization' => $optimization,
                    'savings' => 1,
                    'source' => 'preg_match',
                ]],
                'problems' => [$problem],
            ];
        }

        return new LintReport($results, ['errors' => $errors, 'warnings' => $warnings, 'optimizations' => $count]);
    }
}
