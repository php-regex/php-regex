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
use PHPRegex\Parser\Exception\SyntaxErrorException;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Token\TokenStream;
use PHPRegex\Parser\Token\TokenType;

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

    /**
     * The longest name the release read takes, in code units.
     */
    private readonly int $maxNameLength;

    /**
     * Whether a name is read whole before its length is judged, as from
     * PCRE2 10.33, and one too long refused where it ends; before, at the
     * character that takes it past the limit.
     */
    private readonly bool $readsWhole;

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

    /**
     * The release whose Unicode tables say which letters and digits a name
     * holds, or null when a name holds ASCII word characters only.
     */
    private ?PcreTarget $unicodeTables = null;

    /**
     * @param PcreTarget $target the release the names are read for: how long
     *                           one may be, and where PCRE refuses one
     */
    public function __construct(private readonly TokenStream $stream, private readonly PcreTarget $target)
    {
        $this->maxNameLength = self::maxNameLength($target);
        $this->readsWhole = $target->supports(PcreFeature::NamesReadWhole);
    }

    /**
     * The longest name $target takes, a group's or a verb's, in code units:
     * 32 up to PCRE2 10.43, 128 from 10.44, which PHP bundles from 8.4.
     */
    public static function maxNameLength(PcreTarget $target): int
    {
        return $target->supports(PcreFeature::LongGroupNames) ? self::MAX_NAME_LENGTH : self::MAX_NAME_LENGTH_BEFORE_PCRE_1044;
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
     * In Unicode mode PCRE2 10.33+ takes a name made of letters of any
     * script, decimal digits and "_", not starting with a digit:
     * "(?<nämed>b)"; the letters and digits of the Unicode tables of $target,
     * or ASCII word characters only when null.
     */
    public function readUnicodeNames(?PcreTarget $target): void
    {
        $this->unicodeTables = $target;
    }

    public function duplicatesAllowed(): bool
    {
        return $this->duplicatesAllowed;
    }

    /**
     * Share the names $other meets, with the groups they name, from the ones
     * it met so far on: the body of an alphabetic assertion, read apart,
     * shares its names with the pattern around it, as the body of "(?="
     * does. Shared, not copied: a pattern of many bodies does not copy its
     * names once per body.
     */
    public function shareNamesWith(self $other): void
    {
        $this->used = &$other->used;
        $this->namesByNumber = &$other->namesByNumber;
    }

    /**
     * The names the groups read so far were given.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->used);
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
            throw $this->error(\sprintf('Expected group name at position %d.', $nameStart), ErrorCode::GroupNameExpected, $nameStart);
        }

        // PCRE group names are word characters only and must not start with
        // a digit: PCRE reads the characters a name may hold, and wants what
        // closes the name right after them.
        $valid = null !== $this->unicodeTables ? NameCharacters::isName($name, $this->unicodeTables) : 1 === LibraryPcre::match('/^[A-Za-z_]\w*+\z/', $name);
        if (!$valid) {
            throw $this->invalidName($name, $nameStart);
        }

        $tooLong = $this->tooLongAt($nameStart, $nameEnd);
        if (null !== $tooLong) {
            throw $this->tooLongError($nameStart, $nameEnd, $tooLong);
        }

        // Only then the quote: "(?'a-" is refused on the "-", and "(?'1" on
        // the digit, though no quote closes either.
        if (null !== $quote) {
            $this->closeQuote($quote);
        }

        // "(?<n" with no ">" after the name: PCRE refuses the missing
        // terminator before it looks for a duplicate.
        if ($register && (null !== $quote || '>' === $this->stream->current()->value)) {
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
     * Where PCRE refuses as too long the name that runs from $nameStart to
     * $nameEnd, or null when it is not: where it ends, PCRE2 measuring it
     * once read whole; on PCRE2 10.32, which counts its characters as it
     * reads them, past the one that passes the limit.
     */
    public function tooLongAt(int $nameStart, int $nameEnd): ?int
    {
        if ($nameEnd - $nameStart <= $this->maxNameLength) {
            return null;
        }

        return $this->readsWhole ? $nameEnd : $nameStart + $this->maxNameLength + 1;
    }

    /**
     * The error for the name from $nameStart to $nameEnd that PCRE refuses as
     * too long at $offset.
     */
    public function tooLongError(int $nameStart, int $nameEnd, int $offset): SyntaxErrorException
    {
        return $this->error(
            \sprintf('Group name is too long: %d code units, PCRE allows at most %d.', $nameEnd - $nameStart, $this->maxNameLength),
            ErrorCode::GroupNameTooLong,
            $offset,
        );
    }

    /**
     * Whether a decimal digit starts the name at $position: any decimal
     * digit when names take Unicode letters, an ASCII one otherwise.
     */
    public function startsWithDigit(int $position): bool
    {
        $pattern = $this->stream->getPattern();

        return null !== $this->unicodeTables ? NameCharacters::digitAt($pattern, $position, $this->unicodeTables) > 0 : Ascii::isDigit($pattern[$position] ?? '');
    }

    /**
     * Where PCRE stops reading a name it refuses, which starts at $position:
     * past a leading digit, or where the characters a name may hold end —
     * at $position itself when there is none.
     */
    public function invalidNameOffset(int $position): int
    {
        $pattern = $this->stream->getPattern();

        if (null !== $this->unicodeTables) {
            $digit = NameCharacters::digitAt($pattern, $position, $this->unicodeTables);
            if ($digit > 0) {
                return $this->target->faultOffset($position + $digit, $digit);
            }

            return NameCharacters::end($pattern, $position, $this->unicodeTables);
        }

        if (Ascii::isDigit($pattern[$position] ?? '')) {
            return $this->target->faultOffset($position + 1);
        }

        LibraryPcre::match('/\G\w*+/', $pattern, $matches, 0, $position);

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
    public function nameFault(int $nameStart, int $stop): ErrorCode
    {
        return match (true) {
            $this->startsWithDigit($nameStart) => ErrorCode::GroupNameInvalid,
            $stop === $nameStart => ErrorCode::GroupNameExpected,
            default => ErrorCode::GroupNameUnterminated,
        };
    }

    /**
     * The error for $name, which starts at $nameStart and holds a character
     * no name may hold, reported where PCRE stops reading it.
     */
    private function invalidName(string $name, int $nameStart): SyntaxErrorException
    {
        $offset = $this->invalidNameOffset($nameStart);
        $fault = $this->nameFault($nameStart, $offset);

        // PCRE measures the name it read before it looks for what closes it.
        $tooLong = ErrorCode::GroupNameUnterminated === $fault ? $this->tooLongAt($nameStart, $offset) : null;
        if (null !== $tooLong) {
            return $this->tooLongError($nameStart, $offset, $tooLong);
        }

        return $this->error(
            match ($fault) {
                ErrorCode::GroupNameExpected => \sprintf('Expected group name at position %d, found "%s".', $offset, $name),
                ErrorCode::GroupNameUnterminated => \sprintf('Invalid group name "%s": the name ends at position %d, and nothing closes it there.', $name, $offset),
                default => \sprintf('Invalid group name "%s": names must contain only word characters and must not start with a digit.', $name),
            },
            $fault,
            $offset,
        );
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

            if ($this->stream->check(TokenType::GroupClose)) {
                break;
            }

            // A name holds no escape: PCRE stops on the backslash.
            if (!$this->stream->check(TokenType::Literal)) {
                $token = $this->stream->current();

                // Or before it, on the first character read that no name
                // holds: "(?<a-(" is refused on the "-", whatever follows.
                // A name too long is too long on the token itself: PCRE
                // measures the name before it looks at what stops it.
                $stop = $this->invalidNameOffset($nameStart);
                if ($stop < $token->position || null !== $this->tooLongAt($nameStart, $stop)) {
                    throw $this->invalidName($name, $nameStart);
                }

                $written = substr($this->stream->getPattern(), $token->position, max(1, $token->end() - $token->position));

                throw $this->error(\sprintf('Unexpected token "%s" in group name.', $written), $this->nameFault($nameStart, $token->position), $token->position);
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
                    'Expected closing quote "%s" for group name at position %d.',
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
