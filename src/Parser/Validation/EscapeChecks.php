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

namespace PHPRegex\Parser\Validation;

use PHPRegex\Parser\Cache\StaticCaches;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\PhpVersionGates;
use PHPRegex\Parser\Syntax\BadEscape;
use PHPRegex\Parser\Syntax\NamedCharacterBrace;
use PHPRegex\Parser\Syntax\PropertyNames;
use PHPRegex\Parser\Syntax\RepeatCount;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\Utf8;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenType;

/**
 * The checks of one escape, as PCRE2 reads it: a letter it gives no meaning
 * to or refuses, the digits and braces of "\x{...}", "\o{...}" and
 * "\N{U+...}", the value they spell, a property name, and a type a class
 * cannot hold.
 *
 * Each needs only the text of the escape and the mode it is read in, not
 * the nodes around it. That is why they stand apart from the validator: the
 * validator runs them on the nodes of a tree, and FirstErrorLocator on the
 * tokens of a pattern whose structure could not be read, where PCRE, which
 * reads escapes and structure in one pass, stops on such an escape before
 * it reaches the syntax error.
 *
 * An instance reads one text, the pattern or the payload of a "(*pla:...)"
 * parsed apart, and reports offsets in the whole pattern.
 *
 * @internal
 */
final class EscapeChecks
{
    /**
     * Script and property names newer than PCRE2 10.40, loosely spelled, with
     * the release that brought them ("pcre2test -LS" and "-LP" of each).
     */
    private const UNICODE_NAMES_SINCE = [
        'kawi' => '10.43', 'nagmundari' => '10.43', 'nagm' => '10.43',
        'garay' => '10.45', 'gara' => '10.45', 'gurungkhema' => '10.45', 'gukh' => '10.45',
        'kiratrai' => '10.45', 'krai' => '10.45', 'olonal' => '10.45', 'onao' => '10.45',
        'sunuwar' => '10.45', 'sunu' => '10.45', 'todhri' => '10.45', 'todr' => '10.45',
        'tulutigalari' => '10.45', 'tutg' => '10.45',
        'beriaerfe' => '10.48', 'berf' => '10.48', 'sidetic' => '10.48', 'sidt' => '10.48',
        'taiyo' => '10.48', 'tayo' => '10.48', 'tolongsiki' => '10.48', 'tols' => '10.48',
        'idcompatmathcontinue' => '10.45', 'idcompatmathstart' => '10.45',
        'idsunaryoperator' => '10.45', 'idsu' => '10.45', 'incb' => '10.45',
        'modifiercombiningmark' => '10.45', 'mcm' => '10.45',
    ];

    /**
     * The most characters PCRE reads of a braced property name.
     */
    private const MAX_PROPERTY_NAME_LENGTH = 49;

    /**
     * Escaped letters PCRE2 gives no meaning to.
     */
    private const UNRECOGNIZED_ESCAPES = [
        'I' => true, 'J' => true, 'M' => true, 'O' => true, 'T' => true, 'Y' => true,
        'i' => true, 'j' => true, 'm' => true, 'q' => true, 'y' => true,
    ];

    /**
     * Perl case-changing escapes, which PCRE2 refuses rather than ignores.
     */
    private const UNSUPPORTED_ESCAPES = [
        'F' => true, 'L' => true, 'U' => true, 'l' => true, 'u' => true,
    ];

    /**
     * Escapes that match a position or more than one character: a class,
     * which matches one character, cannot hold them.
     */
    private const CLASS_INVALID_ESCAPES = [
        'A' => true, 'B' => true, 'C' => true, 'G' => true, 'K' => true,
        'R' => true, 'X' => true, 'Z' => true, 'z' => true,
    ];

    private const HEX_DIGITS = '0123456789abcdefABCDEF';

    private const OCTAL_DIGITS = '01234567';

    /**
     * What PCRE2 skips around the digits of "\x{...}" and "\o{...}".
     */
    private const BRACE_PADDING = " \t";

    /**
     * A repeat count after "\N", which makes it "\N" repeated rather than a
     * "\N{...}" name.
     */
    private const REPEAT_COUNT = '/\G\{[ \t]*+(?:\d++[ \t]*+(?:,[ \t]*+\d*+[ \t]*+)?|,[ \t]*+\d++[ \t]*+)\}/';

    /**
     * Whether the running engine compiles each property, by the escape that
     * names it.
     *
     * @var array<string, bool>
     */
    private static array $unicodePropCache = [];

    /**
     * @param PcreTarget  $target             the PHP and PCRE2 judged
     * @param string|null $pattern            the whole pattern, which the errors quote
     * @param string|null $source             the text the positions of the escapes
     *                                        point into: the pattern, or the payload
     *                                        of a "(*pla:...)" parsed apart; null
     *                                        when nothing says what was written
     * @param int         $offset             where that text starts in the pattern
     * @param bool        $utf                whether PCRE reads the pattern as UTF-8,
     *                                        by the "u" flag or "(*UTF)"
     * @param bool        $utfFlag            whether the "u" flag itself is set: PHP
     *                                        refuses "\C" by the flag, not by the mode
     * @param bool        $badEscapeIsLiteral whether the pattern is compiled with
     *                                        PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL, as
     *                                        PHP 7.3 and 7.4 compile it without "X":
     *                                        an escape PCRE2 refuses as it reads it
     *                                        is the character after the backslash
     * @param PcreEngine  $engine             asks the running engine which property
     *                                        names it knows
     */
    public function __construct(
        private readonly PcreTarget $target,
        private readonly ?string $pattern = null,
        private readonly ?string $source = null,
        private readonly int $offset = 0,
        private readonly bool $utf = false,
        private readonly bool $utfFlag = false,
        private readonly bool $badEscapeIsLiteral = false,
        private readonly PcreEngine $engine = new PcreEngine(),
    ) {}

    /**
     * The checks of the escapes of a whole pattern, read with its flags.
     */
    public static function forPattern(string $pattern, string $flags, PcreTarget $target): self
    {
        $utfFlag = str_contains($flags, 'u');

        return new self(
            $target,
            $pattern,
            $pattern,
            0,
            $utfFlag || StartOptions::turnUtfOn($pattern),
            $utfFlag,
            PhpVersionGates::badEscapeIsLiteral($target->phpVersionId, $flags),
        );
    }

    /**
     * Clears the answers of the running engine. Useful for long-running
     * processes or testing.
     */
    public static function clearCaches(): void
    {
        self::$unicodePropCache = [];
    }

    /**
     * The same checks on another text of the pattern, which starts at
     * $offset in it.
     */
    public function reading(?string $source, int $offset): self
    {
        return new self($this->target, $this->pattern, $source, $offset, $this->utf, $this->utfFlag, $this->badEscapeIsLiteral, $this->engine);
    }

