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

namespace PHPRegex\LanguageServer\Document;

use PHPRegex\LanguageServer\Converter\PositionConverter;
use PHPRegex\Linter\Extraction\InlineIgnores;
use PHPRegex\Linter\Extraction\MemoryBudget;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpStringLiteral;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Text\Ascii;

/**
 * Finds regex patterns in PHP source code as `regex lint` finds them: the
 * calls the registry knows (the native functions, the presets, the
 * configured functions), the functions and static methods that declare a
 * pattern parameter with #[RegexPattern] (see PatternDeclarations), and the
 * patterns the file proves through a constant, a variable or a property,
 * found at their literal.
 *
 * An occurrence is one string literal: a pattern built at the call from
 * several pieces, or written as a heredoc, is not found, as no single
 * literal holds it for a diagnostic or an edit to land in.
 *
 * @internal
 */
final class RegexFinder
{
    /**
     * The modifiers a call may add to the pattern its literal spells, as
     * Nette's Strings::match(..., utf8: true) adds "u".
     */
    private const MODIFIERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private readonly TokenBasedExtractionStrategy $extractor;

    /**
     * The extractor told about the declarations last read: a keystroke that
     * declares nothing new builds none.
     */
    private ?TokenBasedExtractionStrategy $declared = null;

    /**
     * The specs and the shadowing namespaced functions the declared
     * extractor was built from.
     *
     * @var array{list<string>, list<string>}
     */
    private array $declaredWith = [[], []];

    /**
     * How many texts the finder read whole: an edit read in place is not
     * one.
     */
    private int $reads = 0;

    /**
     * The last text an edit was read in place into, and its tokens: the
     * next keystroke edits it.
     *
     * @var array{string, list<array{int, string, int}|string>}|null
     */
    private ?array $tokenized = null;

    /**
     * @param PatternFunctionRegistry|null $registry the calls read, every preset by default
     */
    public function __construct(?PatternFunctionRegistry $registry = null)
    {
        $this->extractor = new TokenBasedExtractionStrategy([], $registry ?? PatternFunctionRegistry::defaults());
    }

    /**
     * Find all regex patterns in PHP content, ordered by offset.
     *
     * @return list<RegexOccurrence>
     */
    public function find(string $content, ?PatternDeclarations $declarations = null): array
    {
        $this->reads++;

        return $this->findKnownCalls($content, $this->extractorOf($declarations));
    }

    /**
     * The patterns of a text after an edit, from those this finder found in
     * the text before it with the same declarations: an edit inside the
     * literal a call takes whole as its pattern is read in place, any other
     * reads the edited text whole. Either way the answer is find()'s.
     *
     * @param list<RegexOccurrence> $occurrences what find() gave for $before
     *
     * @return list<RegexOccurrence>
     */
    public function findAfterEdit(string $before, array $occurrences, string $after, ?PatternDeclarations $declarations = null): array
    {
        return $this->readInPlace($before, $occurrences, $after, $this->extractorOf($declarations)) ?? $this->find($after, $declarations);
    }

    private function extractorOf(?PatternDeclarations $declarations): TokenBasedExtractionStrategy
    {
        return null === $declarations ? $this->extractor : $this->extractorFor(
            $declarations->specs(),
            $declarations->plainFunctions($this->extractor->customGlobalFunctions()),
        );
    }

