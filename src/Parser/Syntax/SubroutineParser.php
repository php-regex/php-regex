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
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Token\TokenStream;
use PHPRegex\Parser\Token\TokenType;

/**
 * Reads the calls a "(?" opens for TokenParser: "(?R)", "(?1)", "(?-1)",
 * "(?+1)", "(?&name)" and "(?P>name)", with the groups a call returns from
 * PCRE2 10.47, "(?1(2,<name>))". A name is read as PCRE2's read_name()
 * reads it, and refused where it stops.
 *
 * It shares the parser's ParseState, and reads one text, the pattern or a
 * body read apart.
 *
 * @internal
 */
final class SubroutineParser
{
    private readonly TokenStream $stream;

    private readonly string $pattern;

    private readonly PcreTarget $target;

    /**
     * The reader of the list of groups a call returns, made on the first.
     */
    private ?GroupListReader $groupLists = null;

    public function __construct(private readonly ParseState $state, private readonly GroupNameReader $groupNames)
    {
        $this->stream = $state->stream;
        $this->pattern = $state->pattern;
        $this->target = $state->target;
    }

    /**
     * Whether the token under the cursor, after "(?", may open a call: "&",
     * "R", a sign or a digit. A group that cannot be a call is left to the
     * readers of groups without making this one.
     */
    public static function mayOpenCall(TokenStream $stream): bool
    {
        if ($stream->check(TokenType::Quantifier)) {
            return '+' === $stream->current()->value;
        }

        if (!$stream->check(TokenType::Literal)) {
            return false;
        }

        $value = $stream->current()->value;

        return '&' === $value || 'R' === $value || '-' === $value || Ascii::isDigit($value);
    }

    /**
     * Parses a call "(?" opens, by name or by number: null when none does.
     */
    public function parseCall(int $startPosition): ?SubroutineNode
    {
        return $this->parseSubroutineModifier($startPosition)
            ?? $this->parseNumericSubroutineModifier($startPosition);
    }

    /**
     * Parses the call "(?P>name)", its "(?P>" read.
     */
    public function parsePythonSubroutine(int $startPosition): SubroutineNode
    {
        $name = $this->parseSubroutineName();
        $returned = $this->readReturnedGroups() ?? [];
        $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected ) to close subroutine call', $this->callLeftOpenCode());

