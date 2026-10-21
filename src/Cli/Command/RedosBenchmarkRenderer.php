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

namespace PHPRegex\Cli\Command;

use PHPRegex\Cli\ConsoleStyle;
use PHPRegex\Cli\Output;
use PHPRegex\Cli\PcreRuntimeInfo;
use PHPRegex\Explain\Highlighter\ConsoleHighlighter;
use PHPRegex\Parser\DelimitedPattern;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\LimitException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Json\JsonDocument;
use PHPRegex\Parser\Syntax\PatternParser;
use PHPRegex\Toolkit\Regex;

/**
 * The report of `regex redos`: the patterns and the subject before the
 * benchmark runs, the table of its runs after, or the JSON document of the
 * whole.
 *
 * @internal
 *
 * @phpstan-import-type BenchResult from RedosBenchmark
 */
final class RedosBenchmarkRenderer
{
    public function __construct(private readonly Output $output) {}

    /**
     * The pattern with its syntax coloured, on an ANSI output; as written
     * otherwise, or when it does not parse.
     */
    public function highlight(Regex $regex, string $pattern): string
    {
        if (!$this->output->isAnsi()) {
            return $pattern;
        }

        try {
            $ast = $regex->parse($pattern);
            $patternInfo = DelimitedPattern::fromDelimited($pattern, $regex->target());
            $highlightedBody = $ast->accept(new ConsoleHighlighter());
            $closingDelimiter = PatternParser::closingDelimiter($patternInfo->delimiter);

            return $patternInfo->delimiter.$highlightedBody.$closingDelimiter.$patternInfo->flags;
        } catch (LexerException|LimitException|ParserException) {
            return $pattern;
        }
    }

    /**
     * What the console shows before the benchmark runs: the banner, the
     * patterns, the subject, and the heading of the benchmark.
     *
     * @param array<string, string> $meta the banner lines
     */
    public function renderPreamble(ConsoleStyle $style, array $meta, string $vulnerable, ?string $safe, RedosArguments $arguments, RedosInput $input): void
    {
        $output = $this->output;
        $style->renderBanner('redos', $meta);

        $steps = 3;
        $style->renderSection('Patterns', 1, $steps);
        $style->renderPattern($vulnerable, 'Vulnerable');
        if (null !== $safe) {
            $style->renderPattern($safe, 'Safe');
        }

        if ($style->visualsEnabled()) {
            $output->write("\n");
        }

        $style->renderSection('Input', 2, $steps);
        $inputRows = [
            'Source' => $output->warning($input->source),
            'Base length' => $output->warning((string) \strlen($input->value)),
            'Final length' => $output->warning((string) \strlen($input->subject)),
            'Iterations' => $output->warning($arguments->iterations.' (warmup '.$arguments->warmup.')'),
        ];
        if (1 !== $arguments->repeat) {
            $inputRows['Repeat'] = $output->warning((string) $arguments->repeat);
        }
        if ('' !== $arguments->prefix || '' !== $arguments->suffix) {
            $inputRows['Prefix/Suffix'] = $output->warning(\strlen($arguments->prefix).'/'.\strlen($arguments->suffix));
        }
        $displayInput = $input->preview($arguments->showInput ? null : RedosInput::PREVIEW_LIMIT);
        if ($arguments->showInput) {
            $inputRows['Input'] = $output->dim('"'.$displayInput.'"');
        } else {
            $inputRows['Preview'] = $output->dim('"'.$displayInput.'"');
        }
        if (null !== $input->note) {
            $inputRows['Note'] = $output->dim($input->note);
        }
        $style->renderKeyValueBlock($inputRows);

        if ($style->visualsEnabled()) {
            $output->write("\n");
        }

        $style->renderSection('Benchmark', 3, $steps);
    }

    /**
     * The table of the runs, and how the safe pattern compares when there
     * is one.
     *
     * @param BenchResult      $vuln
     * @param BenchResult|null $safe
     */
    public function renderResults(array $vuln, ?array $safe): void
    {
        $this->renderTable(null === $safe ? [$vuln] : [$vuln, $safe]);

        if (null !== $safe) {
            $this->renderSummary(RedosBenchmark::summary($vuln, $safe));
        }
    }

