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
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Output;
use PHPRegex\Cli\PcreRuntimeInfo;
use PHPRegex\Explain\Highlighter\ConsoleHighlighter;
use PHPRegex\Parser\DelimitedPattern;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Internal\Ascii;
use PHPRegex\Parser\Internal\DisplayEscaper;
use PHPRegex\Parser\Internal\PatternParser;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Redos\Internal\InputGenerator;
use PHPRegex\Toolkit\Regex;

/**
 * @internal
 */
final class RedosCommand extends AbstractCommand
{
    private const PREVIEW_LIMIT = 120;

    /**
     * What a run may change, and puts back.
     */
    private const RUNTIME_SETTINGS = ['pcre.jit', 'pcre.backtrack_limit', 'pcre.recursion_limit', 'max_execution_time'];

    public function getName(): string
    {
        return 'redos';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return 'Benchmark regex patterns for ReDoS behavior';
    }

    public function run(Input $input, Output $output): int
    {
        // The benchmark runs the pattern with the JIT on purpose, unless
        // "--jit 0" says otherwise: it measures what production sees. So it
        // runs the pattern itself, not through the engine, and gives the
        // process back as it found it on every way out.
        $saved = [];
        foreach (self::RUNTIME_SETTINGS as $key) {
            $saved[$key] = \ini_get($key);
        }

        try {
            return $this->benchmark($input, $output);
        } finally {
            foreach ($saved as $key => $value) {
                if (false === $value || \ini_get($key) === $value) {
                    continue;
                }

                if ('max_execution_time' === $key) {
                    // Setting the time limit again restarts its count.
                    set_time_limit((int) $value);

                    continue;
                }

                ini_set($key, $value);
            }
        }
    }

