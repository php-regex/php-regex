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

namespace RegexParser;

use RegexParser\Exception\LexerException;
use RegexParser\Internal\InlineFlags;

/**
 * Regex lexer that tokenizes PCRE pattern strings.
 *
 * Uses precompiled regex patterns and priority-based matching
 * for efficiency while maintaining compatibility with PCRE syntax.
 */
final class Lexer
{
    // Token priority maps for efficient matching
    private const TOKENS_OUTSIDE = [
        'T_COMMENT_OPEN', 'T_CALLOUT', 'T_PCRE_VERB', 'T_GROUP_MODIFIER_OPEN',
        'T_GROUP_OPEN', 'T_GROUP_CLOSE', 'T_CHAR_CLASS_OPEN', 'T_QUANTIFIER',
        'T_ALTERNATION', 'T_DOT', 'T_ANCHOR', 'T_ASSERTION', 'T_KEEP',
        'T_UNICODE_NAMED', 'T_CHAR_TYPE', 'T_G_REFERENCE', 'T_BACKREF', 'T_OCTAL_LEGACY',
        'T_OCTAL', 'T_UNICODE', 'T_UNICODE_PROP',
        'T_CONTROL_CHAR', 'T_QUOTE_MODE_START', 'T_QUOTE_MODE_END',
        'T_LITERAL_ESCAPED', 'T_LITERAL',
    ];

    private const TOKENS_INSIDE = [
        'T_CHAR_CLASS_CLOSE', 'T_POSIX_CLASS', 'T_CHAR_CLASS_OPEN', 'T_UNICODE_NAMED', 'T_CHAR_TYPE', 'T_OCTAL_LEGACY',
        'T_OCTAL', 'T_UNICODE', 'T_UNICODE_PROP',
        'T_CONTROL_CHAR', 'T_QUOTE_MODE_START', 'T_QUOTE_MODE_END', 'T_LITERAL_ESCAPED',
        'T_CLASS_INTERSECTION', 'T_CLASS_SUBTRACTION', 'T_LITERAL',
    ];

    /*
     * PCRE2 10.48 lets spaces and tabs pad the digits of "\x{...}", "\o{...}"
     * and "\N{U+...}", and the space before "U+": "\x{ 41 }" is "A". The
     * token patterns are compiled with /x, so the padding is spelled as a
     * class.
     */
    private const OCTAL_BRACED = '\\\\ o\\{ [\\x20\\t]* [0-7]+ [\\x20\\t]* \\}';

    private const UNICODE_ESCAPE = '\\\\ x [0-9a-fA-F]{1,2} | \\\\ u [0-9a-fA-F]{4} | \\\\ u\\{[0-9a-fA-F]+\\}'
        .' | \\\\ x\\{ [\\x20\\t]* [0-9a-fA-F]+ [\\x20\\t]* \\}';

    /*
     * "\N{4}" is "\N" repeated four times, not a character name: PCRE2 10.48
     * reads a repeat count first, spaces and "{,n}" included.
     */
    private const REPEAT_COUNT = '\\{ [\\x20\\t]* (?: \\d+ [\\x20\\t]* (?: , [\\x20\\t]* \\d* [\\x20\\t]* )? | , [\\x20\\t]* \\d+ [\\x20\\t]* ) \\}';

    private const UNICODE_NAMED = '\\\\ N (?!'.self::REPEAT_COUNT.') \\{ (?: [\\x20\\t]* U\\+[0-9a-fA-F]+ [\\x20\\t]* | [a-zA-Z0-9_ -]+ ) \\}';

    /*
     * A callout string, between any of the delimiters PCRE2 takes; doubling
     * the closing one puts it in the string, ")" included: (?C"a)b""c").
     */
    private const CALLOUT_STRING = '" (?: [^"] | "" )*+ " | \' (?: [^\'] | \'\' )*+ \' | ` (?: [^`] | `` )*+ `'
        .' | \\^ (?: [^^] | \\^\\^ )*+ \\^ | % (?: [^%] | %% )*+ % | \\# (?: [^#] | \\#\\# )*+ \\#'
        .' | \\$ (?: [^$] | \\$\\$ )*+ \\$ | \\{ (?: [^}] | \\}\\} )*+ \\}';

    /*
     * The characters of a group name: letters, decimal digits and "_". In a
     * pattern read as UTF-8 that covers every script, as PCRE2 10.43+ does
     * under "u"; whether the pattern is in Unicode mode is the parser's call.
     */
    private const NAME_CHARS = '\\p{L}\\p{Nd}_';

