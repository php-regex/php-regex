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
use PHPRegex\Parser\Text\DisplayEscaper;
use PHPRegex\Redos\InputGenerator;
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
     * file cannot be read.
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

        return new self($value, $source, $note, $arguments->prefix.str_repeat($value, $arguments->repeat).$arguments->suffix);
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
     * An input built to make the culprit of the pattern backtrack, or the
     * default one when there is none.
     *
     * @return array{0: string, 1: string, 2: ?string}
     */
    private static function generate(Regex $regex, string $pattern): array
    {
        try {
            $analysis = $regex->redos($pattern);
            $culprit = $analysis->culpritNode;
            if (null !== $culprit) {
                $patternInfo = DelimitedPattern::fromDelimited($pattern, $regex->target());
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
}
