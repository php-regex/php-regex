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

namespace RegexParser\NodeVisitor;

use RegexParser\Exception\ParserException;
use RegexParser\Exception\SemanticErrorException;
use RegexParser\GroupNumbering;
use RegexParser\GroupNumberingCollector;
use RegexParser\Internal\VersionCondition;
use RegexParser\Node\AlternationNode;
use RegexParser\Node\AnchorNode;
use RegexParser\Node\AssertionNode;
use RegexParser\Node\BackrefNode;
use RegexParser\Node\CalloutNode;
use RegexParser\Node\CharClassNode;
use RegexParser\Node\CharLiteralNode;
use RegexParser\Node\CharLiteralType;
use RegexParser\Node\CharTypeNode;
use RegexParser\Node\ClassOperationNode;
use RegexParser\Node\CommentNode;
use RegexParser\Node\ConditionalNode;
use RegexParser\Node\ControlCharNode;
use RegexParser\Node\DefineNode;
use RegexParser\Node\DotNode;
use RegexParser\Node\GroupNode;
use RegexParser\Node\GroupType;
use RegexParser\Node\KeepNode;
use RegexParser\Node\LimitMatchNode;
use RegexParser\Node\LiteralNode;
use RegexParser\Node\NodeInterface;
use RegexParser\Node\PcreVerbNode;
use RegexParser\Node\PosixClassNode;
use RegexParser\Node\QuantifierBounds;
use RegexParser\Node\QuantifierNode;
use RegexParser\Node\QuantifierType;
use RegexParser\Node\RangeNode;
use RegexParser\Node\RegexNode;
use RegexParser\Node\ScriptRunNode;
use RegexParser\Node\SequenceNode;
use RegexParser\Node\SubroutineNode;
use RegexParser\Node\UnicodePropNode;
use RegexParser\Node\VersionConditionNode;
use RegexParser\Regex;
use RegexParser\Token;
use RegexParser\TokenType;

/**
 * Validator for regex Abstract Syntax Trees with caching and optimization.
 *
 * This validator provides semantic validation while minimizing
 * computational overhead through caching and streamlined validation logic.
 *
 * @extends AbstractNodeVisitor<void>
 */
final class ValidatorNodeVisitor extends AbstractNodeVisitor
{
    // Maximum cache size to prevent memory leaks in long-running processes
    private const MAX_CACHE_SIZE = 1000;

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
     * pattern; anywhere else they are not verbs at all.
     */
    private const START_OF_PATTERN_VERBS = [
        'UTF8' => true, 'UTF' => true, 'UCP' => true,
        'CR' => true, 'LF' => true, 'CRLF' => true, 'ANYCRLF' => true, 'ANY' => true, 'NUL' => true,
        'BSR_ANYCRLF' => true, 'BSR_UNICODE' => true,
        'NO_AUTO_POSSESS' => true, 'NO_START_OPT' => true, 'NO_DOTSTAR_ANCHOR' => true, 'NO_JIT' => true,
        'NOTEMPTY' => true, 'NOTEMPTY_ATSTART' => true,
        'LIMIT_MATCH' => true, 'LIMIT_RECURSION' => true, 'LIMIT_DEPTH' => true, 'LIMIT_HEAP' => true,
    ];

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
     * Where the text the visited nodes count their positions from starts in
     * the whole pattern: 0, or the start of the payload of the "(*pla:...)"
     * or "(*sr:...)" being visited, which is parsed apart.
     */
    private int $positionOffset = 0;

    /**
     * How many lookbehinds the node being visited sits in.
     */
    private int $lookbehindDepth = 0;

    /**
     * Whether the walk started from the pattern root, and so ends where the
     * errors PCRE finds late can be reported.
     */
    private bool $walkingPattern = false;

    /**
     * Whether a lookbehind is being measured: a missing group ends the
     * measure there, as it ends PCRE's.
     */
    private bool $measuringLookbehind = false;