    /**
     * The first escape PCRE refuses among the tokens that start before
     * $limit, in a pattern whose structure could not be read: PCRE reads
     * escapes and structure in one pass, so it stops on such an escape
     * before it reaches the error at $limit.
     *
     * @param array<Token> $tokens the tokens read from the pattern
     */
    public function firstErrorBefore(array $tokens, int $limit): ?SemanticErrorException
    {
        $source = (string) $this->source;

        // Before PCRE2 10.45, "\N", "\z" or "\p" ending a range is refused as
        // the range, before what follows its letter is read.
        $nEndsRange = !$this->target->supports(PcreFeature::ClassEscapeEndingRangeRefusedAsEscape);
        $pcre2Ten32 = !$this->target->supports(PcreFeature::BadEscapeInClassStaysRefused);
        $afterRange = false;
        $rangeEnded = false;
        // Up to PCRE2 10.44 a quote after a class escape keeps a "-" after
        // it a member: "[\h\E-\pL]".
        $quoteKeepsHyphen = !$this->target->supports(PcreFeature::EmptyQuoteSkippedAfterClassEscape);
        $classEscapeThenQuote = false;
        $classEscape = false;
        $inClass = false;

        try {
            foreach ($tokens as $token) {
                // An escape that starts on the character at fault is not
                // read: PCRE2 10.47 reports past that character.
                if ($this->target->faultOffset($token->position + 1) >= $limit) {
                    break;
                }

                // A "-" right after a range that ended is a member, a quote
                // between them or not: "[a-c-\pL]", "[a-c\E-\pL]". So is a
                // "-" that ends one, "[!--\pL]".
                $endsRange = $afterRange;
                $quote = \in_array($token->type, [TokenType::QuoteModeStart, TokenType::QuoteModeEnd], true);
                $afterRange = (TokenType::Range === $token->type && !$endsRange && !$rangeEnded && !$classEscapeThenQuote) || ($endsRange && $quote);
                // Without UTF a multibyte character ending a range leaves
                // its last byte, which a "-" makes a range from.
                $rangeEnded = $quote ? $rangeEnded || $endsRange
                    : $endsRange && ($this->utf || !\in_array($token->type, [TokenType::Literal, TokenType::LiteralEscaped], true) || 1 === \strlen($token->value));
                $classEscapeThenQuote = $quoteKeepsHyphen && $quote && ($classEscape || $classEscapeThenQuote);
                $classEscape = \in_array($token->type, [TokenType::CharType, TokenType::PosixClass, TokenType::UnicodeProp], true);

                // "\N" ending a range is refused as the range, but for a "\N{"
                // check_escape() refuses first.
                if ($nEndsRange && $endsRange && TokenType::CharType === $token->type && 'N' === $token->value
                    && ('{' !== ($source[$token->end()] ?? '') || (!NamedCharacterBrace::namesCodePoint($source, $token->end(), $this->target)
                        && null === NamedCharacterBrace::errorInClass($source, $token->end(), $this->target)))) {
                    continue;
                }

                if ($nEndsRange && $endsRange && \in_array($token->type, [TokenType::LiteralEscaped, TokenType::CharType, TokenType::UnicodeProp], true)
                    && BadEscape::endsRangeAsInvalidRange($source, $token->position, $pcre2Ten32)
                    && !($this->badEscapeIsLiteral && BadEscape::at($source, $token->position, true, $this->utf, $this->target, null, true))) {
                    $this->raise(
                        \sprintf('Invalid range in character class: "\%s" cannot end a range, at position %d.', $source[$token->position + 1], $token->position + 2),
                        $token->position + 2,
                        ErrorCode::RangeInvalidBounds,
                    );
                }

                $inClass = match ($token->type) {
                    TokenType::CharClassOpen => true,
                    TokenType::CharClassClose => false,
                    default => $inClass,
                };
                $this->token($token, $source, $inClass, $endsRange);
            }
        } catch (SemanticErrorException $error) {
            return $error;
        }

        return null;
    }

    /**
     * A letter written after a backslash that the lexer read as the letter
     * itself, because it names no escape it knows.
     *
     * @param bool $inClass   whether the escape stands in a class
     * @param bool $endsRange whether it ends a range of that class
     */
    public function escapedLetter(string $letter, int $start, bool $inClass, bool $endsRange): void
    {
        $source = (string) $this->source;

        // PHP 7.3 and 7.4 read an escape PCRE2 refuses as it reads it as its
        // letter.
        if ($this->badEscapeIsLiteral && BadEscape::at($source, $start, $inClass, $this->utf, $this->target, null, $endsRange)) {
            return;
        }

        $end = $start + 2;

        if (isset(self::UNRECOGNIZED_ESCAPES[$letter])) {
            $this->raise(
                \sprintf('Unrecognized escape sequence "\%s".', $letter),
                $this->target->faultOffset($end),
                ErrorCode::EscapeUnrecognized,
            );
        }

        if (isset(self::UNSUPPORTED_ESCAPES[$letter])) {
            $this->raiseUnsupportedEscape($letter, $end);
        }

        if ($inClass && isset(self::CLASS_INVALID_ESCAPES[$letter])) {
            $this->raise(
                \sprintf('Escape sequence \%s is invalid in a character class.', $letter),
                $this->target->faultOffset($end),
                ErrorCode::CharclassInvalidEscape,
            );
        }

        // "\k" in a class is the letter from PCRE2 10.45, which no PHP release
        // bundles yet; the releases before refuse it, on the "k".
        if ($inClass && 'k' === $letter && !$this->target->supports(PcreFeature::ClassBackslashKIsLetter)) {
            $this->raise(
                'Escape sequence \k is invalid in a character class before PCRE2 10.45.',
                $start + 1,
                ErrorCode::CharclassInvalidEscape,
            );
        }

        match ($letter) {
            'o' => $this->validateOctalBraces($source, $end),
            'x' => $this->validateHexBraces($source, $end),
            'N' => $this->validateNamedCharacterBraces($source, $end),
            'p', 'P' => $this->raiseMalformedProperty($source, $letter, $end),
            default => null,
        };
    }