    private function benchmark(Input $input, Output $output): int
    {
        $parsed = $this->parseArguments($input->args);
        if (null !== $parsed['error']) {
            $output->write($output->error('Error: '.$parsed['error']."\n"));
            $output->write("Usage: regex redos <pattern> [--safe <pattern>] [--input <string> | --input-file <path>] [--repeat <n>] [--prefix <string>] [--suffix <string>] [--iterations <n>] [--warmup <n>] [--jit 0|1] [--backtrack-limit <n>] [--recursion-limit <n>] [--time-limit <sec>] [--format=json] [--show-input]\n");

            return self::INVALID;
        }

        $pattern = $parsed['pattern'];
        $safePattern = $parsed['safePattern'];
        $inputValue = $parsed['inputValue'];
        $inputFile = $parsed['inputFile'];
        $repeat = $parsed['repeat'];
        $prefix = $parsed['prefix'];
        $suffix = $parsed['suffix'];
        $iterations = $parsed['iterations'];
        $warmup = $parsed['warmup'];
        $jit = $parsed['jit'];
        $backtrackLimit = $parsed['backtrackLimit'];
        $recursionLimit = $parsed['recursionLimit'];
        $timeLimit = $parsed['timeLimit'];
        $format = $parsed['format'];
        $showInput = $parsed['showInput'];

        $regex = $this->createRegex($output, $input->regexOptions);
        if (null === $regex) {
            return self::INVALID;
        }

        $target = $regex->target();

        if (null !== $jit) {
            ini_set('pcre.jit', $jit);
        }
        if (null !== $backtrackLimit) {
            ini_set('pcre.backtrack_limit', (string) $backtrackLimit);
        }
        if (null !== $recursionLimit) {
            ini_set('pcre.recursion_limit', (string) $recursionLimit);
        }
        if (null !== $timeLimit && $timeLimit > 0) {
            set_time_limit($timeLimit);
        }

        $runtime = PcreRuntimeInfo::fromIni();

        $inputSource = 'user';
        $inputNote = null;
        if (null !== $inputFile) {
            $inputValue = is_file($inputFile) && is_readable($inputFile) ? @file_get_contents($inputFile) : false;
            if (false === $inputValue) {
                $output->write($output->error("Error: Input file not readable: {$inputFile}\n"));

                return self::INVALID;
            }
            $inputSource = 'file';
        }

        if (null === $inputValue) {
            [$inputValue, $inputSource, $inputNote] = $this->generateInput($regex, $pattern, $target);
        }

        $baseLength = \strlen($inputValue);
        $subject = $prefix.str_repeat($inputValue, $repeat).$suffix;
        $finalLength = \strlen($subject);

        $style = new ConsoleStyle($output, $input->globalOptions->visuals);
        $meta = [];
        $meta += $this->targetMeta($input, $output);
        $meta['PCRE'] = $output->warning($runtime->version);
        $meta['PCRE JIT'] = $output->warning($runtime->jitSetting ?? 'unknown');
        $meta['Backtrack'] = $output->warning((string) ($runtime->backtrackLimit ?? 'unknown'));
        $meta['Recursion'] = $output->warning((string) ($runtime->recursionLimit ?? 'unknown'));

        $highlightedVuln = $this->highlightPattern($regex, $output, $pattern, $target);
        $highlightedSafe = null;
        if (null !== $safePattern && '' !== $safePattern) {
            $highlightedSafe = $this->highlightPattern($regex, $output, $safePattern, $target);
        }

        if ('json' !== $format) {
            $style->renderBanner('redos', $meta);

            $steps = 3;
            $style->renderSection('Patterns', 1, $steps);
            $style->renderPattern($highlightedVuln, 'Vulnerable');
            if (null !== $highlightedSafe) {
                $style->renderPattern($highlightedSafe, 'Safe');
            }

            if ($style->visualsEnabled()) {
                $output->write("\n");
            }

            $style->renderSection('Input', 2, $steps);
            $inputRows = [
                'Source' => $output->warning($inputSource),
                'Base length' => $output->warning((string) $baseLength),
                'Final length' => $output->warning((string) $finalLength),
                'Iterations' => $output->warning($iterations.' (warmup '.$warmup.')'),
            ];
            if (1 !== $repeat) {
                $inputRows['Repeat'] = $output->warning((string) $repeat);
            }
            if ('' !== $prefix || '' !== $suffix) {
                $inputRows['Prefix/Suffix'] = $output->warning(\strlen($prefix).'/'.\strlen($suffix));
            }
            $displayInput = $this->formatInput($subject, $showInput ? null : self::PREVIEW_LIMIT);
            if ($showInput) {
                $inputRows['Input'] = $output->dim('"'.$displayInput.'"');
            } else {
                $inputRows['Preview'] = $output->dim('"'.$displayInput.'"');
            }
            if (null !== $inputNote) {
                $inputRows['Note'] = $output->dim($inputNote);
            }
            $style->renderKeyValueBlock($inputRows);

            if ($style->visualsEnabled()) {
                $output->write("\n");
            }

            $style->renderSection('Benchmark', 3, $steps);
        }

        $rows = [];
        $rows['vuln'] = $this->bench('vuln', $pattern, $subject, $warmup, $iterations);
        if (null !== $safePattern && '' !== $safePattern) {
            $rows['safe'] = $this->bench('safe', $safePattern, $subject, $warmup, $iterations);
        }

        // A pattern PHP refuses is a problem of the pattern, whatever the
        // benchmark measured.
        $verdict = $this->refuses($pattern) || (isset($rows['safe']) && null !== $safePattern && $this->refuses($safePattern))
            ? self::FAILURE
            : self::SUCCESS;

        if ('json' === $format) {
            $summary = null;
            if (isset($rows['safe'])) {
                $summary = $this->buildSummary($rows['vuln'], $rows['safe']);
            }
            $payload = [
                'pattern' => $pattern,
                'safe_pattern' => $safePattern,
                'runtime' => $runtime,
                'input' => [
                    'source' => $inputSource,
                    'base_length' => $baseLength,
                    'final_length' => $finalLength,
                    'repeat' => $repeat,
                    'prefix' => $prefix,
                    'suffix' => $suffix,
                    'preview' => $showInput ? null : $this->formatInput($subject, self::PREVIEW_LIMIT),
                    'value' => $showInput ? $subject : null,
                    'note' => $inputNote,
                ],
                'settings' => [
                    'iterations' => $iterations,
                    'warmup' => $warmup,
                ],
                'bench' => $rows,
                'summary' => $summary,
            ];

            $json = json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
            if (false === $json) {
                $output->write($output->error("Error: Failed to encode JSON\n"));

                return self::FAILURE;
            }
            $output->write($json."\n");

            return $verdict;
        }

        $this->renderBenchmarkTable($output, $rows);

        if (isset($rows['safe'])) {
            $this->renderSummary($output, $rows['vuln'], $rows['safe']);
        }

        return $verdict;
    }

