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

use PHPRegex\Parser\AbstractNodeVisitor;
use PHPRegex\Parser\Analysis\GroupIndex;
use PHPRegex\Parser\Analysis\GroupNumbering;
use PHPRegex\Parser\Analysis\LengthRangeCalculator;
use PHPRegex\Parser\Analysis\LookbehindLength;
use PHPRegex\Parser\Cache\StaticCaches;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ClassSetOperationNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Node\VersionConditionNode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\PhpVersionGates;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Syntax\BadEscape;
use PHPRegex\Parser\Syntax\InlineFlags;
use PHPRegex\Parser\Syntax\NameCharacters;
use PHPRegex\Parser\Syntax\NamedCharacterBrace;
use PHPRegex\Parser\Syntax\PcreVerb;
use PHPRegex\Parser\Syntax\PosixItem;
use PHPRegex\Parser\Syntax\PropertyNames;
use PHPRegex\Parser\Syntax\RepeatCount;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Syntax\VersionCondition;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\Bytes;
use PHPRegex\Parser\Text\DisplayEscaper;
use PHPRegex\Parser\Text\NearMiss;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenType;

/**
 * Validator for regex Abstract Syntax Trees with caching and optimization.
 *
 * This validator provides semantic validation while minimizing
 * computational overhead through caching and streamlined validation logic.
 *
 * @extends AbstractNodeVisitor<void>
 *
 * @internal
 *
 * @phpstan-import-type LengthRange from LengthRangeCalculator
 */
final class Validator extends AbstractNodeVisitor
{
    /**
     * How deep PCRE2 lets parentheses nest by default, which PHP keeps.
     */
    public const MAX_GROUP_NESTING = 250;
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
     * The longest (*MARK), (*PRUNE), (*SKIP) or (*THEN) name, in code units.
     */
    private const MAX_VERB_NAME_LENGTH = 255;

    /**
     * The most characters PCRE reads of a braced property name.
     */
    private const MAX_PROPERTY_NAME_LENGTH = 49;

    /**
     * The largest (*LIMIT_...=n) value PCRE still multiplies by ten.
     */
    private const MAX_LIMIT_VALUE_BEFORE_DIGIT = 429496728;

    // Precomputed validation sets for maximum performance
    private const VALID_ASSERTIONS = [
        'A' => true, 'z' => true, 'Z' => true,
        'G' => true, 'b' => true, 'B' => true,
    ];

    /**
     * The verbs PCRE2 reads anywhere in the pattern; the settings of the
     * opening run are StartOptions::NAMES.
     */
    private const VALID_PCRE_VERBS = [
        // Backtracking control verbs
        'FAIL' => true, 'F' => true, 'ACCEPT' => true, 'COMMIT' => true,
        'PRUNE' => true, 'SKIP' => true, 'THEN' => true,
        // Definition verb
        'DEFINE' => true,
        // Mark verb (with optional :NAME argument)
        'MARK' => true,
        // Script run verbs (also as lowercase aliases)
        'script_run' => true, 'sr' => true,
        'atomic_script_run' => true, 'asr' => true,
    ];

    /**
     * Start-of-pattern settings PCRE2 10.45 added: settings where the PCRE2
     * judged is 10.45 or newer, unknown verbs on an older one, whether that
     * is the library PHP runs with or the one a targeted PHP version bundles.
     */
    private const PCRE_1045_SETTINGS = ['CASELESS_RESTRICT' => true, 'TURKISH_CASING' => true];

    /**
     * The start-of-pattern settings that take a number: "(*LIMIT_MATCH=10)".
     */
    private const LIMIT_VERBS = [
        'LIMIT_MATCH' => true, 'LIMIT_RECURSION' => true, 'LIMIT_DEPTH' => true, 'LIMIT_HEAP' => true,
    ];

    /**
     * PCRE's own ceiling on a fixed-length lookbehind, the same on every
     * PCRE2 release; max_lookbehind_length only bounds variable ones.
     */
    private const MAX_FIXED_LOOKBEHIND_LENGTH = 65535;

    /**
     * How many branches PCRE measures across all the lookbehinds of a
     * pattern before it gives up: each branch of a lookbehind, and each
     * branch of a group it reaches. Without a branch reset it measures a
     * capturing group once; with one, each time it meets it.
     */
    private const MAX_LOOKBEHIND_BRANCH_MEASURES = 2001;

    /**
     * A group name in Unicode mode, and in any mode once read by the lexer.
     */
    private const GROUP_NAME = '[_\p{L}][_\p{L}\p{Nd}]*+';

    private const VALID_POSIX_CLASSES = [
        'alnum' => true, 'alpha' => true, 'ascii' => true,
        'blank' => true, 'cntrl' => true, 'digit' => true,
        'graph' => true, 'lower' => true, 'print' => true,
        'punct' => true, 'space' => true, 'upper' => true,
        'word' => true, 'xdigit' => true,
    ];

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

    // Optimized state management with minimal memory footprint

    private bool $unicodeMode = false;

    /**
     * Whether the "u" flag itself is set: some refusals come from PHP's
     * handling of the flag, not from Unicode mode, so "(*UTF)" does not
     * trigger them.
     */
    private bool $unicodeFlag = false;

    /**
     * Whether the pattern is compiled with PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL,
     * as PHP 7.3 and 7.4 compile it without "X": an escape PCRE2 refuses as
     * it reads it is the character after the backslash, which the lexer
     * gave as that character.
     */
    private bool $badEscapeIsLiteral = false;

    /**
     * Whether the node visited ends a range of a class.
     */
    private bool $endsRange = false;

    /**
     * The pattern body the tree was parsed from, for what no node records:
     * whether a letter was escaped, and what follows an escape the lexer did
     * not recognize. Null where positions do not point into it.
     */
    private ?string $source = null;

    private int $charClassDepth = 0;

    /**
     * Whether a "\Q" written in the class being read is still open before
     * the item at hand.
     */
    private bool $classQuoteOpen = false;

    /**
     * Where the run of start-of-pattern settings ends in the source, or null
     * when there is no source to read it from.
     */
    private ?int $startOfPatternEnd = null;

    /**
     * The capturing groups by number and by name, and where each call or
     * reference sits in their count.
     */
    private GroupIndex $groups;

    /**
     * Measures a lookbehind branch, telling the validator of what it meets.
     */
    private LookbehindLength $lookbehindLength;

    private int $lookbehindBranchMeasures = 0;

    /**
     * The lookbehind being measured, where PCRE reports the measure it gave
     * up.
     */
    private ?GroupNode $measuredLookbehind = null;

    /**
     * Whether the walk judges the part of a pattern read before a syntax
     * error: every error a lookbehind's measure finds waits for the whole
     * pattern to be read, so there lookbehinds are not measured.
     */
    private bool $readingPrefix = false;

    /**
     * Where the text the visited nodes count their positions from starts in
     * the whole pattern: 0, or the start of the payload of the "(*pla:...)"
     * or "(*sr:...)" being visited, which is parsed apart.
     */
    private int $positionOffset = 0;

    /**
     * For each lookaround the node being visited sits in, innermost last,
     * where the first `\K` in it stands, if one does.
     *
     * @var list<int|null>
     */
    private array $keepsInLookarounds = [];

    /**
     * How many repeated items the node being visited sits in.
     */
    private int $repeatDepth = 0;

    /**
     * How many lookbehinds the node being visited sits in.
     */
    private int $lookbehindDepth = 0;

    /**
     * How many lookaheads inside a lookbehind the walk is in: before PCRE2
     * 10.34, PCRE never measures a lookbehind there.
     */
    private int $lookaheadsInLookbehinds = 0;

    /**
     * Whether PCRE fails on an operator of an extended class its walk over
     * the lookbehinds meets: PCRE2 10.45 to 10.47, in a pattern that holds
     * a lookbehind.
     */
    private bool $classOperatorsFail = false;

    /**
     * The lookarounds and substring scans the walk is in, innermost last:
     * true for a lookbehind.
     *
     * @var list<bool>
     */
    private array $lookaroundKinds = [];

    /**
     * @var list<GroupNode> the lookbehinds around the node visited, innermost last
     */
    private array $lookbehinds = [];

    /**
     * How many groups, conditionals and script runs enclose the node.
     */
    private int $nestingDepth = 0;

    /**
     * Whether the walk started from the pattern root, and so ends where the
     * errors PCRE finds late can be reported.
     */
    private bool $walkingPattern = false;

    /**
     * The length of the pattern body being walked.
     */
    private int $patternLength = 0;

    /**
     * Whether a lookbehind is being measured: a missing group ends the
     * measure there, as it ends PCRE's.
     */
    private bool $measuringLookbehind = false;

    /**
     * The first error of each late pass, kept until the walk ends: [0] the
     * pass that measures lookbehinds, [1] the one that resolves references
     * to groups by number, by name or forward, [2] the count of branches
     * of conditionals. PCRE runs them only once it has read the whole
     * pattern, so any other error comes first.
     *
     * @var array<int, SemanticErrorException>
     */
    private array $lateErrors = [];

    /**
     * Where the first pass of the compiler meets the error kept for it in
     * $lateErrors[1]: the earliest of them is the one it reports, unless the
     * pattern passes the size limit before it.
     */
    private int $firstPassErrorAt = \PHP_INT_MAX;

    /**
     * Where the reference, the call, the conditional or the scan being
     * checked starts: where the first pass meets a group it names, whatever
     * offset reports it.
     */
    private int $referenceAt = 0;

    private GroupNumbering $groupNumbering;

    private ?NodeInterface $previousNode = null;

    private ?NodeInterface $nextNode = null;

    /**
     * @var array<string, bool>
     */
    private static array $unicodePropCache = [];

    private readonly PcreTarget $target;

    /**
     * @param PcreTarget|null $target the PHP and PCRE2 judged; the running ones when null
     * @param PcreEngine      $engine asks the running engine which property names it knows
     */
    public function __construct(
        private readonly int $maxLookbehindLength = RegexParser::DEFAULT_MAX_LOOKBEHIND_LENGTH,
        private readonly ?string $pattern = null,
        ?PcreTarget $target = null,
        private readonly PcreEngine $engine = new PcreEngine(),
    ) {
        $this->target = $target ?? PcreTarget::runtime();
        $this->groups = GroupIndex::empty();
        $this->lookbehindLength = $this->measuring($this->groups);
    }

    /**
     * Clears static caches. Useful for long-running processes or testing.
     */
    public static function clearCaches(): void
    {
        self::$unicodePropCache = [];
        QuantifierCounts::clearCaches();
    }

    /**
     * The first escape PCRE refuses among the tokens that start before
     * $limit, in a pattern whose structure could not be read: PCRE reads
     * escapes and structure in one pass, so it stops on such an escape
     * before it reaches the error at $limit.
     *
     * @internal
     *
     * @param array<Token> $tokens the tokens read from $source
     */
    public function firstEscapeErrorBefore(array $tokens, string $source, string $flags, int $limit): ?SemanticErrorException
    {
        $this->source = $source;
        $this->positionOffset = 0;
        $this->charClassDepth = 0;
        $this->unicodeFlag = str_contains($flags, 'u');
        $this->unicodeMode = $this->unicodeFlag || StartOptions::turnUtfOn($source);
        $this->badEscapeIsLiteral = PhpVersionGates::badEscapeIsLiteral($this->target->phpVersionId, $flags);

        // Before PCRE2 10.45, "\N", "\z" or "\p" ending a range is refused as
        // the range, before what follows its letter is read.
        $nEndsRange = !$this->supports(PcreFeature::ClassEscapeEndingRangeRefusedAsEscape);
        $pcre2Ten32 = !$this->supports(PcreFeature::BadEscapeInClassStaysRefused);
        $afterRange = false;
        $rangeEnded = false;
        // Up to PCRE2 10.44 a quote after a class escape keeps a "-" after
        // it a member: "[\h\E-\pL]".
        $quoteKeepsHyphen = !$this->supports(PcreFeature::EmptyQuoteSkippedAfterClassEscape);
        $classEscapeThenQuote = false;
        $classEscape = false;

        try {
            foreach ($tokens as $token) {
                // An escape that starts on the character at fault is not
                // read: PCRE2 10.47 reports past that character.
                if ($this->pastTheFault($token->position + 1) >= $limit) {
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
                    : $endsRange && ($this->unicodeMode || !\in_array($token->type, [TokenType::Literal, TokenType::LiteralEscaped], true) || 1 === \strlen($token->value));
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
                    && !($this->badEscapeIsLiteral && BadEscape::at($source, $token->position, true, $this->unicodeMode, $this->target, null, true))) {
                    $this->raiseSemanticError(
                        \sprintf('Invalid range in character class: "\%s" cannot end a range, at position %d.', $source[$token->position + 1], $token->position + 2),
                        $token->position + 2,
                        ErrorCode::RangeInvalidBounds,
                    );
                }

                $this->endsRange = $endsRange;
                $this->validateEscapeToken($token, $source);
            }
        } catch (SemanticErrorException $error) {
            return $error;
        }

        return null;
    }

    /**
     * The error PCRE finds in a character class, parsed alone, that closes
     * at $closingAt: none unless PCRE reads the whole class before $limit,
     * the offset where parsing failed.
     */
    public function firstErrorInClassBefore(RegexNode $class, int $closingAt, int $limit): ?SemanticErrorException
    {
        if ($this->pastTheFault($closingAt + 1) > $limit) {
            return null;
        }

        try {
            $class->accept($this);
        } catch (SemanticErrorException $error) {
            return $error;
        }

        return null;
    }

    /**
     * The first error PCRE meets as it reads $prefix, the part of a pattern
     * read whole before the syntax error at $limit, its groups closed where
     * it was cut: what PCRE only finds once it has read the whole pattern
     * waits for that, and is not reported here.
     *
     * @internal
     */
    public function firstErrorReadBefore(RegexNode $prefix, int $limit): ?SemanticErrorException
    {
        $this->readingPrefix = true;

        try {
            $this->walkPattern($prefix);
        } catch (SemanticErrorException $error) {
            return ($error->position ?? $limit) < $limit ? $error : null;
        } finally {
            $this->readingPrefix = false;
        }

        return null;
    }

    #[\Override]
    public function visitRegex(RegexNode $node): void
    {
        $this->walkPattern($node);

        // PCRE measures the compiled pattern in its first pass; one that
        // cannot reach the limit by its text alone is not measured.
        $size = CompiledSize::belowTheLimit($node) ? null : new CompiledSize($node, $this->unicodeMode, $this->target);
        $tooLarge = null !== $size && $size->isTooLarge() ? $size : null;

        // The lookbehinds are measured before the first pass, which stops
        // where the pattern passes the limit: an error it would meet past
        // that point is never reached, nor the second pass, which counts
        // the branches of conditionals.
        if (isset($this->lateErrors[0]) || ([] !== $this->lateErrors && (null === $tooLarge || $this->firstPassErrorAt < $tooLarge->firstPassStop()))) {
            $this->raiseFirstLateError();
        }

        if (null !== $tooLarge) {
            $this->raiseSemanticError(
                'Regular expression is too large: PCRE would compile it to more than 64 KiB.',
                $tooLarge->errorOffset(),
                ErrorCode::PatternTooLarge,
                'A group repeated with a count is compiled once per repetition: lower the count, or repeat a single item.',
            );
        }
    }

    #[\Override]
    public function visitAlternation(AlternationNode $node): void
    {
        $previous = $this->previousNode;
        $next = $this->nextNode;

        foreach ($node->alternatives as $alt) {
            $this->previousNode = null;
            $this->nextNode = null;
            $alt->accept($this);
        }

        $this->previousNode = $previous;
        $this->nextNode = $next;
    }

    #[\Override]
    public function visitSequence(SequenceNode $node): void
    {
        $previous = $this->previousNode;
        $next = $this->nextNode;
        $children = $node->children;
        $total = \count($children);
        $last = null;

        foreach ($children as $index => $child) {
            $this->previousNode = $last;
            $this->nextNode = $index + 1 < $total ? $children[$index + 1] : null;
            $child->accept($this);
            $last = $child;
        }

        $this->previousNode = $previous;
        $this->nextNode = $next;
    }