    /**
     * The patterns after an edit that rewrites only the inside of one
     * string literal a call takes whole as its pattern, no line break
     * written or erased; null for any other edit.
     *
     * Why the rest of the text reads the same. The two texts tokenize alike
     * but for that literal, which stays one quoted string, its line and the
     * line of every other token unchanged: this is checked, token by token.
     * The extractor reads the text of a quoted string in four places: as a
     * pattern argument of a call, as an operand of a value the file proves
     * (an assignment, a constant, define(), an array item, a pattern
     * argument no literal gives: never what a call's parentheses hold), as
     * a key of an option array, and as the version or the operator of
     * version_compare(). A literal standing alone between "(", "," or a
     * named argument's ":" and "," or ")" of a call other than define()
     * and version_compare() is read in the first place only. So every
     * other occurrence stands as it stood, moved by the bytes the edit
     * added, and the literal's are its new value followed by what each
     * call adds to it, as long as the value opens with a delimiter.
     *
     * @param list<RegexOccurrence> $occurrences
     *
     * @return list<RegexOccurrence>|null
     */
    private function readInPlace(string $before, array $occurrences, string $after, TokenBasedExtractionStrategy $extractor): ?array
    {
        if ($before === $after) {
            return $occurrences;
        }

        // The bytes both texts start with, then end with: the edit is what
        // lies between.
        $shorter = min(\strlen($before), \strlen($after));
        $prefix = min(strspn($before ^ $after, "\0"), $shorter);
        $suffix = min(strspn(strrev($before) ^ strrev($after), "\0"), $shorter - $prefix);
        $removedEnd = \strlen($before) - $suffix;
        $delta = \strlen($after) - \strlen($before);

        // Its quotes stand where they stood.
        $edited = null;
        foreach ($occurrences as $occurrence) {
            if ($occurrence->byteOffset < $prefix && $removedEnd < $occurrence->byteOffset + \strlen($occurrence->literal)) {
                $edited = $occurrence;

                break;
            }
        }

        $removed = substr($before, $prefix, $removedEnd - $prefix);
        $added = substr($after, $prefix, $removedEnd + $delta - $prefix);
        if (null === $edited || false !== strpbrk($removed.$added, "\r\n")
            || !MemoryBudget::allows($after, MemoryBudget::TOKENIZE_FACTOR) || !$extractor->readsContent($after)) {
            return null;
        }

        $at = $edited->byteOffset;
        $literal = substr($after, $at, \strlen($edited->literal) + $delta);
        $tokens = token_get_all($after);
        $index = self::stringTokenAt($tokens, $at, $literal);
        if (null === $index || !self::isWholeArgument($tokens, $index) || !self::tokenizeAlike($this->tokensOf($before), $tokens, $index)) {
            return null;
        }

        $patterns = self::patternsOf($occurrences, $at, $edited->literal, $literal);
        if (null === $patterns) {
            return null;
        }

        $this->tokenized = [$after, $tokens];
        $end = PositionConverter::positionIn($after, $at + \strlen($literal));
        $read = [];
        foreach ($occurrences as $occurrence) {
            if ($occurrence->byteOffset < $at) {
                $read[] = $occurrence;
            } elseif ($occurrence->byteOffset === $at) {
                $read[] = new RegexOccurrence(array_shift($patterns) ?? '', $occurrence->start, $end, $at, $literal, $occurrence->ignored, $occurrence->line, $occurrence->guard);
            } else {
                // Past the literal on its last line, the columns moved.
                $offset = $occurrence->byteOffset + $delta;
                $read[] = new RegexOccurrence(
                    $occurrence->pattern,
                    $occurrence->start['line'] === $end['line'] ? PositionConverter::positionIn($after, $offset) : $occurrence->start,
                    $occurrence->end['line'] === $end['line'] ? PositionConverter::positionIn($after, $offset + \strlen($occurrence->literal)) : $occurrence->end,
                    $offset,
                    $occurrence->literal,
                    $occurrence->ignored,
                    $occurrence->line,
                    $occurrence->guard,
                );
            }
        }

        return $read;
    }

    /**
     * The tokens of the text, kept from the last edit read in place when it
     * is that text.
     *
     * @return list<array{int, string, int}|string>
     */
    private function tokensOf(string $content): array
    {
        return null !== $this->tokenized && $this->tokenized[0] === $content ? $this->tokenized[1] : token_get_all($content);
    }