    /**
     * A type: "\C" under the "u" flag, and in a lookbehind in UTF mode, and
     * in a class one that matches no single character.
     *
     * @param bool     $inClass      whether the type stands in a class
     * @param int|null $lookbehindAt where PCRE reports the innermost
     *                               lookbehind "\C" stands in, if one
     */
    public function charType(CharTypeNode $node, bool $inClass, ?int $lookbehindAt = null): void
    {
        // "\C" matches one code unit, which would split a UTF-8 character:
        // PHP 8.4.25 and 8.5.10 refuse it with the "u" flag (GH-21134), and
        // earlier releases, which compile it, can crash matching it, so it is
        // refused for every PHP. "(*UTF)\C" compiles.
        if ('C' === $node->value && $this->utfFlag) {
            // A PHP that compiles it refuses it in a lookbehind, which PCRE
            // reports where it measures that lookbehind; PHP 8.4.25 and
            // 8.5.10 compile "u" patterns with PCRE2_NEVER_BACKSLASH_C.
            $this->raise(
                '\C is not allowed in Unicode mode: it matches a single byte.',
                null === $lookbehindAt || PhpVersionGates::neverBackslashCUnderUtf($this->target->phpVersionId) ? $node->getEndPosition() : $lookbehindAt,
                ErrorCode::EscapeSingleByteInUtf,
                'Use "." or drop the "u" flag.',
            );
        }

        // "(*UTF)" without the "u" flag: "\C" compiles, but not in a
        // lookbehind, which PCRE refuses where it measures it.
        if ('C' === $node->value && $this->utf && null !== $lookbehindAt) {
            $this->raise(
                '\C is not allowed in a lookbehind in UTF-8 mode: the length of the lookbehind would not be known.',
                $lookbehindAt,
                ErrorCode::EscapeSingleByteInUtf,
            );
        }

        if (!$inClass) {
            return;
        }

        if ('N' === $node->value) {
            // "[\N{U+41 }]" with padding the lexer does not read, or a
            // malformed "\N{U+...}", reaches here as "\N": judge the braces
            // as outside a class.
            if (null !== $this->source && $this->startsNamedCodePoint($this->source, $node->getEndPosition())) {
                $this->validateNamedCharacterBraces($this->source, $node->getEndPosition());

                return;
            }

            // "\N{" that is no count PCRE2 reads there, and any from 10.45,
            // is a character name, which PCRE does not support; a count it
            // reads is refused for its numbers. Where the "\N" ends, past the
            // "{" from 10.47.
            $braceError = null !== $this->source && '{' === ($this->source[$node->getEndPosition()] ?? '') ? NamedCharacterBrace::errorInClass($this->source, $node->getEndPosition(), $this->target) : null;
            if (null !== $braceError) {
                $this->raise(
                    match ($braceError) {
                        ErrorCode::QuantifierTooBig => 'Number too big in the count after \N: PCRE allows at most 65535 repetitions.',
                        ErrorCode::QuantifierInvalidRange => 'Invalid count after \N: min > max.',
                        default => 'PCRE does not support the escape "\N{" (\F, \L, \l, \N{name}, \U and \u are not supported), in a character class either.',
                    },
                    $this->target->faultOffset($node->getEndPosition() + 1),
                    $braceError,
                );
            }

            $this->raise(
                '\N is not supported in a character class.',
                $node->getEndPosition(),
                ErrorCode::CharclassInvalidEscape,
            );
        }

        if (isset(self::CLASS_INVALID_ESCAPES[$node->value])) {
            $this->raise(
                \sprintf('Escape sequence \%s is invalid in a character class.', $node->value),
                $this->target->faultOffset($node->getEndPosition()),
                ErrorCode::CharclassInvalidEscape,
            );
        }
    }

    /**
     * A character written by its code: the value PCRE reads, and the way it
     * is written, "\N{...}", "\u", padded braces.
     */
    public function characterLiteral(CharLiteralNode $node): void
    {
        // "\N{name}": PCRE2 refuses a character name, whatever it names, on
        // the "{", past it from PCRE2 10.47; only "\N{U+...}" is a code point,
        // and from 10.43 "\N{ U+...}": before, a space makes "\N{" a name.
        $padding = $this->target->supports(PcreFeature::PaddedBracedEscapes) ? '[ \t]*+' : '';
        if (CharLiteralType::UnicodeNamed === $node->type
            && 1 !== LibraryPcre::match('/^\\\\N\{'.$padding.'U\+/', $node->originalRepresentation)) {
            // Before PCRE2 10.37 its digits are read as a count first.
            if (!$this->target->supports(PcreFeature::LargeOpenCountIsText) && null !== RepeatCount::overflowBeforeTen37($node->originalRepresentation, 2)) {
                $this->raise('Number too big in the count after \N: PCRE allows at most 65535 repetitions.', $node->startPosition + 2, ErrorCode::QuantifierTooBig);
            }

            $this->raiseUnsupportedEscape('N{', $this->target->faultOffset($node->startPosition + 3));
        }

        // "\N{U+...}" outside UTF mode is refused before its braces are read:
        // "\N{U+1 }" fails on the mode on every release, padding or not.
        if (CharLiteralType::UnicodeNamed === $node->type) {
            $this->validateUnicodeNamed($node);
        }

        // "\x" with no digit: NUL up to PCRE2 10.44, refused from 10.45.
        if ('\\x' === $node->originalRepresentation && null !== $this->source) {
            $this->validateHexBraces($this->source, $node->startPosition + 2);
        }

        // The lexer and the parser already read these as well formed: the
        // value is judged, which PCRE reads before it looks at what follows
        // the digits.
        match ($node->type) {
            CharLiteralType::Unicode => $this->validateUnicode($node),
            CharLiteralType::Octal => $this->validateOctal($node),
            CharLiteralType::OctalLegacy => $this->validateOctalLegacy($node),
            CharLiteralType::UnicodeNamed => null,
        };

        $this->validatePaddedBraces($node);

        // UTF-8 cannot encode the UTF-16 surrogates. PCRE reports it at the
        // closing brace.
        if ($this->utf && $node->codePoint >= 0xD800 && $node->codePoint <= 0xDFFF) {
            $this->raise(
                \sprintf('Code point "%s" is a surrogate, which is not allowed in Unicode mode.', $node->originalRepresentation),
                $node->getEndPosition() - 1,
                ErrorCode::UnicodeSurrogate,
            );
        }
    }

