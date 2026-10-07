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

use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Internal\ExtendedClassReader;
use PHPRegex\Parser\Internal\InlineFlags;
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\Internal\PcreVerb;
use PHPRegex\Parser\Internal\StartOptions;
use PHPRegex\Parser\Internal\StaticCaches;
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
        'T_CONTROL_CHAR', 'T_QUOTE_MODE_START', 'T_QUOTE_MODE_END', 'T_LITERAL_ESCAPED', 'T_LITERAL',
    ];

    /*
     * PCRE2 10.48 lets spaces and tabs pad the digits of "\x{...}", "\o{...}"
     * and "\N{U+...}", and the space before "U+": "\x{ 41 }" is "A". The
     * token patterns are compiled with /x, so the padding is spelled as a
     * class.
     */
    private const OCTAL_BRACED = '\\\\ o\\{ [\\x20\\t]*+ [0-7]++ [\\x20\\t]*+ \\}';

    private const UNICODE_ESCAPE = '\\\\ x [0-9a-fA-F]{1,2} | \\\\ u [0-9a-fA-F]{4} | \\\\ u\\{[0-9a-fA-F]++\\}'
        .' | \\\\ x\\{ [\\x20\\t]*+ [0-9a-fA-F]++ [\\x20\\t]*+ \\}';

    /*
     * "\N{4}" is "\N" repeated four times, not a character name: PCRE2 10.48
     * reads a repeat count first, spaces and "{,n}" included.
     */
    private const REPEAT_COUNT = '\\{ [\\x20\\t]*+ (?: \\d++ [\\x20\\t]*+ (?: , [\\x20\\t]*+ \\d*+ [\\x20\\t]*+ )? | , [\\x20\\t]*+ \\d++ [\\x20\\t]*+ ) \\}';

    private const UNICODE_NAMED = '\\\\ N (?!'.self::REPEAT_COUNT.') \\{ (?: [\\x20\\t]*+ U\\+[0-9a-fA-F]++ [\\x20\\t]*+ | [a-zA-Z0-9_ -]++ ) \\}';

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

    /**
     * Text quoted by \Q...\E, possibly up to the end of the pattern.
     */
    private const QUOTED_RUN = '\\\\Q (?: (?!\\\\E) [\\s\\S] )*+ (?: \\\\E | \\z )';

    /**
     * One item of a group body in which a ")" does not close the group: a
     * quoted run, an escape ("\c" with the character it takes, whatever it
     * is, and "\p{...}" to its "}"), a "(?#...)" comment, a callout, a verb
     * that ends at its first ")" as "(*MARK:a(b)" does, or text without
     * parentheses. Classes and nested groups are read by the caller. Each
     * ends where the lexer ends the token it reads there.
     */
    private const GROUP_BODY_ITEM = self::QUOTED_RUN
        .' | \\\\ [pP] \\{ [^}]++ \\} | \\\\ c [\\s\\S] | \\\\ (?!Q) [\\s\\S]'
        .' | \\( \\? \\# [^)]*+ \\)'
        .' | \\( \\? C (?: (?: '.self::CALLOUT_STRING.' ) (?= \\) ) | [^)]*+ ) \\)'
        .' | \\( \\* (?! [a-z_]++ : ) [^)]*+ \\)'
        .self::GROUP_BODY_TEXT;

    /**
     * Text without parentheses, the last item of a group body.
     */
    private const GROUP_BODY_TEXT = ' | [^()\\\\\\[]++';

    /**
     * The letters a POSIX class name is made of, "[:alpha:]".
     */
    private const POSIX_NAME_LETTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * The same text under "x", where "#" starts a comment that runs to the
     * end of the line, which the caller reads.
     */
    private const GROUP_BODY_TEXT_EXTENDED = ' | [^()\\\\\\[\\#]++';

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

    /**
     * @var array<int, string>
     */
    private static array $regexBodyItem = [];

    private string $pattern;

    private int $position = 0;

    private int $length = 0;

    private bool $inCharClass = false;

    private bool $inQuoteMode = false;

    private bool $inCommentMode = false;

    private bool $byteMode = false;

    /**
     * Whether PCRE reads the pattern as UTF-8, one character being several
     * bytes: under "u", or "(*UTF)" at its start.
     */
    private bool $utf = false;

    private bool $extendedMode = false;

    /**
     * The newline convention set by the options the whole pattern opens
     * with, "LF" when they set none, and whether PCRE reads the whole
     * pattern as UTF-8: under "x" a "#" comment ends at that newline.
     */
    private string $newline = 'LF';

    private bool $newlineUtf = false;

    /**
     * By byte, where the search for it in the text read last started, and
     * the first one it found there, false when none follows: a run of "\p{"
     * that no "}" closes, or of "(?C" that no ")" closes, is not searched to
     * the end once per opener.
     *
     * @var array<string, array{0: int, 1: int|false}>
     */
    private array $nextFound = [];

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
     * The whole pattern the text read stands in, and where the text starts
     * in it: the text itself unless a part of it is read.
     */
    private string $wholePattern = '';

    private int $offset = 0;

    /**
     * Where the bodies opened in the whole pattern end, as far as they were
     * read: by reading mode, then by the offset of their "(" in the whole
     * pattern, the offset past their ")", null for a body that never
     * closes, and the end of the text they were read in.
     *
     * @var array<int, array<int, array{0: int|null, 1: int}>>
     */
    private array $bodyEnds = [];

    /**
     * @var list<Token>
     */
    private array $tokensRead = [];

    /**
     * Whether the pattern is read by PCRE2 10.43 or newer, where "{,2}" and
     * counts padded with spaces, "{ 2 }", repeat. Before, those braces are
     * literal text.
     */
    private readonly bool $wideRepeatCounts;

    /**
     * Whether errors are reported past the character at fault, as PCRE2
     * 10.47 does, rather than on it.
     */
    private readonly bool $reportsPastTheFault;

    /**
     * Whether "(*scs:" and "(*scan_substring:" are read.
     */
    private readonly bool $readsScanSubstring;

    /**
     * Whether the Perl extended class "(?[...])" is read.
     */
    private readonly bool $readsExtendedClass;

    /**
     * Whether a class opened by "\E" or "\Q\E" alone, at the end of the
     * pattern, is left open; before, PCRE meets a trailing backslash there.
     */
    private readonly bool $emptyQuoteOpeningClassIsUnclosed;

    /**
     * @param PcreTarget|null $target the PHP and PCRE2 judged; the running ones when null
     */
    public function __construct(?PcreTarget $target = null)
    {
        $target ??= PcreTarget::runtime();
        $this->wideRepeatCounts = $target->supports(PcreFeature::OpenAndPaddedRepeatCounts);
        $this->reportsPastTheFault = $target->supports(PcreFeature::ErrorOffsetPastTheFault);
        $this->readsScanSubstring = $target->supports(PcreFeature::ScanSubstring);
        $this->readsExtendedClass = $target->supports(PcreFeature::ExtendedCharClass);
        $this->emptyQuoteOpeningClassIsUnclosed = $target->supports(PcreFeature::EmptyQuoteOpeningClassIsUnclosed);
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
     * @param bool $extendedMore whether the text is read as under "(?xx)", as the
     *                           classes of an extended class are
     */
    public function tokenize(string $pattern, string $flags = '', bool $extendedMore = false): TokenStream
    {
        $this->wholePattern = $pattern;
        $this->offset = 0;
        $this->bodyEnds = [];
        $this->bodiesInPlace = false;

        return $this->read($pattern, $flags, $extendedMore);
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
        $this->offset = 0;
        $this->bodyEnds = [];
        $this->bodiesInPlace = true;

        return $this->read($pattern, $flags, false);
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
            $this->bodyEnds = [];
        }

        $this->offset = $offset;
        $this->bodiesInPlace = false;

        return $this->read(substr($pattern, $offset, $length), $flags, $extendedMore);
    }

    private function read(string $pattern, string $flags, bool $extendedMore): TokenStream
    {
        // Patterns that are not valid UTF-8 are tokenized byte by byte, the
        // way PCRE compiles them outside UTF mode. In UTF mode, set by /u or
        // by "(*UTF)" among the settings that open the pattern, PCRE itself
        // refuses them, at the first byte that starts no UTF-8 character.
        $this->byteMode = !LibraryPcre::match('//u', $pattern);
        $this->utf = str_contains($flags, 'u')
            || 1 === LibraryPcre::match('/\A(?:\(\*[A-Z_]++(?:=\d++)?\))*?\(\*UTF8?\)/', $pattern);
        if ($this->byteMode && $this->utf) {
            throw LexerException::withContext('Input string is not valid UTF-8.', ErrorCode::EncodingInvalidUtf8, self::firstInvalidUtf8Offset($pattern), $pattern);
        }

        $this->pattern = $pattern;
        $this->length = \strlen($this->pattern);
        $this->extendedMode = str_contains($flags, 'x');
        $this->extendedMoreMode = $extendedMore;
        $this->newline = StartOptions::newline($this->wholePattern);
        $this->newlineUtf = $this->utf || StartOptions::turnUtfOn($this->wholePattern);
        $this->nextFound = [];
        $this->resetState();

        /** @var list<Token> $tokens */
        $tokens = [];

        try {
            // One window for every regex of the run: the limits are read
            // once, not once per token.
            LibraryPcre::run(function () use (&$tokens): void {
                while ($this->position < $this->length) {
                    if ($this->handleTunnelModes($tokens)) {
                        continue;
                    }

                    [$regex, $tokenMap] = $this->getCurrentContext();
                    [$matchedValue, $startPos, $matches] = $this->matchAtPosition($regex);
                    $tokens[] = $this->createToken($tokenMap, $matches, $matchedValue, $startPos, $tokens);
                }

                $this->validateFinalState();
            });
        } finally {
            $this->tokensRead = $tokens;
        }

        $tokens[] = new Token(TokenType::Eof, '', $this->position);

        return new TokenStream($tokens, $pattern);
    }

    private function getRegexOutside(): string
    {
        // The token patterns are constants and the compiled regex only varies
        // with the byte mode, so that is the whole key: keying it on the PHP
        // version as well compiled the same regexes once per version.
        $key = $this->byteMode ? 1 : 0;

        if (!isset(self::$regexOutside[$key])) {
            StaticCaches::register(self::class, self::clearCaches(...));
            self::$regexOutside[$key] = $this->compilePattern(self::PATTERNS_OUTSIDE);
        }

        return self::$regexOutside[$key];
    }

    /**
     * One item of the body of an alphabetic assertion, varying with the byte
     * mode and with "x", under which "#" starts a comment in the body too.
     */
    private function getRegexBodyItem(bool $extended): string
    {
        $key = $this->bodyMode($extended);

        if (!isset(self::$regexBodyItem[$key])) {
            $item = $extended
                ? str_replace(self::GROUP_BODY_TEXT, self::GROUP_BODY_TEXT_EXTENDED, self::GROUP_BODY_ITEM)
                : self::GROUP_BODY_ITEM;

            StaticCaches::register(self::class, self::clearCaches(...));
            self::$regexBodyItem[$key] = '/(?:'.$item.')/xsA'.($this->byteMode ? '' : 'u');
        }

        return self::$regexBodyItem[$key];
    }

    /**
     * The reading mode of a body: the byte mode, and whether "x" and "xx"
     * are in force where the body opens.
     */
    private function bodyMode(bool $extended, bool $extendedMore = false): int
    {
        return ($this->byteMode ? 1 : 0) + ($extended ? 2 : 0) + ($extendedMore ? 4 : 0);
    }

    private function getRegexInside(): string
    {
        $key = $this->byteMode ? 1 : 0;

        if (!isset(self::$regexInside[$key])) {
            StaticCaches::register(self::class, self::clearCaches(...));
            self::$regexInside[$key] = $this->compilePattern(self::PATTERNS_INSIDE);
        }

        return self::$regexInside[$key];
    }

    /**
     * Two regexes outside a class, two inside and four for the body of an
     * alphabetic assertion at most, one per mode.
     */
    private static function clearCaches(): void
    {
        self::$regexOutside = [];
        self::$regexInside = [];
        self::$regexBodyItem = [];
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
        $this->charClassStartPositions = [];
        $this->quotedRun = null;
        $this->unclosedBody = null;
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
        if ($this->readsExtendedClass && !$this->inCharClass && '(?[' === substr($this->pattern, $this->position, 3)) {
            $tokens[] = $this->consumeExtendedClass();

            return true;
        }

        if (!$this->inCharClass && '(' === $this->pattern[$this->position] && $this->readGroupOpener($tokens)) {
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

        // "\p{" that no "}" closes is the escape "\p" then text, as the token
        // regex reads it, without searching the rest of the text for a "}"
        // at each one.
        if ($this->opensUnclosedProperty($this->position)) {
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
        if (null === $opener || $this->follows(')', $this->position + \strlen($opener) + 1)) {
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
        $inPlace = $this->readsBodyInPlace($opener, $tokens);
        $end = $this->bodiesInPlace && $inPlace ? null : $this->alphabeticAssertionEnd($opener);
        $start = $this->position;
        if (null !== $end) {
            $text = substr($this->pattern, $start, $end - $start);
            $this->position = $end;
            $tokens[] = $this->createToken(['T_PCRE_VERB'], ['T_PCRE_VERB' => $text], $text, $start, $tokens);

            return true;
        }

        if (!$inPlace) {
            return false;
        }

        if (!$this->bodiesInPlace) {
            $this->unclosedBody ??= $opener;
        }

        // The opener opens a group: an option set in the body holds up to
        // its ")".
        $this->extendedModeStack[] = [$this->extendedMode, $this->extendedMoreMode];
        $this->position += \strlen($opener);
        $tokens[] = new Token(TokenType::PcreVerb, substr($opener, 2), $start, \strlen($opener));

        return true;
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
            && (PcreVerb::isLookaround($name) || !$this->opensCondition($tokens, $this->position));
    }

    /**
     * Where the alphabetic assertion or script run opened at the cursor by
     * $opener, "(*name:" or "(?*", ends past its ")"; null when its body
     * never closes.
     *
     * The body is read as any group body is: a ")" escaped, quoted by
     * \Q...\E, inside a class or inside a "(?#...)" comment does not close
     * it, and its own groups nest as deep as they like. Each item is the
     * first that matches at its place and is never read again; a "(" that
     * opens no item opens a group, and the first character that neither
     * starts an item nor opens or closes a group ends the body unclosed.
     *
     * An option setting in the body holds there as anywhere else: "x" set
     * by "(?x)" or "(?xx)", cleared by "(?-x)" or "(?^)", holds to the end
     * of the group it stands in, later alternatives included, and one set
     * by "(?x:" only inside that group; a group's ")" gives back the mode
     * its "(" was read in. Under "x" a "#" starts a comment that runs to
     * the end of the line, a ")" in it included. Under "xx" the spaces and
     * tabs before the first member of a class are skipped too.
     */
    private function alphabeticAssertionEnd(string $opener): ?int
    {
        // Each reading records where the groups it meets close, or that they
        // never do, under the mode each "(" is read in: past the opener of a
        // body, the reading from the "(" of that body goes on item for item
        // as the reading of the body itself does, from the same mode. A body
        // met again at the same place in the same mode is not read again:
        // the end found holds in a text that still holds its ")" and ends no
        // later than the text it was found in; "never closes" holds in a
        // text that ends at the same place.
        $extended = $this->extendedMode;
        $extendedMore = $this->extendedMoreMode;
        $textEnd = $this->offset + $this->length;
        $known = $this->bodyEnds[$this->bodyMode($extended, $extendedMore)][$this->offset + $this->position] ?? null;
        if (null !== $known && (null === $known[0] ? $known[1] === $textEnd : $known[0] <= $textEnd && $textEnd <= $known[1])) {
            return null === $known[0] ? null : $known[0] - $this->offset;
        }

        $at = $this->position + \strlen($opener);
        // Each group open: where its "(" stands, and whether "x" and "xx"
        // held there.
        $opened = [[$this->position, $extended, $extendedMore]];
        // No ")" left, no body closes: the run of openers after it is not
        // read again for each one.
        if (!$this->follows(')', $at)) {
            return $this->unclosedBodies($opened);
        }

        while ($at < $this->length) {
            if ('[' === $this->pattern[$at]) {
                $at = $this->bodyClassEnd($at, $extendedMore);
                if (null === $at) {
                    return $this->unclosedBodies($opened);
                }

                continue;
            }

            // Under "x" a "#" comment runs to the newline the pattern sets.
            if ($extended && '#' === $this->pattern[$at]) {
                $newline = $this->nextNewline($at + 1);
                $at = null === $newline ? $this->length : $newline[0] + $newline[1];
                while ($at < $this->length && self::isContinuationByte($this->pattern[$at])) {
                    $at++;
                }

                continue;
            }

            if ($this->opensUnclosedProperty($at)) {
                $at += 2;

                continue;
            }

            $result = LibraryPcre::match($this->getRegexBodyItem($extended), $this->pattern, $read, 0, $at);
            if (false === $result) {
                throw LexerException::withContext(
                    \sprintf('PCRE Error during tokenization: %s', (string) preg_last_error_msg()),
                    ErrorCode::InternalPcreFailure,
                    $at,
                    $this->pattern,
                );
            }

            if (1 === $result) {
                $at += \strlen($read[0]);

                continue;
            }

            $char = $this->pattern[$at];
            // "(?[...])" is read as the lexer reads it, a "#" in it no comment.
            if ('(' === $char && $this->readsExtendedClass && '(?[' === substr($this->pattern, $at, 3)) {
                $at = $this->extendedClassEnd($at);

                continue;
            }

            if ('(' === $char && '(?#' !== substr($this->pattern, $at, 3)) {
                // "(?x)" changes the mode of the group it stands in, "(?x:"
                // opens a group read in the mode it sets.
                $setting = '?' === ($this->pattern[$at + 1] ?? '') ? $this->readInlineFlags($at + 2) : null;
                if (null === $setting || $setting[1]) {
                    $opened[] = [$at, $extended, $extendedMore];
                }

                if (null !== $setting) {
                    $extended = $setting[0]->inForce('x', $extended);
                    $extendedMore = $setting[0]->extendedMoreInForce($extendedMore);
                    $at = $setting[2];

                    continue;
                }
            } elseif (')' !== $char) {
                return $this->unclosedBodies($opened);
            } else {
                [$open, $extended, $extendedMore] = array_pop($opened) ?? [$this->position, $extended, $extendedMore];
                $this->bodyEnds[$this->bodyMode($extended, $extendedMore)][$this->offset + $open] = [$this->offset + $at + 1, $textEnd];
                if ([] === $opened) {
                    return $at + 1;
                }
            }

            $at++;
        }

        return $this->unclosedBodies($opened);
    }

    /**
     * Record that none of the groups still open when the reading of a body
     * stopped closes in the text read, each under the mode its "(" was read
     * in; null.
     *
     * @param list<array{0: int, 1: bool, 2: bool}> $opened
     */
    private function unclosedBodies(array $opened): null
    {
        foreach ($opened as [$open, $extended, $extendedMore]) {
            $this->bodyEnds[$this->bodyMode($extended, $extendedMore)][$this->offset + $open] = [null, $this->offset + $this->length];
        }

        return null;
    }

    /**
     * Where the class opened at $open in the body of an alphabetic assertion
     * ends, past its "]"; null when it never closes.
     *
     * A "]" first in the class is a member, as PCRE reads it: PCRE skips
     * "\E", an empty "\Q\E" and one "^", in any order, before the first
     * member, so "[]" alone never closes, and the class runs to the end of
     * the text, and "[\E]a]" holds "]" and "a". A member is a POSIX class
     * "[:alpha:]", a quoted run, an escape ("\c" with the character it
     * takes, "\p{...}" to its "}"), or any character but "]" and the
     * backslash. Under "xx" PCRE skips spaces and tabs there too, so
     * "[ ]a]" holds "]" and "a".
     */
    private function bodyClassEnd(int $open, bool $extendedMore): ?int
    {
        $first = $open + 1;
        $negated = false;
        while (true) {
            if ($extendedMore && \in_array($this->pattern[$first] ?? '', [' ', "\t"], true)) {
                $first++;
            } elseif ('\\E' === substr($this->pattern, $first, 2)) {
                $first += 2;
            } elseif ('\\Q\\E' === substr($this->pattern, $first, 4)) {
                $first += 4;
            } elseif (!$negated && '^' === ($this->pattern[$first] ?? '')) {
                $negated = true;
                $first++;
            } else {
                break;
            }
        }

        $first += ']' === ($this->pattern[$first] ?? '') ? 1 : 0;
        $end = $this->bodyClassMembersEnd($first);

        return ']' === ($this->pattern[$end] ?? '') ? $end + 1 : null;
    }

    /**
     * Where the members of a class in the body of an alphabetic assertion,
     * read from $at, stop: at a "]" that closes the class, at a backslash
     * that ends the text read, or at the end. Each member is the first that
     * matches at its place.
     *
     * A POSIX class is "[:", an optional "^", letters and ":]", as the lexer
     * reads it in any class; any other "[:" is a member of one character.
     * In "[[:[:]]" the first "]" closes the class, as it does for PCRE, and
     * so it does in "[[:!:]]" and "[[::]]", names PCRE refuses: the class
     * ends where the lexer ends it.
     */
    private function bodyClassMembersEnd(int $at): int
    {
        while ($at < $this->length) {
            $char = $this->pattern[$at];
            if (']' === $char) {
                break;
            }

            if ('[' === $char && ':' === ($this->pattern[$at + 1] ?? '')) {
                $name = $at + 2 + ('^' === ($this->pattern[$at + 2] ?? '') ? 1 : 0);
                $letters = strspn($this->pattern, self::POSIX_NAME_LETTERS, $name);
                if ($letters > 0 && ':]' === substr($this->pattern, $name + $letters, 2)) {
                    $at = $name + $letters + 2;

                    continue;
                }
            }

            if ('\\' !== $char) {
                $at++;

                continue;
            }

            if ($at + 1 >= $this->length) {
                break;
            }

            if ('Q' === $this->pattern[$at + 1]) {
                $quoteEnd = strpos($this->pattern, '\\E', $at + 2);
                $at = false === $quoteEnd ? $this->length : $quoteEnd + 2;

                continue;
            }

            // "\p{...}" runs to its "}", a "]" in it included, as the lexer
            // reads it in a class.
            if (!$this->opensUnclosedProperty($at) && 1 === LibraryPcre::match('/\G\\\\[pP]\{[^}]++\}/', $this->pattern, $property, 0, $at)) {
                $at += \strlen($property[0]);

                continue;
            }

            // "\c" takes the character after it, a "]" too; a byte at a time
            // is enough, no character but its first byte can be "]" or "\".
            $at += 'c' === $this->pattern[$at + 1] && $at + 2 < $this->length ? 3 : 2;
        }

        return $at;
    }

    /**
     * "(?[" to the "]" that closes it at its own level, and the ")" after it;
     * to the end of the pattern when none does. The parser judges the text.
     */
    private function consumeExtendedClass(): Token
    {
        $start = $this->position;
        $this->position = $this->extendedClassEnd($start);

        return new Token(TokenType::ExtendedClass, substr($this->pattern, $start, $this->position - $start), $start);
    }

    /**
     * Where the "(?[" at $start ends: past the "]" that closes it at its own
     * level and the ")" after it, at a ")" at its own level, or at the end
     * of the text.
     */
    private function extendedClassEnd(int $start): int
    {
        $at = $start + 3;
        $depth = 0;
        while ($at < $this->length) {
            $char = $this->pattern[$at];
            if ('\\' === $char) {
                $quoteEnd = 'Q' === ($this->pattern[$at + 1] ?? '') ? strpos($this->pattern, '\\E', $at + 2) : null;
                $at = null === $quoteEnd ? $at + ExtendedClassReader::escapeLength($this->pattern, $at, $this->utf) : (false === $quoteEnd ? $this->length : $quoteEnd + 2);

                continue;
            }

            if ('[' === $char) {
                $at = ExtendedClassReader::endOfClass($this->pattern, $at);

                continue;
            }

            if (')' === $char && 0 === $depth) {
                break;
            }

            if (']' === $char && 0 === $depth) {
                $at += ')' === ($this->pattern[$at + 1] ?? '') ? 2 : 1;

                break;
            }

            $depth += '(' === $char ? 1 : (')' === $char ? -1 : 0);
            $at++;
        }

        return min($at, $this->length);
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

        $newline = $this->nextNewline($this->position);
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
        while ($this->position < $this->length && self::isContinuationByte($this->pattern[$this->position])) {
            $tokens[] = new Token(TokenType::Literal, $this->pattern[$this->position], $this->position);
            $this->position++;
        }
    }

    /**
     * Where the first newline at or after $from starts in the text read, and
     * its length in bytes, under the newline convention the leading options
     * of the pattern set; null when none follows.
     *
     * "(*LF)", the default, ends a line at "\n", "(*CR)" at "\r", "(*NUL)"
     * at NUL, "(*CRLF)" at "\r\n" only, "(*ANYCRLF)" at any of "\r", "\n"
     * and "\r\n", and "(*ANY)" at any of those, "\v", "\f" and NEL, which
     * is the byte 0x85 outside UTF mode, and in UTF mode the character
     * U+0085 and U+2028 and U+2029 too.
     *
     * @return array{0: int, 1: int}|null
     */
    private function nextNewline(int $from): ?array
    {
        $stops = match ($this->newline) {
            'CR', 'CRLF' => "\r",
            'NUL' => "\0",
            'ANYCRLF' => "\r\n",
            'ANY' => $this->newlineUtf ? "\n\v\f\r\xC2\xE2" : "\n\v\f\r\x85",
            default => "\n",
        };

        for ($at = $from + strcspn($this->pattern, $stops, $from); $at < $this->length; $at += 1 + strcspn($this->pattern, $stops, $at + 1)) {
            $length = $this->newlineLength($at);
            if ($length > 0) {
                return [$at, $length];
            }
        }

        return null;
    }

    /**
     * The length of the newline at $at, which holds one of the bytes a
     * newline of the convention in force starts with; 0 when none starts
     * there.
     */
    private function newlineLength(int $at): int
    {
        $text = substr($this->pattern, $at, 3);
        if (str_starts_with($text, "\r\n") && \in_array($this->newline, ['CRLF', 'ANYCRLF', 'ANY'], true)) {
            return 2;
        }

        if ('CRLF' === $this->newline) {
            return 0;
        }

        return match ($text[0]) {
            "\xC2" => str_starts_with($text, "\xC2\x85") ? 2 : 0,
            "\xE2" => "\xE2\x80\xA8" === $text || "\xE2\x80\xA9" === $text ? 3 : 0,
            default => 1,
        };
    }

    /**
     * The length in bytes of the character at $at: one byte in byte mode;
     * else, the text being valid UTF-8, its first byte and the bytes that
     * continue it.
     */
    private function characterLength(int $at): int
    {
        $end = $at + 1;
        while (!$this->byteMode && $end < $this->length && self::isContinuationByte($this->pattern[$end])) {
            $end++;
        }

        return $end - $at;
    }

    /**
     * Whether the byte is one that continues a UTF-8 character.
     */
    private static function isContinuationByte(string $byte): bool
    {
        return 0x80 === (\ord($byte) & 0xC0);
    }

    /**
     * Whether $byte stands at or after $from in the text read. What the last
     * search for it found is kept: a search from between its start and the
     * byte it found, or from past its start when it found none, is not run
     * again.
     */
    private function follows(string $byte, int $from): bool
    {
        $found = $this->nextFound[$byte] ?? null;
        if (null === $found || $from < $found[0] || (false !== $found[1] && $from > $found[1])) {
            $found = $this->nextFound[$byte] = [$from, strpos($this->pattern, $byte, $from)];
        }

        return false !== $found[1];
    }

    /**
     * Whether a "\p{" or "\P{" that no "}" closes stands at $at: the escape
     * "\p" or "\P" then, and "{" the text after it.
     */
    private function opensUnclosedProperty(int $at): bool
    {
        return '\\' === $this->pattern[$at]
            && \in_array($this->pattern[$at + 1] ?? '', ['p', 'P'], true)
            && '{' === ($this->pattern[$at + 2] ?? '')
            && !$this->follows('}', $at + 3);
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
        $result = LibraryPcre::match($regex, $this->pattern, $matches, \PREG_UNMATCHED_AS_NULL, $this->position);

        if (false === $result) {
            throw LexerException::withContext(
                \sprintf('PCRE Error during tokenization: %s', (string) preg_last_error_msg()),
                ErrorCode::InternalPcreFailure,
                $this->position,
                $this->pattern,
            );
        }

        if (0 === $result) {
            $context = substr($this->pattern, $this->position, self::ERROR_CONTEXT_LENGTH);

            // Every character starts a token but a backslash with nothing
            // after it, which PCRE reports where the pattern ends.
            $trailing = '\\' === $context;
            $position = $trailing ? $this->length : $this->position;

            throw LexerException::withContext(
                \sprintf('Unable to tokenize pattern at position %d. Context: "%s..."', $position, $context),
                $trailing ? ErrorCode::EscapeTrailingBackslash : ErrorCode::InternalUnexpectedState,
                $position,
                $this->pattern,
            );
        }

        // Unreachable: a successful match always holds its whole-match string.
        if (!isset($matches[0]) || !\is_string($matches[0])) {
            throw LexerException::withContext(
                'Lexer internal error: Missing matched token.',
                ErrorCode::InternalUnexpectedState,
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
     * @param list<Token>              $currentTokens
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

            if (TokenType::LiteralEscaped === $type) {
                // "\c" only falls through to an escaped literal when no
                // printable ASCII character follows it; PCRE rejects that.
                if ('\\c' === $matchedValue) {
                    throw LexerException::withContext(
                        '\\c must be followed by a printable ASCII character.',
                        ErrorCode::ControlCharInvalid,
                        $this->reportsPastTheFault ? $this->afterCharacter($startPos + 2) : $startPos + 2,
                        $this->pattern,
                    );
                }

                // "\x{}" (empty braces) is a PCRE compile error, where the
                // digits should be.
                if ('\\x' === $matchedValue && '{}' === substr($this->pattern, $startPos + 2, 2)) {
                    throw LexerException::withContext(
                        'Invalid hex escape "\\x{}": at least one hexadecimal digit is required.',
                        ErrorCode::EscapeDigitsMissing,
                        $startPos + 3,
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
            ErrorCode::InternalUnexpectedState,
            $startPos,
            $this->pattern,
        );
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

        // "(*name:" read as a plain "(": its body never closes and is not
        // read in place. A name PCRE does not know is refused where it ends.
        if (TokenType::GroupOpen === $type && 1 === LibraryPcre::match('/\G\(\*([a-z_]++):/', $this->pattern, $opener, 0, $startPos)) {
            // "(?(*atomic:" is no condition: PCRE takes a lookaround there,
            // and refuses any other name it knows at the colon.
            $known = PcreVerb::takesArgument($opener[1]) || ($this->readsScanSubstring && \in_array($opener[1], ['scs', 'scan_substring'], true));
            if ($known && !PcreVerb::isLookaround($opener[1]) && $this->opensCondition($currentTokens, $startPos)) {
                $colon = $startPos + 2 + \strlen($opener[1]);

                throw LexerException::withContext(
                    \sprintf('Invalid conditional condition at position %d: a lookaround assertion is expected after "(?(".', $colon),
                    ErrorCode::ConditionAssertionExpected,
                    $colon,
                    $this->pattern,
                );
            }

            // "(*scs:(1)...": PCRE reads the list of groups first.
            if ($this->readsScanSubstring && \in_array($opener[1], ['scs', 'scan_substring'], true)) {
                $listOpen = $startPos + \strlen($opener[0]);
                [$offset, $code, $message] = PcreVerb::groupListFault(
                    $this->pattern,
                    $listOpen,
                    $this->reportsPastTheFault,
                    $this->utf,
                ) ?? [$this->length, ErrorCode::GroupUnclosed, \sprintf('Missing closing parenthesis for "(*%s:".', $opener[1])];

                throw LexerException::withContext($message, $code, $offset, $this->pattern);
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

            // A name PCRE knows opens a body read in place.
            throw LexerException::withContext(
                \sprintf('Unknown alphabetic assertion "(*%s:".', $opener[1]),
                ErrorCode::VerbInvalid,
                $startPos + 2 + \strlen($opener[1]),
                $this->pattern,
            );
        }

        // Before PCRE2 10.43, "{,2}" and "{ 2 }" are text: only the "{" is
        // read here, and what follows it is read again as text. A "\N" right
        // before keeps its count, which the validator refuses there.
        if (TokenType::Quantifier === $type && !$this->wideRepeatCounts && '{' === $matchedValue[0]
            && 1 !== LibraryPcre::match('/^\{\d++(?:,\d*+)?\}/', $matchedValue)
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

        $inlineFlags = $this->readInlineFlags($this->position);
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

    /**
     * Read the modifiers that follow the "(?" ending at $at, if the group
     * carries any at all.
     *
     * @return array{0: InlineFlags, 1: bool, 2: int} the modifiers, whether
     *                                                the group is scoped with
     *                                                ':', and the offset past
     *                                                that ':' or ')'
     */
    private function readInlineFlags(int $at): ?array
    {
        $matches = [];
        if (!LibraryPcre::match('/\G(\^?[a-zA-Z]*(?:-[a-zA-Z]+)?)([:)])/A', $this->pattern, $matches, 0, $at)) {
            return null;
        }

        $flags = InlineFlags::read(InlineFlags::withoutAsciiOptions($matches[1]), self::INLINE_FLAG_LETTERS);

        return null === $flags ? null : [$flags, ':' === $matches[2], $at + \strlen($matches[0])];
    }

    private function handleCharClassOpen(int $startPos): Token
    {
        // PHP compiles without PCRE2's extended class syntax, so a "[" inside
        // a class is a member: nothing nests.
        if ($this->inCharClass) {
            return new Token(TokenType::Literal, '[', $startPos);
        }

        return $this->openCharClass($startPos);
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
            if (!LibraryPcre::match($this->anchored('(.*?)((\\\\E|\z))', 's'), $this->pattern, $matches, \PREG_UNMATCHED_AS_NULL, $this->position)) {
                // Nothing here can fail to match, so a failure means PCRE
                // itself gave up. Leaving quote mode and skipping to the end
                // would drop the rest of the pattern without a word.
                throw LexerException::withContext(
                    \sprintf('PCRE Error while reading a quoted run: %s', (string) preg_last_error_msg()),
                    ErrorCode::InternalPcreFailure,
                    $this->position,
                    $this->pattern,
                );
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
        if (!LibraryPcre::match($this->anchored('([^)]*)(\)|$)'), $this->pattern, $matches, \PREG_UNMATCHED_AS_NULL, $this->position)) {
            throw LexerException::withContext(
                \sprintf('PCRE Error while reading a comment: %s', (string) preg_last_error_msg()),
                ErrorCode::InternalPcreFailure,
                $this->position,
                $this->pattern,
            );
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
            TokenType::LiteralEscaped => $this->extractEscapedLiteralValue($matchedValue),
            TokenType::PcreVerb => substr($matchedValue, self::OFFSET_VERB_START, self::OFFSET_VERB_END),
            TokenType::Callout => substr($matchedValue, self::OFFSET_CALLOUT_START, self::OFFSET_CALLOUT_END),
            TokenType::Assertion, TokenType::CharType, TokenType::Keep => substr($matchedValue, self::OFFSET_BACKSLASH),
            TokenType::Backref => $matchedValue,
            TokenType::OctalLegacy => substr($matchedValue, self::OFFSET_BACKSLASH),
            /* @phpstan-ignore cast.string */
            TokenType::PosixClass => (string) ($matches['v_posix'] ?? ''),
            TokenType::Unicode => $this->parseUnicodeEscape($matchedValue),
            TokenType::UnicodeProp => $this->normalizeUnicodeProp($matchedValue),
            TokenType::UnicodeNamed => substr($matchedValue, self::OFFSET_UNICODE_NAMED_START, self::OFFSET_UNICODE_NAMED_END),
            TokenType::ControlChar => substr($matchedValue, self::OFFSET_CONTROL_CHAR),
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

        // PCRE2 skips the spaces before the "^": "\P{ ^L}" is "\p{L}".
        $isPropNegated = str_starts_with(ltrim($prop, " \t\n\v\f\r"), '^');
        if ($isPropNegated) {
            $prop = substr(ltrim($prop, " \t\n\v\f\r"), self::PATTERN_UNICODE_PROP_NEGATION_START);
        }

        if ('' === $prop) {
            return '';
        }

        $negated = $isNegated !== $isPropNegated;

        $normalized = $negated ? '^'.$prop : $prop;

        return $hasBraces ? '{'.$normalized.'}' : $normalized;
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

        if ($this->utf && 1 === LibraryPcre::match('/\G./su', $this->pattern, $matches, 0, $position)) {
            return $position + \strlen($matches[0]);
        }

        return $position + 1;
    }

    /**
     * Whether what starts at $position is where the condition of "(?(" is
     * due, the assertion, after the callout "(?(?C1)" may run first.
     *
     * The tokens are read from the end, not copied: a body opens at each
     * token of a long pattern.
     *
     * @param list<Token> $tokens the tokens read before $position
     */
    private function opensCondition(array $tokens, int $position): bool
    {
        $last = array_key_last($tokens);
        $previous = null === $last ? null : $tokens[$last];
        if (null !== $previous && TokenType::Callout === $previous->type && $previous->end() === $position) {
            $position = $previous->position;
            $previous = $tokens[$last - 1] ?? null;
        }

        return null !== $previous && TokenType::GroupModifierOpen === $previous->type && $previous->end() === $position;
    }

    /**
     * Whether the class at $position is the "[" of "(?[", a Perl extended
     * class PCRE2 only reads from 10.45; before, PCRE2 refuses that "[",
     * whatever follows it.
     */
    private function opensExtendedClass(int $position): bool
    {
        if ($position < 2 || '(?' !== substr($this->pattern, $position - 2, 2)) {
            return false;
        }

        // An escaped "(" opens nothing: "\(?[a" is an unclosed class.
        $before = substr($this->pattern, 0, $position - 2);

        return 0 === (\strlen($before) - \strlen(rtrim($before, '\\'))) % 2;
    }

    private function validateFinalState(): void
    {
        if ([] !== $this->charClassStartPositions) {
            $classStart = $this->charClassStartPositions[0];

            // Before PCRE2 10.45, "(?[" is no extended class: PCRE refuses
            // the "[" after "(?", whatever follows it.
            if (!$this->readsExtendedClass && $this->opensExtendedClass($classStart)) {
                throw LexerException::withContext(
                    \sprintf('Invalid group modifier syntax at position %d', $classStart),
                    ErrorCode::GroupSyntax,
                    $classStart,
                    $this->pattern,
                );
            }

            // Before PCRE2 10.45, "[\E", "[^\Q\E" and the like run into the
            // end of the pattern as a backslash: PCRE skips the empty quotes
            // that open a class, and under "(?xx)" its spaces and tabs, and
            // meets nothing after the last quote.
            $skipped = $this->extendedMoreMode ? '(?:\\\\(?:Q\\\\)?E|[ \t])' : '(?:\\\\(?:Q\\\\)?E)';
            if (!$this->emptyQuoteOpeningClassIsUnclosed
                && 1 === LibraryPcre::match('/\G'.$skipped.'*+(?:\^'.$skipped.'*+)?(?<=\\\\E)\z/', $this->pattern, $quotes, 0, $classStart + 1)) {
                throw LexerException::withContext(
                    \sprintf('A backslash ends the pattern at position %d.', $this->length),
                    ErrorCode::EscapeTrailingBackslash,
                    $this->length,
                    $this->pattern,
                );
            }

            throw LexerException::withContext(
                'Unclosed character class "]" at end of input.',
                ErrorCode::CharclassUnclosed,
                $this->opensExtendedClass($classStart) ? $classStart : $this->position,
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

    /**
     * Where the longest run of well-formed UTF-8 at the start of $text ends.
     * Overlong forms, surrogates and code points past U+10FFFF are no UTF-8.
     */
    private static function firstInvalidUtf8Offset(string $text): int
    {
        LibraryPcre::match(
            '/\A(?:[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
            .'|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})*+/',
            $text,
            $valid,
        );

        return \strlen($valid[0] ?? '');
    }
}
