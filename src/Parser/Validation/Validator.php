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
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\InternalFailureException;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
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
use PHPRegex\Parser\Printer\DisplayEscaper;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Syntax\InlineFlags;
use PHPRegex\Parser\Syntax\PcreNumber;
use PHPRegex\Parser\Syntax\PcreVerb;
use PHPRegex\Parser\Syntax\PosixItem;
use PHPRegex\Parser\Syntax\RepeatCount;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Syntax\VersionCondition;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\Bytes;

/**
 * The semantic checks of a parsed pattern: what PCRE2 refuses in a tree the
 * parser could build, at the offset the release judged reports it.
 *
 * It walks the tree once, in the order PCRE2 reads the pattern, and keeps
 * the checks that need that walk: the nesting limit, verbs and the settings
 * of the opening run, repeat counts, the ranges and POSIX items of classes,
 * version conditions and callouts. PCRE2 refuses some things only once it
 * has read the whole pattern: a lookbehind it cannot measure, a reference to
 * a group the pattern does not have, a "\K" in a lookaround from PHP 8.5, a
 * conditional of more than two branches. The walk keeps the first error of
 * each of those passes and raises the earliest pass's once it ends, unless
 * an error read on the way, or a pattern too large to compile, stops PCRE2
 * before.
 *
 * The checks that need no walk stand in classes of their own, which it
 * hands the text being read: EscapeChecks for one escape as written,
 * ReferenceChecks for what names a group, LookbehindChecks for the measure
 * of lookbehinds, CompiledSize for the 64 KiB limit.
 *
 * Each walk starts from a clean state. FirstErrorLocator also asks it about
 * a class parsed alone, and about the part of a pattern read before a
 * syntax error.
 *
 * @extends AbstractNodeVisitor<void>
 *
 * @internal
 */
final class Validator extends AbstractNodeVisitor
{
    /**
     * How deep PCRE2 lets parentheses nest by default, which PHP keeps.
     */
    public const MAX_GROUP_NESTING = 250;

    /**
     * The longest (*MARK), (*PRUNE), (*SKIP) or (*THEN) name, in code units.
     */
    private const MAX_VERB_NAME_LENGTH = 255;

    /**
     * The largest (*LIMIT_...=n) value PCRE still multiplies by ten.
     */
    private const MAX_LIMIT_VALUE_BEFORE_DIGIT = 429496728;

    /**
     * The assertions an escaped letter stands for.
     */
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
     * The names of POSIX classes PCRE2 knows, matched case-sensitively.
     */
    private const VALID_POSIX_CLASSES = [
        'alnum' => true, 'alpha' => true, 'ascii' => true,
        'blank' => true, 'cntrl' => true, 'digit' => true,
        'graph' => true, 'lower' => true, 'print' => true,
        'punct' => true, 'space' => true, 'upper' => true,
        'word' => true, 'xdigit' => true,
    ];

    /**
     * Whether PCRE reads the pattern as UTF-8, by the "u" flag or "(*UTF)".
     */
    private bool $unicodeMode = false;

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

    /**
     * How many classes the node visited sits in: an extended class holds
     * others.
     */
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

    private readonly PcreTarget $target;

    /**
     * The checks of the escapes of the text being read.
     */
    private EscapeChecks $escapes;

    /**
     * The checks of the references of the text being read.
     */
    private ReferenceChecks $references;