    /**
     * The index of the token holding the literal at the offset, when it is
     * that one quoted string, "b" before it or not.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function stringTokenAt(array $tokens, int $offset, string $literal): ?int
    {
        // The offset is inside the text: some token holds it.
        $index = 0;
        $start = 0;
        $text = '';
        foreach ($tokens as $index => $token) {
            $text = \is_array($token) ? $token[1] : $token;
            if ($start + \strlen($text) > $offset) {
                break;
            }

            $start += \strlen($text);
        }

        $quoted = \is_array($tokens[$index] ?? null) && \T_CONSTANT_ENCAPSED_STRING === $tokens[$index][0];

        return $quoted && \in_array(substr($text, 0, $offset - $start), ['', 'b', 'B'], true) && substr($text, $offset - $start) === $literal ? $index : null;
    }

    /**
     * Whether the token is a call's argument by itself: between "(" or ","
     * or a named argument's ":", and "," or ")", in the parentheses of a
     * function, a method, a constructor or an attribute, which are neither
     * define() nor version_compare().
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function isWholeArgument(array $tokens, int $index): bool
    {
        $next = self::significant($tokens, $index, 1);
        $previous = self::significant($tokens, $index, -1);
        if (null === $next || null === $previous || !\in_array($tokens[$next], [',', ')'], true)) {
            return false;
        }

        if (':' === $tokens[$previous]) {
            $name = self::significant($tokens, $previous, -1);
            $previous = null === $name || !\is_array($tokens[$name]) || \T_STRING !== $tokens[$name][0] ? null : self::significant($tokens, $name, -1);
        }

        $open = null === $previous ? null : match ($tokens[$previous]) {
            '(' => $previous,
            ',' => self::opener($tokens, $previous),
            default => null,
        };
        $callee = null === $open || '(' !== $tokens[$open] ? null : self::significant($tokens, $open, -1);
        $token = null === $callee ? null : $tokens[$callee];
        if (!\is_array($token)) {
            return false;
        }

        if (\in_array($token[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE], true)) {
            return !\in_array(Ascii::lower(ltrim($token[1], '\\')), ['define', 'version_compare'], true);
        }

        // A method named by a keyword, as Preg::match().
        $operator = self::significant($tokens, $callee, -1);

        return 1 === LibraryPcre::match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $token[1])
            && null !== $operator && \is_array($tokens[$operator])
            && \in_array($tokens[$operator][0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON], true);
    }

    /**
     * The bracket left open before the token, past the pairs it closes.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function opener(array $tokens, int $index): ?int
    {
        $depth = 0;
        $i = $index;
        while (--$i >= 0) {
            $token = $tokens[$i];
            $opens = \in_array($token, ['(', '[', '{'], true) || (\is_array($token) && \in_array($token[0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES, \T_ATTRIBUTE], true));
            if ($opens && 0 === $depth) {
                break;
            }

            $depth += \in_array($token, [')', ']', '}'], true) ? 1 : ($opens ? -1 : 0);
        }

        return $i >= 0 ? $i : null;
    }

    /**
     * The next token, or the one before, that is no space nor comment.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function significant(array $tokens, int $index, int $step): ?int
    {
        for ($i = $index + $step; isset($tokens[$i]); $i += $step) {
            if (!\is_array($tokens[$i]) || !\in_array($tokens[$i][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Whether the two token lists are one but for the token at the index,
     * which is a quoted string in both.
     *
     * @param list<array{int, string, int}|string> $before
     * @param list<array{int, string, int}|string> $after
     */
    private static function tokenizeAlike(array $before, array $after, int $index): bool
    {
        $quoted = \is_array($before[$index] ?? null) && \T_CONSTANT_ENCAPSED_STRING === $before[$index][0];
        $before[$index] = $after[$index];

        return $quoted && $before === $after;
    }

    /**
     * The patterns the literal gives after the edit, in the order of the
     * readings at its offset: its new value, then what each call adds to
     * it. Null when the value no longer opens with a delimiter, so that no
     * pattern is read there, or a reading does not start with the value.
     *
     * @param list<RegexOccurrence> $occurrences
     *
     * @return list<string>|null
     */
    private static function patternsOf(array $occurrences, int $offset, string $before, string $after): ?array
    {
        $old = PhpStringLiteral::decode($before) ?? '';
        $new = PhpStringLiteral::decode($after);
        if (null === $new || '' === Ascii::trimLeadingSpaces($old) || '' === Ascii::trimLeadingSpaces($new) || !self::opensWithADelimiter($new)) {
            return null;
        }

        $patterns = [];
        foreach ($occurrences as $occurrence) {
            if ($offset === $occurrence->byteOffset) {
                $patterns[] = str_starts_with($occurrence->pattern, $old) ? $new.substr($occurrence->pattern, \strlen($old)) : null;
            }
        }

        return \in_array(null, $patterns, true) ? null : array_values(array_filter($patterns, \is_string(...)));
    }