    // Optimized regex patterns broken into focused components
    private const PATTERNS_OUTSIDE = [
        'T_COMMENT_OPEN' => '\\(\\?\\#',
        'T_CALLOUT' => '\\(\\?C (?: (?:'.self::CALLOUT_STRING.') (?=\\)) | [^)]* ) \\)',
        // "(*atomic:(a(b)c))" nests as deep as it likes, so the body of an
        // alphabetic assertion or a script run — a lowercase name and a
        // colon, or "(?*" — is matched by recursion. Any other verb ends at
        // the first ")", as PCRE reads it: "(*:a(b)" is a mark named "a(b".
        'T_PCRE_VERB' => '\\( (?: \\?\\* (?<verbBody> (?: [^()]++ | \\( (?P>verbBody) \\) )* )'
            .' | \\* [a-z_]++ : (?P>verbBody) | \\* [^)]* ) \\)',
        'T_GROUP_MODIFIER_OPEN' => '\\(\\?',
        'T_GROUP_OPEN' => '\\(',
        'T_GROUP_CLOSE' => '\\)',
        'T_CHAR_CLASS_OPEN' => '\\[',
        // Braces are a quantifier only with a number in them: "{}" and "{,}"
        // are literal text, as PCRE reads them.
        'T_QUANTIFIER' => '(?: [\*\+\?] | \{ \s* (?: \d+ \s* (?: , \s* \d* \s* )? | , \s* \d+ \s* ) \} ) [\?\+]?',
        'T_ALTERNATION' => '\\|',
        'T_DOT' => '\\.',
        'T_ANCHOR' => '\\^|\\$',
        'T_ASSERTION' => '\\\\ (?: b\\{g\\} | B\\{g\\} | [AzZGbB] )',
        'T_KEEP' => '\\\\ K',
        'T_CHAR_TYPE' => '\\\\ (?: N (?: (?!\\{) | (?='.self::REPEAT_COUNT.') ) | [dswDSWhvRCXHV] )',
        'T_G_REFERENCE' => '\\\\ g (?: \\{['.self::NAME_CHARS.'+-]+\\} | <['.self::NAME_CHARS.'+-]+> | \'['.self::NAME_CHARS.'+-]+\' | [0-9+-]+ )?',
        'T_BACKREF' => '\\\\ (?: k(?:<['.self::NAME_CHARS.']+> | \\{['.self::NAME_CHARS.']+\\} | \'['.self::NAME_CHARS.']+\') | (?<v_backref_num> [1-9]\\d*) )',
        'T_OCTAL_LEGACY' => '\\\\ (?: [0-7]{3} | [0-7]{2} | [0-7] )',
        'T_OCTAL' => self::OCTAL_BRACED,
        'T_UNICODE' => self::UNICODE_ESCAPE,
        'T_UNICODE_PROP' => '\\\\ [pP] (?: \\{ [^}]+ \\} | [a-zA-Z] )',
        'T_UNICODE_NAMED' => self::UNICODE_NAMED,
        'T_CONTROL_CHAR' => '\\\\ c [\\x00-\\x7F]',
        'T_QUOTE_MODE_START' => '\\\\ Q',
        'T_QUOTE_MODE_END' => '\\\\ E',
        'T_LITERAL_ESCAPED' => '\\\\ .',
        'T_LITERAL' => '[^\\\\]',
    ];

    private const PATTERNS_INSIDE = [
        'T_CHAR_CLASS_CLOSE' => '\\]',
        'T_POSIX_CLASS' => '\\[ \\: (?<v_posix> \\^? [a-zA-Z]+) \\: \\]',
        'T_CHAR_CLASS_OPEN' => '\\[',
        'T_UNICODE_NAMED' => self::UNICODE_NAMED,
        'T_CHAR_TYPE' => '\\\\ [dswDSWhvRNHV]',
        'T_OCTAL_LEGACY' => '\\\\ (?: [0-7]{3} | [0-7]{2} | [0-7] )',
        'T_OCTAL' => self::OCTAL_BRACED,
        'T_UNICODE' => self::UNICODE_ESCAPE,
        'T_UNICODE_PROP' => '\\\\ [pP] (?: \\{ [^}]+ \\} | [a-zA-Z] )',
        'T_CONTROL_CHAR' => '\\\\ c [\\x00-\\x7F]',
        'T_QUOTE_MODE_START' => '\\\\ Q',
        'T_QUOTE_MODE_END' => '\\\\ E',
        'T_LITERAL_ESCAPED' => '\\\\ .',
        'T_CLASS_INTERSECTION' => '&&',
        'T_CLASS_SUBTRACTION' => '--',
        'T_LITERAL' => '[^\\\\]',
    ];

