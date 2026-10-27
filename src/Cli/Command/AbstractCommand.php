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
use PHPRegex\Cli\Option\Option;
use PHPRegex\Cli\Option\ParsedOption;
use PHPRegex\Cli\Output;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Exception\UnsupportedTargetException;
use PHPRegex\Linter\RedosVerdict;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Json\JsonDocument;
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Parser\Text\Ascii;
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
     * @param array{php_version?: string, pcre_version?: string, locale?: string} $options the target the command line names
     * @param bool                                                                $json    whether the command line asked for JSON: the error is then the envelope
     */
    protected function createRegex(Output $output, array $options, bool $json = false): ?Regex
    {
        try {
            // A PHP the command line names is the target, as lint takes it:
            // one that ships PCRE1 is refused.
            if (isset($options['php_version'])) {
                $options = ProjectTarget::fromSources(['--php-version' => $options['php_version']], ['--pcre-version' => $options['pcre_version'] ?? null], null, [], ['--locale' => $options['locale'] ?? null])->regexOptions();
            }

            return Regex::fromOptions(ParserOptions::fromArray($options));
        } catch (UnsupportedTargetException $e) {
            if ($json) {
                $output->writeDocument(JsonDocument::error($e->getMessage(), JsonDocument::STAGE_TARGET));
            } else {
                $output->writeError($output->error('Error: '.$e->getMessage()."\n"));
            }

            return null;
        } catch (InvalidRegexOptionException $e) {
            $message = $this->invalidOptionMessage($e, $options);
            if ($json) {
                $output->writeDocument(JsonDocument::error($message, JsonDocument::STAGE_USAGE));
            } else {
                $output->writeError($output->error($message."\n"));
            }

            return null;
        }
    }

    /**
     * The message of an option the library refused. A version or a locale
     * the command line gave is named by its option, --php-version,
     * --pcre-version or --locale, not by the library's key.
     *
     * @param array{php_version?: string, pcre_version?: string, locale?: string} $fromCommandLine the library options the command line set
     */
    protected function invalidOptionMessage(InvalidRegexOptionException $e, array $fromCommandLine): string
    {
        $flags = [];
        foreach (['php_version' => '--php-version', 'pcre_version' => '--pcre-version', 'locale' => '--locale'] as $key => $flag) {
            if (isset($fromCommandLine[$key])) {
                $flags['"'.$key.'"'] = $flag;
            }
        }

        return 'Invalid option: '.strtr($e->getMessage(), $flags);
    }

    /**
     * The ReDoS options of analyze and debug.
     *
     * @return list<Option>
     */
    protected static function redosOptions(): array
    {
        return [
            Option::value('--redos-mode', '<mode>', 'ReDoS mode (off, theoretical, confirmed)'),
            Option::value('--redos-threshold', '<sev>', 'Minimum ReDoS severity (low, medium, high, critical)'),
        ];
    }

    /**
     * Read --redos-mode or --redos-threshold into the mode or the threshold
     * it sets, as analyze and debug take them.
     *
     * @return string|null the message refusing the value
     */
    protected function readRedosOption(ParsedOption $option, RedosMode &$mode, ?RedosSeverity &$threshold): ?string
    {
        $value = (string) $option->value;
        if ('--redos-mode' === $option->name) {
            $read = RedosMode::tryFrom(Ascii::lower($value));
            if (null === $read) {
                return 'Invalid value for --redos-mode.';
            }
            $mode = $read;

            return null;
        }

        try {
            $threshold = RedosSeverity::fromConfig($value);
        } catch (InvalidRegexOptionException $e) {
            return 'Invalid value for --redos-threshold: '.$e->getMessage();
        }

        return null;
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

        $output->writeError($output->error('Error: '.$message."\n"));
        $output->writeError($usage);

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
     * The banner lines naming the PHP version, the PCRE2 release and the
     * locale asked for on the command line.
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
        if (null !== $input->globalOptions->locale) {
            $meta['Target locale'] = $output->warning($input->globalOptions->locale);
        }

        return $meta;
    }
}
