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
use PHPRegex\Cli\JsonRequest;
use PHPRegex\Cli\Option\Options;
use PHPRegex\Cli\Output;
use PHPRegex\Cli\PcreRuntimeInfo;
use PHPRegex\Parser\Json\JsonDocument;

/**
 * @internal
 */
final class RedosCommand extends AbstractCommand implements JsonCommandInterface
{
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

    /**
     * The options of the command, which its help lists.
     */
    public static function options(): Options
    {
        return RedosArguments::options();
    }

    public function run(Input $input, Output $output): int
    {
        // The benchmark runs the pattern with the JIT on purpose, unless
        // "--jit 0" says otherwise: it measures what production sees. So it
        // runs the pattern itself, not through the engine, and gives the
        // process back as it found it on every way out: each setting it
        // changes, to the value ini_set() says it had, so nothing needs
        // ini_get().
        $saved = [];

        try {
            return $this->benchmark($input, $output, $saved);
        } finally {
            foreach ($saved as $key => $value) {
                ini_set($key, $value);
            }
        }
    }

    /**
     * @param array<string, string> $saved the settings changed, with the value each had
     */
    private function benchmark(Input $input, Output $output, array &$saved): int
    {
        $json = JsonRequest::in($input->args);
        $arguments = RedosArguments::parse($input->args);
        if (\is_string($arguments)) {
            return $this->usageError($output, $arguments, self::options()->usage, $json);
        }

        $pattern = $arguments->pattern;
        $safePattern = null !== $arguments->safePattern && '' !== $arguments->safePattern ? $arguments->safePattern : null;

        $regex = $this->createRegex($output, $input->regexOptions, $json);
        if (null === $regex) {
            return self::INVALID;
        }

        // A JSON run stops on an invalid pattern, with its validation: there
        // is no benchmark of it to report.
        if ('json' === $arguments->format) {
            $validation = $regex->validate($pattern);
            if (!$validation->isValid) {
                $output->writeDocument(JsonDocument::error($validation->error ?? 'Invalid pattern.', JsonDocument::STAGE_PATTERN, ['validation' => $validation]));

                return self::FAILURE;
            }

            $safeValidation = null !== $safePattern ? $regex->validate($safePattern) : null;
            if (null !== $safeValidation && !$safeValidation->isValid) {
                $output->writeDocument(JsonDocument::error('Invalid --safe pattern: '.($safeValidation->error ?? 'Invalid pattern.'), JsonDocument::STAGE_PATTERN, ['validation' => $safeValidation]));

                return self::FAILURE;
            }
        }

        $error = $this->applySettings($arguments, $saved);
        if (null !== $error) {
            return $this->unusable($output, $error, $json);
        }

        $runtime = PcreRuntimeInfo::fromIni();
        $subject = RedosInput::from($arguments, $regex);
        if (\is_string($subject)) {
            return $this->unusable($output, $subject, $json);
        }

        $renderer = new RedosBenchmarkRenderer($output);
        $highlightedVuln = $renderer->highlight($regex, $pattern);
        $highlightedSafe = null !== $safePattern ? $renderer->highlight($regex, $safePattern) : null;

        if ('json' !== $arguments->format) {
            $meta = $this->targetMeta($input, $output);
            $meta['PCRE'] = $output->warning($runtime->version);
            $meta['PCRE JIT'] = $output->warning($runtime->jitSetting ?? 'unknown');
            $meta['Backtrack'] = $output->warning((string) ($runtime->backtrackLimit ?? 'unknown'));
            $meta['Recursion'] = $output->warning((string) ($runtime->recursionLimit ?? 'unknown'));
            $renderer->renderPreamble(new ConsoleStyle($output, $input->globalOptions->visuals), $meta, $highlightedVuln, $highlightedSafe, $arguments, $subject);
        }

        $benchmark = new RedosBenchmark();
        $vuln = $benchmark->run('vuln', $pattern, $subject->subject, $arguments->warmup, $arguments->iterations);
        $safe = null !== $safePattern ? $benchmark->run('safe', $safePattern, $subject->subject, $arguments->warmup, $arguments->iterations) : null;

        // A pattern PHP refuses is a problem of the pattern, whatever the
        // benchmark measured.
        $verdict = $benchmark->refuses($pattern) || (null !== $safePattern && $benchmark->refuses($safePattern))
            ? self::FAILURE
            : self::SUCCESS;

        if ('json' === $arguments->format) {
            $renderer->writeDocument($arguments, $runtime, $subject, $vuln, $safe);
        } else {
            $renderer->renderResults($vuln, $safe);
        }

        return $verdict;
    }

    /**
     * Report what keeps the command line from running: as the JSON error
     * envelope, stage "usage", when it asked for JSON.
     */
    private function unusable(Output $output, string $message, bool $json): int
    {
        if ($json) {
            $output->writeDocument(JsonDocument::error($message, JsonDocument::STAGE_USAGE));
        } else {
            $output->writeError($output->error("Error: {$message}\n"));
        }

        return self::INVALID;
    }

    /**
     * Set the settings the command line names, keeping in $saved the value
     * each had; the message when ini_set() is disabled and one is named.
     *
     * @param array<string, string> $saved
     */
    private function applySettings(RedosArguments $arguments, array &$saved): ?string
    {
        // disable_functions is set per process, so the test of this block
        // runs the command in a PHP process of its own.
        $settings = array_filter([
            'pcre.jit' => $arguments->jit,
            'pcre.backtrack_limit' => $arguments->backtrackLimit,
            'pcre.recursion_limit' => $arguments->recursionLimit,
            // Setting the time limit restarts its count, as set_time_limit() does.
            'max_execution_time' => null !== $arguments->timeLimit && $arguments->timeLimit > 0 ? $arguments->timeLimit : null,
        ], static fn (string|int|null $value): bool => null !== $value);

        if ([] === $settings) {
            return null;
        }

        if (!\function_exists('ini_set')) {
            return '--jit, --backtrack-limit, --recursion-limit and --time-limit set a setting, and ini_set() is disabled.';
        }

        foreach ($settings as $key => $value) {
            $previous = ini_set($key, (string) $value);
            if (false !== $previous) {
                $saved[$key] = $previous;
            }
        }

        return null;
    }
}
