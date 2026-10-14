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
use PHPRegex\Cli\JsonRequest;
use PHPRegex\Cli\Output;
use PHPRegex\Parser\Exception\FormattingException;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Internal\JsonDocument;
use PHPRegex\Parser\Printer\FormatRefusal;
use PHPRegex\Parser\Printer\PatternFormatter;

/**
 * Lays a pattern out over lines under x, or compacts it to one line. The
 * pattern alone goes to stdout, so that it can be piped or pasted back.
 *
 * @internal
 */
final class FormatCommand extends AbstractCommand implements JsonCommandInterface
{
    private const USAGE = "Usage: regex format <pattern> [--compact] [--width=N] [--format=console|json]\n";

    public function getName(): string
    {
        return 'format';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return 'Lay a pattern out over lines, or compact it to one';
    }

    public function run(Input $input, Output $output): int
    {
        $json = JsonRequest::in($input->args);
        $arguments = $this->readArguments($input->args, ['--compact', '--json'], ['--format', '--width']);
        if (null !== $arguments['error']) {
            return $this->usageError($output, $arguments['error'], self::USAGE, $json);
        }

        $options = $arguments['options'];
        // --json and --format name the same thing: the last one wins.
        $format = JsonRequest::format($input->args) ?? 'console';
        if (!\in_array($format, ['console', 'json'], true)) {
            return $this->usageError($output, 'Invalid value for --format. Use console or json.', self::USAGE, $json);
        }

        $width = self::width($options['--width'] ?? '80');
        if (null === $width) {
            return $this->usageError($output, 'The --width value must be a positive integer.', self::USAGE, $json);
        }

        $regex = $this->createRegex($output, $input->regexOptions, $json);
        if (null === $regex) {
            return self::INVALID;
        }

        $pattern = $arguments['pattern'];

        try {
            $ast = $regex->parse($pattern);
        } catch (LexerException|ParserException $e) {
            if ($json) {
                $output->writeDocument(JsonDocument::error($e->getMessage(), JsonDocument::STAGE_PATTERN));
            } else {
                $output->writeError($output->error('Error: '.$e->getMessage())."\n");
            }

            return self::FAILURE;
        }

        $formatter = new PatternFormatter($width);

        try {
            $result = isset($options['--compact']) ? $formatter->compact($ast) : $formatter->format($ast);
        } catch (FormattingException $e) {
            // The formatter's self-check refused its own output: a bug, never
            // reached by a pattern the formatter handles correctly.
            $message = \sprintf(
                'The formatter could not keep the meaning of this pattern (%s). This is a bug; please report it with the pattern: %s',
                $e->errorCode->value,
                $pattern,
            );
            if ($json) {
                $output->writeDocument(JsonDocument::error($message, JsonDocument::STAGE_INTERNAL));
            } else {
                $output->writeError($output->error('Error: '.$message)."\n");
            }

            return self::FAILURE;
        }

        if ($json) {
            $output->writeDocument(JsonDocument::encode([
                'pattern' => $pattern,
                'formatted' => $result->pattern,
                'changed' => $result->changed,
                'refusal' => $result->refusal?->value,
            ]));

            return self::SUCCESS;
        }

        // The pattern is what the command prints, not a status line: quiet
        // mode keeps it.
        $output->writeDocument($result->pattern."\n");

        if (null !== $result->refusal && !$output->isQuiet()) {
            $output->writeError($output->warning('Note: '.self::refusalNote($result->refusal))."\n");
        }

        return self::SUCCESS;
    }

    /**
     * The width a --width value asks for: a whole number of 1 or more, null
     * for anything else.
     */
    private static function width(string|true $value): ?int
    {
        if (true === $value || '' === $value || \strlen($value) !== strspn($value, '0123456789')) {
            return null;
        }

        $width = (int) $value;

        return $width >= 1 ? $width : null;
    }

    private static function refusalNote(FormatRefusal $refusal): string
    {
        return match ($refusal) {
            FormatRefusal::NewlineConvention => 'left as written: under (*CR), (*CRLF) or (*NUL) a # comment does not end at a line feed, so the pattern cannot be laid out over lines.',
            FormatRefusal::SpacedQuantifier => 'left as written: a quantifier written with spaces, such as a{ 1,2 }, is a literal before PCRE2 10.43, so x cannot be added.',
            FormatRefusal::ExtendedModeSwitchedOff => 'left as written: (?-x) or (?^) turns x off at the top level, where a line break would be a literal.',
        };
    }
}