    /**
     * The JSON document of the run.
     *
     * @param BenchResult      $vuln
     * @param BenchResult|null $safe
     */
    public function writeDocument(RedosArguments $arguments, PcreRuntimeInfo $runtime, RedosInput $input, array $vuln, ?array $safe): void
    {
        $rows = ['vuln' => $vuln];
        $summary = null;
        if (null !== $safe) {
            $rows['safe'] = $safe;
            $summary = RedosBenchmark::summary($vuln, $safe);
        }

        $this->output->writeDocument(JsonDocument::encode([
            'pattern' => $arguments->pattern,
            'safe_pattern' => $arguments->safePattern,
            'runtime' => $runtime,
            'input' => [
                'source' => $input->source,
                'base_length' => \strlen($input->value),
                'final_length' => \strlen($input->subject),
                'repeat' => $arguments->repeat,
                'prefix' => $arguments->prefix,
                'suffix' => $arguments->suffix,
                'preview' => $arguments->showInput ? null : $input->preview(),
                'value' => $arguments->showInput ? $input->subject : null,
                'note' => $input->note,
            ],
            'settings' => [
                'iterations' => $arguments->iterations,
                'warmup' => $arguments->warmup,
            ],
            'bench' => $rows,
            'summary' => $summary,
        ]));
    }

    /**
     * @param list<BenchResult> $rows
     */
    private function renderTable(array $rows): void
    {
        $output = $this->output;
        $header = \sprintf(
            "%-8s | %-6s | %10s | %10s | %10s | %10s | %10s | %s\n",
            'case',
            'result',
            'wall_ms',
            'avg_ms',
            'cpu_ms',
            'mem_kb',
            'peak_kb',
            'err',
        );
        $output->write($output->dim($header));
        $output->write(str_repeat('-', 92)."\n");

        foreach ($rows as $row) {
            $label = $this->formatCase(str_pad($row['label'], 8), $row['label']);
            $result = $this->formatResult(str_pad($row['result'], 6), $row['result']);
            $wall = str_pad(self::formatMs($row['wall_ms']), 10, ' ', \STR_PAD_LEFT);
            $avg = str_pad(self::formatMs($row['avg_ms']), 10, ' ', \STR_PAD_LEFT);
            $cpu = str_pad(null === $row['cpu_ms'] ? 'n/a' : self::formatMs($row['cpu_ms']), 10, ' ', \STR_PAD_LEFT);
            $mem = str_pad(self::formatKb($row['mem_bytes']), 10, ' ', \STR_PAD_LEFT);
            $peak = str_pad(self::formatKb($row['peak_bytes']), 10, ' ', \STR_PAD_LEFT);
            $err = '-' === $row['err_msg'] ? $row['err_msg'] : $output->error($row['err_msg']);

            $output->write($label.' | '.$result.' | '.$wall.' | '.$avg.' | '.$cpu.' | '.$mem.' | '.$peak.' | '.$err."\n");
        }
    }

    /**
     * @param array{result_parity: string, speedup: ?float, delta_ms: ?float} $summary
     */
    private function renderSummary(array $summary): void
    {
        $output = $this->output;
        $parity = 'same' === $summary['result_parity']
            ? $output->success('same')
            : $output->warning('different');

        $speedupText = 'n/a';
        if (null !== $summary['speedup']) {
            $speedupText = number_format($summary['speedup'], 2).'x';
            $speedupText = $summary['speedup'] >= 1.0
                ? $output->success($speedupText)
                : $output->warning($speedupText);
        }

        $deltaText = 'n/a';
        if (null !== $summary['delta_ms']) {
            $deltaText = self::formatMs($summary['delta_ms']).' ms';
            $deltaText = $summary['delta_ms'] >= 0.0
                ? $output->success($deltaText)
                : $output->warning($deltaText);
        }

        $output->write("\n");
        $output->write('Result parity : '.$parity."\n");
        $output->write('Speedup       : '.$speedupText." (vuln avg / safe avg)\n");
        $output->write('Delta avg     : '.$deltaText." (vuln - safe)\n");
    }

    private function formatCase(string $label, string $raw): string
    {
        return match ($raw) {
            'vuln' => $this->output->error($label),
            'safe' => $this->output->success($label),
            default => $label,
        };
    }

    private function formatResult(string $label, string $raw): string
    {
        return match ($raw) {
            'match' => $this->output->success($label),
            'no' => $this->output->warning($label),
            'error' => $this->output->error($label),
            default => $label,
        };
    }

    private static function formatMs(float $ms): string
    {
        return number_format($ms, 2);
    }

    private static function formatKb(int $bytes): string
    {
        return \sprintf('%+.1f', $bytes / 1024);
    }
}
