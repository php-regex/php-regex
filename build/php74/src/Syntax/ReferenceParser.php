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

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenStream;

/**
 * Reads the references a backslash writes for TokenParser: "\1", "\k<name>",
 * "\g{-1}", the call "\g<name>", and what PCRE reads in their place, an
 * octal escape and digits for a number past the groups opened, and on PHP
 * 7.3 and 7.4 the character after a backslash PCRE2 cannot read. It reports
 * each where PCRE stops reading: a name that is malformed or too long, a
 * number it cannot take, a closer it misses.
 *
 * It shares the parser's ParseState and group names, and reads one text, the
 * pattern or a body read apart. An escape it splits into a character and
 * text is left in ParseState::$splitEscape, where a quantifier after it
 * finds that only the last character repeats.
 *
 * @internal
 */
final class ReferenceParser
{
    /**
     */
    private TokenStream $stream;

    /**
     */
    private string $pattern;

    /**
     */
    private PcreTarget $target;
    /**
     */
    private ParseState $state;
    /**
     */
    private GroupNameReader $groupNames;

    public function __construct(ParseState $state, GroupNameReader $groupNames)
    {
        $this->state = $state;
        $this->groupNames = $groupNames;
        $this->stream = $state->stream;
        $this->pattern = $state->pattern;
        $this->target = $state->target;
    }

    /**
     * "\k" names a group, and the lexer leaves it a plain escaped letter when
     * no well-formed name follows. PCRE refuses every such shape, and says
     * where it stopped reading: on the opener's absence, where a name should
     * start, past a leading digit, or where the closer should be.
     *
     * @param Token $token the escaped "k" under the cursor
     *
     * @throws ParserException
     */
    public function guardNamedReferenceEscape(Token $token): void
    {
        if ($this->state->inQuoteMode || $this->readsBadEscapeAt($token->position)) {
            return;
        }

        $opener = $this->pattern[$token->position + 2] ?? '';
        $closer = ['<' => '>', '{' => '}', "'" => "'"][$opener] ?? null;

        if (null === $closer) {
            throw $this->state->error(\sprintf('\k is not followed by a braced, angle-bracketed or quoted name at position %d.', $token->position + 2), ErrorCode::BackrefInvalidSyntax(), $token->position + 2);
        }

        $nameStart = $token->position + 3;
        // Inside braces PCRE skips the spaces before the name as well.
        if ('{' === $opener && $this->supports(PcreFeature::PaddedBracedEscapes())) {
            $nameStart += strspn($this->pattern, " \t", $nameStart);
        }
        $nameEnd = $this->groupNames->invalidNameOffset($nameStart);

        if (ErrorCode::GroupNameInvalid() === $this->groupNames->nameFault($nameStart, $nameEnd)) {
            throw $this->state->error(\sprintf('Group name after \k%s must not start with a digit at position %d.', $opener, $nameEnd), ErrorCode::GroupNameInvalid(), $nameEnd);
        }

        // PCRE measures the name before it looks for what closes it.
        $tooLong = $this->groupNames->tooLongAt($nameStart, $nameEnd);
        if (null !== $tooLong) {
            throw $this->groupNames->tooLongError($nameStart, $nameEnd, $tooLong);
        }

        if ($nameEnd === $nameStart) {
            throw $this->state->error(\sprintf('Group name expected after \k%s at position %d.', $opener, $nameStart), ErrorCode::GroupNameExpected(), $nameStart);
        }

        // Inside braces PCRE skips the spaces after the name, then wants
        // the "}": it stops on the first character that is neither.
        $closeAt = $nameEnd;
        if ('}' === $closer && $this->supports(PcreFeature::PaddedBracedEscapes())) {
            $closeAt += strspn($this->pattern, " \t", $nameEnd);
        }

        if ($closer !== ($this->pattern[$closeAt] ?? '')) {
            throw $this->state->error(\sprintf('Missing "%s" to close the group name after \k%s at position %d.', $closer, $opener, $closeAt), ErrorCode::GroupNameUnterminated(), $closeAt);
        }
    }

