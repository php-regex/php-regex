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
     * The longest name PCRE2 10.48 takes, in code units. 10.40 stopped at
     * 32; names are judged as the newer release reads them.
     */
    public const MAX_NAME_LENGTH = 128;

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

    public function __construct(private readonly TokenStream $stream) {}

    /**
     * Whether the pattern currently allows two groups to share a name, which
     * the "J" modifier says — globally, or inside a "(?J:...)" group.
     */
    public function allowDuplicates(bool $allowed): void
    {
        $this->duplicatesAllowed = $allowed;
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
     * @param int|null $errorPosition where to point when the name is wrong,
     *                                for a caller that knows better than the
     *                                token under the cursor
     * @param bool     $register      false for a name that refers to a group
     *                                rather than declaring one
     * @param int|null $number        the number of the group the name
     *                                declares, when the caller counts them
     *
     * @throws SyntaxErrorException
     */
    public function read(?int $errorPosition = null, bool $register = true, ?int $number = null): string
    {
        $nameStart = $errorPosition ?? $this->stream->current()->position;
        $quote = $this->openingQuote();
        $name = $this->readName($quote);
        $nameEnd = $this->stream->current()->position;

        if (null !== $quote) {
            $this->closeQuote($quote);
        }

        if ('' === $name) {
            throw $this->error(\sprintf('Expected group name at position %d', $nameStart), $nameStart);
        }

        // PCRE group names are word characters only and must not start with a digit.
        if (1 !== preg_match('/^[A-Za-z_]\w*+$/', $name)) {
            throw $this->error(
                \sprintf(
                    'Invalid group name "%s": names must contain only word characters and must not start with a digit.',
                    $name,
                ),
                $nameStart,
            );
        }

        if (\strlen($name) > self::MAX_NAME_LENGTH) {
            throw $this->error(
                \sprintf('Group name is too long: %d code units, PCRE allows at most %d.', \strlen($name), self::MAX_NAME_LENGTH),
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

            $this->register($name, $nameStart, $number);
        }

        return $name;
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

            if (!$this->stream->check(TokenType::T_LITERAL) && !$this->stream->check(TokenType::T_LITERAL_ESCAPED)) {
                throw $this->error(
                    \sprintf('Unexpected token "%s" in group name', $this->stream->current()->value),
                    $this->stream->current()->position,
                );
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