    /**
     * Whether PHP refuses to compile the pattern: the call fails with a
     * warning, which a failure while matching never raises.
     */
    private function refuses(string $pattern): bool
    {
        $warned = false;
        set_error_handler(static function () use (&$warned): bool {
            $warned = true;

            return true;
        });

        try {
            $result = preg_match($pattern, '');
        } finally {
            restore_error_handler();
        }

        return false === $result && $warned;
    }

    /**
     * @param array<int, string> $args
     *
     * @return array{
     *     pattern: string,
     *     safePattern: ?string,
     *     inputValue: ?string,
     *     inputFile: ?string,
     *     repeat: int,
     *     prefix: string,
     *     suffix: string,
     *     iterations: int,
     *     warmup: int,
     *     jit: ?string,
     *     backtrackLimit: ?int,
     *     recursionLimit: ?int,
     *     timeLimit: ?int,
     *     format: string,
     *     showInput: bool,
     *     error: ?string
     * }
     */
    private function parseArguments(array $args): array
    {
        $pattern = '';
        $safePattern = null;
        $inputValue = null;
        $inputFile = null;
        $repeat = 1;
        $prefix = '';
        $suffix = '';
        $iterations = 1;
        $warmup = 0;
        $jit = null;
        $backtrackLimit = null;
        $recursionLimit = null;
        $timeLimit = null;
        $format = 'console';
        $showInput = false;
        $stopParsing = false;

        for ($i = 0; $i < \count($args); $i++) {
            $arg = $args[$i];

            if (!$stopParsing && '--' === $arg) {
                $stopParsing = true;

                continue;
            }

            if (!$stopParsing && ('--json' === $arg)) {
                $format = 'json';

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--format=')) {
                $format = strtolower(substr($arg, \strlen('--format=')));

                continue;
            }

            if (!$stopParsing && '--format' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return $this->errorPayload('Missing value for --format.');
                }
                $format = strtolower($value);
                $i++;

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--safe=')) {
                $safePattern = substr($arg, \strlen('--safe='));

                continue;
            }

            if (!$stopParsing && '--safe' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return $this->errorPayload('Missing value for --safe.');
                }
                $safePattern = $value;
                $i++;

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--input=')) {
                $inputValue = substr($arg, \strlen('--input='));

                continue;
            }

            if (!$stopParsing && '--input' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return $this->errorPayload('Missing value for --input.');
                }
                $inputValue = $value;
                $i++;

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--input-file=')) {
                $inputFile = substr($arg, \strlen('--input-file='));

