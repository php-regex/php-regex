<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Internal;

use RegexParser\ErrorCode;
use RegexParser\Exception\SyntaxErrorException;
use RegexParser\TokenStream;
use RegexParser\TokenType;

/**
 * Reads the name of a group, whichever way the pattern spells it.
 *
 * PCRE takes "(?<name>", "(?'name'", "(?P<name>" and "(?P=name"; the name
 * itself is the same in all of them, and only the "(?'name'" spellings put
 * it in quotes. The reader also keeps the names already used, since a pattern may
 * only repeat one under the "J" modifier.
 *
 * @internal
 */
final class GroupNameReader
{
    /**
     * The longest name PCRE2 takes from 10.44, in code units; before, 32.
     */
    public const MAX_NAME_LENGTH = 128;

    public const MAX_NAME_LENGTH_BEFORE_PCRE_1044 = 32;

    private int $maxNameLength = self::MAX_NAME_LENGTH;

    /**
     * Whether a name starting with a digit is refused past the digit, as
     * PCRE2 10.47 does, rather than on it.
     */
    private bool $pastTheFault = true;

    /**
     * The group numbers each name was given so far.
     *
     * @var array<string, list<int>>
     */
    private array $used = [];

    /**
     * The name each numbered group was given, when it has one.
     *
     * @var array<int, string>
     */
    private array $namesByNumber = [];

    private bool $duplicatesAllowed = false;

    private bool $unicodeNames = false;

    public function __construct(private readonly TokenStream $stream) {}

    /**
     * The longest name the PCRE2 release read takes, in code units.
     */
    public function limitNameLength(int $maxNameLength): void
    {
        $this->maxNameLength = $maxNameLength;
    }

    public function reportPastTheFault(bool $pastTheFault): void
    {
        $this->pastTheFault = $pastTheFault;
    }

    public function maxNameLength(): int
    {
        return $this->maxNameLength;
    }

    /**
     * Whether the pattern currently allows two groups to share a name, which
     * the "J" modifier says — globally, or inside a "(?J:...)" group.
     */
    public function allowDuplicates(bool $allowed): void
    {
        $this->duplicatesAllowed = $allowed;
    }

    /**
     * Whether the pattern is in Unicode mode, where PCRE2 10.43+ takes a name
     * made of letters of any script, decimal digits and "_", not starting
     * with a digit: "(?<nämed>b)".
     */
    public function readUnicodeNames(bool $unicode): void
    {
        $this->unicodeNames = $unicode;
    }

    public function duplicatesAllowed(): bool
    {
        return $this->duplicatesAllowed;
    }

    public function forget(): void
    {
        $this->used = [];
        // Unreachable today: nothing calls forget(). Kept in step with $used
        // so a future caller does not keep stale group numbers.
        $this->namesByNumber = [];
    }

    /**
     * A name PCRE refuses is reported where PCRE stops reading it.
     *
     * @param bool     $register false for a name that refers to a group
     *                           rather than declaring one
     * @param int|null $number   the number of the group the name declares,
     *                           when the caller counts them
     * @param bool     $quoted   whether the name stands in single quotes, as
     *                           in "(?'name'" and "(?('name')": elsewhere a
     *                           quote starts no name
     *
     * @throws SyntaxErrorException
     */
    public function read(bool $register = true, ?int $number = null, bool $quoted = false): string
    {
        $quote = $quoted ? $this->openingQuote() : null;
        $nameStart = $this->stream->current()->position;
        $name = $this->readName($quote, $nameStart);
        $nameEnd = $this->stream->current()->position;

        // PCRE wants a name before anything that closes it.
        if ('' === $name) {
            throw $this->error(\sprintf('Expected group name at position %d', $nameStart), ErrorCode::GroupNameExpected, $nameStart);
        }

        if (null !== $quote) {
            $this->closeQuote($quote);
        }

        // PCRE group names are word characters only and must not start with
        // a digit: PCRE reads the characters a name may hold, and wants what
        // closes the name right after them.
        $namePattern = $this->unicodeNames ? '/^[_\p{L}][_\p{L}\p{Nd}]*+$/u' : '/^[A-Za-z_]\w*+$/';
        if (1 !== preg_match($namePattern, $name)) {
            $offset = $this->invalidNameOffset($nameStart);
            $fault = $this->nameFault($nameStart, $offset);

            // PCRE measures the name it read before it looks for what closes it.
            if (ErrorCode::GroupNameUnterminated === $fault && $offset - $nameStart > $this->maxNameLength) {
                throw $this->error(
                    \sprintf('Group name is too long: %d code units, PCRE allows at most %d.', $offset - $nameStart, $this->maxNameLength),
                    ErrorCode::GroupNameTooLong,
                    $offset,
                );
            }

            throw $this->error(
                match ($fault) {
                    ErrorCode::GroupNameExpected => \sprintf('Expected group name at position %d, found "%s".', $offset, $name),
                    ErrorCode::GroupNameUnterminated => \sprintf('Invalid group name "%s": the name ends at position %d, and nothing closes it there.', $name, $offset),
                    default => \sprintf('Invalid group name "%s": names must contain only word characters and must not start with a digit.', $name),
                },
                $fault,
                $offset,
            );
        }

        if (\strlen($name) > $this->maxNameLength) {
            throw $this->error(
                \sprintf('Group name is too long: %d code units, PCRE allows at most %d.', \strlen($name), $this->maxNameLength),
                ErrorCode::GroupNameTooLong,
                $nameEnd,
            );
        }

        if ($register) {
            if (null !== $number && $name !== ($this->namesByNumber[$number] ?? $name)) {
                // Only a branch reset gives two groups the same number.
                throw $this->error(
                    \sprintf(
                        'Different names for groups of the same number are not allowed: group %d is already named "%s", not "%s".',
                        $number,
                        $this->namesByNumber[$number],
                        $name,
                    ),
                    ErrorCode::GroupNameConflict,
                    $nameEnd + 1,
                );
            }

            // PCRE finds a duplicate once it has read the name and what
            // closes it.
            $this->register($name, $nameEnd + 1, $number);
        }

        return $name;
    }

