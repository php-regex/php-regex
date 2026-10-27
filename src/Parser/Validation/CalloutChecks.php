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
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Text\Bytes;

/**
 * The check of a callout, "(?C1)": a number of at most 255. Any string is
 * a valid argument, the empty one included: PCRE2 compiles (?C""), (?C'')
 * and (?C{}).
 *
 * An instance reads one text, the pattern or the payload of a "(*pla:...)"
 * parsed apart, and reports offsets in the whole pattern.
 *
 * @internal
 */
final class CalloutChecks
{
    /**
     * @param string|null $pattern the whole pattern, which the errors quote
     * @param string|null $source  the text the positions of the callouts point
     *                             into: the pattern, or the payload of a
     *                             "(*pla:...)" parsed apart; null when
     *                             nothing says what was written
     * @param int         $offset  where that text starts in the pattern
     */
    public function __construct(
        private readonly ?string $pattern = null,
        private readonly ?string $source = null,
        private readonly int $offset = 0,
    ) {}

    /**
     * The same check on another text of the pattern, which starts at
     * $offset in it.
     */
    public function reading(?string $source, int $offset): self
    {
        return new self($this->pattern, $source, $offset);
    }

    /**
     * A callout numbered past 255, refused where PCRE stops reading the
     * number.
     */
    public function callout(CalloutNode $node): void
    {
        if (\is_int($node->identifier) && ($node->identifier < 0 || $node->identifier > 255)) {
            throw new SemanticErrorException(
                \sprintf('Callout identifier must be between 0 and 255, got %d.', $node->identifier),
                ErrorCode::CalloutOutOfRange,
                $this->overflowOffset($node) + $this->offset,
                $this->pattern,
            );
        }
    }

    /**
     * PCRE reads the number of a callout digit by digit, and stops past the
     * one that takes it over 255.
     */
    private function overflowOffset(CalloutNode $node): int
    {
        $digits = null !== $this->source && 1 === LibraryPcre::match('/\G\d++/', $this->source, $matches, 0, $node->startPosition + 3)
            ? $matches[0]
            : (string) $node->identifier; // Unreachable from a parsed pattern: only a hand-built callout has no source.

        $number = 0;
        foreach (Bytes::split($digits) as $index => $digit) {
            $number = $number * 10 + (int) $digit;
            if ($number > 255) {
                return $node->startPosition + 4 + $index;
            }
        }

        return $node->startPosition + 4; // Unreachable from a parsed pattern, whose number is over 255 here.
    }
}
