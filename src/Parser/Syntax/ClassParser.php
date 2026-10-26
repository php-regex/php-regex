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

namespace PHPRegex\Parser\Syntax;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenStream;
use PHPRegex\Parser\Token\TokenType;

/**
 * Reads a character class, "[...]", for the token parser.
 *
 * A class is a small grammar of its own: what PCRE skips before its first
 * member, members and ranges, quotes that cut through them, and the many
 * places a range is refused, each release refusing some at another offset.
 * None of it depends on the pattern around the class but where the tokens
 * stand and whether they are quoted, which the token parser shares with it
 * through a ParseState. A class nested in a class is read here too.
 *
 * @internal
 */
final class ClassParser
{
    private readonly TokenStream $stream;

    private readonly string $pattern;

    private readonly PcreTarget $target;

    /**
     * Whether an escape a class refuses once it has read it, as "[\B]",
     * "[\N]" or the "\d" of "[a-\d]", is read as its letter under
     * PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL, as PCRE2 10.32 reads it: the
     * negation of PcreFeature::BadEscapeInClassStaysRefused.
     */
    private readonly bool $classBadEscapeReadAsLetter;

    public function __construct(private readonly ParseState $state)
    {
        $this->stream = $state->stream;
        $this->pattern = $state->pattern;
        $this->target = $state->target;
        $this->classBadEscapeReadAsLetter = !$state->target->supports(PcreFeature::BadEscapeInClassStaysRefused);
    }

    /**
     * Reads the class whose "[" was just read: what PCRE skips before its
     * first member, its members, and the "]" that closes it.
     */
    public function parse(): CharClassNode
    {
        $startToken = $this->stream->previous();
        $startPosition = $startToken->position;
        $isNegated = $this->readPrefix();
        $parts = $this->readMembers();

        $endToken = $this->stream->consume(TokenType::CharClassClose, 'Expected "]" to close character class', ErrorCode::CharclassUnclosed);

        return new CharClassNode($parts, $isNegated, $startPosition, $endToken->position + 1);
    }

    /**
     * "[[:<:]]" and "[[:>:]]", exactly, are no classes: PCRE2 reads them as
     * the start and the end of a word, "\b(?=\w)" and "\b(?<=\w)". Null for
     * any other class, its "[" just read, the stream left where it was.
     */
    public function wordBoundary(): ?GroupNode
    {
        $start = $this->stream->previous()->position;
        $text = substr($this->pattern, $start, 7);
        if ('[[:<:]]' !== $text && '[[:>:]]' !== $text) {
            return null;
        }

        $end = $start + 7;
        while (!$this->stream->isAtEnd() && $this->stream->current()->position < $end) {
            $this->stream->advance();
        }

        // Each node starts inside the one holding it, as written groups do.
        $side = new GroupNode(
            new CharTypeNode('w', $start + 2, $end - 1),
            '<' === $text[3] ? GroupType::LookaheadPositive : GroupType::LookbehindPositive,
            null,
            null,
            $start + 1,
            $end - 1,
        );

        return new GroupNode(
            new SequenceNode([new AssertionNode('b', $start + 1, $start + 1), $side], $start + 1, $end - 1),
            GroupType::NonCapturing,
            null,
            null,
            $start,
            $end,
        );
    }

    /**
     * Read what PCRE skips before the first member of a class: "\E", an
     * empty "\Q\E" and the negating "^", in any order, so "[\E^a]" is
     * negated. The lexer only gives a negation token in that prefix.
     *
     * @return bool whether the class is negated
     */
    private function readPrefix(): bool
    {
        $isNegated = false;

        while (true) {
            if ($this->stream->match(TokenType::Negation)) {
                $isNegated = true;
            } elseif ($this->stream->match(TokenType::QuoteModeStart)) {
                $this->state->inQuoteMode = true;
            } elseif ($this->stream->match(TokenType::QuoteModeEnd)) {
                $this->state->inQuoteMode = false;
            } else {
                return $isNegated;
            }
        }
    }