    /**
     * A property, "\p{...}", "\P{...}" or "\pL": whether its name is
     * well formed, and known to the release judged.
     */
    public function property(UnicodePropNode $node): void
    {
        // Before PCRE2 10.40, a name is looked up as written in a table of
        // its own: no running engine since then answers for it.
        if (null !== $this->source && !$this->target->supports(PcreFeature::LoosePropertyNames)) {
            $this->validateExactPropertyName($node, $this->source);

            return;
        }

        if ($node->hasBraces && null !== $this->source) {
            $malformedAt = $this->malformedPropertyNameEnd($this->source, $node->startPosition + 2);
            if (null !== $malformedAt) {
                $this->raiseMalformedPropertyAt($this->source[$node->startPosition + 1], $malformedAt);
            }
        }

        $prop = $node->prop;
        $key = $node->hasBraces
            ? 'p'.$prop
            : ((\strlen($prop) > 1 || str_starts_with($prop, '^'))
                ? 'p{'.$prop.'}'
                : 'p'.$prop);

        // The name as the release reads it, past the "^" that negates: no
        // property name holds another "^". From PCRE2 10.45 the "^" may
        // follow spaces, hyphens or underscores.
        $skippedCaret = null !== $this->nameAfterSkippedCaret($node);
        $read = $node->hasBraces ? $this->negationRead($node) : null;
        if (null !== $read) {
            [$negated, $name] = $read;
            if (str_contains($name, '^')) {
                $this->raise(
                    \sprintf('Invalid or unsupported Unicode property: \\%s: no property name holds a "^".', substr($this->source ?? '', $node->startPosition + 1, $node->getEndPosition() - $node->startPosition - 1)),
                    $node->getEndPosition(),
                    ErrorCode::UnicodePropertyInvalid,
                );
            }

            if ($skippedCaret) {
                $key = 'p{'.($negated ? '^' : '').$name.'}';
            }
        }

        if (!isset(self::$unicodePropCache[$key])) {
            StaticCaches::register(self::class, self::clearCaches(...));
            self::$unicodePropCache = StaticCaches::makeRoom(self::$unicodePropCache);
            self::$unicodePropCache[$key] = $this->compiles($key);
        }

        // The running engine answered; for another target, a name whose
        // support arrived in a later release is judged by that release, both
        // ways: known to the target, unknown to it.
        $needs = $this->target->isRunningEngine() ? null : $this->releaseAddingProperty($node);
        if (null !== $needs) {
            if (!$this->target->pcreAtLeast($needs)) {
                $this->raise(
                    \sprintf('Invalid or unsupported Unicode property: \\%s. It needs PCRE2 %s, and the target is PCRE2 %s.', ltrim($key, '\\'), $needs, $this->target->pcreVersion),
                    $node->getEndPosition(),
                    ErrorCode::UnicodePropertyInvalid,
                    \sprintf('Target a PHP whose PCRE2 is %s or later, or use another property.', $needs),
                );
            }

            // A "^" after skipped characters needs 10.45 only: the name
            // after it is looked up, unless a later release added it.
            if (!$skippedCaret || isset(self::UNICODE_NAMES_SINCE[self::looseName($read[1] ?? '')])) {
                return;
            }
        }

        if (false === self::$unicodePropCache[$key]) {
            // A name a later release added: the running engine is that old.
            $needs = $this->releaseAddingProperty($node);
            if (null !== $needs && !$this->target->pcreAtLeast($needs)) {
                $this->raise(
                    \sprintf('Invalid or unsupported Unicode property: \\%s.', $key),
                    $node->getEndPosition(),
                    ErrorCode::UnicodePropertyInvalid,
                    \sprintf('Target a PHP whose PCRE2 is %s or later, or use another property.', $needs),
                );
            }

            $propertyKey = $this->extractUnicodePropertyKey($key);
            $suggestion = $this->suggestUnicodeProperty($propertyKey);
            $hint = null === $suggestion ? null : "Did you mean \\{$suggestion}?";
            // PCRE reads the whole escape before it looks the name up.
            $this->raise(
                \sprintf('Invalid or unsupported Unicode property: \\%s.', $key).(null === $hint ? '' : ' '.$hint),
                $node->getEndPosition(),
                ErrorCode::UnicodePropertyInvalid,
                $hint,
            );
        }
    }

    /**
     * Judge one token the way the node it would become is judged, for the
     * checks that need no other node: escaped letters, "\N{...}", character
     * types and properties.
     */
    private function token(Token $token, string $source, bool $inClass, bool $endsRange): void
    {
        if (TokenType::UnicodeNamed === $token->type) {
            $this->validateNamedCharacterBraces($source, $token->position + 2);
        }

        if (TokenType::CharType === $token->type) {
            $this->charType(new CharTypeNode($token->value, $token->position, $token->end()), $inClass);
        }

        if (TokenType::UnicodeProp === $token->type) {
            $this->property(new UnicodePropNode(
                $token->value,
                str_starts_with($token->value, '{'),
                $token->position,
                $token->end(),
                'P' === ($source[$token->position + 1] ?? ''),
            ));
        }

        $letter = $token->value;
        if (TokenType::LiteralEscaped === $token->type && 1 === \strlen($letter) && Ascii::isAlpha($letter)
            && '\\'.$letter === substr($source, $token->position, 2)) {
            $this->escapedLetter($letter, $token->position, $inClass, $endsRange);
        }
    }

    /**
     * "\p" and "\P" as PCRE2 10.39 reads them: one letter, or a braced name
     * of at most 31 characters after an optional "^", spelled exactly as its
     * table holds it. Longer, the sequence is malformed where PCRE stops
     * reading; unknown, it is refused past its "}".
     */
    private function validateExactPropertyName(UnicodePropNode $node, string $source): void
    {
        $letter = $source[$node->startPosition + 1] ?? 'p';
        $at = $node->startPosition + 2;
        $negated = false;
        if ('{' !== ($source[$at] ?? '')) {
            $name = $source[$at] ?? '';
            $end = $at + 1;
        } else {
            $at++;
            if ('^' === ($source[$at] ?? '')) {
                $negated = true;
                $at++;
            }

            $close = strpos($source, '}', $at);
            if (false === $close || $close - $at >= PropertyNames::MAX_NAME_LENGTH_BEFORE_10_40) {
                $this->raiseMalformedPropertyAt($letter, min(\strlen($source), $at + PropertyNames::MAX_NAME_LENGTH_BEFORE_10_40));
            }

            $name = substr($source, $at, $close - $at);
            $end = $close + 1;
        }

        if (PropertyNames::knownBefore1040($name, $this->target)) {
            return;
        }

        // A script a later Unicode added, which this release does not know.
        $needs = PropertyNames::releaseAddingBefore1040($name);
        if (null !== $needs) {
            $this->raise(
                \sprintf('Invalid or unsupported Unicode property: \\%s. It needs PCRE2 %s, and the target is PCRE2 %s.', substr($source, $node->startPosition + 1, $end - $node->startPosition - 1), $needs, $this->target->pcreVersion),
                $end,
                ErrorCode::UnicodePropertyInvalid,
                \sprintf('Target a PHP whose PCRE2 is %s or later, or use another property.', $needs),
            );
        }

        $unprefixed = LibraryPcre::replace('/^\s*+(?:sc|script|scx|script[\s_-]*+extensions)\s*+[:=]/i', '', $name) ?? $name;
        $spelling = PropertyNames::spellingBefore1040($unprefixed, $this->target);
        $this->raise(
            \sprintf('Invalid or unsupported Unicode property: \\%s. PCRE2 %s knows a property by one exact name only.', substr($source, $node->startPosition + 1, $end - $node->startPosition - 1), $this->target->pcreVersion),
            $end,
            ErrorCode::UnicodePropertyInvalid,
            null === $spelling
                ? 'Spell the name as PCRE2 10.39 lists it, as "Greek" or "Lu", or target a PHP whose PCRE2 is 10.40 or later.'
                : \sprintf('Write \\%s{%s%s}.', $letter, $negated ? '^' : '', $spelling),
        );
    }

