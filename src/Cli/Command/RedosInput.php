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

use PHPRegex\Parser\DelimitedPattern;
use PHPRegex\Parser\Printer\DisplayEscaper;
use PHPRegex\Redos\InputGenerator;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Toolkit\Regex;

/**
 * The subject `regex redos` runs the patterns on: the input the command
 * line gives, the file it names, or one generated from the pattern, repeated
 * between the prefix and the suffix.
 *
 * @internal
 */
final class RedosInput
{
    /**
     * How much of the subject the report shows unless asked for all of it.
     */
    public const PREVIEW_LIMIT = 120;

    /**
     * The pumps of a witness the generated input holds: enough for an
     * exponential attempt to reach any backtrack limit, and for a
     * polynomial one to take time without a long subject.
     */
    private const EXPONENTIAL_PUMPS = 32;

    private const POLYNOMIAL_PUMPS = 1000;

    /**
     * The most the input repeated may weigh, 8 MiB: printed with
     * --show-input, a subject of bytes PHP escapes as \xHH takes four times
     * its size once escaped, and the console copies that line as it lays it
     * out. At 8 MiB the run peaks at about 115 MiB (PHP 8.1 to 8.5, 32- and
     * 64-bit), within PHP's default memory_limit of 128M, which a subject of
     * 16 MiB goes past.
     */
    private const MAX_REPEATED_LENGTH = 8 * 1024 * 1024;

    /**
     * @param string  $value   the input before it is repeated
     * @param string  $source  "user", "file", "auto" or "default"
     * @param ?string $note    why the input is not the one asked for
     * @param string  $subject the input repeated, between the prefix and the suffix
     */
    public function __construct(
        public readonly string $value,
        public readonly string $source,
        public readonly ?string $note,
        public readonly string $subject,
    ) {}

    /**
     * The subject of the command line, or the message saying its input
     * file cannot be read or its count repeats the input past the limit,
     * before the subject is built.
     */
    public static function from(RedosArguments $arguments, Regex $regex): self|string
    {
        $value = $arguments->input;
        $source = 'user';
        $note = null;
        if (null !== $arguments->inputFile) {
            $file = $arguments->inputFile;
            $value = is_file($file) && is_readable($file) ? @file_get_contents($file) : false;
            if (false === $value) {
                return 'Input file not readable: '.$file;
            }
            $source = 'file';
        }

        if (null === $value) {
            [$value, $source, $note] = self::generate($regex, $arguments->pattern);
        }

        $refusal = self::refuseRepeat($value, $arguments->repeat);
        if (null !== $refusal) {
            return $refusal;
        }

        return new self($value, $source, $note, $arguments->prefix.str_repeat($value, $arguments->repeat).$arguments->suffix);
    }

    /**
     * The message refusing a count that repeats the input past the limit,
     * or null when the input repeated fits: bin/redos-bench asks it too.
     */
    public static function refuseRepeat(string $value, int $repeat): ?string
    {
        $largest = '' === $value ? \PHP_INT_MAX : intdiv(self::MAX_REPEATED_LENGTH, \strlen($value));
        if ($repeat <= $largest) {
            return null;
        }

        return \sprintf("--repeat %d repeats the %d-byte input past 8 MiB (%d bytes), the most it may weigh: printed with --show-input, an 8 MiB subject of bytes to escape takes up to about 115 MiB, which must fit in PHP's default memory_limit of 128M. The largest count for this input is %d.", $repeat, \strlen($value), self::MAX_REPEATED_LENGTH, $largest);
    }

    /**
     * The subject escaped for display, cut after $limit bytes.
     */
    public function preview(?int $limit = self::PREVIEW_LIMIT): string
    {
        $escaped = DisplayEscaper::escapeText($this->subject);
        if (null !== $limit && \strlen($escaped) > $limit) {
            return substr($escaped, 0, $limit).'...';
        }

        return $escaped;
    }

    /**
     * The input built for an analysis: its witness when the verdict is
     * proven, which holds the literals every match needs that PCRE2 looks
     * for before it backtracks, else one that makes its culprit backtrack;
     * null when it has neither.
     */
    public static function forAnalysis(RedosAnalysis $analysis, string $flags): ?string
    {
        $witness = $analysis->witness;
        if (null !== $witness) {
            return $witness->build(RedosComplexity::Exponential === $analysis->complexity ? self::EXPONENTIAL_PUMPS : self::POLYNOMIAL_PUMPS);
        }

        if (null === $analysis->culpritNode) {
            return null;
        }

        $generated = (new InputGenerator())->generate($analysis->culpritNode, $flags, $analysis->severity);

        return '' === $generated ? null : $generated;
    }

    /**
     * An input built for the pattern, or the default one when there is none.
     *
     * @return array{0: string, 1: string, 2: ?string}
     */
    private static function generate(Regex $regex, string $pattern): array
    {
        try {
            $generated = self::forAnalysis($regex->redos($pattern), DelimitedPattern::fromDelimited($pattern, $regex->target())->flags);
            if (null !== $generated) {
                return [$generated, 'auto', null];
            }
        } catch (\Throwable) {
            // Fall back to default input.
        }

        return ['a!', 'default', 'Auto input unavailable; using default.'];
    }
}
