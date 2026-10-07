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
use PHPRegex\Parser\Analysis\GroupNumbering;
use PHPRegex\Parser\Analysis\GroupNumberingCollector;
use PHPRegex\Parser\Analysis\LengthRangeCalculator;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Internal\Ascii;
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\Internal\PcreVerb;
use PHPRegex\Parser\Internal\VersionCondition;
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
use PHPRegex\Parser\Node\QuantifierBounds;
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
use PHPRegex\Parser\RegexParser;
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
 */
final class Validator extends AbstractNodeVisitor
{
    // Maximum cache size to prevent memory leaks in long-running processes
    private const MAX_CACHE_SIZE = 1000;

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

    /**
     * How deep PCRE2 lets parentheses nest by default, which PHP keeps.
     */
    private const MAX_GROUP_NESTING = 250;

    /**
     * The largest compiled pattern PCRE2 takes with the two-byte links PHP
     * builds it with, in code units.
     */
    private const MAX_COMPILED_SIZE = 65536;

    /**
     * What every compiled pattern holds around its body: the opening and
     * closing brackets, and the end marker.
     */
    private const COMPILED_FRAME_SIZE = 7;

    /**
     * The opening and closing brackets of a compiled group.
     */
    private const COMPILED_GROUP_SIZE = 6;

    /**
     * What an optional copy of a counted group adds around the group: the
     * "may skip" marker and the brackets that nest the next copy.
     */
    private const COMPILED_OPTIONAL_COPY_SIZE = 7;

    /**
     * A size no pattern reaches: the floor stops growing there.
     */
    private const COMPILED_SIZE_CAP = 1 << 24;

    // Precomputed validation sets for maximum performance
    private const VALID_ASSERTIONS = [
        'A' => true, 'z' => true, 'Z' => true,
        'G' => true, 'b' => true, 'B' => true,
    ];

    private const VALID_PCRE_VERBS = [
        // Backtracking control verbs
        'FAIL' => true, 'F' => true, 'ACCEPT' => true, 'COMMIT' => true,
        'PRUNE' => true, 'SKIP' => true, 'THEN' => true,
        // Definition verb
        'DEFINE' => true,
        // Mark verb (with optional :NAME argument)
        'MARK' => true,
        // Mode setting verbs
        'UTF8' => true, 'UTF' => true, 'UCP' => true,
        // Newline conventions
        'CR' => true, 'LF' => true, 'CRLF' => true, 'ANYCRLF' => true, 'ANY' => true, 'NUL' => true,
        // BSR (backslash-R) conventions
        'BSR_ANYCRLF' => true, 'BSR_UNICODE' => true,
        // Optimization control
        'NO_AUTO_POSSESS' => true, 'NO_START_OPT' => true, 'NO_DOTSTAR_ANCHOR' => true,
        // Limit verbs
        'LIMIT_MATCH' => true, 'LIMIT_RECURSION' => true,
        'LIMIT_DEPTH' => true, 'LIMIT_HEAP' => true,
        // Script run verbs (also as lowercase aliases)
        'script_run' => true, 'sr' => true,
        'atomic_script_run' => true, 'asr' => true,
        // Empty match control
        'NOTEMPTY' => true, 'NOTEMPTY_ATSTART' => true,
        // JIT control (PCRE2)
        'NO_JIT' => true,
    ];

    /**
     * Settings PCRE2 only reads in the run of "(*...)" items that opens the
     * pattern; anywhere else they are not verbs at all. StartOptions holds
     * the same list, kept in step with this one.
     */
    private const START_OF_PATTERN_VERBS = [
        'UTF8' => true, 'UTF' => true, 'UCP' => true,
        'CR' => true, 'LF' => true, 'CRLF' => true, 'ANYCRLF' => true, 'ANY' => true, 'NUL' => true,
        'BSR_ANYCRLF' => true, 'BSR_UNICODE' => true,
        'NO_AUTO_POSSESS' => true, 'NO_START_OPT' => true, 'NO_DOTSTAR_ANCHOR' => true, 'NO_JIT' => true,
        'NOTEMPTY' => true, 'NOTEMPTY_ATSTART' => true,
        'LIMIT_MATCH' => true, 'LIMIT_RECURSION' => true, 'LIMIT_DEPTH' => true, 'LIMIT_HEAP' => true,
        'CASELESS_RESTRICT' => true, 'TURKISH_CASING' => true,
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
     * How many branches PCRE measures for one lookbehind before it gives up,
     * which it only reaches in a pattern with a branch reset: there it cannot
     * keep the length of a group once measured.
     */
    private const MAX_LOOKBEHIND_BRANCH_MEASURES = 1000;

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

    /**
     * The start-of-pattern settings, "(*UTF)" among them, that PCRE2 reads
     * before the pattern itself.
     */
    private const LEADING_UTF_VERB = '/\A(?:\(\*[A-Z_]++(?:=\d++)?\))*?\(\*UTF8?\)/';

    // Optimized state management with minimal memory footprint

    private bool $unicodeMode = false;

    /**
     * Whether the "u" flag itself is set: some refusals come from PHP's
     * handling of the flag, not from Unicode mode, so "(*UTF)" does not
     * trigger them.
     */
    private bool $unicodeFlag = false;

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
     * The groups each number and each name points to, for the length of a
     * call or a reference inside a lookbehind.
     *
     * @var array<int, list<GroupNode>>
     */
    private array $groupsByNumber = [];

    /**
     * @var array<string, list<GroupNode>>
     */
    private array $groupsByName = [];

    /**
     * The number the next group would take where each call or reference
     * sits, keyed by node, so "(?-1)" can be resolved.
     *
     * @var array<int, int>
     */
    private array $nextGroupNumberAt = [];

    /**
     * How many capturing groups open before each call or reference, keyed
     * by node, for a relative one checked out of the walk's order.
     *
     * @var array<int, int>
     */
    private array $captureIndexAt = [];

    private int $capturesIndexed = 0;

    /**
     * The groups a branch reset holds, by node: a back reference to one of
     * them has no length PCRE can know in a lookbehind.
     *
     * @var array<int, true>
     */
    private array $groupsInBranchReset = [];

    private bool $hasBranchReset = false;

    /**
     * The capturing groups around the node being visited, by node: calling
     * one of them from a lookbehind inside it is a recursion.
     *
     * @var array<int, true>
     */
    private array $enclosingGroups = [];

    private int $lookbehindBranchMeasures = 0;

    /**
     * The length of each group measured for a lookbehind, by node, with the
     * groups its measure called: a group called again, from the same
     * lookbehind or another, is not measured again while none of those is
     * being measured where it is called.
     *
     * @var array<int, array{length: array{0: int, 1: int|null}, calls: array<int, true>}>
     */
    private array $measuredGroupLengths = [];

    /**
     * The lookbehinds measured and found sound, by node, with the groups
     * their measure called: one inside another is measured with it, and not
     * again when the walk reaches it, unless it calls a group around it.
     *
     * @var array<int, array<int, true>>
     */
    private array $measuredLookbehinds = [];

    /**
     * The groups the measure in progress called, by node: whether one of
     * them is being measured is all that decides what the measure finds.
     *
     * @var array<int, true>
     */
    private array $calledWhileMeasuring = [];

    /**
     * How many times a measure called a group being measured already. A
     * measure during which this moved is not kept: what it found depends on
     * the groups being measured around it.
     */
    private int $lookbehindRecursions = 0;

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
     * whether a `\K` stands in it.
     *
     * @var list<bool>
     */
    private array $keepsInLookarounds = [];

    /**
     * How many lookbehinds the node being visited sits in.
     */
    private int $lookbehindDepth = 0;

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

    private GroupNumbering $groupNumbering;

    /**
     * @var array<int>
     */
    private array $captureSequence = [];

    private int $captureIndex = 0;

    private ?NodeInterface $previousNode = null;

    private ?NodeInterface $nextNode = null;

    /**
     * @var array<string, bool>
     */
    private static array $unicodePropCache = [];

    /**
     * @var array<string, array{0: int, 1: int}>
     */
    private static array $quantifierBoundsCache = [];

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
    }