    /**
     * The PCRE2 release that brought the property or script "\p{...}"
     * names, for a name whose support moved between releases; null for one
     * every release knows or refuses alike.
     */
    private function releaseAddingProperty(UnicodePropNode $node): ?string
    {
        $negated = $this->nameAfterSkippedCaret($node);
        if (null !== $negated) {
            $since = self::UNICODE_NAMES_SINCE[self::looseName($negated)] ?? '10.45';

            return version_compare($since, '10.45', '>') ? $since : '10.45';
        }

        $written = substr($this->source ?? '', $node->startPosition, $node->getEndPosition() - $node->startPosition);
        if (1 !== LibraryPcre::match('/^\\\\[pP]\{[ \t]*+\^?(.*)\}$/s', $written, $matches)) {
            return null;
        }

        return self::UNICODE_NAMES_SINCE[self::looseName($matches[1])] ?? null;
    }

    /**
     * The name after a "^" that spaces, hyphens or underscores put off the
     * brace, which PCRE2 skips: up to 10.44 that "^" is a letter of the
     * name, from 10.45 it negates. Null for any other spelling.
     */
    private function nameAfterSkippedCaret(UnicodePropNode $node): ?string
    {
        $written = substr($this->source ?? '', $node->startPosition, $node->getEndPosition() - $node->startPosition);

        return 1 === LibraryPcre::match('/^\\\\[pP]\{[ _\-\t-\r]++\^(.*)\}$/s', $written, $matches) ? $matches[1] : null;
    }

    /**
     * Whether the braced name of the property is negated, by its letter
     * and a "^", and the name past that "^", as the release reads them: a
     * "^" right after the brace negates, and from PCRE2 10.45 one after
     * spaces, hyphens or underscores too. Null when the text is not known.
     *
     * @return array{0: bool, 1: string}|null
     */
    private function negationRead(UnicodePropNode $node): ?array
    {
        $source = $this->source ?? '';
        $written = substr($source, $node->startPosition, $node->getEndPosition() - $node->startPosition);
        if (1 !== LibraryPcre::match('/^\\\\([pP])\{(.*)\}$/s', $written, $matches)) {
            return null;
        }

        [$letter, $name] = [$matches[1], $matches[2]];
        $caret = false;
        if (str_starts_with($name, '^')) {
            [$caret, $name] = [true, substr($name, 1)];
        } elseif ($this->target->supports(PcreFeature::MalformedPropertyName) && null !== ($after = $this->nameAfterSkippedCaret($node))) {
            [$caret, $name] = [true, $after];
        }

        return [('P' === $letter) !== $caret, $name];
    }

    /**
     * Loose matching: case, spaces, hyphens and underscores are ignored, and
     * "sc=", "scx:" and their long forms only name the table.
     */
    private static function looseName(string $name): string
    {
        $name = Ascii::lower(str_replace([' ', "\t", '-', '_'], '', $name));

        return LibraryPcre::replace('/^(?:sc|script|scx|scriptextensions)[:=]/', '', $name) ?? $name;
    }

    private function extractUnicodePropertyKey(string $key): string
    {
        // Strip the "p" or "P" the key opens with, after a backslash or not.
        $key = ltrim($key, '\\');
        if (str_starts_with($key, 'p') || str_starts_with($key, 'P')) {
            $key = substr($key, 1);
        }
        if (str_starts_with($key, '{') && str_ends_with($key, '}')) {
            return substr($key, 1, -1);
        }

        return $key;
    }

    private function suggestUnicodeProperty(string $key): ?string
    {
        $suggestions = [
            'Letter' => 'p{L}',
            'Number' => 'p{N}',
            'Punctuation' => 'p{P}',
            'Symbol' => 'p{S}',
            'Mark' => 'p{M}',
            'Separator' => 'p{Z}',
            'Other' => 'p{C}',
            'Control' => 'p{Cc}',
            'Format' => 'p{Cf}',
            'Surrogate' => 'p{Cs}',
            'Private_Use' => 'p{Co}',
            'Unassigned' => 'p{Cn}',
            'Lowercase_Letter' => 'p{Ll}',
            'Uppercase_Letter' => 'p{Lu}',
            'Titlecase_Letter' => 'p{Lt}',
            'Cased_Letter' => 'p{L&}',
            'Modifier_Letter' => 'p{Lm}',
            'Other_Letter' => 'p{Lo}',
            'Nonspacing_Mark' => 'p{Mn}',
            'Spacing_Mark' => 'p{Mc}',
            'Enclosing_Mark' => 'p{Me}',
            'Decimal_Number' => 'p{Nd}',
            'Letterlike_Number' => 'p{Nl}',
            'Other_Number' => 'p{No}',
            'Connector_Punctuation' => 'p{Pc}',
            'Dash_Punctuation' => 'p{Pd}',
            'Open_Punctuation' => 'p{Ps}',
            'Close_Punctuation' => 'p{Pe}',
            'Initial_Punctuation' => 'p{Pi}',
            'Final_Punctuation' => 'p{Pf}',
            'Other_Punctuation' => 'p{Po}',
            'Math_Symbol' => 'p{Sm}',
            'Currency_Symbol' => 'p{Sc}',
            'Modifier_Symbol' => 'p{Sk}',
            'Other_Symbol' => 'p{So}',
            'Space_Separator' => 'p{Zs}',
            'Line_Separator' => 'p{Zl}',
            'Paragraph_Separator' => 'p{Zp}',
            'Other_Separator' => 'p{Zo}',
        ];

        return $suggestions[$key] ?? null;
    }

    /**
     * Whether the engine that runs compiles the property: a name it does not
     * know is refused, whatever another regex flavour calls it.
     */
    private function compiles(string $key): bool
    {
        return null === $this->engine->compile("/^\\{$key}$/u");
    }

    private function validateUnicode(CharLiteralNode $node): void
    {
        // The code point is read from the escape as written.
        $rep = $node->originalRepresentation;

        if (1 === LibraryPcre::match('/^\\\\x([0-9a-fA-F]{1,2})$/', $rep, $m)) {
            $codePoint = (int) hexdec($m[1]);
        } elseif (1 === LibraryPcre::match('/^\\\\u([0-9a-fA-F]{4})$/', $rep, $m)) {
            $codePoint = (int) hexdec($m[1]);
        } elseif (1 === LibraryPcre::match('/^\\\\(x|u)\\{[ \t]*+([0-9a-fA-F]+)[ \t]*+\\}$/', $rep, $m)) {
            // Past six digits the value is past U+10FFFF, and may be past
            // what an integer holds.
            $codePoint = \strlen(ltrim($m[2], '0')) > 6 ? 0x110000 : (int) hexdec($m[2]);
        } else {
            return; // A spelling of no code point: nothing to judge.
        }

        // PCRE refuses the value where codePointOverflow() says.
        $overflow = '{' === ($rep[2] ?? '') ? $this->codePointOverflow($rep, 3, self::HEX_DIGITS) : null;
        if (null !== $overflow && $codePoint > 0x10FFFF) {
            $this->raise(
                \sprintf('Invalid Unicode codepoint "%s" (out of range).', $node->originalRepresentation),
                $node->startPosition + $overflow,
                ErrorCode::UnicodeOutOfRange,
            );
        }

        // Without Unicode mode a character is one byte, as for "\o{400}".
        if (null !== $overflow) {
            $this->raise(
                \sprintf('Invalid code point "%s": without the "u" flag, a character is at most \xFF.', $node->originalRepresentation),
                $node->startPosition + $overflow,
                ErrorCode::OctalOutOfRange,
                'Add the "u" flag, or use a code point up to \xFF.',
            );
        }

        // "A" and "\u{41}" are JavaScript: PCRE2 refuses "\u" outright.
        // What was written is read from the source, so a node built by hand
        // is not judged on its spelling.
        if (null !== $this->source && '\\u' === substr($this->source, $node->startPosition, 2)) {
            $this->raiseUnsupportedEscape('u', $node->startPosition + 2);
        }
    }