    /**
     * The measure of the lookbehinds of the pattern.
     */
    private LookbehindChecks $lookbehindChecks;

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
        $groups = GroupIndex::empty();
        $this->escapes = new EscapeChecks($this->target, $pattern, engine: $engine);
        $this->references = new ReferenceChecks($groups, $groups->numbering(), $this->target, $this->keepMissingReference(...), $pattern);
        $this->lookbehindChecks = new LookbehindChecks($groups, $this->target, $this->checkMeasuredReference(...), $pattern, $maxLookbehindLength, false);
    }

    /**
     * Clears static caches. Useful for long-running processes or testing.
     */
    public static function clearCaches(): void
    {
        EscapeChecks::clearCaches();
        QuantifierCounts::clearCaches();
    }

    /**
     * The error PCRE finds in a character class, parsed alone, that closes
     * at $closingAt: none unless PCRE reads the whole class before $limit,
     * the offset where parsing failed.
     */
    public function firstErrorInClassBefore(RegexNode $class, int $closingAt, int $limit): ?SemanticErrorException
    {
        if ($this->target->faultOffset($closingAt + 1) > $limit) {
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
        try {
            $this->walkPattern($prefix, true);
        } catch (SemanticErrorException $error) {
            return ($error->position ?? $limit) < $limit ? $error : null;
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
        foreach ($node->alternatives as $alt) {
            $alt->accept($this);
        }
    }

    #[\Override]
    public function visitSequence(SequenceNode $node): void
    {
        foreach ($node->children as $child) {
            $child->accept($this);
        }
    }

    #[\Override]
    public function visitGroup(GroupNode $node): void
    {
        if (GroupType::ScanSubstring === $node->type) {
            $this->referenceAt = $node->startPosition + $this->positionOffset;
            $this->references->listedGroups($node, $node->scannedGroups, $node->startPosition + 2);
        }

        $isLookbehind = \in_array(
            $node->type,
            [GroupType::LookbehindPositive, GroupType::LookbehindNegative],
            true,
        );
        if ($isLookbehind) {
            $this->measureLookbehind($node);
        }

        $source = $this->source;
        $positionOffset = $this->positionOffset;
        $escapes = $this->escapes;
        $references = $this->references;
        if ($node->child->getStartPosition() <= $node->startPosition) {
            $this->enterPayload($node->startPosition, $node->endPosition);
            $this->escapes = $this->escapes->reading($this->source, $this->positionOffset);
            $this->references = $this->references->reading($this->source, $this->positionOffset);
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
            $this->escapes = $escapes;
            $this->references = $references;
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
        if ($min > PcreNumber::MAX || $max > PcreNumber::MAX) {
            $this->raiseSemanticError(
                \sprintf('Number too big in "%s" quantifier: PCRE allows at most 65535 repetitions.', $node->quantifier),
                $afterN ?? ($min > PcreNumber::MAX ? $minEnd : $maxEnd),
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

        $this->escapes->escapedLetter($letter, $start, $this->charClassDepth > 0, $this->endsRange);
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): void
    {
        // A "\C" in a lookbehind is refused where PCRE measures it.
        $lookbehind = 'C' === $node->value ? end($this->lookbehinds) : false;
        $this->escapes->charType($node, $this->charClassDepth > 0, false === $lookbehind ? null : $this->lookbehindChecks->errorPosition($lookbehind));
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
                $this->target->faultOffset($endsAt),
                ErrorCode::RangeReversed,
            );
        }
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): void
    {
        $this->referenceAt = $node->startPosition + $this->positionOffset;
        $this->references->backref($node);
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): void
    {
        $this->escapes->characterLiteral($node);
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): void
    {
        $this->escapes->property($node);
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

    #[\Override]
    public function visitComment(CommentNode $node): void
    {
        // Comments are ignored in validation
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): void
    {
        $this->referenceAt = $node->startPosition + $this->positionOffset;

        // PCRE counts the conditional as it opens it: past a reference, or
        // on the assertion that decides it.
        $this->enterNesting($node->condition instanceof GroupNode || $this->isCalloutThenAssertion($node->condition)
            ? $node->condition->getStartPosition()
            : $node->yes->getStartPosition());

        // Check if the condition is a valid *type* of condition first
        // (e.g., a backreference, a subroutine call, or a lookaround)
        if ($node->condition instanceof BackrefNode) {
            // "(?(1)...)", "(?(<name>)...)" or "(?(name)...)": a bare name
            // has to name a group, then the reference is judged as any.
            $this->references->conditionName($node->condition);
            $node->condition->accept($this);
        } elseif ($node->condition instanceof SubroutineNode) {
            // A recursion condition, "(?(R1)...)", or a call.
            if (!$this->references->recursionCondition($node->condition)) {
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
        $this->references->subroutine($node);
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
                    $this->target->faultOffset($node->startPosition + 2),
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
                    1 === LibraryPcre::match('/^[a-z]/', $verbName) && ':' !== ($this->source[$nameEnd] ?? '') => $this->target->faultOffset($nameEnd + $width, $width),
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
                1 === LibraryPcre::match('/^[a-z]/', $verbName) ? $this->target->faultOffset($equals + 1) : $equals,
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
        $pcreOffset = false === $versionAt ? null : VersionCondition::errorOffsetFor((string) $this->source, $versionAt, $this->target, $this->unicodeMode);

        if (!\in_array($node->operator, ['=', '>='], true)) {
            $this->raiseSemanticError(
                \sprintf('Version condition "%s" is not supported: PCRE compares with "=" or ">=".', $node->operator),
                $pcreOffset ?? $node->startPosition,
                ErrorCode::ConditionalVersionOperator,
            );
        }

        // PCRE reads a major number and at most one ".minor".
        if (1 === LibraryPcre::match('/^\d++(?:\.\d++)?$/', $node->version, $matches)) {
            $tooBig = false === $versionAt ? null : VersionCondition::errorOffsetFor((string) $this->source, $versionAt, $this->target, $this->unicodeMode);

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
            $pcreOffset ?? ($afterNumber ? $this->target->faultOffset($versionStart + \strlen($valid) + 1) : $versionStart + \strlen($valid)),
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
     * Every walk starts from the state a new instance has.
     *
     * @param bool $readingPrefix whether the tree is the part of a pattern
     *                            read before a syntax error
     */
    private function walkPattern(RegexNode $node, bool $readingPrefix = false): void
    {
        $unicodeFlag = str_contains($node->flags, 'u');
        $this->unicodeMode = $unicodeFlag || (null !== $node->source && StartOptions::turnUtfOn($node->source));
        $this->endsRange = false;
        $this->source = $node->source;
        $this->charClassDepth = 0;
        $this->classQuoteOpen = false;
        $this->startOfPatternEnd = null === $node->source ? null : $this->readStartOfPatternEnd($node->source);
        $this->readingPrefix = $readingPrefix;
        $this->positionOffset = 0;
        $this->keepsInLookarounds = [];
        $this->repeatDepth = 0;
        $this->lookbehindDepth = 0;
        $this->lookaheadsInLookbehinds = 0;
        $this->classOperatorsFail = $this->supports(PcreFeature::ExtendedCharClass)
            && !$this->supports(PcreFeature::ExtendedClassBesideLookbehindCompiles)
            && self::holdsLookbehind($node->pattern);
        $this->lookaroundKinds = [];
        $this->lookbehinds = [];
        $this->nestingDepth = 0;
        $this->walkingPattern = false;
        $this->patternLength = \strlen($node->source ?? '');
        $this->measuringLookbehind = false;
        $this->lateErrors = [];
        $this->firstPassErrorAt = \PHP_INT_MAX;
        $this->referenceAt = 0;
        $this->escapes = new EscapeChecks(
            $this->target,
            $this->pattern,
            $node->source,
            0,
            $this->unicodeMode,
            $unicodeFlag,
            PhpVersionGates::badEscapeIsLiteral($this->target->phpVersionId, $node->flags),
            $this->engine,
        );
        $groups = GroupIndex::of($node->pattern);
        $this->references = new ReferenceChecks($groups, $groups->numbering(), $this->target, $this->keepMissingReference(...), $this->pattern, $node->source, 0, $this->unicodeMode);
        // A measure of its own: what an earlier pattern measured is gone.
        $this->lookbehindChecks = new LookbehindChecks($groups, $this->target, $this->checkMeasuredReference(...), $this->pattern, $this->maxLookbehindLength, $this->unicodeMode);

        $this->validateCasingSettings($node);

        $this->walkingPattern = true;

        try {
            $node->pattern->accept($this);
        } finally {
            $this->walkingPattern = false;
        }
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
            if ($value > PcreNumber::MAX) {
                return $index + 1;
            }
        }

        return \strlen($digits);
    }

    private function isSingleCharNode(NodeInterface $node): bool
    {
        return $node instanceof LiteralNode
            || $node instanceof CharLiteralNode
            || $node instanceof ControlCharNode;
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
     * Whether the version numbers of "(?(VERSION...)" are read whole, as
     * PCRE2 10.47 does; before, the minor is two digits.
     */
    private function supports(PcreFeature $feature): bool
    {
        return $this->target->supports($feature);
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
     *
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
        $offset = $this->target->faultOffset($terminator + 2, $terminator + 2 - $start);

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

        throw InternalFailureException::unexpectedState('A range endpoint is neither a literal, a character escape nor a control character.');
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
            default => throw InternalFailureException::unexpectedState('A range endpoint is neither a literal, a character escape nor a control character.'),
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
                $this->lookbehindChecks->forget();
            }
            $this->lookbehindChecks->measure($node, $this->positionOffset);

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
            $this->lookbehindChecks->measure($node, $this->positionOffset);
        } catch (SemanticErrorException $error) {
            $this->lateErrors[0] ??= $error;
        } finally {
            $this->measuringLookbehind = false;
        }
    }

    /**
     * A reference or a call met while a lookbehind is measured, which PCRE
     * judges there: a missing group is refused at once.
     */
    private function checkMeasuredReference(BackrefNode|SubroutineNode $node): void
    {
        $node->accept($this);
    }

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

    /**
     * A reference to a group the pattern does not have. PCRE resolves it
     * once the whole pattern is read, or while measuring the lookbehind it
     * sits in: on a walk from the pattern root, it waits for the walk to end.
     */
    private function keepMissingReference(SemanticErrorException $error): void
    {
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
}