    /**
     * The node of a back reference token, "\1" or "\k<name>", read: the
     * reference, or what PCRE reads in its place.
     */
    public function backReference(Token $token, int $startPosition): NodeInterface
    {
        if ($this->readsBadEscapeAt($token->position)) {
            return $this->badEscapeLiteral($token, $startPosition);
        }

        $octal = $this->digitsFromUnreadReference($token, $startPosition)
            ?? $this->octalEscapeFromReference($token, $startPosition);
        if (null !== $octal) {
            return $octal;
        }

        $this->guardReferenceNameLength($token->value, $token->position);

        return new BackrefNode(self::withoutBracePadding($token->value), $startPosition, $token->end());
    }

    /**
     * parses \g references (backreferences and subroutines).
     */
    public function parseGReference(int $startPosition): NodeInterface
    {
        $token = $this->stream->previous();
        if ($this->readsBadEscapeAt($token->position)) {
            return $this->badEscapeLiteral($token, $startPosition);
        }

        $endPosition = $startPosition + \strlen($token->value);
        $value = self::withoutBracePadding($token->value);
        $this->guardReferenceNameLength($token->value, $token->position);

        // \g{N} or \gN (numeric, incl. relative) -> Backreference; \g'N',
        // like \g<N>, calls the group instead. Braces hold one sign at most,
        // then digits only: "\g{1-}" and "\g{--1}" are refused below.
        if (1 === LibraryPcre::match('/^\\\\g(?:\{[+-]?\d++\}|[0-9+-]++)$/', $value)) {
            return new BackrefNode($value, $startPosition, $endPosition);
        }

        // PCRE reads the number after "\g{", "\g<" or "\g'" before it looks
        // for what closes it, and refuses one it cannot read right after
        // the "g", on every release.
        $numberError = $this->gReferenceNumberError($token->position);
        if (null !== $numberError) {
            throw $this->state->error(\sprintf('Invalid \\g reference %s at position %d: %s.', $value, $token->position + 2, $numberError[1]), $numberError[0], $token->position + 2);
        }

        // \g{name} is a back reference, like \k{name}; it is recorded that
        // way, and the compiler gives back the spelling the pattern used.
        if (1 === LibraryPcre::match('/^\\\\g\{([\p{L}\p{Nd}_]++)\}$/u', $value, $m)) {
            return new BackrefNode('\\k{'.$m[1].'}', $startPosition, $endPosition);
        }

        // "\g<5fg>": PCRE reads a number after "\g<" or "\g'", and wants the
        // closing character right after it; before PCRE2 10.47, it refuses
        // the "\g" itself.
        if (1 === LibraryPcre::match('/^\\\\g[<\'][+-]?\d/', $value) && 1 !== LibraryPcre::match('/^\\\\g(?:<[+-]?\d++>|\'[+-]?\d++\')$/', $value)) {
            $position = $this->gReferenceErrorOffset($token->position);

            throw $this->state->error(\sprintf('Invalid subroutine call %s at position %d: the group number is not followed by what closes it.', $value, $position), $this->supports(PcreFeature::GReferenceNumberReadBeforeClosing()) ? ErrorCode::SubroutineInvalidSyntax() : ErrorCode::BackrefInvalidSyntax(), $position);
        }

        // \g<name> and \g'name' (non-numeric) call the group -> Subroutine;
        // a sign before a name is no name.
        if (1 === LibraryPcre::match('/^\\\\g<([+-]?\d++|[\p{L}\p{Nd}_]++)>$/u', $value, $m)) {
            return new SubroutineNode($m[1], 'g', $startPosition, $endPosition);
        }

        if (1 === LibraryPcre::match('/^\\\\g\'([+-]?\d++|[\p{L}\p{Nd}_]++)\'$/u', $value, $m)) {
            return new SubroutineNode($m[1], 'g', $startPosition, $endPosition);
        }

        $position = $this->gReferenceErrorOffset($token->position);
        $code = $this->gReferenceErrorCode($token->position, $position);

        throw $this->state->error(\sprintf('Invalid \\g reference syntax: %s at position %d%s', $value, $position, (function () use ($code) {
            switch (true) {
                case $code === ErrorCode::GroupNameExpected(): return ': a group name or number is expected.';
                case $code === ErrorCode::GroupNameUnterminated(): return ': nothing closes the group name there.';
                default: return '.';
            }
        })()), $code, $position);
    }