    #[\Override]
    public function visitGroup(GroupNode $node): void
    {
        $this->ensureGroupNumberingInitialized();

        if (GroupType::ScanSubstring === $node->type) {
            $this->referenceAt = $node->startPosition + $this->positionOffset;
            $this->validateListedGroups($node, $node->scannedGroups, $node->startPosition + 2);
        }

        $previous = $this->previousNode;
        $next = $this->nextNode;

        $isLookbehind = \in_array(
            $node->type,
            [GroupType::LookbehindPositive, GroupType::LookbehindNegative],
            true,
        );
        if ($isLookbehind) {
            $this->measureLookbehind($node);
        }

        $this->previousNode = null;
        $this->nextNode = null;

        $source = $this->source;
        $positionOffset = $this->positionOffset;
        if ($node->child->getStartPosition() <= $node->startPosition) {
            $this->enterPayload($node->startPosition, $node->endPosition);
        }

        if ($isLookbehind) {
            $this->lookbehindDepth++;
            $this->lookbehinds[] = $node;
        }

        $isLookaround = $this->isLookaround($node);
        if ($isLookaround) {
            $this->keepsInLookarounds[] = null;
        }

        $lookaheadInLookbehind = $isLookaround && !$isLookbehind && $this->lookbehindDepth > 0;
        if ($lookaheadInLookbehind) {
            $this->lookaheadsInLookbehinds++;
        }

        $walkedApart = $isLookaround || GroupType::ScanSubstring === $node->type;
        if ($walkedApart) {
            $this->lookaroundKinds[] = $isLookbehind;
        }

        // "(?i)" sets options for what follows and opens nothing.
        $nests = !(GroupType::InlineFlags === $node->type && null !== $this->source && InlineFlags::setOptionsOnly($this->source, $node->startPosition));
        if ($nests) {
            $this->enterNesting($node->child->getStartPosition());
        }

        try {
            $node->child->accept($this);
        } finally {
            $this->source = $source;
            $this->positionOffset = $positionOffset;
            if ($nests) {
                $this->nestingDepth--;
            }
            if ($isLookbehind) {
                $this->lookbehindDepth--;
                array_pop($this->lookbehinds);
            }
            if ($lookaheadInLookbehind) {
                $this->lookaheadsInLookbehinds--;
            }
            if ($walkedApart) {
                array_pop($this->lookaroundKinds);
            }
            $keepAt = $isLookaround ? array_pop($this->keepsInLookarounds) : null;
        }

        // PCRE refuses the "\K" as it compiles the lookaround, once the whole
        // pattern is read, and reports it at the end of the pattern.
        if (null !== $keepAt && $this->target->phpVersionId >= PhpVersionGates::NO_KEEP_IN_LOOKAROUND) {
            $this->raiseLateCompileError(
                '\K is not allowed in a lookaround from PHP 8.5, which compiles without PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK.',
                $this->patternLength - $this->positionOffset,
                ErrorCode::KeepInLookaround,
                $this->keepHint($node),
                $keepAt,
            );
        }

        $this->previousNode = $previous;
        $this->nextNode = $next;
    }

    #[\Override]
    public function visitScriptRun(ScriptRunNode $node): void
    {
        if (null === $node->content) {
            return;
        }

        $this->enterNesting($node->content->getStartPosition());

        try {
            $node->content->accept($this);
        } finally {
            $this->nestingDepth--;
        }
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): void
    {
        // "\N{4 }", "\N{,2}": before PCRE2 10.43 such a count does not repeat
        // "\N", and "\N{" that is no repeat is refused where the "\N" ends.
        // "a{ 4 }" and "a{,2}" are literal text there, which the lexer reads
        // as such.
        if ($node->node instanceof CharTypeNode && 'N' === $node->node->value && 0 === $this->charClassDepth
            && str_starts_with($node->quantifier, '{')
            && 1 !== LibraryPcre::match('/^\{\d++(?:,\d*+)?\}/', $node->quantifier)
            && !$this->supports(PcreFeature::OpenAndPaddedRepeatCounts)) {
            // Before 10.37 its digits are read first: one past 65535 is
            // refused as too big a count, at the same place.
            if (!$this->supports(PcreFeature::LargeOpenCountIsText) && null !== RepeatCount::overflowBeforeTen37($node->quantifier, 0)) {
                $this->raiseSemanticError('Number too big in the count after \N: PCRE allows at most 65535 repetitions.', $node->node->getEndPosition(), ErrorCode::QuantifierTooBig);
            }

            $this->raiseSemanticError(
                \sprintf('The count "%s" after \N needs PCRE2 10.43, which PHP bundles from 8.4.', $node->quantifier),
                $node->node->getEndPosition(),
                ErrorCode::EscapeUnsupported,
                'Write the count as {n}, {n,} or {n,m} without spaces, or target PHP 8.4+.',
            );
        }

        // PCRE reads the item before its count: what it refuses there comes
        // first, and what it checks once the pattern is read waits.
        $this->repeatDepth++;

        try {
            $node->node->accept($this);
        } finally {
            $this->repeatDepth--;
        }

        [$min, $max] = QuantifierCounts::of($node->quantifier);

        // PCRE caps repetition counts at 65535, and checks each number as
        // it reads it, before it compares the two.
        [$minEnd, $maxEnd] = $this->quantifierNumberEnds($node);

        // Right after "\N", PCRE reads the count to tell it from a character
        // name, and refuses it where the "\N" ends.
        $afterN = $node->node instanceof CharTypeNode && 'N' === $node->node->value && null !== $this->source
            && '{' === ($this->source[$node->node->getEndPosition()] ?? '') ? $node->node->getEndPosition() : null;
        if ($min > 65535 || $max > 65535) {
            $this->raiseSemanticError(
                \sprintf('Number too big in "%s" quantifier: PCRE allows at most 65535 repetitions.', $node->quantifier),
                $afterN ?? ($min > 65535 ? $minEnd : $maxEnd),
                ErrorCode::QuantifierTooBig,
            );
        }

        if (-1 !== $max && $min > $max) {
            $this->raiseSemanticError(
                \sprintf('Invalid quantifier range "%s": min > max.', $node->quantifier),
                $afterN ?? $maxEnd,
                ErrorCode::QuantifierInvalidRange,
                \sprintf('Write the smaller count first: "{%d,%d}".', $max, $min),
            );
        }
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): void
    {
        if (null === $this->source) {
            return;
        }

        if ($this->charClassDepth > 0 && '[' === $node->value && $this->isUnquotedClassBracket($this->source, $node)) {
            $this->validateBracketInClass($this->source, $node->startPosition);

            return;
        }

        $letter = $node->value;
        $start = $node->startPosition;
        if (1 !== \strlen($letter) || '\\' !== ($this->source[$start] ?? '') || !Ascii::isAlpha($letter)
            || '\\'.$letter !== substr($this->source, $start, $node->endPosition - $start)) {
            return;
        }

        $this->validateEscapedLetter($this->source, $letter, $start);
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): void
    {
        // "\C" matches one code unit, which would split a UTF-8 character:
        // PHP 8.4.25 and 8.5.10 refuse it with the "u" flag (GH-21134), and
        // earlier releases, which compile it, can crash matching it, so it is
        // refused for every PHP. "(*UTF)\C" compiles.
        if ('C' === $node->value && $this->unicodeFlag) {
            // A PHP that compiles it refuses it in a lookbehind, which PCRE
            // reports where it measures that lookbehind.
            $lookbehind = $this->refusesBackslashCUnderUtf() ? null : ($this->lookbehinds[\count($this->lookbehinds) - 1] ?? null);
            $this->raiseSemanticError(
                '\C is not allowed in Unicode mode: it matches a single byte.',
                null === $lookbehind ? $node->getEndPosition() : $this->lookbehindErrorPosition($lookbehind),
                ErrorCode::EscapeSingleByteInUtf,
                'Use "." or drop the "u" flag.',
            );
        }

        // "(*UTF)" without the "u" flag: "\C" compiles, but not in a
        // lookbehind, which PCRE refuses where it measures it.
        $lookbehind = $this->lookbehinds[\count($this->lookbehinds) - 1] ?? null;
        if ('C' === $node->value && $this->unicodeMode && null !== $lookbehind) {
            $this->raiseSemanticError(
                '\C is not allowed in a lookbehind in UTF-8 mode: the length of the lookbehind would not be known.',
                $this->lookbehindErrorPosition($lookbehind),
                ErrorCode::EscapeSingleByteInUtf,
            );
        }

        if (0 === $this->charClassDepth) {
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
                $this->raiseSemanticError(
                    match ($braceError) {
                        ErrorCode::QuantifierTooBig => 'Number too big in the count after \N: PCRE allows at most 65535 repetitions.',
                        ErrorCode::QuantifierInvalidRange => 'Invalid count after \N: min > max.',
                        default => 'PCRE does not support the escape "\N{" (\F, \L, \l, \N{name}, \U and \u are not supported), in a character class either.',
                    },
                    $this->pastTheFault($node->getEndPosition() + 1),
                    $braceError,
                );
            }

            $this->raiseSemanticError(
                '\N is not supported in a character class.',
                $node->getEndPosition(),
                ErrorCode::CharclassInvalidEscape,
            );
        }