    // Token value extraction offsets
    private const OFFSET_VERB_START = 2; // After (* or (?
    private const OFFSET_VERB_END = -1;
    private const OFFSET_CALLOUT_START = 3; // After (?C
    private const OFFSET_CALLOUT_END = -1;
    private const OFFSET_BACKSLASH = 1; // After backslash
    private const OFFSET_UNICODE_NAMED_START = 3; // After \N{
    private const OFFSET_UNICODE_NAMED_END = -1;
    private const OFFSET_CONTROL_CHAR = 2; // After \c

    // Escape sequences
    private const ESCAPE_BACKSPACE = "\x08";
    private const ESCAPE_TAB = "\t";
    private const ESCAPE_NEWLINE = "\n";
    private const ESCAPE_CARRIAGE_RETURN = "\r";
    private const ESCAPE_FORM_FEED = "\f";
    private const ESCAPE_VERTICAL_TAB = "\v";
    private const ESCAPE_ESCAPE = "\x1B";
    private const ESCAPE_BELL = "\x07";

    // Pattern literals
    private const PATTERN_QUOTE_END = '\E';
    private const PATTERN_COMMENT_CLOSE = ')';
    private const PATTERN_UNICODE_PROP_BRACE_START = 1; // After {
    private const PATTERN_UNICODE_PROP_BRACE_END = -1; // Before }
    private const PATTERN_UNICODE_PROP_NEGATION_START = 1; // After ^
    private const ERROR_CONTEXT_LENGTH = 10;

    /**
     * Modifier letters accepted inside "(?...)": what the parser takes, plus
     * the PCRE2 10.43 "r". Whether "r" is allowed on the running PHP version
     * is the parser's call; here it only decides where /x starts.
     */
    private const INLINE_FLAG_LETTERS = InlineFlags::LETTERS.'r';

    /**
     * Token patterns compiled once per byte mode.
     *
     * @var array<int, string>
     */
    private static array $regexOutside = [];

    /**
     * @var array<int, string>
     */
    private static array $regexInside = [];

    private string $pattern;

    private int $position = 0;

    private int $length = 0;

    private bool $inCharClass = false;

    private bool $inQuoteMode = false;

    private bool $inCommentMode = false;

    private bool $byteMode = false;

    private bool $extendedMode = false;

    /**
     * Whether "(?xx)" is in force, under which PCRE also skips the spaces and
     * tabs of a class. PHP has no "xx" pattern modifier: only the inline
     * setting turns it on.
     */
    private bool $extendedMoreMode = false;

    /**
     * Values of $extendedMode and $extendedMoreMode saved by each open group,
     * restored when it closes.
     *
     * @var array<array{0: bool, 1: bool}>
     */
    private array $extendedModeStack = [];

    /**
     * @var array<int>
     */
    private array $charClassStartPositions = [];

    public function tokenize(string $pattern, string $flags = ''): TokenStream
    {
        // Patterns that are not valid UTF-8 are tokenized byte by byte, the
        // way PCRE compiles them without the /u modifier. With /u, PCRE
        // itself refuses such patterns.
        $this->byteMode = !preg_match('//u', $pattern);
        if ($this->byteMode && str_contains($flags, 'u')) {
            throw LexerException::withContext('Input string is not valid UTF-8.', 0, $pattern);
        }

        $this->pattern = $pattern;
        $this->length = \strlen($this->pattern);
        $this->extendedMode = str_contains($flags, 'x');
        $this->extendedMoreMode = false;
        $this->resetState();

        /** @var array<Token> $tokens */
        $tokens = [];

        while ($this->position < $this->length) {
            if ($this->handleTunnelModes($tokens)) {
                continue;
            }

            [$regex, $tokenMap] = $this->getCurrentContext();
            [$matchedValue, $startPos, $matches] = $this->matchAtPosition($regex);
            $tokens[] = $this->createToken($tokenMap, $matches, $matchedValue, $startPos, $tokens);
        }

        $this->validateFinalState();
        $tokens[] = new Token(TokenType::T_EOF, '', $this->position);

        return new TokenStream($tokens, $pattern);
    }