    /**
     * The error PCRE's read_number() gives the number after the "\g{",
     * "\g<" or "\g'" whose backslash stands at $start, and its reason: a
     * number past 65535, a forward one counted from the groups opened before
     * it included; a relative zero; a backward one past the groups opened.
     * Null when it reads the number, or none stands there. Only "\g{" takes
     * spaces and tabs before it, from PCRE2 10.43.
     *
     * @return array{ErrorCode, string}|null
     */
    private function gReferenceNumberError(int $start): ?array
    {
        $opener = $this->pattern[$start + 2] ?? '';
        if (!\in_array($opener, ['{', '<', "'"], true)) {
            return null;
        }

        $at = $start + 3;
        if ('{' === $opener && $this->supports(PcreFeature::PaddedBracedEscapes())) {
            $at += strspn($this->pattern, " \t", $at);
        }

        if (1 !== LibraryPcre::match('/\G([+-]?)(\d++)/', $this->pattern, $number, 0, $at)) {
            return null;
        }

        [, $sign, $digits] = $number;
        $value = (int) $digits;
        $call = '{' !== $opener;

        switch (true) {
            case true === (PcreNumber::exceeds($digits) || '+' === $sign && $value + $this->state->captureCount > PcreNumber::MAX):
                return [ErrorCode::GroupNumberTooBig(), 'the group number is too big: PCRE takes at most 65535'];
            case true === ('' !== $sign && 0 === $value):
                return [$call ? ErrorCode::SubroutineRelativeZero() : ErrorCode::BackrefZero(), 'a relative number may not be zero'];
            case true === ('-' === $sign && $value > $this->state->captureCount):
                return [$call ? ErrorCode::SubroutineRelativeMissing() : ErrorCode::BackrefRelative(), \sprintf('-%d counts back past the %d groups opened before it', $value, $this->state->captureCount)];
            default:
                return null;
        }
    }

    /**
     * What PCRE reports for a "\g" it stops reading at $position: after a
     * "{", "<" or quote, a number not closed is a syntax error in it, and
     * otherwise PCRE reads a name, which is either missing or not closed.
     *
     * @param int $start the offset of the backslash
     */
    private function gReferenceErrorCode(int $start, int $position): ErrorCode
    {
        $opener = $this->pattern[$start + 2] ?? '';
        if (!\in_array($opener, ['{', '<', "'"], true)) {
            return ErrorCode::BackrefInvalidSyntax();
        }

        // Only "\g{" takes spaces and tabs before a name or number, from
        // PCRE2 10.43.
        $nameStart = $start + 3 + ('{' === $opener && $this->supports(PcreFeature::PaddedBracedEscapes()) ? strspn($this->pattern, " \t", $start + 3) : 0);

        if (1 === LibraryPcre::match('/\G[+-]?\d/', $this->pattern, $matches, 0, $nameStart)) {
            return ErrorCode::BackrefInvalidSyntax();
        }

        // PCRE measures the name before it looks for what closes it.
        if (!$this->groupNames->startsWithDigit($nameStart)
            && $position === $this->groupNames->tooLongAt($nameStart, $this->groupNames->invalidNameOffset($nameStart))) {
            return ErrorCode::GroupNameTooLong();
        }

        return $this->groupNames->nameFault($nameStart, $position);
    }

