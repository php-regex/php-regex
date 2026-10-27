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
use PHPRegex\Parser\Lexing\BodyEnds;
use PHPRegex\Parser\Lexing\ClassRanges;
use PHPRegex\Parser\Lexing\PatternText;
use PHPRegex\Parser\Lexing\TargetSyntax;
use PHPRegex\Parser\Lexing\TokenValue;
use PHPRegex\Parser\Lexing\VerbNames;
use PHPRegex\Parser\Syntax\BadEscape;
use PHPRegex\Parser\Syntax\PcreVerb;
use PHPRegex\Parser\Syntax\PosixItem;
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

    // Pattern literals
    private const PATTERN_QUOTE_END = '\E';
    private const PATTERN_COMMENT_CLOSE = ')';

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
     * The text read, how PCRE reads it, and where it stands in the whole
     * pattern.
     */
    private PatternText $text;

    /**
     * The text read, its length and whether it is read byte by byte, as the
     * text holds them: the loop over its characters reads them at each one.
     */
    private string $pattern;

    private int $position = 0;

    private int $length = 0;

    private bool $inCharClass = false;

    private bool $inQuoteMode = false;

    private bool $inCommentMode = false;

    /**
     * Where the POSIX item read whole, character by character, ends: its
     * "]" when it opens a class, past it in a class.
     */
    private ?int $classTextEnd = null;

    private bool $byteMode = false;

    private bool $extendedMode = false;

    /**
     * The quoted run read last: where the reading of it started, where its
     * text ends, and what ends it, "\E" or "" at the end of the text. A run
     * in a class is read one character at a time, each without searching
     * the rest of the run again.
     *
     * @var array{0: int, 1: int, 2: string}|null
     */
    private ?array $quotedRun = null;

    /**
     * Whether the body of every alphabetic assertion is read in place, as
     * PCRE reads it, rather than as one token: its opener, then the tokens
     * of the body, then its ")".
     */
    private bool $bodiesInPlace = false;

    /**
     * The opener of the first body that never closes, "(*pla:" or "(?*",
     * read in place.
     */
    private ?string $unclosedBody = null;

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

    /**
     * The whole pattern the text read stands in: the text itself unless a
     * part of it is read.
     */
    private string $wholePattern = '';

    /**
     * Where the bodies of the whole pattern end, as far as they were read:
     * built at the first body the lexer reads ahead.
     */
    private ?BodyEnds $bodies = null;

    /**
     * @var list<Token>
     */
    private array $tokensRead = [];

    /**
     * Where each escape read as its character stands.
     *
     * @var array<int, true>
     */
    private array $literalEscapes = [];

    /**
     * Where the token tokenizing stopped on starts, when it is refused for
     * itself, whatever stands around it.
     */
    private ?int $refusedTokenStart = null;

    /**
     * @param PcreTarget|null $target the PHP and PCRE2 judged; the running ones when null
     */
    public function __construct(?PcreTarget $target = null)
    {
        $this->syntax = new TargetSyntax($target ?? PcreTarget::runtime());
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
        return $this->literalEscapes;
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
        return $this->refusedTokenStart;
    }

    /**
     * @param bool $extendedMore whether the text is read as under "(?xx)", as the
     *                           classes of an extended class are
     */
    public function tokenize(string $pattern, string $flags = '', bool $extendedMore = false): TokenStream
    {
        $this->wholePattern = $pattern;
        $this->bodies?->forget();
        $this->bodiesInPlace = false;

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
        $this->bodies?->forget();
        $this->bodiesInPlace = true;

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
            $this->bodies?->forget();
        }

        $this->bodiesInPlace = false;

        return $this->read(substr($pattern, $offset, $length), $flags, $extendedMore, $offset);
    }

    private function read(string $pattern, string $flags, bool $extendedMore, int $offset): TokenStream
    {
        $this->text = $text = new PatternText($this->syntax, $pattern, $flags, $this->wholePattern, $offset);
        $this->pattern = $pattern;
        $this->length = $text->length;
        $this->byteMode = $text->byteMode;
        $this->extendedMode = str_contains($flags, 'x');
        $this->extendedMoreMode = $extendedMore;
        $this->resetState();

        /** @var list<Token> $tokens */
        $tokens = [];

        try {
            // One window for every regex of the run: the limits are read
            // once, not once per token.
            LibraryPcre::run(function () use (&$tokens): void {
                while ($this->position < $this->length) {
                    if (null !== $this->classTextEnd) {
                        $tokens[] = $this->consumeClassText($this->classTextEnd);

                        continue;
                    }

                    if ($this->handleTunnelModes($tokens)) {
                        continue;
                    }

                    if (!$this->inCharClass && !$this->extendedMode && !$this->extendedMoreMode && null !== $text = $this->plainCharacter()) {
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

        $tokens[] = new Token(TokenType::Eof, '', $this->position);

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
        $at = $this->position;
        $byte = $this->pattern[$at];
        $ord = \ord($byte);

        if ($ord < 0x80) {
            if (!isset((self::$plainBytes ??= self::plainBytes())[$byte])) {
                return null;
            }

            $this->position++;

            return new Token(TokenType::Literal, $byte, $at, 1);
        }

        if ($this->byteMode) {
            $this->position++;

            return new Token(TokenType::Literal, $byte, $at, 1);
        }

        // A valid UTF-8 pattern, read a character at a time: every token
        // ends between two characters, so the cursor stands on a lead byte,
        // which gives the length.
        $length = Utf8::width($this->pattern, $at);
        $this->position += $length;

        return new Token(TokenType::Literal, substr($this->pattern, $at, $length), $at, $length);
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
        return $this->regexes['outside'.($this->byteMode ? 1 : 0)] ??= $this->compilePattern(self::PATTERNS_OUTSIDE);
    }

    private function getRegexInside(): string
    {
        return $this->regexes['inside'.($this->byteMode ? 1 : 0)] ??= $this->compilePattern(self::PATTERNS_INSIDE);
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

    private function resetState(): void
    {
        $this->position = 0;
        $this->extendedModeStack = [];
        $this->inCharClass = false;
        $this->inQuoteMode = false;
        $this->inCommentMode = false;
        $this->classTextEnd = null;
        $this->charClassStartPositions = [];
        $this->quotedRun = null;
        $this->unclosedBody = null;
        $this->literalEscapes = [];
        $this->refusedTokenStart = null;
    }

    /**
     * @param list<Token> $tokens
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

        // "(?[...])", PCRE2 10.45: one token the parser reads the expression of.
        if ('(' === $this->pattern[$this->position] && !$this->inCharClass && $this->syntax->readsExtendedClass && '(?[' === substr($this->pattern, $this->position, 3)) {
            $tokens[] = $this->consumeExtendedClass();

            return true;
        }

        if (!$this->inCharClass && '(' === $this->pattern[$this->position] && $this->readGroupOpener($tokens)) {
            return true;
        }

        // Under "(?xx)" PCRE skips spaces and tabs anywhere in a class:
        // "(?xx)[ ]]" holds "]", "(?xx)[a - c]" is the range "a-c". They
        // carry no meaning there and are not given a token.
        if ($this->extendedMoreMode && $this->inCharClass
            && \in_array($this->pattern[$this->position], [' ', "\t"], true)) {
            $this->position++;

            return true;
        }

        // PHP 7.3 and 7.4 read an escape PCRE2 refuses as it reads it as the
        // character after the backslash, then what follows as pattern text.
        if ($this->text->badEscapeIsLiteral && '\\' === $this->pattern[$this->position]
            && BadEscape::at($this->pattern, $this->position, $this->inCharClass, $this->text->utf, $this->syntax->target, null, ClassRanges::startsRangeEnd($this->text, $tokens, $this->position, $this->inCharClass ? $this->charClassStartPositions[\count($this->charClassStartPositions) - 1] : null))) {
            $tokens[] = new Token(TokenType::LiteralEscaped, $this->pattern[$this->position + 1], $this->position, 2);
            $this->literalEscapes[$this->position] = true;
            $this->position += 2;

            return true;
        }

        // "\p{" that no "}" closes is the escape "\p" then text, as the token
        // regex reads it, without searching the rest of the text for a "}"
        // at each one.
        if ('\\' === $this->pattern[$this->position] && $this->text->opensUnclosedProperty($this->position)) {
            $text = substr($this->pattern, $this->position, 2);
            $start = $this->position;
            $this->position += 2;
            $tokens[] = $this->createToken(['T_LITERAL_ESCAPED'], ['T_LITERAL_ESCAPED' => $text], $text, $start, $tokens);

            return true;
        }

        return false;
    }

    /**
     * Reads what the "(" at the cursor opens where the token regex cannot:
     * the body of an alphabetic assertion or a script run; "(*" or "(?C"
     * that no ")" closes, which is "(" then "*", or "(?" then text, as the
     * token regex reads them, without searching the rest of the text for a
     * ")" at each one; "(**" that no ")" closes is read so too, "(" then
     * "*". False when the token regex reads it, "(**" with a ")" after it
     * included. Either way the parser refuses "(**" where PCRE meets it,
     * after what comes before.
     *
     * @param list<Token> $tokens
     */
    private function readGroupOpener(array &$tokens): bool
    {
        $next = $this->pattern[$this->position + 1] ?? '';
        if (('*' === $next || '?' === $next)
            && 1 === LibraryPcre::match('/\G\((?:\?\*|\*[a-z_]++:)/', $this->pattern, $opener, 0, $this->position)
            && $this->readBody($opener[0], $tokens)) {
            return true;
        }

        $opener = match (true) {
            '*' === $next => '(',
            '?' === $next && 'C' === ($this->pattern[$this->position + 2] ?? '') => '(?',
            default => null,
        };
        if (null === $opener || $this->text->follows(')', $this->position + \strlen($opener) + 1)) {
            return false;
        }

        $start = $this->position;
        $type = '(' === $opener ? 'T_GROUP_OPEN' : 'T_GROUP_MODIFIER_OPEN';
        $this->position += \strlen($opener);
        $tokens[] = $this->createToken([$type], [$type => $opener], $opener, $start, $tokens);

        return true;
    }

    /**
     * Reads the alphabetic assertion or script run the cursor opens with
     * $opener, "(*pla:" or "(?*": one token with its whole body; its opener
     * alone when the body is read in place or never closes, a token whose
     * value is the opener without its "(" and first character, "pla:" or
     * "*", the body then read as any text is. PCRE reads a body that never
     * closes to the end of the pattern, and stops on the first error in
     * it, or on the ")" missing at the end. An opener whose body is never
     * read, as a name PCRE refuses, is left to the token regex when the
     * body does not close.
     *
     * @param list<Token> $tokens
     */
    private function readBody(string $opener, array &$tokens): bool
    {
        // Before PCRE2 10.35 "(?*" is no group: no option letter is "*".
        if ('(?*' === $opener && !$this->syntax->readsNonAtomicShortSyntax) {
            $this->refusedTokenStart = $this->position;

            throw LexerException::withContext(
                \sprintf('Invalid group modifier syntax at position %d: PCRE2 10.35 added "(?*" for "(*napla:"%s.', $this->position + 2, $this->syntax->readsNonAtomicAssertions ? '; write "(*napla:" instead' : ''),
                ErrorCode::GroupSyntax,
                $this->position + 2,
                $this->pattern,
            );
        }

        $name = substr($opener, 2, -1);
        $why = match (true) {
            PcreVerb::isAlphabeticAssertion($name) => $this->syntax->readsAlphabeticAssertions ? null
                : \sprintf('Unknown alphabetic assertion "(*%s:": PCRE2 10.33 added the alphabetic names of the assertions; write "%s" instead.', $name, PcreVerb::classicOpener($name)),
            PcreVerb::isNonAtomicAssertion($name) => $this->syntax->readsNonAtomicAssertions ? null
                : \sprintf('Unknown alphabetic assertion "(*%s:": PCRE2 10.34 added the non-atomic lookarounds.', $name),
            PcreVerb::namesScriptRun($name) => $this->syntax->readsScriptRuns ? null
                : \sprintf('Unknown verb "(*%s:": PCRE2 10.33 added script runs.', $name),
            // Before PCRE2 10.33 no verb has a lowercase name.
            default => $this->syntax->readsAlphabeticAssertions ? null : \sprintf('Invalid or unsupported PCRE verb: "%s".', $name),
        };
        // Where a name is due, as after "(?<", PCRE wants it on the "(" and
        // reads no verb: the parser refuses it there.
        if (null !== $why && !VerbNames::followsNameOpener($this->text, $this->position)) {
            // Before PCRE2 10.33 no name is alphabetic: as a condition PCRE
            // wants a group name on its "*", elsewhere a verb name ends with
            // its run of letters, and is not one PCRE knows. From 10.33 a
            // name it does not know yet is refused where it ends.
            $start = $this->position;
            $at = VerbNames::nothingReadBefore($this->text, $start, $this->extendedMode);
            $before = array_values(array_filter($tokens, static fn (Token $token): bool => $token->position < $at));
            if (!$this->syntax->readsAlphabeticAssertions && VerbNames::opensCondition($before, $at)) {
                // After a callout it wants an assertion, on the "(", what it
                // reads as nothing between them aside.
                $last = $before[\count($before) - 1] ?? null;
                if (null !== $last && TokenType::Callout === $last->type) {
                    throw LexerException::withContext(
                        \sprintf('Invalid conditional condition at position %d: a lookaround assertion is expected after the callout.', $start),
                        ErrorCode::ConditionalAssertionExpected,
                        $start,
                        $this->pattern,
                    );
                }

                if ($at === $start) {
                    throw VerbNames::conditionWantsAName($start + 1, $this->pattern);
                }
            }

            if (!$this->syntax->namesReadWhole) {
                VerbNames::refuseLongLetterRun($this->text, strspn($name, 'abcdefghijklmnopqrstuvwxyz'), $start);
            }

            throw LexerException::withContext(
                $why,
                ErrorCode::VerbInvalid,
                $start + 2 + ($this->syntax->readsAlphabeticAssertions ? \strlen($name) : strspn($name, 'abcdefghijklmnopqrstuvwxyz')),
                $this->pattern,
            );
        }

        $inPlace = $this->readsBodyInPlace($opener, $tokens);
        $end = $this->bodiesInPlace && $inPlace ? null
            : ($this->bodies ??= new BodyEnds())->assertionEnd($this->text, $this->position, $opener, $this->extendedMode, $this->extendedMoreMode);
        $start = $this->position;
        if (null !== $end) {
            // The body is copied once, into the value of its token: the name
            // is judged on the characters up to the first one no name holds,
            // which are all a verb name is judged on.
            VerbNames::refuse($this->text, substr($this->pattern, $start + 2, strspn($this->pattern, PcreVerb::WORD_NAME, $start + 2) + 1), $start, $tokens, $this->extendedMode);
            $this->position = $end;
            $tokens[] = new Token(TokenType::PcreVerb, substr($this->pattern, $start + 2, $end - $start - 3), $start, $end - $start);

            return true;
        }

        // "(*scs:" that no ")" closes: PCRE reads its list of groups, then
        // its body in place, as the body of any group.
        $opened = $opener;
        if (!$inPlace) {
            $list = $this->scanSubstringList($name, $tokens);
            if (null === $list) {
                return false;
            }
            $opened .= $list;
        }

        if (!$this->bodiesInPlace) {
            $this->unclosedBody ??= $opener;
        }

        // The opener opens a group: an option set in the body holds up to
        // its ")".
        $this->extendedModeStack[] = [$this->extendedMode, $this->extendedMoreMode];
        $this->position += \strlen($opened);
        $tokens[] = new Token(TokenType::PcreVerb, substr($opened, 2), $start, \strlen($opened));

        return true;
    }

    /**
     * The list of groups of the "(*scs:" or "(*scan_substring:" the cursor
     * opens, "(1,<n>)", which PCRE reads before the body; null where the
     * name opens no substring scan, as the condition of "(?(" or where a
     * group name is due.
     *
     * @param list<Token> $tokens
     */
    private function scanSubstringList(string $name, array $tokens): ?string
    {
        if (!$this->syntax->readsScanSubstring || !\in_array($name, ['scs', 'scan_substring'], true)
            || VerbNames::opensCondition($tokens, $this->position) || VerbNames::followsNameOpener($this->text, $this->position)) {
            return null;
        }

        $listOpen = $this->position + 3 + \strlen($name);
        $fault = PcreVerb::groupListFault($this->pattern, $listOpen, $this->syntax->target, $this->text->utf);
        if (null !== $fault) {
            if (!$this->syntax->target->supports(PcreFeature::GroupListErrors)) {
                $fault = PcreVerb::groupListFaultBefore1047($fault, $this->length);
            }

            throw LexerException::withContext($fault[2], $fault[1], $fault[0], $this->pattern);
        }

        // A list read without a fault ends at its first ")".
        $listEnd = (int) strpos($this->pattern, ')', $listOpen);

        return substr($this->pattern, $listOpen, $listEnd + 1 - $listOpen);
    }

    /**
     * Whether PCRE reads the body $opener opens at the cursor as the body of
     * a group: the body of an assertion or a script run, but not as the
     * condition of "(?(", which takes a lookahead or lookbehind only, not
     * "(?*" nor "(*atomic:".
     *
     * @param list<Token> $tokens
     */
    private function readsBodyInPlace(string $opener, array $tokens): bool
    {
        $name = '(?*' === $opener ? 'napla' : substr($opener, 2, -1);

        return PcreVerb::takesArgument($name)
            && (PcreVerb::isLookaround($name) || !VerbNames::opensCondition($tokens, $this->position));
    }

    /**
     * "(?[" to the "]" that closes it at its own level, and the ")" after it;
     * to the end of the pattern when none does. The parser judges the text.
     */
    private function consumeExtendedClass(): Token
    {
        $start = $this->position;
        $this->position = BodyEnds::extendedClassEnd($this->text, $start);

        return new Token(TokenType::ExtendedClass, substr($this->pattern, $start, $this->position - $start), $start);
    }

    /**
     * Under /x a '#' starts a comment that runs to the end of the line, so its
     * content is text: '[', '(' and friends must not be tokenized as regex
     * syntax. The comment is emitted as literals — '#', the body and the
     * closing newline — which is what the parser turns into a CommentNode.
     * The line ends at the newline the leading options of the pattern set:
     * that newline is given the value "\n" whatever its bytes, which the
     * token still spans.
     *
     * @param list<Token> $tokens
     */
    private function consumeExtendedComment(array &$tokens): void
    {
        $tokens[] = new Token(TokenType::Literal, '#', $this->position);
        $this->position++;

        $newline = $this->text->nextNewline($this->position);
        $bodyEnd = null === $newline ? $this->length : $newline[0];

        if ($bodyEnd > $this->position) {
            $body = substr($this->pattern, $this->position, $bodyEnd - $this->position);
            $tokens[] = new Token(TokenType::Literal, $body, $this->position);
            $this->position = $bodyEnd;
        }

        if (null === $newline) {
            return;
        }

        $tokens[] = new Token(TokenType::Literal, "\n", $this->position, $newline[1]);
        $this->position += $newline[1];

        // Outside UTF mode the byte 0x85 ends the line under "(*ANY)" even
        // inside a UTF-8 character: the bytes left of that character are
        // read one by one, as PCRE reads them.
        while ($this->position < $this->length && Utf8::continues($this->pattern[$this->position])) {
            $tokens[] = new Token(TokenType::Literal, $this->pattern[$this->position], $this->position);
            $this->position++;
        }
    }

    /**
     * The length in bytes of the character at $at: one byte in byte mode;
     * else, the text being valid UTF-8, its first byte and the bytes that
     * continue it.
     */
    private function characterLength(int $at): int
    {
        return $this->byteMode ? 1 : Utf8::width($this->pattern, $at);
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
     * @return array{0: string, 1: int, 2: array<int|string, string|null>}
     */
    private function matchAtPosition(string $regex): array
    {
        if (1 !== LibraryPcre::match($regex, $this->pattern, $matches, \PREG_UNMATCHED_AS_NULL, $this->position)) {
            // Every character starts a token but a backslash with nothing
            // after it, which PCRE reports where the pattern ends.
            if ('\\' !== substr($this->pattern, $this->position)) {
                throw InternalFailureException::unexpectedState(\sprintf('No token of the lexer starts at offset %d of the pattern.', $this->position));
            }

            throw LexerException::withContext(
                \sprintf('Unable to tokenize pattern at position %d. Context: "\\..."', $this->length),
                ErrorCode::EscapeTrailingBackslash,
                $this->length,
                $this->pattern,
            );
        }

        $matchedValue = (string) $matches[0];
        $startPos = $this->position;
        $this->position += \strlen($matchedValue);

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
                    $this->refusedTokenStart = $startPos;

                    throw LexerException::withContext(
                        '\\c must be followed by a character it can read.',
                        ErrorCode::ControlCharInvalid,
                        $this->syntax->target->faultOffset($this->afterCharacter($startPos + 2), $this->afterCharacter($startPos + 2) - $startPos - 2),
                        $this->pattern,
                    );
                }

                // "\x{}" (empty braces) is a PCRE compile error, where the
                // digits should be.
                if ('\\x' === $matchedValue && '{}' === substr($this->pattern, $startPos + 2, 2)) {
                    $this->refusedTokenStart = $startPos;

                    throw LexerException::withContext(
                        'Invalid hex escape "\\x{}": at least one hexadecimal digit is required.',
                        ErrorCode::EscapeDigitsMissing,
                        $startPos + 3,
                        $this->pattern,
                    );
                }
            }

            $value = TokenValue::of($type, $matchedValue, $matches, $this->inCharClass);

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
        if (!$this->inCharClass) {
            $this->trackExtendedModeScope($type);
        }

        if (TokenType::PcreVerb === $type && !$this->inCharClass) {
            VerbNames::refuse($this->text, TokenValue::verb($matchedValue), $startPos, $currentTokens, $this->extendedMode);
        }

        // Before PCRE2 10.37 a backslash and its digits are one number, refused
        // at the digit that takes it past 214748363.
        if (!$this->syntax->overflowingNumberIsOctal && !$this->inCharClass && TokenType::Backref === $type
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
                        $this->pattern,
                    );
                }
            }
        }

        // "(*name:" read as a plain "(": its body never closes and is not
        // read in place. A name PCRE does not know is refused where it ends.
        // Where a name is due, as after "(?<", PCRE wants it on the "(" and
        // reads no name: the parser refuses it there.
        if (TokenType::GroupOpen === $type && null !== ($name = PcreVerb::nameBeforeColonAt($this->pattern, $startPos, PcreVerb::LOWERCASE_NAME)) && !VerbNames::followsNameOpener($this->text, $startPos)) {
            // "(?(*atomic:" is no condition: PCRE takes a lookaround there,
            // and refuses any other name it knows at the colon.
            $known = PcreVerb::takesArgument($name) || ($this->syntax->readsScanSubstring && \in_array($name, ['scs', 'scan_substring'], true));
            if ($known && !PcreVerb::isLookaround($name) && VerbNames::opensCondition($currentTokens, $startPos)) {
                $colon = $startPos + 2 + \strlen($name);

                throw LexerException::withContext(
                    \sprintf('Invalid conditional condition at position %d: a lookaround assertion is expected after "(?(".', $colon),
                    ErrorCode::ConditionalAssertionExpected,
                    $colon,
                    $this->pattern,
                );
            }

            // In "(?((*foo:" the verb opens after the condition's own "(":
            // PCRE wants a group name there, on the "(" of the verb. In
            // "(?(*foo:" the verb is the condition, refused as a verb.
            if (!$known && $startPos >= 3 && '(?((' === substr($this->pattern, $startPos - 3, 4)) {
                throw LexerException::withContext(
                    \sprintf('Invalid conditional construct at position %d. Condition must be a group reference, lookaround, or (DEFINE).', $startPos),
                    ErrorCode::ConditionalInvalid,
                    $startPos,
                    $this->pattern,
                );
            }

            // A name too long is refused as one before it is looked up.
            if ($this->syntax->namesReadWhole) {
                VerbNames::refuseTooLong($this->text, \strlen($name), $startPos);
            }

            // A name PCRE knows opens a body read in place.
            throw LexerException::withContext(
                \sprintf('Unknown alphabetic assertion "(*%s:".', $name),
                ErrorCode::VerbInvalid,
                $startPos + 2 + \strlen($name),
                $this->pattern,
            );
        }

        // Before PCRE2 10.43, "{,2}" and "{ 2 }" are text: only the "{" is
        // read here, and what follows it is read again as text. A "\N" right
        // before keeps its count, which the validator refuses there.
        if (TokenType::Quantifier === $type && !$this->syntax->wideRepeatCounts && '{' === $matchedValue[0]
            && !self::isPlainCount($matchedValue)
            && !$this->followsNamedCharacterEscape($currentTokens, $startPos)) {
            $this->position = $startPos + 1;

            return new Token(TokenType::Literal, '{', $startPos);
        }

        return match ($type) {
            TokenType::CharClassOpen => $this->handleCharClassOpen($startPos),
            TokenType::CharClassClose => $this->closeCharClass($startPos, $currentTokens),
            TokenType::CommentOpen => $this->openComment($startPos),
            TokenType::QuoteModeStart => $this->openQuoteMode($startPos),
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
            [$this->extendedMode, $this->extendedMoreMode] = array_pop($this->extendedModeStack)
                ?? [$this->extendedMode, $this->extendedMoreMode];

            return;
        }

        if (TokenType::GroupOpen === $type) {
            $this->extendedModeStack[] = [$this->extendedMode, $this->extendedMoreMode];

            return;
        }

        if (TokenType::GroupModifierOpen !== $type) {
            return;
        }

        $inlineFlags = $this->text->inlineFlags($this->position);
        if (null === $inlineFlags) {
            $this->extendedModeStack[] = [$this->extendedMode, $this->extendedMoreMode];

            return;
        }

        [$flags, $scoped] = $inlineFlags;
        $updated = $flags->inForce('x', $this->extendedMode);
        $updatedMore = $flags->extendedMoreInForce($this->extendedMoreMode);

        // "(?x)" survives its own closing parenthesis: push the new value so
        // the pop performed by ")" leaves it in place. "(?x:...)" pushes the
        // previous value instead, which the pop restores.
        $this->extendedModeStack[] = $scoped
            ? [$this->extendedMode, $this->extendedMoreMode]
            : [$updated, $updatedMore];
        $this->extendedMode = $updated;
        $this->extendedMoreMode = $updatedMore;
    }

    private function handleCharClassOpen(int $startPos): Token
    {
        // PHP compiles without PCRE2's extended class syntax, so a "[" inside
        // a class is a member: nothing nests. A POSIX item whose name holds
        // a backslash, "[:\d-z:]", is read whole, as PCRE reads it, not as
        // the escapes it holds: one token in a class; the "[" of a collating
        // element, "[.\d.]", then each of its characters; the characters of
        // the class it opens otherwise.
        $terminator = PosixItem::terminatorPastABackslash($this->pattern, $startPos);
        if ($this->inCharClass) {
            if (null !== $terminator && ':' === $this->pattern[$startPos + 1]) {
                $this->position = $terminator + 2;

                return new Token(TokenType::PosixClass, substr($this->pattern, $startPos + 2, $terminator - $startPos - 2), $startPos, $this->position - $startPos);
            }

            $this->classTextEnd = null === $terminator ? null : $terminator + 2;

            return new Token(TokenType::Literal, '[', $startPos);
        }

        $this->classTextEnd = null === $terminator ? null : $terminator + 1;

        return $this->openCharClass($startPos);
    }

    /**
     * A character of a POSIX item read whole, as a member: no escape, no
     * range, no quote in it is read.
     */
    private function consumeClassText(int $end): Token
    {
        $start = $this->position;
        $this->position += $this->characterLength($start);
        if ($this->position >= $end) {
            $this->classTextEnd = null;
        }

        return new Token(TokenType::Literal, substr($this->pattern, $start, $this->position - $start), $start);
    }

    private function openCharClass(int $startPos): Token
    {
        $this->inCharClass = true;
        $this->charClassStartPositions[] = $startPos;

        return new Token(TokenType::CharClassOpen, '[', $startPos);
    }

    /**
     * @param array<Token> $currentTokens
     */
    private function closeCharClass(int $startPos, array $currentTokens): Token
    {
        if ($this->isAtCharClassStart($currentTokens)) {
            return new Token(TokenType::Literal, ']', $startPos);
        }

        array_pop($this->charClassStartPositions);
        if ([] === $this->charClassStartPositions) {
            $this->inCharClass = false;
        }

        return new Token(TokenType::CharClassClose, ']', $startPos);
    }

    private function openComment(int $startPos): Token
    {
        $this->inCommentMode = true;

        return new Token(TokenType::CommentOpen, '(?#', $startPos);
    }

    private function openQuoteMode(int $startPos): Token
    {
        $this->inQuoteMode = true;

        return new Token(TokenType::QuoteModeStart, '\Q', $startPos);
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
        if (!$this->inCharClass || TokenType::Literal !== $type) {
            return null;
        }

        $prefix = $this->readCharClassPrefix($currentTokens);

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

            if (TokenType::CharClassOpen === $token->type && $classStart === $token->position) {
                return $negated;
            }

            if (TokenType::Negation === $token->type) {
                $negated = true;

                continue;
            }

            // Quote mode is closed by the time a member is read here, so a
            // "\Q" in the prefix is the start of an empty "\Q\E": anything it
            // quoted would be a literal token, which ends the prefix.
            if (TokenType::QuoteModeEnd !== $token->type && TokenType::QuoteModeStart !== $token->type) {
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
        // A run in a class is read one character at a time: where the run
        // ends is searched once, not again for each character.
        $run = $this->quotedRun;
        if (null === $run || $this->position < $run[0] || $this->position > $run[1]) {
            // "\z", not "$": "$" stops before a final newline, which the
            // quoted run holds, and would drop it.
            if (1 !== LibraryPcre::match($this->anchored('(.*?)((\\\\E|\z))', 's'), $this->pattern, $matches, \PREG_UNMATCHED_AS_NULL, $this->position)) {
                // Nothing here can fail to match, and PCRE giving up throws
                // on its own. Leaving quote mode and skipping to the end
                // would drop the rest of the pattern without a word.
                throw InternalFailureException::unexpectedState('The regex reading a quoted run did not match.');
            }

            // Both groups always take part in the match; the null the
            // unmatched flag would give is not reachable.
            $run = $this->quotedRun = [$this->position, $this->position + \strlen((string) $matches[1]), (string) $matches[2]];
        }

        [, $textEnd, $endSequence] = $run;
        $startPos = $this->position;

        if ($textEnd > $startPos) {
            // Inside a class a quoted run stands for its characters one by
            // one: "[\Qabc\E-z]" is a, b and the range c-z.
            $length = $this->inCharClass ? $this->characterLength($startPos) : $textEnd - $startPos;
            $this->position += $length;

            return new Token(TokenType::Literal, substr($this->pattern, $startPos, $length), $startPos);
        }

        if (self::PATTERN_QUOTE_END === $endSequence) {
            $this->inQuoteMode = false;
            $token = new Token(TokenType::QuoteModeEnd, self::PATTERN_QUOTE_END, $this->position);
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
        // Nothing here can fail to match, and PCRE giving up throws on its own.
        if (1 !== LibraryPcre::match($this->anchored('([^)]*)(\)|$)'), $this->pattern, $matches, \PREG_UNMATCHED_AS_NULL, $this->position)) {
            throw InternalFailureException::unexpectedState('The regex reading a comment did not match.');
        }

        $commentText = (string) $matches[1];
        $endSequence = $matches[2];
        $startPos = $this->position;

        if ('' !== $commentText) {
            $this->position += \strlen($commentText);

            return new Token(TokenType::Literal, $commentText, $startPos);
        }

        if (self::PATTERN_COMMENT_CLOSE === $endSequence) {
            $this->inCommentMode = false;
            $token = new Token(TokenType::GroupClose, self::PATTERN_COMMENT_CLOSE, $this->position);
            $this->position += \strlen(self::PATTERN_COMMENT_CLOSE);

            return $token;
        }

        $this->position = $this->length;

        return null;
    }

    /**
     * The offset past the character at $position, as PCRE reads it: one byte,
     * or a whole UTF-8 character in UTF mode; the end of the pattern stays
     * where it is.
     */
    private function afterCharacter(int $position): int
    {
        if ($position >= $this->length) {
            return $this->length;
        }

        // A pattern that is no UTF-8 is read byte by byte, whatever its flags.
        return $position + ($this->text->utf && !$this->byteMode ? Utf8::width($this->pattern, $position) : 1);
    }

    /**
     * Whether the class at $position is the "[" of "(?[", a Perl extended
     * class PCRE2 only reads from 10.45; before, PCRE2 refuses that "[",
     * whatever follows it. Only a group opener ending there does: in
     * "\(?[a" and "\c(?[a" the "(" is a character, and the class is plain.
     *
     * @param list<Token> $tokens
     */
    private function opensExtendedClass(array $tokens, int $position): bool
    {
        foreach ($tokens as $token) {
            if ($token->end() === $position) {
                return TokenType::GroupModifierOpen === $token->type;
            }
        }

        return false;
    }

    /**
     * @param list<Token> $tokens
     */
    private function validateFinalState(array $tokens): void
    {
        if ([] !== $this->charClassStartPositions) {
            $classStart = $this->charClassStartPositions[0];

            // Before PCRE2 10.45, "(?[" is no extended class: PCRE refuses
            // the "[" after "(?", whatever follows it.
            if (!$this->syntax->readsExtendedClass && $this->opensExtendedClass($tokens, $classStart)) {
                throw LexerException::withContext(
                    \sprintf('Invalid group modifier syntax at position %d.', $classStart),
                    ErrorCode::GroupSyntax,
                    $classStart,
                    $this->pattern,
                );
            }

            // Before PCRE2 10.45, "[\E", "[^\Q\E" and the like run into the
            // end of the pattern as a backslash: PCRE skips the empty quotes
            // that open a class, and under "(?xx)" its spaces and tabs, and
            // meets nothing after the last quote. Under
            // PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL, as PHP 7.3 and 7.4 compile,
            // that backslash is a character, and the class is left open.
            $skipped = $this->extendedMoreMode ? '(?:\\\\(?:Q\\\\)?E|[ \t])' : '(?:\\\\(?:Q\\\\)?E)';
            if (!$this->syntax->emptyQuoteOpeningClassIsUnclosed && !$this->text->badEscapeIsLiteral
                && 1 === LibraryPcre::match('/\G'.$skipped.'*+(?:\^'.$skipped.'*+)?(?<=\\\\E)\z/', $this->pattern, $quotes, 0, $classStart + 1)) {
                throw LexerException::withContext(
                    \sprintf('A backslash ends the pattern at position %d.', $this->length),
                    ErrorCode::EscapeTrailingBackslash,
                    $this->length,
                    $this->pattern,
                );
            }

            // PCRE reads the ranges of the class first: a reversed one is
            // refused past its end, one byte before it up to 10.46.
            $reversed = ClassRanges::reversedRangeEnd($this->text, $tokens, $classStart);
            if (null !== $reversed) {
                throw LexerException::withContext(
                    'Range out of order in character class.',
                    ErrorCode::RangeReversed,
                    $this->syntax->target->faultOffset($reversed),
                    $this->pattern,
                );
            }

            throw LexerException::withContext(
                'Unclosed character class "]" at end of input.',
                ErrorCode::CharclassUnclosed,
                $this->opensExtendedClass($tokens, $classStart) ? $classStart : $this->position,
                $this->pattern,
            );
        }

        if ($this->inCommentMode) {
            throw LexerException::withContext(
                'Unclosed comment ")" at end of input.',
                ErrorCode::CommentUnclosed,
                $this->position,
                $this->pattern,
            );
        }

        // A body read to the end with no error in it: PCRE misses its ")"
        // there.
        if (null !== $this->unclosedBody) {
            throw LexerException::withContext(
                \sprintf('Missing closing parenthesis for "%s".', $this->unclosedBody),
                ErrorCode::GroupUnclosed,
                $this->length,
                $this->pattern,
            );
        }
    }
}
