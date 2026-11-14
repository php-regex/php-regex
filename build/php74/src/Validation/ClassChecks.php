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
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\PosixItem;

/**
 * The checks of the brackets of a class: a POSIX class PCRE2 does not
 * know, a collating element it refuses, a POSIX item written outside a
 * class or ending a range, and the "\Q...\E" written between the items,
 * which makes a "[" text.
 *
 * No node records how a "[" inside a class was written, nor the "\Q" and
 * "\E" around it: these checks read the text. The validator keeps whether
 * a quote is open, as it walks the items of a class.
 *
 * An instance reads one text, the pattern or the payload of a "(*pla:...)"
 * parsed apart, and reports offsets in the whole pattern.
 *
 * @internal
 */
final class ClassChecks
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
     * The names of POSIX classes PCRE2 knows, matched case-sensitively.
     */
    private const VALID_POSIX_CLASSES = [
        'alnum' => true, 'alpha' => true, 'ascii' => true,
        'blank' => true, 'cntrl' => true, 'digit' => true,
        'graph' => true, 'lower' => true, 'print' => true,
        'punct' => true, 'space' => true, 'upper' => true,
        'word' => true, 'xdigit' => true,
    ];

    /**
     * @param PcreTarget  $target  the PHP and PCRE2 judged
     * @param string|null $pattern the whole pattern, which the errors quote
     * @param string|null $source  the text the positions of the items point
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
     * Whether the class at $start is "[[:<:]]" or "[[:>:]]", the old POSIX
     * word boundaries, which PCRE reads as such outside a class.
     */
    public function isWordBoundary(int $start): bool
    {
        return \in_array(substr((string) $this->source, $start, 7), ['[[:<:]]', '[[:>:]]'], true);
    }

    /**
     * "[:alpha:]" written as a class of its own is a POSIX item outside a
     * class, which PCRE refuses rather than reading as a set of characters.
     */
    public function posixOutsideClass(int $start): void
    {
        $source = (string) $this->source;
        $terminator = PosixItem::terminator($source, $start);
        if (null === $terminator) {
            return;
        }

        // Past its end from PCRE2 10.47, on its "[" before.
        $offset = $this->target->faultOffset($terminator + 2, $terminator + 2 - $start);

        if (':' === $source[$start + 1]) {
            $this->raise(
                'POSIX named classes are supported only within a class.',
                $offset,
                ErrorCode::PosixOutsideClass(),
            );
        }

        $this->raise(
            'POSIX collating elements are not supported.',
            $offset,
            ErrorCode::PosixCollatingElement(),
        );
    }

    /**
     * Whether a "[" read inside a class was written as is, rather than
     * escaped or quoted: PCRE reads a quoted "[" as text, so "[\Qc[:(\E:]"
     * opens no POSIX class.
     *
     * @param bool $quoteOpen whether a "\Q" written in the class is still open
     *                        before the item
     */
    public function isUnquotedBracket(LiteralNode $node, bool $quoteOpen): bool
    {
        $start = $node->startPosition;

        return !$quoteOpen && 1 === $node->endPosition - $start && '[' === ($this->source[$start] ?? '');
    }

    /**
     * A "[" inside a class starts a POSIX item when one closes after it:
     * "[[:alpha:]]" is known, "[[:foo:]]" and "[[.ch.]]" are errors.
     *
     * @param bool $isRangeEnd whether the item ends a range, as in "[a-[.x.]]"
     */
    public function bracket(int $start, bool $isRangeEnd = false): void
    {
        $source = (string) $this->source;
        $terminator = PosixItem::terminator($source, $start);
        if (null === $terminator) {
            return;
        }

        // Past the element from PCRE2 10.45; before, on the "[" that opens
        // a collating element, or on the name of a class.
        $past = $this->target->supports(PcreFeature::PosixItemErrorPastItsEnd());

        // As a range end, "[a-[.x.]]", just inside its "[", and refused as a
        // range PCRE cannot make before its name is read: "[a-[:\d:]]", a
        // name the lexer reads as no class, too.
        if ($isRangeEnd) {
            $this->raise('Invalid range in character class: a POSIX item cannot end a range.', $past ? $terminator + 2 : $start + 1, ErrorCode::RangeInvalidBounds());
        }

        // After a hyphen that is a member, "[-[.x.]]", on its "[".
        if (':' !== $source[$start + 1]) {
            $this->raise('POSIX collating elements are not supported.', $past ? $terminator + 2 : $start, ErrorCode::PosixCollatingElement());
        }

        $name = (string) substr($source, $start + 2, $terminator - $start - 2);
        if (!$this->isPosixClassName($name)) {
            $this->raise(
                \sprintf('Invalid POSIX class: "%s".', $name),
                $past ? $terminator + 2 : $start + 2 + (0 === strncmp($name, '^', strlen('^')) ? 1 : 0),
                ErrorCode::PosixInvalid(),
            );
        }
    }

    /**
     * A POSIX class the parser read, "[[:alpha:]]": PCRE matches its name
     * case-sensitively, so "[[:ALPHA:]]" is unknown.
     */
    public function posixClass(PosixClassNode $node): void
    {
        if (!$this->isPosixClassName($node->class)) {
            // Past the class from PCRE2 10.45, on its name before.
            $this->raise(
                \sprintf('Invalid POSIX class: "%s".', $node->class),
                $this->target->supports(PcreFeature::PosixItemErrorPastItsEnd()) ? $node->getEndPosition() : $node->startPosition + 2 + (0 === strncmp($node->class, '^', strlen('^')) ? 1 : 0),
                ErrorCode::PosixInvalid(),
            );
        }
    }

    /**
     * Whether a "\Q" is open past the "\Q" and "\E" written between two
     * items of a class, from $from to $to, given whether one was open
     * before: no node records them, and nothing else sits there but the
     * class opener, the "^" that negates it, the "-" of a range and, under
     * "xx", blanks, none of them a backslash, so the last one decides. Each
     * stretch is read once, which keeps a long quoted class linear.
     */
    public function quoteOpenAfter(int $from, int $to, bool $open): bool
    {
        if (null === $this->source || $to <= $from) {
            return $open;
        }

        $between = (string) substr($this->source, $from, $to - $from);
        $opens = strrpos($between, '\Q');
        $closes = strrpos($between, '\E');
        if (false !== $opens || false !== $closes) {
            return false === $closes || (false !== $opens && $opens > $closes);
        }

        return $open;
    }

    private function isPosixClassName(string $name): bool
    {
        return isset(self::VALID_POSIX_CLASSES[0 === strncmp($name, '^', strlen('^')) ? (string) substr($name, 1) : $name]);
    }

    /**
     * @return never
     */
    private function raise(string $message, int $position, ErrorCode $code)
    {
        throw new SemanticErrorException($message, $code, $position + $this->offset, $this->pattern);
    }
}
