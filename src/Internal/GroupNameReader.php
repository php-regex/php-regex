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

use RegexParser\Exception\SyntaxErrorException;
use RegexParser\TokenStream;
use RegexParser\TokenType;

/**
 * Reads the name of a group, whichever way the pattern spells it.
 *
 * PCRE takes "(?<name>", "(?'name'", "(?P<name>" and "(?P=name", and Python
 * patterns bring double quotes along; the name itself is the same in all of
 * them. The reader also keeps the names already used, since a pattern may
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
     *
     * @throws SyntaxErrorException
     */
    public function read(bool $register = true, ?int $number = null): string
    {
        $quote = $this->openingQuote();
        $nameStart = $this->stream->current()->position;
        $name = $this->readName($quote);
        $nameEnd = $this->stream->current()->position;

        if (null !== $quote) {
            $this->closeQuote($quote);
        }

        if ('' === $name) {
            throw $this->error(\sprintf('Expected group name at position %d', $nameStart), $nameStart);
        }

        // PCRE group names are word characters only and must not start with a digit.
        $namePattern = $this->unicodeNames ? '/^[_\p{L}][_\p{L}\p{Nd}]*+$/u' : '/^[A-Za-z_]\w*+$/';
        if (1 !== preg_match($namePattern, $name)) {
            throw $this->error(
                \sprintf(
                    'Invalid group name "%s": names must contain only word characters and must not start with a digit.',
                    $name,
                ),
                $this->invalidNameOffset($nameStart),
            );
        }

        if (\strlen($name) > $this->maxNameLength) {
            throw $this->error(
                \sprintf('Group name is too long: %d code units, PCRE allows at most %d.', \strlen($name), $this->maxNameLength),
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
                return $position + \strlen($matches[0]);
            }

            preg_match('/\G[_\p{L}\p{Nd}]*+/u', $pattern, $matches, 0, $position);
        } else {
            if (ctype_digit($pattern[$position] ?? '')) {
                return $position + 1;
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
            throw $this->error(\sprintf('Duplicate group name "%s" at position %d.', $name, $position), $position);
        }

        $this->used[$name][] = $number ?? 0;
        if (null !== $number) {
            $this->namesByNumber[$number] = $name;
        }
    }

    private function openingQuote(): ?string
    {
        if (!$this->stream->checkLiteral("'") && !$this->stream->checkLiteral('"')) {
            return null;
        }

        $quote = $this->stream->current()->value;
        $this->stream->advance();

        return $quote;
    }

    /**
     * @throws SyntaxErrorException
     */
    private function readName(?string $quote): string
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

                throw $this->error(\sprintf('Unexpected token "%s" in group name', $written), $token->position);
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
                $this->stream->current()->position,
            );
        }

        $this->stream->advance();
    }

    private function error(string $message, int $position): SyntaxErrorException
    {
        return SyntaxErrorException::withContext($message, $position, $this->stream->getPattern());
    }
}
