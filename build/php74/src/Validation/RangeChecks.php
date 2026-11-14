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

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\InternalFailureException;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Printer\DisplayEscaper;

/**
 * The checks of a range of a class, "[a-z]": endpoints that are one
 * character each, and in order, on the values PCRE reads them as.
 *
 * Each needs only the two endpoints and the mode they are read in. The
 * validator walks the endpoints themselves between the two checks, as
 * PCRE reads them before it compares them.
 *
 * An instance reads one text, the pattern or the payload of a "(*pla:...)"
 * parsed apart, and reports offsets in the whole pattern.
 *
 * @internal
 */
final class RangeChecks
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
     */
    private bool $utf = false;

    /**
     * @param PcreTarget  $target  the PHP and PCRE2 judged
     * @param string|null $pattern the whole pattern, which the errors quote
     * @param string|null $source  the text the positions of the ranges point
     *                             into: the pattern, or the payload of a
     *                             "(*pla:...)" parsed apart; null when
     *                             nothing says what was written
     * @param int         $offset  where that text starts in the pattern
     * @param bool        $utf     whether PCRE reads the pattern as UTF-8
     */
    public function __construct(PcreTarget $target, ?string $pattern = null, ?string $source = null, int $offset = 0, bool $utf = false)
    {
        $this->target = $target;
        $this->pattern = $pattern;
        $this->source = $source;
        $this->offset = $offset;
        $this->utf = $utf;
    }

    /**
     * The same checks on another text of the pattern, which starts at
     * $offset in it.
     */
    public function reading(?string $source, int $offset): self
    {
        return new self($this->target, $this->pattern, $source, $offset, $this->utf);
    }

    /**
     * Each endpoint is one character: a literal, an escape that writes one,
     * or a control character.
     */
    public function endpoints(RangeNode $node): void
    {
        // Unreachable from a parsed pattern: the parser refuses such an
        // endpoint first, with the same code; only a hand-built range gets here.
        if (!$this->isSingleCharNode($node->start) || !$this->isSingleCharNode($node->end)) {
            $this->raise(
                \sprintf(
                    'Invalid range: ranges must be between literal characters or single escape sequences. Found %s and %s.',
                    get_class($node->start),
                    get_class($node->end),
                ),
                $node->startPosition,
                ErrorCode::RangeInvalidBounds(),
            );
        }

        // A literal endpoint is one code point.
        if ($node->start instanceof LiteralNode && mb_strlen($node->start->value, 'UTF-8') > 1) {
            $this->raise(
                'Invalid range: start char must be a single character.',
                $node->startPosition,
                ErrorCode::RangeInvalidStart(),
            );
        }
        if ($node->end instanceof LiteralNode && mb_strlen($node->end->value, 'UTF-8') > 1) {
            $this->raise(
                'Invalid range: end char must be a single character.',
                $node->startPosition,
                ErrorCode::RangeInvalidEnd(),
            );
        }
    }

    /**
     * The start of the range comes before its end, on the code points of
     * both endpoints. PCRE reports it where the range ends.
     */
    public function order(RangeNode $node): void
    {
        $startCodePoint = $this->endpointCodePoint($node->start, true);
        $endCodePoint = $this->endpointCodePoint($node->end, false);
        if (null !== $startCodePoint && null !== $endCodePoint && $startCodePoint > $endCodePoint) {
            // Without Unicode mode, a multibyte end ends the range on its
            // first byte.
            $endsAt = !$this->utf && $node->end instanceof LiteralNode ? $node->getEndPosition() - \strlen($node->end->value) + 1 : $node->getEndPosition();
            $this->raise(
                \sprintf('Invalid range "%s": start character comes after end character.', $this->describe($node)),
                $this->target->faultOffset($endsAt),
                ErrorCode::RangeReversed(),
            );
        }
    }

    private function isSingleCharNode(NodeInterface $node): bool
    {
        return $node instanceof LiteralNode
            || $node instanceof CharLiteralNode
            || $node instanceof ControlCharNode;
    }

    /**
     * The value an endpoint gives the range. Without Unicode mode PCRE reads
     * the pattern byte by byte, so a multibyte character written as is ends
     * a range start with its last byte and begins a range end with its first:
     * "[\u{e9}-\xe0]" is the range from 0xA9 to 0xE0.
     *
     * @param bool $isStart whether the node is the start of the range
     */
    private function endpointCodePoint(NodeInterface $node, bool $isStart): ?int
    {
        if ($node instanceof LiteralNode) {
            // Unreachable from a parsed pattern: the parser never gives a
            // range an empty endpoint. It guards a tree built by hand.
            if ('' === $node->value) {
                return null;
            }

            if (!$this->utf) {
                return \ord($isStart ? $node->value[\strlen($node->value) - 1] : $node->value[0]);
            }

            $codePoint = mb_ord($node->value, 'UTF-8');

            return false === $codePoint ? \ord($node->value) : $codePoint;
        }

        if ($node instanceof CharLiteralNode || $node instanceof ControlCharNode) {
            return $node->codePoint >= 0 ? $node->codePoint : null;
        }

        throw InternalFailureException::unexpectedState('A range endpoint is neither a literal, a character escape nor a control character.'); // Unreachable: endpoints() refuses any other node first.
    }

    private function describe(RangeNode $node): string
    {
        $text = null === $this->source
            ? $this->describeEndpoint($node->start).'-'.$this->describeEndpoint($node->end)
            : (string) substr($this->source, $node->startPosition, $node->getEndPosition() - $node->startPosition);

        // A range from the last byte of a character, without UTF, starts in
        // the middle of it: its bytes are spelled.
        return mb_check_encoding($text, 'UTF-8') ? $text : DisplayEscaper::escapeFragment($text, false);
    }

    private function describeEndpoint(NodeInterface $node): string
    {
        switch (true) {
            case true === $node instanceof LiteralNode:
                return $node->value;
            case true === $node instanceof CharLiteralNode:
                return $node->originalRepresentation;
            case true === $node instanceof ControlCharNode:
                return '\c'.$node->char;
            default:
                throw InternalFailureException::unexpectedState('A range endpoint is neither a literal, a character escape nor a control character.');
        }
    }

    /**
     * @return never
     */
    private function raise(string $message, int $position, ErrorCode $code)
    {
        throw new SemanticErrorException($message, $code, $position + $this->offset, $this->pattern);
    }
}
