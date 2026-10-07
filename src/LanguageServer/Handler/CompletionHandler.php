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

namespace PHPRegex\LanguageServer\Handler;

use PHPRegex\LanguageServer\Document\DocumentManager;
use PHPRegex\LanguageServer\Document\RegexOccurrence;
use PHPRegex\LanguageServer\Protocol\Message;
use PHPRegex\LanguageServer\Protocol\Response;
use PHPRegex\Parser\Internal\LibraryPcre;

/**
 * Handles textDocument/completion requests for regex patterns.
 *
 * @internal
 */
final readonly class CompletionHandler
{
    // LSP CompletionItemKind constants
    private const KIND_KEYWORD = 14;
    private const KIND_SNIPPET = 15;
    private const KIND_VALUE = 12;

    /**
     * Character class shorthands.
     */
    private const CHAR_CLASSES = [
        ['label' => '\\d', 'detail' => 'Digit [0-9]', 'doc' => 'Matches any digit character (0-9). Use \\D for non-digit.'],
        ['label' => '\\D', 'detail' => 'Non-digit [^0-9]', 'doc' => 'Matches any character that is not a digit.'],
        ['label' => '\\w', 'detail' => 'Word character [a-zA-Z0-9_]', 'doc' => 'Matches any word character (letters, digits, underscore). Use /u flag for Unicode support.'],
        ['label' => '\\W', 'detail' => 'Non-word character', 'doc' => 'Matches any character that is not a word character.'],
        ['label' => '\\s', 'detail' => 'Whitespace', 'doc' => 'Matches any whitespace character (space, tab, newline, etc.).'],
        ['label' => '\\S', 'detail' => 'Non-whitespace', 'doc' => 'Matches any character that is not whitespace.'],
        ['label' => '\\h', 'detail' => 'Horizontal whitespace', 'doc' => 'Matches horizontal whitespace (space, tab).'],
        ['label' => '\\H', 'detail' => 'Non-horizontal whitespace', 'doc' => 'Matches any character that is not horizontal whitespace.'],
        ['label' => '\\v', 'detail' => 'Vertical whitespace', 'doc' => 'Matches vertical whitespace (newline, carriage return, etc.).'],
        ['label' => '\\V', 'detail' => 'Non-vertical whitespace', 'doc' => 'Matches any character that is not vertical whitespace.'],
        ['label' => '\\R', 'detail' => 'Line break', 'doc' => 'Matches any Unicode line break sequence.'],
        ['label' => '\\N', 'detail' => 'Non-newline', 'doc' => 'Matches any character except newline (like . without /s flag).'],
        ['label' => '\\X', 'detail' => 'Unicode grapheme cluster', 'doc' => 'Matches a Unicode extended grapheme cluster (user-perceived character).'],
    ];

    /**
     * Anchors and boundaries.
     */
    private const ANCHORS = [
        ['label' => '^', 'detail' => 'Start of string/line', 'doc' => 'Matches the start of the string, or start of line with /m flag.'],
        ['label' => '$', 'detail' => 'End of string/line', 'doc' => 'Matches the end of the string, or end of line with /m flag.'],
        ['label' => '\\A', 'detail' => 'Absolute start', 'doc' => 'Matches only at the very start of the string (ignores /m flag).'],
        ['label' => '\\Z', 'detail' => 'End (before final newline)', 'doc' => 'Matches at the end of the string or before a trailing newline.'],
        ['label' => '\\z', 'detail' => 'Absolute end', 'doc' => 'Matches only at the very end of the string.'],
        ['label' => '\\b', 'detail' => 'Word boundary', 'doc' => 'Matches at a word boundary (between \\w and \\W).'],
        ['label' => '\\B', 'detail' => 'Non-word boundary', 'doc' => 'Matches where there is no word boundary.'],
        ['label' => '\\G', 'detail' => 'Match position', 'doc' => 'Matches at the position where the previous match ended.'],
    ];

    /**
     * Common quantifiers.
     */
    private const QUANTIFIERS = [
        ['label' => '+', 'detail' => 'One or more', 'doc' => 'Matches the preceding element one or more times (greedy).'],
        ['label' => '*', 'detail' => 'Zero or more', 'doc' => 'Matches the preceding element zero or more times (greedy).'],
        ['label' => '?', 'detail' => 'Zero or one', 'doc' => 'Matches the preceding element zero or one time (optional).'],
        ['label' => '+?', 'detail' => 'One or more (lazy)', 'doc' => 'Matches one or more times, but as few as possible.'],
        ['label' => '*?', 'detail' => 'Zero or more (lazy)', 'doc' => 'Matches zero or more times, but as few as possible.'],
        ['label' => '++', 'detail' => 'One or more (possessive)', 'doc' => 'Matches one or more times without backtracking. Prevents catastrophic backtracking.'],
        ['label' => '*+', 'detail' => 'Zero or more (possessive)', 'doc' => 'Matches zero or more times without backtracking.'],
        ['label' => '{n}', 'detail' => 'Exactly n times', 'doc' => 'Matches exactly n occurrences of the preceding element.', 'insertText' => '{${1:n}}'],
        ['label' => '{n,}', 'detail' => 'At least n times', 'doc' => 'Matches n or more occurrences.', 'insertText' => '{${1:n},}'],
        ['label' => '{n,m}', 'detail' => 'Between n and m times', 'doc' => 'Matches between n and m occurrences (inclusive).', 'insertText' => '{${1:n},${2:m}}'],
    ];

    /**
     * Group constructs.
     */
    private const GROUPS = [
        ['label' => '(...)', 'detail' => 'Capturing group', 'doc' => 'Creates a capturing group. The matched content can be referenced later.', 'insertText' => '(${1})'],
        ['label' => '(?:...)', 'detail' => 'Non-capturing group', 'doc' => 'Groups without capturing. Useful for applying quantifiers to multiple tokens.', 'insertText' => '(?:${1})'],
        ['label' => '(?<name>...)', 'detail' => 'Named capturing group', 'doc' => 'Creates a named capturing group that can be referenced by name.', 'insertText' => '(?<${1:name}>${2})'],
        ['label' => '(?=...)', 'detail' => 'Positive lookahead', 'doc' => 'Zero-width assertion that matches if followed by the pattern.', 'insertText' => '(?=${1})'],
        ['label' => '(?!...)', 'detail' => 'Negative lookahead', 'doc' => 'Zero-width assertion that matches if NOT followed by the pattern.', 'insertText' => '(?!${1})'],
        ['label' => '(?<=...)', 'detail' => 'Positive lookbehind', 'doc' => 'Zero-width assertion that matches if preceded by the pattern.', 'insertText' => '(?<=${1})'],
        ['label' => '(?<!...)', 'detail' => 'Negative lookbehind', 'doc' => 'Zero-width assertion that matches if NOT preceded by the pattern.', 'insertText' => '(?<!${1})'],
        ['label' => '(?>...)', 'detail' => 'Atomic group', 'doc' => 'Non-backtracking group. Prevents catastrophic backtracking.', 'insertText' => '(?>${1})'],
        ['label' => '(?|...)', 'detail' => 'Branch reset group', 'doc' => 'Resets group numbers in each alternative branch.', 'insertText' => '(?|${1})'],
    ];

    /**
     * Unicode property classes.
     */
    private const UNICODE_PROPERTIES = [
        ['label' => '\\p{L}', 'detail' => 'Any letter', 'doc' => 'Matches any kind of letter from any language. Requires /u flag.'],
        ['label' => '\\p{Lu}', 'detail' => 'Uppercase letter', 'doc' => 'Matches an uppercase letter. Requires /u flag.'],
        ['label' => '\\p{Ll}', 'detail' => 'Lowercase letter', 'doc' => 'Matches a lowercase letter. Requires /u flag.'],
        ['label' => '\\p{N}', 'detail' => 'Any number', 'doc' => 'Matches any kind of numeric character. Requires /u flag.'],
        ['label' => '\\p{Nd}', 'detail' => 'Decimal digit', 'doc' => 'Matches a digit in any script (0-9, Arabic-Indic, etc.). Requires /u flag.'],
        ['label' => '\\p{P}', 'detail' => 'Punctuation', 'doc' => 'Matches any kind of punctuation character. Requires /u flag.'],
        ['label' => '\\p{S}', 'detail' => 'Symbol', 'doc' => 'Matches math, currency, or other symbols. Requires /u flag.'],
        ['label' => '\\p{Z}', 'detail' => 'Separator', 'doc' => 'Matches any kind of whitespace or invisible separator. Requires /u flag.'],
        ['label' => '\\p{C}', 'detail' => 'Control/Other', 'doc' => 'Matches control characters and other non-printable characters. Requires /u flag.'],
        ['label' => '\\p{M}', 'detail' => 'Mark', 'doc' => 'Matches combining marks (accents, etc.). Requires /u flag.'],
        ['label' => '\\p{Script=Latin}', 'detail' => 'Latin script', 'doc' => 'Matches characters in the Latin script. Requires /u flag.', 'insertText' => '\\p{Script=${1:Latin}}'],
        ['label' => '\\p{Script=Greek}', 'detail' => 'Greek script', 'doc' => 'Matches characters in the Greek script. Requires /u flag.'],
        ['label' => '\\p{Script=Cyrillic}', 'detail' => 'Cyrillic script', 'doc' => 'Matches characters in the Cyrillic script. Requires /u flag.'],
        ['label' => '\\p{Script=Arabic}', 'detail' => 'Arabic script', 'doc' => 'Matches characters in the Arabic script. Requires /u flag.'],
        ['label' => '\\p{Script=Han}', 'detail' => 'Han (Chinese) script', 'doc' => 'Matches Chinese characters. Requires /u flag.'],
        ['label' => '\\p{Emoji}', 'detail' => 'Emoji', 'doc' => 'Matches emoji characters. Requires /u flag.'],
    ];

    /**
     * Regex flags.
     */
    private const FLAGS = [
        ['label' => 'i', 'detail' => 'Case-insensitive', 'doc' => 'Makes the pattern case-insensitive.'],
        ['label' => 'm', 'detail' => 'Multiline mode', 'doc' => 'Makes ^ and $ match line beginnings/endings, not just string start/end.'],
        ['label' => 's', 'detail' => 'Dot matches newline', 'doc' => 'Makes the dot (.) match newline characters as well.'],
        ['label' => 'x', 'detail' => 'Extended mode', 'doc' => 'Allows whitespace and comments in the pattern for readability.'],
        ['label' => 'u', 'detail' => 'Unicode mode', 'doc' => 'Enables Unicode support. Required for \\p{} properties and proper UTF-8 handling.'],
        ['label' => 'U', 'detail' => 'Ungreedy mode', 'doc' => 'Makes quantifiers lazy by default (inverts ? behavior).'],
        ['label' => 'A', 'detail' => 'Anchored', 'doc' => 'Forces pattern to match only at the start of the string.'],
        ['label' => 'D', 'detail' => 'Dollar end only', 'doc' => 'Makes $ match only at the end of the string, ignoring trailing newlines.'],
        ['label' => 'S', 'detail' => 'Study pattern', 'doc' => 'Studies the pattern for optimizations (PCRE internal).'],
        ['label' => 'J', 'detail' => 'Allow duplicate names', 'doc' => 'Allows duplicate named capturing groups.'],
    ];

    /**
     * POSIX character classes.
     */
    private const POSIX_CLASSES = [
        ['label' => '[:alnum:]', 'detail' => 'Alphanumeric', 'doc' => 'Matches alphanumeric characters [a-zA-Z0-9].'],
        ['label' => '[:alpha:]', 'detail' => 'Alphabetic', 'doc' => 'Matches alphabetic characters [a-zA-Z].'],
        ['label' => '[:ascii:]', 'detail' => 'ASCII', 'doc' => 'Matches ASCII characters [\\x00-\\x7F].'],
        ['label' => '[:blank:]', 'detail' => 'Blank', 'doc' => 'Matches space and tab.'],
        ['label' => '[:cntrl:]', 'detail' => 'Control', 'doc' => 'Matches control characters.'],
        ['label' => '[:digit:]', 'detail' => 'Digit', 'doc' => 'Matches digits [0-9].'],
        ['label' => '[:graph:]', 'detail' => 'Graphical', 'doc' => 'Matches visible characters (not space).'],
        ['label' => '[:lower:]', 'detail' => 'Lowercase', 'doc' => 'Matches lowercase letters [a-z].'],
        ['label' => '[:print:]', 'detail' => 'Printable', 'doc' => 'Matches printable characters including space.'],
        ['label' => '[:punct:]', 'detail' => 'Punctuation', 'doc' => 'Matches punctuation characters.'],
        ['label' => '[:space:]', 'detail' => 'Whitespace', 'doc' => 'Matches whitespace characters.'],
        ['label' => '[:upper:]', 'detail' => 'Uppercase', 'doc' => 'Matches uppercase letters [A-Z].'],
        ['label' => '[:word:]', 'detail' => 'Word character', 'doc' => 'Matches word characters [a-zA-Z0-9_].'],
        ['label' => '[:xdigit:]', 'detail' => 'Hex digit', 'doc' => 'Matches hexadecimal digits [0-9A-Fa-f].'],
    ];

    public function __construct(private DocumentManager $documents) {}

    /**
     * Handle textDocument/completion request.
     */
    public function handle(Message $message): void
    {
        $params = $message->params ?? [];
        /** @var array<string, mixed> $textDocument */
        $textDocument = $params['textDocument'] ?? [];
        $uri = isset($textDocument['uri']) && \is_string($textDocument['uri']) ? $textDocument['uri'] : null;
        /** @var array{line?: int, character?: int}|null $position */
        $position = isset($params['position']) && \is_array($params['position']) ? $params['position'] : null;

        if (null === $message->id || null === $uri || null === $position) {
            Response::success($message->id ?? 0, null);

            return;
        }

        $line = $position['line'] ?? 0;
        $character = $position['character'] ?? 0;

        // Check if we're inside a regex pattern
        $occurrence = $this->documents->getOccurrenceAtPosition($uri, $line, $character);
        if (null === $occurrence) {
            Response::success($message->id, null);

            return;
        }

        // Get the context (what was typed before the cursor)
        $content = $this->documents->getContent($uri);
        if (null === $content) {
            Response::success($message->id, null);

            return;
        }

        $context = $this->getCompletionContext($occurrence, $character);
        $items = $this->getCompletionItems($context);

        Response::success($message->id, [
            'isIncomplete' => false,
            'items' => $items,
        ]);
    }

    /**
     * Get the completion context based on cursor position.
     *
     * @return array{type: string, prefix: string}
     */
    private function getCompletionContext(RegexOccurrence $occurrence, int $character): array
    {
        // Get text from pattern start to cursor
        $patternStart = $occurrence->start['character'];
        $cursorInPattern = $character - $patternStart;

        if ($cursorInPattern < 0 || $cursorInPattern > \strlen($occurrence->pattern)) {
            return ['type' => 'general', 'prefix' => ''];
        }

        $textBeforeCursor = substr($occurrence->pattern, 0, $cursorInPattern);

        // Check for escape sequence start
        if (str_ends_with($textBeforeCursor, '\\')) {
            return ['type' => 'escape', 'prefix' => '\\'];
        }

        // Check for \p{ - Unicode property
        if (LibraryPcre::match('/\\\\p\\{([^}]*)$/', $textBeforeCursor, $matches)) {
            return ['type' => 'unicode_property', 'prefix' => $matches[1]];
        }

        // Check for [: - POSIX class
        if (LibraryPcre::match('/\\[\\[:?([^\\]:]*)$/', $textBeforeCursor, $matches)) {
            return ['type' => 'posix', 'prefix' => $matches[1]];
        }

        // Check for after closing delimiter - flags
        $delimiterPos = strrpos($textBeforeCursor, $occurrence->pattern[0]);
        if (false !== $delimiterPos && $delimiterPos > 0) {
            // We're after the closing delimiter, offer flags
            $afterDelimiter = substr($textBeforeCursor, $delimiterPos + 1);
            if (LibraryPcre::match('/^[imsxuUADSJ]*$/', $afterDelimiter)) {
                return ['type' => 'flags', 'prefix' => $afterDelimiter];
            }
        }

        // Check for (? - group start
        if (str_ends_with($textBeforeCursor, '(?')) {
            return ['type' => 'group', 'prefix' => '(?'];
        }

        return ['type' => 'general', 'prefix' => ''];
    }

    /**
     * Get completion items based on context.
     *
     * @param array{type: string, prefix: string} $context
     *
     * @return array<array<string, mixed>>
     */
    private function getCompletionItems(array $context): array
    {
        return match ($context['type']) {
            'escape' => $this->buildItems(self::CHAR_CLASSES, self::KIND_KEYWORD),
            'unicode_property' => $this->buildItems(self::UNICODE_PROPERTIES, self::KIND_VALUE),
            'posix' => $this->buildItems(self::POSIX_CLASSES, self::KIND_VALUE),
            'flags' => $this->buildFlagItems($context['prefix']),
            'group' => $this->buildItems(self::GROUPS, self::KIND_SNIPPET),
            default => $this->buildAllItems(),
        };
    }

    /**
     * Build completion items from a category.
     *
     * @param array<array{label: string, detail: string, doc: string, insertText?: string}> $items
     *
     * @return array<array<string, mixed>>
     */
    private function buildItems(array $items, int $kind): array
    {
        $result = [];

        foreach ($items as $item) {
            $completion = [
                'label' => $item['label'],
                'kind' => $kind,
                'detail' => $item['detail'],
                'documentation' => [
                    'kind' => 'markdown',
                    'value' => $item['doc'],
                ],
            ];

            if (isset($item['insertText'])) {
                $completion['insertText'] = $item['insertText'];
                $completion['insertTextFormat'] = 2; // Snippet
            }

            $result[] = $completion;
        }

        return $result;
    }

    /**
     * Build flag completion items, excluding already used flags.
     *
     * @return array<array<string, mixed>>
     */
    private function buildFlagItems(string $usedFlags): array
    {
        $items = [];

        foreach (self::FLAGS as $flag) {
            if (!str_contains($usedFlags, $flag['label'])) {
                $items[] = [
                    'label' => $flag['label'],
                    'kind' => self::KIND_KEYWORD,
                    'detail' => $flag['detail'],
                    'documentation' => [
                        'kind' => 'markdown',
                        'value' => $flag['doc'],
                    ],
                ];
            }
        }

        return $items;
    }

    /**
     * Build all completion items for general context.
     *
     * @return array<array<string, mixed>>
     */
    private function buildAllItems(): array
    {
        return array_merge(
            $this->buildItems(self::CHAR_CLASSES, self::KIND_KEYWORD),
            $this->buildItems(self::ANCHORS, self::KIND_KEYWORD),
            $this->buildItems(self::QUANTIFIERS, self::KIND_KEYWORD),
            $this->buildItems(self::GROUPS, self::KIND_SNIPPET),
        );
    }
}
