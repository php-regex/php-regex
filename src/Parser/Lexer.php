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

use PHPRegex\Parser\Cache\StaticCaches;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Syntax\BadEscape;
use PHPRegex\Parser\Syntax\CodePointReader;
use PHPRegex\Parser\Syntax\ExtendedClassReader;
use PHPRegex\Parser\Syntax\InlineFlags;
use PHPRegex\Parser\Syntax\PcreVerb;
use PHPRegex\Parser\Syntax\PosixItem;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Text\Ascii;
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
    /**
     * The largest number a backslash and its digits may make before PCRE2
     * 10.37, which reads one past it as an octal escape and text.
     */
    private const LARGEST_NUMBER_BEFORE_PCRE_10_37 = 214_748_363;

    /**
     * The longest name PCRE2 10.32 reads, a verb's or a group's.
     */
    private const NAME_LENGTH_BEFORE_PCRE_10_33 = 32;

    /**
     * The longest name PCRE2 reads, a verb's or a group's: 32 code units up
     * to 10.43, 128 from 10.44.
     */
    private const NAME_LENGTH_BEFORE_PCRE_10_44 = 32;

    private const NAME_LENGTH = 128;

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

    /**
     * Where the POSIX item read whole, character by character, ends: its
     * "]" when it opens a class, past it in a class.
     */
    private ?int $classTextEnd = null;

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
     * Whether "(*pla:" and the other alphabetic names of the assertions
     * are read; before, they are verbs PCRE does not know.
     */
    private readonly bool $readsAlphabeticAssertions;

    /**
     * Whether a name is read whole before it is judged; before, a verb name
     * is a run of letters, and a name is refused at the character that
     * takes it past 32.
     */
    private readonly bool $namesReadWhole;

    /**
     * Whether "(*sr:" and the other names of a script run are read.
     */
    private readonly bool $readsScriptRuns;

    /**
     * Whether "(*napla:" and the other names of the non-atomic lookarounds
     * are read.
     */
    private readonly bool $readsNonAtomicAssertions;

    /**
     * Whether "(?*" spells a non-atomic lookahead; before, the "*" follows
     * "(?" where no option letter may.
     */
    private readonly bool $readsNonAtomicShortSyntax;

    /**
     * Whether a backslash and digits past 214748363 is an octal escape and
     * text; before, the number is refused at the digit that takes it there.
     */
    private readonly bool $overflowingNumberIsOctal;

    /**
     * The PHP judged, and whether its PCRE2 is 10.32, which reads more
     * escapes as literals in a class than the later releases.
     */
    private readonly int $phpVersionId;

    private readonly bool $pcre2Ten32;

    /**
     * The PHP and PCRE2 judged, which say what a bad escape is.
     */
    private readonly PcreTarget $target;

    /**
     * Whether the pattern read is compiled with
     * PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL: PHP 7.3 and 7.4 without "X". An
     * escape PCRE2 refuses as it reads it is then the character after the
     * backslash.
     */
    private bool $badEscapeIsLiteral = false;

    /**
     * Where each escape read as its character stands.
     *
     * @var array<int, true>
     */
    private array $literalEscapes = [];

    /**
     * @param PcreTarget|null $target the PHP and PCRE2 judged; the running ones when null
     */
    public function __construct(?PcreTarget $target = null)
    {
        $target ??= PcreTarget::runtime();
        $this->readsAlphabeticAssertions = $target->supports(PcreFeature::AlphabeticAssertionNames);
        $this->namesReadWhole = $target->supports(PcreFeature::NamesReadWhole);
        $this->readsScriptRuns = $target->supports(PcreFeature::ScriptRuns);
        $this->readsNonAtomicAssertions = $target->supports(PcreFeature::NonAtomicAssertionNames);
        $this->readsNonAtomicShortSyntax = $target->supports(PcreFeature::NonAtomicAssertionShortSyntax);
        $this->overflowingNumberIsOctal = $target->supports(PcreFeature::OverflowingBackslashNumberIsOctal);
        $this->phpVersionId = $target->phpVersionId;
        $this->target = $target;
        $this->pcre2Ten32 = !$target->supports(PcreFeature::BadEscapeInClassStaysRefused);
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
            || StartOptions::turnUtfOn($pattern);
        if ($this->byteMode && $this->utf) {
            throw LexerException::withContext('Input string is not valid UTF-8.', ErrorCode::EncodingInvalidUtf8, self::firstInvalidUtf8Offset($pattern), $pattern);
        }

        $this->pattern = $pattern;
        $this->length = \strlen($this->pattern);
        $this->extendedMode = str_contains($flags, 'x');
        $this->badEscapeIsLiteral = PhpVersionGates::badEscapeIsLiteral($this->phpVersionId, $flags);
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
        $length = $ord >= 0xF0 ? 4 : ($ord >= 0xE0 ? 3 : 2);
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
        $this->classTextEnd = null;
        $this->charClassStartPositions = [];
        $this->quotedRun = null;
        $this->unclosedBody = null;
        $this->literalEscapes = [];
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
        if ($this->badEscapeIsLiteral && '\\' === $this->pattern[$this->position]
            && BadEscape::at($this->pattern, $this->position, $this->inCharClass, $this->utf, $this->target, null, $this->startsRangeEnd($tokens))) {
            $tokens[] = new Token(TokenType::LiteralEscaped, $this->pattern[$this->position + 1], $this->position, 2);
            $this->literalEscapes[$this->position] = true;
            $this->position += 2;

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
        // Before PCRE2 10.35 "(?*" is no group: no option letter is "*".
        if ('(?*' === $opener && !$this->readsNonAtomicShortSyntax) {
            throw LexerException::withContext(
                \sprintf('Invalid group modifier syntax at position %d: PCRE2 10.35 added "(?*" for "(*napla:"%s.', $this->position + 2, $this->readsNonAtomicAssertions ? '; write "(*napla:" instead' : ''),
                ErrorCode::GroupSyntax,
                $this->position + 2,
                $this->pattern,
            );
        }

        $name = substr($opener, 2, -1);
        $why = match (true) {
            PcreVerb::isAlphabeticAssertion($name) => $this->readsAlphabeticAssertions ? null
                : \sprintf('Unknown alphabetic assertion "(*%s:": PCRE2 10.33 added the alphabetic names of the assertions; write "%s" instead.', $name, PcreVerb::classicOpener($name)),
            PcreVerb::isNonAtomicAssertion($name) => $this->readsNonAtomicAssertions ? null
                : \sprintf('Unknown alphabetic assertion "(*%s:": PCRE2 10.34 added the non-atomic lookarounds.', $name),
            PcreVerb::namesScriptRun($name) => $this->readsScriptRuns ? null
                : \sprintf('Unknown verb "(*%s:": PCRE2 10.33 added script runs.', $name),
            // Before PCRE2 10.33 no verb has a lowercase name.
            default => $this->readsAlphabeticAssertions ? null : \sprintf('Invalid or unsupported PCRE verb: "%s".', $name),
        };
        // Where a name is due, as after "(?<", PCRE wants it on the "(" and
        // reads no verb: the parser refuses it there.
        if (null !== $why && !$this->followsNameOpener($this->position)) {
            // Before PCRE2 10.33 no name is alphabetic: as a condition PCRE
            // wants a group name on its "*", elsewhere a verb name ends with
            // its run of letters, and is not one PCRE knows. From 10.33 a
            // name it does not know yet is refused where it ends.
            $start = $this->position;
            $at = $this->nothingReadBefore($start);
            $before = array_values(array_filter($tokens, static fn (Token $token): bool => $token->position < $at));
            if (!$this->readsAlphabeticAssertions && $this->opensCondition($before, $at)) {
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
                    throw self::conditionWantsAName($start + 1, $this->pattern);
                }
            }

            if (!$this->namesReadWhole) {
                $this->refuseLongVerbName(strspn($name, 'abcdefghijklmnopqrstuvwxyz'), $start);
            }

            throw LexerException::withContext(
                $why,
                ErrorCode::VerbInvalid,
                $start + 2 + ($this->readsAlphabeticAssertions ? \strlen($name) : strspn($name, 'abcdefghijklmnopqrstuvwxyz')),
                $this->pattern,
            );
        }

        $inPlace = $this->readsBodyInPlace($opener, $tokens);
        $end = $this->bodiesInPlace && $inPlace ? null : $this->alphabeticAssertionEnd($opener);
        $start = $this->position;
        if (null !== $end) {
            // The body is copied once, into the value of its token: the name
            // is judged on the characters up to the first one no name holds,
            // which are all a verb name is judged on.
            $this->refuseVerbName(substr($this->pattern, $start + 2, strspn($this->pattern, PcreVerb::WORD_NAME, $start + 2) + 1), $start, $tokens);
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
        if (!$this->readsScanSubstring || !\in_array($name, ['scs', 'scan_substring'], true)
            || $this->opensCondition($tokens, $this->position) || $this->followsNameOpener($this->position)) {
            return null;
        }

        $listOpen = $this->position + 3 + \strlen($name);
        $fault = PcreVerb::groupListFault($this->pattern, $listOpen, $this->reportsPastTheFault, $this->utf ? $this->target : null);
        if (null !== $fault) {
            if (!$this->target->supports(PcreFeature::GroupListErrors)) {
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
            $this->unclosedBodies($opened);

            return null;
        }

        while ($at < $this->length) {
            if ('[' === $this->pattern[$at]) {
                $at = $this->bodyClassEnd($at, $extendedMore);
                if (null === $at) {
                    $this->unclosedBodies($opened);

                    return null;
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

            // An escape PHP 7.3 and 7.4 read as its character takes no more:
            // "\p{L)}" is "p{L" then the ")" that closes the body.
            if ($this->badEscapeIsLiteral && '\\' === $this->pattern[$at] && BadEscape::at($this->pattern, $at, false, $this->utf, $this->target)) {
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
                $this->unclosedBodies($opened);

                return null;
            } else {
                [$open, $extended, $extendedMore] = array_pop($opened) ?? [$this->position, $extended, $extendedMore];
                $this->bodyEnds[$this->bodyMode($extended, $extendedMore)][$this->offset + $open] = [$this->offset + $at + 1, $textEnd];
                if ([] === $opened) {
                    return $at + 1;
                }
            }

            $at++;
        }

        $this->unclosedBodies($opened);

        return null;
    }

    /**
     * Record that none of the groups still open when the reading of a body
     * stopped closes in the text read, each under the mode its "(" was read
     * in.
     *
     * @param list<array{0: int, 1: bool, 2: bool}> $opened
     */
    private function unclosedBodies(array $opened): void
    {
        foreach ($opened as [$open, $extended, $extendedMore]) {
            $this->bodyEnds[$this->bodyMode($extended, $extendedMore)][$this->offset + $open] = [null, $this->offset + $this->length];
        }
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

            // So is a POSIX item whose name holds a backslash, "[:\d-z:]".
            $terminator = '[' === $char ? PosixItem::terminatorPastABackslash($this->pattern, $at) : null;
            if (null !== $terminator) {
                $at = $terminator + 2;

                continue;
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
     * @return array{0: string, 1: int, 2: array<int|string, string|null>}
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

        if (TokenType::PcreVerb === $type && !$this->inCharClass) {
            $this->refuseVerbName(substr($matchedValue, self::OFFSET_VERB_START, self::OFFSET_VERB_END), $startPos, $currentTokens);
        }

        // Before PCRE2 10.37 a backslash and its digits are one number, refused
        // at the digit that takes it past 214748363.
        if (!$this->overflowingNumberIsOctal && !$this->inCharClass && TokenType::Backref === $type
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
        if (TokenType::GroupOpen === $type && null !== ($name = PcreVerb::nameBeforeColonAt($this->pattern, $startPos, PcreVerb::LOWERCASE_NAME)) && !$this->followsNameOpener($startPos)) {
            // "(?(*atomic:" is no condition: PCRE takes a lookaround there,
            // and refuses any other name it knows at the colon.
            $known = PcreVerb::takesArgument($name) || ($this->readsScanSubstring && \in_array($name, ['scs', 'scan_substring'], true));
            if ($known && !PcreVerb::isLookaround($name) && $this->opensCondition($currentTokens, $startPos)) {
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
            if ($this->namesReadWhole) {
                $this->refuseVerbNameTooLong(\strlen($name), $startPos);
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
        if (TokenType::Quantifier === $type && !$this->wideRepeatCounts && '{' === $matchedValue[0]
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
     * @return array{0: InlineFlags, 1: bool, 2: int}|null the modifiers,
     *                                                     whether the group
     *                                                     is scoped with ':',
     *                                                     and the offset past
     *                                                     that ':' or ')'
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
        $ord = \ord($this->pattern[$start]);
        $this->position += $this->byteMode || $ord < 0xC0 ? 1 : ($ord >= 0xF0 ? 4 : ($ord >= 0xE0 ? 3 : 2));
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
     * @param array<int|string, string|null> $matches
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
            TokenType::PosixClass => \is_string($matches['v_posix'] ?? null) ? $matches['v_posix'] : '',
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
     * PCRE reads the name of a verb as it reads a group name, and refuses
     * one too long as one. PCRE2 10.32 reads it as a run of letters and wants
     * a ":" or a ")" right after it; it refuses a name that starts with a
     * digit, or that runs past 32 letters, as a group name. As the condition
     * of "(?(" it reads no verb: it wants a group name on the "*". A setting
     * the pattern opens with is no verb, and is read before.
     *
     * @param string      $name   the verb as written between "(*" and ")", "NAME:arg",
     *                            or up to the first character no name holds
     * @param list<Token> $tokens the tokens read before it
     */
    private function refuseVerbName(string $name, int $start, array $tokens): void
    {
        if ('' === $name || $this->followsNameOpener($start)) {
            return;
        }

        // As the condition, 10.32 wants a name on the "*" right after "(?(";
        // after a callout the condition must be an assertion, which the
        // parser refuses on the "(" of the verb, before its name is read.
        // What PCRE reads as nothing between them, a comment, "\E", "\Q\E"
        // or under "x" a space or a line comment, does not count.
        $at = $this->nothingReadBefore($start);
        while ([] !== $tokens && $tokens[\count($tokens) - 1]->position >= $at) {
            array_pop($tokens);
        }

        $condition = $this->opensCondition($tokens, $at);
        if (!$this->readsAlphabeticAssertions && $condition) {
            $previous = $tokens[\count($tokens) - 1] ?? null;
            if (null !== $previous && TokenType::GroupModifierOpen === $previous->type && $at === $start) {
                throw self::conditionWantsAName($start + 1, $this->pattern);
            }

            return;
        }

        // From 10.33 the name is the run of word characters, held to the
        // length of a group name, and refused where it ends.
        if ($this->namesReadWhole) {
            $length = strspn($name, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_');
            // As the condition, only an alphabetic name is read before the
            // parser refuses what is no assertion.
            if (!$condition || 1 === LibraryPcre::match('/^[a-z]/', $name)) {
                $this->refuseVerbNameTooLong($length, $start);
            }

            // As the condition, the parser refuses it on its "(".
            if (!$condition) {
                $this->refuseSettingWithArgument($name, $length, $start);
                $this->refuseAlphabeticNameEndingThePattern($name, $length, $start);
            }

            return;
        }

        // A limit that ends the opening settings is read as one, whatever
        // its value: the settings reader refuses a value it cannot take.
        $settingsEnd = $this->leadingSettingsEnd();
        if ($start < $settingsEnd || ($start === $settingsEnd && 1 === LibraryPcre::match('/^LIMIT_(?:MATCH|DEPTH|HEAP|RECURSION)=/', $name))) {
            return;
        }

        if (Ascii::isDigit($name[0])) {
            throw LexerException::withContext(
                \sprintf('Invalid verb name at position %d: PCRE2 before 10.33 reads it as a group name, which must not start with a digit.', $start + 2),
                ErrorCode::GroupNameInvalid,
                $start + 2,
                $this->pattern,
            );
        }

        $letters = strspn($name, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ');
        $this->refuseLongVerbName($letters, $start);

        $next = $name[$letters] ?? '';
        if (':' !== $next && '' !== $next) {
            throw LexerException::withContext(
                \sprintf('Invalid or unsupported PCRE verb at position %d: PCRE2 before 10.33 reads a verb name as letters only.', $start + 2 + $letters),
                ErrorCode::VerbInvalid,
                $start + 2 + $letters,
                $this->pattern,
            );
        }

        $this->refuseSettingWithArgument($name, $letters, $start);
    }

    /**
     * Where the run of what PCRE reads as nothing that ends at $position
     * starts: "(?#...)" comments, "\E" and "\Q\E", and under "x" white
     * space and line comments.
     */
    private function nothingReadBefore(int $position): int
    {
        do {
            $before = $position;
            // Nothing stands before the start: a negative offset would read
            // the end of the pattern.
            $previous = $position > 0 ? $this->pattern[$position - 1] : '';
            if ($this->extendedMode && '' !== $previous && '' === trim($previous, " \t\n\r\f\v")) {
                // A space, or the line break that ends a "#" comment.
                $position--;
                if ("\n" === $previous) {
                    $lineStart = $this->lastBefore("\n", $position) + 1;
                    $hash = strpos(substr($this->pattern, $lineStart, $position - $lineStart), '#');
                    $position = false === $hash ? $position : $lineStart + $hash;
                }
            } elseif (')' === $previous) {
                // A "(?#...)" comment holds no ")": it opens past the one before.
                $from = $this->lastBefore(')', $position - 1) + 1;
                $open = strpos(substr($this->pattern, $from, $position - 1 - $from), '(?#');
                $position = false === $open ? $position : $from + $open;
            } elseif ('E' === $previous && 1 === strspn(strrev(substr($this->pattern, max(0, $position - 65), min($position - 1, 64))), '\\') % 2) {
                $position -= '\\Q' === substr($this->pattern, max(0, $position - 4), 2) && $position >= 4 ? 4 : 2;
            }
        } while ($position !== $before);

        return $position;
    }

    /**
     * Where $needle last stands before $position, -1 for nowhere.
     */
    private function lastBefore(string $needle, int $position): int
    {
        $at = $position > 0 ? strrpos($this->pattern, $needle, $position - 1 - $this->length) : false;

        return false === $at ? -1 : $at;
    }

    /**
     * PCRE reads a setting only as it is written, "(*UTF)": given an
     * argument, "(*UTF:x)", it is a verb PCRE does not know, refused where
     * its name ends, wherever it stands.
     *
     * @param string $name   the verb as written between "(*" and ")"
     * @param int    $length the length of its name, as the release reads it
     */
    private function refuseSettingWithArgument(string $name, int $length, int $start): void
    {
        if (':' !== ($name[$length] ?? '') || !isset(StartOptions::NAMES[substr($name, 0, $length)])) {
            return;
        }

        throw LexerException::withContext(
            \sprintf('Invalid or unsupported PCRE verb at position %d: "(*%s)" is a setting, which takes no argument.', $start + 2 + $length, substr($name, 0, $length)),
            ErrorCode::VerbInvalid,
            $start + 2 + $length,
            $this->pattern,
        );
    }

    /**
     * From PCRE2 10.47 an alphabetic name closed with no colon, "(*pla)", is
     * refused past its ")". Where that ")" ends the pattern, PCRE refuses
     * the name before it finds a group left open there.
     *
     * @param string $name   the verb as written between "(*" and ")"
     * @param int    $length the length of its name
     */
    private function refuseAlphabeticNameEndingThePattern(string $name, int $length, int $start): void
    {
        if (!$this->reportsPastTheFault || $length !== \strlen($name) || 1 !== LibraryPcre::match('/^[a-z]/', $name) || $start + $length + 3 !== $this->length) {
            return;
        }

        throw LexerException::withContext(
            \sprintf('Unknown alphabetic assertion "(*%s)" at position %d: an alphabetic name is followed by ":".', $name, $this->length),
            ErrorCode::VerbInvalid,
            $this->length,
            $this->pattern,
        );
    }

    /**
     * From PCRE2 10.33 a verb name is held to the length of a group name,
     * and refused as one too long where it ends.
     *
     * @param int $length the length of the name
     * @param int $start  the offset of the "(" of the verb
     */
    private function refuseVerbNameTooLong(int $length, int $start): void
    {
        $longest = $this->target->supports(PcreFeature::LongGroupNames) ? self::NAME_LENGTH : self::NAME_LENGTH_BEFORE_PCRE_10_44;
        if ($length > $longest) {
            throw LexerException::withContext(
                \sprintf('Verb name too long at position %d: PCRE takes at most %d characters.', $start + 2 + $length, $longest),
                ErrorCode::GroupNameTooLong,
                $start + 2 + $length,
                $this->pattern,
            );
        }
    }

    /**
     * PCRE2 10.32 refuses a verb name of more than 32 letters at the 33rd,
     * as a group name too long.
     */
    private function refuseLongVerbName(int $letters, int $start): void
    {
        if ($letters > self::NAME_LENGTH_BEFORE_PCRE_10_33) {
            $offset = $start + 2 + self::NAME_LENGTH_BEFORE_PCRE_10_33 + 1;

            throw LexerException::withContext(
                \sprintf('Verb name too long at position %d: PCRE2 before 10.33 takes at most 32 letters.', $offset),
                ErrorCode::GroupNameTooLong,
                $offset,
                $this->pattern,
            );
        }
    }

    /**
     * Where the settings the pattern opens with, "(*UTF)(*NO_JIT)", end:
     * PCRE reads them before the pattern, and a setting it does not know
     * yet ends them.
     */
    private function leadingSettingsEnd(): int
    {
        $settings = StartOptions::of($this->pattern);
        if (!$this->target->supports(PcreFeature::CasingSettingVerbs)) {
            foreach (['(*CASELESS_RESTRICT)', '(*TURKISH_CASING)'] as $unknown) {
                $at = strpos($settings, $unknown);
                $settings = false === $at ? $settings : substr($settings, 0, $at);
            }
        }

        return \strlen($settings);
    }

    /**
     * Whether $position stands where PCRE reads a name: after "(?<", "(?'",
     * "(?P<", a call by name, "(?&" or "(?P>", a reference, "(?P=", or the
     * name of a condition, "(?(", "(?(<", "(?('", "(?(R&", and the start of
     * a name, which a "(" ends.
     */
    private function followsNameOpener(int $position): bool
    {
        $window = min($position, 2 * self::NAME_LENGTH);
        $before = substr($this->pattern, $position - $window, $window);

        return 1 === LibraryPcre::match('/\(\?(?:[<\'&]|P[<>=]|\((?:[<\']|R&)?)\w*+\z/', $before);
    }

    private static function conditionWantsAName(int $offset, string $pattern): LexerException
    {
        return LexerException::withContext(
            \sprintf('Invalid conditional construct at position %d. Condition must be a group reference, lookaround, or (DEFINE).', $offset),
            ErrorCode::ConditionalInvalid,
            $offset,
            $pattern,
        );
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
     * Whether what the cursor reads in a class of PCRE2 10.32 ends a range:
     * a character, then a "-" that starts a range from it. A class escape
     * there is refused, and read as its letter under
     * PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL.
     *
     * @param list<Token> $tokens
     */
    private function startsRangeEnd(array $tokens): bool
    {
        // Only a class escape may be refused there; a character never is.
        if (!$this->pcre2Ten32 || !$this->inCharClass || !str_contains(BadEscape::CLASS_ESCAPE_LETTERS, $this->pattern[$this->position + 1] ?? '')) {
            return false;
        }

        $classStart = $this->charClassStartPositions[\count($this->charClassStartPositions) - 1];
        $first = \count($tokens);
        while ($first > 0 && !(TokenType::CharClassOpen === $tokens[$first - 1]->type && $tokens[$first - 1]->position === $classStart)) {
            $first--;
        }

        $state = 'none';
        foreach (\array_slice($tokens, $first) as $token) {
            $state = match ($token->type) {
                TokenType::QuoteModeStart, TokenType::QuoteModeEnd, TokenType::Negation => $state,
                TokenType::Range => match ($state) {
                    'character' => 'range',
                    'range' => 'none',
                    default => 'character',
                },
                // Without UTF mode a multibyte end leaves its last byte, a
                // character a "-" may start a range from.
                TokenType::Literal, TokenType::LiteralEscaped => 'range' === $state && 1 === $this->multibyteLength($token) ? 'none' : 'character',
                TokenType::Unicode, TokenType::Octal, TokenType::OctalLegacy,
                TokenType::UnicodeNamed, TokenType::ControlChar => 'range' === $state ? 'none' : 'character',
                default => 'none',
            };
        }

        return 'range' === $state;
    }

    /**
     * The offset past the first reversed range of the class opened at
     * $classStart, "z-a", whose bounds each name one character: a literal,
     * quoted or not, or an escape such as "\x7A", "\172" or "\N{U+7A}". PCRE
     * skips the quotes on either side of the "-". Null when it has none.
     *
     * @param list<Token> $tokens
     */
    private function reversedRangeEnd(array $tokens, int $classStart): ?int
    {
        $members = [];
        $open = false;
        $quoted = false;
        foreach ($tokens as $token) {
            if (!$open) {
                $open = TokenType::CharClassOpen === $token->type && $token->position === $classStart;
            } elseif (TokenType::QuoteModeStart === $token->type || TokenType::QuoteModeEnd === $token->type) {
                $quoted = TokenType::QuoteModeStart === $token->type;
            } elseif (!$quoted && TokenType::Literal === $token->type && '[' === $token->value && null !== PosixItem::terminator($this->pattern, $token->position)) {
                // The "[" of a POSIX item, "[.a.]", names no character:
                // PCRE refuses the item, or a range it ends.
                $members[] = new Token(TokenType::PosixClass, '', $token->position, 1);
            } else {
                $members[] = $token;
            }
        }

        $count = \count($members);
        for ($i = 0; $i + 2 < $count; $i++) {
            if (TokenType::Range !== $members[$i + 1]->type) {
                continue;
            }

            $start = $this->rangeBound($members[$i], true);
            $end = $this->rangeBound($members[$i + 2], false);
            $endLength = $this->multibyteLength($members[$i + 2]);
            if (null !== $start && null !== $end && $start > $end) {
                // On the first byte of a multibyte end.
                return $members[$i + 2]->end() - $endLength + 1;
            }

            // Without UTF mode, a multibyte end may start the next range
            // from its last byte.
            $i += $endLength > 1 ? 1 : 2;
        }

        return null;
    }

    /**
     * The character a class member names as a bound of a range, null when it
     * names none or one this reading leaves alone. Without UTF mode PCRE
     * reads bytes: a multibyte literal starts a range at its last byte, and
     * ends one at its first. A "-" right after the one that makes the range
     * is its end.
     */
    private function rangeBound(Token $token, bool $start): ?int
    {
        // "\x" with no digit is NUL where PCRE takes it (up to 10.44). A
        // "\x{" PCRE2 refuses, which PHP 7.3 and 7.4 read as the letter x
        // then text, stays the x.
        if (TokenType::LiteralEscaped === $token->type && 2 === $token->end() - $token->position && '\\x' === substr($this->pattern, $token->position, 2)
            && '{' !== ($this->pattern[$token->end()] ?? '')) {
            return 0;
        }

        $value = $token->value;
        $codePoint = match ($token->type) {
            TokenType::Literal, TokenType::LiteralEscaped, TokenType::Range => match (true) {
                1 === \strlen($value) => \ord($value),
                $this->utf && 1 === mb_strlen($value, 'UTF-8') => mb_ord($value, 'UTF-8'),
                !$this->utf && \strlen($value) > 1 => \ord(substr($value, $start ? -1 : 0, 1)),
                default => null,
            },
            TokenType::Unicode => CodePointReader::fromHexEscape($value),
            TokenType::Octal => CodePointReader::fromOctalEscape($value),
            TokenType::OctalLegacy => CodePointReader::fromOctalEscape('\\'.$value),
            TokenType::UnicodeNamed => CodePointReader::fromNamedEscape('\\N{'.$value.'}'),
            TokenType::ControlChar => 1 === \strlen($value) && \ord($value) < 0x80 ? \ord(Ascii::upper($value)) ^ 0x40 : null,
            default => null,
        };

        return null === $codePoint || $codePoint < 0 || (!$this->utf && $codePoint > 0xFF) ? null : $codePoint;
    }

    /**
     * The bytes of a literal PCRE reads one at a time, without UTF mode: a
     * multibyte character ends a range on its first byte. One otherwise.
     */
    private function multibyteLength(Token $token): int
    {
        return !$this->utf && \in_array($token->type, [TokenType::Literal, TokenType::LiteralEscaped], true) ? max(1, \strlen($token->value)) : 1;
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

    /**
     * @param list<Token> $tokens
     */
    private function validateFinalState(array $tokens): void
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
            // meets nothing after the last quote. Under
            // PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL, as PHP 7.3 and 7.4 compile,
            // that backslash is a character, and the class is left open.
            $skipped = $this->extendedMoreMode ? '(?:\\\\(?:Q\\\\)?E|[ \t])' : '(?:\\\\(?:Q\\\\)?E)';
            if (!$this->emptyQuoteOpeningClassIsUnclosed && !$this->badEscapeIsLiteral
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
            $reversed = $this->reversedRangeEnd($tokens, $classStart);
            if (null !== $reversed) {
                throw LexerException::withContext(
                    'Range out of order in character class.',
                    ErrorCode::RangeReversed,
                    $this->reportsPastTheFault ? $reversed : $reversed - 1,
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
