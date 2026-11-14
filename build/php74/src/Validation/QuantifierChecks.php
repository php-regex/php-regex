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

namespace PHPRegex\Parser\Validation;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\PcreNumber;
use PHPRegex\Parser\Syntax\RepeatCount;
use PHPRegex\Parser\Text\Bytes;

/**
 * The checks of the count of a repeat, "a{2,5}": numbers of at most 65535,
 * in order, and a count after "\N" the release judged reads as one.
 *
 * PCRE refuses a number as it reads it, so each error stands where the
 * number ends, or where PCRE stops in it, an offset that moved between
 * releases. Each needs only the text of the count and what it repeats.
 *
 * An instance reads one text, the pattern or the payload of a "(*pla:...)"
 * parsed apart, and reports offsets in the whole pattern.
 *
 * @internal
 */
final class QuantifierChecks
{
    /**
     */
    private PcreTarget $target;
    /**
     */
    private ?string $pattern = null;
    /**
     */
    private ?string $source = null;
    /**
     */
    private int $offset = 0;

    /**
     * @param PcreTarget  $target  the PHP and PCRE2 judged
     * @param string|null $pattern the whole pattern, which the errors quote
     * @param string|null $source  the text the positions of the repeats point
     *                             into: the pattern, or the payload of a
     *                             "(*pla:...)" parsed apart; null when
     *                             nothing says what was written
     * @param int         $offset  where that text starts in the pattern
     */
    public function __construct(PcreTarget $target, ?string $pattern = null, ?string $source = null, int $offset = 0)
    {
        $this->target = $target;
        $this->pattern = $pattern;
        $this->source = $source;
        $this->offset = $offset;
    }

    /**
     * The same checks on another text of the pattern, which starts at
     * $offset in it.
     */
    public function reading(?string $source, int $offset): self
    {
        return new self($this->target, $this->pattern, $source, $offset);
    }

    /**
     * "\N{4 }", "\N{,2}": before PCRE2 10.43 such a count does not repeat
     * "\N", and "\N{" that is no repeat is refused where the "\N" ends.
     * "a{ 4 }" and "a{,2}" are literal text there, which the lexer reads as
     * such. PCRE refuses it before it reads the item the count repeats.
     *
     * @param bool $inClass whether the repeat sits in a class
     */
    public function countAfterN(QuantifierNode $node, bool $inClass): void
    {
        if ($node->node instanceof CharTypeNode && 'N' === $node->node->value && !$inClass
            && 0 === strncmp($node->quantifier, '{', strlen('{'))
            && 1 !== LibraryPcre::match('/^\{\d++(?:,\d*+)?\}/', $node->quantifier)
            && !$this->target->supports(PcreFeature::OpenAndPaddedRepeatCounts())) {
            // Before 10.37 its digits are read first: one past 65535 is
            // refused as too big a count, at the same place.
            if (!$this->target->supports(PcreFeature::LargeOpenCountIsText()) && null !== RepeatCount::overflowBeforeTen37($node->quantifier, 0)) {
                $this->raise('Number too big in the count after \N: PCRE allows at most 65535 repetitions.', $node->node->getEndPosition(), ErrorCode::QuantifierTooBig());
            }

            $this->raise(
                \sprintf('The count "%s" after \N needs PCRE2 10.43, which PHP bundles from 8.4.', $node->quantifier),
                $node->node->getEndPosition(),
                ErrorCode::EscapeUnsupported(),
                'Write the count as {n}, {n,} or {n,m} without spaces, or target PHP 8.4+.',
            );
        }
    }

    /**
     * The numbers of the count: PCRE caps them at 65535, and checks each as
     * it reads it, before it compares the two. It reads them once it has
     * read the item they repeat.
     */
    public function counts(QuantifierNode $node): void
    {
        [$min, $max] = QuantifierCounts::of($node->quantifier);

        [$minEnd, $maxEnd] = $this->numberEnds($node);

        // Right after "\N", PCRE reads the count to tell it from a character
        // name, and refuses it where the "\N" ends.
        $afterN = $node->node instanceof CharTypeNode && 'N' === $node->node->value && null !== $this->source
            && '{' === ($this->source[$node->node->getEndPosition()] ?? '') ? $node->node->getEndPosition() : null;
        if ($min > PcreNumber::MAX || $max > PcreNumber::MAX) {
            $this->raise(
                \sprintf('Number too big in "%s" quantifier: PCRE allows at most 65535 repetitions.', $node->quantifier),
                $afterN ?? ($min > PcreNumber::MAX ? $minEnd : $maxEnd),
                ErrorCode::QuantifierTooBig(),
            );
        }

        if (-1 !== $max && $min > $max) {
            $this->raise(
                \sprintf('Invalid quantifier range "%s": min > max.', $node->quantifier),
                $afterN ?? $maxEnd,
                ErrorCode::QuantifierInvalidRange(),
                \sprintf('Write the smaller count first: "{%d,%d}".', $max, $min),
            );
        }
    }

    /**
     * Where the two numbers of a "{n,m}" quantifier end in the pattern, the
     * places PCRE reports a number it refuses.
     *
     * @return array{0: int, 1: int}
     */
    private function numberEnds(QuantifierNode $node): array
    {
        // A lazy or possessive quantifier ends with one more character.
        $suffix = QuantifierType::Greedy() === $node->type ? 0 : 1;
        $braceStart = $node->getEndPosition() - $suffix - \strlen($node->quantifier);
        // A comment may stand between the count and its "+" or "?": the
        // count is found in the source, past the item it repeats.
        $written = null === $this->source ? false : strpos($this->source, $node->quantifier, $node->node->getEndPosition());
        if (false !== $written && $written < $braceStart) {
            $braceStart = $written;
        }

        if (1 !== LibraryPcre::match('/^\{\s*+(\d*+)\s*+(?:,\s*+(\d*+))?/', $node->quantifier, $matches, \PREG_OFFSET_CAPTURE)) {
            return [$node->startPosition, $node->startPosition];
        }

        $minEnd = $braceStart + $matches[1][1] + $this->digitsRead($matches[1][0]);
        $maxEnd = isset($matches[2]) ? $braceStart + $matches[2][1] + $this->digitsRead($matches[2][0]) : $minEnd;

        return [$minEnd, $maxEnd];
    }

    /**
     * How many digits of a count PCRE has read when it refuses one over
     * 65535: every one from PCRE2 10.45; before, up to the digit that takes
     * it over.
     */
    private function digitsRead(string $digits): int
    {
        if ($this->target->supports(PcreFeature::NumberTooBigPastWholeNumber())) {
            return \strlen($digits);
        }

        $value = 0;
        foreach (Bytes::split($digits) as $index => $digit) {
            $value = $value * 10 + (int) $digit;
            if ($value > PcreNumber::MAX) {
                return $index + 1;
            }
        }

        return \strlen($digits);
    }

    /**
     * @return never
     */
    private function raise(string $message, int $position, ErrorCode $code, ?string $hint = null)
    {
        throw new SemanticErrorException($message, $code, $position + $this->offset, $this->pattern, null, null, $hint);
    }
}