    private function validateOctal(CharLiteralNode $node): void
    {
        // Without /u, PCRE limits \o{} to single-byte values (0-255); in
        // Unicode mode any valid codepoint is allowed. It refuses the value
        // where codePointOverflow() says.
        // A node built by hand may hold a value its spelling does not: it is
        // refused at its closing brace.
        $overflow = $this->codePointOverflow($node->originalRepresentation, 3, self::OCTAL_DIGITS)
            ?? ($node->codePoint > ($this->utf ? 0x10FFFF : 0xFF) ? $node->getEndPosition() - 1 - $node->startPosition : null);
        if (null !== $overflow) {
            $this->raise(
                \sprintf($this->utf ? 'Invalid octal codepoint "%s" (out of Unicode range).' : 'Invalid octal codepoint "%s".', $node->originalRepresentation),
                $node->startPosition + $overflow,
                ErrorCode::OctalOutOfRange,
            );
        }
    }

    /**
     * Where PCRE refuses a code point too large among the digits of
     * "\x{...}", "\o{...}" or "\N{U+...}" that start at $at in $text, past
     * the spaces and tabs after the "{" from PCRE2 10.43: it stops on the
     * digit that takes the value past U+10FFFF under UTF, past 0xFF
     * otherwise, skips the spaces and tabs after it from 10.43, then the
     * digits left, and refuses it there, whatever follows. Null when no
     * digit takes it past.
     */
    private function codePointOverflow(string $text, int $at, string $digits): ?int
    {
        $padded = $this->target->supports(PcreFeature::PaddedBracedEscapes);
        $at += $padded ? strspn($text, self::BRACE_PADDING, $at) : 0;
        $base = self::HEX_DIGITS === $digits ? 16 : 8;
        $max = $this->utf ? 0x10FFFF : 0xFF;
        $value = 0;
        while (1 === strspn($text, $digits, $at, 1)) {
            $value = $value * $base + (int) hexdec($text[$at]);
            $at++;
            if ($value > $max) {
                $at += $padded ? strspn($text, self::BRACE_PADDING, $at) : 0;

                return $at + strspn($text, $digits, $at);
            }
        }

        return null;
    }

    private function validateOctalLegacy(CharLiteralNode $node): void
    {
        // Without Unicode mode PCRE limits legacy octal to one byte, \377;
        // in Unicode mode "\400" to "\777" are code points like any other.
        if (!$this->utf && $node->codePoint > 0xFF) {
            $this->raise(
                \sprintf('Invalid legacy octal codepoint "%s" (out of range).', $node->originalRepresentation),
                // PCRE reads the whole escape first, in every release.
                $node->getEndPosition(),
                ErrorCode::OctalOutOfRange,
            );
        }
    }

    private function validateUnicodeNamed(CharLiteralNode $node): void
    {
        // Extract the Unicode name from the representation. Unreachable from
        // a parsed pattern: the parser always spells a non-empty "\N{...}".
        if (1 !== LibraryPcre::match('/^\\\\N\\{(.+)}$/', $node->originalRepresentation, $matches)) {
            throw new ParserException("Invalid Unicode named character format: {$node->originalRepresentation}", ErrorCode::EscapeUnsupported, $node->getStartPosition(), $this->pattern);
        }

        $name = $matches[1];

        // PCRE only supports \N{U+hhhh} in Unicode (/u) mode, "\N{U+ }"
        // included.
        if (!$this->utf && 1 === LibraryPcre::match('/^[ \t]*+U\+(?:[0-9A-Fa-f]++[ \t]*+|[ \t]++)$/', $name)) {
            $this->raise(
                \sprintf('\N{%s} is only supported in Unicode mode; add the "u" flag.', $name),
                // From PCRE2 10.47, PCRE reads the escape to its closing
                // brace before it looks at the mode; before, past the "\N".
                $this->target->supports(PcreFeature::NamedCodePointReadBeforeModeCheck) ? $node->getEndPosition() : $node->startPosition + 2,
                ErrorCode::UnicodeNamedRequiresUtf,
            );
        }

        // As for "\x{...}", PCRE refuses a value above U+10FFFF where
        // codePointOverflow() says.
        $overflow = $this->codePointOverflow($node->originalRepresentation, 3 + strspn($name, self::BRACE_PADDING) + 2, self::HEX_DIGITS);
        if (null !== $overflow) {
            $this->raise(
                \sprintf('Invalid Unicode codepoint "%s" (out of range).', $node->originalRepresentation),
                $node->startPosition + $overflow,
                ErrorCode::UnicodeOutOfRange,
            );
        }

        // If the codePoint is -1, the name could not be resolved. PCRE refuses
        // a name it does not take once it has read "\N{". Unreachable from a
        // parsed pattern: characterLiteral() refuses every "\N{...}" that is
        // no "U+" code point before it gets here.
        if (-1 === $node->codePoint) {
            throw new ParserException("Invalid Unicode character name: {$name}", ErrorCode::EscapeUnsupported, $node->getStartPosition() + 3, $this->pattern);
        }
    }

    /**
     * Spaces inside "\x{ 41 }", "\o{ 101 }" and "\N{ U+41 }" arrived in
     * PCRE2 10.43; PHP 8.2 and 8.3 bundle 10.40 and 10.42, which refuse
     * them. PCRE reports the first space.
     */
    private function validatePaddedBraces(CharLiteralNode $node): void
    {
        $representation = $node->originalRepresentation;
        $space = strcspn($representation, " \t");
        if ($space === \strlen($representation) || $this->target->supports(PcreFeature::PaddedBracedEscapes)) {
            return;
        }

        // A space before "U+", which makes "\N{" a name, is refused before.
        [$code, $escape] = match (true) {
            CharLiteralType::Octal === $node->type => [ErrorCode::OctalInvalidDigit, '\o{}'],
            CharLiteralType::UnicodeNamed === $node->type => [ErrorCode::UnicodeInvalidDigit, '\N{U+}'],
            default => [ErrorCode::UnicodeInvalidDigit, '\x{}'],
        };

        $this->raise(
            \sprintf('Spaces inside %s need PCRE2 10.43, which PHP bundles from 8.4.', $escape),
            $node->startPosition + $space,
            $code,
            'Write the escape without spaces, or target PHP 8.4+.',
        );
    }