    /**
     * The members of a class, up to its "]": one node, an alternation of
     * several, or an empty literal for none.
     */
    private function readMembers(): NodeInterface
    {
        $parts = [];

        while (
            !$this->stream->check(TokenType::CharClassClose)
            && !$this->stream->isAtEnd()
        ) {
            // Silent tokens inside char class
            if ($this->stream->match(TokenType::QuoteModeStart)) {
                $this->state->inQuoteMode = true;

                continue;
            }
            if ($this->stream->match(TokenType::QuoteModeEnd)) {
                $this->state->inQuoteMode = false;

                continue;
            }
            array_push($parts, ...$this->readParts());
        }

        if (empty($parts)) {
            $position = $this->stream->current()->position;

            return new LiteralNode('', $position, $position);
        }

        if (1 === \count($parts)) {
            return $parts[0];
        }

        $start = $parts[0]->getStartPosition();
        $end = $parts[\count($parts) - 1]->getEndPosition();

        return new AlternationNode($parts, $start, $end);
    }

    /**
     * PCRE takes a range between two characters; "[\d-z]" names no range.
     *
     * @throws ParserException
     */
    private function guardRangeEndpoint(NodeInterface $node, int $position, bool $isEnd): void
    {
        if (!$this->isNonRangeEndpointType($node)) {
            return;
        }

        // Before PCRE2 10.45, a range start is refused on the hyphen, a POSIX
        // class ending a range just inside its "[", and a type or a property
        // ending one past its letter.
        if (!($this->target->supports(PcreFeature::RangeFromTypeReadToItsEnd))) {
            $position = match (true) {
                !$isEnd => $position - 1,
                $node instanceof PosixClassNode => $node->getStartPosition() + 1,
                // A type or a property.
                default => $node->getStartPosition() + 2,
            };
        }

        throw $this->state->error(
            \sprintf(
                'Invalid range in character class: a character type, POSIX class, or Unicode property cannot be a range endpoint at position %d.',
                $position,
            ),
            ErrorCode::RangeInvalidBounds,
            $position,
        );
    }

    /**
     * Whether an escape PCRE refuses inside a class starts at $position:
     * an assertion such as "\B", "\R", "\X", or "\N" that names no code
     * point, or a letter with no meaning there, as "\j".
     */
    private function isInvalidEscapeAt(Token $token, bool $endsRange = false): bool
    {
        $position = $token->position;
        if ('\\' !== ($this->pattern[$position] ?? '')) {
            return false;
        }

        // PHP 7.3 and 7.4 read one PCRE2 refuses as it reads it as its letter.
        if ($this->state->badEscapeIsLiteral && BadEscape::at($this->pattern, $position, true, $this->state->unicodeMode, $this->target, null, $endsRange)) {
            return false;
        }

        $letter = $this->pattern[$position + 1] ?? '';

        // "\N{" is refused where check_escape() refuses the brace, but for
        // a count it reads, which leaves "\N", and a code point the lexer
        // read as one.
        if ('N' === $letter) {
            if ('{' !== ($this->pattern[$position + 2] ?? '')) {
                return true;
            }

            return NamedCharacterBrace::namesCodePoint($this->pattern, $position + 2, $this->target)
                ? TokenType::UnicodeNamed !== $token->type
                : null !== NamedCharacterBrace::errorInClass($this->pattern, $position + 2, $this->target);
        }

        // An assertion, or a letter PCRE gives no meaning in a class; "\k" is
        // the letter from PCRE2 10.45.
        return '' !== $letter && (str_contains('ABCFGIJKLMORTUXYZijlmquyz', $letter) || ('k' === $letter && !$this->target->supports(PcreFeature::ClassBackslashKIsLetter)));
    }

    /**
     * Refuses the range an escape that names no character ends, past the
     * escape's letter, as PCRE2 does before 10.45.
     *
     * @throws ParserException
     */
    private function throwEscapeEndingRange(int $endAt): never
    {
        throw $this->state->error(
            \sprintf('Invalid range in character class: "\%s" cannot end a range, at position %d.', $this->pattern[$endAt + 1], $endAt + 2),
            ErrorCode::RangeInvalidBounds,
            $endAt + 2,
        );
    }

