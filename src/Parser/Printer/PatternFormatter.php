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

namespace PHPRegex\Parser\Printer;

use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Parser\Exception\FormattingException;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Printer\Internal\StructuralFingerprint;
use PHPRegex\Parser\RegexParser;

/**
 * Lays a pattern out over lines under x, or compacts it to one line.
 *
 * A pattern that fits the width and nests at most two groups comes back
 * byte for byte. Anything else is laid out: each group on lines of its own,
 * runs of atoms together, one branch per line with its "|" hanging in the
 * indent, comments kept and aligned. The author's spelling is kept, except
 * where x would skip a character or read a comment. Formatting a formatted
 * pattern gives it back unchanged.
 *
 * Both operations read their own output back and throw rather than return a
 * pattern that is not the one they were given.
 */
final class PatternFormatter
{
    private const CLOSING_DELIMITERS = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];

    private readonly int $width;

    private ?RegexParser $parser = null;

    /**
     * @param int $width the width a line aims at, indent included: code
     *                   points under u or (*UTF), bytes otherwise
     *
     * @throws InvalidRegexOptionException when $width is below 1
     */
    public function __construct(int $width = 80)
    {
        if ($width < 1) {
            throw new InvalidRegexOptionException(\sprintf('The width must be at least 1, %d given.', $width));
        }

        $this->width = $width;
    }

    /**
     * Lay the pattern out over lines under x when it does not fit the width
     * or nests more than two groups. Where a line break would change what it
     * matches, the pattern comes back as written, with the reason.
     *
     * @throws FormattingException when the output would not be the same pattern
     */
    public function format(RegexNode $regex): FormatResult
    {
        $printer = PatternPrinter::forFormatting($this->width);
        $formatted = $regex->accept($printer);

        $refusal = $printer->refusal();
        if (null !== $refusal) {
            return new FormatResult($formatted, false, $refusal);
        }

        $this->check($regex, $formatted);

        return new FormatResult($formatted, $formatted !== self::written($regex));
    }

    /**
     * Write the pattern on one line, without the global x, its layout or its
     * comments; an inline "(?x)" is kept.
     *
     * @throws FormattingException when the output would not be the same pattern
     */
    public function compact(RegexNode $regex): FormatResult
    {
        $compacted = $regex->accept(PatternPrinter::forCompacting());

        $this->check($regex, $compacted);

        return new FormatResult($compacted, $compacted !== self::written($regex));
    }

    /**
     * Read the output back: it must be the input's tree, comments, layout
     * and spelling aside.
     *
     * @throws FormattingException
     */
    private function check(RegexNode $input, string $output): void
    {
        try {
            $same = StructuralFingerprint::of($input) === StructuralFingerprint::of($this->parser()->parse($output));
        } catch (RegexException $exception) {
            throw self::unsafe($input, $exception);
        }

        if (!$same) {
            throw self::unsafe($input);
        }
    }

    private static function unsafe(RegexNode $input, ?RegexException $previous = null): FormattingException
    {
        $pattern = self::written($input);

        return new FormattingException(\sprintf('The formatter wrote a pattern that does not read back as %s.', $pattern), $pattern, $previous);
    }

    /**
     * The pattern as its author wrote it, or as the tree spells it when it
     * was not parsed from a source.
     */
    private static function written(RegexNode $regex): string
    {
        if (null === $regex->source) {
            return $regex->accept(new PatternPrinter());
        }

        return $regex->delimiter.$regex->source.(self::CLOSING_DELIMITERS[$regex->delimiter] ?? $regex->delimiter).$regex->flags;
    }

    private function parser(): RegexParser
    {
        // A cached tree of the output would not be read from the output.
        return $this->parser ??= RegexParser::create(['cache' => new NullCache()]);
    }
}
