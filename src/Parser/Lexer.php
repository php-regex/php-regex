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

namespace PHPRegex\Parser;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Exception\InternalFailureException;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Lexing\ClassRanges;
use PHPRegex\Parser\Lexing\ClassState;
use PHPRegex\Parser\Lexing\GroupOpeners;
use PHPRegex\Parser\Lexing\LexerState;
use PHPRegex\Parser\Lexing\PatternText;
use PHPRegex\Parser\Lexing\TargetSyntax;
use PHPRegex\Parser\Lexing\TokenValue;
use PHPRegex\Parser\Lexing\Tunnels;
use PHPRegex\Parser\Lexing\VerbNames;
use PHPRegex\Parser\Syntax\BadEscape;
use PHPRegex\Parser\Syntax\PcreVerb;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\Utf8;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenStream;
use PHPRegex\Parser\Token\TokenType;

/**
 * Regex lexer that tokenizes PCRE pattern strings.
 *
 * Uses precompiled regex patterns and priority-based matching
 * for efficiency while maintaining compatibility with PCRE syntax.
 *
 * @internal
 */
final class Lexer
{
    /**
     * A callout string, between any of the delimiters PCRE2 takes; doubling
     * the closing one puts it in the string, ")" included: (?C"a)b""c").
     * The body of an alphabetic assertion reads it as the lexer does.
     */
    public const CALLOUT_STRING = '" (?: [^"] | "" )*+ " | \' (?: [^\'] | \'\' )*+ \' | ` (?: [^`] | `` )*+ `'
        .' | \\^ (?: [^^] | \\^\\^ )*+ \\^ | % (?: [^%] | %% )*+ % | \\# (?: [^#] | \\#\\# )*+ \\#'
        .' | \\$ (?: [^$] | \\$\\$ )*+ \\$ | \\{ (?: [^}] | \\}\\} )*+ \\}';
    // Token priority maps for efficient matching
    /**
     * The largest number a backslash and its digits may make before PCRE2
     * 10.37, which reads one past it as an octal escape and text.
     */
    private const LARGEST_NUMBER_BEFORE_PCRE_10_37 = 214_748_363;

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
        'T_CONTROL_CHAR', 'T_QUOTE_MODE_START', 'T_QUOTE_MODE_END', 'T_LITERAL_ESCAPED', 'T_LITERAL',
    ];

    /*
     * PCRE2 10.43 lets spaces and tabs pad the digits of "\x{...}", "\o{...}"
     * and "\N{U+...}", and the space before "U+": "\x{ 41 }" is "A". It
     * asks for a digit before it skips the padding after "{", and enters
     * "\N{U+" past that question: "\N{U+ }" is U+0000. The token patterns
     * are compiled with /x, so the padding is spelled as a class.
     */
    private const OCTAL_BRACED = '\\\\ o\\{ [\\x20\\t]*+ [0-7]++ [\\x20\\t]*+ \\}';

    private const UNICODE_ESCAPE = '\\\\ x [0-9a-fA-F]{1,2} | \\\\ u [0-9a-fA-F]{4} | \\\\ u\\{[0-9a-fA-F]++\\}'
        .' | \\\\ x\\{ [\\x20\\t]*+ [0-9a-fA-F]++ [\\x20\\t]*+ \\}';

    /*
     * "\N{4}" is "\N" repeated four times, not a character name: PCRE2 10.48
     * reads a repeat count first, spaces and "{,n}" included.
     */
    private const REPEAT_COUNT = '\\{ [\\x20\\t]*+ (?: \\d++ [\\x20\\t]*+ (?: , [\\x20\\t]*+ \\d*+ [\\x20\\t]*+ )? | , [\\x20\\t]*+ \\d++ [\\x20\\t]*+ ) \\}';

    private const UNICODE_NAMED = '\\\\ N (?!'.self::REPEAT_COUNT.') \\{ (?: [\\x20\\t]*+ U\\+ (?: [0-9a-fA-F]++ [\\x20\\t]*+ | [\\x20\\t]++ ) | [a-zA-Z0-9_ -]++ ) \\}';

    /*
     * The characters of a group name: letters, decimal digits and "_". In a
     * pattern read as UTF-8 that covers every script, as PCRE2 10.43+ does
     * under "u"; whether the pattern is in Unicode mode is the parser's call.
     */
    private const NAME_CHARS = '\\p{L}\\p{Nd}_';

    // Optimized regex patterns broken into focused components
    private const PATTERNS_OUTSIDE = [
        'T_COMMENT_OPEN' => '\\(\\?\\#',
        'T_CALLOUT' => '\\(\\?C (?: (?:'.self::CALLOUT_STRING.') (?=\\)) | [^)]*+ ) \\)',
        // A verb ends at the first ")", as PCRE reads it: "(*:a(b)" is a mark
        // named "a(b". The body of an alphabetic assertion or a script run,
        // "(*atomic:(a(b)c))" or "(?*...)", nests as deep as it likes: the
        // lexer reads it before this regex, one item at a time.
        'T_PCRE_VERB' => '\\( \\* (?! [a-z_]++ : ) [^)]*+ \\)',
        'T_GROUP_MODIFIER_OPEN' => '\\(\\?',
        'T_GROUP_OPEN' => '\\(',
        'T_GROUP_CLOSE' => '\\)',
        'T_CHAR_CLASS_OPEN' => '\\[',
        // Braces are a quantifier only with a number in them: "{}" and "{,}"
        // are literal text, as PCRE reads them, and so are braces padded
        // with white space other than spaces and tabs: "{2\n}".
        'T_QUANTIFIER' => '(?: [\*\+\?] | '.self::REPEAT_COUNT.' ) [\?\+]?',
        'T_ALTERNATION' => '\\|',
        'T_DOT' => '\\.',
        'T_ANCHOR' => '\\^|\\$',
        'T_ASSERTION' => '\\\\ [AzZGbB]',
        'T_KEEP' => '\\\\ K',
        'T_CHAR_TYPE' => '\\\\ (?: N (?: (?!\\{) | (?='.self::REPEAT_COUNT.') ) | [dswDSWhvRCXHV] )',
        'T_G_REFERENCE' => '\\\\ g (?: \\{[ \\t]*+['.self::NAME_CHARS.'+-]++[ \\t]*+\\} | <['.self::NAME_CHARS.'+-]++> | \'['.self::NAME_CHARS.'+-]++\' | [+-]?+[0-9]++ | [+-]++ )?',
        'T_BACKREF' => '\\\\ (?: k(?:<['.self::NAME_CHARS.']++> | \\{[ \\t]*+['.self::NAME_CHARS.']++[ \\t]*+\\} | \'['.self::NAME_CHARS.']++\') | (?<v_backref_num> [1-9]\\d*+) )',
        'T_OCTAL_LEGACY' => '\\\\ (?: [0-7]{3} | [0-7]{2} | [0-7] )',
        'T_OCTAL' => self::OCTAL_BRACED,
        'T_UNICODE' => self::UNICODE_ESCAPE,
        'T_UNICODE_PROP' => '\\\\ [pP] (?: \\{ [^}]++ \\} | [a-zA-Z] )',
        'T_UNICODE_NAMED' => self::UNICODE_NAMED,
        'T_CONTROL_CHAR' => '\\\\ c [\\x20-\\x7E]',
        'T_QUOTE_MODE_START' => '\\\\ Q',
        'T_QUOTE_MODE_END' => '\\\\ E',
        'T_LITERAL_ESCAPED' => '\\\\ .',
        'T_LITERAL' => '[^\\\\]',
    ];

    private const PATTERNS_INSIDE = [
        'T_CHAR_CLASS_CLOSE' => '\\]',
        'T_POSIX_CLASS' => '\\[ \\: (?<v_posix> \\^? [a-zA-Z]++) \\: \\]',
        'T_CHAR_CLASS_OPEN' => '\\[',
        'T_UNICODE_NAMED' => self::UNICODE_NAMED,
        'T_CHAR_TYPE' => '\\\\ [dswDSWhvRNHV]',
        'T_OCTAL_LEGACY' => '\\\\ (?: [0-7]{3} | [0-7]{2} | [0-7] )',
        'T_OCTAL' => self::OCTAL_BRACED,
        'T_UNICODE' => self::UNICODE_ESCAPE,
        'T_UNICODE_PROP' => '\\\\ [pP] (?: \\{ [^}]++ \\} | [a-zA-Z] )',
        'T_CONTROL_CHAR' => '\\\\ c [\\x20-\\x7E]',
        'T_QUOTE_MODE_START' => '\\\\ Q',
        'T_QUOTE_MODE_END' => '\\\\ E',
        'T_LITERAL_ESCAPED' => '\\\\ .',
        'T_LITERAL' => '[^\\\\]',
    ];

    /**
     * The ASCII characters that are text wherever they stand outside a class
     * and outside "x": no token of the regex below starts with one but the
     * literal. A run of them is most of what patterns hold.
     */
    private const PLAIN_TEXT = ' !"%&\',-/0123456789:;<=>@ABCDEFGHIJKLMNOPQRSTUVWXYZ_`abcdefghijklmnopqrstuvwxyz~';

    /**
     * PLAIN_TEXT as a set of bytes, the digits keyed as integers.
     *
     * @var array<int|string, true>|null
     */
    private static ?array $plainBytes = null;

    /**
     * The token regexes this lexer built, by context and reading mode: a
     * string built once per lexer, which PCRE keeps compiled across them.
     *
     * @var array<string, string>
     */
    private array $regexes = [];

    /**
     * The syntax the PHP and PCRE2 judged read, asked once.
     */
    private readonly TargetSyntax $syntax;

    /**
     * Where the reading stands and the modes it reads in, which the readers
     * of the quoted runs, comments, classes and group openers move too.
     */
    private readonly LexerState $state;

    /**
     * The whole pattern the text read stands in: the text itself unless a
     * part of it is read.
     */
    private string $wholePattern = '';

    /**
     * @var list<Token>
     */
    private array $tokensRead = [];

    /**
     * @param PcreTarget|null $target the PHP and PCRE2 judged; the running ones when null
     */
    public function __construct(?PcreTarget $target = null)
    {
        $this->syntax = new TargetSyntax($target ?? PcreTarget::runtime());
        $this->state = new LexerState();
    }

    /**
     * The tokens the last tokenize() call read, up to where it failed if it
     * did: what the pattern holds before an error the lexer stops on.
     *
     * @internal
     *
     * @return list<Token>
     */
    public function tokensRead(): array
    {
        return $this->tokensRead;
    }

    /**
     * Where the escapes PHP 7.3 and 7.4 read as the character after the
     * backslash stand, in the text the last tokenizing read.
     *
     * @internal
     *
     * @return array<int, true>
     */
    public function literalEscapesRead(): array
    {
        return $this->state->literalEscapes;
    }

    /**
     * Where the token the last tokenizing stopped on starts, when that
     * token is refused for itself, whatever stands around it: a "\c" that
     * ends the pattern, a "\x{}", a "(?*" older than PCRE2 10.35. Null when
     * tokenizing read the whole pattern, or stopped for what stands around
     * the token.
     *
     * @internal
     */
    public function refusedTokenStart(): ?int
    {
        return $this->state->refusedTokenStart;
    }

    /**
     * @param bool $extendedMore whether the text is read as under "(?xx)", as the
     *                           classes of an extended class are
     */
    public function tokenize(string $pattern, string $flags = '', bool $extendedMore = false): TokenStream
    {
        $this->wholePattern = $pattern;
        $this->state->bodies?->forget();
        $this->state->bodiesInPlace = false;

        return $this->read($pattern, $flags, $extendedMore, 0);
    }

    /**
     * Tokenize the pattern reading the body of each alphabetic assertion in
     * place, in one pass as PCRE reads it: its opener is a token of its own,
     * then come the tokens of the body, then its ")". The stream shows each
     * escape and class of the bodies where it stands; it is not one the
     * parser reads.
     *
     * @internal
     */
    public function tokenizeInPlace(string $pattern, string $flags = ''): TokenStream
    {
        $this->wholePattern = $pattern;
        $this->state->bodies?->forget();
        $this->state->bodiesInPlace = true;

        return $this->read($pattern, $flags, false, 0);
    }

    /**
     * Tokenize the $length bytes of $pattern from $offset, such as the body
     * of an alphabetic assertion, with positions counted from $offset.
     *
     * Where the bodies inside it end is taken from the earlier calls on the
     * same pattern when they found it, so that bodies nested in bodies are
     * not each read again at every level.
     *
     * @internal
     *
     * @param bool $extendedMore whether "(?xx)" holds where the text starts
     */
    public function tokenizePart(string $pattern, int $offset, int $length, string $flags = '', bool $extendedMore = false): TokenStream
    {
        if ($pattern !== $this->wholePattern) {
            $this->wholePattern = $pattern;
            $this->state->bodies?->forget();
        }

        $this->state->bodiesInPlace = false;

        return $this->read(substr($pattern, $offset, $length), $flags, $extendedMore, $offset);
    }

    private function read(string $pattern, string $flags, bool $extendedMore, int $offset): TokenStream
    {
        $state = $this->state;
        $state->start(new PatternText($this->syntax, $pattern, $flags, $this->wholePattern, $offset), $flags, $extendedMore);
        $length = $state->length;

        /** @var list<Token> $tokens */
        $tokens = [];

        try {
            // One window for every regex of the run: the limits are read
            // once, not once per token.
            LibraryPcre::run(function () use (&$tokens, $state, $length): void {
                while ($state->position < $length) {
                    if (null !== $state->classTextEnd) {
                        $tokens[] = ClassState::itemText($state, $state->classTextEnd);

                        continue;
                    }

                    if ($this->readWithoutTheRegex($tokens)) {
                        continue;
                    }

                    if (!$state->inCharClass && !$state->extendedMode && !$state->extendedMoreMode && null !== $text = $this->plainCharacter()) {
                        $tokens[] = $text;

                        continue;
                    }

                    [$regex, $tokenMap] = $this->getCurrentContext();
                    [$matchedValue, $startPos, $matches] = $this->matchAtPosition($regex);
                    $tokens[] = $this->createToken($tokenMap, $matches, $matchedValue, $startPos, $tokens);
                }

                $this->validateFinalState($tokens);
            });
        } finally {
            $this->tokensRead = $tokens;
        }

        $tokens[] = new Token(TokenType::Eof, '', $state->position);

        return new TokenStream($tokens, $pattern);
    }

    /**
     * The character at the cursor as a literal token, when nothing but the
     * literal of the token regex can read it there: a plain ASCII character,
     * a byte of a pattern that is not UTF-8, a whole UTF-8 character of one
     * that is. Null for anything else, which the token regex reads.
     */
    private function plainCharacter(): ?Token
    {
        $state = $this->state;
        $at = $state->position;
        $byte = $state->pattern[$at];
        $ord = \ord($byte);

        if ($ord < 0x80) {
            if (!isset((self::$plainBytes ??= self::plainBytes())[$byte])) {
                return null;
            }

            $state->position++;

            return new Token(TokenType::Literal, $byte, $at, 1);
        }

        if ($state->byteMode) {
            $state->position++;

            return new Token(TokenType::Literal, $byte, $at, 1);
        }

        // A valid UTF-8 pattern, read a character at a time: every token
        // ends between two characters, so the cursor stands on a lead byte,
        // which gives the length.
        $length = Utf8::width($state->pattern, $at);
        $state->position += $length;

        return new Token(TokenType::Literal, substr($state->pattern, $at, $length), $at, $length);
    }

    /**
     * @return array<int|string, true>
     */
    private static function plainBytes(): array
    {
        $bytes = [];
        for ($i = 0, $count = \strlen(self::PLAIN_TEXT); $i < $count; $i++) {
            $bytes[self::PLAIN_TEXT[$i]] = true;
        }

        return $bytes;
    }

    private function getRegexOutside(): string
    {
        // The token patterns are constants and the regex only varies with
        // the byte mode.
        return $this->regexes['outside'.($this->state->byteMode ? 1 : 0)] ??= $this->compilePattern(self::PATTERNS_OUTSIDE);
    }

    private function getRegexInside(): string
    {
        return $this->regexes['inside'.($this->state->byteMode ? 1 : 0)] ??= $this->compilePattern(self::PATTERNS_INSIDE);
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

        return '/(?:'.implode('|', $regexParts).')/xsA'.($this->state->byteMode ? '' : 'u');
    }

    /**
     * Reads at the cursor what the token regex does not, and says whether it
     * did: the text of a quoted run or a comment, a "#" comment under "x",
     * the Perl extended class, what a "(" opens that the token regex cannot
     * read, the spaces and tabs "(?xx)" skips in a class, an escape PHP 7.3
     * and 7.4 read as its character, and "\p{" that no "}" closes. Called at
     * every character, before the plain text and the token regex.
     *
     * @param list<Token> $tokens
     */
    private function readWithoutTheRegex(array &$tokens): bool
    {
        $state = $this->state;
        if ($state->inQuoteMode) {
            if ($token = Tunnels::quoted($state)) {
                $tokens[] = $token;
            }

            return true;
        }

        if ($state->inCommentMode) {
            if ($token = Tunnels::comment($state)) {
                $tokens[] = $token;
            }

            return true;
        }

        // The loop reads only while a character is left at the cursor.
        $at = $state->position;
        $char = $state->pattern[$at];

        if ($state->extendedMode && !$state->inCharClass && '#' === $char) {
            Tunnels::extendedComment($state, $tokens);

            return true;
        }

        if ('(' === $char && !$state->inCharClass) {
            // "(?[...])", PCRE2 10.45: one token the parser reads the expression of.
            if ($this->syntax->readsExtendedClass && '(?[' === substr($state->pattern, $at, 3)) {
                $tokens[] = ClassState::extendedClass($state);

                return true;
            }

            if (GroupOpeners::readBody($state, $tokens)) {
                return true;
            }

            $opener = GroupOpeners::unclosedOpener($state);
            if (null === $opener) {
                return false;
            }

            $type = '(' === $opener ? 'T_GROUP_OPEN' : 'T_GROUP_MODIFIER_OPEN';
            $state->position += \strlen($opener);
            $tokens[] = $this->createToken([$type], [$type => $opener], $opener, $at, $tokens);

            return true;
        }

        // Under "(?xx)" PCRE skips spaces and tabs anywhere in a class:
        // "(?xx)[ ]]" holds "]", "(?xx)[a - c]" is the range "a-c". They
        // carry no meaning there and are not given a token.
        if ($state->extendedMoreMode && $state->inCharClass && \in_array($char, [' ', "\t"], true)) {
            $state->position++;

            return true;
        }

        if ('\\' !== $char) {
            return false;
        }

        // PHP 7.3 and 7.4 read an escape PCRE2 refuses as it reads it as the
        // character after the backslash, then what follows as pattern text.
        $text = $state->text;
        if ($text->badEscapeIsLiteral
            && BadEscape::at($state->pattern, $at, $state->inCharClass, $text->utf, $this->syntax->target, null, ClassRanges::startsRangeEnd($text, $tokens, $at, $state->inCharClass ? $state->charClassStartPositions[\count($state->charClassStartPositions) - 1] : null))) {
            $tokens[] = new Token(TokenType::LiteralEscaped, $state->pattern[$at + 1], $at, 2);
            $state->literalEscapes[$at] = true;
            $state->position += 2;

            return true;
        }

        // "\p{" that no "}" closes is the escape "\p" then text, as the token
        // regex reads it, without searching the rest of the text for a "}"
        // at each one.
        if ($text->opensUnclosedProperty($at)) {
            $escape = substr($state->pattern, $at, 2);
            $state->position += 2;
            $tokens[] = $this->createToken(['T_LITERAL_ESCAPED'], ['T_LITERAL_ESCAPED' => $escape], $escape, $at, $tokens);

            return true;
        }

        return false;
    }

    /**
     * @return array{0: string, 1: array<string>}
     */
    private function getCurrentContext(): array
    {
        if ($this->state->inCharClass) {
            return [$this->getRegexInside(), self::TOKENS_INSIDE];
        }

        return [$this->getRegexOutside(), self::TOKENS_OUTSIDE];
    }

    /**
     * @return array{0: string, 1: int, 2: array<int|string, string|null>}
     */
    private function matchAtPosition(string $regex): array
    {
        $state = $this->state;
        if (1 !== LibraryPcre::match($regex, $state->pattern, $matches, \PREG_UNMATCHED_AS_NULL, $state->position)) {
            // Every character starts a token but a backslash with nothing
            // after it, which PCRE reports where the pattern ends.
            if ('\\' !== substr($state->pattern, $state->position)) {
                throw InternalFailureException::unexpectedState(\sprintf('No token of the lexer starts at offset %d of the pattern.', $state->position));
            }

            throw LexerException::withContext(
                \sprintf('Unable to tokenize pattern at position %d. Context: "\\..."', $state->length),
                ErrorCode::EscapeTrailingBackslash,
                $state->length,
                $state->pattern,
            );
        }

        $matchedValue = (string) $matches[0];
        $startPos = $state->position;
        $state->position += \strlen($matchedValue);

        return [$matchedValue, $startPos, $matches];
    }

    /**
     * @param array<string>                  $tokenMap
     * @param array<int|string, string|null> $matches
     * @param list<Token>                    $currentTokens
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

            $type = TokenType::from(Ascii::lower(substr($tokenName, 2)));

            if ($token = $this->handleStatefulToken($type, $matchedValue, $startPos, $currentTokens)) {
                return $token;
            }

            if (TokenType::LiteralEscaped === $type) {
                // "\c" only falls through to an escaped literal when no
                // character it can XOR with 0x40 follows it; PCRE
                // rejects that.
                if ('\\c' === $matchedValue) {
                    $this->state->refusedTokenStart = $startPos;

                    throw LexerException::withContext(
                        '\\c must be followed by a character it can read.',
                        ErrorCode::ControlCharInvalid,
                        $this->syntax->target->faultOffset($this->afterCharacter($startPos + 2), $this->afterCharacter($startPos + 2) - $startPos - 2),
                        $this->state->pattern,
                    );
                }

                // "\x{}" (empty braces) is a PCRE compile error, where the
                // digits should be.
                if ('\\x' === $matchedValue && '{}' === substr($this->state->pattern, $startPos + 2, 2)) {
                    $this->state->refusedTokenStart = $startPos;

                    throw LexerException::withContext(
                        'Invalid hex escape "\\x{}": at least one hexadecimal digit is required.',
                        ErrorCode::EscapeDigitsMissing,
                        $startPos + 3,
                        $this->state->pattern,
                    );
                }
            }

            $value = TokenValue::of($type, $matchedValue, $matches, $this->state->inCharClass);

            // The value may be a rewrite of what was matched — a stripped
            // backslash, a normalized property name — so the token carries the
            // length of the text it was cut from, not the length of its value.
            return new Token($type, $value, $startPos, \strlen($matchedValue));
        }

        throw InternalFailureException::unexpectedState(\sprintf('A token the lexer matched at offset %d has no known type.', $startPos));
    }

    /**
     * @param list<Token> $currentTokens
     */
    private function handleStatefulToken(
        TokenType $type,
        string $matchedValue,
        int $startPos,
        array $currentTokens
    ): ?Token {
        $state = $this->state;
        if (!$state->inCharClass) {
            $this->trackExtendedModeScope($type);
        }

        if (TokenType::PcreVerb === $type && !$state->inCharClass) {
            VerbNames::refuse($state->text, TokenValue::verb($matchedValue), $startPos, $currentTokens, $state->extendedMode);
        }

        // Before PCRE2 10.37 a backslash and its digits are one number, refused
        // at the digit that takes it past 214748363.
        if (!$this->syntax->overflowingNumberIsOctal && !$state->inCharClass && TokenType::Backref === $type
            && 1 === LibraryPcre::match('/^\\\\([1-9]\d{8,})$/', $matchedValue, $digits)) {
            $value = 0;
            for ($index = 0, $count = \strlen($digits[1]); $index < $count; $index++) {
                $value = 10 * $value + (int) $digits[1][$index];
                if ($value > self::LARGEST_NUMBER_BEFORE_PCRE_10_37) {
                    $overflow = $startPos + 2 + $index;

                    throw LexerException::withContext(
                        \sprintf('Number too big at position %d: PCRE2 before 10.37 reads the digits after a backslash as one number, at most 214748363.', $overflow),
                        ErrorCode::GroupNumberTooBig,
                        $overflow,
                        $state->pattern,
                    );
                }
            }
        }

        // "(*name:" read as a plain "(": its body never closes and is not
        // read in place. A name PCRE does not know is refused where it ends.
        // Where a name is due, as after "(?<", PCRE wants it on the "(" and
        // reads no name: the parser refuses it there.
        if (TokenType::GroupOpen === $type && null !== ($name = PcreVerb::nameBeforeColonAt($state->pattern, $startPos, PcreVerb::LOWERCASE_NAME)) && !VerbNames::followsNameOpener($state->text, $startPos)) {
            // "(?(*atomic:" is no condition: PCRE takes a lookaround there,
            // and refuses any other name it knows at the colon.
            $known = PcreVerb::takesArgument($name) || ($this->syntax->readsScanSubstring && \in_array($name, ['scs', 'scan_substring'], true));
            if ($known && !PcreVerb::isLookaround($name) && VerbNames::opensCondition($currentTokens, $startPos)) {
                $colon = $startPos + 2 + \strlen($name);

                throw LexerException::withContext(
                    \sprintf('Invalid conditional condition at position %d: a lookaround assertion is expected after "(?(".', $colon),
                    ErrorCode::ConditionalAssertionExpected,
                    $colon,
                    $state->pattern,
                );
            }

            // In "(?((*foo:" the verb opens after the condition's own "(":
            // PCRE wants a group name there, on the "(" of the verb. In
            // "(?(*foo:" the verb is the condition, refused as a verb.
            if (!$known && $startPos >= 3 && '(?((' === substr($state->pattern, $startPos - 3, 4)) {
                throw LexerException::withContext(
                    \sprintf('Invalid conditional construct at position %d. Condition must be a group reference, lookaround, or (DEFINE).', $startPos),
                    ErrorCode::ConditionalInvalid,
                    $startPos,
                    $state->pattern,
                );
            }

            // A name too long is refused as one before it is looked up.
            if ($this->syntax->namesReadWhole) {
                VerbNames::refuseTooLong($state->text, \strlen($name), $startPos);
            }

            // A name PCRE knows opens a body read in place.
            throw LexerException::withContext(
                \sprintf('Unknown alphabetic assertion "(*%s:".', $name),
                ErrorCode::VerbInvalid,
                $startPos + 2 + \strlen($name),
                $state->pattern,
            );
        }

        // Before PCRE2 10.43, "{,2}" and "{ 2 }" are text: only the "{" is
        // read here, and what follows it is read again as text. A "\N" right
        // before keeps its count, which the validator refuses there.
        if (TokenType::Quantifier === $type && !$this->syntax->wideRepeatCounts && '{' === $matchedValue[0]
            && !self::isPlainCount($matchedValue)
            && !$this->followsNamedCharacterEscape($currentTokens, $startPos)) {
            $state->position = $startPos + 1;

            return new Token(TokenType::Literal, '{', $startPos);
        }

        return match ($type) {
            TokenType::CharClassOpen => ClassState::open($state, $startPos),
            TokenType::CharClassClose => ClassState::close($state, $startPos, $currentTokens),
            TokenType::CommentOpen => Tunnels::openComment($state, $startPos),
            TokenType::QuoteModeStart => Tunnels::openQuote($state, $startPos),
            default => $this->handleContextualLiteral($type, $matchedValue, $startPos, $currentTokens),
        };
    }

    /**
     * Whether a quantifier token is a count every release reads: "{n}",
     * "{n,}" or "{n,m}", unpadded.
     */
    private static function isPlainCount(string $quantifier): bool
    {
        return 1 === LibraryPcre::match('/^\{\d++(?:,\d*+)?\}/', $quantifier);
    }

    /**
     * @param array<Token> $currentTokens
     */
    private function followsNamedCharacterEscape(array $currentTokens, int $position): bool
    {
        $previous = end($currentTokens);

        return false !== $previous
            && TokenType::CharType === $previous->type
            && 'N' === $previous->value
            && $previous->end() === $position;
    }

    /**
     * Follow the /x setting through the group structure, so that "(?x)" and
     * "(?x:...)" turn '#' comments on the way PCRE does: "(?x)" holds until
     * the end of the enclosing group, "(?x:...)" only inside its own group.
     */
    private function trackExtendedModeScope(TokenType $type): void
    {
        if (TokenType::GroupClose === $type) {
            $state = $this->state;
            [$state->extendedMode, $state->extendedMoreMode] = array_pop($state->extendedModeStack)
                ?? [$state->extendedMode, $state->extendedMoreMode];

            return;
        }

        if (TokenType::GroupOpen === $type) {
            $state = $this->state;
            $state->extendedModeStack[] = [$state->extendedMode, $state->extendedMoreMode];

            return;
        }

        if (TokenType::GroupModifierOpen !== $type) {
            return;
        }

        $state = $this->state;
        $inlineFlags = $state->text->inlineFlags($state->position);
        if (null === $inlineFlags) {
            $state->extendedModeStack[] = [$state->extendedMode, $state->extendedMoreMode];

            return;
        }

        [$flags, $scoped] = $inlineFlags;
        $updated = $flags->inForce('x', $state->extendedMode);
        $updatedMore = $flags->extendedMoreInForce($state->extendedMoreMode);

        // "(?x)" survives its own closing parenthesis: push the new value so
        // the pop performed by ")" leaves it in place. "(?x:...)" pushes the
        // previous value instead, which the pop restores.
        $state->extendedModeStack[] = $scoped
            ? [$state->extendedMode, $state->extendedMoreMode]
            : [$updated, $updatedMore];
        $state->extendedMode = $updated;
        $state->extendedMoreMode = $updatedMore;
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
        if (!$this->state->inCharClass || TokenType::Literal !== $type) {
            return null;
        }

        $prefix = ClassState::prefix($this->state, $currentTokens);

        // Only the first "^" negates: in "[^^]" the second one is a member.
        if (false === $prefix && '^' === $matchedValue) {
            return new Token(TokenType::Negation, '^', $startPos);
        }

        if (null === $prefix && '-' === $matchedValue) {
            return new Token(TokenType::Range, '-', $startPos);
        }

        return null;
    }

    /**
     * The offset past the character at $position, as PCRE reads it: one byte,
     * or a whole UTF-8 character in UTF mode; the end of the pattern stays
     * where it is.
     */
    private function afterCharacter(int $position): int
    {
        $state = $this->state;
        if ($position >= $state->length) {
            return $state->length;
        }

        // A pattern that is no UTF-8 is read byte by byte, whatever its flags.
        return $position + ($state->text->utf && !$state->byteMode ? Utf8::width($state->pattern, $position) : 1);
    }

    /**
     * @param list<Token> $tokens
     */
    private function validateFinalState(array $tokens): void
    {
        $state = $this->state;
        if ([] !== $state->charClassStartPositions) {
            $classStart = $state->charClassStartPositions[0];

            // Before PCRE2 10.45, "(?[" is no extended class: PCRE refuses
            // the "[" after "(?", whatever follows it.
            if (!$this->syntax->readsExtendedClass && ClassState::opensExtendedClass($tokens, $classStart)) {
                throw LexerException::withContext(
                    \sprintf('Invalid group modifier syntax at position %d.', $classStart),
                    ErrorCode::GroupSyntax,
                    $classStart,
                    $state->pattern,
                );
            }

            // Before PCRE2 10.45, "[\E", "[^\Q\E" and the like run into the
            // end of the pattern as a backslash: PCRE skips the empty quotes
            // that open a class, and under "(?xx)" its spaces and tabs, and
            // meets nothing after the last quote. Under
            // PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL, as PHP 7.3 and 7.4 compile,
            // that backslash is a character, and the class is left open.
            $skipped = $state->extendedMoreMode ? '(?:\\\\(?:Q\\\\)?E|[ \t])' : '(?:\\\\(?:Q\\\\)?E)';
            if (!$this->syntax->emptyQuoteOpeningClassIsUnclosed && !$state->text->badEscapeIsLiteral
                && 1 === LibraryPcre::match('/\G'.$skipped.'*+(?:\^'.$skipped.'*+)?(?<=\\\\E)\z/', $state->pattern, $quotes, 0, $classStart + 1)) {
                throw LexerException::withContext(
                    \sprintf('A backslash ends the pattern at position %d.', $state->length),
                    ErrorCode::EscapeTrailingBackslash,
                    $state->length,
                    $state->pattern,
                );
            }

            // PCRE reads the ranges of the class first: a reversed one is
            // refused past its end, one byte before it up to 10.46.
            $reversed = ClassRanges::reversedRangeEnd($state->text, $tokens, $classStart);
            if (null !== $reversed) {
                throw LexerException::withContext(
                    'Range out of order in character class.',
                    ErrorCode::RangeReversed,
                    $this->syntax->target->faultOffset($reversed),
                    $state->pattern,
                );
            }

            throw LexerException::withContext(
                'Unclosed character class "]" at end of input.',
                ErrorCode::CharclassUnclosed,
                ClassState::opensExtendedClass($tokens, $classStart) ? $classStart : $state->position,
                $state->pattern,
            );
        }

        if ($state->inCommentMode) {
            throw LexerException::withContext(
                'Unclosed comment ")" at end of input.',
                ErrorCode::CommentUnclosed,
                $state->position,
                $state->pattern,
            );
        }

        // A body read to the end with no error in it: PCRE misses its ")"
        // there.
        if (null !== $state->unclosedBody) {
            throw LexerException::withContext(
                \sprintf('Missing closing parenthesis for "%s".', $state->unclosedBody),
                ErrorCode::GroupUnclosed,
                $state->length,
                $state->pattern,
            );
        }
    }
}