        return new SubroutineNode($name, 'P>', $startPosition, $endToken->position + 1, $returned);
    }

    /**
     * Parses a subroutine group modifier like (?&name).
     */
    private function parseSubroutineModifier(int $startPosition): ?SubroutineNode
    {
        if (!$this->stream->matchLiteral('&')) {
            return null;
        }

        $name = $this->parseSubroutineName();
        $returned = $this->readReturnedGroups() ?? [];
        $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected ) to close subroutine call', $this->callLeftOpenCode());

        return new SubroutineNode($name, '&', $startPosition, $endToken->position + 1, $returned);
    }

    /**
     * Parses a numeric or R subroutine group modifier like (?R), (?1), (?-1).
     */
    private function parseNumericSubroutineModifier(int $startPosition): ?SubroutineNode
    {
        if ($this->stream->matchLiteral('R')) {
            $returned = $this->readReturnedGroups();
            if (null !== $returned || $this->stream->check(TokenType::GroupClose)) {
                $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected )', ErrorCode::GroupUnclosed);

                return new SubroutineNode('R', '', $startPosition, $endToken->position + 1, $returned ?? []);
            }
            $this->stream->rewind(1);
        }

        $subroutine = $this->parseNumericSubroutine($startPosition);
        if (null !== $subroutine) {
            return $subroutine;
        }

        return null;
    }

    /**
     * Parses numeric subroutine calls like (?1), (?-1), (?0).
     */
    private function parseNumericSubroutine(int $startPos): ?SubroutineNode
    {
        $tokensConsumed = 0;
        $num = '';

        if ($this->stream->matchLiteral('-')) {
            $num = '-';
            $tokensConsumed++;
        } elseif ($this->stream->check(TokenType::Quantifier) && '+' === $this->stream->current()->value) {
            // "+" after "(?" is lexed as a quantifier token; here it is the
            // sign of a relative subroutine call like (?+1).
            $this->stream->advance();
            $num = '+';
            $tokensConsumed++;
        }

        if ($this->state->isLiteralDigitToken()) {
            $num .= $this->stream->current()->value;
            $this->stream->advance();
            $tokensConsumed++;

            // Consume additional digits
            while ($this->stream->check(TokenType::Literal) && Ascii::isDigit($this->stream->current()->value)) {
                $num .= $this->stream->current()->value;
                $this->stream->advance();
                $tokensConsumed++;
            }

            $returned = $this->readReturnedGroups();
            if (null !== $returned || $this->stream->check(TokenType::GroupClose)) {
                $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected )', ErrorCode::GroupUnclosed);

                return new SubroutineNode($num, '', $startPos, $endToken->position + 1, $returned ?? []);
            }

            // Not a valid subroutine, rewind all consumed tokens
            $this->stream->rewind($tokensConsumed);
        } elseif ('-' === $num || '+' === $num) {
            // Only consumed the sign, rewind it
            $this->stream->rewind(1);
        }

        return null;
    }

    /**
     * "(?1(2,<name>))": the groups a call returns, read from PCRE2 10.47 when
     * a "(" follows the reference. PCRE reads the list from the text, and
     * refuses what it cannot take as it reads it: an item that is no number
     * or name, group zero, a relative zero, a group before the first, or a
     * number past 65535. Null when no list follows.
     *
     * @return list<string>|null
     */
    private function readReturnedGroups(): ?array
    {
        // Whatever the lexer made of the "(" and what follows it, as "(?:".
        $open = $this->stream->current()->position;
        if (!$this->supports(PcreFeature::CallsReturnCaptureGroups) || $this->stream->isAtEnd() || '(' !== ($this->pattern[$open] ?? '')) {
            return null;
        }

        [$groups, $at] = $this->groupLists()->readGroupList($open + 1);

        while (!$this->stream->isAtEnd() && $this->stream->current()->position < $at) {
            $this->stream->advance();
        }

        return $groups;
    }

    /**
     * The code of a call by name the pattern ends in: before PCRE2 10.47 the
     * name wants a ")" right after it, from 10.47 the call does.
     */
    private function callLeftOpenCode(): ErrorCode
    {
        return $this->supports(PcreFeature::CallsReturnCaptureGroups) ? ErrorCode::GroupUnclosed : ErrorCode::GroupNameUnterminated;
    }

    /**
     * Reads the name of "(?&name)" or "(?P>name)" as PCRE2's read_name()
     * does: a digit first is refused, then the characters a name holds are
     * read, the name measured, an empty one refused, and, up to PCRE2 10.46,
     * the ")" that must close it looked for right after it. From 10.47 the
     * name is read alone: the caller looks for the groups it returns and the
     * ")".
     */
    private function parseSubroutineName(): string
    {
        $nameStart = $this->stream->current()->position;
        $nameEnd = $this->groupNames->invalidNameOffset($nameStart);

        if ($this->groupNames->startsWithDigit($nameStart)) {
            throw $this->state->error(
                \sprintf('Invalid subroutine name at position %d: a group name must not start with a digit.', $nameEnd),
                ErrorCode::GroupNameInvalid,
                $nameEnd,
            );
        }

        $tooLong = $this->groupNames->tooLongAt($nameStart, $nameEnd);
        if (null !== $tooLong) {
            throw $this->groupNames->tooLongError($nameStart, $nameEnd, $tooLong);
        }

        if ($nameEnd === $nameStart) {
            throw $this->state->error(
                \sprintf('Expected subroutine name at position %d.', $nameStart),
                ErrorCode::GroupNameExpected,
                $nameStart,
            );
        }

        if (!$this->supports(PcreFeature::CallsReturnCaptureGroups) && ')' !== ($this->pattern[$nameEnd] ?? '')) {
            throw $this->state->error(
                \sprintf('Missing ")" to close subroutine call: the name ends at position %d.', $nameEnd),
                ErrorCode::GroupNameUnterminated,
                $nameEnd,
            );
        }

        while (!$this->stream->isAtEnd() && $this->stream->current()->position < $nameEnd) {
            $this->stream->advance();
        }

        return substr($this->pattern, $nameStart, $nameEnd - $nameStart);
    }

    private function groupLists(): GroupListReader
    {
        return $this->groupLists ??= new GroupListReader($this->state);
    }

    private function supports(PcreFeature $feature): bool
    {
        return $this->target->supports($feature);
    }
}
