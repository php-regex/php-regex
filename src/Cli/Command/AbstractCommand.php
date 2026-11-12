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
use PHPRegex\Linter\Formatter\ReportSpelling;
use PHPRegex\Linter\RangePoints;
use PHPRegex\Linter\RedosVerdict;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Json\JsonDocument;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;

/**
 * @internal
 *
 * @phpstan-import-type RangePoint from RangePoints
 */
abstract class AbstractCommand implements CommandInterface
{
    /**
     * The PHP versions the command judges its pattern for: the target the
     * command line names (--php-version, a range included, --pcre-version
     * and --locale, as Input holds them), read as lint reads them, else the
     * running PHP. No file of the working directory is read. A version the
     * library cannot read or does not judge is reported in lint's words, as
     * the JSON error envelope when the command line asked for JSON; null
     * then, and the command exits 2.
     *
     * @param bool $json whether the command line asked for JSON: the error is then the envelope
     */
    protected function resolveTarget(Input $input, Output $output, bool $json = false): ?TargetRange
    {
        $options = $input->regexOptions;

        try {
            $target = ProjectTarget::fromSources(['--php-version' => $options['php_version'] ?? null], ['--pcre-version' => $options['pcre_version'] ?? null], null, [], ['--locale' => $options['locale'] ?? null]);
            $regex = Regex::create($target->regexOptions());

            return new TargetRange($target, $regex, $target->rangeParsers($regex->parser()));
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
     * The exit code of a JSON run once the range judged the pattern: a
     * later version that refuses a pattern the floor accepts is a problem
     * with the pattern, exit 1; every other exit code stays, the floor's
     * refusal being the command's own verdict.
     *
     * @param non-empty-list<RangePoint> $points
     */
    protected static function exitCodeOverRange(array $points, int $exitCode): int
    {
        return self::SUCCESS === $exitCode && TargetRange::refusedLater($points) ? self::FAILURE : $exitCode;
    }

    /**
     * Say on stderr what the later versions of the range find in a
     * pattern the floor accepts, in lint's words: an error for the lowest
     * that refuses it, which makes the run exit 1, a warning for the
     * lowest that reads it into another tree or gives it another meaning.
     * stdout keeps the command's own output.
     *
     * @param string $about what names the pattern when the command reads more than one, "Pattern 1: "
     */
    protected function reportRange(Output $output, TargetRange $range, string $pattern, int $exitCode, string $about = ''): int
    {
        $findings = $range->findings($pattern);
        if (null !== $findings['refusal']) {
            $output->writeError($output->error('Error: '.$about.ReportSpelling::displayField($findings['refusal']))."\n");

            return self::SUCCESS === $exitCode ? self::FAILURE : $exitCode;
        }

        if (null !== $findings['warning'] && !$output->isQuiet()) {
            $output->writeError($output->warning('Warning: '.$about.ReportSpelling::displayField($findings['warning']))."\n");
        }

        return $exitCode;
    }

    /**
     * Say on stderr what resolving the target noticed, as lint does when
     * its report is no console one: for a run whose stdout carries no
     * banner, a JSON document or another format. The console banner
     * carries them instead. Silenced by -q.
     */
    protected function reportNotices(Output $output, TargetRange $range): void
    {
        if ($output->isQuiet()) {
            return;
        }

        foreach ($range->target->notices() as $notice) {
            $output->writeError('Note: '.$notice."\n");
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
            Option::value('--redos-threshold', '<sev>', 'Lowest ReDoS severity confirmed mode replays (low, medium, high, critical)'),
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
     * The banner line naming the target judged, as lint's Target row names
     * it: the PHP, the PCRE2 release and the locale resolved, where they
     * came from, and the later versions of the range. None when the
     * command line names none of them: the target is then the runtime,
     * which the banner's Runtime line names already.
     *
     * @return array<string, string>
     */
    protected function targetMeta(Output $output, TargetRange $range): array
    {
        $target = $range->target;
        if ('running PHP' === $target->source()) {
            return [];
        }

        return ['Target PHP' => $output->warning($target->label()).' ('.$target->source().')'.LintOutputRenderer::laterPoints($output, $target)];
    }
}
