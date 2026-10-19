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
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Syntax\PatternParser;

/**
 * Lays a pattern out over lines under x, or compacts it to one line.
 *
 * A pattern that fits the width and nests at most two groups comes back
 * byte for byte. Anything else is laid out: each group on lines of its own,
 * a run of atoms on its line when it fits, broken after its anchors and its
 * white space and filled up to the width when it does not, one branch per
 * line with its "|" hanging in the indent, comments kept and aligned. The
 * author's spelling is kept, except where x would skip a character or read
 * a comment. Formatting a formatted pattern gives it back unchanged.
 *
 * Both operations read their own output back, for the PHP and PCRE2 they
 * are given, and throw rather than return a pattern that is not the one they
 * were given. Neither probes the engine.
 */
final class PatternFormatter
{
    private readonly int $width;

    private readonly PcreTarget $target;

    /**
     * @param int             $width  the width a line aims at, indent included:
     *                                code points under u or (*UTF), bytes otherwise
     * @param PcreTarget|null $target the PHP and PCRE2 the output is read back
     *                                for, as the input was read; the running
     *                                ones when null
     *
     * @throws InvalidRegexOptionException when $width is below 1
     */
    public function __construct(int $width = 80, ?PcreTarget $target = null)
    {
        if ($width < 1) {
            throw new InvalidRegexOptionException(\sprintf('The width must be at least 1, %d given.', $width));
        }

        $this->width = $width;
        $this->target = $target ?? PcreTarget::runtime();
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
     * comments; an inline "(?x)" is kept. Where dropping x or a line break
     * would change what it matches, the pattern comes back as written, with
     * the reason.
     *
     * @throws FormattingException when the output would not be the same pattern
     */
    public function compact(RegexNode $regex): FormatResult
    {
        $printer = PatternPrinter::forCompacting();
        $compacted = $regex->accept($printer);

        $refusal = $printer->refusal();
        if (null !== $refusal) {
            return new FormatResult($compacted, false, $refusal);
        }

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
            $same = StructuralFingerprint::of($input) === StructuralFingerprint::of($this->parser($output)->parse($output));
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

        return $regex->delimiter.$regex->source.(PatternParser::CLOSING_BRACKETS[$regex->delimiter] ?? $regex->delimiter).$regex->flags;
    }

    /**
     * A parser for the target that refuses nothing the input passed: the
     * output is as deep as the input, holds the same lookbehinds, and may be
     * longer than any length limit the input was read under. A cached tree
     * of the output would not be read from the output.
     */
    private function parser(string $output): RegexParser
    {
        $length = max(\strlen($output), RegexParser::DEFAULT_MAX_PATTERN_LENGTH);

        return RegexParser::fromOptions(new ParserOptions(
            $length,
            \PHP_INT_MAX,
            new NullCache(),
            maxRecursionDepth: max($length, RegexParser::DEFAULT_MAX_RECURSION_DEPTH),
            target: $this->target,
        ));
    }
}