    /**
     * The first error of each late pass, kept until the walk ends: [0] the
     * pass that measures lookbehinds, [1] the one that resolves references
     * to groups by number or name. PCRE runs both only once it has read the
     * whole pattern, so any other error comes first.
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

    public function __construct(
        private readonly int $maxLookbehindLength = Regex::DEFAULT_MAX_LOOKBEHIND_LENGTH,
        private readonly ?string $pattern = null,
        private readonly int $phpVersionId = \PHP_VERSION_ID,
    ) {}

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
        $this->unicodeMode = $this->unicodeFlag || 1 === preg_match(self::LEADING_UTF_VERB, $source);

        try {
            foreach ($tokens as $token) {
                if ($token->position >= $limit) {
                    break;
                }

                $this->validateEscapeToken($token, $source);
            }
        } catch (SemanticErrorException $error) {
            return $error;
        }

        return null;
    }

    #[\Override]
    public function visitRegex(RegexNode $node): void
    {
        $this->source = $node->source;
        $this->charClassDepth = 0;
        $this->startOfPatternEnd = null === $node->source ? null : $this->readStartOfPatternEnd($node->source);
        $this->unicodeFlag = str_contains($node->flags, 'u');
        $this->unicodeMode = $this->unicodeFlag
            || (null !== $node->source && 1 === preg_match(self::LEADING_UTF_VERB, $node->source));
        $this->groupNumbering = (new GroupNumberingCollector())->collect($node);
        $this->groupsByNumber = [];
        $this->groupsByName = [];
        $this->nextGroupNumberAt = [];
        $this->captureIndexAt = [];
        $this->capturesIndexed = 0;
        $this->groupsInBranchReset = [];
        $this->hasBranchReset = false;
        $this->enclosingGroups = [];
        $nextGroupNumber = 1;
        $this->indexGroups($node->pattern, $nextGroupNumber);
        $this->captureSequence = $this->groupNumbering->captureSequence;
        $this->captureIndex = 0;

        $this->previousNode = null;
        $this->nextNode = null;
        $this->lookbehindDepth = 0;
        $this->positionOffset = 0;
        $this->lateErrors = [];
        $this->walkingPattern = true;

        try {
            $node->pattern->accept($this);
        } finally {
            $this->walkingPattern = false;
        }

        $this->raiseFirstLateError();

        // PCRE measures the compiled pattern last, once it has read it all.
        if ($this->compiledSizeFloor($node->pattern) + self::COMPILED_FRAME_SIZE > self::MAX_COMPILED_SIZE) {
            $this->raiseSemanticError(
                'Regular expression is too large: PCRE would compile it to more than 64 KiB.',
                \strlen($node->source ?? ''),
                'regex.pattern.too_large',
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

        $previous = $this->previousNode;
        $next = $this->nextNode;

        $isLookbehind = \in_array(
            $node->type,
            [GroupType::T_GROUP_LOOKBEHIND_POSITIVE, GroupType::T_GROUP_LOOKBEHIND_NEGATIVE],
            true,
        );
        if ($isLookbehind) {
            $this->measureLookbehind($node);
        }

        if (GroupType::T_GROUP_CAPTURING === $node->type || GroupType::T_GROUP_NAMED === $node->type) {
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
        if (GroupType::T_GROUP_CAPTURING === $node->type || GroupType::T_GROUP_NAMED === $node->type) {
            $this->enclosingGroups[spl_object_id($node)] = true;
        }

        if ($isLookbehind) {
            $this->lookbehindDepth++;
        }

        try {
            $node->child->accept($this);
        } finally {
            $this->source = $source;
            $this->positionOffset = $positionOffset;
            $this->enclosingGroups = $enclosingGroups;
            if ($isLookbehind) {
                $this->lookbehindDepth--;
            }
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

        $source = $this->source;
        $positionOffset = $this->positionOffset;
        $this->enterPayload($node->startPosition, $node->endPosition);

        try {
            $node->content->accept($this);
        } finally {
            $this->source = $source;
            $this->positionOffset = $positionOffset;
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
            && 1 !== preg_match('/^\{\d++(?:,\d*+)?\}/', $node->quantifier)
            && !$this->supportsPaddedBraces()) {
            $this->raiseSemanticError(
                \sprintf('The count "%s" after \N needs PCRE2 10.43, which PHP bundles from 8.4.', $node->quantifier),
                $node->node->getEndPosition(),
                'regex.escape.unsupported',
                'Write the count as {n}, {n,} or {n,m} without spaces, or target PHP 8.4+.',
            );
        }

        // Fast cached quantifier bounds parsing
        [$min, $max] = $this->getQuantifierBounds($node->quantifier);

        // PCRE caps repetition counts at 65535, and checks each number as
        // it reads it, before it compares the two.
        [$minEnd, $maxEnd] = $this->quantifierNumberEnds($node);
        if ($min > 65535 || $max > 65535) {
            $this->raiseSemanticError(
                \sprintf('Number too big in "%s" quantifier: PCRE allows at most 65535 repetitions.', $node->quantifier),
                $min > 65535 ? $minEnd : $maxEnd,
                'regex.quantifier.too_big',
            );
        }

        if (-1 !== $max && $min > $max) {
            $this->raiseSemanticError(
                \sprintf('Invalid quantifier range "%s": min > max.', $node->quantifier),
                $maxEnd,
                'regex.quantifier.invalid_range',
            );
        }

        $node->node->accept($this);
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
        if (1 !== \strlen($letter) || !ctype_alpha($letter)
            || '\\'.$letter !== substr($this->source, $start, $node->endPosition - $start)) {
            return;
        }

        $this->validateEscapedLetter($this->source, $letter, $start);
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): void
    {
        // "\C" matches one code unit, which would split a UTF-8 character:
        // PHP refuses it with the "u" flag. "(*UTF)\C" compiles.
        if ('C' === $node->value && $this->unicodeFlag) {
            $this->raiseSemanticError(
                '\C is not allowed in Unicode mode: it matches a single byte.',
                $node->getEndPosition(),
                'regex.escape.single_byte_in_utf',
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

            $this->raiseSemanticError(
                '\N is not supported in a character class.',
                $node->getEndPosition(),
                'regex.charclass.invalid_escape',
            );
        }

        if (isset(self::CLASS_INVALID_ESCAPES[$node->value])) {
            $this->raiseSemanticError(
                \sprintf('Escape sequence \%s is invalid in a character class.', $node->value),
                $node->getEndPosition(),
                'regex.charclass.invalid_escape',
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
                'regex.assertion.invalid',
            );
        }
    }

    /**
     * `\K` is valid anywhere, lookarounds included: PHP compiles every pattern
     * with PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK, and PCRE2 allowed it there by
     * default before 10.38.
     */
    #[\Override]
    public function visitKeep(KeepNode $node): void {}

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

        $this->charClassDepth++;