    /**
     * "\p" or "\P" the lexer read as a letter: no property letter and no
     * closed braced name follows it. PCRE reads one more character, or an
     * unclosed brace up to the end of the pattern, before it gives up.
     */
    private function raiseMalformedProperty(string $source, string $letter, int $position): never
    {
        if ('{}' === substr($source, $position, 2)) {
            $this->raise(
                \sprintf('Invalid or unsupported Unicode property: \\%s{}.', $letter),
                $position + 2,
                ErrorCode::UnicodePropertyInvalid,
            );
        }

        $offset = match (true) {
            $position >= \strlen($source) => $position,
            '{' === $source[$position] => $this->malformedPropertyNameEnd($source, $position) ?? \strlen($source),
            default => $this->pastTheCharacter($source, $position),
        };

        $this->raiseMalformedPropertyAt($letter, $offset);
    }

    /**
     * Past the character at $position: under UTF, up to PCRE2 10.46 one
     * byte past its start, from 10.47 past all of it.
     */
    private function pastTheCharacter(string $source, int $position): int
    {
        $width = $this->characterLengthAt($source, $position);

        return $this->target->faultOffset($position + $width, $width - 1);
    }

    private function raiseMalformedPropertyAt(string $letter, int $offset): never
    {
        $this->raise(
            \sprintf('Malformed \\%s sequence: a property letter or a braced name must follow it.', $letter),
            $offset,
            ErrorCode::UnicodePropertyMalformed,
            \sprintf('Name a property, as in "\\%1$sL" or "\\%1$s{Lu}", or drop the backslash for a literal "%1$s".', $letter),
        );
    }

    /**
     * Where PCRE gives up on the property name its brace at $brace opens:
     * past the most characters it reads of one, at the end of the pattern,
     * or, from PCRE2 10.45, past the first character no name holds. Spaces,
     * "_" and "-" are skipped, and so is one leading "^". Null for a name
     * closed in time.
     */
    private function malformedPropertyNameEnd(string $source, int $brace): ?int
    {
        $length = \strlen($source);

        // Before PCRE2 10.40, the name is read as written, spaces included,
        // into a buffer of PropertyNames::MAX_NAME_LENGTH_BEFORE_10_40 bytes.
        if (!$this->target->supports(PcreFeature::LoosePropertyNames)) {
            $start = $brace + 1 + ('^' === ($source[$brace + 1] ?? '') ? 1 : 0);
            $close = strpos($source, '}', $start);
            if (false !== $close && $close - $start < PropertyNames::MAX_NAME_LENGTH_BEFORE_10_40) {
                return null;
            }

            return min($length, $start + PropertyNames::MAX_NAME_LENGTH_BEFORE_10_40);
        }

        $position = $brace + 1;
        $negated = false;
        $read = 0;

        while ($read < self::MAX_PROPERTY_NAME_LENGTH) {
            if ($position >= $length) {
                return $length;
            }

            // Up to PCRE2 10.46 the name is read byte by byte, from 10.47
            // character by character.
            $char = $source[$position];
            $position = $this->pastTheCharacter($source, $position);

            if (str_contains(" _-\t\n\v\f\r", $char)) {
                continue;
            }

            // Up to PCRE2 10.44 only a "^" right after the brace negates.
            if (0 === $read && !$negated && '^' === $char && ($position === $brace + 2 || $this->target->supports(PcreFeature::MalformedPropertyName))) {
                $negated = true;

                continue;
            }

            if ('}' === $char) {
                return null;
            }

            // Names hold "&" to "z" only; 10.45 and 10.46 stop past the
            // first byte of a longer UTF-8 character, 10.47 past all of it.
            if (($char < '&' || $char > 'z') && $this->target->supports(PcreFeature::MalformedPropertyName)) {
                return $position;
            }

            $read++;
        }

        return $position;
    }

    /**
     * "\o" takes its digits in braces, always.
     */
    private function validateOctalBraces(string $source, int $position): void
    {
        // At the end of the pattern PCRE steps back onto the "o", before
        // PCRE2 10.47.
        if ('{' !== ($source[$position] ?? '')) {
            $this->raise(
                'Missing opening brace after \o.',
                $position >= \strlen($source) ? $this->target->faultOffset($position) : $position,
                ErrorCode::OctalMissingBrace,
            );
        }

        $this->validateBracedDigits($source, $position + 1, self::OCTAL_DIGITS, true, ErrorCode::OctalInvalidDigit, '\o{}');
    }

    /**
     * A bare "\x" is left alone: PCRE2 releases disagree on it.
     */
    private function validateHexBraces(string $source, int $position): void
    {
        if ('{' === ($source[$position] ?? '')) {
            $this->validateBracedDigits($source, $position + 1, self::HEX_DIGITS, true, ErrorCode::UnicodeInvalidDigit, '\x{}');
        } elseif ($this->target->supports(PcreFeature::HexEscapeNeedsDigits)) {
            // A "\x" with no digit is "\x00" up to PCRE2 10.44 and an error
            // from 10.45, which no PHP release bundles yet: only a newer
            // linked PCRE2 refuses it.
            $this->raise('Digits missing after \x.', $position, ErrorCode::EscapeDigitsMissing);
        }
    }

    /**
     * "\N{" outside a class that the lexer did not read as a named character:
     * a repeat count, a malformed "\N{U+...}", or a name PCRE2 refuses.
     */
    private function validateNamedCharacterBraces(string $source, int $position): void
    {
        // Unreachable: the lexer only leaves "\N" as an escaped letter when
        // a brace follows it, and inside a class "{U+" is checked first. It
        // guards a caller that did not check.
        if ('{' !== ($source[$position] ?? '')) {
            return;
        }

        if ($this->startsNamedCodePoint($source, $position)) {
            $digits = $position + 1 + strspn($source, self::BRACE_PADDING, $position + 1) + 2;

            if (!$this->utf) {
                // From PCRE2 10.47, PCRE reads the hex digits right after
                // "U+", the padding after them and a "}" before it looks at
                // the mode; before, it stops past the "\N".
                $end = $digits + strspn($source, self::HEX_DIGITS, $digits);
                $end += strspn($source, self::BRACE_PADDING, $end);
                $this->raise(
                    '\N{U+...} is only supported in Unicode mode; add the "u" flag.',
                    $this->target->supports(PcreFeature::NamedCodePointReadBeforeModeCheck) ? ('}' === ($source[$end] ?? '') ? $end + 1 : $end) : $position,
                    ErrorCode::UnicodeNamedRequiresUtf,
                );
            }

            $this->validateBracedDigits($source, $digits, self::HEX_DIGITS, false, ErrorCode::UnicodeInvalidDigit, '\N{U+}');

            // Unreachable: a well-formed "\N{U+...}", "\N{U+ }" included, is
            // a token of its own, so the digits above are always refused.
            return;
        }

        // Before PCRE2 10.37 the digits are read as a count first, and one
        // past 65535 refused past the "\N", whatever follows it.
        if (!$this->target->supports(PcreFeature::LargeOpenCountIsText) && null !== RepeatCount::overflowBeforeTen37($source, $position)) {
            $this->raise('Number too big in the count after \N: PCRE allows at most 65535 repetitions.', $position, ErrorCode::QuantifierTooBig);
        }

        if (1 !== LibraryPcre::match(self::REPEAT_COUNT, $source, $matches, 0, $position)) {
            $this->raiseUnsupportedEscape('N{', $this->target->faultOffset($position + 1));
        }
    }