    /**
     * Whether the class item at the current token is a code point PCRE
     * refuses where it reads it. The stream is left where it was.
     */
    private function endRefusesCodePoint(): bool
    {
        $at = $this->stream->getPosition();

        try {
            [$endNode] = $this->readAtom($this->stream->current()->position);
        } catch (ParserException) {
            return false;
        } finally {
            $this->stream->setPosition($at);
        }

        return $this->refusesCodePoint($endNode);
    }

    /**
     * Whether the node is a code point PCRE refuses where it reads it: past
     * \xFF without UTF, past U+10FFFF or a surrogate under it.
     */
    private function refusesCodePoint(NodeInterface $node): bool
    {
        if (!$node instanceof CharLiteralNode) {
            return false;
        }

        $codePoint = $node->codePoint;

        return $this->state->unicodeMode ? $codePoint > 0x10FFFF || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF) : $codePoint > 0xFF;
    }

    /**
     * Whether a hyphen after the node is a member rather than a range: a
     * type, a property, a POSIX class or a class names no single character.
     */
    private function isNonRangeEndpointType(NodeInterface $node): bool
    {
        return $node instanceof CharTypeNode
            || $node instanceof UnicodePropNode
            || $node instanceof PosixClassNode
            || $node instanceof CharClassNode;
    }

    /**
     * Reads a single member of a class: a character, a type, a property, a
     * POSIX class, a nested class, or a hyphen read as a member.
     *
     * @return array{0: NodeInterface, 1: int} The node and its end position
     */
    private function readAtom(int $startPosition): array
    {
        // An anchor, an assertion or a backreference is a plain character
        // inside a class: "[$]" is a dollar sign, not an anchor. The lexer
        // already gives them as literals there, so what is left is the same
        // set of atoms as outside, plus what only a class can hold.
        if (null !== $atom = $this->state->matchAtom($startPosition)) {
            return [$atom, $atom->getEndPosition()];
        }

        if ($this->stream->match(TokenType::CharClassOpen)) {
            $node = $this->parse();

            return [$node, $node->getEndPosition()];
        }

        if ($this->stream->match(TokenType::Range)) {
            $token = $this->stream->previous();

            return [new LiteralNode($token->value, $startPosition, $token->end()), $token->end()];
        }

        if ($this->stream->match(TokenType::PosixClass)) {
            $token = $this->stream->previous();

            return [new PosixClassNode($token->value, $startPosition, $token->end()), $token->end()];
        }

        throw $this->state->error(
            \sprintf(
                'Unexpected token "%s" in character class at position %d.',
                $this->stream->current()->value,
                $this->stream->current()->position,
            ),
            ErrorCode::TokenUnexpected,
            $this->stream->current()->position,
        );
    }

    /**
     * The next part of a class, or the parts PCRE reads there. Without UTF
     * mode PCRE reads a class byte by byte: a multibyte character ending a
     * range ends it on its first byte, and a "-" after it starts a range
     * from its last byte, the bytes between members: "[a-é-a]" is the range
     * a to 0xC3, then 0xA9 to "a". The range is cut only when that "-" makes
     * a range.
     *
     * @return non-empty-list<NodeInterface>
     */
    private function readParts(): array
    {
        $parts = [];
        $part = $this->readPart();

        while (!$this->state->unicodeMode && $part instanceof RangeNode && $part->end instanceof LiteralNode && \strlen($part->end->value) > 1) {
            $end = $part->end;
            $endsAt = $end->getEndPosition();
            $tokenAt = $this->stream->getPosition();
            $wasInQuoteMode = $this->state->inQuoteMode;
            $next = $this->readPart(new LiteralNode(substr($end->value, -1), $endsAt - 1, $endsAt, $end->isRaw));
            if (!$next instanceof RangeNode) {
                $this->stream->setPosition($tokenAt);
                $this->state->inQuoteMode = $wasInQuoteMode;

                break;
            }

            // The first byte ends the range; the bytes between are members.
            $firstEndsAt = $endsAt - \strlen($end->value) + 1;
            $parts[] = new RangeNode($part->start, new LiteralNode($end->value[0], $end->getStartPosition(), $firstEndsAt, $end->isRaw), $part->getStartPosition(), $firstEndsAt);
            if ($endsAt - 1 > $firstEndsAt) {
                $parts[] = new LiteralNode(substr($end->value, 1, -1), $firstEndsAt, $endsAt - 1, $end->isRaw);
            }

            $part = $next;
        }

        $parts[] = $part;

        return $parts;
    }

    /**
     * Reads a part of a class: a member, or a range from it to the member
     * after a "-", where PCRE reads one.
     *
     * @param LiteralNode|null $lastByte the last byte of a multibyte range end, which starts this part
     */
    private function readPart(?LiteralNode $lastByte = null): NodeInterface
    {
        if (null !== $lastByte) {
            $startNode = $lastByte;
            $startPosition = $lastByte->getStartPosition();
        } else {
            $startToken = $this->stream->current();
            $startPosition = $startToken->position;

            [$startNode] = $this->readAtom($startPosition);

            // PCRE refuses such an escape as soon as it reads it, before any
            // "-" after it could make a range.
            if ($this->isInvalidEscapeAt($startToken)) {
                return $startNode;
            }
        }

        // PCRE also skips "\E" and an empty "\Q\E" before the "-": "[z\E-a]"
        // is the range z-a. Without a "-" after them, they are left to the
        // class loop as they were. A quoted run of several characters starts
        // its range at its last one, which one node cannot say, so that case
        // keeps its members apart.
        $beforeQuotes = $this->stream->getPosition();
        $wasInQuoteMode = $this->state->inQuoteMode;
        $singleCharacterStart = !($startNode instanceof LiteralNode && mb_strlen($startNode->value, 'UTF-8') > 1);
        // A class escape before them, "[\w\E-a]", keeps a member "-" up to
        // PCRE2 10.44, the newest any PHP release bundles; from 10.45 the
        // range forms and fails, so only a newer linked PCRE2 refuses it.
        $classEscapeStart = $startNode instanceof CharTypeNode
            || $startNode instanceof PosixClassNode
            || $startNode instanceof UnicodePropNode;
        if ($singleCharacterStart && (!$classEscapeStart || $this->target->supports(PcreFeature::EmptyQuoteSkippedAfterClassEscape))) {
            $this->state->skipEmptyQuotes();
        }

        // Check for Range
        $rangePosition = $this->stream->getPosition();
        if (!$this->stream->match(TokenType::Range)) {
            $this->stream->setPosition($beforeQuotes);
            $this->state->inQuoteMode = $wasInQuoteMode;

            return $startNode;
        }

        // Under "(?xx)" a space or a tab between a class escape and the "-",
        // which the lexer skipped, keeps the "-" a member up to PCRE2 10.44:
        // "[\d -z]" is "[\d\-z]". From 10.45 the range forms and fails.
        if ($classEscapeStart && $this->stream->previous()->position > $startNode->getEndPosition()
            && !$this->target->supports(PcreFeature::SpacedHyphenAfterClassEscapeMakesRange)) {
            $this->stream->setPosition($rangePosition);

            return $startNode;
        }

        // A code point PCRE refuses as it reads it, "[\x{100}-\z]" without
        // UTF, is refused before the "-": the range is not read, and the
        // validator reports the character.
        if ($this->refusesCodePoint($startNode)) {
            $this->stream->setPosition($rangePosition);

            return $startNode;
        }

        // PCRE refuses a class escape before the "-" once it has read the "-".
        $afterHyphen = $this->stream->previous()->end();
        $length = \strlen($this->pattern);

        // Before PCRE2 10.45 a range from a type is refused on its hyphen,
        // before its end is read, unless the pattern ends after the hyphen
        // or a "]" follows it, read as characters: "[\w-\Q\E]" is refused.
        $rangeFromType = $this->isNonRangeEndpointType($startNode);
        if ($rangeFromType && !$this->target->supports(PcreFeature::RangeFromTypeReadToItsEnd)) {
            if ($afterHyphen >= $length || ']' === $this->pattern[$afterHyphen]) {
                $this->stream->setPosition($rangePosition);

                return $startNode;
            }

            $this->guardRangeEndpoint($startNode, $afterHyphen, false);
        }

        // PCRE skips "\E" and an empty "\Q\E" after the "-": "[a-\Ec]" is
        // the range a-c, and in "[a-\Q\E]" the "-" is a plain member. From
        // 10.45 a "-" after a class escape that the pattern ends after, past
        // them or in a "\Q" that quotes nothing, is one too: the "]" is
        // missing.
        $this->state->skipEmptyQuotes();
        $quoteEndingThePattern = $this->stream->check(TokenType::QuoteModeStart)
            && TokenType::Eof === $this->stream->peek()->type && $this->stream->peek()->position >= $length;

        if ($this->stream->check(TokenType::CharClassClose)
            || ($rangeFromType && (($this->stream->isAtEnd() && $this->stream->current()->position >= $length) || $quoteEndingThePattern))) {
            $this->stream->setPosition($rangePosition);

            return $startNode;
        }

        // From 10.45 the end of a range from a type is read first: a code
        // point PCRE refuses there is reported as itself, by the validator.
        if ($rangeFromType && $this->endRefusesCodePoint()) {
            $this->stream->setPosition($rangePosition);

            return $startNode;
        }

        // Before PCRE2 10.45, an escape that names no character ends a range
        // as a type does: the range is refused past its letter, before a
        // name after it is read. A property PCRE reads is left to
        // guardRangeEndpoint().
        $endAt = $this->stream->current()->position;
        $endsAsInvalidRange = !$this->target->supports(PcreFeature::ClassEscapeEndingRangeRefusedAsEscape)
            && !($this->state->badEscapeIsLiteral && BadEscape::at($this->pattern, $endAt, true, $this->state->unicodeMode, $this->target, null, true));
        if ($endsAsInvalidRange && TokenType::UnicodeProp !== $this->stream->current()->type && BadEscape::endsRangeAsInvalidRange($this->pattern, $endAt, $this->classBadEscapeReadAsLetter)) {
            $this->throwEscapeEndingRange($endAt);
        }

        if ($this->isInvalidEscapeAt($this->stream->current(), true)) {
            // A "\N{" check_escape() refuses is refused before the range.
            if ($endsAsInvalidRange && 'N' === $this->pattern[$endAt + 1] && '{' !== ($this->pattern[$endAt + 2] ?? '')) {
                $this->throwEscapeEndingRange($endAt);
            }

            $this->stream->setPosition($rangePosition);

            return $startNode;
        }

        $this->guardRangeEndpoint($startNode, $afterHyphen, false);

        if ($this->stream->check(TokenType::CharClassOpen)) {
            $this->stream->rewind(1);

            return $startNode;
        }

        // A quoted end, "[a-\Qcz\E]", ends the range at the first quoted
        // character; the lexer gives a class one quoted character at a time,
        // so the rest stay members.
        if ($this->stream->check(TokenType::QuoteModeStart)) {
            if (TokenType::Literal !== $this->stream->peek()->type) {
                $this->stream->setPosition($beforeQuotes);
                $this->state->inQuoteMode = $wasInQuoteMode;

                return $startNode;
            }

            $this->stream->advance();
            $this->state->inQuoteMode = true;
        }

        $endToken = $this->stream->current();
        $endPosition = $endToken->position;

        try {
            [$endNode] = $this->readAtom($endPosition);
        } catch (ParserException) {
            throw $this->state->error(
                \sprintf(
                    'Unexpected token "%s" in character class range at position %d.',
                    $this->stream->current()->value,
                    $this->stream->current()->position,
                ),
                ErrorCode::TokenUnexpected,
                $this->stream->current()->position,
            );
        }

        // A class escape after it, once it has read the escape.
        $this->guardRangeEndpoint($endNode, $endNode->getEndPosition(), true);

        return new RangeNode($startNode, $endNode, $startPosition, $endNode->getEndPosition());
    }
}