    private function getRegexOutside(): string
    {
        // The token patterns are constants and the compiled regex only varies
        // with the byte mode, so that is the whole key: keying it on the PHP
        // version as well compiled the same two regexes once per version.
        $key = $this->byteMode ? 1 : 0;

        return self::$regexOutside[$key] ??= $this->compilePattern(self::PATTERNS_OUTSIDE);
    }

    private function getRegexInside(): string
    {
        $key = $this->byteMode ? 1 : 0;

        return self::$regexInside[$key] ??= $this->compilePattern(self::PATTERNS_INSIDE);
    }

    /**
     * Anchor a sub-pattern at the cursor, reading the subject the way the
     * pattern itself is read.
     *
     * A pattern that is not valid UTF-8 is tokenized byte by byte, so the
     * "u" modifier must go with it: asking PCRE to read invalid UTF-8 under
     * /u fails, and a failure here used to be taken for "nothing left to
     * read".
     */
    private function anchored(string $pattern, string $modifiers = ''): string
    {
        return '/'.$pattern.'/A'.$modifiers.($this->byteMode ? '' : 'u');
    }

    /**
     * Compile patterns into an optimized regex with named groups.
     *
     * @param array<string, string> $patterns
     */
    private function compilePattern(array $patterns): string
    {
        $regexParts = [];
        foreach ($patterns as $name => $pattern) {
            $regexParts[] = "(?<{$name}> {$pattern} )";
        }

        return '/(?:'.implode('|', $regexParts).')/xsA'.($this->byteMode ? '' : 'u');
    }

    /**
     * Checks if a token type is a character class operation type.
     */
    private function isClassOperationType(TokenType $type): bool
    {
        return TokenType::T_CLASS_INTERSECTION === $type
            || TokenType::T_CLASS_SUBTRACTION === $type;
    }

    private function resetState(): void
    {
        $this->position = 0;
        $this->extendedModeStack = [];
        $this->inCharClass = false;
        $this->inQuoteMode = false;
        $this->inCommentMode = false;
        $this->charClassStartPositions = [];
    }

    /**
     * @param array<Token> $tokens
     */
    private function handleTunnelModes(array &$tokens): bool
    {
        if ($this->inQuoteMode) {
            if ($token = $this->consumeQuoteMode()) {
                $tokens[] = $token;
            }

            return true;
        }

        if ($this->inCommentMode) {
            if ($token = $this->consumeCommentMode()) {
                $tokens[] = $token;
            }

            return true;
        }

        if ($this->extendedMode && !$this->inCharClass && '#' === ($this->pattern[$this->position] ?? '')) {
            $this->consumeExtendedComment($tokens);

            return true;
        }

        // Under "(?xx)" PCRE skips spaces and tabs before the first member of
        // a class, so "(?xx)[ ]]" holds "]". They carry no meaning there and
        // are not given a token.
        if ($this->extendedMoreMode && $this->inCharClass
            && \in_array($this->pattern[$this->position], [' ', "\t"], true)
            && null !== $this->readCharClassPrefix($tokens)) {
            $this->position++;

            return true;
        }

        return false;
    }

    /**
     * Under /x a '#' starts a comment that runs to the end of the line, so its
     * content is text: '[', '(' and friends must not be tokenized as regex
     * syntax. The comment is emitted as literals — '#', the body and the
     * closing newline — which is what the parser turns into a CommentNode.
     *
     * @param array<Token> $tokens
     */
    private function consumeExtendedComment(array &$tokens): void
    {
        $tokens[] = new Token(TokenType::T_LITERAL, '#', $this->position);
        $this->position++;

        $end = strpos($this->pattern, "\n", $this->position);
        $bodyEnd = false === $end ? $this->length : $end;

        if ($bodyEnd > $this->position) {
            $body = substr($this->pattern, $this->position, $bodyEnd - $this->position);
            $tokens[] = new Token(TokenType::T_LITERAL, $body, $this->position);
            $this->position = $bodyEnd;
        }

        if (false !== $end) {
            $tokens[] = new Token(TokenType::T_LITERAL, "\n", $this->position);
            $this->position++;
        }
    }

    /**
     * @return array{0: string, 1: array<string>}
     */
    private function getCurrentContext(): array
    {
        if ($this->inCharClass) {
            return [$this->getRegexInside(), self::TOKENS_INSIDE];
        }

        return [$this->getRegexOutside(), self::TOKENS_OUTSIDE];
    }