    /**
     * The patterns of the calls the extractor knows, one per literal and
     * pattern: a literal read as "/\xFF/" by one call and "/\xFF/u" by
     * another gives both, each with its own diagnostics on the literal.
     *
     * @return list<RegexOccurrence>
     */
    private function findKnownCalls(string $content, TokenBasedExtractionStrategy $extractor): array
    {
        $positions = null;
        $suppressed = null;
        $found = [];
        foreach ($extractor->extractSource($content) as $occurrence) {
            $offset = $occurrence->fileOffset;
            if (null === $offset || isset($found[$offset."\0".$occurrence->pattern]) || !self::opensWithADelimiter($occurrence->pattern)) {
                continue;
            }

            // b'/a/' is a plain string: the literal is what the quotes hold,
            // the b stays out of its range and of any edit to it.
            $offset += \in_array($content[$offset] ?? '', ['b', 'B'], true) ? 1 : 0;
            $literal = self::literalAt($content, $offset);
            if (null === $literal || !self::spells($content, $offset, $literal, $occurrence->pattern)) {
                continue;
            }

            $positions ??= new PositionConverter($content);
            // The markers `regex lint` reads, on the line of the literal.
            $suppressed ??= InlineIgnores::suppressedLines($content);
            $found[$occurrence->fileOffset."\0".$occurrence->pattern] = new RegexOccurrence(
                pattern: $occurrence->pattern,
                start: $positions->offsetToPosition($offset),
                end: $positions->offsetToPosition($offset + \strlen($literal)),
                byteOffset: $offset,
                literal: $literal,
                ignored: isset($suppressed[$occurrence->line]),
                line: $occurrence->line,
                guard: $occurrence->guard,
            );
        }

        // In the order of the source; the readings of one literal in the
        // order of their calls.
        $found = array_values($found);
        usort($found, static fn (RegexOccurrence $a, RegexOccurrence $b): int => $a->byteOffset <=> $b->byteOffset);

        return $found;
    }

    /**
     * The extractor that reads the calls to the declared functions too, and
     * leaves to a namespace's own function the unqualified calls it
     * reaches.
     *
     * @param list<string> $specs
     * @param list<string> $plain
     */
    private function extractorFor(array $specs, array $plain): TokenBasedExtractionStrategy
    {
        if ([] === $specs && [] === $plain) {
            return $this->extractor;
        }

        if (null === $this->declared || [$specs, $plain] !== $this->declaredWith) {
            $this->declared = $this->extractor->withPatternFunctions($specs, $plain);
            $this->declaredWith = [$specs, $plain];
        }

        return $this->declared;
    }

    /**
     * The quoted string literal that starts at $offset, quotes included.
     */
    private static function literalAt(string $content, int $offset): ?string
    {
        $quote = $content[$offset] ?? '';
        if ("'" !== $quote && '"' !== $quote) {
            return null;
        }

        // The offset starts a string token the tokenizer closed: its closing
        // quote is in the content, and the end of the content stops the
        // search all the same.
        $stops = $quote.'\\';
        $i = $offset + 1;
        while (true) {
            $i += strcspn($content, $stops, $i);
            if ($quote === ($content[$i] ?? $quote)) {
                return substr($content, $offset, $i - $offset + 1);
            }

            // A backslash: the byte after it is escaped.
            $i += 2;
        }
    }

    /**
     * Whether the literal at $offset spells the pattern, but for the
     * modifiers a call adds after it: a literal the source concatenates
     * with more ('/a/' . 'i') is only a piece of the pattern.
     */
    private static function spells(string $content, int $offset, string $literal, string $pattern): bool
    {
        // A closed literal always decodes, and the pattern read there starts
        // with its value.
        $value = PhpStringLiteral::decode($literal) ?? '';
        $added = \strlen($pattern) - \strlen($value);
        if (0 === $added) {
            return $value === $pattern;
        }

        $after = $offset + \strlen($literal);
        $after += strspn($content, " \t\r\n", $after);

        return str_starts_with($pattern, $value) && strspn($pattern, self::MODIFIERS, \strlen($value)) === $added && '.' !== ($content[$after] ?? '');
    }

    /**
     * Whether the string opens with a delimiter PHP accepts, after the
     * spaces it skips: any byte but a letter, a digit, a backslash and NUL.
     * A string that does not is not taken for a pattern; one that does is
     * read as lint reads it, an unclosed or invalid body included.
     */
    private static function opensWithADelimiter(string $pattern): bool
    {
        $delimiter = Ascii::trimLeadingSpaces($pattern)[0] ?? '';

        return '' !== $delimiter && "\0" !== $delimiter && '\\' !== $delimiter && !Ascii::isAlnum($delimiter);
    }
}
