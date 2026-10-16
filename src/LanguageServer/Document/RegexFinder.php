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
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpStringLiteral;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Parser\Internal\LibraryPcre;

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
        if (null === $declarations) {
            return $this->findKnownCalls($content, $this->extractor);
        }

        return $this->findKnownCalls($content, $this->extractorFor(
            $declarations->specs(),
            $declarations->plainFunctions($this->extractor->customGlobalFunctions()),
        ));
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
            if (null === $offset || isset($found[$offset."\0".$occurrence->pattern]) || !$this->isValidRegexDelimiter($occurrence->pattern)) {
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
     * Check if a string looks like a regex with valid delimiter.
     */
    private function isValidRegexDelimiter(string $pattern): bool
    {
        if (\strlen($pattern) < 2) {
            return false;
        }

        $delimiter = $pattern[0];

        // Common regex delimiters
        if (LibraryPcre::match('/^[\/~#@!%]/', $delimiter)) {
            return true;
        }

        // Paired delimiters
        $pairs = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];

        return isset($pairs[$delimiter]);
    }
}