    /**
     * @return array{0: string, 1: int, 2: array<int|string, mixed>}
     */
    private function matchAtPosition(string $regex): array
    {
        $result = preg_match($regex, $this->pattern, $matches, \PREG_UNMATCHED_AS_NULL, $this->position);

        if (false === $result) {
            throw LexerException::withContext(
                \sprintf('PCRE Error during tokenization: %s', (string) preg_last_error_msg()),
                $this->position,
                $this->pattern,
            );
        }

        if (0 === $result) {
            $context = substr($this->pattern, $this->position, self::ERROR_CONTEXT_LENGTH);

            throw LexerException::withContext(
                \sprintf('Unable to tokenize pattern at position %d. Context: "%s..."', $this->position, $context),
                $this->position,
                $this->pattern,
            );
        }

        if (!isset($matches[0]) || !\is_string($matches[0])) {
            throw LexerException::withContext(
                'Lexer internal error: Missing matched token.',
                $this->position,
                $this->pattern,
            );
        }

        $matchedValue = (string) $matches[0];
        $startPos = $this->position;
        $this->position += \strlen($matchedValue);

        return [$matchedValue, $startPos, $matches];
    }

    /**
     * @param array<string>            $tokenMap
     * @param array<int|string, mixed> $matches
     * @param array<Token>             $currentTokens
     */
    private function createToken(
        array $tokenMap,
        array $matches,
        string $matchedValue,
        int $startPos,
        array $currentTokens
    ): Token {
        foreach ($tokenMap as $tokenName) {
            /** @var string $tokenName */
            if (!isset($matches[$tokenName])) {
                continue;
            }

            $type = TokenType::from(strtolower(substr($tokenName, 2)));

            if ($token = $this->handleStatefulToken($type, $matchedValue, $startPos, $currentTokens)) {
                return $token;
            }

            if (TokenType::T_LITERAL_ESCAPED === $type) {
                // "\c" only falls through to an escaped literal when no ASCII
                // character follows it; PCRE rejects that.
                if ('\\c' === $matchedValue) {
                    throw LexerException::withContext(
                        '\\c must be followed by a printable ASCII character.',
                        $startPos,
                        $this->pattern,
                    );
                }

                // "\x{}" (empty braces) is a PCRE compile error.
                if ('\\x' === $matchedValue && '{}' === substr($this->pattern, $startPos + 2, 2)) {
                    throw LexerException::withContext(
                        'Invalid hex escape "\\x{}": at least one hexadecimal digit is required.',
                        $startPos,
                        $this->pattern,
                    );
                }
            }

            $value = $this->extractTokenValue($type, $matchedValue, $matches);

            // The value may be a rewrite of what was matched — a stripped
            // backslash, a normalized property name — so the token carries the
            // length of the text it was cut from, not the length of its value.
            return new Token($type, $value, $startPos, \strlen($matchedValue));
        }

        throw LexerException::withContext(
            \sprintf('Lexer internal error: No known token matched at position %d.', $startPos),
            $startPos,
            $this->pattern,
        );
    }

    /**
     * @param array<Token> $currentTokens
     */
    private function handleStatefulToken(
        TokenType $type,
        string $matchedValue,
        int $startPos,
        array $currentTokens
    ): ?Token {
        if (!$this->inCharClass) {
            $this->trackExtendedModeScope($type);
        }

        return match ($type) {
            TokenType::T_CHAR_CLASS_OPEN => $this->handleCharClassOpen($startPos, $currentTokens),
            TokenType::T_CHAR_CLASS_CLOSE => $this->closeCharClass($startPos, $currentTokens),
            TokenType::T_COMMENT_OPEN => $this->openComment($startPos),
            TokenType::T_QUOTE_MODE_START => $this->openQuoteMode($startPos),
            default => $this->handleContextualLiteral($type, $matchedValue, $startPos, $currentTokens),
        };
    }