    /**
     * PCRE reads the name of "\k<name>", "\g{name}" and the like before it
     * looks the group up, and stops where a name past the limit ends.
     *
     * @param int $start the offset of the backslash
     */
    private function guardReferenceNameLength(string $reference, int $start): void
    {
        if (1 !== LibraryPcre::match('/^\\\\[gk]([<{\'])/', $reference, $matches)) {
            return;
        }

        // Only braces take spaces and tabs before the name, from PCRE2 10.43;
        // the name ends where the characters a name holds end.
        $nameStart = $start + 3;
        if ('{' === $matches[1] && $this->supports(PcreFeature::PaddedBracedEscapes())) {
            $nameStart += strspn($this->pattern, " \t", $nameStart);
        }

        if ($this->groupNames->startsWithDigit($nameStart)) {
            return;
        }

        $nameEnd = $this->groupNames->invalidNameOffset($nameStart);
        $tooLong = $this->groupNames->tooLongAt($nameStart, $nameEnd);
        if (null !== $tooLong) {
            throw $this->groupNames->tooLongError($nameStart, $nameEnd, $tooLong);
        }
    }

    /**
     * "\g{ 1 }" and "\k{ name }" refer to what "\g{1}" and "\k{name}" do:
     * PCRE2 10.43 lets spaces and tabs follow the "{" and precede the "}".
     * Whether the target PCRE2 takes them is the validator's to say.
     */
    private static function withoutBracePadding(string $reference): string
    {
        return LibraryPcre::replace('/^(\\\\[gk]\{)[ \t]*+(.*?)[ \t]*+\}$/', '$1$2}', $reference) ?? $reference;
    }

    /**
     * Where PCRE stops reading a "\g" it cannot make sense of: right after
     * the "\g" when nothing it knows follows, after a number that nothing
     * closes, or where the characters of a name end.
     *
     * @param int $start the offset of the backslash
     */
    private function gReferenceErrorOffset(int $start): int
    {
        $position = $start + 2;
        $closing = ['<' => '>', "'" => "'", '{' => '}'][$this->pattern[$position] ?? ''] ?? null;

        if (null === $closing) {
            return $position;
        }

        // Only "\g{...}" takes spaces around what it holds, from PCRE2 10.43.
        $blanks = '}' === $closing && $this->supports(PcreFeature::PaddedBracedEscapes()) ? " \t" : '';
        ++$position;
        $position += strspn($this->pattern, $blanks, $position);

        if (1 === LibraryPcre::match('/\G[+-]?\d++/', $this->pattern, $matches, 0, $position)) {
            // Before PCRE2 10.47, a number nothing closes fails "\g" itself,
            // or, before 10.43, the space after "{" that pads it.
            if (!$this->supports(PcreFeature::GReferenceNumberReadBeforeClosing())) {
                $padded = false !== strpos(" \t", $this->pattern[$start + 3] ?? 'x');

                return $start + ($padded && !$this->supports(PcreFeature::PaddedBracedEscapes()) ? 3 : 2);
            }

            $position += \strlen($matches[0]);
        } elseif ($this->groupNames->startsWithDigit($position)) {
            // A digit first is refused there, before any padding.
            return $this->groupNames->invalidNameOffset($position);
        } else {
            $nameStart = $position;
            $position = $this->groupNames->invalidNameOffset($position);
            $tooLong = $this->groupNames->tooLongAt($nameStart, $position);
            if (null !== $tooLong) {
                return $tooLong;
            }
        }

        return $position + strspn($this->pattern, $blanks, $position);
    }

    /**
     * Whether PHP 7.3 and 7.4 read the escape whose backslash stands at
     * $position, outside a class, as the character after the backslash,
     * knowing the groups opened so far.
     */
    private function readsBadEscapeAt(int $position): bool
    {
        return $this->state->badEscapeIsLiteral && !$this->state->inQuoteMode
            && BadEscape::at($this->pattern, $position, false, $this->state->unicodeMode, $this->target, $this->state->captureCount);
    }