    /**
     * Clears static caches. Useful for long-running processes or testing.
     */
    public static function clearCaches(): void
    {
        self::$unicodePropCache = [];
        self::$quantifierBoundsCache = [];
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
        $this->unicodeMode = $this->unicodeFlag || 1 === LibraryPcre::match(self::LEADING_UTF_VERB, $source);

        // Before PCRE2 10.45, "\N" ending a range is refused as the range.
        $nEndsRange = !$this->supports(PcreFeature::ClassNEndingRangeRefusedAsN);
        $afterRange = false;

        try {
            foreach ($tokens as $token) {
                // An escape that starts on the character at fault is not
                // read: PCRE2 10.47 reports past that character.
                if ($this->pastTheFault($token->position + 1) >= $limit) {
                    break;
                }

                if ($nEndsRange && $afterRange && TokenType::CharType === $token->type && 'N' === $token->value) {
                    continue;
                }

                $afterRange = TokenType::Range === $token->type
                    || ($afterRange && \in_array($token->type, [TokenType::QuoteModeStart, TokenType::QuoteModeEnd], true));

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
            return ($error->getPosition() ?? $limit) < $limit ? $error : null;
        } finally {
            $this->readingPrefix = false;
        }

        return null;
    }

    #[\Override]
    public function visitRegex(RegexNode $node): void
    {
        $this->walkPattern($node);
        $this->raiseFirstLateError();

        // PCRE measures the compiled pattern last, once it has read it all.
        if ($this->compiledSizeFloor($node->pattern) + self::COMPILED_FRAME_SIZE > self::MAX_COMPILED_SIZE) {
            $this->raiseSemanticError(
                'Regular expression is too large: PCRE would compile it to more than 64 KiB.',
                \strlen($node->source ?? ''),
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
        $total = \count($node->children);
        $last = null;

        foreach ($node->children as $index => $child) {
            $this->previousNode = $last;
            $this->nextNode = $index + 1 < $total ? $node->children[$index + 1] : null;
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

        if (GroupType::Capturing === $node->type || GroupType::Named === $node->type) {
            $this->captureIndex++;
        }

        $this->previousNode = null;
        $this->nextNode = null;

        $source = $this->source;
        $positionOffset = $this->positionOffset;
        if ($node->child->getStartPosition() <= $node->startPosition) {
            $this->enterPayload($node->startPosition, $node->endPosition);
        }

        $enclosingGroups = $this->enclosingGroups;
        if (GroupType::Capturing === $node->type || GroupType::Named === $node->type) {
            $this->enclosingGroups[spl_object_id($node)] = true;
        }

        if ($isLookbehind) {
            $this->lookbehindDepth++;
            $this->lookbehinds[] = $node;
        }

        $isLookaround = $this->isLookaround($node);
        if ($isLookaround) {
            $this->keepsInLookarounds[] = false;
        }

        // "(?i)" sets options for what follows and opens nothing.
        $nests = !$this->setsOptionsOnly($node);
        if ($nests) {
            $this->enterNesting($node->child->getStartPosition());
        }

        try {
            $node->child->accept($this);
        } finally {
            $this->source = $source;
            $this->positionOffset = $positionOffset;
            $this->enclosingGroups = $enclosingGroups;
            if ($nests) {
                $this->nestingDepth--;
            }
            if ($isLookbehind) {
                $this->lookbehindDepth--;
                array_pop($this->lookbehinds);
            }
            $holdsKeep = $isLookaround && array_pop($this->keepsInLookarounds);
        }

        // PCRE refuses the "\K" as it compiles the lookaround, once the whole
        // pattern is read, and reports it at the end of the pattern.
        if ($holdsKeep && $this->target->phpVersionId >= 80500) {
            $this->raiseLateCompileError(
                '\K is not allowed in a lookaround from PHP 8.5, which compiles without PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK.',
                $this->patternLength - $this->positionOffset,
                ErrorCode::KeepInLookaround,
                'Move the \K out of the lookaround.',
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
            $this->raiseSemanticError(
                \sprintf('The count "%s" after \N needs PCRE2 10.43, which PHP bundles from 8.4.', $node->quantifier),
                $node->node->getEndPosition(),
                ErrorCode::EscapeUnsupported,
                'Write the count as {n}, {n,} or {n,m} without spaces, or target PHP 8.4+.',
            );
        }

        // PCRE reads the item before its count: what it refuses there comes
        // first, and what it checks once the pattern is read waits.
        $node->node->accept($this);

        // Fast cached quantifier bounds parsing
        [$min, $max] = $this->getQuantifierBounds($node->quantifier);

        // PCRE caps repetition counts at 65535, and checks each number as
        // it reads it, before it compares the two.
        [$minEnd, $maxEnd] = $this->quantifierNumberEnds($node);
        if ($min > 65535 || $max > 65535) {
            $this->raiseSemanticError(
                \sprintf('Number too big in "%s" quantifier: PCRE allows at most 65535 repetitions.', $node->quantifier),
                $min > 65535 ? $minEnd : $maxEnd,
                ErrorCode::QuantifierTooBig,
            );
        }

        if (-1 !== $max && $min > $max) {
            $this->raiseSemanticError(
                \sprintf('Invalid quantifier range "%s": min > max.', $node->quantifier),
                $maxEnd,
                ErrorCode::QuantifierInvalidRange,
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
        if (1 !== \strlen($letter) || !Ascii::isAlpha($letter)
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

            // From PCRE2 10.47, "\N{" there is a character name, which PCRE
            // does not support, refused past the "{".
            if ('{' === ($this->source[$node->getEndPosition()] ?? '') && $this->supports(PcreFeature::ErrorOffsetPastTheFault)) {
                $this->raiseSemanticError(
                    'PCRE does not support the escape "\N{" (\F, \L, \l, \N{name}, \U and \u are not supported), in a character class either.',
                    $node->getEndPosition() + 1,
                    ErrorCode::EscapeUnsupported,
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
            array_pop($this->keepsInLookarounds);
            $this->keepsInLookarounds[] = true;
        }
    }

    /**
     * The operands of "(?[...])" are judged as the members of a class.
     */
    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node): void
    {
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

        $node->end->accept($this);

        // 3. Validation: order check, on the code points of both endpoints.
        // PCRE reports it where the range ends.
        $startCodePoint = $this->rangeEndpointCodePoint($node->start, true);
        $endCodePoint = $this->rangeEndpointCodePoint($node->end, false);
        if (null !== $startCodePoint && null !== $endCodePoint && $startCodePoint > $endCodePoint) {
            $this->raiseSemanticError(
                \sprintf('Invalid range "%s": start character comes after end character.', $this->describeRange($node)),
                $this->pastTheFault($node->getEndPosition()),
                ErrorCode::RangeReversed,
            );
        }
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): void
    {
        $this->ensureGroupNumberingInitialized();
        $this->validateReferenceBracePadding($node);

        $ref = $node->ref;

        $suggestions = $this->getNameSuggestions($ref);

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
            $this->assertRelativeReferenceExists((int) $ref, $this->missingReferenceOffset($node), ErrorCode::BackrefRelative, 'Condition');

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
            if (!$this->groupNumbering->hasNamedGroup($name)) {
                $suggestions = $this->getNameSuggestions($name);
                $this->raiseMissingReference(
                    \sprintf('Backreference to non-existent named group: "%s".', $name).$suggestions,
                    $this->missingReferenceOffset($node),
                    ErrorCode::BackrefMissingNamedGroup,
                );
            }

            return;
        }

        // Bare name validation (conditionals)
        if ($this->isBareNamedBackref($ref)) {
            if (!$this->groupNumbering->hasNamedGroup($ref)) {
                $suggestions = $this->getNameSuggestions($ref);
                $this->raiseMissingReference(
                    \sprintf('Backreference to non-existent named group: "%s".', $ref).$suggestions,
                    $node->startPosition,
                    ErrorCode::BackrefMissingNamedGroup,
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
            if ('0' === $numStr || '+0' === $numStr || '-0' === $numStr) {
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
                $this->assertRelativeReferenceExists($offset, $this->missingReferenceOffset($node), ErrorCode::BackrefRelative, 'Backreference');

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

        // "\k<1>": PCRE reads a name there, and a name never starts with a
        // digit. The tree spells "\g{1a}" the same way, but PCRE reads a
        // number after "\g{" and calls it a syntax error: the source tells.
        if (1 === LibraryPcre::match('/^\\\\k[<{\']\d/', $ref) && null !== $this->source && '\\k' === substr($this->source, $node->startPosition, 2)) {
            $this->raiseSemanticError(
                \sprintf('Group name after \k must not start with a digit: "%s".', $ref),
                $this->missingReferenceOffset($node),
                ErrorCode::GroupNameInvalid,
            );
        }

        $this->raiseSemanticError(
            \sprintf('Invalid backreference syntax: "%s".', $ref),
            $this->missingReferenceOffset($node),
            ErrorCode::BackrefInvalidSyntax,
        );
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): void
    {
        // "\N{name}": PCRE2 refuses a character name, whatever it names, on
        // the "{", past it from PCRE2 10.47; only "\N{U+...}" is a code point.
        if (CharLiteralType::UnicodeNamed === $node->type
            && 1 !== LibraryPcre::match('/^\\\\N\{[ \t]*+U\+/', $node->originalRepresentation)) {
            $this->raiseUnsupportedEscape('N{', $this->pastTheFault($node->startPosition + 3));
        }

        // "\N{U+...}" outside UTF mode is refused before its braces are read:
        // "\N{U+1 }" fails on the mode on every release, padding or not.
        if (CharLiteralType::UnicodeNamed === $node->type) {
            $this->validateUnicodeNamed($node);
        }

        $this->validatePaddedBraces($node);

        // "\x" with no digit: NUL up to PCRE2 10.44, refused from 10.45.
        if ('\\x' === $node->originalRepresentation && null !== $this->source) {
            $this->validateHexBraces($this->source, $node->startPosition + 2);
        }

        // The Lexer/Parser combination already ensures these are
        // syntactically valid. We validate the *value*.
        match ($node->type) {
            CharLiteralType::Unicode => $this->validateUnicode($node),
            CharLiteralType::Octal => $this->validateOctal($node),
            CharLiteralType::OctalLegacy => $this->validateOctalLegacy($node),
            CharLiteralType::UnicodeNamed => null,
        };

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

        // Intelligent caching with lazy validation and size limit
        if (!isset(self::$unicodePropCache[$key])) {
            // Prevent unbounded cache growth in long-running processes
            if (\count(self::$unicodePropCache) >= self::MAX_CACHE_SIZE) {
                self::$unicodePropCache = \array_slice(self::$unicodePropCache, -((int) (self::MAX_CACHE_SIZE / 2)), null, true);
            }
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
                );
            }

            return;
        }

        if (false === self::$unicodePropCache[$key]) {
            $propertyKey = $this->extractUnicodePropertyKey($key);
            $suggestion = $this->suggestUnicodeProperty($propertyKey);
            $message = \sprintf('Invalid or unsupported Unicode property: \\%s.', $key);
            if (null !== $suggestion) {
                $message .= " Did you mean \\{$suggestion}?";
            }
            // PCRE reads the whole escape before it looks the name up.
            $this->raiseSemanticError(
                $message,
                $node->getEndPosition(),
                ErrorCode::UnicodePropertyInvalid,
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
                    ErrorCode::ConditionMissingGroup,
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
        $this->validateSubroutineReference($node);
        $this->validateReturnedGroups($node);
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): void
    {
        $verbName = LibraryPcre::split('/[:=]/', $node->verb, 2)[0] ?? $node->verb;

        if (!isset(self::VALID_PCRE_VERBS[$verbName])
            && !(isset(self::PCRE_1045_SETTINGS[$verbName]) && $this->supports(PcreFeature::CasingSettingVerbs))) {
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
            // character after it an alphabetic assertion, whose name starts
            // with a lowercase letter, followed by no colon.
            $nameEnd = $node->startPosition + 2 + (1 === LibraryPcre::match('/^\w*+/', $verbName, $name) ? \strlen($name[0]) : 0);
            $this->raiseSemanticError(
                \sprintf('Invalid or unsupported PCRE verb: "%s".', $verbName),
                match (true) {
                    1 === LibraryPcre::match('/^[a-z]/', $verbName) && ':' !== ($this->source[$nameEnd] ?? '') => $this->pastTheFault($nameEnd + 1),
                    default => $nameEnd,
                },
                ErrorCode::VerbInvalid,
            );
        }

        // PCRE reports these at the closing parenthesis.
        $closing = $node->getEndPosition() - 1;

        if (isset(self::START_OF_PATTERN_VERBS[$verbName])) {
            $this->validateStartOfPatternPlacement($verbName, $node->startPosition);
        }

        if (isset(self::LIMIT_VERBS[$verbName]) && 1 !== LibraryPcre::match('/=\d++\z/', $node->verb)) {
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
                ErrorCode::ConditionVersionOperator,
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
                    ErrorCode::ConditionUnclosed,
                );
            }

            if (null !== $tooBig) {
                $this->raiseSemanticError(
                    \sprintf('Invalid version "%s" in a version condition: the number is too big.', $node->version),
                    $tooBig,
                    ErrorCode::ConditionVersionSyntax,
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
            ErrorCode::ConditionVersionSyntax,
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
            || (null !== $node->source && 1 === LibraryPcre::match(self::LEADING_UTF_VERB, $node->source));
        $this->groupNumbering = (new GroupNumberingCollector())->collect($node);
        $this->groupsByNumber = [];
        $this->groupsByName = [];
        $this->nextGroupNumberAt = [];
        $this->captureIndexAt = [];
        $this->capturesIndexed = 0;
        $this->groupsInBranchReset = [];
        $this->hasBranchReset = false;
        $this->enclosingGroups = [];
        $this->measuredGroupLengths = [];
        $this->measuredLookbehinds = [];
        $nextGroupNumber = 1;
        $this->indexGroups($node->pattern, $nextGroupNumber);
        $this->captureSequence = $this->groupNumbering->captureSequence;
        $this->captureIndex = 0;

        $this->previousNode = null;
        $this->nextNode = null;
        $this->lookbehindDepth = 0;
        $this->lookbehinds = [];
        $this->nestingDepth = 0;
        $this->keepsInLookarounds = [];
        $this->patternLength = \strlen($node->source ?? '');
        $this->positionOffset = 0;
        $this->lateErrors = [];
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
                str_starts_with($group, '+') => ($this->nextGroupNumberAt[spl_object_id($node)] ?? 1) - 1 + (int) $group <= $this->groupNumbering->maxGroupNumber,
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
                $this->assertRelativeReferenceExists($num, $this->missingReferenceOffset($node), ErrorCode::SubroutineRelativeMissing, 'Subroutine call');
            } else {
                $this->assertAbsoluteReferenceExists($num, $this->missingReferenceOffset($node), ErrorCode::SubroutineMissingGroup, 'Subroutine call');
            }

            return;
        }

        // Named reference: (?&name), (?P>name), \g<name>
        if (!$this->groupNumbering->hasNamedGroup($ref)) {
            $this->raiseMissingReference(
                \sprintf('Subroutine call to non-existent named group: "%s".', $ref),
                $this->missingReferenceOffset($node),
                ErrorCode::SubroutineMissingNamedGroup,
            );
        }
    }

    /**
     * The PCRE2 release that brought the property or script "\p{...}"
     * names, for a name whose support moved between releases; null for one
     * every release knows or refuses alike.
     */
    private function releaseAddingProperty(UnicodePropNode $node): ?string
    {
        $written = substr($this->source ?? '', $node->startPosition, $node->getEndPosition() - $node->startPosition);
        if (1 !== LibraryPcre::match('/^\\\\[pP]\{([ \t]*+)(\^?)(.*)\}$/s', $written, $matches)) {
            return null;
        }

        // Loose matching: case, spaces, hyphens and underscores are ignored,
        // and "sc=", "scx:" and their long forms only name the table.
        $name = strtolower(str_replace([' ', "\t", '-', '_'], '', $matches[3]));
        $name = LibraryPcre::replace('/^(?:sc|script|scx|scriptextensions)[:=]/', '', $name) ?? $name;

        return '' !== $matches[1] && '' !== $matches[2] ? '10.45' : (self::UNICODE_NAMES_SINCE[$name] ?? null);
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
     * Whether the group is "(?i)", options for the rest of the enclosing
     * group, rather than "(?i:...)".
     */
    private function setsOptionsOnly(GroupNode $node): bool
    {
        if (GroupType::InlineFlags !== $node->type || null === $this->source) {
            return false;
        }

        $flagsEnd = $node->startPosition + 2 + strspn($this->source, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ^-', $node->startPosition + 2);

        return ')' === ($this->source[$flagsEnd] ?? '');
    }

    /**
     * PCRE reads a (*LIMIT_...=n) value digit by digit and refuses the digit
     * that would take it past 4294967289, where it stops.
     */
    private function validateLimitValue(string $digits, int $start): void
    {
        $value = 0;
        foreach (str_split($digits) as $index => $digit) {
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
        foreach (str_split($digits) as $index => $digit) {
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
        foreach (str_split($digits) as $index => $digit) {
            $value = $value * 10 + (int) $digit;
            if ($value > 65535) {
                return $index + 1;
            }
        }

        return \strlen($digits);
    }

    private function extractUnicodePropertyKey(string $key): string
    {
        // Strip \p or \P prefix
        if (str_starts_with($key, '\\p') || str_starts_with($key, '\\P')) {
            $key = substr($key, 2);
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

    private function normalizeQuantifier(string $q): string
    {
        if (!str_starts_with($q, '{') || !str_ends_with($q, '}')) {
            return $q;
        }

        $inner = substr($q, 1, -1);
        $inner = LibraryPcre::replace('/\\s+/', '', $inner) ?? $inner;

        return '{'.$inner.'}';
    }

    private function getNameSuggestions(string $name): string
    {
        $available = array_keys($this->groupNumbering->namedGroups);
        $suggestions = [];
        foreach ($available as $avail) {
            if (levenshtein($name, $avail) <= 2) {
                $suggestions[] = $avail;
            }
        }
        if (!empty($suggestions)) {
            return ' Did you mean: '.implode(', ', $suggestions).'?';
        }

        return '';
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
            $codePoint = (int) hexdec($m[2]);
        } else {
            return; // Invalid format, skip
        }

        // PCRE reads every digit before it refuses the value, and reports it
        // at the closing brace.
        if ($codePoint > 0x10FFFF) {
            $this->raiseSemanticError(
                \sprintf('Invalid Unicode codepoint "%s" (out of range).', $node->originalRepresentation),
                $node->getEndPosition() - 1,
                ErrorCode::UnicodeOutOfRange,
            );
        }

        // Without Unicode mode a character is one byte, as for "\o{400}".
        if (!$this->unicodeMode && $codePoint > 0xFF) {
            $this->raiseSemanticError(
                \sprintf('Invalid code point "%s": without the "u" flag, a character is at most \xFF.', $node->originalRepresentation),
                $node->getEndPosition() - 1,
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
        // Unicode mode any valid codepoint is allowed.
        if ($this->unicodeMode) {
            if ($node->codePoint > 0x10FFFF) {
                $this->raiseSemanticError(
                    \sprintf('Invalid octal codepoint "%s" (out of Unicode range).', $node->originalRepresentation),
                    $node->getEndPosition() - 1,
                    ErrorCode::OctalOutOfRange,
                );
            }

            return;
        }

        if ($node->codePoint > 0xFF) {
            $this->raiseSemanticError(
                \sprintf('Invalid octal codepoint "%s".', $node->originalRepresentation),
                $node->getEndPosition() - 1,
                ErrorCode::OctalOutOfRange,
            );
        }
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

        // PCRE only supports \N{U+hhhh} in Unicode (/u) mode.
        if (!$this->unicodeMode && 1 === LibraryPcre::match('/^[ \t]*+U\+[0-9A-Fa-f]+[ \t]*+$/', $name)) {
            $this->raiseSemanticError(
                \sprintf('\N{%s} is only supported in Unicode mode; add the "u" flag.', $name),
                // From PCRE2 10.47, PCRE reads the escape to its closing
                // brace before it looks at the mode; before, past the "\N".
                $this->supports(PcreFeature::NamedCodePointReadBeforeModeCheck) ? $node->getEndPosition() : $node->startPosition + 2,
                ErrorCode::UnicodeNamedRequiresUtf,
            );
        }

        // As for "\x{...}", PCRE reads every digit before it refuses a value
        // above U+10FFFF, and reports it at the closing brace.
        if (1 === LibraryPcre::match('/^[ \t]*+U\+0*+([0-9A-Fa-f]++)[ \t]*+$/', $name, $digits)
            && (\strlen($digits[1]) > 6 || hexdec($digits[1]) > 0x10FFFF)) {
            $this->raiseSemanticError(
                \sprintf('Invalid Unicode codepoint "%s" (out of range).', $node->originalRepresentation),
                $node->getEndPosition() - 1,
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
     * Optimized Unicode property validation with error suppression.
     */
    private function validateUnicodeProperty(string $key): bool
    {
        if ($this->compileUnicodeProperty($key)) {
            return true;
        }

        if (null !== $mappedKey = $this->mapJavaUnicodeProperty($key)) {
            if ($this->compileUnicodeProperty($mappedKey)) {
                return true;
            }
        }

        // Fallback: map Block=/Blk= to In<block> alias which PCRE recognizes.
        if (LibraryPcre::match('/^p\\{(\\^)?bl(?:ock|k)=([^}]+)\\}$/i', $key, $matches)) {
            $negation = (string) $matches[1];
            $block = $matches[2];
            $aliasKey = 'p{'.$negation.'In'.$block.'}';
            // Try to compile the alias; if the runtime lacks block-name support,
            // still treat the property as syntactically valid.
            $this->compileUnicodeProperty($aliasKey);

            return true;
        }

        return false;
    }

    private function mapJavaUnicodeProperty(string $key): ?string
    {
        if (!LibraryPcre::match('/^p\\{(\\^)?([A-Za-z_][A-Za-z0-9_]*)\\}$/', $key, $matches)) {
            return null;
        }

        $negation = $matches[1];
        $property = strtolower($matches[2]);
        $aliases = [
            'javalowercase' => 'Ll',
            'javauppercase' => 'Lu',
            'javawhitespace' => 'White_Space',
            'javamirrored' => 'Bidi_Mirrored',
        ];

        if (!isset($aliases[$property])) {
            return null;
        }

        return 'p{'.$negation.$aliases[$property].'}';
    }

    private function compileUnicodeProperty(string $key): bool
    {
        return null === $this->engine->compile("/^\\{$key}$/u");
    }

    private function isSingleCharNode(NodeInterface $node): bool
    {
        return $node instanceof LiteralNode
            || $node instanceof CharLiteralNode
            || $node instanceof ControlCharNode;
        // CharTypeNode (e.g., \d) is technically invalid in a standard PCRE range start/end,
        // but we exclude it here to remain spec-compliant unless lenient mode is desired.
    }

    /**
     * Cached quantifier bounds parsing.
     *
     * @return array{0: int, 1: int}
     */
    private function getQuantifierBounds(string $q): array
    {
        $normalized = $this->normalizeQuantifier($q);
        // Return cached result if available
        if (isset(self::$quantifierBoundsCache[$normalized])) {
            return self::$quantifierBoundsCache[$normalized];
        }

        // Prevent unbounded cache growth in long-running processes
        if (\count(self::$quantifierBoundsCache) >= self::MAX_CACHE_SIZE) {
            self::$quantifierBoundsCache = \array_slice(self::$quantifierBoundsCache, -((int) (self::MAX_CACHE_SIZE / 2)), null, true);
        }

        // Compute and cache the result
        $bounds = $this->parseQuantifierBounds($normalized);
        self::$quantifierBoundsCache[$normalized] = $bounds;

        return $bounds;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parseQuantifierBounds(string $q): array
    {
        $bounds = QuantifierBounds::parse($q);

        return null === $bounds ? [1, 1] : [$bounds->min, $bounds->max ?? -1];
    }

    private function calculateFixedLength(NodeInterface $node): ?int
    {
        return match (true) {
            $node instanceof LiteralNode => mb_strlen($node->value),
            $node instanceof CharTypeNode, $node instanceof DotNode => 1,
            $node instanceof AnchorNode, $node instanceof AssertionNode => 0,
            $node instanceof SequenceNode => $this->calculateSequenceLength($node),
            $node instanceof GroupNode => $this->calculateFixedLength($node->child),
            $node instanceof QuantifierNode => $this->calculateQuantifierLength($node),
            $node instanceof CharClassNode => 1,
            $node instanceof AlternationNode => null, // Handled separately
            default => null, // Unknown or variable
        };
    }

    private function calculateSequenceLength(SequenceNode $node): ?int
    {
        $total = 0;
        foreach ($node->children as $child) {
            $length = $this->calculateFixedLength($child);
            if (null === $length) {
                return null; // Variable length
            }
            $total += $length;
        }

        return $total;
    }

    private function calculateQuantifierLength(QuantifierNode $node): ?int
    {
        [$min, $max] = $this->parseQuantifierBounds($node->quantifier);

        // Only fixed if min == max (and both are not -1)
        if ($min !== $max || -1 === $max) {
            return null; // Variable length
        }

        $childLength = $this->calculateFixedLength($node->node);
        if (null === $childLength) {
            return null;
        }

        return $min * $childLength;
    }

    /**
     * @param array<int, true>|null $expanding the groups being measured, by
     *                                         node, when the lookbehind sits
     *                                         in one being measured
     */
    private function validateLookbehindLength(GroupNode $node, ?array $expanding = null): void
    {
        // A group the lookbehind sits in is being measured already.
        $measuring = $expanding ?? $this->enclosingGroups;
        if (null === $expanding) {
            $this->calledWhileMeasuring = [];
        }

        // A lookbehind measured inside another is not measured again, unless
        // it calls a group being measured here and was not there.
        $id = spl_object_id($node);
        $measured = $this->measuredLookbehinds[$id] ?? null;
        if (null !== $measured && !self::callsAnyOf($measured, $measuring)) {
            $this->calledWhileMeasuring += $measured;

            return;
        }

        $calledAround = $this->calledWhileMeasuring;
        $this->calledWhileMeasuring = [];
        $recursions = $this->lookbehindRecursions;

        // "\X" matches a whole grapheme cluster, of no bounded length.
        if ($this->containsGraphemeCluster($node->child)) {
            $this->raiseSemanticError(
                'Lookbehind is unbounded: \X matches a grapheme cluster of any length.',
                $this->lookbehindErrorPosition($node),
                ErrorCode::LookbehindUnbounded,
                'Match the characters the cluster may hold instead of \X.',
            );
        }

        // PCRE measures each top-level branch on its own: "(?<=a{300}|b)" is
        // two fixed lengths, "(?<=(?:a{300}|b))" one variable length.
        $branches = $node->child instanceof AlternationNode ? $node->child->alternatives : [$node->child];
        if (null === $expanding) {
            $this->lookbehindBranchMeasures = 0;
        }
        $lengths = [];
        foreach ($branches as $branch) {
            $length = $this->lookbehindLength($branch, $measuring);
            $lengths[] = $length;

            // PCRE stops at the first branch it cannot bound.
            if (null === $length[1]) {
                $this->validateLookbehindBranchLength($node, $length, true);
            }

            if ($this->lookbehindBranchMeasures > self::MAX_LOOKBEHIND_BRANCH_MEASURES) {
                $this->raiseSemanticError(
                    'Lookbehind is too complicated: in a pattern with a branch reset, PCRE gives up measuring it.',
                    $this->lookbehindErrorPosition($node),
                    ErrorCode::LookbehindTooComplex,
                    'Call fewer groups from the lookbehind, or drop the branch reset.',
                );
            }
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

        // In a pattern with a branch reset PCRE measures again, and counts.
        if (!$this->hasBranchReset && $recursions === $this->lookbehindRecursions) {
            $this->measuredLookbehinds[$id] = $this->calledWhileMeasuring;
        }
        $this->calledWhileMeasuring += $calledAround;
    }

    /**
     * Whether a measure that called the groups $called, none of them being
     * measured then, would call one of $measuring: a measure that calls
     * none holds wherever it is taken.
     *
     * @param array<int, true> $called
     * @param array<int, true> $measuring
     */
    private static function callsAnyOf(array $called, array $measuring): bool
    {
        foreach (array_keys($measuring) as $group) {
            if (isset($called[$group])) {
                return true;
            }
        }

        return false;
    }

    /**
     * PHP 8.4.25 and 8.5.10 compile "u" patterns with PCRE2_NEVER_BACKSLASH_C
     * (GH-21134); earlier releases compile "\C" but in a lookbehind.
     */
    private function refusesBackslashCUnderUtf(): bool
    {
        $php = $this->target->phpVersionId;

        return $php >= 80510 || ($php >= 80425 && $php < 80500);
    }

    /**
     * Where PCRE reports a lookbehind it cannot take: its "(", or the last
     * letter of the name of "(*plb:...)" and its kin.
     */
    private function lookbehindErrorPosition(GroupNode $node): int
    {
        $start = $node->startPosition;
        if (null !== $this->pattern && 1 === LibraryPcre::match('/\G\(\*(\w++):/', $this->pattern, $name, 0, $start)) {
            return $start + \strlen($name[1]) - 1;
        }

        return $start;
    }

    /**
     * @param array{0: int, 1: int|null} $lengthRange
     */
    private function validateLookbehindBranchLength(GroupNode $node, array $lengthRange, bool $variable): void
    {
        [$min, $max] = $lengthRange;

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

        if (!$this->supportsVariableLengthLookbehind() && $min !== $max) {
            $this->raiseSemanticError(
                'Variable-length lookbehind needs PCRE2 10.43, which PHP bundles from 8.4.',
                $this->lookbehindErrorPosition($node),
                ErrorCode::LookbehindVariableLengthNotSupported,
                'Give each branch of the lookbehind a fixed length, or target PHP 8.4+.',
            );
        }

        // A fixed length is only capped by PCRE itself; a variable one by
        // max_lookbehind_length, which stands for PCRE2's max_varlookbehind.
        if (!$variable && $max > self::MAX_FIXED_LOOKBEHIND_LENGTH) {
            $this->raiseSemanticError(
                \sprintf('Lookbehind is too long: PCRE takes a fixed-length lookbehind of at most %d characters (length=%d).', self::MAX_FIXED_LOOKBEHIND_LENGTH, $max),
                $this->lookbehindErrorPosition($node),
                ErrorCode::LookbehindTooLong,
                'Shorten the lookbehind.',
            );
        }

        if ($variable && $max > $this->maxLookbehindLength) {
            $this->raiseSemanticError(
                \sprintf('Lookbehind exceeds the maximum length of %d (max=%d).', $this->maxLookbehindLength, $max),
                $this->lookbehindErrorPosition($node),
                ErrorCode::LookbehindTooLong,
                'Reduce the lookbehind length, or raise max_lookbehind_length.',
            );
        }
    }

    /**
     * The length range of a lookbehind branch, the way PCRE measures it: a
     * call or a reference is as long as the group it names, a lookaround is
     * zero-width however often it is repeated, and a call back into a group
     * being measured has no bound.
     *
     * It counts the branches it measures as it goes, hence impure.
     *
     * @param array<int, true> $expanding the groups being measured, by node
     *
     * @return array{0: int, 1: int|null}
     *
     * @phpstan-impure
     */
    private function lookbehindLength(NodeInterface $node, array $expanding): array
    {
        if ($node instanceof SequenceNode) {
            [$min, $max] = [0, 0];
            foreach ($node->children as $child) {
                [$childMin, $childMax] = $this->lookbehindLength($child, $expanding);
                $min += $childMin;
                $max = null === $childMax ? null : $max + $childMax;

                // PCRE stops measuring at the first item it cannot bound.
                if (null === $max) {
                    break;
                }
            }

            return [$min, $max];
        }

        if ($node instanceof AlternationNode || $node instanceof ConditionalNode) {
            $alternatives = $node instanceof AlternationNode ? $node->alternatives : [$node->yes, $node->no];
            [$min, $max] = [\PHP_INT_MAX, 0];
            foreach ($alternatives as $alternative) {
                [$altMin, $altMax] = $this->lookbehindLength($alternative, $expanding);
                $min = min($min, $altMin);
                $max = null === $altMax ? null : max($max, $altMax);

                if (null === $max) {
                    break;
                }
            }

            return [$min, $max];
        }

        if ($node instanceof GroupNode) {
            if ($this->isLookaround($node)) {
                $this->validateNestedLookbehinds($node, $expanding);

                return [0, 0];
            }

            $this->countLookbehindBranches($node->child);

            return $this->lookbehindLength($node->child, $expanding);
        }

        if ($node instanceof QuantifierNode) {
            [$childMin, $childMax] = $this->lookbehindLength($node->node, $expanding);
            [$qMin, $qMax] = $this->getQuantifierBounds($node->quantifier);

            // A repeated lookahead, "(?=.)*" or "(*pla:.)+", adds nothing.
            // Only a lookahead read directly under the quantifier does: a
            // repeated lookbehind, a lookahead inside another group, or a
            // repeated "(*ACCEPT)" has no bound, as PCRE measures them.
            if ($node->node instanceof GroupNode && \in_array($node->node->type, [
                GroupType::LookaheadPositive,
                GroupType::LookaheadNegative,
            ], true)) {
                return [0, 0];
            }

            // Before PCRE2 10.43, a group of variable length stays variable
            // even repeated zero times.
            if (0 === $qMax && $childMin !== $childMax && !$this->supportsVariableLengthLookbehind()) {
                return [0, $childMax];
            }

            return [$childMin * $qMin, null === $childMax || -1 === $qMax ? null : $childMax * $qMax];
        }

        if ($node instanceof DefineNode) {
            return [0, 0];
        }

        // "\X" is a grapheme cluster of any length, here or in a group a call
        // or a reference reaches.
        if ($node instanceof CharTypeNode && 'X' === $node->value) {
            return [0, null];
        }

        if ($node instanceof SubroutineNode || $node instanceof BackrefNode) {
            return $this->referencedGroupLength($node, $expanding);
        }

        return $node->accept(new LengthRangeCalculator($this->unicodeMode));
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
            default => [],
        };

        foreach ($children as $child) {
            $this->validateNestedLookbehinds($child, $expanding);
        }
    }

    /**
     * @param array<int, true> $expanding
     *
     * @return array{0: int, 1: int|null}
     */
    private function referencedGroupLength(SubroutineNode|BackrefNode $node, array $expanding): array
    {
        $groups = $node instanceof SubroutineNode ? $this->groupsCalledBy($node) : $this->groupsReferencedBy($node);

        // PCRE checks that the group exists as it measures the lookbehind,
        // counting relative references from where they stand. A numbered
        // back reference in a pattern with a branch reset it does not
        // measure at all, unless it failed already while being read: one
        // back past the first group, or to group zero.
        $unmeasured = $node instanceof BackrefNode
            && $this->hasBranchReset
            && !str_starts_with($node->ref, '\\k')
            && 1 !== LibraryPcre::match('/^\\\\g[{<\']?\s*+(?:-|[+-]?0++(?!\d))/', $node->ref);
        if ([] === $groups && !$unmeasured) {
            $captureIndex = $this->captureIndex;
            $this->captureIndex = $this->captureIndexAt[spl_object_id($node)] ?? $captureIndex;

            try {
                $node->accept($this);
            } finally {
                $this->captureIndex = $captureIndex;
            }
        }

        // No group, a whole-pattern recursion, or a reference to a name that
        // several groups share: PCRE finds no bound.
        if (1 !== \count($groups)) {
            return [0, null];
        }

        $group = $groups[0];
        $id = spl_object_id($group);
        if (isset($expanding[$id])) {
            $this->lookbehindRecursions++;

            return [0, null];
        }

        // A back reference into a branch reset: PCRE cannot tell which of
        // the groups sharing the number it points to.
        if ($node instanceof BackrefNode && isset($this->groupsInBranchReset[$id])) {
            return [0, null];
        }

        $this->calledWhileMeasuring[$id] = true;

        // A group measured already, from this call or another, is as long
        // here unless it calls a group being measured here: one group
        // calling the next twice, k levels down, is measured k times, not
        // 2^k.
        $measured = $this->measuredGroupLengths[$id] ?? null;
        if (null !== $measured && !self::callsAnyOf($measured['calls'], $expanding)) {
            $this->calledWhileMeasuring += $measured['calls'];

            return $measured['length'];
        }

        $this->countLookbehindBranches($group->child);

        $calledAround = $this->calledWhileMeasuring;
        $this->calledWhileMeasuring = [];
        $recursions = $this->lookbehindRecursions;
        $length = $this->lookbehindLength($group->child, $expanding + [$id => true]);

        // In a pattern with a branch reset PCRE measures again, and counts.
        if (!$this->hasBranchReset && $recursions === $this->lookbehindRecursions) {
            $this->measuredGroupLengths[$id] = ['length' => $length, 'calls' => $this->calledWhileMeasuring];
        }
        $this->calledWhileMeasuring += $calledAround;

        return $length;
    }

    /**
     * Count the branches of a group PCRE measures for a lookbehind; it only
     * gives up once a branch reset stops it from reusing a measure.
     */
    private function countLookbehindBranches(NodeInterface $groupBody): void
    {
        if ($this->hasBranchReset) {
            $this->lookbehindBranchMeasures += $groupBody instanceof AlternationNode ? \count($groupBody->alternatives) : 1;
        }
    }

    /**
     * @return list<GroupNode>
     */
    private function groupsCalledBy(SubroutineNode $node): array
    {
        $reference = $node->reference;

        if (1 === LibraryPcre::match('/^[+-]\d++$/', $reference)) {
            $next = $this->nextGroupNumberAt[spl_object_id($node)] ?? null;
            if (null === $next) {
                // Unreachable from a parsed pattern: every call in the tree
                // was indexed when the regex was visited. It guards a tree
                // visited without its root.
                return [];
            }

            $offset = (int) $reference;

            // A call measures the first group bearing the number, as PCRE
            // does in a branch reset.
            return \array_slice($this->groupsByNumber[$offset < 0 ? $next + $offset : $next + $offset - 1] ?? [], 0, 1);
        }

        if (1 === LibraryPcre::match('/^\d++$/', $reference)) {
            return \array_slice($this->groupsByNumber[(int) $reference] ?? [], 0, 1);
        }

        // A name, called once whichever group bears it first.
        return \array_slice($this->groupsByName[$reference] ?? [], 0, 1);
    }

    /**
     * @return list<GroupNode>
     */
    private function groupsReferencedBy(BackrefNode $node): array
    {
        $ref = $node->ref;

        if (1 === LibraryPcre::match('/^\\\\g(?:\{([+-]\d++)\}|\'([+-]\d++)\'|([+-]\d++))$/', $ref, $matches)) {
            $next = $this->nextGroupNumberAt[spl_object_id($node)] ?? null;
            $offset = (int) ($matches[1].($matches[2] ?? '').($matches[3] ?? ''));

            if (null === $next || 0 === $offset) {
                return [];
            }

            // "-1" is the group before the reference, "+1" the one after it.
            return $this->groupsByNumber[$offset < 0 ? $next + $offset : $next + $offset - 1] ?? [];
        }

        if (1 === LibraryPcre::match('/^\\\\(?:g\{(\d++)\}|g\'(\d++)\'|g?(\d++))$/', $ref, $matches)) {
            return $this->groupsByNumber[(int) ($matches[1].($matches[2] ?? '').($matches[3] ?? ''))] ?? [];
        }

        if (1 === LibraryPcre::match('/^\\\\k[<{\']('.self::GROUP_NAME.')[>}\']$/u', $ref, $matches)) {
            return $this->groupsByName[$matches[1]] ?? [];
        }

        // Unreachable from a parsed pattern: the parser spells a reference
        // one of the ways above. It guards a hand-built one.
        return [];
    }

    /**
     * Number the capturing groups as PCRE does, branch resets included, and
     * note where each call or reference sits in that count.
     */
    private function indexGroups(NodeInterface $node, int &$nextGroupNumber, bool $inBranchReset = false): void
    {
        if ($node instanceof SubroutineNode || $node instanceof BackrefNode) {
            $this->nextGroupNumberAt[spl_object_id($node)] = $nextGroupNumber;
            $this->captureIndexAt[spl_object_id($node)] = $this->capturesIndexed;

            return;
        }

        if ($node instanceof GroupNode && GroupType::BranchReset === $node->type) {
            $this->hasBranchReset = true;
            $base = $nextGroupNumber;
            $highest = $base;
            $branches = $node->child instanceof AlternationNode ? $node->child->alternatives : [$node->child];
            foreach ($branches as $branch) {
                $nextGroupNumber = $base;
                $this->indexGroups($branch, $nextGroupNumber, true);
                $highest = max($highest, $nextGroupNumber);
            }
            $nextGroupNumber = $highest;

            return;
        }

        if ($node instanceof GroupNode) {
            // "(*scs:(+1)...)" counts groups from where it stands.
            if (GroupType::ScanSubstring === $node->type) {
                $this->nextGroupNumberAt[spl_object_id($node)] = $nextGroupNumber;
            }

            if (GroupType::Capturing === $node->type || GroupType::Named === $node->type) {
                $this->capturesIndexed++;
                $this->groupsByNumber[$nextGroupNumber++][] = $node;
                if (null !== $node->name) {
                    $this->groupsByName[$node->name][] = $node;
                }
                if ($inBranchReset) {
                    $this->groupsInBranchReset[spl_object_id($node)] = true;
                }
            }

            $this->indexGroups($node->child, $nextGroupNumber, $inBranchReset);

            return;
        }

        $children = match (true) {
            $node instanceof SequenceNode => $node->children,
            $node instanceof AlternationNode => $node->alternatives,
            $node instanceof QuantifierNode => [$node->node],
            $node instanceof ConditionalNode => [$node->condition, $node->yes, $node->no],
            $node instanceof DefineNode => [$node->content],
            $node instanceof ScriptRunNode && null !== $node->content => [$node->content],
            default => [],
        };

        foreach ($children as $child) {
            $this->indexGroups($child, $nextGroupNumber, $inBranchReset);
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

        // A space before "U+" makes "\N{" a name, which PCRE2 refuses past
        // the "\N", as "\N{foo}".
        $name = CharLiteralType::UnicodeNamed === $node->type && 1 === LibraryPcre::match('/^\\\\N\{[ \t]/', $representation);
        [$code, $escape] = match (true) {
            CharLiteralType::Octal === $node->type => [ErrorCode::OctalInvalidDigit, '\o{}'],
            $name => [ErrorCode::EscapeUnsupported, '\N{U+}'],
            CharLiteralType::UnicodeNamed === $node->type => [ErrorCode::UnicodeInvalidDigit, '\N{U+}'],
            default => [ErrorCode::UnicodeInvalidDigit, '\x{}'],
        };

        $this->raiseSemanticError(
            \sprintf('Spaces inside %s need PCRE2 10.43, which PHP bundles from 8.4.', $escape),
            $node->startPosition + ($name ? 2 : $space),
            $code,
            'Write the escape without spaces, or target PHP 8.4+.',
        );
    }

    /**
     * "\g{ 1 }" and "\k{ name }" need PCRE2 10.43. Before, PCRE stops on the
     * space after the "{", or, with no space there, where "\g" wants a
     * reference and "\k{" the end of the name.
     */
    private function validateReferenceBracePadding(BackrefNode $node): void
    {
        if (null === $this->source || $this->supports(PcreFeature::PaddedBracedEscapes)) {
            return;
        }

        $written = substr($this->source, $node->startPosition, $node->getEndPosition() - $node->startPosition);
        if (1 !== LibraryPcre::match('/^\\\\([gk])\{([ \t]*+)([^ \t}]*+)([ \t]?)/', $written, $matches)
            || '' === $matches[2].$matches[4]) {
            return;
        }

        $offset = match (true) {
            '' !== $matches[2] => 3,
            'g' === $matches[1] => 2,
            default => 3 + \strlen($matches[3]),
        };

        $this->raiseSemanticError(
            \sprintf('Spaces inside \%s{} need PCRE2 10.43, which PHP bundles from 8.4.', $matches[1]),
            $node->startPosition + $offset,
            ErrorCode::BackrefInvalidSyntax,
            'Remove the spaces inside the braces, or target PHP 8.4+.',
        );
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
            [, $max] = $this->getQuantifierBounds($node->quantifier);
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
            if ('' === $matches[4]) {
                return $start + 3;
            }

            // "\k<5ghj>" names no group: a name cannot start with a digit.
            if ('k' === $matches[1]) {
                return $this->pastTheFault($start + 4);
            }

            // A number past 65535 is refused on the brace that opens it.
            $tooBig = $this->groupNumberTooBigOffset($start + \strlen($matches[0]) - \strlen($matches[4]));
            if (null !== $tooBig) {
                return '' === $matches[2] ? $tooBig : $start + 2;
            }

            // "\g'3gh'": the number read, what should close it is missing;
            // before PCRE2 10.47, "\g" itself is refused, past the "g".
            $closing = ['{' => '}', '<' => '>', "'" => "'", '' => ''][$matches[2]];
            $rest = ltrim(substr($text, \strlen($matches[0])));
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
            $number = (int) $matches[4] + ('+' === $matches[3] ? ($this->nextGroupNumberAt[spl_object_id($node)] ?? 1) - 1 : 0);

            return '-' === $matches[3] || 0 === $number || $number > 65535 ? $end : $this->pastTheFault($end);
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
            $number += ($this->nextGroupNumberAt[spl_object_id($node)] ?? 1) - 1;
        }

        if ($number > 65535) {
            $this->raiseSemanticError(
                \sprintf('Group number %s%s is too big: PCRE takes at most 65535.', $sign, $digits),
                $this->missingReferenceOffset($node),
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

    private function assertRelativeReferenceExists(int $offset, int $position, ErrorCode $code, string $context): void
    {
        if (0 === $offset) {
            $this->raiseSemanticError(
                \sprintf('%s relative reference cannot be zero.', $context),
                $position,
                $code,
            );
        }

        $index = $offset > 0 ? $this->captureIndex + $offset - 1 : $this->captureIndex + $offset;

        // A reference forward names a group PCRE has not read yet: it is
        // resolved with the references by number, once the pattern is read.
        if ($offset > 0 && $index >= \count($this->captureSequence)) {
            $this->raiseMissingReference(
                \sprintf('%s relative reference %d is outside the range of available capture groups.', $context, $offset),
                $position,
                $code,
            );

            return;
        }

        if ($index < 0 || $index >= \count($this->captureSequence)) {
            $this->raiseSemanticError(
                \sprintf('%s relative reference %d is outside the range of available capture groups.', $context, $offset),
                $position,
                $code,
                'Check group numbering or remove the relative reference.',
            );
        }
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
            default => $position + $this->characterLengthAt($source, $position),
        };

        $this->raiseMalformedPropertyAt($letter, $offset);
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
        $position = $brace + 1;
        $negated = false;
        $read = 0;

        while ($read < self::MAX_PROPERTY_NAME_LENGTH) {
            if ($position >= $length) {
                return $length;
            }

            $char = $source[$position];
            $step = $this->characterLengthAt($source, $position);
            $position += $step;

            if (str_contains(" _-\t\n\v\f\r", $char)) {
                continue;
            }

            if (0 === $read && !$negated && '^' === $char) {
                $negated = true;

                continue;
            }

            if ('}' === $char) {
                return null;
            }

            // Names hold "&" to "z" only; 10.45 and 10.46 stopped past the
            // first byte of a longer UTF-8 character, 10.47 past all of it.
            if (($char < '&' || $char > 'z') && $this->supports(PcreFeature::MalformedPropertyName)) {
                return $this->supports(PcreFeature::ErrorOffsetPastTheFault) ? $position : $position - $step + 1;
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
        if ('{' !== ($source[$position] ?? '')) {
            $this->raiseSemanticError(
                'Missing opening brace after \o.',
                $position,
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
                // From PCRE2 10.47, PCRE reads to the closing brace before it
                // looks at the mode; before, it stops past the "\N".
                $end = $digits + strspn($source, self::HEX_DIGITS.self::BRACE_PADDING, $digits);
                $this->raiseSemanticError(
                    '\N{U+...} is only supported in Unicode mode; add the "u" flag.',
                    $this->supports(PcreFeature::NamedCodePointReadBeforeModeCheck) ? ('}' === ($source[$end] ?? '') ? $end + 1 : $end) : $position,
                    ErrorCode::UnicodeNamedRequiresUtf,
                );
            }

            $this->validateBracedDigits($source, $digits, self::HEX_DIGITS, false, ErrorCode::UnicodeInvalidDigit, '\N{U+}');

            // Reached only when the digits are left unjudged, "\N{U+ }": a
            // well-formed "\N{U+...}" is a token of its own.
            return;
        }

        if (1 !== LibraryPcre::match(self::REPEAT_COUNT, $source, $matches, 0, $position)) {
            $this->raiseUnsupportedEscape('N{', $this->pastTheFault($position + 1));
        }
    }

    /**
     * Whether "{U+" follows, which PCRE2 10.48 also reads with spaces or tabs
     * after the brace: "\N{ U+41}".
     */
    private function startsNamedCodePoint(string $source, int $position): bool
    {
        return '{' === ($source[$position] ?? '')
            && 'U+' === substr($source, $position + 1 + strspn($source, self::BRACE_PADDING, $position + 1), 2);
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

        if ($leadingPadding) {
            $position += strspn($source, self::BRACE_PADDING, $position);
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

        $position += strspn($source, $digits, $position);
        $position += strspn($source, self::BRACE_PADDING, $position);

        // Unreachable from a parsed pattern: the lexer reads every braced
        // escape of this shape as a token of its own, so only a malformed one
        // gets here. It guards a caller that passes a well-formed one.
        if ($position < $length && '}' === $source[$position]) {
            return;
        }

        $this->raiseSemanticError(
            \sprintf('Invalid character in %s, or closing brace missing.', $escape),
            $this->braceFaultOffset($source, $position),
            $invalidDigitCode,
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
        if (':' !== $source[$start + 1]) {
            // As a range end, "[a-[.x.]]", just inside its "[", and refused
            // as a range PCRE cannot make.
            $position = $past ? $terminator + 2 : $start + ('-' === ($source[$start - 1] ?? '') ? 1 : 0);
            if ($isRangeEnd) {
                $this->raiseSemanticError('Invalid range in character class: a POSIX collating element cannot end a range.', $position, ErrorCode::RangeInvalidBounds);
            }

            $this->raiseSemanticError('POSIX collating elements are not supported.', $position, ErrorCode::PosixCollatingElement);
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
        $terminator = $source[$offset] ?? '';
        if (':' !== $terminator && '.' !== $terminator && '=' !== $terminator) {
            return null;
        }

        $length = \strlen($source);
        for ($position = $offset + 1; $length - $position >= 2; $position++) {
            $char = $source[$position];
            $next = $source[$position + 1];

            if ('\\' === $char && (']' === $next || '\\' === $next)) {
                $position++;

                continue;
            }

            if (('[' === $char && $terminator === $next) || ']' === $char) {
                return null;
            }

            if ($terminator === $char && ']' === $next) {
                return $position;
            }
        }

        return null;
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
        if (null !== $this->source) {
            return substr($this->source, $node->startPosition, $node->getEndPosition() - $node->startPosition);
        }

        return $this->describeRangeEndpoint($node->start).'-'.$this->describeRangeEndpoint($node->end);
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
        $names = implode('|', array_keys(self::START_OF_PATTERN_VERBS));

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
     * The smallest size PCRE could compile the node to, in code units. Every
     * item counts at most what PCRE spends on it, so a pattern whose floor
     * passes the limit is one PCRE refuses: a literal character is two units,
     * a group its brackets and its body, a branch three, a call or a
     * reference three, anything else one or nothing. A counted group is
     * compiled once per repetition, a counted single item once.
     */
    private function compiledSizeFloor(NodeInterface $node): int
    {
        $size = match (true) {
            $node instanceof SequenceNode => array_sum(array_map($this->compiledSizeFloor(...), $node->children)),
            $node instanceof AlternationNode => array_sum(array_map($this->compiledSizeFloor(...), $node->alternatives))
                + 3 * (\count($node->alternatives) - 1),
            $node instanceof GroupNode => $this->groupSizeFloor($node),
            $node instanceof ConditionalNode => $this->conditionalSizeFloor($node),
            $node instanceof QuantifierNode => $this->repeatedSizeFloor($node),
            $node instanceof LiteralNode => 2 * mb_strlen($node->value, 'UTF-8'),
            // A reference by number may be an octal character, "\101".
            $node instanceof BackrefNode => 2,
            $node instanceof SubroutineNode => 3,
            $node instanceof CharClassNode => $this->classSizeFloor($node),
            // Under UCP, "\d", "\s" and "\w" are Unicode properties.
            $node instanceof CharTypeNode => $this->unicodeFlag && str_contains('dDsSwW', $node->value) ? 3 : 1,
            $node instanceof UnicodePropNode => 3,
            $node instanceof DotNode, $node instanceof CharLiteralNode => 1,
            // A callout carries its number or its string; a verb its name.
            $node instanceof CalloutNode => \is_string($node->identifier) && $node->isStringIdentifier ? 11 + \strlen($node->identifier) : 6,
            $node instanceof PcreVerbNode => 1 === LibraryPcre::match('/^(?:MARK|PRUNE|SKIP|THEN|COMMIT):(.+)$/s', $node->verb, $name) ? 3 + \strlen($name[1]) : 0,
            default => 0,
        };

        return min($size, self::COMPILED_SIZE_CAP);
    }

    /**
     * A class is a 32-byte map. In UTF mode, what the map cannot hold,
     * characters past 255, goes in an extended class: its header, then its
     * items; so do properties, and the types and POSIX classes UCP reads.
     * PCRE compiles smaller a class of one member, a pair of case variants
     * such as "[aA]" or "[ǅǆ]", and one that may match every character: those
     * count one unit, and so does a type or a POSIX class the map would hold.
     * Characters PCRE may merge into one range, or fold under a caseless
     * option set anywhere, count as a single item. All to stay a lower bound.
     */
    private function classSizeFloor(CharClassNode $node): int
    {
        $members = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];
        $extended = 0;
        $codePoints = [];
        $covered = 0;
        $pastMap = false;
        foreach ($members as $member) {
            if ($member instanceof UnicodePropNode
                || ($this->unicodeFlag && ($member instanceof CharTypeNode || $member instanceof PosixClassNode))) {
                $extended += 3;

                continue;
            }

            $ends = array_map($this->classCodePoint(...), $member instanceof RangeNode ? [$member->start, $member->end] : [$member]);
            if (\in_array(null, $ends, true)) {
                return 1;
            }

            $codePoints[] = min($ends);
            $codePoints[] = max($ends);
            $covered += max($ends) - min($ends) + 1;
            $pastMap = $pastMap || ($this->unicodeMode && max($ends) > 255);
        }

        // One item at least: a character past 255 is its tag and two bytes.
        $extended += $pastMap ? 3 : 0;

        $distinct = array_values(array_unique($codePoints));
        if ([] === $distinct) {
            // Only properties and UCP types: an extended class of those.
            return 5 + $extended;
        }

        if (\count($distinct) < 2 || $covered >= ($this->unicodeMode ? 0x110000 : 256)) {
            return 1;
        }

        // A pair of case variants is one caseless character: two units.
        if (2 === \count($distinct) && mb_strtolower((string) mb_chr($distinct[0], 'UTF-8'), 'UTF-8') === mb_strtolower((string) mb_chr($distinct[1], 'UTF-8'), 'UTF-8')) {
            return 2;
        }

        return 0 < $extended ? 5 + $extended : 33;
    }

    /**
     * The code point a class member stands for, or null when it stands for
     * more than one character.
     */
    private function classCodePoint(NodeInterface $member): ?int
    {
        return match (true) {
            // Without UTF mode, a character is a byte.
            $member instanceof LiteralNode && $this->unicodeMode && 1 === mb_strlen($member->value, 'UTF-8') => (int) mb_ord($member->value, 'UTF-8'),
            $member instanceof LiteralNode && !$this->unicodeMode && 1 === \strlen($member->value) => \ord($member->value),
            $member instanceof CharLiteralNode => $member->codePoint,
            default => null,
        };
    }

    /**
     * A conditional is a group holding its branches, the second one behind
     * a branch marker.
     */
    private function conditionalSizeFloor(ConditionalNode $node): int
    {
        $no = $this->compiledSizeFloor($node->no);

        return $this->compiledSizeFloor($node->yes) + $no + self::COMPILED_GROUP_SIZE + ($no > 0 ? 3 : 0);
    }

    /**
     * The brackets of a compiled group: those of a capturing group also hold
     * its number. A "(?i)" that scopes nothing compiles to no group at all,
     * and a lookaround that holds nothing to one unit at most.
     */
    private function groupSizeFloor(GroupNode $node): int
    {
        // The body is measured once: measuring it again at every level
        // would double the work with each group nested in another.
        $body = $this->compiledSizeFloor($node->child);

        return $body + $this->compiledGroupSize($node, $body);
    }

    private function compiledGroupSize(GroupNode $node, int $body): int
    {
        return match (true) {
            // A lookaround that holds nothing compiles to at most one unit:
            // "(?!)" is a plain failure.
            $this->isLookaround($node) && 0 === $body => 0,
            GroupType::InlineFlags === $node->type && $node->child instanceof LiteralNode && '' === $node->child->value => 0,
            GroupType::Capturing === $node->type, GroupType::Named === $node->type => self::COMPILED_GROUP_SIZE + 2,
            default => self::COMPILED_GROUP_SIZE,
        };
    }

    /**
     * A counted group, conditional, call or "(*ACCEPT)" is compiled once per
     * repetition: every mandatory copy as it is, every optional one inside
     * brackets that nest the next (the innermost only behind its "may skip"
     * marker), and an open maximum as one more copy that loops. Any other
     * item is compiled once, with its count.
     */
    private function repeatedSizeFloor(QuantifierNode $node): int
    {
        $copy = $this->compiledSizeFloor($node->node);

        // "(*ACCEPT)" is wrapped in a group to be repeated; a name adds its
        // length and three units.
        if ($node->node instanceof PcreVerbNode && 1 === LibraryPcre::match('/^ACCEPT(?::(.*))?$/s', $node->node->verb, $accept)) {
            $copy = self::COMPILED_GROUP_SIZE + 1 + (isset($accept[1]) && '' !== $accept[1] ? 3 + \strlen($accept[1]) : 0);
        } elseif (!$node->node instanceof GroupNode && !$node->node instanceof ConditionalNode && !$node->node instanceof SubroutineNode) {
            return $copy;
        }

        [$min, $max] = $this->getQuantifierBounds($node->quantifier);
        if (-1 === $max) {
            return max($min, 1) * $copy;
        }

        $optional = $max - $min;

        return $min * $copy + ($optional > 0 ? ($optional - 1) * ($copy + self::COMPILED_OPTIONAL_COPY_SIZE) + $copy + 1 : 0);
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
        if (!$this->walkingPattern) {
            // Off a walk, the nodes measured on an earlier visit may be gone
            // and their ids taken by others.
            if (0 === $this->lookbehindDepth) {
                $this->measuredGroupLengths = [];
                $this->measuredLookbehinds = [];
            }
            $this->validateLookbehindLength($node);

            return;
        }

        if ($this->readingPrefix) {
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
     * references to missing groups: on a walk from the pattern root, it waits
     * for the walk to end.
     */
    private function raiseLateCompileError(string $message, int $position, ErrorCode $code, string $hint): void
    {
        $error = new SemanticErrorException($message, $code, $position + $this->positionOffset, $this->pattern, null, $hint);

        if (!$this->walkingPattern) {
            throw $error;
        }

        $this->lateErrors[1] ??= $error;
    }

    private function raiseMissingReference(string $message, int $position, ErrorCode $code): void
    {
        $error = new SemanticErrorException($message, $code, $position + $this->positionOffset, $this->pattern);

        if (!$this->walkingPattern || $this->measuringLookbehind) {
            throw $error;
        }

        $this->lateErrors[$this->lookbehindDepth > 0 ? 0 : 1] ??= $error;
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
            $this->captureSequence = [];
            $this->captureIndex = 0;
        }
    }
}