    /**
     * Follow the /x setting through the group structure, so that "(?x)" and
     * "(?x:...)" turn '#' comments on the way PCRE does: "(?x)" holds until
     * the end of the enclosing group, "(?x:...)" only inside its own group.
     */
    private function trackExtendedModeScope(TokenType $type): void
    {
        if (TokenType::T_GROUP_CLOSE === $type) {
            [$this->extendedMode, $this->extendedMoreMode] = array_pop($this->extendedModeStack)
                ?? [$this->extendedMode, $this->extendedMoreMode];

            return;
        }

        if (TokenType::T_GROUP_OPEN === $type) {
            $this->extendedModeStack[] = [$this->extendedMode, $this->extendedMoreMode];

            return;
        }

        if (TokenType::T_GROUP_MODIFIER_OPEN !== $type) {
            return;
        }

        $inlineFlags = $this->readInlineFlags();
        if (null === $inlineFlags) {
            $this->extendedModeStack[] = [$this->extendedMode, $this->extendedMoreMode];

            return;
        }

        [$flags, $scoped] = $inlineFlags;
        $updated = $flags->inForce('x', $this->extendedMode);

        // "(?xx)" turns both on; a single "x" set or any "x" unset turns the
        // second one off, as PCRE2 does.
        $updatedMore = substr_count($flags->set, 'x') >= 2
            || (!$flags->turnsOn('x') && !$flags->turnsOff('x') && $this->extendedMoreMode);

        // "(?x)" survives its own closing parenthesis: push the new value so
        // the pop performed by ")" leaves it in place. "(?x:...)" pushes the
        // previous value instead, which the pop restores.
        $this->extendedModeStack[] = $scoped
            ? [$this->extendedMode, $this->extendedMoreMode]
            : [$updated, $updatedMore];
        $this->extendedMode = $updated;
        $this->extendedMoreMode = $updatedMore;
    }

    /**
     * Read the modifiers that follow "(?", if the group carries any at all.
     *
     * @return array{0: InlineFlags, 1: bool} the modifiers, and whether the
     *                                        group is scoped with ':'
     */
    private function readInlineFlags(): ?array
    {
        $matches = [];
        if (!preg_match('/\G(\^?[a-zA-Z]*(?:-[a-zA-Z]+)?)([:)])/A', $this->pattern, $matches, 0, $this->position)) {
            return null;
        }

        $flags = InlineFlags::read($matches[1], self::INLINE_FLAG_LETTERS);

        return null === $flags ? null : [$flags, ':' === $matches[2]];
    }

    /**
     * @param array<Token> $currentTokens
     */
    private function handleCharClassOpen(int $startPos, array $currentTokens): Token
    {
        if ($this->inCharClass) {
            if ($this->isAtCharClassStart($currentTokens)) {
                return new Token(TokenType::T_LITERAL, '[', $startPos);
            }

            $lastToken = end($currentTokens);
            if ($lastToken instanceof Token && $this->isClassOperationType($lastToken->type)) {
                return $this->openCharClass($startPos);
            }

            return new Token(TokenType::T_LITERAL, '[', $startPos);
        }

        return $this->openCharClass($startPos);
    }

    private function openCharClass(int $startPos): Token
    {
        $this->inCharClass = true;
        $this->charClassStartPositions[] = $startPos;

        return new Token(TokenType::T_CHAR_CLASS_OPEN, '[', $startPos);
    }

    /**
     * @param array<Token> $currentTokens
     */
    private function closeCharClass(int $startPos, array $currentTokens): Token
    {
        if ($this->isAtCharClassStart($currentTokens)) {
            return new Token(TokenType::T_LITERAL, ']', $startPos);
        }

        array_pop($this->charClassStartPositions);
        if ([] === $this->charClassStartPositions) {
            $this->inCharClass = false;
        }

        return new Token(TokenType::T_CHAR_CLASS_CLOSE, ']', $startPos);
    }

    private function openComment(int $startPos): Token
    {
        $this->inCommentMode = true;

        return new Token(TokenType::T_COMMENT_OPEN, '(?#', $startPos);
    }

    private function openQuoteMode(int $startPos): Token
    {
        $this->inQuoteMode = true;

        return new Token(TokenType::T_QUOTE_MODE_START, '\Q', $startPos);
    }

    /**
     * @param array<Token> $currentTokens
     */
    private function handleContextualLiteral(
        TokenType $type,
        string $matchedValue,
        int $startPos,
        array $currentTokens
    ): ?Token {
        if (!$this->inCharClass || TokenType::T_LITERAL !== $type) {
            return null;
        }

        $prefix = $this->readCharClassPrefix($currentTokens);

        // Only the first "^" negates: in "[^^]" the second one is a member.
        if (false === $prefix && '^' === $matchedValue) {
            return new Token(TokenType::T_NEGATION, '^', $startPos);
        }

        if (null === $prefix && '-' === $matchedValue) {
            return new Token(TokenType::T_RANGE, '-', $startPos);
        }

        return null;
    }