    /**
     * An escape read as the character after its backslash: "\g{-1}" with
     * no group open is "g" then the text "{-1}", "\400" without UTF is "4"
     * then "00". What follows the character is plain text in either, but
     * for the "+" of "\g{+n}", "\g<+n>", "\g'+n'" or "\g+n", which repeats
     * the character before it: "g{+65535}" is "g", "{" once or more, then
     * the text "65535}".
     */
    private function badEscapeLiteral(Token $token, int $startPosition): SequenceNode
    {
        $characterEnd = $startPosition + 2;
        $end = $token->end();
        $character = new LiteralNode($this->pattern[$startPosition + 1], $startPosition, $characterEnd);
        $parts = [$character];
        $textStart = $characterEnd;
        $plusAt = '+' === ($this->pattern[$characterEnd] ?? '') ? $characterEnd : $characterEnd + 1;
        if ('g' === $character->value && '+' === ($this->pattern[$plusAt] ?? '') && $plusAt < $end) {
            $parts = $plusAt === $characterEnd
                ? [new QuantifierNode($character, '+', QuantifierType::Greedy(), $startPosition, $plusAt + 1)]
                : [$character, new QuantifierNode(new LiteralNode($this->pattern[$characterEnd], $characterEnd, $plusAt), '+', QuantifierType::Greedy(), $characterEnd, $plusAt + 1)];
            $textStart = $plusAt + 1;
        }

        $parts[] = new LiteralNode((string) substr($this->pattern, $textStart, $end - $textStart), $textStart, $end);
        $this->state->splitEscape = new SequenceNode($parts, $startPosition, $end);

        return $this->state->splitEscape;
    }

    /**
     * Before PCRE2 10.45 a number past 214748363 is read as no reference:
     * "\8" or "\9" and eight digits or more is then the digit, and the
     * digits after it text.
     */
    private function digitsFromUnreadReference(Token $token, int $startPosition): ?NodeInterface
    {
        if ($this->supports(PcreFeature::HugeBackreferenceNumberIsReference()) || 1 !== LibraryPcre::match('/^\\\\([89]\d{8,})$/', $token->value, $matches)) {
            return null;
        }

        $digitEnd = $startPosition + 2;
        $this->state->splitEscape = new SequenceNode(
            [new LiteralNode($matches[1][0], $startPosition, $digitEnd), new LiteralNode((string) substr($matches[1], 1), $digitEnd, $token->end())],
            $startPosition,
            $token->end(),
        );

        return $this->state->splitEscape;
    }

    /**
     * "\NN" of 10 or more that starts with 1 to 7, past the groups opened so
     * far, is no reference: PCRE reads an octal escape of up to three octal
     * digits, and the digits left as text, "\1000" being "@" then "0".
     */
    private function octalEscapeFromReference(Token $token, int $startPosition): ?NodeInterface
    {
        if (1 !== LibraryPcre::match('/^\\\\([1-7]\d++)$/', $token->value, $matches)
            || (\strlen($matches[1]) < 6 && (int) $matches[1] <= $this->state->captureCount)
            || 1 !== LibraryPcre::match('/^[0-7]{1,3}/', $matches[1], $octal)) {
            return null;
        }

        $representation = '\\'.$octal[0];
        $escapeEnd = $startPosition + \strlen($representation);
        $escape = new CharLiteralNode(
            $representation,
            CodePointReader::fromLiteral($representation, CharLiteralType::OctalLegacy()),
            CharLiteralType::OctalLegacy(),
            $startPosition,
            $escapeEnd,
        );

        $text = (string) substr($matches[1], \strlen($octal[0]));
        if ('' === $text) {
            return $escape;
        }

        $this->state->splitEscape = new SequenceNode([$escape, new LiteralNode($text, $escapeEnd, $token->end())], $startPosition, $token->end());

        return $this->state->splitEscape;
    }

    private function supports(PcreFeature $feature): bool
    {
        return $this->target->supports($feature);
    }
}