                continue;
            }

            if (!$stopParsing && '--input-file' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return $this->errorPayload('Missing value for --input-file.');
                }
                $inputFile = $value;
                $i++;

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--repeat=')) {
                $value = substr($arg, \strlen('--repeat='));
                $repeatValue = $this->parseIntOption($value, 1);
                if (null === $repeatValue) {
                    return $this->errorPayload('Invalid value for --repeat.');
                }
                $repeat = $repeatValue;

                continue;
            }

            if (!$stopParsing && '--repeat' === $arg) {
                $value = $args[$i + 1] ?? '';
                $repeatValue = $this->parseIntOption($value, 1);
                if (null === $repeatValue) {
                    return $this->errorPayload('Missing or invalid value for --repeat.');
                }
                $repeat = $repeatValue;
                $i++;

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--prefix=')) {
                $prefix = substr($arg, \strlen('--prefix='));

                continue;
            }

            if (!$stopParsing && '--prefix' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return $this->errorPayload('Missing value for --prefix.');
                }
                $prefix = $value;
                $i++;

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--suffix=')) {
                $suffix = substr($arg, \strlen('--suffix='));

                continue;
            }

            if (!$stopParsing && '--suffix' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return $this->errorPayload('Missing value for --suffix.');
                }
                $suffix = $value;
                $i++;

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--iterations=')) {
                $value = substr($arg, \strlen('--iterations='));
                $iterationsValue = $this->parseIntOption($value, 1);
                if (null === $iterationsValue) {
                    return $this->errorPayload('Invalid value for --iterations.');
                }
                $iterations = $iterationsValue;

                continue;
            }

            if (!$stopParsing && '--iterations' === $arg) {
                $value = $args[$i + 1] ?? '';
                $iterationsValue = $this->parseIntOption($value, 1);
                if (null === $iterationsValue) {
                    return $this->errorPayload('Missing or invalid value for --iterations.');
                }
                $iterations = $iterationsValue;
                $i++;

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--warmup=')) {
                $value = substr($arg, \strlen('--warmup='));
                $warmupValue = $this->parseIntOption($value, 0);
                if (null === $warmupValue) {
                    return $this->errorPayload('Invalid value for --warmup.');
                }
                $warmup = $warmupValue;

                continue;
            }

            if (!$stopParsing && '--warmup' === $arg) {
                $value = $args[$i + 1] ?? '';
                $warmupValue = $this->parseIntOption($value, 0);
                if (null === $warmupValue) {
                    return $this->errorPayload('Missing or invalid value for --warmup.');
                }
                $warmup = $warmupValue;
                $i++;

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--jit=')) {
                $value = substr($arg, \strlen('--jit='));
                if (!\in_array($value, ['0', '1'], true)) {
                    return $this->errorPayload('Invalid value for --jit.');
                }
                $jit = $value;

                continue;
            }

            if (!$stopParsing && '--jit' === $arg) {
                $value = $args[$i + 1] ?? '';
                if (!\in_array($value, ['0', '1'], true)) {
                    return $this->errorPayload('Missing or invalid value for --jit.');
                }
                $jit = $value;
                $i++;

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--backtrack-limit=')) {
                $value = substr($arg, \strlen('--backtrack-limit='));
                $limit = $this->parseIntOption($value, 1);
                if (null === $limit) {
                    return $this->errorPayload('Invalid value for --backtrack-limit.');
                }
                $backtrackLimit = $limit;

                continue;
            }

            if (!$stopParsing && '--backtrack-limit' === $arg) {
                $value = $args[$i + 1] ?? '';
                $limit = $this->parseIntOption($value, 1);
                if (null === $limit) {
                    return $this->errorPayload('Missing or invalid value for --backtrack-limit.');
                }
                $backtrackLimit = $limit;
                $i++;

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--recursion-limit=')) {
                $value = substr($arg, \strlen('--recursion-limit='));
                $limit = $this->parseIntOption($value, 1);
                if (null === $limit) {
                    return $this->errorPayload('Invalid value for --recursion-limit.');
                }
                $recursionLimit = $limit;

                continue;
            }

            if (!$stopParsing && '--recursion-limit' === $arg) {
                $value = $args[$i + 1] ?? '';
                $limit = $this->parseIntOption($value, 1);
                if (null === $limit) {
                    return $this->errorPayload('Missing or invalid value for --recursion-limit.');
                }
                $recursionLimit = $limit;
                $i++;

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--time-limit=')) {
                $value = substr($arg, \strlen('--time-limit='));
                $limit = $this->parseIntOption($value, 0);
                if (null === $limit) {
                    return $this->errorPayload('Invalid value for --time-limit.');
                }
                $timeLimit = $limit;

                continue;
            }

            if (!$stopParsing && '--time-limit' === $arg) {
                $value = $args[$i + 1] ?? '';
                $limit = $this->parseIntOption($value, 0);
                if (null === $limit) {
                    return $this->errorPayload('Missing or invalid value for --time-limit.');
                }
                $timeLimit = $limit;
                $i++;

                continue;
            }

            if (!$stopParsing && '--show-input' === $arg) {
                $showInput = true;

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '-')) {
                return $this->errorPayload('Unknown option: '.$arg);
            }

            if ('' === $pattern) {
                $pattern = $arg;

                continue;
            }

            return $this->errorPayload('Unexpected argument: '.$arg);
        }

        if ('' === $pattern) {
            return $this->errorPayload('Missing pattern.');
        }

        if (null !== $inputValue && null !== $inputFile) {
            return $this->errorPayload('Use only one of --input or --input-file.');
        }

        if (!\in_array($format, ['console', 'json'], true)) {
            return $this->errorPayload('Invalid value for --format.');
        }

        return [
            'pattern' => $pattern,
            'safePattern' => $safePattern,
            'inputValue' => $inputValue,
            'inputFile' => $inputFile,
            'repeat' => $repeat,
            'prefix' => $prefix,
            'suffix' => $suffix,
            'iterations' => $iterations,
            'warmup' => $warmup,
            'jit' => $jit,
            'backtrackLimit' => $backtrackLimit,
            'recursionLimit' => $recursionLimit,
            'timeLimit' => $timeLimit,
            'format' => $format,
            'showInput' => $showInput,
            'error' => null,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: ?string}
     */
    private function generateInput(Regex $regex, string $pattern, PcreTarget $target): array
    {
        try {
            $analysis = $regex->redos($pattern);
            $culprit = $analysis->getCulpritNode();
            if (null !== $culprit) {
                $patternInfo = DelimitedPattern::fromDelimited($pattern, $target);
                $generated = (new InputGenerator())->generate($culprit, $patternInfo->flags, $analysis->severity);
                if ('' !== $generated) {
                    return [$generated, 'auto', null];
                }
            }
        } catch (\Throwable) {
            // Fall back to default input.
        }

        return ['a!', 'default', 'Auto input unavailable; using default.'];
    }

    private function highlightPattern(Regex $regex, Output $output, string $pattern, PcreTarget $target): string
    {
        if (!$output->isAnsi()) {
            return $pattern;
        }

        try {
            $ast = $regex->parse($pattern);
            $patternInfo = DelimitedPattern::fromDelimited($pattern, $target);
            $highlightedBody = $ast->accept(new ConsoleHighlighter());
            $closingDelimiter = PatternParser::closingDelimiter($patternInfo->delimiter);

            return $patternInfo->delimiter.$highlightedBody.$closingDelimiter.$patternInfo->flags;
        } catch (LexerException|ParserException) {
            return $pattern;
        }
    }

    /**
     * @return array{
     *     label: string,
     *     result: string,
     *     wall_ms: float,
     *     avg_ms: float,
     *     cpu_ms: ?float,
     *     mem_bytes: int,
     *     peak_bytes: int,
     *     err_msg: string,
     *     err_code: int,
     *     iterations: int
     * }
     */
    private function bench(string $label, string $pattern, string $subject, int $warmup, int $iterations): array
    {
        // A pattern PHP refuses warns on every call: its error is read from
        // preg_last_error() below.
        set_error_handler(static fn (): bool => true);

        try {
            return $this->measure($label, $pattern, $subject, $warmup, $iterations);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return array{
     *     label: string,
     *     result: string,
     *     wall_ms: float,
     *     avg_ms: float,
     *     cpu_ms: ?float,
     *     mem_bytes: int,
     *     peak_bytes: int,
     *     err_msg: string,
     *     err_code: int,
     *     iterations: int
     * }
     */
    private function measure(string $label, string $pattern, string $subject, int $warmup, int $iterations): array
    {
        for ($i = 0; $i < $warmup; $i++) {
            preg_match($pattern, $subject);
        }

        $usageStart = \function_exists('getrusage') ? (array) getrusage() : null;
        $memStart = memory_get_usage(true);
        $peakStart = memory_get_peak_usage(true);

        $t0 = hrtime(true);
        $result = false;
        $errCode = \PREG_NO_ERROR;
        $iterationsRun = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $iterationsRun++;
            $result = preg_match($pattern, $subject);
            $errCode = preg_last_error();

            if (false === $result && \PREG_NO_ERROR !== $errCode) {
                break;
            }
        }

        $wallMs = (hrtime(true) - $t0) / 1e6;
        $usageEnd = \function_exists('getrusage') ? (array) getrusage() : null;
        $memEnd = memory_get_usage(true);
        $peakEnd = memory_get_peak_usage(true);

        $cpuMs = null;
        if (\is_array($usageStart) && \is_array($usageEnd)) {
            $cpuMs = $this->usageToMs($usageEnd, 'ru_utime') - $this->usageToMs($usageStart, 'ru_utime');
            $cpuMs += $this->usageToMs($usageEnd, 'ru_stime') - $this->usageToMs($usageStart, 'ru_stime');
        }

        $errMsg = '-';
        if (\PREG_NO_ERROR !== $errCode) {
            $errMsg = \function_exists('preg_last_error_msg') ? preg_last_error_msg() : (string) $errCode;
        }

        return [
            'label' => $label,
            'result' => $this->matchResultName($result),
            'wall_ms' => $wallMs,
            'avg_ms' => $wallMs / max(1, $iterationsRun),
            'cpu_ms' => $cpuMs,
            'mem_bytes' => $memEnd - $memStart,
            'peak_bytes' => $peakEnd - $peakStart,
            'err_msg' => $errMsg,
            'err_code' => $errCode,
            'iterations' => $iterationsRun,
        ];
    }

    /**
     * @param array{
     *     label: string,
     *     result: string,
     *     wall_ms: float,
     *     avg_ms: float,
     *     cpu_ms: ?float,
     *     mem_bytes: int,
     *     peak_bytes: int,
     *     err_msg: string,
     *     err_code: int,
     *     iterations: int
     * } $vuln
     * @param array{
     *     label: string,
     *     result: string,
     *     wall_ms: float,
     *     avg_ms: float,
     *     cpu_ms: ?float,
     *     mem_bytes: int,
     *     peak_bytes: int,
     *     err_msg: string,
     *     err_code: int,
     *     iterations: int
     * } $safe
     *
     * @return array{result_parity: string, speedup: ?float, delta_ms: ?float}
     */
    private function buildSummary(array $vuln, array $safe): array
    {
        $speedup = null;
        $delta = null;
        if ($safe['avg_ms'] > 0.0) {
            $speedup = $vuln['avg_ms'] / $safe['avg_ms'];
            $delta = $vuln['avg_ms'] - $safe['avg_ms'];
        }

        return [
            'result_parity' => $vuln['result'] === $safe['result'] ? 'same' : 'different',
            'speedup' => $speedup,
            'delta_ms' => $delta,
        ];
    }

    /**
     * @param array<string, array{
     *     label: string,
     *     result: string,
     *     wall_ms: float,
     *     avg_ms: float,
     *     cpu_ms: ?float,
     *     mem_bytes: int,
     *     peak_bytes: int,
     *     err_msg: string,
     *     err_code: int,
     *     iterations: int
     * }> $rows
     */
    private function renderBenchmarkTable(Output $output, array $rows): void
    {
        $header = sprintf(
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
            $labelText = str_pad($row['label'], 8);
            $resultText = str_pad($row['result'], 6);
            $label = $this->formatCase($output, $labelText, $row['label']);
            $result = $this->formatResult($output, $resultText, $row['result']);
            $wall = str_pad($this->formatMs($row['wall_ms']), 10, ' ', \STR_PAD_LEFT);
            $avg = str_pad($this->formatMs($row['avg_ms']), 10, ' ', \STR_PAD_LEFT);
            $cpu = null === $row['cpu_ms'] ? 'n/a' : $this->formatMs($row['cpu_ms']);
            $cpu = str_pad($cpu, 10, ' ', \STR_PAD_LEFT);
            $mem = str_pad($this->formatKb($row['mem_bytes']), 10, ' ', \STR_PAD_LEFT);
            $peak = str_pad($this->formatKb($row['peak_bytes']), 10, ' ', \STR_PAD_LEFT);
            $err = $row['err_msg'];
            if ('-' !== $err) {
                $err = $output->error($err);
            }

            $output->write(
                $label.' | '
                .$result.' | '
                .$wall.' | '
                .$avg.' | '
                .$cpu.' | '
                .$mem.' | '
                .$peak.' | '
                .$err."\n",
            );
        }
    }

    /**
     * @param array{
     *     label: string,
     *     result: string,
     *     wall_ms: float,
     *     avg_ms: float,
     *     cpu_ms: ?float,
     *     mem_bytes: int,
     *     peak_bytes: int,
     *     err_msg: string,
     *     err_code: int,
     *     iterations: int
     * } $vuln
     * @param array{
     *     label: string,
     *     result: string,
     *     wall_ms: float,
     *     avg_ms: float,
     *     cpu_ms: ?float,
     *     mem_bytes: int,
     *     peak_bytes: int,
     *     err_msg: string,
     *     err_code: int,
     *     iterations: int
     * } $safe
     */
    private function renderSummary(Output $output, array $vuln, array $safe): void
    {
        $summary = $this->buildSummary($vuln, $safe);
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
            $deltaText = $this->formatMs($summary['delta_ms']).' ms';
            $deltaText = $summary['delta_ms'] >= 0.0
                ? $output->success($deltaText)
                : $output->warning($deltaText);
        }

        $output->write("\n");
        $output->write('Result parity : '.$parity."\n");
        $output->write('Speedup       : '.$speedupText." (vuln avg / safe avg)\n");
        $output->write('Delta avg     : '.$deltaText." (vuln - safe)\n");
    }

    private function formatCase(Output $output, string $label, string $raw): string
    {
        return match ($raw) {
            'vuln' => $output->error($label),
            'safe' => $output->success($label),
            default => $label,
        };
    }

    private function formatResult(Output $output, string $label, string $raw): string
    {
        return match ($raw) {
            'match' => $output->success($label),
            'no' => $output->warning($label),
            'error' => $output->error($label),
            default => $label,
        };
    }

    private function formatInput(string $value, ?int $limit): string
    {
        $escaped = DisplayEscaper::escape($value);
        if (null !== $limit && \strlen($escaped) > $limit) {
            return substr($escaped, 0, $limit).'...';
        }

        return $escaped;
    }

    private function formatMs(float $ms): string
    {
        return number_format($ms, 2);
    }

    private function formatKb(int $bytes): string
    {
        return sprintf('%+.1f', $bytes / 1024);
    }

    /**
     * @param array<mixed, mixed> $usage
     */
    private function usageToMs(array $usage, string $prefix): float
    {
        $secKey = $prefix.'.tv_sec';
        $usecKey = $prefix.'.tv_usec';

        $sec = isset($usage[$secKey]) && is_numeric($usage[$secKey]) ? (float) $usage[$secKey] : 0.0;
        $usec = isset($usage[$usecKey]) && is_numeric($usage[$usecKey]) ? (float) $usage[$usecKey] : 0.0;

        return $sec * 1000 + $usec / 1000;
    }

    private function matchResultName(int|false $result): string
    {
        if (1 === $result) {
            return 'match';
        }

        if (0 === $result) {
            return 'no';
        }

        return 'error';
    }

    private function parseIntOption(string $value, int $min): ?int
    {
        if ('' === $value || !Ascii::isDigit($value)) {
            return null;
        }

        $parsed = (int) $value;
        if ($parsed < $min) {
            return null;
        }

        return $parsed;
    }

    /**
     * @return array{
     *     pattern: string,
     *     safePattern: ?string,
     *     inputValue: ?string,
     *     inputFile: ?string,
     *     repeat: int,
     *     prefix: string,
     *     suffix: string,
     *     iterations: int,
     *     warmup: int,
     *     jit: ?string,
     *     backtrackLimit: ?int,
     *     recursionLimit: ?int,
     *     timeLimit: ?int,
     *     format: string,
     *     showInput: bool,
     *     error: ?string
     * }
     */
    private function errorPayload(string $message): array
    {
        return [
            'pattern' => '',
            'safePattern' => null,
            'inputValue' => null,
            'inputFile' => null,
            'repeat' => 1,
            'prefix' => '',
            'suffix' => '',
            'iterations' => 1,
            'warmup' => 0,
            'jit' => null,
            'backtrackLimit' => null,
            'recursionLimit' => null,
            'timeLimit' => null,
            'format' => 'console',
            'showInput' => false,
            'error' => $message,
        ];
    }
}