    /**
     * @param array<Token> $currentTokens
     */
    private function isAtCharClassStart(array $currentTokens): bool
    {
        return null !== $this->readCharClassPrefix($currentTokens);
    }

    /**
     * Whether the cursor is still at the start of the innermost class.
     *
     * PCRE skips "\E", an empty "\Q\E" and one "^", in any order, before it
     * reads the first member of a class, so a "]" that follows only those is
     * a member and does not close the class: "[\E]a]" holds "]" and "a".
     *
     * @param array<Token> $currentTokens
     *
     * @return bool|null null when a member has already been read; otherwise
     *                   whether the prefix holds the negating "^"
     */
    private function readCharClassPrefix(array $currentTokens): ?bool
    {
        // Unreachable: every caller is inside a class, which has an opening
        // position. It guards a call made outside one.
        if ([] === $this->charClassStartPositions) {
            return null;
        }

        $classStart = $this->charClassStartPositions[\count($this->charClassStartPositions) - 1];
        $negated = false;

        for ($index = \count($currentTokens) - 1; $index >= 0; $index--) {
            $token = $currentTokens[$index];

            if (TokenType::T_CHAR_CLASS_OPEN === $token->type && $classStart === $token->position) {
                return $negated;
            }

            if (TokenType::T_NEGATION === $token->type) {
                $negated = true;

                continue;
            }

            // Quote mode is closed by the time a member is read here, so a
            // "\Q" in the prefix is the start of an empty "\Q\E": anything it
            // quoted would be a literal token, which ends the prefix.
            if (TokenType::T_QUOTE_MODE_END !== $token->type && TokenType::T_QUOTE_MODE_START !== $token->type) {
                return null;
            }
        }

        // Unreachable: the opening token of the innermost class is always in
        // the list, so the loop returns before running out. It guards a token
        // list that lost it.
        return null;
    }

    private function consumeQuoteMode(): ?Token
    {
        if (!preg_match($this->anchored('(.*?)((\\\\E|$))', 's'), $this->pattern, $matches, \PREG_UNMATCHED_AS_NULL, $this->position)) {
            // Nothing here can fail to match, so a failure means PCRE itself
            // gave up. Leaving quote mode and skipping to the end would drop
            // the rest of the pattern without a word.
            throw LexerException::withContext(
                \sprintf('PCRE Error while reading a quoted run: %s', (string) preg_last_error_msg()),
                $this->position,
                $this->pattern,
            );
        }

        // Both groups always take part in the match; the null the unmatched
        // flag would give is not reachable.
        $literalText = (string) $matches[1];
        $endSequence = $matches[2];
        $startPos = $this->position;

        if ('' !== $literalText) {
            $this->position += \strlen($literalText);

            return new Token(TokenType::T_LITERAL, $literalText, $startPos);
        }

        if (self::PATTERN_QUOTE_END === $endSequence) {
            $this->inQuoteMode = false;
            $token = new Token(TokenType::T_QUOTE_MODE_END, self::PATTERN_QUOTE_END, $this->position);
            $this->position += \strlen(self::PATTERN_QUOTE_END);

            return $token;
        }

        // End of pattern reached without \E - PCRE treats \Q without \E as valid
        // (quotes to end of pattern). Keep inQuoteMode = true per PCRE semantics.
        $this->position = $this->length;

        return null;
    }

    private function consumeCommentMode(): ?Token
    {
        if (!preg_match($this->anchored('([^)]*)(\)|$)'), $this->pattern, $matches, \PREG_UNMATCHED_AS_NULL, $this->position)) {
            throw LexerException::withContext(
                \sprintf('PCRE Error while reading a comment: %s', (string) preg_last_error_msg()),
                $this->position,
                $this->pattern,
            );
        }

        $commentText = (string) $matches[1];
        $endSequence = $matches[2];
        $startPos = $this->position;

        if ('' !== $commentText) {
            $this->position += \strlen($commentText);

            return new Token(TokenType::T_LITERAL, $commentText, $startPos);
        }

        if (self::PATTERN_COMMENT_CLOSE === $endSequence) {
            $this->inCommentMode = false;
            $token = new Token(TokenType::T_GROUP_CLOSE, self::PATTERN_COMMENT_CLOSE, $this->position);
            $this->position += \strlen(self::PATTERN_COMMENT_CLOSE);

            return $token;
        }

        $this->position = $this->length;

        return null;
    }

