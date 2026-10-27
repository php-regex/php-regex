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
use PHPRegex\Parser\Syntax\InlineFlags;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\Bytes;

/**
 * The semantic checks of a parsed pattern: what PCRE2 refuses in a tree the
 * parser could build, at the offset the release judged reports it.
 *
 * It walks the tree once, in the order PCRE2 reads the pattern, and keeps
 * the checks that need that walk: the nesting limit and callouts. PCRE2
 * refuses some things only once it has read the whole pattern: a lookbehind it cannot
 * measure, a reference to a group the pattern does not have, a "\K" in a
 * lookaround from PHP 8.5, a conditional of more than two branches. The
 * walk keeps the first error of each of those passes and raises the
 * earliest pass's once it ends, unless an error read on the way, or a
 * pattern too large to compile, stops PCRE2 before.
 *
 * The checks that need no walk stand in classes of their own, which it
 * hands the text being read: EscapeChecks for one escape as written,
 * ReferenceChecks for what names a group, VerbChecks for a verb and the
 * settings of the opening run, ClassChecks for the brackets and quotes of
 * a class, RangeChecks for its ranges, ConditionalChecks for the branches
 * of a conditional and the version it compares with, QuantifierChecks for
 * the count of a repeat, LookbehindChecks for the measure of lookbehinds, CompiledSize
 * for the 64 KiB limit.
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
     * The assertions an escaped letter stands for.
     */
    private const VALID_ASSERTIONS = [
        'A' => true, 'z' => true, 'Z' => true,
        'G' => true, 'b' => true, 'B' => true,
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
     * The checks of the verbs and settings of the text being read.
     */
    private VerbChecks $verbs;

    /**
     * The checks of the brackets of the classes of the text being read.
     */
    private ClassChecks $classes;

    /**
     * The checks of the ranges of the classes of the text being read.
     */
    private RangeChecks $ranges;

    /**
     * The checks of the conditionals of the text being read.
     */
    private ConditionalChecks $conditionals;

    /**
     * The checks of the counts of the repeats of the text being read.
     */
    private QuantifierChecks $quantifiers;

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
        $this->verbs = new VerbChecks($this->target, $pattern);
        $this->classes = new ClassChecks($this->target, $pattern);
        $this->ranges = new RangeChecks($this->target, $pattern);
        $this->conditionals = new ConditionalChecks($this->target, $this->keepBranchCountError(...), $pattern);
        $this->quantifiers = new QuantifierChecks($this->target, $pattern);
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

        $text = [$this->source, $this->positionOffset, $this->escapes, $this->references, $this->verbs, $this->classes, $this->ranges, $this->conditionals, $this->quantifiers];
        if ($node->child->getStartPosition() <= $node->startPosition) {
            $this->enterPayload($node->startPosition, $node->endPosition);
            $this->readText();
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
            [$this->source, $this->positionOffset, $this->escapes, $this->references, $this->verbs, $this->classes, $this->ranges, $this->conditionals, $this->quantifiers] = $text;
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
        $this->quantifiers->countAfterN($node, $this->charClassDepth > 0);

        // PCRE reads the item before its count: what it refuses there comes
        // first, and what it checks once the pattern is read waits.
        $this->repeatDepth++;

        try {
            $node->node->accept($this);
        } finally {
            $this->repeatDepth--;
        }

        $this->quantifiers->counts($node);
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): void
    {
        if (null === $this->source) {
            return;
        }

        if ($this->charClassDepth > 0 && '[' === $node->value && $this->classes->isUnquotedBracket($node, $this->classQuoteOpen)) {
            $this->classes->bracket($node->startPosition);

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
                hint: 'Target PCRE2 10.48 or later, or write the class without an operator, as "[...]".',
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
        if (0 === $this->charClassDepth) {
            if ($this->classes->isWordBoundary($node->startPosition)) {
                return;
            }

            $this->classes->posixOutsideClass($node->startPosition);
        }

        $parts = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];

        $quoteOpen = $this->classQuoteOpen;
        $this->classQuoteOpen = false;
        $this->charClassDepth++;

        try {
            $end = $node->startPosition;
            foreach ($parts as $part) {
                $this->classQuoteOpen = $this->classes->quoteOpenAfter($end, $part->getStartPosition(), $this->classQuoteOpen);
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
        $this->ranges->endpoints($node);

        $node->start->accept($this);
        $this->classQuoteOpen = $this->classes->quoteOpenAfter($node->start->getEndPosition(), $node->end->getStartPosition(), $this->classQuoteOpen);

        // "[a-[.x.]]": a collating element is no range end.
        if ($node->end instanceof LiteralNode && '[' === $node->end->value && $this->classes->isUnquotedBracket($node->end, $this->classQuoteOpen)) {
            $this->classes->bracket($node->end->startPosition, true);
        }

        $this->endsRange = true;

        try {
            $node->end->accept($this);
        } finally {
            $this->endsRange = false;
        }

        $this->ranges->order($node);
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
        $this->classes->posixClass($node);
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

        $this->conditionals->branches($node);

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
        $this->verbs->verb($node);
    }

    #[\Override]
    public function visitDefine(DefineNode $node): void
    {
        $this->conditionals->defineBranches($node);

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
        $this->verbs->limitMatch($node);
    }

    #[\Override]
    public function visitVersionCondition(VersionConditionNode $node): void
    {
        $this->conditionals->versionCondition($node);
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
        $this->verbs = VerbChecks::forPattern($this->target, $this->pattern, $node->source, $this->unicodeMode);
        $this->classes = new ClassChecks($this->target, $this->pattern, $node->source);
        $this->ranges = new RangeChecks($this->target, $this->pattern, $node->source, 0, $this->unicodeMode);
        $this->conditionals = new ConditionalChecks($this->target, $this->keepBranchCountError(...), $this->pattern, $node->source, 0, $this->unicodeMode);
        $this->quantifiers = new QuantifierChecks($this->target, $this->pattern, $node->source);
        // A measure of its own: what an earlier pattern measured is gone.
        $this->lookbehindChecks = new LookbehindChecks($groups, $this->target, $this->checkMeasuredReference(...), $this->pattern, $this->maxLookbehindLength, $this->unicodeMode);

        $this->verbs->casingSettings($node->flags);

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
     * Point the checks that read the text at the one read now, after a step
     * into a payload.
     */
    private function readText(): void
    {
        $this->escapes = $this->escapes->reading($this->source, $this->positionOffset);
        $this->references = $this->references->reading($this->source, $this->positionOffset);
        $this->verbs = $this->verbs->reading($this->source, $this->positionOffset);
        $this->classes = $this->classes->reading($this->source, $this->positionOffset);
        $this->ranges = $this->ranges->reading($this->source, $this->positionOffset);
        $this->conditionals = $this->conditionals->reading($this->source, $this->positionOffset);
        $this->quantifiers = $this->quantifiers->reading($this->source, $this->positionOffset);
    }

    /**
     * A conditional with more than two branches, or a (DEFINE) with more
     * than one: PCRE counts them once it has resolved the references, so on
     * a walk from the pattern root the error waits for the walk to end.
     */
    private function keepBranchCountError(SemanticErrorException $error): void
    {
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
        $error = new SemanticErrorException($message, $code, $position + $this->positionOffset, $this->pattern, hint: $hint);

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
            hint: $hint,
        );
    }
}