    /**
     * Where PCRE stops reading a name it refuses, which starts at $position:
     * past a leading digit, or where the characters a name may hold end —
     * at $position itself when there is none.
     */
    public function invalidNameOffset(int $position): int
    {
        $pattern = $this->stream->getPattern();

        if ($this->unicodeNames) {
            if (1 === preg_match('/\G\p{Nd}/u', $pattern, $matches, 0, $position)) {
                return $position + ($this->pastTheFault ? \strlen($matches[0]) : 0);
            }

            preg_match('/\G[_\p{L}\p{Nd}]*+/u', $pattern, $matches, 0, $position);
        } else {
            if (Ascii::isDigit($pattern[$position] ?? '')) {
                return $position + ($this->pastTheFault ? 1 : 0);
            }

            preg_match('/\G\w*+/', $pattern, $matches, 0, $position);
        }

        return $position + \strlen($matches[0] ?? '');
    }

    /**
     * @param int|null $number the number of the group being named: a branch
     *                         reset may give the same name to groups that
     *                         share a number, which is not a duplicate
     *
     * @throws SyntaxErrorException
     */
    public function register(string $name, int $position, ?int $number = null): void
    {
        $sameGroup = null !== $number && \in_array($number, $this->used[$name] ?? [], true);

        if (isset($this->used[$name]) && !$sameGroup && !$this->duplicatesAllowed) {
            throw $this->error(\sprintf('Duplicate group name "%s" at position %d.', $name, $position), ErrorCode::GroupDuplicateName, $position);
        }

        $this->used[$name][] = $number ?? 0;
        if (null !== $number) {
            $this->namesByNumber[$number] = $name;
        }
    }

    /**
     * What PCRE reports for a name that starts at $nameStart and that it
     * stops reading at $stop: a name starting with a digit, no name at all
     * when it stops where the name starts, or else a name nothing closes.
     */
    private function nameFault(int $nameStart, int $stop): ErrorCode
    {
        $digit = $this->unicodeNames ? '/\G\p{Nd}/u' : '/\G[0-9]/';

        return match (true) {
            1 === preg_match($digit, $this->stream->getPattern(), $matches, 0, $nameStart) => ErrorCode::GroupNameInvalid,
            $stop === $nameStart => ErrorCode::GroupNameExpected,
            default => ErrorCode::GroupNameUnterminated,
        };
    }

    private function openingQuote(): ?string
    {
        if (!$this->stream->checkLiteral("'")) {
            return null;
        }

        $this->stream->advance();

        return "'";
    }

    /**
     * @throws SyntaxErrorException
     */
    private function readName(?string $quote, int $nameStart): string
    {
        $name = '';

        while (!$this->stream->checkLiteral('>') && !$this->stream->checkLiteral('}') && !$this->stream->isAtEnd()) {
            if (null !== $quote && $this->stream->checkLiteral($quote)) {
                break;
            }

            if ($this->stream->check(TokenType::T_GROUP_CLOSE)) {
                break;
            }

            // A name holds no escape: PCRE stops on the backslash.
            if (!$this->stream->check(TokenType::T_LITERAL)) {
                $token = $this->stream->current();
                $written = substr($this->stream->getPattern(), $token->position, max(1, $token->end() - $token->position));

                throw $this->error(\sprintf('Unexpected token "%s" in group name', $written), $this->nameFault($nameStart, $token->position), $token->position);
            }

            $name .= $this->stream->current()->value;
            $this->stream->advance();
        }

        return $name;
    }

    /**
     * @throws SyntaxErrorException
     */
    private function closeQuote(string $quote): void
    {
        if (!$this->stream->checkLiteral($quote)) {
            throw $this->error(
                \sprintf(
                    'Expected closing quote "%s" for group name at position %d',
                    $quote,
                    $this->stream->current()->position,
                ),
                ErrorCode::GroupNameUnterminated,
                $this->stream->current()->position,
            );
        }

        $this->stream->advance();
    }

    private function error(string $message, ErrorCode $code, int $position): SyntaxErrorException
    {
        return SyntaxErrorException::withContext($message, $code, $position, $this->stream->getPattern());
    }
}