    /**
     * Extracts value from an escaped literal token.
     * Handles special escape sequences and context-sensitive escapes like \b.
     */
    private function extractEscapedLiteralValue(string $matchedValue): string
    {
        $char = substr($matchedValue, self::OFFSET_BACKSLASH);

        // Inside character classes, \b means backspace (0x08), not word boundary
        if ($this->inCharClass && 'b' === $char) {
            return self::ESCAPE_BACKSPACE;
        }

        return match ($char) {
            't' => self::ESCAPE_TAB,
            'n' => self::ESCAPE_NEWLINE,
            'r' => self::ESCAPE_CARRIAGE_RETURN,
            'f' => self::ESCAPE_FORM_FEED,
            'v' => self::ESCAPE_VERTICAL_TAB,
            'e' => self::ESCAPE_ESCAPE,
            'a' => self::ESCAPE_BELL,
            default => $char,
        };
    }

    /**
     * @param array<int|string, mixed> $matches
     */
    private function extractTokenValue(TokenType $type, string $matchedValue, array $matches): string
    {
        return match ($type) {
            TokenType::T_LITERAL_ESCAPED => $this->extractEscapedLiteralValue($matchedValue),
            TokenType::T_PCRE_VERB => substr($matchedValue, self::OFFSET_VERB_START, self::OFFSET_VERB_END),
            TokenType::T_CALLOUT => substr($matchedValue, self::OFFSET_CALLOUT_START, self::OFFSET_CALLOUT_END),
            TokenType::T_ASSERTION, TokenType::T_CHAR_TYPE, TokenType::T_KEEP => substr($matchedValue, self::OFFSET_BACKSLASH),
            TokenType::T_BACKREF => $matchedValue,
            TokenType::T_OCTAL_LEGACY => substr($matchedValue, self::OFFSET_BACKSLASH),
            /* @phpstan-ignore cast.string */
            TokenType::T_POSIX_CLASS => (string) ($matches['v_posix'] ?? ''),
            TokenType::T_UNICODE => $this->parseUnicodeEscape($matchedValue),
            TokenType::T_UNICODE_PROP => $this->normalizeUnicodeProp($matchedValue),
            TokenType::T_UNICODE_NAMED => substr($matchedValue, self::OFFSET_UNICODE_NAMED_START, self::OFFSET_UNICODE_NAMED_END),
            TokenType::T_CONTROL_CHAR => substr($matchedValue, self::OFFSET_CONTROL_CHAR),
            TokenType::T_CLASS_INTERSECTION => '&&',
            TokenType::T_CLASS_SUBTRACTION => '--',
            default => $matchedValue,
        };
    }

    /**
     * Parses a Unicode escape sequence.
     *
     * Preserve the original escape lexeme so token offsets stay byte-accurate and
     * round-tripping remains stable.
     */
    private function parseUnicodeEscape(string $escape): string
    {
        return $escape;
    }

    private function normalizeUnicodeProp(string $matchedValue): string
    {
        $isNegated = str_starts_with($matchedValue, '\\P');
        $prop = substr($matchedValue, self::OFFSET_VERB_START); // Strip "\p" or "\P"

        $hasBraces = str_starts_with($prop, '{') && str_ends_with($prop, '}');

        if ($hasBraces) {
            $prop = substr($prop, self::PATTERN_UNICODE_PROP_BRACE_START, self::PATTERN_UNICODE_PROP_BRACE_END);
        }

        $isPropNegated = str_starts_with($prop, '^');
        if ($isPropNegated) {
            $prop = substr($prop, self::PATTERN_UNICODE_PROP_NEGATION_START);
        }

        if ('' === $prop) {
            return '';
        }

        $negated = $isNegated !== $isPropNegated;

        $normalized = $negated ? '^'.$prop : $prop;

        return $hasBraces ? '{'.$normalized.'}' : $normalized;
    }

    private function validateFinalState(): void
    {
        if ([] !== $this->charClassStartPositions) {
            throw LexerException::withContext(
                'Unclosed character class "]" at end of input.',
                $this->position,
                $this->pattern,
            );
        }

        if ($this->inCommentMode) {
            throw LexerException::withContext(
                'Unclosed comment ")" at end of input.',
                $this->position,
                $this->pattern,
            );
        }
    }
}