        if (isset(self::CLASS_INVALID_ESCAPES[$node->value])) {
            $this->raiseSemanticError(
                \sprintf('Escape sequence \%s is invalid in a character class.', $node->value),
                $this->pastTheFault($node->getEndPosition()),
                ErrorCode::CharclassInvalidEscape,
            );
        }
    }

    #[\Override]
    public function visitDot(DotNode $node): void
    {
        // No semantic validation needed for dot
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node): void
    {
        // No semantic validation needed for anchors
    }

    #[\Override]
    public function visitAssertion(AssertionNode $node): void
    {
        // Fast array lookup with early return
        if (!isset(self::VALID_ASSERTIONS[$node->value])) {
            $this->raiseSemanticError(
                \sprintf('Invalid assertion: \\%s.', $node->value),
                $node->startPosition,
                ErrorCode::AssertionInvalid,
            );
        }
    }

    /**
     * `\K` stands anywhere up to PHP 8.4, which compiles every pattern with
     * PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK. PHP 8.5 dropped that option, and a
     * `\K` in a lookaround is refused where the lookaround ends.
     */
    #[\Override]
    public function visitKeep(KeepNode $node): void
    {
        if ([] !== $this->keepsInLookarounds) {
            $this->keepsInLookarounds[] = array_pop($this->keepsInLookarounds) ?? $node->startPosition + $this->positionOffset;
        }
    }

    /**
     * The operands of "(?[...])" are judged as the members of a class.
     */
    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node): void
    {
        // PCRE2 10.45 to 10.47 walk the pattern to measure its lookbehinds
        // and know no operator of an extended class: one the walk meets, out
        // of every lookbehind or in a lookahead there, fails at offset 0.
        // An operator, anywhere in the class, is the root of its expression.
        if ($this->walkingPattern && $this->classOperatorsFail && true !== end($this->lookaroundKinds) && $node->expression instanceof ClassSetOperationNode) {
            $this->lateErrors[0] ??= new SemanticErrorException(
                'An operator of an extended class in a pattern that holds a lookbehind makes PCRE2 10.45 to 10.47 fail ("unknown meta code in check_lookbehinds()").',
                ErrorCode::ExtendedClassBesideLookbehind,
                0,
                $this->pattern,
                null,
                'Target PCRE2 10.48 or later, or write the class without an operator, as "[...]".',
            );
        }

        $this->charClassDepth++;

        try {
            $node->expression->accept($this);
        } finally {
            $this->charClassDepth--;
        }
    }

    #[\Override]
    public function visitClassSetOperation(ClassSetOperationNode $node): void
    {
        $node->left?->accept($this);
        $node->right->accept($this);
    }

    #[\Override]
    public function visitCharClass(CharClassNode $node): void
    {
        if (0 === $this->charClassDepth && null !== $this->source) {
            $start = $node->startPosition;

            // "[[:<:]]" and "[[:>:]]" are the old POSIX word boundaries,
            // which PCRE reads as such outside a class.
            if (\in_array(substr($this->source, $start, 7), ['[[:<:]]', '[[:>:]]'], true)) {
                return;
            }

            $this->validatePosixOutsideClass($this->source, $start);
        }

        $parts = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];

        $quoteOpen = $this->classQuoteOpen;
        $this->classQuoteOpen = false;
        $this->charClassDepth++;

        try {
            $end = $node->startPosition;
            foreach ($parts as $part) {
                $this->readClassQuotes($end, $part->getStartPosition());
                $part->accept($this);
                $end = $part->getEndPosition();
            }
        } finally {
            $this->charClassDepth--;
            $this->classQuoteOpen = $quoteOpen;
        }
    }

    #[\Override]
    public function visitRange(RangeNode $node): void
    {
        // 1. Validation: Ensure start and end nodes represent a single character.
        // We allow LiteralNode, but also CharLiteralNode and friends.
        // Unreachable from a parsed pattern: the parser refuses such an
        // endpoint first, with the same code; only a hand-built range gets here.
        if (!$this->isSingleCharNode($node->start) || !$this->isSingleCharNode($node->end)) {
            $this->raiseSemanticError(
                \sprintf(
                    'Invalid range: ranges must be between literal characters or single escape sequences. Found %s and %s.',
                    $node->start::class,
                    $node->end::class,
                ),
                $node->startPosition,
                ErrorCode::RangeInvalidBounds,
            );
        }

        // 2. Validation: Ensure characters are single codepoint (for LiteralNodes).
        // Use mb_strlen for proper Unicode character counting.
        if ($node->start instanceof LiteralNode && mb_strlen($node->start->value, 'UTF-8') > 1) {
            $this->raiseSemanticError(
                'Invalid range: start char must be a single character.',
                $node->startPosition,
                ErrorCode::RangeInvalidStart,
            );
        }
        if ($node->end instanceof LiteralNode && mb_strlen($node->end->value, 'UTF-8') > 1) {
            $this->raiseSemanticError(
                'Invalid range: end char must be a single character.',
                $node->startPosition,
                ErrorCode::RangeInvalidEnd,
            );
        }

        $node->start->accept($this);
        $this->readClassQuotes($node->start->getEndPosition(), $node->end->getStartPosition());

        // "[a-[.x.]]": a collating element is no range end.
        if (null !== $this->source && $node->end instanceof LiteralNode && '[' === $node->end->value && $this->isUnquotedClassBracket($this->source, $node->end)) {
            $this->validateBracketInClass($this->source, $node->end->startPosition, true);
        }

        $this->endsRange = true;

        try {
            $node->end->accept($this);
        } finally {
            $this->endsRange = false;
        }

        // 3. Validation: order check, on the code points of both endpoints.
        // PCRE reports it where the range ends.
        $startCodePoint = $this->rangeEndpointCodePoint($node->start, true);
        $endCodePoint = $this->rangeEndpointCodePoint($node->end, false);
        if (null !== $startCodePoint && null !== $endCodePoint && $startCodePoint > $endCodePoint) {
            // Without Unicode mode, a multibyte end ends the range on its
            // first byte.
            $endsAt = !$this->unicodeMode && $node->end instanceof LiteralNode ? $node->getEndPosition() - \strlen($node->end->value) + 1 : $node->getEndPosition();
            $this->raiseSemanticError(
                \sprintf('Invalid range "%s": start character comes after end character.', $this->describeRange($node)),
                $this->pastTheFault($endsAt),
                ErrorCode::RangeReversed,
            );
        }
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): void
    {
        $this->referenceAt = $node->startPosition + $this->positionOffset;
        $this->ensureGroupNumberingInitialized();
        $this->validateReferenceName($node);

        $ref = $node->ref;

        // Fast path for numeric backreferences
        if (LibraryPcre::match('/^\\\\(\d++)$/', $ref, $matches)) {
            $num = (int) $matches[1];
            if (0 === $num) {
                $this->raiseSemanticError(
                    'Backreference \\0 is not valid.',
                    $node->startPosition,
                    ErrorCode::BackrefZero,
                    'Use \\g<0> for recursion to the whole pattern, or remove the reference.',
                );
            }
            $this->guardGroupNumberSize($node, '', $matches[1]);

            // "\NN" that PCRE reads as an octal escape is parsed as one: a
            // reference by number here names a group or none.
            if ($num > $this->groupNumbering->maxGroupNumber) {
                $this->raiseMissingReference(
                    \sprintf('Backreference to non-existent group: \\%d.', $num),
                    $this->missingReferenceOffset($node),
                    ErrorCode::BackrefMissingGroup,
                );
            }

            return;
        }

        // Relative conditions, "(?(-1)...)" and "(?(+1)...)", count groups
        // from where they stand.
        if (LibraryPcre::match('/^[+-]\d++$/', $ref)) {
            $this->assertRelativeReferenceExists((int) $ref, $this->missingReferenceOffset($node), ErrorCode::BackrefRelative, 'Condition', $node);

            return;
        }

        // Numeric conditionals without a leading backslash (e.g., (?(2)...))
        if (LibraryPcre::match('/^(\d++)$/', $ref, $matches)) {
            $num = (int) $matches[1];
            if (0 === $num) {
                $this->raiseSemanticError(
                    'Backreference 0 is not valid.',
                    $this->missingReferenceOffset($node),
                    ErrorCode::BackrefZero,
                    'Use \\g<0> for recursion to the whole pattern, or remove the reference.',
                );
            }
            if ($num > $this->groupNumbering->maxGroupNumber) {
                $this->raiseMissingReference(
                    \sprintf('Backreference to non-existent group: %d.', $num),
                    $this->missingReferenceOffset($node),
                    ErrorCode::BackrefMissingGroup,
                );
            }

            return;
        }

        // Optimized named backreference validation
        if (LibraryPcre::match('/^\\\\k[<{\'](?<name>'.self::GROUP_NAME.')[>}\']$/u', $ref, $matches)) {
            $name = $matches['name'];
            $this->guardByteModeName($node, $name, ErrorCode::GroupNameUnterminated);
            if (!$this->groupNumbering->hasNamedGroup($name)) {
                $suggestion = $this->getNameSuggestions($name);
                $this->raiseMissingReference(
                    \sprintf('Backreference to non-existent named group: "%s".', $name).(null === $suggestion ? '' : ' '.$suggestion),
                    $this->missingReferenceOffset($node),
                    ErrorCode::BackrefMissingNamedGroup,
                    $suggestion,
                );
            }

            return;
        }

        // Bare name validation (conditionals)
        if ($this->isBareNamedBackref($ref)) {
            if (!$this->groupNumbering->hasNamedGroup($ref)) {
                $suggestion = $this->getNameSuggestions($ref);
                $this->raiseMissingReference(
                    \sprintf('Backreference to non-existent named group: "%s".', $ref).(null === $suggestion ? '' : ' '.$suggestion),
                    $node->startPosition,
                    ErrorCode::BackrefMissingNamedGroup,
                    $suggestion,
                );
            }

            return;
        }

        // \g backreference with optimized validation (\g1, \g{1}, \g'1')
        if (LibraryPcre::match('/^\\\\g(?:\{([0-9+-]++)\}|\'([0-9+-]++)\'|([0-9+-]++))$/', $ref, $matches)) {
            $numStr = ('' !== $matches[1]) ? $matches[1] : (('' !== ($matches[2] ?? '')) ? $matches[2] : ($matches[3] ?? ''));

            // "\g-" or "\g+" with no digit is no reference at all: PCRE stops
            // on the sign.
            if ('' !== ($matches[3] ?? '') && '' === ltrim($numStr, '+-')) {
                $this->raiseSemanticError(
                    '\g is not followed by a braced, angle-bracketed or quoted name or number, or by a plain number.',
                    $node->startPosition + 2,
                    ErrorCode::BackrefInvalidSyntax,
                );
            }
            if (1 === LibraryPcre::match('/^[+-]?0++$/', $numStr)) {
                $this->raiseSemanticError(
                    'Backreference \\g{0} is not valid.',
                    $this->missingReferenceOffset($node),
                    ErrorCode::BackrefZero,
                    'Use \\g<0> for recursion to the whole pattern.',
                );
            }

            if (1 === LibraryPcre::match('/^([+-]?)(\d++)$/', $numStr, $number)) {
                $this->guardGroupNumberSize($node, $number[1], $number[2]);
            }

            if (str_starts_with($numStr, '+') || str_starts_with($numStr, '-')) {
                $offset = (int) $numStr;
                $this->assertRelativeReferenceExists($offset, $this->missingReferenceOffset($node), ErrorCode::BackrefRelative, 'Backreference', $node);

                return;
            }

            $num = (int) $numStr;
            if ($num > $this->groupNumbering->maxGroupNumber) {
                $this->raiseMissingReference(
                    \sprintf('Backreference to non-existent group: \\g{%d}.', $num),
                    $this->missingReferenceOffset($node),
                    ErrorCode::BackrefMissingGroup,
                );
            }

            return;
        }

        // "\g{name}" is kept as "\k{name}": the message quotes the source.
        $written = null === $this->source ? $ref : substr($this->source, $node->startPosition, $node->getEndPosition() - $node->startPosition);

        $this->raiseSemanticError(
            \sprintf('Invalid backreference syntax: "%s".', '' === $written ? $ref : $written),
            $this->missingReferenceOffset($node),
            ErrorCode::BackrefInvalidSyntax,
        );
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): void
    {
        // "\N{name}": PCRE2 refuses a character name, whatever it names, on
        // the "{", past it from PCRE2 10.47; only "\N{U+...}" is a code point,
        // and from 10.43 "\N{ U+...}": before, a space makes "\N{" a name.
        $padding = $this->supports(PcreFeature::PaddedBracedEscapes) ? '[ \t]*+' : '';
        if (CharLiteralType::UnicodeNamed === $node->type
            && 1 !== LibraryPcre::match('/^\\\\N\{'.$padding.'U\+/', $node->originalRepresentation)) {
            // Before PCRE2 10.37 its digits are read as a count first.
            if (!$this->supports(PcreFeature::LargeOpenCountIsText) && null !== RepeatCount::overflowBeforeTen37($node->originalRepresentation, 2)) {
                $this->raiseSemanticError('Number too big in the count after \N: PCRE allows at most 65535 repetitions.', $node->startPosition + 2, ErrorCode::QuantifierTooBig);
            }

            $this->raiseUnsupportedEscape('N{', $this->pastTheFault($node->startPosition + 3));
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

        // The Lexer/Parser combination already ensures these are
        // syntactically valid. We validate the *value*, which PCRE reads
        // before it looks at what follows the digits.
        match ($node->type) {
            CharLiteralType::Unicode => $this->validateUnicode($node),
            CharLiteralType::Octal => $this->validateOctal($node),
            CharLiteralType::OctalLegacy => $this->validateOctalLegacy($node),
            CharLiteralType::UnicodeNamed => null,
        };

        $this->validatePaddedBraces($node);

        // UTF-8 cannot encode the UTF-16 surrogates. PCRE reports it at the
        // closing brace.
        if ($this->unicodeMode && $node->codePoint >= 0xD800 && $node->codePoint <= 0xDFFF) {
            $this->raiseSemanticError(
                \sprintf('Code point "%s" is a surrogate, which is not allowed in Unicode mode.', $node->originalRepresentation),
                $node->getEndPosition() - 1,
                ErrorCode::UnicodeSurrogate,
            );
        }
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): void
    {
        // Before PCRE2 10.40, a name is looked up as written in a table of
        // its own: no running engine since then answers for it.
        if (null !== $this->source && !$this->supports(PcreFeature::LoosePropertyNames)) {
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
                $this->raiseSemanticError(
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
            self::$unicodePropCache[$key] = $this->validateUnicodeProperty($key);
        }

        // The running engine answered; for another target, a name whose
        // support arrived in a later release is judged by that release, both
        // ways: known to the target, unknown to it.
        $needs = $this->target->isRunningEngine() ? null : $this->releaseAddingProperty($node);
        if (null !== $needs) {
            if (!$this->target->pcreAtLeast($needs)) {
                $this->raiseSemanticError(
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
                $this->raiseSemanticError(
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
            $this->raiseSemanticError(
                \sprintf('Invalid or unsupported Unicode property: \\%s.', $key).(null === $hint ? '' : ' '.$hint),
                $node->getEndPosition(),
                ErrorCode::UnicodePropertyInvalid,
                $hint,
            );
        }
    }

    #[\Override]
    public function visitControlChar(ControlCharNode $node): void
    {
        if ($node->codePoint < 0 || $node->codePoint > 0xFF) {
            $this->raiseSemanticError(
                \sprintf('Invalid control character "\\c%s".', $node->char),
                $node->startPosition,
                ErrorCode::ControlCharInvalid,
            );
        }
    }

    #[\Override]
    public function visitPosixClass(PosixClassNode $node): void
    {
        // PCRE matches the name case-sensitively: "[[:ALPHA:]]" is unknown.
        if (!$this->isPosixClassName($node->class)) {
            // Past the class from PCRE2 10.45, on its name before.
            $this->raiseSemanticError(
                \sprintf('Invalid POSIX class: "%s".', $node->class),
                $this->supports(PcreFeature::PosixItemErrorPastItsEnd) ? $node->getEndPosition() : $node->startPosition + 2 + (str_starts_with($node->class, '^') ? 1 : 0),
                ErrorCode::PosixInvalid,
            );
        }
    }

    /**
     * Validates a `CommentNode`.
     */
    #[\Override]
    public function visitComment(CommentNode $node): void
    {
        // Comments are ignored in validation
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): void
    {
        $this->referenceAt = $node->startPosition + $this->positionOffset;
        $this->ensureGroupNumberingInitialized();

        // PCRE counts the conditional as it opens it: past a reference, or
        // on the assertion that decides it.
        $this->enterNesting($node->condition instanceof GroupNode || $this->isCalloutThenAssertion($node->condition)
            ? $node->condition->getStartPosition()
            : $node->yes->getStartPosition());

        // Check if the condition is a valid *type* of condition first
        // (e.g., a backreference, a subroutine call, or a lookaround)
        if ($node->condition instanceof BackrefNode) {
            // This is (?(1)...) or (?(<name>)...) or (?(name)...)
            // For bare names, check if the group exists before calling accept
            $ref = $node->condition->ref;
            if ($this->isBareNamedBackref($ref) && !$this->groupNumbering->hasNamedGroup($ref)) {
                // Bare name that doesn't exist - this is an invalid conditional
                $this->raiseMissingReference(
                    \sprintf('Invalid conditional construct. Condition must be a group reference, lookaround, or (DEFINE). The pattern has no group named "%s".', $ref),
                    $this->missingReferenceOffset($node->condition),
                    ErrorCode::ConditionalMissingGroup,
                    $this->getNameSuggestions($ref),
                );
            }
            // Now validate the backreference itself
            $node->condition->accept($this);
        } elseif ($node->condition instanceof SubroutineNode) {
            $ref = $node->condition->reference;
            if ('R' === $ref || '0' === $ref) {
                // Always valid recursion condition to entire pattern.
            } elseif (LibraryPcre::match('/^R-?\d++$/', $ref)) {
                $num = (int) substr($ref, 1);
                // PCRE reads "R2" as a name, then as a group number digit by
                // digit, and stops on the digit that takes it over 65535.
                $overflow = strspn($ref, 'R') + $this->digitsWithinGroupLimit(ltrim($ref, 'R'));
                if ($overflow < \strlen($ref)) {
                    $this->raiseSemanticError(
                        \sprintf('Group number %s in a recursion condition is too big: PCRE takes at most 65535.', substr($ref, 1)),
                        $node->condition->startPosition + $overflow,
                        ErrorCode::GroupNumberTooBig,
                    );
                }
                // Group 0 is the whole pattern: "R0" asks whether any recursion runs.
                if (0 !== $num) {
                    $this->assertSubroutineReferenceExists($num, $this->missingReferenceOffset($node->condition), ErrorCode::SubroutineRecursion, 'Recursion condition');
                }
            } elseif (str_starts_with($ref, 'R&')) {
                // "(?(R&name)...)": the group has to exist.
                if (!$this->groupNumbering->hasNamedGroup(substr($ref, 2))) {
                    $this->raiseMissingReference(
                        \sprintf('Recursion condition to non-existent named group: "%s".', substr($ref, 2)),
                        $this->missingReferenceOffset($node->condition),
                        ErrorCode::SubroutineMissingNamedGroup,
                        \sprintf('Name a group "%s" with (?<%1$s>...), or test a group the pattern names.', substr($ref, 2)),
                    );
                }
            } else {
                $node->condition->accept($this);
            }
        } elseif ($node->condition instanceof GroupNode && \in_array($node->condition->type, [
            GroupType::LookaheadPositive,
            GroupType::LookaheadNegative,
            GroupType::LookbehindPositive,
            GroupType::LookbehindNegative,
        ], true)) {
            // This is (?(?=...)...) etc. This is valid.
            $node->condition->accept($this);
        } elseif ($this->isCalloutThenAssertion($node->condition)) {
            // "(?(?C1)(?=a)...)": a callout, then the assertion that decides.
            $node->condition->accept($this);
        } elseif ($node->condition instanceof AssertionNode && 'DEFINE' === $node->condition->value) {
            // (?(DEFINE)...) This is valid.
            $node->condition->accept($this);
        } elseif ($node->condition instanceof VersionConditionNode) {
            // (?(VERSION>=10.4)...) asks about the library reading the
            // pattern; whether the comparison is one PCRE makes is checked
            // where the condition is visited.
            $node->condition->accept($this);
        } else {
            // Any other atom is not a valid condition
            $this->raiseSemanticError(
                'Invalid conditional construct. Condition must be a group reference, lookaround, or (DEFINE).',
                $node->condition->getStartPosition(),
                ErrorCode::ConditionalInvalid,
            );
        }

        // The parser keeps every branch after the first in "no"; PCRE takes
        // two at most.
        if ($node->no instanceof AlternationNode) {
            $this->raiseBranchCountError(
                'A conditional group holds more than two branches.',
                $this->branchCountErrorOffset($node),
                ErrorCode::ConditionalTooManyBranches,
                'Group the extra branches: (?(1)a|(?:b|c)).',
            );
        }

        $node->yes->accept($this);
        $node->no->accept($this);

        // When an error stops the walk, visitRegex() resets the count.
        $this->nestingDepth--;
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): void
    {
        $this->referenceAt = $node->startPosition + $this->positionOffset;
        $this->validateSubroutineReference($node);
        $this->validateReturnedGroups($node);
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): void
    {
        $verbName = LibraryPcre::split('/[:=]/', $node->verb, 2)[0] ?? $node->verb;

        $setting = isset(StartOptions::NAMES[$verbName]);
        // A script run takes a body: its name closed with no colon is an
        // alphabetic name PCRE does not know.
        $known = isset(self::VALID_PCRE_VERBS[$verbName])
            && (1 !== LibraryPcre::match('/^[a-z]/', $verbName) || '' !== substr($node->verb, \strlen($verbName), 1));
        if (!$known
            && !($setting && (!isset(self::PCRE_1045_SETTINGS[$verbName]) || $this->supports(PcreFeature::CasingSettingVerbs)))) {
            // "(*)" is a "*" with nothing to repeat, refused past it from
            // PCRE2 10.47.
            if ('' === $node->verb) {
                $this->raiseSemanticError(
                    'Quantifier "*" does not follow a repeatable item: "(*)" names no verb.',
                    $this->pastTheFault($node->startPosition + 2),
                    ErrorCode::QuantifierNothingToRepeat,
                );
            }

            // PCRE reports an unknown verb where its name ends, and past the
            // character after it, whole in UTF mode, an alphabetic assertion,
            // whose name starts with a lowercase letter, followed by no colon.
            $nameEnd = $node->startPosition + 2 + (1 === LibraryPcre::match('/^\w*+/', $verbName, $name) ? \strlen($name[0]) : 0);
            $width = $this->unicodeMode && 1 === LibraryPcre::match('/\G./su', (string) $this->source, $character, 0, $nameEnd) ? \strlen($character[0]) : 1;
            $this->raiseSemanticError(
                \sprintf('Invalid or unsupported PCRE verb: "%s".', $verbName),
                match (true) {
                    1 === LibraryPcre::match('/^[a-z]/', $verbName) && ':' !== ($this->source[$nameEnd] ?? '') => $this->pastTheFault($nameEnd + $width, $width),
                    default => $nameEnd,
                },
                ErrorCode::VerbInvalid,
            );
        }

        // PCRE reports these at the closing parenthesis.
        $closing = $node->getEndPosition() - 1;

        if ($setting) {
            $this->validateStartOfPatternPlacement($verbName, $node->startPosition);
        }

        // Only the limits take a value after "=": after any other name PCRE
        // refuses the "=" where it stands. "(*=name)", a mark written short,
        // is refused further down.
        $equals = $node->startPosition + 2 + \strlen($verbName);
        if (!isset(self::LIMIT_VERBS[$verbName]) && '=' === ($this->source[$equals] ?? '') && '=' !== ($this->source[$node->startPosition + 2] ?? '')) {
            $this->raiseSemanticError(
                \sprintf('(*%s) takes no value: only the limits take one, as (*LIMIT_MATCH=10).', $verbName),
                1 === LibraryPcre::match('/^[a-z]/', $verbName) ? $this->pastTheFault($equals + 1) : $equals,
                ErrorCode::VerbInvalid,
            );
        }

        if (isset(self::LIMIT_VERBS[$verbName]) && 1 !== LibraryPcre::match('/\A[A-Z_]++=\d++\z/', $node->verb)) {
            $this->raiseSemanticError(
                \sprintf('(*%s) needs a number: (*%s=10).', $verbName, $verbName),
                PcreVerb::limitValueErrorOffset((string) $this->source, $node->startPosition, $this->supports(PcreFeature::LimitValueErrorOnFaultingCharacter)) ?? $closing,
                ErrorCode::VerbInvalid,
            );
        }

        // A verb name holds at most 255 code units.
        if (1 === LibraryPcre::match('/^[A-Z]*+:(.*)$/s', $node->verb, $name) && \strlen($name[1]) > self::MAX_VERB_NAME_LENGTH) {
            $this->raiseSemanticError(
                \sprintf('The name of (*%s) is too long: PCRE takes at most %d code units.', $verbName, self::MAX_VERB_NAME_LENGTH),
                $closing,
                ErrorCode::VerbNameTooLong,
            );
        }

        if (isset(self::LIMIT_VERBS[$verbName]) && 1 === LibraryPcre::match('/=(\d++)\z/', $node->verb, $digits, \PREG_OFFSET_CAPTURE)) {
            $this->validateLimitValue($digits[1][0], $node->startPosition + 2 + $digits[1][1]);
        }

        // "(*=name)" is read as a mark shorthand, but PCRE only knows "(*:".
        if (str_starts_with($node->verb, 'MARK=')) {
            $this->raiseSemanticError(
                '(*=...) is not a PCRE verb; a mark is written (*MARK:name) or (*:name).',
                $node->startPosition + 2,
                ErrorCode::VerbInvalid,
            );
        }

        if ('MARK' === $verbName && 1 !== LibraryPcre::match('/^MARK:./s', $node->verb)) {
            $this->raiseSemanticError(
                '(*MARK) must have a name: (*MARK:name) or (*:name).',
                $closing,
                ErrorCode::VerbMarkNameMissing,
            );
        }
    }

    #[\Override]
    public function visitDefine(DefineNode $node): void
    {
        // PCRE reports it at the "DEFINE" word, past "(?(".
        if ($node->content instanceof AlternationNode) {
            $this->raiseBranchCountError(
                'A (DEFINE) group holds more than one branch.',
                $node->startPosition + 3,
                ErrorCode::DefineTooManyBranches,
                'Group the branches: (?(DEFINE)(?:a|b)).',
            );
        }

        $this->enterNesting($node->content->getStartPosition());

        try {
            $node->content->accept($this);
        } finally {
            $this->nestingDepth--;
        }
    }

    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node): void
    {
        $this->validateStartOfPatternPlacement('LIMIT_MATCH', $node->startPosition);

        // The digits as written, leading zeros included.
        $written = null === $this->source ? '' : substr($this->source, $node->startPosition, $node->getEndPosition() - $node->startPosition);
        if (1 === LibraryPcre::match('/=(\d++)\)$/', $written, $digits, \PREG_OFFSET_CAPTURE)) {
            $this->validateLimitValue($digits[1][0], $node->startPosition + $digits[1][1]);
        }
    }

    /**
     * PCRE compares the version two ways and no more.
     *
     * The parser reads the others so that a pattern using one still produces
     * a tree to look at; saying they will not compile is this visitor's job.
     */
    #[\Override]
    public function visitVersionCondition(VersionConditionNode $node): void
    {
        $versionAt = null === $this->source ? false : strpos($this->source, 'VERSION', $node->startPosition);
        $pcreOffset = false === $versionAt ? null : VersionCondition::errorOffset((string) $this->source, $versionAt, !$this->readsWholeVersionNumbers(), $this->supports(PcreFeature::ErrorOffsetPastTheFault), $this->unicodeMode);

        if (!\in_array($node->operator, ['=', '>='], true)) {
            $this->raiseSemanticError(
                \sprintf('Version condition "%s" is not supported: PCRE compares with "=" or ">=".', $node->operator),
                $pcreOffset ?? $node->startPosition,
                ErrorCode::ConditionalVersionOperator,
            );
        }

        // PCRE reads a major number and at most one ".minor".
        if (1 === LibraryPcre::match('/^\d++(?:\.\d++)?$/', $node->version, $matches)) {
            $tooBig = false === $versionAt ? null : VersionCondition::errorOffset((string) $this->source, $versionAt, !$this->readsWholeVersionNumbers(), $this->supports(PcreFeature::ErrorOffsetPastTheFault), $this->unicodeMode);

            // "(?(VERSION=10 )": before PCRE2 10.47, what follows the major
            // where the ")" belongs leaves the condition open.
            if (null !== $tooBig && false !== $versionAt && !$this->supports(PcreFeature::VersionConditionLeftOpenIsVersionError)
                && VersionCondition::isMajorLeftOpenAt((string) $this->source, $versionAt, $tooBig)) {
                $this->raiseSemanticError(
                    \sprintf('Missing ")" to close the condition at position %d.', $tooBig),
                    $tooBig,
                    ErrorCode::ConditionalUnclosed,
                );
            }

            if (null !== $tooBig) {
                $this->raiseSemanticError(
                    \sprintf('Invalid version "%s" in a version condition: the number is too big.', $node->version),
                    $tooBig,
                    ErrorCode::ConditionalVersionSyntax,
                    'PCRE takes a major and a minor of at most 1000, and before PCRE2 10.47 a minor of two digits.',
                );
            }

            return;
        }

        // The pattern matches every string, the empty prefix included: the
        // '' branch is unreachable and only there for the type.
        $valid = 1 === LibraryPcre::match('/^\d*+(?:\.\d*+)?/', $node->version, $matches) ? $matches[0] : '';
        $afterNumber = '' !== $valid && !str_ends_with($valid, '.');
        $versionStart = null === $this->source
            ? $node->startPosition
            : (int) strpos($this->source, $node->version, $node->startPosition);

        $this->raiseSemanticError(
            \sprintf('Invalid version "%s" in a version condition: PCRE takes a major number and an optional ".minor".', $node->version),
            // From PCRE2 10.47, PCRE steps past a character it reads where
            // ")" belongs; it stops on one it reads where a digit belongs.
            // Before, it stops on a third digit of a minor.
            $pcreOffset ?? $versionStart + \strlen($valid) + ($afterNumber && $this->supports(PcreFeature::ErrorOffsetPastTheFault) ? 1 : 0),
            ErrorCode::ConditionalVersionSyntax,
        );
    }

    #[\Override]
    public function visitCallout(CalloutNode $node): void
    {
        // Any string is a valid argument, the empty one included: PCRE2
        // compiles (?C""), (?C'') and (?C{}). A number goes up to 255.
        if (\is_int($node->identifier) && ($node->identifier < 0 || $node->identifier > 255)) {
            $this->raiseSemanticError(
                \sprintf('Callout identifier must be between 0 and 255, got %d.', $node->identifier),
                $this->calloutOverflowOffset($node),
                ErrorCode::CalloutOutOfRange,
            );
        }
    }

    /**
     * What to do with a lookaround PHP 8.5 refuses for its "\K". Up to PHP
     * 8.4 the "\K" resets the start of the match from inside it, which no
     * other spelling keeps in general; only a "\K" alone at the start of a
     * plain lookahead, in no repeated item nor in another lookaround,
     * resets it where the lookahead starts, as a "\K" just before it does.
     */
    private function keepHint(GroupNode $node): string
    {
        $body = $node->child instanceof SequenceNode ? $node->child->children : [$node->child];
        if (GroupType::LookaheadPositive === $node->type && null === $node->flags
            && $node->child->getStartPosition() === $node->startPosition + 3
            && ($body[0] ?? null) instanceof KeepNode && 1 === self::keepCount($node->child)
            && 0 === $this->repeatDepth && [] === $this->keepsInLookarounds) {
            return 'This \K opens the lookahead: write "\K(?=" for "(?=\K", which every PHP reads alike.';
        }

        return 'Up to PHP 8.4 a \K in a lookaround resets the start of the match from inside it: "(?=\Ka)" matches an empty string in "a", and "(?<=\Ka)b" matches "ab" in "ab". No spelling keeps that in general: rewrite the pattern without it.';
    }

    private static function keepCount(NodeInterface $node): int
    {
        $count = $node instanceof KeepNode ? 1 : 0;
        foreach ($node->getChildren() as $child) {
            $count += self::keepCount($child);
        }

        return $count;
    }

    /**
     * Walks the pattern from its root, raising each error PCRE finds as it
     * reads it, and keeping those it finds once the whole pattern is read.
     */
    private function walkPattern(RegexNode $node): void
    {
        $this->source = $node->source;
        $this->charClassDepth = 0;
        $this->startOfPatternEnd = null === $node->source ? null : $this->readStartOfPatternEnd($node->source);
        $this->validateCasingSettings($node);
        $this->unicodeFlag = str_contains($node->flags, 'u');
        $this->unicodeMode = $this->unicodeFlag
            || (null !== $node->source && StartOptions::turnUtfOn($node->source));
        $this->badEscapeIsLiteral = PhpVersionGates::badEscapeIsLiteral($this->target->phpVersionId, $node->flags);
        $this->groups = GroupIndex::of($node->pattern);
        $this->groupNumbering = $this->groups->numbering();
        $this->lookbehindBranchMeasures = 0;
        // A measure of its own: what an earlier pattern measured is gone.
        $this->lookbehindLength = $this->measuring($this->groups);

        $this->previousNode = null;
        $this->nextNode = null;
        $this->lookbehindDepth = 0;
        $this->lookaheadsInLookbehinds = 0;
        $this->lookaroundKinds = [];
        $this->classOperatorsFail = $this->supports(PcreFeature::ExtendedCharClass)
            && !$this->supports(PcreFeature::ExtendedClassBesideLookbehindCompiles)
            && self::holdsLookbehind($node->pattern);
        $this->lookbehinds = [];
        $this->nestingDepth = 0;
        $this->keepsInLookarounds = [];
        $this->patternLength = \strlen($node->source ?? '');
        $this->positionOffset = 0;
        $this->lateErrors = [];
        $this->firstPassErrorAt = \PHP_INT_MAX;
        $this->walkingPattern = true;

        try {
            $node->pattern->accept($this);
        } finally {
            $this->walkingPattern = false;
        }
    }

    /**
     * "(?1(2,<name>))": each group the call returns must exist.
     */
    private function validateReturnedGroups(SubroutineNode $node): void
    {
        if ([] !== $node->returnedGroups) {
            $this->validateListedGroups($node, $node->returnedGroups, $node->startPosition + 1);
        }
    }

    /**
     * Each group a call returns, or a substring scan matches, must exist; the
     * list opens at the first "(" from $from. PCRE reports a missing group
     * where it is named, a name past its "<" or quote.
     *
     * @param list<string> $groups
     */
    private function validateListedGroups(NodeInterface $node, array $groups, int $from): void
    {
        if (null === $this->source) {
            return;
        }

        $at = (int) strpos($this->source, '(', $from) + 1;
        foreach ($groups as $group) {
            $exists = match (true) {
                Ascii::isDigit($group) => (int) $group <= $this->groupNumbering->maxGroupNumber,
                str_starts_with($group, '+') => ($this->groups->nextNumberAt($node) ?? 1) - 1 + (int) $group <= $this->groupNumbering->maxGroupNumber,
                str_starts_with($group, '<'), str_starts_with($group, "'") => $this->groupNumbering->hasNamedGroup(substr($group, 1, -1)),
                // A relative number back is refused as it is read.
                default => true,
            };

            if (!$exists) {
                $this->raiseMissingReference(
                    \sprintf('Group %s is listed but does not exist.', $group),
                    Ascii::isDigit($group) || str_starts_with($group, '+') ? $at : $at + 1,
                    ErrorCode::GroupListMissingGroup,
                );
            }

            $at += \strlen($group) + 1;
        }
    }

    private function validateSubroutineReference(SubroutineNode $node): void
    {
        $this->ensureGroupNumberingInitialized();
        $this->validateReferenceName($node);

        $ref = $node->reference;

        if ('R' === $ref || '0' === $ref) {
            return; // (?R) or (?0) is always valid.
        }

        if (str_starts_with($ref, 'R')) {
            $numPart = substr($ref, 1);

            if (Ascii::isDigit($numPart)) {
                $num = (int) $numPart;
                $this->assertAbsoluteReferenceExists($num, $this->missingReferenceOffset($node), ErrorCode::SubroutineRecursion, 'Recursion condition');

                return;
            }

            if (str_starts_with($numPart, '-') && Ascii::isDigit(substr($numPart, 1))) {
                $num = (int) $numPart;
                $this->assertRelativeReferenceExists($num, $node->startPosition, ErrorCode::SubroutineRecursion, 'Recursion condition');

                return;
            }
        }

        // "(?-0)" and "(?+0)" point nowhere: PCRE refuses a relative zero,
        // at the ")" of "(?-0)" and at the "<" of "\g<-0>".
        if ('-0' === $ref || '+0' === $ref) {
            $this->raiseSemanticError(
                \sprintf('Subroutine call relative reference cannot be zero: "%s".', $ref),
                'g' === $node->syntax ? $node->startPosition + 2 : max($node->startPosition, $node->getEndPosition() - 1),
                ErrorCode::SubroutineRelativeZero,
            );
        }

        // Numeric reference: (?1), (?-1), (?+1), \g<-1>, \g<+1>
        if (1 === LibraryPcre::match('/^([+-]?)(\d+)$/', $ref, $matches)) {
            $this->guardGroupNumberSize($node, $matches[1], $matches[2]);
            $num = (int) $ref;
            if (0 === $num) {
                return; // (?0) is an alias for (?R)
            }
            if (str_starts_with($ref, '+') || str_starts_with($ref, '-')) {
                $this->assertRelativeReferenceExists($num, $this->missingReferenceOffset($node), ErrorCode::SubroutineRelativeMissing, 'Subroutine call', $node);
            } else {
                $this->assertAbsoluteReferenceExists($num, $this->missingReferenceOffset($node), ErrorCode::SubroutineMissingGroup, 'Subroutine call');
            }

            return;
        }

        // Named reference: (?&name), (?P>name), \g<name>
        $this->guardByteModeName($node, $ref, ErrorCode::GroupUnclosed);
        if (!$this->groupNumbering->hasNamedGroup($ref)) {
            $this->raiseMissingReference(
                \sprintf('Subroutine call to non-existent named group: "%s".', $ref),
                $this->missingReferenceOffset($node),
                ErrorCode::SubroutineMissingNamedGroup,
                \sprintf('Name a group "%s" with (?<%1$s>...), or call a group the pattern names.', $ref),
            );
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
            $this->raiseSemanticError(
                \sprintf('Invalid or unsupported Unicode property: \\%s. It needs PCRE2 %s, and the target is PCRE2 %s.', substr($source, $node->startPosition + 1, $end - $node->startPosition - 1), $needs, $this->target->pcreVersion),
                $end,
                ErrorCode::UnicodePropertyInvalid,
                \sprintf('Target a PHP whose PCRE2 is %s or later, or use another property.', $needs),
            );
        }

        $unprefixed = LibraryPcre::replace('/^\s*+(?:sc|script|scx|script[\s_-]*+extensions)\s*+[:=]/i', '', $name) ?? $name;
        $spelling = PropertyNames::spellingBefore1040($unprefixed, $this->target);
        $this->raiseSemanticError(
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
        } elseif ($this->supports(PcreFeature::MalformedPropertyName) && null !== ($after = $this->nameAfterSkippedCaret($node))) {
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

    /**
     * One more level of parentheses, whose body starts at $bodyStart. PCRE
     * refuses the level past its limit once it has read the opener, before
     * any space that "x" skips.
     */
    private function enterNesting(int $bodyStart): void
    {
        if (++$this->nestingDepth <= self::MAX_GROUP_NESTING) {
            return;
        }

        $this->nestingDepth--;
        $source = $this->source ?? '';
        while ($bodyStart > 0 && isset($source[$bodyStart - 1]) && Ascii::isSpace($source[$bodyStart - 1])) {
            $bodyStart--;
        }

        $this->raiseSemanticError(
            \sprintf('Parentheses are nested too deeply: PCRE allows at most %d levels.', self::MAX_GROUP_NESTING),
            $bodyStart,
            ErrorCode::GroupNestedTooDeep,
            'Flatten the pattern: drop groups that only wrap one item.',
        );
    }

    /**
     * PCRE reads a (*LIMIT_...=n) value digit by digit and refuses the digit
     * that would take it past 4294967289, where it stops.
     */
    private function validateLimitValue(string $digits, int $start): void
    {
        $value = 0;
        foreach (Bytes::split($digits) as $index => $digit) {
            if ($value > self::MAX_LIMIT_VALUE_BEFORE_DIGIT) {
                $this->raiseSemanticError(
                    \sprintf('The value %s is too large for a (*LIMIT_...) setting: PCRE takes at most 4294967289.', $digits),
                    // Before PCRE2 10.45, past the digit it refuses.
                    $start + $index + ($this->supports(PcreFeature::LimitValueErrorOnFaultingCharacter) ? 0 : 1),
                    ErrorCode::VerbLimitTooLarge,
                );
            }

            $value = $value * 10 + (int) $digit;
        }
    }

    /**
     * PCRE reads the number of a callout digit by digit, and stops past the
     * one that takes it over 255.
     */
    private function calloutOverflowOffset(CalloutNode $node): int
    {
        $digits = null !== $this->source && 1 === LibraryPcre::match('/\G\d++/', $this->source, $matches, 0, $node->startPosition + 3)
            ? $matches[0]
            : (string) $node->identifier; // Unreachable from a parsed pattern: only a hand-built callout has no source.

        $number = 0;
        foreach (Bytes::split($digits) as $index => $digit) {
            $number = $number * 10 + (int) $digit;
            if ($number > 255) {
                return $node->startPosition + 4 + $index;
            }
        }

        return $node->startPosition + 4; // Unreachable from a parsed pattern, whose number is over 255 here.
    }

    /**
     * Where the two numbers of a "{n,m}" quantifier end in the pattern, the
     * places PCRE reports a number it refuses.
     *
     * @return array{0: int, 1: int}
     */
    private function quantifierNumberEnds(QuantifierNode $node): array
    {
        // A lazy or possessive quantifier ends with one more character.
        $suffix = QuantifierType::Greedy === $node->type ? 0 : 1;
        $braceStart = $node->getEndPosition() - $suffix - \strlen($node->quantifier);
        // A comment may stand between the count and its "+" or "?": the
        // count is found in the source, past the item it repeats.
        $written = null === $this->source ? false : strpos($this->source, $node->quantifier, $node->node->getEndPosition());
        if (false !== $written && $written < $braceStart) {
            $braceStart = $written;
        }

        if (1 !== LibraryPcre::match('/^\{\s*+(\d*+)\s*+(?:,\s*+(\d*+))?/', $node->quantifier, $matches, \PREG_OFFSET_CAPTURE)) {
            return [$node->startPosition, $node->startPosition];
        }

        $minEnd = $braceStart + $matches[1][1] + $this->digitsReadForCount($matches[1][0]);
        $maxEnd = isset($matches[2]) ? $braceStart + $matches[2][1] + $this->digitsReadForCount($matches[2][0]) : $minEnd;

        return [$minEnd, $maxEnd];
    }

    /**
     * How many digits of a count PCRE has read when it refuses one over
     * 65535: every one from PCRE2 10.45; before, up to the digit that takes
     * it over.
     */
    private function digitsReadForCount(string $digits): int
    {
        if ($this->supports(PcreFeature::NumberTooBigPastWholeNumber)) {
            return \strlen($digits);
        }

        $value = 0;
        foreach (Bytes::split($digits) as $index => $digit) {
            $value = $value * 10 + (int) $digit;
            if ($value > 65535) {
                return $index + 1;
            }
        }

        return \strlen($digits);
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
     * "Did you mean: name?" for the group names near $name, or null.
     */
    private function getNameSuggestions(string $name): ?string
    {
        $suggestions = NearMiss::within($name, array_map(strval(...), array_keys($this->groupNumbering->namedGroups)));

        return [] === $suggestions ? null : 'Did you mean: '.implode(', ', $suggestions).'?';
    }

    private function isBareNamedBackref(string $ref): bool
    {
        return 1 === LibraryPcre::match('/^'.self::GROUP_NAME.'$/u', $ref);
    }

    private function validateUnicode(CharLiteralNode $node): void
    {
        // Parse codePoint from the escape string
        $rep = $node->originalRepresentation;

        if (LibraryPcre::match('/^\\\\x([0-9a-fA-F]{1,2})$/', $rep, $m)) {
            $codePoint = (int) hexdec($m[1]);
        } elseif (LibraryPcre::match('/^\\\\u([0-9a-fA-F]{4})$/', $rep, $m)) {
            $codePoint = (int) hexdec($m[1]);
        } elseif (LibraryPcre::match('/^\\\\(x|u)\\{[ \t]*+([0-9a-fA-F]+)[ \t]*+\\}$/', $rep, $m)) {
            // Past six digits the value is past U+10FFFF, and may be past
            // what an integer holds.
            $codePoint = \strlen(ltrim($m[2], '0')) > 6 ? 0x110000 : (int) hexdec($m[2]);
        } else {
            return; // Invalid format, skip
        }

        // PCRE refuses the value where codePointOverflow() says.
        $overflow = '{' === ($rep[2] ?? '') ? $this->codePointOverflow($rep, 3, self::HEX_DIGITS) : null;
        if (null !== $overflow && $codePoint > 0x10FFFF) {
            $this->raiseSemanticError(
                \sprintf('Invalid Unicode codepoint "%s" (out of range).', $node->originalRepresentation),
                $node->startPosition + $overflow,
                ErrorCode::UnicodeOutOfRange,
            );
        }

        // Without Unicode mode a character is one byte, as for "\o{400}".
        if (null !== $overflow) {
            $this->raiseSemanticError(
                \sprintf('Invalid code point "%s": without the "u" flag, a character is at most \xFF.', $node->originalRepresentation),
                $node->startPosition + $overflow,
                ErrorCode::OctalOutOfRange,
                'Add the "u" flag, or use a code point up to \xFF.',
            );
        }

        // "\u0041" and "\u{41}" are JavaScript: PCRE2 refuses "\u" outright.
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
            ?? ($node->codePoint > ($this->unicodeMode ? 0x10FFFF : 0xFF) ? $node->getEndPosition() - 1 - $node->startPosition : null);
        if (null !== $overflow) {
            $this->raiseSemanticError(
                \sprintf($this->unicodeMode ? 'Invalid octal codepoint "%s" (out of Unicode range).' : 'Invalid octal codepoint "%s".', $node->originalRepresentation),
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
        $padded = $this->supports(PcreFeature::PaddedBracedEscapes);
        $at += $padded ? strspn($text, self::BRACE_PADDING, $at) : 0;
        $base = self::HEX_DIGITS === $digits ? 16 : 8;
        $max = $this->unicodeMode ? 0x10FFFF : 0xFF;
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
        if (!$this->unicodeMode && $node->codePoint > 0xFF) {
            $this->raiseSemanticError(
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
        if (!LibraryPcre::match('/^\\\\N\\{(.+)}$/', $node->originalRepresentation, $matches)) {
            throw new ParserException("Invalid Unicode named character format: {$node->originalRepresentation}", ErrorCode::EscapeUnsupported, $node->getStartPosition(), $this->pattern);
        }

        $name = $matches[1];

        // PCRE only supports \N{U+hhhh} in Unicode (/u) mode, "\N{U+ }"
        // included.
        if (!$this->unicodeMode && 1 === LibraryPcre::match('/^[ \t]*+U\+(?:[0-9A-Fa-f]++[ \t]*+|[ \t]++)$/', $name)) {
            $this->raiseSemanticError(
                \sprintf('\N{%s} is only supported in Unicode mode; add the "u" flag.', $name),
                // From PCRE2 10.47, PCRE reads the escape to its closing
                // brace before it looks at the mode; before, past the "\N".
                $this->supports(PcreFeature::NamedCodePointReadBeforeModeCheck) ? $node->getEndPosition() : $node->startPosition + 2,
                ErrorCode::UnicodeNamedRequiresUtf,
            );
        }

        // As for "\x{...}", PCRE refuses a value above U+10FFFF where
        // codePointOverflow() says.
        $overflow = $this->codePointOverflow($node->originalRepresentation, 3 + strspn($name, self::BRACE_PADDING) + 2, self::HEX_DIGITS);
        if (null !== $overflow) {
            $this->raiseSemanticError(
                \sprintf('Invalid Unicode codepoint "%s" (out of range).', $node->originalRepresentation),
                $node->startPosition + $overflow,
                ErrorCode::UnicodeOutOfRange,
            );
        }

        // If the codePoint is -1, the name could not be resolved. PCRE refuses
        // a name it does not take once it has read "\N{". Unreachable from a
        // parsed pattern: visitCharLiteral refuses every "\N{...}" that is no
        // "U+" code point before it gets here.
        if (-1 === $node->codePoint) {
            throw new ParserException("Invalid Unicode character name: {$name}", ErrorCode::EscapeUnsupported, $node->getStartPosition() + 3, $this->pattern);
        }
    }

    /**
     * Whether the engine that runs compiles the property: a name it does not
     * know is refused, whatever another regex flavour calls it.
     */
    private function validateUnicodeProperty(string $key): bool
    {
        return null === $this->engine->compile("/^\\{$key}$/u");
    }

    private function isSingleCharNode(NodeInterface $node): bool
    {
        return $node instanceof LiteralNode
            || $node instanceof CharLiteralNode
            || $node instanceof ControlCharNode;
    }

    /**
     * @param array<int, true>|null $expanding the groups being measured, by
     *                                         node, when the lookbehind sits
     *                                         in one being measured
     */
    private function validateLookbehindLength(GroupNode $node, ?array $expanding = null): void
    {
        // Only the groups the measure reached through calls are being
        // measured: those around the lookbehind are not.
        $measuring = $expanding ?? [];

        // A lookbehind measured inside another is not measured again, unless
        // it calls a group being measured here and was not there.
        $this->lookbehindLength->once(
            $node,
            $measuring,
            null === $expanding,
            fn () => $this->measureLookbehindBranches($node, $measuring),
        );
    }

    /**
     * Measure each branch of a lookbehind, raising the errors PCRE raises.
     *
     * @param array<int, true> $measuring the groups being measured, by node
     */
    private function measureLookbehindBranches(GroupNode $node, array $measuring): void
    {
        // PCRE measures each top-level branch on its own: "(?<=a{300}|b)" is
        // two fixed lengths, "(?<=(?:a{300}|b))" one variable length.
        $branches = $node->child instanceof AlternationNode ? $node->child->alternatives : [$node->child];
        $around = $this->measuredLookbehind;
        $this->measuredLookbehind = $node;
        $lengths = [];

        try {
            foreach ($branches as $branch) {
                $this->countLookbehindMeasures(1);
                [$length, $unfixed, $tooLong] = $this->lookbehindLength->branch($branch, $measuring);
                $lengths[] = $length;

                // PCRE adds each item to the branch before the repeat after
                // it, and refuses the branch as soon as it passes the limit.
                if (null !== $tooLong) {
                    $this->raiseSemanticError(
                        \sprintf('Lookbehind is too long: PCRE adds each item to the length of its branch before the repeat after it, and refuses a branch past %d characters (%d reached).', self::MAX_FIXED_LOOKBEHIND_LENGTH, $tooLong),
                        $this->lookbehindErrorPosition($node),
                        ErrorCode::LookbehindTooLong,
                        'Shorten the lookbehind.',
                    );
                }

                // PCRE stops at the first branch it cannot bound, met in
                // order: a nested lookbehind before it was judged first.
                if (null === $length[1]) {
                    // "\X" matches a whole grapheme cluster, of no bounded length.
                    if ($this->containsGraphemeCluster($branch)) {
                        $this->raiseSemanticError(
                            'Lookbehind is unbounded: \X matches a grapheme cluster of any length.',
                            $this->lookbehindErrorPosition($node),
                            ErrorCode::LookbehindUnbounded,
                            'Match the characters the cluster may hold instead of \X.',
                        );
                    }

                    $this->validateLookbehindBranchLength($node, $length, true);
                }

                // Up to PCRE2 10.42 the measure ends at the first item of no
                // fixed length, and the lookbehind is refused there.
                if ($unfixed) {
                    $this->refuseVariableLengthLookbehind($node);
                }
            }
        } finally {
            $this->measuredLookbehind = $around;
        }

        // One branch of variable length makes the whole lookbehind variable,
        // and then every branch answers to the variable-length limit.
        $variable = false;
        foreach ($lengths as [$min, $max]) {
            $variable = $variable || $min !== $max;
        }

        foreach ($lengths as $length) {
            $this->validateLookbehindBranchLength($node, $length, $variable);
        }
    }

    /**
     * PHP 8.4.25 and 8.5.10 compile "u" patterns with PCRE2_NEVER_BACKSLASH_C
     * (GH-21134); earlier releases compile "\C" but in a lookbehind.
     */
    private function refusesBackslashCUnderUtf(): bool
    {
        return PhpVersionGates::neverBackslashCUnderUtf($this->target->phpVersionId);
    }

    /**
     * Where PCRE reports a lookbehind it cannot take: its "(", or the last
     * letter of the name of "(*plb:...)" and its kin.
     */
    private function lookbehindErrorPosition(GroupNode $node): int
    {
        $start = $node->startPosition;
        $name = null === $this->pattern ? null : PcreVerb::nameBeforeColonAt($this->pattern, $start, PcreVerb::WORD_NAME);
        if (null !== $name) {
            return $start + \strlen($name) - 1;
        }

        return $start;
    }

    /**
     * @param LengthRange $lengthRange
     */
    private function validateLookbehindBranchLength(GroupNode $node, array $lengthRange, bool $variable): void
    {
        $max = $lengthRange[1];

        if (null === $max) {
            $culprit = $this->findUnboundedLookbehindNode($node->child);
            $detail = $culprit instanceof QuantifierNode ? $culprit->quantifier : null;
            $hint = null !== $detail
                ? \sprintf('Use a bounded quantifier instead of "%s".', $detail)
                : 'Ensure the lookbehind has a bounded maximum length.';

            // PCRE reports the lookbehind itself, not what makes it unbounded.
            $this->raiseSemanticError(
                'Lookbehind is unbounded. PCRE requires a bounded maximum length.',
                $this->lookbehindErrorPosition($node),
                ErrorCode::LookbehindUnbounded,
                $hint,
            );
        }

        // A fixed length is only capped by PCRE itself, as the branch is
        // measured; a variable one by max_lookbehind_length, which stands for
        // PCRE2's max_varlookbehind.
        if ($variable && $max > $this->maxLookbehindLength) {
            $this->raiseSemanticError(
                \sprintf('Lookbehind exceeds the maximum length of %d (max=%d).', $this->maxLookbehindLength, $max),
                $this->lookbehindErrorPosition($node),
                ErrorCode::LookbehindTooLong,
                'Reduce the lookbehind length, or raise max_lookbehind_length.',
            );
        }
    }

    private function refuseVariableLengthLookbehind(GroupNode $node): never
    {
        $this->raiseSemanticError(
            'Variable-length lookbehind needs PCRE2 10.43, which PHP bundles from 8.4.',
            $this->lookbehindErrorPosition($node),
            ErrorCode::LookbehindVariableLengthNotSupported,
            'Give each branch of the lookbehind a fixed length, or target PHP 8.4+.',
        );
    }

    /**
     * PCRE measures a lookbehind it meets inside the one it is measuring, and
     * those a lookahead there holds, before it goes on: the innermost one
     * that has no bound is the one reported.
     *
     * @param array<int, true> $expanding
     */
    private function validateNestedLookbehinds(NodeInterface $node, array $expanding): void
    {
        if ($node instanceof GroupNode && \in_array($node->type, [
            GroupType::LookbehindPositive,
            GroupType::LookbehindNegative,
        ], true)) {
            $this->validateLookbehindLength($node, $expanding);

            return;
        }

        $children = match (true) {
            $node instanceof GroupNode => [$node->child],
            $node instanceof SequenceNode => $node->children,
            $node instanceof AlternationNode => $node->alternatives,
            $node instanceof QuantifierNode => [$node->node],
            $node instanceof ConditionalNode => [$node->condition, $node->yes, $node->no],
            $node instanceof DefineNode => [$node->content],
            $node instanceof ScriptRunNode => null === $node->content ? [] : [$node->content],
            default => [],
        };

        foreach ($children as $child) {
            $this->validateNestedLookbehinds($child, $expanding);
        }
    }

    /**
     * The lookbehind measure, wired to the errors PCRE raises as it measures.
     */
    private function measuring(GroupIndex $groups): LookbehindLength
    {
        return new LookbehindLength(
            $groups,
            $this->unicodeMode,
            $this->supportsVariableLengthLookbehind(),
            $this->validateNestedLookbehinds(...),
            $this->countLookbehindBranches(...),
            $this->checkMeasuredReference(...),
            $this->supports(PcreFeature::LookbehindInLookaheadMeasured),
            $this->supports(PcreFeature::DefineSkippedInLookbehind),
            !$this->supports(PcreFeature::ExtendedCharClass) || $this->supports(PcreFeature::ExtendedClassBesideLookbehindCompiles),
            !$this->supports(PcreFeature::LookbehindLengthOverflowRefused),
        );
    }

    /**
     * PCRE checks that the group a call or a reference names exists as it
     * measures the lookbehind, counting relative references from where they
     * stand. A numbered back reference in a pattern with a branch reset it
     * does not measure at all, unless it failed already while being read:
     * one back past the first group, or to group zero.
     *
     * @param list<GroupNode> $groups the groups it names
     */
    private function checkMeasuredReference(SubroutineNode|BackrefNode $node, array $groups): void
    {
        $unmeasured = $node instanceof BackrefNode
            && $this->groups->hasBranchReset()
            && !str_starts_with($node->ref, '\\k')
            && 1 !== LibraryPcre::match('/^\\\\g[{<\']?\s*+(?:-|[+-]?0++(?!\d))/', $node->ref);
        if ([] !== $groups || $unmeasured) {
            return;
        }

        $node->accept($this);
    }

    /**
     * Count the branches of a group PCRE measures for a lookbehind.
     */
    private function countLookbehindBranches(NodeInterface $groupBody): void
    {
        $this->countLookbehindMeasures($groupBody instanceof AlternationNode ? \count($groupBody->alternatives) : 1);
    }

    /**
     * PCRE counts each branch it measures and gives up past its budget at
     * once, at the lookbehind it is measuring: the measure stops there,
     * however much is left of it.
     */
    private function countLookbehindMeasures(int $branches): void
    {
        $this->lookbehindBranchMeasures += $branches;
        if ($this->lookbehindBranchMeasures > self::MAX_LOOKBEHIND_BRANCH_MEASURES && null !== $this->measuredLookbehind) {
            $this->raiseSemanticError(
                'Lookbehind is too complicated: PCRE gives up past 2,001 branches measured for the lookbehinds of a pattern.',
                $this->lookbehindErrorPosition($this->measuredLookbehind),
                ErrorCode::LookbehindTooComplex,
                'Call fewer groups from the lookbehinds, use fewer lookbehinds, or drop the branch reset, which makes PCRE measure a group again each time.',
            );
        }
    }

    /**
     * "(?C1)(?=a)" as the condition of a conditional.
     */
    private function isCalloutThenAssertion(NodeInterface $condition): bool
    {
        return $condition instanceof SequenceNode
            && 2 === \count($condition->children)
            && $condition->children[0] instanceof CalloutNode
            && $condition->children[1] instanceof GroupNode
            && $this->isLookaround($condition->children[1]);
    }

    /**
     * Variable-length lookbehinds arrived in PCRE2 10.43. php-src bundles
     * 10.40 in PHP 8.2 and 10.42 in 8.3, so an explicit target below 8.4
     * lacks them; for the running PHP, the PCRE2 it links decides.
     */
    /**
     * Spaces inside "\x{ 41 }", "\o{ 101 }" and "\N{ U+41 }" arrived in
     * PCRE2 10.43; PHP 8.2 and 8.3 bundle 10.40 and 10.42, which refuse
     * them. PCRE reports the first space.
     */
    private function validatePaddedBraces(CharLiteralNode $node): void
    {
        $representation = $node->originalRepresentation;
        $space = strcspn($representation, " \t");
        if ($space === \strlen($representation) || $this->supports(PcreFeature::PaddedBracedEscapes)) {
            return;
        }

        // A space before "U+", which makes "\N{" a name, is refused before.
        [$code, $escape] = match (true) {
            CharLiteralType::Octal === $node->type => [ErrorCode::OctalInvalidDigit, '\o{}'],
            CharLiteralType::UnicodeNamed === $node->type => [ErrorCode::UnicodeInvalidDigit, '\N{U+}'],
            default => [ErrorCode::UnicodeInvalidDigit, '\x{}'],
        };

        $this->raiseSemanticError(
            \sprintf('Spaces inside %s need PCRE2 10.43, which PHP bundles from 8.4.', $escape),
            $node->startPosition + $space,
            $code,
            'Write the escape without spaces, or target PHP 8.4+.',
        );
    }

    /**
     * The name of "\k{...}", "\k<...>" and "\k'...'", and of "\g{...}",
     * "\g<...>" and "\g'...'" where no number stands, as PCRE2's read_name()
     * reads it: past the spaces and tabs after "{" from 10.43, a digit first
     * is refused on it, past it from 10.47; a character no name holds first
     * leaves no name; else the name ends at the first character no name
     * holds, and what closes it must follow, past the spaces and tabs before
     * "}" from 10.43. A name holds ASCII letters, digits and underscores,
     * and under UTF from 10.33 any letter or decimal digit. Before 10.43 a
     * space after the number of "\g{" ends "\g", whose number PCRE reads
     * first.
     */
    private function validateReferenceName(BackrefNode|SubroutineNode $node): void
    {
        $source = $this->source ?? '';
        $start = $node->startPosition;
        $closer = ['{' => '}', '<' => '>', "'" => "'"][$source[$start + 2] ?? ''] ?? null;
        if (null === $closer || 1 !== LibraryPcre::match('/\G\\\\[gk]/', $source, $escape, 0, $start)) {
            return;
        }

        $letter = $source[$start + 1];
        $padded = '}' === $closer && $this->supports(PcreFeature::PaddedBracedEscapes);
        $nameStart = $start + 3 + ($padded ? strspn($source, " \t", $start + 3) : 0);
        $digits = [];
        if ('g' === $letter && 1 === LibraryPcre::match('/\G([+-]?)(\d++)/', $source, $digits, 0, $nameStart)) {
            // Only "\g{...}" is a back reference, whose number is read here.
            if ($node instanceof BackrefNode && '}' === $closer && !$padded && 1 === strspn($source, " \t", $nameStart + \strlen($digits[0]), 1)) {
                $this->raiseSemanticError(
                    'Spaces inside \g{} need PCRE2 10.43, which PHP bundles from 8.4.',
                    $start + 2,
                    $this->braceNumberError($node, $digits[1], $digits[2]) ?? ErrorCode::BackrefInvalidSyntax,
                    'Remove the spaces inside the braces, or target PHP 8.4+.',
                );
            }

            return;
        }

        if ($this->unicodeMode && $this->supports(PcreFeature::NonAsciiGroupNames)) {
            $digit = substr($source, $nameStart, NameCharacters::digitAt($source, $nameStart, $this->target));
            $read = '' === $digit ? substr($source, $nameStart, NameCharacters::end($source, $nameStart, $this->target) - $nameStart) : $digit;
        } else {
            LibraryPcre::match('/\G(?:([0-9])|[_A-Za-z0-9]*+)/', $source, $name, 0, $nameStart);
            $read = $name[0] ?? '';
            $digit = $name[1] ?? '';
        }

        $closeAt = $nameStart + \strlen($read);
        $closeAt += $padded ? strspn($source, " \t", $closeAt) : 0;

        if ('' === $digit && '' !== $read && $closer === ($source[$closeAt] ?? '')) {
            return;
        }

        $written = substr($source, $start, $node->getEndPosition() - $start);
        [$offset, $code, $message] = match (true) {
            '' !== $digit => [$this->pastTheFault($nameStart + \strlen($digit), \strlen($digit)), ErrorCode::GroupNameInvalid, \sprintf('Invalid reference "%s": a group name must not start with a digit.', $written)],
            '' === $read => [$nameStart, ErrorCode::GroupNameExpected, \sprintf('Invalid reference "%s": a group name is expected at position %d.', $written, $nameStart)],
            default => [$closeAt, ErrorCode::GroupNameUnterminated, \sprintf('Invalid reference "%s": the group name ends at position %d, and no "%s" closes it there.', $written, $closeAt, $closer)],
        };

        // A space PCRE2 stops on inside the braces is padding it does not
        // read before 10.43.
        $unreadPadding = '}' === $closer && !$padded && 1 === strspn($source, " \t", $offset, 1);
        $this->raiseSemanticError(
            $unreadPadding ? \sprintf('Spaces inside \%s{} need PCRE2 10.43, which PHP bundles from 8.4.', $letter) : $message,
            $offset,
            $code,
            $unreadPadding ? 'Remove the spaces inside the braces, or target PHP 8.4+.' : null,
        );
    }

    /**
     * The error PCRE gives the number of "\g{...}" as it reads it, before
     * what follows: past 65535, a forward one counted from the groups opened
     * before it included; a relative zero; a backward one past the groups
     * opened. Null for a number it reads.
     */
    private function braceNumberError(BackrefNode $node, string $sign, string $digits): ?ErrorCode
    {
        $value = (int) $digits;
        $before = ($this->groups->nextNumberAt($node) ?? 1) - 1;

        return match (true) {
            \strlen(ltrim($digits, '0')) > 5 || $value > 65535 || ('+' === $sign && $value + $before > 65535) => ErrorCode::GroupNumberTooBig,
            '' !== $sign && 0 === $value => ErrorCode::BackrefZero,
            '-' === $sign && $value > $before => ErrorCode::BackrefRelative,
            default => null,
        };
    }

    /**
     * Whether the version numbers of "(?(VERSION...)" are read whole, as
     * PCRE2 10.47 does; before, the minor is two digits.
     */
    private function readsWholeVersionNumbers(): bool
    {
        return $this->supports(PcreFeature::VersionConditionWholeNumbers);
    }

    private function supportsVariableLengthLookbehind(): bool
    {
        return $this->supports(PcreFeature::VariableLengthLookbehind);
    }

    /**
     * Where PCRE reports an error it reports past the character at fault
     * from PCRE2 10.47, given that later offset: $shift characters earlier
     * before 10.47, which every PHP bundles.
     */
    private function pastTheFault(int $offset, int $shift = 1): int
    {
        return $this->supports(PcreFeature::ErrorOffsetPastTheFault) ? $offset : $offset - $shift;
    }

    private function supports(PcreFeature $feature): bool
    {
        return $this->target->supports($feature);
    }

    private function findUnboundedLookbehindNode(NodeInterface $node): ?NodeInterface
    {
        if ($node instanceof BackrefNode || $node instanceof SubroutineNode) {
            return $node;
        }

        if ($node instanceof QuantifierNode) {
            [, $max] = QuantifierCounts::of($node->quantifier);
            if (-1 === $max) {
                return $node;
            }

            return $this->findUnboundedLookbehindNode($node->node);
        }

        if ($node instanceof GroupNode) {
            return $this->findUnboundedLookbehindNode($node->child);
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alt) {
                $culprit = $this->findUnboundedLookbehindNode($alt);
                if (null !== $culprit) {
                    return $culprit;
                }
            }
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                $culprit = $this->findUnboundedLookbehindNode($child);
                if (null !== $culprit) {
                    return $culprit;
                }
            }
        }

        if ($node instanceof ConditionalNode) {
            return $this->findUnboundedLookbehindNode($node->condition)
                ?? $this->findUnboundedLookbehindNode($node->yes)
                ?? $this->findUnboundedLookbehindNode($node->no);
        }

        if ($node instanceof DefineNode) {
            return $this->findUnboundedLookbehindNode($node->content);
        }

        if ($node instanceof CharClassNode) {
            return $this->findUnboundedLookbehindNode($node->expression);
        }

        if ($node instanceof RangeNode) {
            return $this->findUnboundedLookbehindNode($node->start) ?? $this->findUnboundedLookbehindNode($node->end);
        }

        return null;
    }

    /**
     * Where PCRE reports a reference to a group that does not exist.
     *
     * A name is looked up where it starts. A number is checked once the
     * whole reference is read, except when it fails while being read: a
     * relative reference back past the first group, or a relative zero,
     * stops right after "\g" when bracketed and after its digits when not.
     * "(?1)" is reported at its ")", and a condition "(?(2)" two characters
     * before the end of its number, where PCRE records it.
     */
    private function missingReferenceOffset(BackrefNode|SubroutineNode $node): int
    {
        $start = $node->startPosition;
        $end = $node->getEndPosition();

        if (null === $this->source || $end <= $start) {
            return $start; // Unreachable from a parsed pattern: only "(?(R)" is empty, and it always exists.
        }

        $text = substr($this->source, $start, $end - $start);

        // "\g{2}", "\g-1", "\k<name>", "\g<name>", "\g'1'", ...
        if (1 === LibraryPcre::match('/^\\\\([gk])([{<\'])?\s*+([+-]?)(\d*)/', $text, $matches)) {
            // A name, past the spaces and tabs a brace takes from 10.43.
            if ('' === $matches[4]) {
                return $start + 3 + ('{' === $matches[2] && $this->supports(PcreFeature::PaddedBracedEscapes) ? strspn($text, " \t", 3) : 0);
            }

            // A number past 65535 is refused on the brace that opens it.
            $tooBig = $this->groupNumberTooBigOffset($start + \strlen($matches[0]) - \strlen($matches[4]));
            if (null !== $tooBig) {
                return '' === $matches[2] ? $tooBig : $start + 2;
            }

            // "\g'3gh'": the number read, what should close it is missing;
            // before PCRE2 10.47, "\g" itself is refused, past the "g".
            $closing = ['{' => '}', '<' => '>', "'" => "'", '' => ''][$matches[2]];
            $rest = ltrim(substr($text, \strlen($matches[0])), " \t\n\r\0\x0B");
            if ('' !== $closing && !str_starts_with($rest, $closing)) {
                return $this->supports(PcreFeature::GReferenceNumberReadBeforeClosing) ? $start + \strlen($matches[0]) : $start + 2;
            }

            $failsWhileRead = '-' === $matches[3] || ('' !== $matches[3] && 0 === (int) $matches[4]);
            if ($failsWhileRead && '' !== $matches[2]) {
                return $start + 2;
            }

            // Only a positive number that names no group moved in 10.47; one
            // past 65535, a forward one counted from the groups before it
            // included, is refused where it ends.
            $number = (int) $matches[4] + ('+' === $matches[3] ? ($this->groups->nextNumberAt($node) ?? 1) - 1 : 0);

            return $failsWhileRead || 0 === $number || $number > 65535 ? $end : $this->pastTheFault($end);
        }

        // "\1", "\81"; a number past 65535 is refused where it ends.
        if ('\\' === $text[0]) {
            return (int) substr($text, 1) > 65535 ? $end : $this->pastTheFault($end);
        }

        // "(?P=name)", "(?P>name)", "(?&name)", "(?1)", "(?-1)"
        if (str_starts_with($text, '(?')) {
            if (str_starts_with($text, '(?P')) {
                return $start + 4;
            }

            if ('&' === ($text[2] ?? '')) {
                return $start + 3;
            }

            // Past the number, before the groups "(?1(2))" returns.
            return $this->groupNumberTooBigOffset($start + 2 + strspn($text, '+-', 2)) ?? $start + 2 + \strlen($node instanceof SubroutineNode ? $node->reference : $node->ref);
        }

        // "(?(VERSION=10z)": PCRE reads a version condition, not a name.
        $versionError = VersionCondition::errorOffset($this->source, $start, !$this->readsWholeVersionNumbers(), $this->supports(PcreFeature::ErrorOffsetPastTheFault), $this->unicodeMode);
        if (null !== $versionError) {
            return $versionError;
        }

        // What follows "(?(": "<name>", "'name'", "R&name", "-1", "2", a bare name.
        if ('<' === $text[0] || '\'' === $text[0]) {
            return $start + 1;
        }

        if (str_starts_with($text, 'R&')) {
            return $start + 2;
        }

        if (1 === LibraryPcre::match('/^([+-]?)(\d++)$/', $text, $matches)) {
            return $this->groupNumberTooBigOffset($start + \strlen($matches[1]))
                ?? ('-' === $matches[1] || 0 === (int) $matches[2] ? $end : $end - 2);
        }

        return $start;
    }

    /**
     * Where PCRE refuses a group number past 65535 whose digits start at
     * $digitsStart: past the whole number from PCRE2 10.45, past the digit
     * that takes it over before. Null for a number within the limit.
     */
    private function groupNumberTooBigOffset(int $digitsStart): ?int
    {
        $source = (string) $this->source;
        $digits = substr($source, $digitsStart, strspn($source, '0123456789', $digitsStart));
        $read = $this->digitsWithinGroupLimit($digits);
        if ($read === \strlen($digits)) {
            return null;
        }

        return $digitsStart + ($this->supports(PcreFeature::NumberTooBigPastWholeNumber) ? \strlen($digits) : $read + 1);
    }

    /**
     * How many leading digits of $digits PCRE reads before the number goes
     * over 65535, the highest group number; all of them when it does not.
     */
    private function digitsWithinGroupLimit(string $digits): int
    {
        $number = 0;
        $length = \strlen($digits);
        for ($index = 0; $index < $length && Ascii::isDigit($digits[$index]); $index++) {
            $number = $number * 10 + (int) $digits[$index];
            if ($number > 65535) {
                return $index;
            }
        }

        return $length;
    }

    /**
     * PCRE refuses a group number past 65535 as it reads the pattern, before
     * it looks up any group: a forward relative number counts the groups
     * before it too.
     *
     * @param string $sign   "+", "-" or "", as written
     * @param string $digits the number, as written
     */
    private function guardGroupNumberSize(BackrefNode|SubroutineNode $node, string $sign, string $digits): void
    {
        $number = $this->digitsWithinGroupLimit($digits) < \strlen($digits) ? 65536 : (int) $digits;
        if ('+' === $sign) {
            $number += ($this->groups->nextNumberAt($node) ?? 1) - 1;
        }

        if ($number > 65535) {
            // A braced, angled or quoted number is refused on its bracket,
            // even when only its sum with the groups before it overflows.
            $text = null === $this->source ? '' : substr($this->source, $node->startPosition, 3);
            $bracketed = 1 === LibraryPcre::match('/^\\\\g[{<\']/', $text);

            $this->raiseSemanticError(
                \sprintf('Group number %s%s is too big: PCRE takes at most 65535.', $sign, $digits),
                $bracketed ? $node->startPosition + 2 : $this->missingReferenceOffset($node),
                ErrorCode::GroupNumberTooBig,
            );
        }
    }

    private function assertAbsoluteReferenceExists(int $num, int $position, ErrorCode $code, string $context): void
    {
        if ($num <= 0 || $num > $this->groupNumbering->maxGroupNumber) {
            $this->raiseMissingReference(
                \sprintf('%s to non-existent group: %d.', $context, $num),
                $position,
                $code,
            );
        }
    }

    private function assertRelativeReferenceExists(int $offset, int $position, ErrorCode $code, string $context, BackrefNode|SubroutineNode|null $node = null): void
    {
        if (0 === $offset) {
            $this->raiseSemanticError(
                \sprintf('%s relative reference cannot be zero.', $context),
                $position,
                $code,
            );
        }

        if ($this->relativeTargetExists($node)) {
            return;
        }

        // A reference forward names a group PCRE has not read yet: it is
        // resolved with the references by number, once the pattern is read.
        if ($offset > 0) {
            $this->raiseMissingReference(
                \sprintf('%s relative reference %d is outside the range of available capture groups.', $context, $offset),
                $position,
                $code,
            );

            return;
        }

        $this->raiseSemanticError(
            \sprintf('%s relative reference %d is outside the range of available capture groups.', $context, $offset),
            $position,
            $code,
            'Check group numbering or remove the relative reference.',
        );
    }

    /**
     * Whether a group bears the number a relative reference counts to. The
     * index counts group numbers, as PCRE does: every branch of a branch
     * reset numbers its groups from the same number. A node of no indexed
     * tree names no group, nor does "(?(R-1)", which PCRE refuses to read.
     */
    private function relativeTargetExists(BackrefNode|SubroutineNode|null $node): bool
    {
        return match (true) {
            $node instanceof BackrefNode => [] !== $this->groups->groupsReferencedBy($node),
            $node instanceof SubroutineNode => [] !== $this->groups->groupsCalledBy($node),
            default => false,
        };
    }

    private function assertSubroutineReferenceExists(int $num, int $position, ErrorCode $code, string $context): void
    {
        if ($num > 0) {
            $this->assertAbsoluteReferenceExists($num, $position, $code, $context);

            return;
        }

        $this->assertRelativeReferenceExists($num, $position, $code, $context);
    }

    /**
     * Judge one token the way the node it would become is judged, for the
     * checks that need no other node: escaped letters, "\N{...}", character
     * types and properties.
     */
    private function validateEscapeToken(Token $token, string $source): void
    {
        match ($token->type) {
            TokenType::CharClassOpen => $this->charClassDepth = 1,
            TokenType::CharClassClose => $this->charClassDepth = 0,
            TokenType::UnicodeNamed => $this->validateNamedCharacterBraces($source, $token->position + 2),
            default => null,
        };

        if (TokenType::CharType === $token->type) {
            $this->visitCharType(new CharTypeNode($token->value, $token->position, $token->end()));
        }

        if (TokenType::UnicodeProp === $token->type) {
            $this->visitUnicodeProp(new UnicodePropNode(
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
            $this->validateEscapedLetter($source, $letter, $token->position);
        }
    }

    /**
     * A letter written after a backslash that the lexer read as the letter
     * itself, because it names no escape it knows.
     */
    private function validateEscapedLetter(string $source, string $letter, int $start): void
    {
        // PHP 7.3 and 7.4 read an escape PCRE2 refuses as it reads it as its
        // letter.
        if ($this->badEscapeIsLiteral && BadEscape::at($source, $start, $this->charClassDepth > 0, $this->unicodeMode, $this->target, null, $this->endsRange)) {
            return;
        }

        $end = $start + 2;

        if (isset(self::UNRECOGNIZED_ESCAPES[$letter])) {
            $this->raiseSemanticError(
                \sprintf('Unrecognized escape sequence "\%s".', $letter),
                $this->pastTheFault($end),
                ErrorCode::EscapeUnrecognized,
            );
        }

        if (isset(self::UNSUPPORTED_ESCAPES[$letter])) {
            $this->raiseUnsupportedEscape($letter, $end);
        }

        if ($this->charClassDepth > 0 && isset(self::CLASS_INVALID_ESCAPES[$letter])) {
            $this->raiseSemanticError(
                \sprintf('Escape sequence \%s is invalid in a character class.', $letter),
                $this->pastTheFault($end),
                ErrorCode::CharclassInvalidEscape,
            );
        }

        // "\k" in a class is the letter from PCRE2 10.45, which no PHP release
        // bundles yet; the releases before refuse it, on the "k".
        if ($this->charClassDepth > 0 && 'k' === $letter && !$this->supports(PcreFeature::ClassBackslashKIsLetter)) {
            $this->raiseSemanticError(
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
     * "\p" or "\P" the lexer read as a letter: no property letter and no
     * closed braced name follows it. PCRE reads one more character, or an
     * unclosed brace up to the end of the pattern, before it gives up.
     */
    private function raiseMalformedProperty(string $source, string $letter, int $position): never
    {
        if ('{}' === substr($source, $position, 2)) {
            $this->raiseSemanticError(
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

        return $this->pastTheFault($position + $width, $width - 1);
    }

    private function raiseMalformedPropertyAt(string $letter, int $offset): never
    {
        $this->raiseSemanticError(
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
        if (!$this->supports(PcreFeature::LoosePropertyNames)) {
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
            $position += $this->supports(PcreFeature::ErrorOffsetPastTheFault) ? $this->characterLengthAt($source, $position) : 1;

            if (str_contains(" _-\t\n\v\f\r", $char)) {
                continue;
            }

            // Up to PCRE2 10.44 only a "^" right after the brace negates.
            if (0 === $read && !$negated && '^' === $char && ($position === $brace + 2 || $this->supports(PcreFeature::MalformedPropertyName))) {
                $negated = true;

                continue;
            }

            if ('}' === $char) {
                return null;
            }

            // Names hold "&" to "z" only; 10.45 and 10.46 stop past the
            // first byte of a longer UTF-8 character, 10.47 past all of it.
            if (($char < '&' || $char > 'z') && $this->supports(PcreFeature::MalformedPropertyName)) {
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
            $this->raiseSemanticError(
                'Missing opening brace after \o.',
                $position >= \strlen($source) && !$this->supports(PcreFeature::ErrorOffsetPastTheFault) ? $position - 1 : $position,
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
        } elseif ($this->supports(PcreFeature::HexEscapeNeedsDigits)) {
            // A "\x" with no digit is "\x00" up to PCRE2 10.44 and an error
            // from 10.45, which no PHP release bundles yet: only a newer
            // linked PCRE2 refuses it.
            $this->raiseSemanticError('Digits missing after \x.', $position, ErrorCode::EscapeDigitsMissing);
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

            if (!$this->unicodeMode) {
                // From PCRE2 10.47, PCRE reads the hex digits right after
                // "U+", the padding after them and a "}" before it looks at
                // the mode; before, it stops past the "\N".
                $end = $digits + strspn($source, self::HEX_DIGITS, $digits);
                $end += strspn($source, self::BRACE_PADDING, $end);
                $this->raiseSemanticError(
                    '\N{U+...} is only supported in Unicode mode; add the "u" flag.',
                    $this->supports(PcreFeature::NamedCodePointReadBeforeModeCheck) ? ('}' === ($source[$end] ?? '') ? $end + 1 : $end) : $position,
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
        if (!$this->supports(PcreFeature::LargeOpenCountIsText) && null !== RepeatCount::overflowBeforeTen37($source, $position)) {
            $this->raiseSemanticError('Number too big in the count after \N: PCRE allows at most 65535 repetitions.', $position, ErrorCode::QuantifierTooBig);
        }

        if (1 !== LibraryPcre::match(self::REPEAT_COUNT, $source, $matches, 0, $position)) {
            $this->raiseUnsupportedEscape('N{', $this->pastTheFault($position + 1));
        }
    }

    /**
     * Whether "{U+" follows, which PCRE2 10.43 also reads with spaces or tabs
     * after the brace: "\N{ U+41}". Before, "\N{ " names a character.
     */
    private function startsNamedCodePoint(string $source, int $position): bool
    {
        $padding = $this->supports(PcreFeature::PaddedBracedEscapes) ? strspn($source, self::BRACE_PADDING, $position + 1) : 0;

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
        $padded = $this->supports(PcreFeature::PaddedBracedEscapes);

        if ($leadingPadding) {
            $position += $padded ? strspn($source, self::BRACE_PADDING, $position) : 0;
        } elseif ($position < $length && 1 === strspn($source, self::BRACE_PADDING, $position, 1)) {
            // Padding right after "U+": before PCRE2 10.43 it is refused on
            // its first character; from 10.43 it may only run up to the
            // closing brace, "\N{U+ }", and anything else is refused past
            // the first character that is not padding.
            if (!$this->supports(PcreFeature::PaddedBracedEscapes)) {
                $this->raiseSemanticError(
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

            $this->raiseSemanticError(
                \sprintf('Invalid character in %s, or closing brace missing.', $escape),
                $this->braceFaultOffset($source, $position),
                $invalidDigitCode,
            );
        }

        if ($position >= $length || '}' === $source[$position]) {
            $this->raiseSemanticError(
                \sprintf('Digits missing in %s.', $escape),
                $position,
                ErrorCode::EscapeDigitsMissing,
            );
        }

        // A value too large is refused first, whatever follows its digits.
        $overflow = $this->codePointOverflow($source, $position, $digits);
        if (null !== $overflow) {
            $this->raiseSemanticError(
                \sprintf('Code point too large in %s.', $escape),
                $overflow,
                '\o{}' === $escape || ('\x{}' === $escape && !$this->unicodeMode) ? ErrorCode::OctalOutOfRange : ErrorCode::UnicodeOutOfRange,
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
        if ($this->unicodeMode && $codePoint >= 0xD800 && $codePoint <= 0xDFFF && $this->supports(PcreFeature::SurrogateRefusedBeforeBrace)) {
            $this->raiseSemanticError(
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
        $this->raiseSemanticError(
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
            return match (true) {
                $this->supports(PcreFeature::UnclosedBraceAtPatternEnd) => $length,
                $this->supports(PcreFeature::ErrorOffsetPastTheFault) => $length + 1,
                default => $length - 1,
            };
        }

        return $this->pastTheFault($position + $this->characterLengthAt($source, $position), $this->characterLengthAt($source, $position));
    }

    private function raiseUnsupportedEscape(string $escape, int $position): never
    {
        $this->raiseSemanticError(
            \sprintf('PCRE does not support the escape "\%s" (\F, \L, \l, \N{name}, \U and \u are not supported).', $escape),
            $position,
            ErrorCode::EscapeUnsupported,
        );
    }

    /**
     * Whether a "[" read inside a class was written as is, rather than
     * escaped or quoted: PCRE reads a quoted "[" as text, so "[\Qc[:(\E:]"
     * opens no POSIX class.
     */
    private function isUnquotedClassBracket(string $source, LiteralNode $node): bool
    {
        $start = $node->startPosition;

        return !$this->classQuoteOpen && 1 === $node->endPosition - $start && '[' === ($source[$start] ?? '');
    }

    /**
     * Follow the "\Q" and "\E" written between two items of a class, from
     * $from to $to: no node records them, and nothing else sits there but
     * the class opener, the "^" that negates it, the "-" of a range and,
     * under "xx", blanks, none of them a backslash, so the last one decides.
     * Each stretch is read once, which keeps a long quoted class linear.
     */
    private function readClassQuotes(int $from, int $to): void
    {
        if (null === $this->source || $to <= $from) {
            return;
        }

        $between = substr($this->source, $from, $to - $from);
        $open = strrpos($between, '\Q');
        $close = strrpos($between, '\E');
        if (false !== $open || false !== $close) {
            $this->classQuoteOpen = false === $close || (false !== $open && $open > $close);
        }
    }

    /**
     * A "[" inside a class starts a POSIX item when one closes after it:
     * "[[:alpha:]]" is known, "[[:foo:]]" and "[[.ch.]]" are errors.
     */
    /**
     * @param bool $isRangeEnd whether the item ends a range, as in "[a-[.x.]]"
     */
    private function validateBracketInClass(string $source, int $start, bool $isRangeEnd = false): void
    {
        $terminator = $this->findPosixTerminator($source, $start + 1);
        if (null === $terminator) {
            return;
        }

        // Past the element from PCRE2 10.45; before, on the "[" that opens
        // a collating element, or on the name of a class.
        $past = $this->supports(PcreFeature::PosixItemErrorPastItsEnd);

        // As a range end, "[a-[.x.]]", just inside its "[", and refused as a
        // range PCRE cannot make before its name is read: "[a-[:\d:]]", a
        // name the lexer reads as no class, too.
        if ($isRangeEnd) {
            $this->raiseSemanticError('Invalid range in character class: a POSIX item cannot end a range.', $past ? $terminator + 2 : $start + 1, ErrorCode::RangeInvalidBounds);
        }

        // After a hyphen that is a member, "[-[.x.]]", on its "[".
        if (':' !== $source[$start + 1]) {
            $this->raiseSemanticError('POSIX collating elements are not supported.', $past ? $terminator + 2 : $start, ErrorCode::PosixCollatingElement);
        }

        $name = substr($source, $start + 2, $terminator - $start - 2);
        if (!$this->isPosixClassName($name)) {
            $this->raiseSemanticError(
                \sprintf('Invalid POSIX class: "%s".', $name),
                $past ? $terminator + 2 : $start + 2 + (str_starts_with($name, '^') ? 1 : 0),
                ErrorCode::PosixInvalid,
            );
        }
    }

    /**
     * "[:alpha:]" written as a class of its own is a POSIX item outside a
     * class, which PCRE refuses rather than reading as a set of characters.
     */
    private function validatePosixOutsideClass(string $source, int $start): void
    {
        $terminator = $this->findPosixTerminator($source, $start + 1);
        if (null === $terminator) {
            return;
        }

        // Past its end from PCRE2 10.47, on its "[" before.
        $offset = $this->supports(PcreFeature::ErrorOffsetPastTheFault) ? $terminator + 2 : $start;

        if (':' === $source[$start + 1]) {
            $this->raiseSemanticError(
                'POSIX named classes are supported only within a class.',
                $offset,
                ErrorCode::PosixOutsideClass,
            );
        }

        $this->raiseSemanticError(
            'POSIX collating elements are not supported.',
            $offset,
            ErrorCode::PosixCollatingElement,
        );
    }

    /**
     * PCRE2's check_posix_syntax(): after "[:", "[." or "[=", find the
     * matching ":]", ".]" or "=]" before any "]" or a new "[:"-like opener.
     *
     * @return int|null the offset of the closing ":", "." or "="
     */
    private function findPosixTerminator(string $source, int $offset): ?int
    {
        return PosixItem::terminator($source, $offset - 1);
    }

    private function isPosixClassName(string $name): bool
    {
        return isset(self::VALID_POSIX_CLASSES[str_starts_with($name, '^') ? substr($name, 1) : $name]);
    }

    /**
     * The value an endpoint gives the range. Without Unicode mode PCRE reads
     * the pattern byte by byte, so a multibyte character written as is ends
     * a range start with its last byte and begins a range end with its first:
     * "[\u{e9}-\xe0]" is the range from 0xA9 to 0xE0.
     *
     * @param bool $isStart whether the node is the start of the range
     */
    private function rangeEndpointCodePoint(NodeInterface $node, bool $isStart): ?int
    {
        if ($node instanceof LiteralNode) {
            // Unreachable from a parsed pattern: the parser never gives a
            // range an empty endpoint. It guards a tree built by hand.
            if ('' === $node->value) {
                return null;
            }

            if (!$this->unicodeMode) {
                return \ord($isStart ? $node->value[\strlen($node->value) - 1] : $node->value[0]);
            }

            $codePoint = mb_ord($node->value, 'UTF-8');

            return false === $codePoint ? \ord($node->value) : $codePoint;
        }

        if ($node instanceof CharLiteralNode || $node instanceof ControlCharNode) {
            return $node->codePoint >= 0 ? $node->codePoint : null;
        }

        // Unreachable from a parsed pattern: guardRangeEndpoint() and
        // isSingleCharNode() leave only the node types above as endpoints.
        return null;
    }

    private function describeRange(RangeNode $node): string
    {
        $text = null === $this->source
            ? $this->describeRangeEndpoint($node->start).'-'.$this->describeRangeEndpoint($node->end)
            : substr($this->source, $node->startPosition, $node->getEndPosition() - $node->startPosition);

        // A range from the last byte of a character, without UTF, starts in
        // the middle of it: its bytes are spelled.
        return mb_check_encoding($text, 'UTF-8') ? $text : DisplayEscaper::escapeFragment($text, false);
    }

    private function describeRangeEndpoint(NodeInterface $node): string
    {
        return match (true) {
            $node instanceof LiteralNode => $node->value,
            $node instanceof CharLiteralNode => $node->originalRepresentation,
            $node instanceof ControlCharNode => '\c'.$node->char,
            default => '?',
        };
    }

    /**
     * The width of the character at an offset, which is how far PCRE steps
     * past it: one byte, or a whole UTF-8 sequence in Unicode mode.
     */
    private function characterLengthAt(string $source, int $position): int
    {
        if (!$this->unicodeMode) {
            return 1;
        }

        $byte = \ord($source[$position]);

        return match (true) {
            $byte >= 0xF0 => 4,
            $byte >= 0xE0 => 3,
            $byte >= 0xC0 => 2,
            default => 1,
        };
    }

    private function containsGraphemeCluster(NodeInterface $node): bool
    {
        return match (true) {
            $node instanceof CharTypeNode => 'X' === $node->value,
            $node instanceof SequenceNode => $this->anyContainsGraphemeCluster($node->children),
            $node instanceof AlternationNode => $this->anyContainsGraphemeCluster($node->alternatives),
            // A lookaround adds no length to the lookbehind around it, and a
            // nested lookbehind is checked on its own.
            $node instanceof GroupNode => !$this->isLookaround($node) && $this->containsGraphemeCluster($node->child),
            $node instanceof QuantifierNode => $this->containsGraphemeCluster($node->node),
            $node instanceof ConditionalNode => $this->anyContainsGraphemeCluster([$node->condition, $node->yes, $node->no]),
            default => false,
        };
    }

    private function isLookaround(GroupNode $node): bool
    {
        return \in_array($node->type, [
            GroupType::LookaheadPositive,
            GroupType::LookaheadNegative,
            GroupType::LookbehindPositive,
            GroupType::LookbehindNegative,
        ], true);
    }

    /**
     * @param array<NodeInterface> $nodes
     */
    private function anyContainsGraphemeCluster(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($this->containsGraphemeCluster($node)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where the opening run of start-of-pattern settings ends.
     */
    private function readStartOfPatternEnd(string $source): int
    {
        $names = implode('|', array_keys(StartOptions::NAMES));

        // The run may be empty, so the pattern always matches: the 0 branch
        // is unreachable and only there for the type.
        return 1 === LibraryPcre::match('/\A(?:\(\*(?:'.$names.')(?:=\d*+)?\))*+/', $source, $matches) ? \strlen($matches[0]) : 0;
    }

    /**
     * "(*TURKISH_CASING)" needs UTF mode, and does not go with
     * "(*CASELESS_RESTRICT)": PCRE2 refuses both where the opening run of
     * settings ends, before it reads the rest.
     */
    private function validateCasingSettings(RegexNode $node): void
    {
        if (null === $node->source || null === $this->startOfPatternEnd || !$this->supports(PcreFeature::CasingSettingVerbs)) {
            return;
        }

        $settings = substr($node->source, 0, $this->startOfPatternEnd);
        if (!str_contains($settings, '(*TURKISH_CASING)')) {
            return;
        }

        if (str_contains($settings, '(*CASELESS_RESTRICT)')) {
            $this->raiseSemanticError(
                '(*TURKISH_CASING) and (*CASELESS_RESTRICT) cannot be used together.',
                $this->startOfPatternEnd,
                ErrorCode::VerbConflictingCasings,
            );
        }

        if (!str_contains($node->flags, 'u') && 1 !== LibraryPcre::match('/\(\*UTF8?\)/', $settings)) {
            $this->raiseSemanticError(
                str_contains($settings, '(*UCP)')
                    ? '(*TURKISH_CASING) needs UTF mode: UCP alone is not enough.'
                    : '(*TURKISH_CASING) needs UTF mode.',
                $this->startOfPatternEnd,
                ErrorCode::VerbTurkishCasingWithoutUtf,
                'Add the "u" modifier.',
            );
        }
    }

    private function validateStartOfPatternPlacement(string $verbName, int $start): void
    {
        // One that starts where the run of well-formed settings ends is still
        // read there, and judged on its value; one in the body of "(*pla:...)"
        // stands where that body does.
        if (null === $this->startOfPatternEnd || $start + $this->positionOffset <= $this->startOfPatternEnd) {
            return;
        }

        // Anywhere else PCRE does not know the name, and stops where it ends.
        $this->raiseSemanticError(
            \sprintf('(*%s) is only recognized at the very start of the pattern.', $verbName),
            $start + 2 + \strlen($verbName),
            ErrorCode::VerbMisplaced,
            'Move it before anything else in the pattern.',
        );
    }

    /**
     * Step into the payload of "(*pla:...)", "(?*...)" or "(*sr:...)", which
     * spans $start to $end in the text read so far: its nodes count positions
     * from the payload's start, so the source read becomes the payload's and
     * errors are moved back by where it starts. Without a source, nothing
     * says where that is, and the source stays unread.
     */
    private function enterPayload(int $start, int $end): void
    {
        $source = $this->source;
        $this->source = null;
        if (null === $source) {
            return;
        }

        $colon = strpos($source, ':', $start);
        $payloadStart = '?' === ($source[$start + 1] ?? '') ? $start + 3 : (false === $colon ? null : $colon + 1);
        if (null === $payloadStart || $payloadStart > $end) {
            return;
        }

        $this->source = substr($source, $payloadStart, max(0, $end - 1 - $payloadStart));
        $this->positionOffset += $payloadStart;
    }

    /**
     * Where PCRE reports a conditional that holds more than two branches:
     * where the group opens, one character on for each character of a group
     * number past its first, as in "(?(+1)"; before PCRE2 10.47, on the name
     * the condition tests.
     */
    private function branchCountErrorOffset(ConditionalNode $node): int
    {
        $condition = $node->condition;
        if ($condition instanceof BackrefNode && 1 === LibraryPcre::match('/^[+-]?\d++$/', $condition->ref)) {
            return $node->startPosition + \strlen($condition->ref) - 1;
        }

        if ($this->supports(PcreFeature::BranchCountErrorOffTheConditionName)
            || !($condition instanceof BackrefNode || $condition instanceof SubroutineNode)) {
            return $node->startPosition;
        }

        // "(?(R&name)", "(?(<name>)" and "(?('name')": the name comes after
        // what introduces it.
        return $condition->startPosition + match (true) {
            $condition instanceof SubroutineNode && str_starts_with($condition->reference, 'R&') => 2,
            \in_array($this->source[$condition->startPosition] ?? '', ['<', "'"], true) => 1,
            default => 0,
        };
    }

    /**
     * A conditional with more than two branches, or a (DEFINE) with more
     * than one: PCRE counts them once it has resolved the references, so on
     * a walk from the pattern root the error waits for the walk to end.
     */
    private function raiseBranchCountError(string $message, int $position, ErrorCode $code, string $hint): void
    {
        $error = new SemanticErrorException($message, $code, $position + $this->positionOffset, $this->pattern, null, $hint);

        if (!$this->walkingPattern) {
            throw $error;
        }

        $this->lateErrors[2] ??= $error;
    }

    private static function holdsLookbehind(NodeInterface $node): bool
    {
        if ($node instanceof GroupNode && \in_array($node->type, [GroupType::LookbehindPositive, GroupType::LookbehindNegative], true)) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::holdsLookbehind($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The error of the earliest late pass, once nothing else went wrong.
     */
    private function raiseFirstLateError(): void
    {
        if ([] !== $this->lateErrors) {
            throw $this->lateErrors[min(array_keys($this->lateErrors))];
        }
    }

    /**
     * Measure a lookbehind in the pass PCRE runs once the whole pattern is
     * read: on a walk from the pattern root, an error found here waits for
     * the walk to end.
     */
    private function measureLookbehind(GroupNode $node): void
    {
        // Before PCRE2 10.34 the measure of a lookbehind skips a lookahead
        // whole: a lookbehind inside it is never measured.
        if ($this->lookaheadsInLookbehinds > 0 && !$this->supports(PcreFeature::LookbehindInLookaheadMeasured)) {
            return;
        }

        if (!$this->walkingPattern) {
            // Off a walk, the nodes measured on an earlier visit may be gone
            // and their ids taken by others.
            if (0 === $this->lookbehindDepth) {
                $this->lookbehindLength->forget();
            }
            $this->validateLookbehindLength($node);

            return;
        }

        // A lookbehind inside another is measured with it, where the measure
        // of the outer one reaches it: not after a "(*ACCEPT)" that ends its
        // branch, nor in a DEFINE group PCRE skips.
        if ($this->readingPrefix || $this->lookbehindDepth > 0) {
            return;
        }

        $this->measuringLookbehind = true;

        try {
            $this->validateLookbehindLength($node);
        } catch (SemanticErrorException $error) {
            $this->lateErrors[0] ??= $error;
        } finally {
            $this->measuringLookbehind = false;
        }
    }

    /**
     * A reference to a group the pattern does not have. PCRE resolves it
     * once the whole pattern is read, or while measuring the lookbehind it
     * sits in: on a walk from the pattern root, it waits for the walk to end.
     */
    /**
     * An error PCRE finds as it compiles the pattern, in the same pass as the
     * references to missing groups, where it meets $at: on a walk from the
     * pattern root, it waits for the walk to end.
     */
    private function raiseLateCompileError(string $message, int $position, ErrorCode $code, string $hint, int $at): void
    {
        $error = new SemanticErrorException($message, $code, $position + $this->positionOffset, $this->pattern, null, $hint);

        if (!$this->walkingPattern) {
            throw $error;
        }

        $this->keepFirstPassError($error, $at);
    }

    /**
     * Keep the error the first pass meets first: a `\K` in a lookaround is
     * found where the lookaround ends, after the references in it.
     */
    private function keepFirstPassError(SemanticErrorException $error, int $at): void
    {
        if ($at < $this->firstPassErrorAt) {
            $this->lateErrors[1] = $error;
            $this->firstPassErrorAt = $at;
        }
    }

    private function raiseMissingReference(string $message, int $position, ErrorCode $code, ?string $hint = null): void
    {
        $error = new SemanticErrorException($message, $code, $position + $this->positionOffset, $this->pattern, null, $hint);

        if (!$this->walkingPattern || $this->measuringLookbehind) {
            throw $error;
        }

        if ($this->lookbehindDepth > 0) {
            $this->lateErrors[0] ??= $error;

            return;
        }

        // The walk meets the references in the order the first pass does.
        if (!isset($this->lateErrors[1])) {
            $this->keepFirstPassError($error, $this->referenceAt);
        }
    }

    /**
     * Without UTF mode a name holds ASCII word characters only: PCRE stops
     * reading it on the first byte above 0x7F and refuses what follows there.
     */
    private function guardByteModeName(BackrefNode|SubroutineNode $node, string $name, ErrorCode $code): void
    {
        if ($this->unicodeMode || null === $this->source || 1 !== LibraryPcre::match('/[\x80-\xFF]/', $name)) {
            return;
        }

        $text = substr($this->source, $node->startPosition, $node->getEndPosition() - $node->startPosition);
        $position = $node->startPosition + strcspn($text, implode('', array_map(chr(...), range(0x80, 0xFF))));

        $this->raiseSemanticError(
            \sprintf('Group name "%s" holds a byte past ASCII, which ends a name without UTF mode.', $name),
            $position,
            $code,
        );
    }

    private function raiseSemanticError(string $message, int $position, ErrorCode $code, ?string $hint = null): never
    {
        throw new SemanticErrorException(
            $message,
            $code,
            $position + $this->positionOffset,
            $this->pattern,
            null,
            $hint,
        );
    }

    private function ensureGroupNumberingInitialized(): void
    {
        if (!isset($this->groupNumbering)) {
            $this->groupNumbering = new GroupNumbering(0, [], []);
        }
    }
}