    /**
     * Whether "{U+" follows, which PCRE2 10.43 also reads with spaces or tabs
     * after the brace: "\N{ U+41}". Before, "\N{ " names a character.
     */
    private function startsNamedCodePoint(string $source, int $position): bool
    {
        $padding = $this->target->supports(PcreFeature::PaddedBracedEscapes) ? strspn($source, self::BRACE_PADDING, $position + 1) : 0;

        return '{' === ($source[$position] ?? '') && 'U+' === substr($source, $position + 1 + $padding, 2);
    }

    /**
     * Read the digits of a braced escape the way PCRE2 10.48 does: spaces and
     * tabs may pad them, at least one digit is needed, and the first other
     * character must be the closing brace.
     *
     * @param bool $leadingPadding whether padding may precede the digits;
     *                             where PCRE2's reading of it is unsettled,
     *                             the escape is not judged
     */
    private function validateBracedDigits(
        string $source,
        int $position,
        string $digits,
        bool $leadingPadding,
        ErrorCode $invalidDigitCode,
        string $escape,
    ): void {
        $length = \strlen($source);
        // Before PCRE2 10.43 a space or a tab is no digit, refused on it.
        $padded = $this->target->supports(PcreFeature::PaddedBracedEscapes);

        if ($leadingPadding) {
            $position += $padded ? strspn($source, self::BRACE_PADDING, $position) : 0;
        } elseif ($position < $length && 1 === strspn($source, self::BRACE_PADDING, $position, 1)) {
            // Padding right after "U+": before PCRE2 10.43 it is refused on
            // its first character; from 10.43 it may only run up to the
            // closing brace, "\N{U+ }", and anything else is refused past
            // the first character that is not padding.
            if (!$padded) {
                $this->raise(
                    \sprintf('Spaces inside %s need PCRE2 10.43, which PHP bundles from 8.4.', $escape),
                    $position,
                    $invalidDigitCode,
                    'Write the escape without spaces, or target PHP 8.4+.',
                );
            }

            $position += strspn($source, self::BRACE_PADDING, $position);
            if ('}' === ($source[$position] ?? '')) {
                return;
            }

            $this->raise(
                \sprintf('Invalid character in %s, or closing brace missing.', $escape),
                $this->braceFaultOffset($source, $position),
                $invalidDigitCode,
            );
        }

        if ($position >= $length || '}' === $source[$position]) {
            $this->raise(
                \sprintf('Digits missing in %s.', $escape),
                $position,
                ErrorCode::EscapeDigitsMissing,
            );
        }

        // A value too large is refused first, whatever follows its digits.
        $overflow = $this->codePointOverflow($source, $position, $digits);
        if (null !== $overflow) {
            $this->raise(
                \sprintf('Code point too large in %s.', $escape),
                $overflow,
                '\o{}' === $escape || ('\x{}' === $escape && !$this->utf) ? ErrorCode::OctalOutOfRange : ErrorCode::UnicodeOutOfRange,
            );
        }

        $digitsStart = $position;
        $position += strspn($source, $digits, $position);
        $value = substr($source, $digitsStart, $position - $digitsStart);
        $position += $padded ? strspn($source, self::BRACE_PADDING, $position) : 0;

        // From PCRE2 10.47 a surrogate under UTF is refused there too, past
        // the spaces and tabs after its digits; no larger than U+10FFFF, or
        // refused above.
        $codePoint = (int) ('\o{}' === $escape ? octdec($value) : hexdec($value));
        if ($this->utf && $codePoint >= 0xD800 && $codePoint <= 0xDFFF && $this->target->supports(PcreFeature::SurrogateRefusedBeforeBrace)) {
            $this->raise(
                \sprintf('Invalid code point in %s: a surrogate, which UTF-8 cannot hold.', $escape),
                $position,
                ErrorCode::UnicodeSurrogate,
            );
        }

        // Unreachable from a parsed pattern: the lexer reads every braced
        // escape of this shape as a token of its own, so only a malformed one
        // gets here. It guards a caller that passes a well-formed one.
        if ($position < $length && '}' === $source[$position]) {
            return;
        }

        $unreadPadding = !$padded && 1 === strspn($source, self::BRACE_PADDING, $position, 1);
        $this->raise(
            $unreadPadding ? \sprintf('Spaces inside %s need PCRE2 10.43, which PHP bundles from 8.4.', $escape) : \sprintf('Invalid character in %s, or closing brace missing.', $escape),
            $this->braceFaultOffset($source, $position),
            $invalidDigitCode,
            $unreadPadding ? 'Write the escape without spaces, or target PHP 8.4+.' : null,
        );
    }

    /**
     * Where PCRE refuses the character at $position inside braced digits:
     * past it from PCRE2 10.47, on it before. At the end of the pattern, on
     * the last character before 10.47, past the end on 10.47, and at the
     * end from 10.48.
     */
    private function braceFaultOffset(string $source, int $position): int
    {
        $length = \strlen($source);
        if ($position >= $length) {
            return $this->target->supports(PcreFeature::UnclosedBraceAtPatternEnd) ? $length : $this->target->faultOffset($length + 1, 2);
        }

        return $this->target->faultOffset($position + $this->characterLengthAt($source, $position), $this->characterLengthAt($source, $position));
    }

    private function raiseUnsupportedEscape(string $escape, int $position): never
    {
        $this->raise(
            \sprintf('PCRE does not support the escape "\%s" (\F, \L, \l, \N{name}, \U and \u are not supported).', $escape),
            $position,
            ErrorCode::EscapeUnsupported,
        );
    }

    /**
     * The width of the character at an offset, which is how far PCRE steps
     * past it: one byte, or a whole UTF-8 sequence in Unicode mode.
     */
    private function characterLengthAt(string $source, int $position): int
    {
        return $this->utf ? Utf8::width($source, $position) : 1;
    }

    private function raise(string $message, int $position, ErrorCode $code, ?string $hint = null): never
    {
        throw new SemanticErrorException($message, $code, $position + $this->offset, $this->pattern, null, $hint);
    }
}