        try {
            foreach ($parts as $part) {
                $part->accept($this);
            }
        } finally {
            $this->charClassDepth--;
        }
    }

    /**
     * @deprecated the parser no longer builds a ClassOperationNode; this method goes in the next major version
     */
    #[\Override]
    public function visitClassOperation(ClassOperationNode $node): void
    {
        $node->left->accept($this);
        $node->right->accept($this);
    }

    #[\Override]
    public function visitRange(RangeNode $node): void
    {
        // 1. Validation: Ensure start and end nodes represent a single character.
        // We allow LiteralNode, but also CharLiteralNode and friends.
        if (!$this->isSingleCharNode($node->start) || !$this->isSingleCharNode($node->end)) {
            $this->raiseSemanticError(
                \sprintf(
                    'Invalid range: ranges must be between literal characters or single escape sequences. Found %s and %s.',
                    $node->start::class,
                    $node->end::class,
                ),
                $node->startPosition,
                'regex.range.invalid_bounds',
            );
        }

        // 2. Validation: Ensure characters are single codepoint (for LiteralNodes).
        // Use mb_strlen for proper Unicode character counting.
        if ($node->start instanceof LiteralNode && mb_strlen($node->start->value, 'UTF-8') > 1) {
            $this->raiseSemanticError(
                'Invalid range: start char must be a single character.',
                $node->startPosition,
                'regex.range.invalid_start',
            );
        }
        if ($node->end instanceof LiteralNode && mb_strlen($node->end->value, 'UTF-8') > 1) {
            $this->raiseSemanticError(
                'Invalid range: end char must be a single character.',
                $node->startPosition,
                'regex.range.invalid_end',
            );
        }

        $node->start->accept($this);
        $node->end->accept($this);

        // 3. Validation: order check, on the code points of both endpoints.
        // PCRE reports it where the range ends.
        $startCodePoint = $this->rangeEndpointCodePoint($node->start, true);
        $endCodePoint = $this->rangeEndpointCodePoint($node->end, false);
        if (null !== $startCodePoint && null !== $endCodePoint && $startCodePoint > $endCodePoint) {
            $this->raiseSemanticError(
                \sprintf('Invalid range "%s": start character comes after end character.', $this->describeRange($node)),
                $node->getEndPosition(),
                'regex.range.reversed',
            );
        }
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): void
    {
        $this->ensureGroupNumberingInitialized();

        $ref = $node->ref;

        $suggestions = $this->getNameSuggestions($ref);

        // Fast path for numeric backreferences
        if (preg_match('/^\\\\(\d++)$/', $ref, $matches)) {
            $num = (int) $matches[1];
            if (0 === $num) {
                $this->raiseSemanticError(
                    'Backreference \\0 is not valid.',
                    $node->startPosition,
                    'regex.backref.zero',
                    'Use \\g<0> for recursion to the whole pattern, or remove the reference.',
                );
            }
            if ($num > $this->groupNumbering->maxGroupNumber) {
                // PCRE disambiguation: \NN with NN >= 10 and no such group is
                // read as an octal escape (up to three octal digits, value
                // <= \377) followed by literal digits, e.g. (a)\11 == (a)\x09.
                if ($num >= 10 && $this->isValidOctalFallback($matches[1])) {
                    return;
                }

                $this->raiseMissingReference(
                    \sprintf('Backreference to non-existent group: \\%d.', $num),
                    $this->missingReferenceOffset($node),
                    'regex.backref.missing_group',
                );
            }

            return;
        }

        // Relative conditions, "(?(-1)...)" and "(?(+1)...)", count groups
        // from where they stand.
        if (preg_match('/^[+-]\d++$/', $ref)) {
            $this->assertRelativeReferenceExists((int) $ref, $this->missingReferenceOffset($node), 'regex.backref.relative', 'Condition');

            return;
        }

        // Numeric conditionals without a leading backslash (e.g., (?(2)...))
        if (preg_match('/^(\d++)$/', $ref, $matches)) {
            $num = (int) $matches[1];
            if (0 === $num) {
                $this->raiseSemanticError(
                    'Backreference 0 is not valid.',
                    $this->missingReferenceOffset($node),
                    'regex.backref.zero',
                    'Use \\g<0> for recursion to the whole pattern, or remove the reference.',
                );
            }
            if ($num > $this->groupNumbering->maxGroupNumber) {
                $this->raiseMissingReference(
                    \sprintf('Backreference to non-existent group: %d.', $num),
                    $this->missingReferenceOffset($node),
                    'regex.backref.missing_group',
                );
            }

            return;
        }

        // Optimized named backreference validation
        if (preg_match('/^\\\\k[<{\'](?<name>'.self::GROUP_NAME.')[>}\']$/u', $ref, $matches)) {
            $name = $matches['name'];
            if (!$this->groupNumbering->hasNamedGroup($name)) {
                $suggestions = $this->getNameSuggestions($name);
                $this->raiseMissingReference(
                    \sprintf('Backreference to non-existent named group: "%s".', $name).$suggestions,
                    $this->missingReferenceOffset($node),
                    'regex.backref.missing_named_group',
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
                    'regex.backref.missing_named_group',
                );
            }

            return;
        }

        // \g backreference with optimized validation (\g1, \g{1}, \g'1')
        if (preg_match('/^\\\\g(?:\{([0-9+-]++)\}|\'([0-9+-]++)\'|([0-9+-]++))$/', $ref, $matches)) {
            $numStr = ('' !== $matches[1]) ? $matches[1] : (('' !== ($matches[2] ?? '')) ? $matches[2] : ($matches[3] ?? ''));

            // "\g-" or "\g+" with no digit is no reference at all: PCRE stops
            // on the sign.
            if ('' !== ($matches[3] ?? '') && '' === ltrim($numStr, '+-')) {
                $this->raiseSemanticError(
                    '\g is not followed by a braced, angle-bracketed or quoted name or number, or by a plain number.',
                    $node->startPosition + 2,
                    'regex.backref.invalid_syntax',
                );
            }
            if ('0' === $numStr || '+0' === $numStr || '-0' === $numStr) {
                $this->raiseSemanticError(
                    'Backreference \\g{0} is not valid.',
                    $this->missingReferenceOffset($node),
                    'regex.backref.zero',
                    'Use \\g<0> for recursion to the whole pattern.',
                );
            }

            if (str_starts_with($numStr, '+') || str_starts_with($numStr, '-')) {
                $offset = (int) $numStr;
                $this->assertRelativeReferenceExists($offset, $this->missingReferenceOffset($node), 'regex.backref.relative', 'Backreference');

                return;
            }

            $num = (int) $numStr;
            if ($num > $this->groupNumbering->maxGroupNumber) {
                $this->raiseMissingReference(
                    \sprintf('Backreference to non-existent group: \\g{%d}.', $num),
                    $this->missingReferenceOffset($node),
                    'regex.backref.missing_group',
                );
            }

            return;
        }

        $this->raiseSemanticError(
            \sprintf('Invalid backreference syntax: "%s".', $ref),
            $this->missingReferenceOffset($node),
            'regex.backref.invalid_syntax',
        );
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): void
    {
        $this->validatePaddedBraces($node);

        // The Lexer/Parser combination already ensures these are
        // syntactically valid. We validate the *value*.
        match ($node->type) {
            CharLiteralType::UNICODE => $this->validateUnicode($node),
            CharLiteralType::OCTAL => $this->validateOctal($node),
            CharLiteralType::OCTAL_LEGACY => $this->validateOctalLegacy($node),
            CharLiteralType::UNICODE_NAMED => $this->validateUnicodeNamed($node),
        };

        // UTF-8 cannot encode the UTF-16 surrogates. PCRE reports it at the
        // closing brace.
        if ($this->unicodeMode && $node->codePoint >= 0xD800 && $node->codePoint <= 0xDFFF) {
            $this->raiseSemanticError(
                \sprintf('Code point "%s" is a surrogate, which is not allowed in Unicode mode.', $node->originalRepresentation),
                $node->getEndPosition() - 1,
                'regex.unicode.surrogate',
            );
        }
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): void
    {
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
                'regex.unicode.property_invalid',
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
                'regex.control_char.invalid',
            );
        }
    }

    #[\Override]
    public function visitPosixClass(PosixClassNode $node): void
    {
        // PCRE matches the name case-sensitively: "[[:ALPHA:]]" is unknown.
        if (!$this->isPosixClassName($node->class)) {
            $this->raiseSemanticError(
                \sprintf('Invalid POSIX class: "%s".', $node->class),
                $node->getEndPosition(),
                'regex.posix.invalid',
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

        // Check if the condition is a valid *type* of condition first
        // (e.g., a backreference, a subroutine call, or a lookaround)
        if ($node->condition instanceof BackrefNode) {
            // This is (?(1)...) or (?(<name>)...) or (?(name)...)
            // For bare names, check if the group exists before calling accept
            $ref = $node->condition->ref;
            if ($this->isBareNamedBackref($ref) && !$this->groupNumbering->hasNamedGroup($ref)) {
                // Bare name that doesn't exist - this is an invalid conditional
                $this->raiseMissingReference(
                    'Invalid conditional construct. Condition must be a group reference, lookaround, or (DEFINE).',
                    $this->missingReferenceOffset($node->condition),
                    'regex.conditional.invalid',
                );
            }
            // Now validate the backreference itself
            $node->condition->accept($this);
        } elseif ($node->condition instanceof SubroutineNode) {
            $ref = $node->condition->reference;
            if ('R' === $ref || '0' === $ref) {
                // Always valid recursion condition to entire pattern.
            } elseif (preg_match('/^R-?\d++$/', $ref)) {
                $num = (int) substr($ref, 1);
                // PCRE reads "R2" as a name, then as a group number digit by
                // digit, and stops on the digit that takes it over 65535.
                $overflow = strspn($ref, 'R') + $this->digitsWithinGroupLimit(ltrim($ref, 'R'));
                $position = $overflow < \strlen($ref)
                    ? $node->condition->startPosition + $overflow
                    : $this->missingReferenceOffset($node->condition);
                $this->assertSubroutineReferenceExists($num, $position, 'regex.subroutine.recursion', 'Recursion condition');
            } elseif (str_starts_with($ref, 'R&')) {
                // "(?(R&name)...)": the group has to exist.
                if (!$this->groupNumbering->hasNamedGroup(substr($ref, 2))) {
                    $this->raiseMissingReference(
                        \sprintf('Recursion condition to non-existent named group: "%s".', substr($ref, 2)),
                        $this->missingReferenceOffset($node->condition),
                        'regex.subroutine.missing_named_group',
                    );
                }
            } else {
                $node->condition->accept($this);
            }
        } elseif ($node->condition instanceof GroupNode && \in_array($node->condition->type, [
            GroupType::T_GROUP_LOOKAHEAD_POSITIVE,
            GroupType::T_GROUP_LOOKAHEAD_NEGATIVE,
            GroupType::T_GROUP_LOOKBEHIND_POSITIVE,
            GroupType::T_GROUP_LOOKBEHIND_NEGATIVE,
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
                'regex.conditional.invalid',
            );
        }

        // The parser keeps every branch after the first in "no"; PCRE takes
        // two at most.
        if ($node->no instanceof AlternationNode) {
            $this->raiseSemanticError(
                'A conditional group holds more than two branches.',
                $node->startPosition,
                'regex.conditional.too_many_branches',
                'Group the extra branches: (?(1)a|(?:b|c)).',
            );
        }

        $node->yes->accept($this);
        $node->no->accept($this);
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): void
    {
        $this->ensureGroupNumberingInitialized();

        $ref = $node->reference;

        if ('R' === $ref || '0' === $ref) {
            return; // (?R) or (?0) is always valid.
        }

        if (str_starts_with($ref, 'R')) {
            $numPart = substr($ref, 1);
            if ('' === $numPart) {
                return;
            }

            if (ctype_digit($numPart)) {
                $num = (int) $numPart;
                $this->assertAbsoluteReferenceExists($num, $this->missingReferenceOffset($node), 'regex.subroutine.recursion', 'Recursion condition');

                return;
            }

            if (str_starts_with($numPart, '-') && ctype_digit(substr($numPart, 1))) {
                $num = (int) $numPart;
                $this->assertRelativeReferenceExists($num, $node->startPosition, 'regex.subroutine.recursion', 'Recursion condition');

                return;
            }
        }

        // "(?-0)" and "(?+0)" point nowhere: PCRE refuses a relative zero,
        // at the ")" of "(?-0)" and at the "<" of "\g<-0>".
        if ('-0' === $ref || '+0' === $ref) {
            $this->raiseSemanticError(
                \sprintf('Subroutine call relative reference cannot be zero: "%s".', $ref),
                'g' === $node->syntax ? $node->startPosition + 2 : max($node->startPosition, $node->getEndPosition() - 1),
                'regex.subroutine.relative_zero',
            );
        }

        // Numeric reference: (?1), (?-1), (?+1), \g<-1>, \g<+1>
        if (1 === preg_match('/^[+-]?\d+$/', $ref)) {
            $num = (int) $ref;
            if (0 === $num) {
                return; // (?0) is an alias for (?R)
            }
            if (str_starts_with($ref, '+') || str_starts_with($ref, '-')) {
                $this->assertRelativeReferenceExists($num, $this->missingReferenceOffset($node), 'regex.subroutine.relative_missing', 'Subroutine call');
            } else {
                $this->assertAbsoluteReferenceExists($num, $this->missingReferenceOffset($node), 'regex.subroutine.missing_group', 'Subroutine call');
            }

            return;
        }

        // Named reference: (?&name), (?P>name), \g<name>
        if (!$this->groupNumbering->hasNamedGroup($ref)) {
            $this->raiseMissingReference(
                \sprintf('Subroutine call to non-existent named group: "%s".', $ref),
                $this->missingReferenceOffset($node),
                'regex.subroutine.missing_named_group',
            );
        }
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): void
    {
        $verbName = preg_split('/[:=]/', $node->verb, 2)[0] ?? $node->verb;

        if (!isset(self::VALID_PCRE_VERBS[$verbName])) {
            // PCRE reports an unknown verb where its name ends.
            $nameLength = 1 === preg_match('/^\w*+/', $verbName, $name) ? \strlen($name[0]) : 0;
            $this->raiseSemanticError(
                \sprintf('Invalid or unsupported PCRE verb: "%s".', $verbName),
                $node->startPosition + 2 + $nameLength,
                'regex.verb.invalid',
            );
        }

        // PCRE reports these at the closing parenthesis.
        $closing = $node->getEndPosition() - 1;

        if (isset(self::LIMIT_VERBS[$verbName]) && 1 !== preg_match('/=\d++$/', $node->verb)) {
            $this->raiseSemanticError(
                \sprintf('(*%s) needs a number: (*%s=10).', $verbName, $verbName),
                $closing,
                'regex.verb.invalid',
            );
        }

        if (isset(self::START_OF_PATTERN_VERBS[$verbName])) {
            $this->validateStartOfPatternPlacement($verbName, $node->startPosition, $closing);
        }

        // "(*=name)" is read as a mark shorthand, but PCRE only knows "(*:".
        if (str_starts_with($node->verb, 'MARK=')) {
            $this->raiseSemanticError(
                '(*=...) is not a PCRE verb; a mark is written (*MARK:name) or (*:name).',
                $node->startPosition + 2,
                'regex.verb.invalid',
            );
        }

        if ('MARK' === $verbName && 1 !== preg_match('/^MARK:./s', $node->verb)) {
            $this->raiseSemanticError(
                '(*MARK) must have a name: (*MARK:name) or (*:name).',
                $closing,
                'regex.verb.mark_name_missing',
            );
        }
    }

    #[\Override]
    public function visitDefine(DefineNode $node): void
    {
        // PCRE reports it at the "DEFINE" word, past "(?(".
        if ($node->content instanceof AlternationNode) {
            $this->raiseSemanticError(
                'A (DEFINE) group holds more than one branch.',
                $node->startPosition + 3,
                'regex.define.too_many_branches',
                'Group the branches: (?(DEFINE)(?:a|b)).',
            );
        }

        $node->content->accept($this);
    }

    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node): void
    {
        $this->validateStartOfPatternPlacement('LIMIT_MATCH', $node->startPosition, $node->getEndPosition() - 1);
        // No specific validation needed for this node.
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
        $pcreOffset = false === $versionAt ? null : VersionCondition::errorOffset((string) $this->source, $versionAt);

        if (!\in_array($node->operator, ['=', '>='], true)) {
            $this->raiseSemanticError(
                \sprintf('Version condition "%s" is not supported: PCRE compares with "=" or ">=".', $node->operator),
                $pcreOffset ?? $node->startPosition,
                'regex.condition.version_operator',
            );
        }

        // PCRE reads a major number and at most one ".minor".
        if (1 === preg_match('/^\d++(?:\.\d++)?$/', $node->version, $matches)) {
            return;
        }

        // The pattern matches every string, the empty prefix included: the
        // '' branch is unreachable and only there for the type.
        $valid = 1 === preg_match('/^\d*+(?:\.\d*+)?/', $node->version, $matches) ? $matches[0] : '';
        $afterNumber = '' !== $valid && !str_ends_with($valid, '.');
        $versionStart = null === $this->source
            ? $node->startPosition
            : (int) strpos($this->source, $node->version, $node->startPosition);

        $this->raiseSemanticError(
            \sprintf('Invalid version "%s" in a version condition: PCRE takes a major number and an optional ".minor".', $node->version),
            // PCRE steps past a character it reads where ")" belongs, and
            // stops on one it reads where a digit belongs.
            $versionStart + \strlen($valid) + ($afterNumber ? 1 : 0),
            'regex.condition.version_syntax',
        );
    }

    #[\Override]
    public function visitCallout(CalloutNode $node): void
    {
        $position = $node->startPosition + 4;

        if (null === $node->identifier) {
            return;
        }

        if (\is_int($node->identifier)) {
            if ($node->identifier < 0 || $node->identifier > 255) {
                $this->raiseSemanticError(
                    \sprintf('Callout identifier must be between 0 and 255, got %d.', $node->identifier),
                    $this->calloutOverflowOffset($node),
                    'regex.callout.out_of_range',
                );
            }
        } elseif (\is_string($node->identifier)) {
            // Any string is a valid argument, the empty one included: PCRE2
            // compiles (?C""), (?C'') and (?C{}).
        } else {
            // This case should ideally be caught by the Lexer/Parser, but as a safeguard.
            $this->raiseSemanticError(
                'Invalid callout identifier type.',
                $position,
                'regex.callout.invalid_type',
            );
        }
    }

    /**
     * PCRE reads the number of a callout digit by digit, and stops past the
     * one that takes it over 255.
     */
    private function calloutOverflowOffset(CalloutNode $node): int
    {
        $digits = null !== $this->source && 1 === preg_match('/\G\d++/', $this->source, $matches, 0, $node->startPosition + 3)
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
        $suffix = QuantifierType::T_GREEDY === $node->type ? 0 : 1;
        $braceStart = $node->getEndPosition() - $suffix - \strlen($node->quantifier);

        if (1 !== preg_match('/^\{\s*+(\d*+)\s*+(?:,\s*+(\d*+))?/', $node->quantifier, $matches, \PREG_OFFSET_CAPTURE)) {
            return [$node->startPosition, $node->startPosition];
        }

        $minEnd = $braceStart + $matches[1][1] + \strlen($matches[1][0]);
        $maxEnd = isset($matches[2]) ? $braceStart + $matches[2][1] + \strlen($matches[2][0]) : $minEnd;

        return [$minEnd, $maxEnd];
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
        $inner = preg_replace('/\\s+/', '', $inner) ?? $inner;

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
        return 1 === preg_match('/^'.self::GROUP_NAME.'$/u', $ref);
    }

    private function validateUnicode(CharLiteralNode $node): void
    {
        // Parse codePoint from the escape string
        $rep = $node->originalRepresentation;

        if (preg_match('/^\\\\x([0-9a-fA-F]{1,2})$/', $rep, $m)) {
            $codePoint = (int) hexdec($m[1]);
        } elseif (preg_match('/^\\\\u([0-9a-fA-F]{4})$/', $rep, $m)) {
            $codePoint = (int) hexdec($m[1]);
        } elseif (preg_match('/^\\\\(x|u)\\{[ \t]*+([0-9a-fA-F]+)[ \t]*+\\}$/', $rep, $m)) {
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
                'regex.unicode.out_of_range',
            );
        }

        // Without Unicode mode a character is one byte, as for "\o{400}".
        if (!$this->unicodeMode && $codePoint > 0xFF) {
            $this->raiseSemanticError(
                \sprintf('Invalid code point "%s": without the "u" flag, a character is at most \xFF.', $node->originalRepresentation),
                $node->getEndPosition() - 1,
                'regex.octal.out_of_range',
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
                    'regex.octal.out_of_range',
                );
            }

            return;
        }

        if ($node->codePoint > 0xFF) {
            $this->raiseSemanticError(
                \sprintf('Invalid octal codepoint "%s".', $node->originalRepresentation),
                $node->getEndPosition() - 1,
                'regex.octal.out_of_range',
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
                $node->startPosition,
                'regex.octal.out_of_range',
            );
        }
    }

    private function validateUnicodeNamed(CharLiteralNode $node): void
    {
        // Extract the Unicode name from the representation
        if (!preg_match('/^\\\\N\\{(.+)}$/', $node->originalRepresentation, $matches)) {
            throw new ParserException("Invalid Unicode named character format: {$node->originalRepresentation}", $node->getStartPosition(), $this->pattern);
        }

        $name = $matches[1];

        // PCRE only supports \N{U+hhhh} in Unicode (/u) mode.
        if (!$this->unicodeMode && 1 === preg_match('/^[ \t]*+U\+[0-9A-Fa-f]+[ \t]*+$/', $name)) {
            $this->raiseSemanticError(
                \sprintf('\N{%s} is only supported in Unicode mode; add the "u" flag.', $name),
                // PCRE reads the escape to its closing brace before it looks
                // at the mode.
                $node->getEndPosition(),
                'regex.unicode_named.requires_utf',
            );
        }

        // As for "\x{...}", PCRE reads every digit before it refuses a value
        // above U+10FFFF, and reports it at the closing brace.
        if (1 === preg_match('/^[ \t]*+U\+0*+([0-9A-Fa-f]++)[ \t]*+$/', $name, $digits)
            && (\strlen($digits[1]) > 6 || hexdec($digits[1]) > 0x10FFFF)) {
            $this->raiseSemanticError(
                \sprintf('Invalid Unicode codepoint "%s" (out of range).', $node->originalRepresentation),
                $node->getEndPosition() - 1,
                'regex.unicode.out_of_range',
            );
        }

        // If the codePoint is -1, the name could not be resolved. PCRE refuses
        // a name it does not take once it has read "\N{".
        if (-1 === $node->codePoint) {
            throw new ParserException("Invalid Unicode character name: {$name}", $node->getStartPosition() + 3, $this->pattern);
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
        if (preg_match('/^p\\{(\\^)?bl(?:ock|k)=([^}]+)\\}$/i', $key, $matches)) {
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
        if (!preg_match('/^p\\{(\\^)?([A-Za-z_][A-Za-z0-9_]*)\\}$/', $key, $matches)) {
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
        // Use error suppression as preg_match warns on invalid properties
        $result = @preg_match("/^\\{$key}$/u", '');
        $error = preg_last_error();

        // PREG_NO_ERROR means it compiled successfully
        return false !== $result && \PREG_NO_ERROR === $error;
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
        // "\X" matches a whole grapheme cluster, of no bounded length.
        if ($this->containsGraphemeCluster($node->child)) {
            $this->raiseSemanticError(
                'Lookbehind is unbounded: \X matches a grapheme cluster of any length.',
                $node->startPosition,
                'regex.lookbehind.unbounded',
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
            // A group the lookbehind sits in is being measured already.
            $length = $this->lookbehindLength($branch, $expanding ?? $this->enclosingGroups);
            $lengths[] = $length;

            // PCRE stops at the first branch it cannot bound.
            if (null === $length[1]) {
                $this->validateLookbehindBranchLength($node, $length, true);
            }

            if ($this->lookbehindBranchMeasures > self::MAX_LOOKBEHIND_BRANCH_MEASURES) {
                $this->raiseSemanticError(
                    'Lookbehind is too complicated: in a pattern with a branch reset, PCRE gives up measuring it.',
                    $node->startPosition,
                    'regex.lookbehind.too_complex',
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
                $node->startPosition,
                'regex.lookbehind.unbounded',
                $hint,
            );
        }

        if (!$this->supportsVariableLengthLookbehind() && $min !== $max) {
            $this->raiseSemanticError(
                'Variable-length lookbehind needs PCRE2 10.43, which PHP bundles from 8.4.',
                $node->startPosition,
                'regex.lookbehind.variable_length_not_supported',
                'Give each branch of the lookbehind a fixed length, or target PHP 8.4+.',
            );
        }

        // A fixed length is only capped by PCRE itself; a variable one by
        // max_lookbehind_length, which stands for PCRE2's max_varlookbehind.
        if (!$variable && $max > self::MAX_FIXED_LOOKBEHIND_LENGTH) {
            $this->raiseSemanticError(
                \sprintf('Lookbehind is too long: PCRE takes a fixed-length lookbehind of at most %d characters (length=%d).', self::MAX_FIXED_LOOKBEHIND_LENGTH, $max),
                $node->startPosition,
                'regex.lookbehind.too_long',
                'Shorten the lookbehind.',
            );
        }

        if ($variable && $max > $this->maxLookbehindLength) {
            $this->raiseSemanticError(
                \sprintf('Lookbehind exceeds the maximum length of %d (max=%d).', $this->maxLookbehindLength, $max),
                $node->startPosition,
                'regex.lookbehind.too_long',
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
                GroupType::T_GROUP_LOOKAHEAD_POSITIVE,
                GroupType::T_GROUP_LOOKAHEAD_NEGATIVE,
            ], true)) {
                return [0, 0];
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

        return $node->accept(new LengthRangeNodeVisitor());
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
            GroupType::T_GROUP_LOOKBEHIND_POSITIVE,
            GroupType::T_GROUP_LOOKBEHIND_NEGATIVE,
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
            && 1 !== preg_match('/^\\\\g[{<\']?\s*+(?:-|[+-]?0++(?!\d))/', $node->ref);
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
            return [0, null];
        }

        // A back reference into a branch reset: PCRE cannot tell which of
        // the groups sharing the number it points to.
        if ($node instanceof BackrefNode && isset($this->groupsInBranchReset[$id])) {
            return [0, null];
        }

        $this->countLookbehindBranches($group->child);

        return $this->lookbehindLength($group->child, $expanding + [$id => true]);
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

        if (1 === preg_match('/^[+-]\d++$/', $reference)) {
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

        if (1 === preg_match('/^\d++$/', $reference)) {
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

        if (1 === preg_match('/^\\\\g(?:\{([+-]\d++)\}|\'([+-]\d++)\'|([+-]\d++))$/', $ref, $matches)) {
            $next = $this->nextGroupNumberAt[spl_object_id($node)] ?? null;
            $offset = (int) ($matches[1].($matches[2] ?? '').($matches[3] ?? ''));

            if (null === $next || 0 === $offset) {
                return [];
            }

            // "-1" is the group before the reference, "+1" the one after it.
            return $this->groupsByNumber[$offset < 0 ? $next + $offset : $next + $offset - 1] ?? [];
        }

        if (1 === preg_match('/^\\\\(?:g\{(\d++)\}|g\'(\d++)\'|g?(\d++))$/', $ref, $matches)) {
            return $this->groupsByNumber[(int) ($matches[1].($matches[2] ?? '').($matches[3] ?? ''))] ?? [];
        }

        if (1 === preg_match('/^\\\\k[<{\']('.self::GROUP_NAME.')[>}\']$/u', $ref, $matches)) {
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

        if ($node instanceof GroupNode && GroupType::T_GROUP_BRANCH_RESET === $node->type) {
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
            if (GroupType::T_GROUP_CAPTURING === $node->type || GroupType::T_GROUP_NAMED === $node->type) {
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
        if ($space === \strlen($representation) || $this->supportsPaddedBraces()) {
            return;
        }

        [$code, $escape] = match ($node->type) {
            CharLiteralType::OCTAL => ['regex.octal.invalid_digit', '\o{}'],
            // A space before "U+" makes "\N{" a name, which PCRE2 refuses.
            CharLiteralType::UNICODE_NAMED => 1 === preg_match('/^\\\\N\{[ \t]/', $representation)
                ? ['regex.escape.unsupported', '\N{U+}']
                : ['regex.unicode.invalid_digit', '\N{U+}'],
            default => ['regex.unicode.invalid_digit', '\x{}'],
        };

        $this->raiseSemanticError(
            \sprintf('Spaces inside %s need PCRE2 10.43, which PHP bundles from 8.4.', $escape),
            $node->startPosition + $space,
            $code,
            'Write the escape without spaces, or target PHP 8.4+.',
        );
    }

    private function supportsPaddedBraces(): bool
    {
        return $this->phpVersionId >= 80400 || $this->runningPcreAtLeast('10.43');
    }

    private function supportsVariableLengthLookbehind(): bool
    {
        return $this->phpVersionId >= 80400 || $this->runningPcreAtLeast('10.43');
    }

    /**
     * Whether validation targets the running PHP, whose linked PCRE2 may be
     * newer than the one its version bundles, and that PCRE2 is at least
     * the given release.
     */
    private function runningPcreAtLeast(string $release): bool
    {
        return \PHP_VERSION_ID === $this->phpVersionId
            && version_compare(explode(' ', \PCRE_VERSION)[0], $release, '>=');
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

        if ($node instanceof ClassOperationNode) {
            return $this->findUnboundedLookbehindNode($node->left) ?? $this->findUnboundedLookbehindNode($node->right);
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
        if (1 === preg_match('/^\\\\([gk])([{<\'])?\s*+([+-]?)(\d*)/', $text, $matches)) {
            if ('' === $matches[4]) {
                return $start + 3;
            }

            // "\k<5ghj>" names no group: a name cannot start with a digit.
            if ('k' === $matches[1]) {
                return $start + 4;
            }

            // "\g'3gh'": the number read, what should close it is missing.
            $closing = ['{' => '}', '<' => '>', "'" => "'", '' => ''][$matches[2]];
            $rest = ltrim(substr($text, \strlen($matches[0])));
            if ('' !== $closing && !str_starts_with($rest, $closing)) {
                return $start + \strlen($matches[0]);
            }

            $failsWhileRead = '-' === $matches[3] || ('' !== $matches[3] && 0 === (int) $matches[4]);

            return $failsWhileRead && '' !== $matches[2] ? $start + 2 : $end;
        }

        // "\1", "\81"
        if ('\\' === $text[0]) {
            return $end;
        }

        // "(?P=name)", "(?P>name)", "(?&name)", "(?1)", "(?-1)"
        if (str_starts_with($text, '(?')) {
            if (str_starts_with($text, '(?P')) {
                return $start + 4;
            }

            return '&' === ($text[2] ?? '') ? $start + 3 : $end - 1;
        }

        // "(?(VERSION=10z)": PCRE reads a version condition, not a name.
        $versionError = VersionCondition::errorOffset($this->source, $start);
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

        if (1 === preg_match('/^([+-]?)(\d++)$/', $text, $matches)) {
            return '-' === $matches[1] || 0 === (int) $matches[2] ? $end : $end - 2;
        }

        return $start;
    }

    /**
     * How many leading digits of $digits PCRE reads before the number goes
     * over 65535, the highest group number; all of them when it does not.
     */
    private function digitsWithinGroupLimit(string $digits): int
    {
        $number = 0;
        $length = \strlen($digits);
        for ($index = 0; $index < $length && ctype_digit($digits[$index]); $index++) {
            $number = $number * 10 + (int) $digits[$index];
            if ($number > 65535) {
                return $index;
            }
        }

        return $length;
    }

    private function assertAbsoluteReferenceExists(int $num, int $position, string $code, string $context): void
    {
        if ($num <= 0 || $num > $this->groupNumbering->maxGroupNumber) {
            $this->raiseMissingReference(
                \sprintf('%s to non-existent group: %d.', $context, $num),
                $position,
                $code,
            );
        }
    }

    private function assertRelativeReferenceExists(int $offset, int $position, string $code, string $context): void
    {
        if (0 === $offset) {
            $this->raiseSemanticError(
                \sprintf('%s relative reference cannot be zero.', $context),
                $position,
                $code,
            );
        }

        $index = $offset > 0 ? $this->captureIndex + $offset - 1 : $this->captureIndex + $offset;
        if ($index < 0 || $index >= \count($this->captureSequence)) {
            $this->raiseSemanticError(
                \sprintf('%s relative reference %d is outside the range of available capture groups.', $context, $offset),
                $position,
                $code,
                'Check group numbering or remove the relative reference.',
            );
        }
    }

    private function assertSubroutineReferenceExists(int $num, int $position, string $code, string $context): void
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
            TokenType::T_CHAR_CLASS_OPEN => $this->charClassDepth = 1,
            TokenType::T_CHAR_CLASS_CLOSE => $this->charClassDepth = 0,
            TokenType::T_UNICODE_NAMED => $this->validateNamedCharacterBraces($source, $token->position + 2),
            default => null,
        };

        if (TokenType::T_CHAR_TYPE === $token->type) {
            $this->visitCharType(new CharTypeNode($token->value, $token->position, $token->end()));
        }

        if (TokenType::T_UNICODE_PROP === $token->type) {
            $this->visitUnicodeProp(new UnicodePropNode(
                $token->value,
                str_starts_with($token->value, '{'),
                $token->position,
                $token->end(),
                'P' === ($source[$token->position + 1] ?? ''),
            ));
        }

        $letter = $token->value;
        if (TokenType::T_LITERAL_ESCAPED === $token->type && 1 === \strlen($letter) && ctype_alpha($letter)
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
                $end,
                'regex.escape.unrecognized',
            );
        }

        if (isset(self::UNSUPPORTED_ESCAPES[$letter])) {
            $this->raiseUnsupportedEscape($letter, $end);
        }

        if ($this->charClassDepth > 0 && isset(self::CLASS_INVALID_ESCAPES[$letter])) {
            $this->raiseSemanticError(
                \sprintf('Escape sequence \%s is invalid in a character class.', $letter),
                $end,
                'regex.charclass.invalid_escape',
            );
        }

        // "\k" in a class is the letter from PCRE2 10.45, which no PHP release
        // bundles yet; the releases before refuse it, on the "k".
        if ($this->charClassDepth > 0 && 'k' === $letter && !$this->runningPcreAtLeast('10.45')) {
            $this->raiseSemanticError(
                'Escape sequence \k is invalid in a character class before PCRE2 10.45.',
                $start + 1,
                'regex.charclass.invalid_escape',
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
                'regex.unicode.property_invalid',
            );
        }

        $offset = match (true) {
            $position >= \strlen($source) => $position,
            '{' === $source[$position] => \strlen($source),
            default => $position + $this->characterLengthAt($source, $position),
        };

        $this->raiseSemanticError(
            \sprintf('Malformed \\%s sequence: a property letter or a braced name must follow it.', $letter),
            $offset,
            'regex.unicode.property_malformed',
            \sprintf('Name a property, as in "\\%1$sL" or "\\%1$s{Lu}", or drop the backslash for a literal "%1$s".', $letter),
        );
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
                'regex.octal.missing_brace',
            );
        }

        $this->validateBracedDigits($source, $position + 1, self::OCTAL_DIGITS, true, 'regex.octal.invalid_digit', '\o{}');
    }

    /**
     * A bare "\x" is left alone: PCRE2 releases disagree on it.
     */
    private function validateHexBraces(string $source, int $position): void
    {
        if ('{' === ($source[$position] ?? '')) {
            $this->validateBracedDigits($source, $position + 1, self::HEX_DIGITS, true, 'regex.unicode.invalid_digit', '\x{}');
        } elseif ($this->runningPcreAtLeast('10.45')) {
            // A "\x" with no digit is "\x00" up to PCRE2 10.44 and an error
            // from 10.45, which no PHP release bundles yet: only a newer
            // linked PCRE2 refuses it.
            $this->raiseSemanticError('Digits missing after \x.', $position, 'regex.escape.digits_missing');
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
                // PCRE reads to the closing brace before it looks at the mode.
                $end = $digits + strspn($source, self::HEX_DIGITS.self::BRACE_PADDING, $digits);
                $this->raiseSemanticError(
                    '\N{U+...} is only supported in Unicode mode; add the "u" flag.',
                    '}' === ($source[$end] ?? '') ? $end + 1 : $end,
                    'regex.unicode_named.requires_utf',
                );
            }

            $this->validateBracedDigits($source, $digits, self::HEX_DIGITS, false, 'regex.unicode.invalid_digit', '\N{U+}');

            // Reached only when the digits are left unjudged, "\N{U+ }": a
            // well-formed "\N{U+...}" is a token of its own.
            return;
        }

        if (1 !== preg_match(self::REPEAT_COUNT, $source, $matches, 0, $position)) {
            $this->raiseUnsupportedEscape('N{', $position + 1);
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
        string $invalidDigitCode,
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
            if (!$this->supportsPaddedBraces()) {
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
                $position >= $length ? $length : $position + $this->characterLengthAt($source, $position),
                $invalidDigitCode,
            );
        }

        if ($position >= $length || '}' === $source[$position]) {
            $this->raiseSemanticError(
                \sprintf('Digits missing in %s.', $escape),
                $position,
                'regex.escape.digits_missing',
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
            $position >= $length ? $length : $position + $this->characterLengthAt($source, $position),
            $invalidDigitCode,
        );
    }

    private function raiseUnsupportedEscape(string $escape, int $position): never
    {
        $this->raiseSemanticError(
            \sprintf('PCRE does not support the escape "\%s" (\F, \L, \l, \N{name}, \U and \u are not supported).', $escape),
            $position,
            'regex.escape.unsupported',
        );
    }

    /**
     * Whether a "[" read inside a class was written as is, rather than
     * quoted by a "\Q" that holds nothing else, or escaped.
     */
    private function isUnquotedClassBracket(string $source, LiteralNode $node): bool
    {
        $start = $node->startPosition;
        if (1 !== $node->endPosition - $start || '[' !== ($source[$start] ?? '')) {
            return false;
        }

        if ($start < 2 || '\Q' !== substr($source, $start - 2, 2)) {
            return true;
        }

        // "\\Q[" is an escaped backslash, a "Q" and a bracket.
        $backslashes = \strlen(substr($source, 0, $start - 1)) - \strlen(rtrim(substr($source, 0, $start - 1), '\\'));

        return 0 === $backslashes % 2;
    }

    /**
     * A "[" inside a class starts a POSIX item when one closes after it:
     * "[[:alpha:]]" is known, "[[:foo:]]" and "[[.ch.]]" are errors.
     */
    private function validateBracketInClass(string $source, int $start): void
    {
        $terminator = $this->findPosixTerminator($source, $start + 1);
        if (null === $terminator) {
            return;
        }

        if (':' !== $source[$start + 1]) {
            $this->raiseSemanticError(
                'POSIX collating elements are not supported.',
                $terminator + 2,
                'regex.posix.collating_element',
            );
        }

        $name = substr($source, $start + 2, $terminator - $start - 2);
        if (!$this->isPosixClassName($name)) {
            $this->raiseSemanticError(
                \sprintf('Invalid POSIX class: "%s".', $name),
                $terminator + 2,
                'regex.posix.invalid',
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

        if (':' === $source[$start + 1]) {
            $this->raiseSemanticError(
                'POSIX named classes are supported only within a class.',
                $terminator + 2,
                'regex.posix.outside_class',
            );
        }

        $this->raiseSemanticError(
            'POSIX collating elements are not supported.',
            $terminator + 2,
            'regex.posix.collating_element',
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
            GroupType::T_GROUP_LOOKAHEAD_POSITIVE,
            GroupType::T_GROUP_LOOKAHEAD_NEGATIVE,
            GroupType::T_GROUP_LOOKBEHIND_POSITIVE,
            GroupType::T_GROUP_LOOKBEHIND_NEGATIVE,
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
        return 1 === preg_match('/\A(?:\(\*(?:'.$names.')(?:=\d*+)?\))*+/', $source, $matches) ? \strlen($matches[0]) : 0;
    }

    private function validateStartOfPatternPlacement(string $verbName, int $start, int $closing): void
    {
        if (null === $this->startOfPatternEnd || $start < $this->startOfPatternEnd) {
            return;
        }

        $this->raiseSemanticError(
            \sprintf('(*%s) is only recognized at the very start of the pattern.', $verbName),
            $closing,
            'regex.verb.misplaced',
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
            $node instanceof GroupNode => $this->compiledSizeFloor($node->child) + $this->compiledGroupSize($node),
            $node instanceof ConditionalNode => $this->compiledSizeFloor($node->yes) + $this->compiledSizeFloor($node->no)
                + self::COMPILED_GROUP_SIZE + ($this->compiledSizeFloor($node->no) > 0 ? 3 : 0),
            $node instanceof QuantifierNode => $this->repeatedSizeFloor($node),
            $node instanceof LiteralNode => 2 * mb_strlen($node->value, 'UTF-8'),
            $node instanceof SubroutineNode, $node instanceof BackrefNode => 3,
            $node instanceof CharClassNode, $node instanceof CharTypeNode, $node instanceof DotNode,
            $node instanceof CharLiteralNode, $node instanceof UnicodePropNode => 1,
            default => 0,
        };

        return min($size, self::COMPILED_SIZE_CAP);
    }

    /**
     * The brackets of a compiled group: those of a capturing group also hold
     * its number. A "(?i)" that scopes nothing compiles to no group at all.
     */
    private function compiledGroupSize(GroupNode $node): int
    {
        return match (true) {
            GroupType::T_GROUP_INLINE_FLAGS === $node->type && $node->child instanceof LiteralNode && '' === $node->child->value => 0,
            GroupType::T_GROUP_CAPTURING === $node->type, GroupType::T_GROUP_NAMED === $node->type => self::COMPILED_GROUP_SIZE + 2,
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
        if ($node->node instanceof PcreVerbNode && 1 === preg_match('/^ACCEPT(?::(.*))?$/s', $node->node->verb, $accept)) {
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
            $this->validateLookbehindLength($node);

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
    private function raiseMissingReference(string $message, int $position, string $code): void
    {
        $error = new SemanticErrorException($message, $position + $this->positionOffset, $this->pattern, null, $code);

        if (!$this->walkingPattern || $this->measuringLookbehind) {
            throw $error;
        }

        $this->lateErrors[$this->lookbehindDepth > 0 ? 0 : 1] ??= $error;
    }

    private function raiseSemanticError(string $message, int $position, string $code, ?string $hint = null): never
    {
        throw new SemanticErrorException(
            $message,
            $position + $this->positionOffset,
            $this->pattern,
            null,
            $code,
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

    /**
     * Whether a digit string that does not resolve to a capture group is a
     * valid octal escape under PCRE rules: it must start with an octal digit,
     * and the leading run of up to three octal digits must encode <= \377
     * unless the pattern is in Unicode mode, where "\400" is U+0100.
     */
    private function isValidOctalFallback(string $digits): bool
    {
        if (1 !== preg_match('/^([0-7]{1,3})/', $digits, $octal)) {
            return false;
        }

        return $this->unicodeMode || octdec($octal[1]) <= 0xFF;
    }
}
