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

use PHPRegex\Cli\Input;
use PHPRegex\Cli\Output;
use PHPRegex\Linter\Internal\RedosVerdict;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Internal\JsonDocument;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;

/**
 * @internal
 */
abstract class AbstractCommand implements CommandInterface
{
    /**
     * @param array<string, mixed> $options
     * @param bool                 $json    whether the command line asked for JSON: the error is then the envelope
     */
    protected function createRegex(Output $output, array $options, bool $json = false): ?Regex
    {
        try {
            return Regex::create($options);
        } catch (InvalidRegexOptionException $e) {
            if ($json) {
                $output->writeDocument(JsonDocument::error('Invalid option: '.$e->getMessage(), JsonDocument::STAGE_USAGE));
            } else {
                $output->write($output->error('Invalid option: '.$e->getMessage()."\n"));
            }

            return null;
        }
    }

    /**
     * Read a command line of one pattern and the options named here. A flag
     * stands alone; a valued option is "--name=value" or "--name value". An
     * argument starting with "--" is an option until "--" ends them, and the
     * first other argument is the pattern.
     *
     * @param array<int, string> $args
     * @param list<string>       $flags
     * @param list<string>       $valued
     *
     * @return array{pattern: string, options: array<string, string|true>, error: ?string}
     */
    protected function readArguments(array $args, array $flags = [], array $valued = []): array
    {
        $pattern = null;
        $options = [];
        $endOfOptions = false;
        $count = \count($args);

        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];

            if ($endOfOptions || !str_starts_with($arg, '--')) {
                $pattern ??= $arg;

                continue;
            }

            if ('--' === $arg) {
                $endOfOptions = true;

                continue;
            }

            $parts = explode('=', $arg, 2);
            $name = $parts[0];
            $value = $parts[1] ?? null;

            if (null === $value && \in_array($name, $flags, true)) {
                $options[$name] = true;

                continue;
            }

            if (!\in_array($name, $valued, true)) {
                return ['pattern' => '', 'options' => [], 'error' => 'Unknown option: '.$arg];
            }

            if (null === $value) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return ['pattern' => '', 'options' => [], 'error' => \sprintf('Missing value for %s.', $name)];
                }
                $i++;
            }

            $options[$name] = $value;
        }

        if (null === $pattern || '' === $pattern) {
            return ['pattern' => '', 'options' => [], 'error' => 'Missing pattern.'];
        }

        return ['pattern' => $pattern, 'options' => $options, 'error' => null];
    }

    /**
     * Report a command line the command cannot use, with its usage; as the
     * JSON error envelope, stage "usage", when the command line asked for
     * JSON.
     */
    protected function usageError(Output $output, string $message, string $usage, bool $json = false): int
    {
        if ($json) {
            $output->writeDocument(JsonDocument::error($message, JsonDocument::STAGE_USAGE));

            return self::INVALID;
        }

        $output->write($output->error('Error: '.$message."\n"));
        $output->write($usage);

        return self::INVALID;
    }

    /**
     * Whether a ReDoS analysis is a problem the exit code reports: a risk the
     * confirmed mode confirmed, or a proof whose replay was skipped because
     * the engine could not set its limits, at the threshold or above and of
     * high severity or more, the rule the lint command uses for an error. A
     * theoretical finding is a warning.
     */
    protected function isConfirmedRedos(RedosAnalysis $analysis, ?RedosSeverity $threshold): bool
    {
        return RedosVerdict::standsConfirmed($analysis)
            && $analysis->exceedsThreshold($threshold ?? RedosSeverity::High)
            && $analysis->exceedsThreshold(RedosSeverity::High);
    }

    /**
     * The one-line verdict of a ReDoS analysis: the analysis' own headline,
     * or the disabled analysis the command line asked for.
     */
    protected function redosHeadline(RedosAnalysis $analysis): string
    {
        if (RedosMode::Off === $analysis->mode) {
            return 'ReDoS analysis disabled';
        }

        return $analysis->headline();
    }

    /**
     * The lines that follow the headline: what the model analysed
     * differently from the pattern as written, the budget when the
     * heuristics decided past it, then the evidence of the lint report: the
     * attack and, in confirmed mode, what the running engine made of it.
     *
     * @return list<string>
     */
    protected function redosVerdictLines(RedosAnalysis $analysis): array
    {
        $lines = [];

        if ([] !== $analysis->abstractions) {
            $lines[] = 'Model: '.implode('; ', $analysis->abstractions);
        }

        // The heuristic headline won over the budget: the budget is named here.
        if (RedosProof::BudgetExceeded === $analysis->proof && RedosSeverity::Safe !== $analysis->severity) {
            $lines[] = 'Note: analysis budget exceeded, the heuristics decided';
        }

        return [...$lines, ...RedosVerdict::evidence($analysis)];
    }

    /**
     * The banner lines naming the PHP version and the PCRE2 release asked
     * for on the command line.
     *
     * @return array<string, string>
     */
    protected function targetMeta(Input $input, Output $output): array
    {
        $meta = [];
        if (null !== $input->globalOptions->phpVersion) {
            $meta['Target PHP'] = $output->warning('PHP '.$input->globalOptions->phpVersion);
        }
        if (null !== $input->globalOptions->pcreVersion) {
            $meta['Target PCRE2'] = $output->warning('PCRE2 '.$input->globalOptions->pcreVersion);
        }

        return $meta;
    }
}
