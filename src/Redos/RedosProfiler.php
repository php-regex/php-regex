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

namespace PHPRegex\Redos;

use PHPRegex\Parser\AbstractNodeVisitor;
use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Analysis\LengthRangeCalculator;
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
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\Text\Ascii;

/**
 * Analyzes the AST to detect ReDoS vulnerabilities.
 *
 * @extends AbstractNodeVisitor<RedosSeverity>
 *
 * @internal
 */
final class RedosProfiler extends AbstractNodeVisitor
{
    /**
     * The largest count the proof unrolls, by default: past it, a count is
     * analysed as a loop.
     */
    private const UNROLLED_COUNT = 16;

    /**
     * The items that may match empty a loop is read past in full, on either
     * side. Further, what stands is taken to keep the loop apart from
     * nothing, and of the loops it meets only the first is named, so that a
     * run of loops that may all match empty costs the heuristics work and
     * findings that grow with its length, not with its square, and still
     * leaves no loop it meets unread.
     */
    private const ITEMS_READ_PAST = 8;

    /**
     * The pairs a sequence reads past ITEMS_READ_PAST items, at most: a run
     * of loops that never meet would have each compared with every later
     * one. Once they are spent, the loops past them are taken to meet, as
     * an overlap not known is.
     */
    private const PAIRS_READ_PAST = 1024;

    /**
     * The unbounded loops around the node that may come back into it: an
     * atomic group or a possessive loop between them counts them from none.
     */
    private int $unboundedQuantifierDepth = 0;

    /**
     * Tracks total nesting of quantifiers (bounded or not) to detect LOW risks.
     */
    private int $totalQuantifierDepth = 0;

    /**
     * The unbounded loops that run the node again at each iteration, an
     * atomic group between them or not: a lookaround in one reads the
     * subject again each time.
     */
    private int $repeatingLoopDepth = 0;

    /**
     * Stores all detected ReDoS vulnerabilities during the AST traversal.
     *
     * @var array<Finding>
     */
    private array $vulnerabilities = [];

    /**
     * @var array<Hotspot>
     */
    private array $hotspots = [];

    /**
     * Whether the node being read ends the body of an atomic group or of a
     * possessive loop: once it has matched, nothing after it in that body
     * can fail and send the engine back into it. Atomicity only holds once
     * the body has matched; until then the engine backtracks inside it as
     * anywhere, so only a loop that ends the body keeps its first way
     * through: "(?>(a+)+)" never retries its loop, "(?>(a+)+b)" does when
     * "b" fails.
     */
    private bool $endsAtomicBody = false;

    private ?NodeInterface $previousNode = null;

    /**
     * Whether each node read so far always reads a character, by object id.
     *
     * @var array<int, bool>
     */
    private array $reads = [];

    private ?NodeInterface $nextNode = null;

    /**
     * The items of the sequence the node being read stands in, and its
     * place there: the items around it are read past those that may match
     * empty. None outside a sequence.
     *
     * @var array<NodeInterface>
     */
    private array $siblings = [];

    private int $position = -1;

    /**
     * The body of the loop whose iteration the node ends: the next
     * iteration starts there. A run that ends "([^/*][^*]*\*+)*" never
     * takes the "[^/*]" that starts the next one.
     */
    private ?NodeInterface $iterationHead = null;

    private bool $backrefLoopDetected = false;

    private ?NodeInterface $culpritNode = null;

    private RedosSeverity $culpritSeverity = RedosSeverity::Safe;

    private readonly Nullability $nullability;

    private readonly FirstTriedStep $firstTriedStep;

    /**
     * The first capturing group bearing each number and each name, as PCRE
     * numbers them, branch resets included: the group a call runs.
     *
     * @var array<int|string, GroupNode>
     */
    private array $calledGroups = [];

    /**
     * The groups opened before each call, by object id: a relative call
     * counts from there.
     *
     * @var array<int, int>
     */
    private array $groupsBeforeCall = [];

    /**
     * The calls already read as the group they run, by object id and the
     * place they were read from, with what the reading found: each is read
     * so once from each place.
     *
     * @var array<string, RedosSeverity>
     */
    private array $callsReadThrough = [];

    /**
     * The groups being read through a call, by object id: a call of one of
     * them is a recursion.
     *
     * @var array<int, true>
     */
    private array $groupsReadThrough = [];

    /**
     * The pattern the profile reads, for the skips to a name looked up
     * when first asked about.
     */
    private ?NodeInterface $pattern = null;

    /**
     * The skips to a name the engine finds a mark of their name for on its
     * way back, by object id; null until one is asked about.
     *
     * @var array<int, true>|null
     */
    private ?array $skipsPastTheirMark = null;

    /**
     * The analyzer in force at each node of a pattern that holds an option
     * setting, whose sets follow it; null for one that holds none.
     *
     * @var \SplObjectStorage<NodeInterface, CharSetAnalyzer>|null
     */
    private ?\SplObjectStorage $setsInForce = null;

    /**
     * The innermost unbounded loop around the node being read.
     */
    private ?QuantifierNode $loop = null;

    /**
     * @param (\Closure(QuantifierNode): bool)|null $provenLinearAlone whether the loop, read alone before an end that
     *                                                                 fails, is proven linear: no text it reads two
     *                                                                 ways; null when it is not asked
     */
    public function __construct(private readonly CharSetAnalyzer $charSetAnalyzer = new CharSetAnalyzer(), private readonly ?\Closure $provenLinearAlone = null)
    {
        $this->nullability = new Nullability();
        $this->firstTriedStep = new FirstTriedStep();
    }

    /**
     * @return array{severity: RedosSeverity, recommendations: array<string>, vulnerablePattern: ?string, trigger: ?string, confidence: ?RedosConfidence, falsePositiveRisk: ?string, suggestedRewrite: ?string, findings: array<Finding>}
     */
    public function getResult(): array
    {
        $maxSeverity = RedosSeverity::Safe;
        $recommendations = [];
        $pattern = null;
        $trigger = null;
        $confidence = null;
        $falsePositiveRisk = null;
        $suggestedRewrite = null;

        foreach ($this->vulnerabilities as $vuln) {
            if ($vuln->severity->rank() > $maxSeverity->rank()) {
                $maxSeverity = $vuln->severity;
                $pattern = $vuln->pattern;
                $trigger = $vuln->trigger;
                $confidence = $vuln->confidence;
                $falsePositiveRisk = $vuln->falsePositiveRisk;
                $suggestedRewrite = $vuln->suggestedRewrite;
            } elseif ($vuln->severity === $maxSeverity && null === $suggestedRewrite) {
                $suggestedRewrite = $vuln->suggestedRewrite;
            }
            $recommendations[] = null !== $vuln->suggestedRewrite
                ? $vuln->message.' Suggested (verify behavior): '.$vuln->suggestedRewrite
                : $vuln->message;
        }

        if ($this->backrefLoopDetected) {
            $maxSeverity = $this->maxSeverity($maxSeverity, RedosSeverity::Critical);
        }

        return [
            'severity' => $maxSeverity,
            'recommendations' => array_unique($recommendations),
            'vulnerablePattern' => $pattern,
            'trigger' => $trigger,
            'confidence' => $confidence,
            'falsePositiveRisk' => $falsePositiveRisk,
            'suggestedRewrite' => $suggestedRewrite,
            'findings' => $this->vulnerabilities,
        ];
    }

    /**
     * @return array<Hotspot>
     */
    public function getHotspots(): array
    {
        return $this->hotspots;
    }

    public function getCulpritNode(): ?NodeInterface
    {
        return $this->culpritNode;
    }

    #[\Override]
    public function visitRegex(RegexNode $node): RedosSeverity
    {
        $this->unboundedQuantifierDepth = 0;
        $this->totalQuantifierDepth = 0;
        $this->repeatingLoopDepth = 0;
        $this->vulnerabilities = [];
        $this->hotspots = [];
        $this->endsAtomicBody = false;
        $this->previousNode = null;
        $this->nextNode = null;
        $this->siblings = [];
        $this->position = -1;
        $this->iterationHead = null;
        $this->backrefLoopDetected = false;
        $this->culpritNode = null;
        $this->culpritSeverity = RedosSeverity::Safe;
        $this->calledGroups = [];
        $this->groupsBeforeCall = [];
        $this->reads = [];
        $this->indexGroups($node->pattern, 0);
        $this->callsReadThrough = [];
        $this->groupsReadThrough = [];
        $this->pattern = $node->pattern;
        $this->skipsPastTheirMark = null;
        $this->setsInForce = self::holdsOptionSetting($node->pattern) ? $this->charSetAnalyzer->inForce($node->pattern) : null;

        // A recursion the attempt tries first, before it reads a character,
        // enters the pattern again where it started, and PCRE2 stops the
        // match there with an error: nothing after it ever runs.
        $recursion = $node->accept($this->firstTriedStep);
        if ($recursion instanceof SubroutineNode) {
            $this->addVulnerability(
                RedosSeverity::Low,
                'PCRE stops every match attempt it runs before it backtracks: the attempt tries this recursion first, before it reads a character, and enters the pattern again where it started, so preg_match() returns false, with PREG_INTERNAL_ERROR (a recursion loop) without the JIT and PREG_JIT_STACKLIMIT_ERROR with it.',
                $recursion,
                'Read a character before the recursion, or try the recursion after the branch that reads one.',
                RedosConfidence::High,
                'Low false-positive risk; the engine stops the attempt at its first step whatever the subject.',
            );

            return RedosSeverity::Low;
        }

        return $node->pattern->accept($this);
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): RedosSeverity
    {
        // A body repeated "{0}" never runs where it stands: one holding no
        // group runs nowhere, and the groups it holds run only through a
        // call, so it is read on its own, as a DEFINE block is.
        if (0 === $this->quantifierBounds($node)[1]) {
            return $this->holdsCapturingGroup($node->node) ? $this->readOnItsOwn($node->node) : RedosSeverity::Safe;
        }

        $wasAtomic = $this->endsAtomicBody;
        $depths = [$this->unboundedQuantifierDepth, $this->totalQuantifierDepth];
        // An item that may match empty keeps the loop apart only with the
        // ones past it: each, to the first that always reads a character,
        // must start or end apart from the loop. The walk that runs out of
        // items is kept apart by nothing, but after the loop by the head of
        // the iteration the sequence ends; the walk cut short, by nothing.
        [$before, $afterTheStart] = $this->itemsAround(-1);
        [$after, $beforeTheEnd] = $this->itemsAround(1);
        $boundarySeparatedPrev = false === $afterTheStart && $this->apartFrom($before, $node->node, false);
        $boundarySeparatedNext = false === $beforeTheEnd && $this->apartFrom($after, $node->node, true);
        $boundarySeparated = $boundarySeparatedPrev || $boundarySeparatedNext;
        $iterationsSeparated = $boundarySeparated
            || (true === $beforeTheEnd && null !== $this->iterationHead && $this->apartFrom([...$after, $this->iterationHead], $node->node, true) && $this->separatesIterations($this->iterationHead));

        $controlVerbShield = $this->hasTrailingBacktrackingControl($node->node);
        $isPossessive = QuantifierType::Possessive === $node->type;
        [$qMin, $qMax] = $this->quantifierBounds($node);

        // A possessive loop is an atomic group around the loop, and nothing
        // goes back past the (*COMMIT), (*PRUNE) or (*SKIP) that ends a body:
        // the loops around never retry what it holds, its own loops nest
        // from none.
        if ($isPossessive || $controlVerbShield) {
            $this->unboundedQuantifierDepth = 0;
            $this->totalQuantifierDepth = 0;
        }

        // A loop that ends an atomic body and needs at most one iteration
        // keeps its first way through: an iteration that fails ends it, and
        // nothing after it in the body can fail. Its body ends the atomic
        // body in turn, as does the part of a body before such a verb, read
        // without the checks of a loop. A count it may not reach yet sends
        // the engine back into its iterations.
        if ($controlVerbShield || (($isPossessive || $this->endsAtomicBody) && $qMin <= 1)) {
            $this->endsAtomicBody = true;
            $result = $node->node->accept($this);
            $this->endsAtomicBody = $wasAtomic;
            [$this->unboundedQuantifierDepth, $this->totalQuantifierDepth] = $depths;

            return $this->reduceSeverity($result, RedosSeverity::Low);
        }

        // --- Standard ReDoS logic for the loops the engine may retry ---

        $this->endsAtomicBody = false;
        $this->totalQuantifierDepth++;
        $isUnbounded = $this->isUnbounded($node);

        // Check if the immediate target is an atomic group (e.g., (? >...)+)
        $isTargetAtomic = $node->node instanceof GroupNode && GroupType::Atomic === $node->node->type;

        $severity = RedosSeverity::Safe;
        $entersUnbounded = $isUnbounded && !$isTargetAtomic;
        // Past the count the proof unrolls, a count repeats its body as a
        // loop does, "(?:a|a){1,40}"; in a loop, a count of variable length
        // splits a run as a loop does, the "\d{1,3}" of "(?:\d{1,3}\.?)+".
        $entersLongCount = !$isUnbounded && !$isTargetAtomic && null !== $qMax && $qMin !== $qMax && $qMax > self::UNROLLED_COUNT;
        $loopsAround = $this->unboundedQuantifierDepth;
        $isNestedUnbounded = !$isTargetAtomic && $qMin !== $qMax && $loopsAround > 0;

        if ($entersUnbounded || $entersLongCount) {
            $this->unboundedQuantifierDepth++;
            $this->repeatingLoopDepth++;
        }

        if ($entersUnbounded || $isNestedUnbounded) {
            if ($entersUnbounded && $this->hasBackrefLoop($node->node)) {
                $this->backrefLoopDetected = true;
                $this->addVulnerability(
                    RedosSeverity::Critical,
                    'Unbounded quantifier combined with backreferences to variable-length captures can cause catastrophic backtracking.',
                    $node,
                    'Use atomic groups (?>...) or possessive quantifiers around the quantified token.',
                    RedosConfidence::High,
                    'Low false-positive risk; nested backtracking with backreferences is a known hotspot.',
                );
            }

            if ($isNestedUnbounded) {
                $hasRecursion = $this->hasRecursion($node->node);
                $severity = $iterationsSeparated ? RedosSeverity::Low : ($hasRecursion ? RedosSeverity::Medium : RedosSeverity::Critical);
                if (!$iterationsSeparated) {
                    $vulnSeverity = $hasRecursion ? RedosSeverity::Medium : RedosSeverity::Critical;
                    $this->addVulnerability(
                        $vulnSeverity,
                        $isUnbounded
                            ? 'Nested unbounded quantifiers detected. This allows exponential backtracking. Consider using atomic groups (?>...) or possessive quantifiers (*+, ++).'
                            : 'A count of variable length in a loop splits a run in many ways, as nested unbounded quantifiers do. This allows exponential backtracking. Consider using atomic groups (?>...) or possessive quantifiers (*+, ++).',
                        $node,
                        'Replace inner quantifiers with possessive variants or wrap them in (?>...).',
                        RedosConfidence::High,
                        'Low false-positive risk; nested unbounded quantifiers are a classic ReDoS pattern.',
                    );
                }
            } else {
                $severity = $boundarySeparated ? RedosSeverity::Low : RedosSeverity::Medium;
                if (!$boundarySeparated) {
                    $this->addVulnerability(
                        RedosSeverity::Medium,
                        'Unbounded quantifier detected. May cause backtracking on non-matching input. Consider making it possessive (*+) or using atomic groups (?>...).',
                        $node,
                        'Consider using possessive quantifiers or atomic groups to limit backtracking.',
                        RedosConfidence::Medium,
                        'Medium false-positive risk; depends on input distribution and surrounding tokens.',
                    );
                }
            }
        } else {
            if ($this->isLargeBounded($node)) {
                $severity = RedosSeverity::Low;
                $this->addVulnerability(
                    RedosSeverity::Low,
                    'Large bounded quantifier detected (>1000). May cause slow matching. Consider reducing the upper bound.',
                    $node,
                    'Reduce the upper bound or pre-validate input length.',
                    RedosConfidence::Low,
                    'High false-positive risk; bounded quantifiers may still be safe in context.',
                );
            } elseif ($this->totalQuantifierDepth > 1 && 0 === $loopsAround) {
                $severity = RedosSeverity::Low;
                $this->addVulnerability(
                    RedosSeverity::Low,
                    'Nested bounded quantifiers detected. May cause polynomial backtracking. Consider simplifying the pattern or using atomic groups (?>...).',
                    $node,
                    'Flatten nested quantifiers or introduce atomic groups.',
                    RedosConfidence::Low,
                    'Medium false-positive risk; bounded quantifiers are often acceptable.',
                );
            }
        }

        if ($this->shouldFlagEmptyRepeat($node->node, $qMax)) {
            $repeatEmptySeverity = $this->emptyRepeatSeverity($isUnbounded, $qMax);
            if ($this->unboundedQuantifierDepth > 1) {
                $repeatEmptySeverity = RedosSeverity::Critical;
            }

            $repeatConfidence = RedosConfidence::High;
            $repeatFalsePositiveRisk = 'Low false-positive risk; repeated empty matches are a known backtracking hotspot.';
            if ($this->shouldDowngradeEmptyRepeat($node->node)) {
                $repeatEmptySeverity = $this->reduceSeverity($repeatEmptySeverity, RedosSeverity::Medium);
                $repeatConfidence = RedosConfidence::Medium;
                $repeatFalsePositiveRisk = 'Medium false-positive risk; possessive or atomic branches with recursion can reduce backtracking.';
            } elseif (!$this->holdsUnboundedRepeat($node->node)) {
                // PCRE2 ends a repeat whose iteration matched empty: without a
                // loop of its own, the body splits the input one way.
                $repeatEmptySeverity = $this->reduceSeverity($repeatEmptySeverity, RedosSeverity::Medium);
                $repeatConfidence = RedosConfidence::Medium;
                $repeatFalsePositiveRisk = 'Medium false-positive risk; an iteration that matches empty ends the repeat, and the body holds no loop.';
            }

            $severity = $this->maxSeverity($severity, $repeatEmptySeverity);
            $this->addVulnerability(
                $repeatEmptySeverity,
                'Quantifier repeats a subpattern that can match empty. This creates ambiguous backtracking paths and can be catastrophic.',
                $node,
                'Ensure the repeated subpattern consumes at least one character, or wrap it in (?>...) / use possessive quantifiers.',
                $repeatConfidence,
                $repeatFalsePositiveRisk,
                'quantifier repeating empty',
            );
        }

        $childPrevious = $this->previousNode;
        $childNext = $this->nextNode;
        $head = $this->iterationHead;
        $loop = $this->loop;
        $siblings = $this->siblings;
        $position = $this->position;
        $this->previousNode = null;
        $this->nextNode = null;
        $this->siblings = [];
        $this->position = -1;
        $this->iterationHead = $this->quantifierAllowsMultiple($qMax) ? $node->node : null;
        $this->loop = $entersUnbounded ? $node : $loop;
        $childSeverity = $node->node->accept($this);
        $this->previousNode = $childPrevious;
        $this->nextNode = $childNext;
        $this->siblings = $siblings;
        $this->position = $position;
        $this->iterationHead = $head;
        $this->loop = $loop;

        if ($entersUnbounded && !$boundarySeparated && RedosSeverity::High === $childSeverity) {
            $hasRecursion = $this->hasRecursion($node->node);
            $vulnSeverity = $hasRecursion ? RedosSeverity::Medium : RedosSeverity::Critical;
            $severity = $hasRecursion ? RedosSeverity::Medium : RedosSeverity::Critical;
            $this->addVulnerability(
                $vulnSeverity,
                'Critical nesting of quantifiers detected (Star Height > 1). This is a classic ReDoS risk. Refactor the pattern to avoid nested unbounded quantifiers over the same subpattern.',
                $node,
                'Use atomic groups or restructure the repetition to be deterministic.',
                RedosConfidence::High,
                $hasRecursion ? 'Medium false-positive risk; recursion may mitigate some backtracking.' : 'Low false-positive risk; star-height > 1 patterns are highly suspect.',
            );
        }

        $this->endsAtomicBody = $wasAtomic;
        [$this->unboundedQuantifierDepth, $this->totalQuantifierDepth] = $depths;
        if ($entersUnbounded || $entersLongCount) {
            $this->repeatingLoopDepth--;
        }

        return $this->maxSeverity($severity, $childSeverity);
    }

    #[\Override]
    public function visitAlternation(AlternationNode $node): RedosSeverity
    {
        $max = RedosSeverity::Safe;

        if ($this->unboundedQuantifierDepth > 0 && $this->hasOverlappingAlternatives($node) && !$this->loopReadsOneWayAlone()) {
            $this->addVulnerability(
                RedosSeverity::Critical,
                'Overlapping alternation branches inside a quantifier. e.g. (a|a)* or (ab|a)*. This can lead to catastrophic backtracking.',
                $node,
                'Make alternatives mutually exclusive or order longer alternatives first.',
                RedosConfidence::High,
                'Low false-positive risk; overlapping alternations are a known backtracking trigger.',
            );
            $max = RedosSeverity::Critical;
        }

        // Each alternative stands between the items around the alternation:
        // those of the call it stands for when a call is read through, as
        // the "," before "\d+" in "(?:,(?&v))*" over "(?<v>\[\]|\d+)".
        foreach ($node->alternatives as $alt) {
            $max = $this->maxSeverity($max, $alt->accept($this));
        }

        return $max;
    }

    #[\Override]
    public function visitGroup(GroupNode $node): RedosSeverity
    {
        $wasAtomic = $this->endsAtomicBody;
        $previous = $this->previousNode;
        $next = $this->nextNode;
        $depths = [$this->unboundedQuantifierDepth, $this->totalQuantifierDepth, $this->repeatingLoopDepth];
        $isAtomicGroup = GroupType::Atomic === $node->type;

        // The loops around an atomic group never come back into it: its own
        // loops nest from none, and its body ends there. A group that only
        // wraps what it holds passes on whether it ends an atomic body; any
        // other checks something once its body has matched.
        if ($isAtomicGroup) {
            $this->unboundedQuantifierDepth = 0;
            $this->totalQuantifierDepth = 0;
            $this->endsAtomicBody = true;
        } elseif (!\in_array($node->type, [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::InlineFlags, GroupType::BranchReset], true)) {
            $this->endsAtomicBody = false;
        }

        // A lookaround runs on its own, from where it stands, and holds
        // without being tried again: what its body may read in two ways is
        // its own risk, its loops nested from none, whatever the groups
        // around it. "(*napla:...)" and "(?*...)" are backtracked into.
        if ('*' !== $node->flags && \in_array($node->type, [GroupType::LookaheadPositive, GroupType::LookaheadNegative, GroupType::LookbehindPositive, GroupType::LookbehindNegative], true)) {
            $this->reportReread($node);
            $this->unboundedQuantifierDepth = 0;
            $this->totalQuantifierDepth = 0;
            $this->repeatingLoopDepth = 0;
        }

        // Only a group that wraps what it holds ends the iteration with it.
        $head = $this->iterationHead;
        if (!\in_array($node->type, [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::InlineFlags, GroupType::BranchReset], true)) {
            $this->iterationHead = null;
        }

        $siblings = $this->siblings;
        $position = $this->position;
        $this->previousNode = null;
        $this->nextNode = null;
        $this->siblings = [];
        $this->position = -1;
        $severity = $node->child->accept($this);
        $this->previousNode = $previous;
        $this->nextNode = $next;
        $this->siblings = $siblings;
        $this->position = $position;
        $this->iterationHead = $head;

        [$this->unboundedQuantifierDepth, $this->totalQuantifierDepth, $this->repeatingLoopDepth] = $depths;
        $this->endsAtomicBody = $wasAtomic;

        return $isAtomicGroup ? $this->reduceSeverity($severity, RedosSeverity::Low) : $severity;
    }

    #[\Override]
    public function visitSequence(SequenceNode $node): RedosSeverity
    {
        $max = RedosSeverity::Safe;
        $previous = $this->previousNode;
        $next = $this->nextNode;
        $endsAtomicBody = $this->endsAtomicBody;
        $head = $this->iterationHead;
        $last = null;
        $children = $node->children;
        $total = \count($children);

        // In a sequence that ends an atomic body, its last item ends it in
        // turn, past the verbs that cut backtracking: they never fail.
        $tail = $endsAtomicBody ? $this->tailIndex($children) : null;

        // An item that reads nothing, an option setting above all, keeps
        // no two items apart: the ones before and after it meet, and a loop
        // followed by such items alone ends the sequence.
        $reads = [];
        $readsAll = true;
        foreach ($children as $index => $child) {
            $reads[$index] = $child instanceof LiteralNode ? '' !== $child->value : !self::readsNothing($child);
            $readsAll = $readsAll && $reads[$index];
        }

        $max = $this->maxSeverity($max, $this->analyzeAdjacentQuantifiers($children, $readsAll ? null : $reads, $tail));

        // The reading item after each one, read from the end when an item
        // reads nothing.
        $following = [];
        $after = null;
        for ($index = $readsAll ? -1 : $total - 1; $index >= 0; $index--) {
            $following[$index] = $after;
            if ($reads[$index]) {
                $after = $children[$index];
            }
        }

        // The iteration may end after each item from the last that always
        // reads a character: the ones after it may all match empty.
        $lastSolid = -1;
        for ($index = null === $head ? -1 : $total - 1; $index >= 0; $index--) {
            if ($reads[$index] && $this->alwaysReads($children[$index])) {
                $lastSolid = $index;

                break;
            }
        }

        $siblings = $this->siblings;
        $position = $this->position;
        $this->siblings = $children;
        foreach ($children as $index => $child) {
            $followedBy = $readsAll ? ($children[$index + 1] ?? null) : $following[$index];
            $this->previousNode = $last;
            $this->nextNode = $followedBy;
            $this->position = $index;
            $this->iterationHead = $index >= $lastSolid ? $head : null;
            $this->endsAtomicBody = $index === $tail;
            $max = $this->maxSeverity($max, $child->accept($this));
            if ($reads[$index]) {
                $last = $child;
            }
        }

        $this->siblings = $siblings;
        $this->position = $position;
        $this->previousNode = $previous;
        $this->nextNode = $next;
        $this->iterationHead = $head;
        $this->endsAtomicBody = $endsAtomicBody;

        return $max;
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): RedosSeverity
    {
        return RedosSeverity::Safe;
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): RedosSeverity
    {
        return RedosSeverity::Safe;
    }

    #[\Override]
    public function visitDot(DotNode $node): RedosSeverity
    {
        return RedosSeverity::Safe;
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node): RedosSeverity
    {
        return RedosSeverity::Safe;
    }

    #[\Override]
    public function visitAssertion(AssertionNode $node): RedosSeverity
    {
        return RedosSeverity::Safe;
    }

    #[\Override]
    public function visitKeep(KeepNode $node): RedosSeverity
    {
        return RedosSeverity::Safe;
    }

    #[\Override]
    public function visitCharClass(CharClassNode $node): RedosSeverity
    {
        return RedosSeverity::Safe;
    }

    #[\Override]
    public function visitRange(RangeNode $node): RedosSeverity
    {
        return RedosSeverity::Safe;
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): RedosSeverity
    {
        return RedosSeverity::Safe;
    }

    #[\Override]
    public function visitControlChar(ControlCharNode $node): RedosSeverity
    {
        return RedosSeverity::Safe;
    }

    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node): RedosSeverity
    {
        return RedosSeverity::Safe;
    }

    #[\Override]
    public function visitClassSetOperation(ClassSetOperationNode $node): RedosSeverity
    {
        return RedosSeverity::Safe;
    }

    #[\Override]
    public function visitScriptRun(ScriptRunNode $node): RedosSeverity
    {
        if (null === $node->content) {
            return RedosSeverity::Safe;
        }

        // The body of "(*asr:...)" is atomic, as in an atomic group; that of
        // "(*sr:...)" is checked once it has matched.
        $wasAtomic = $this->endsAtomicBody;
        $depths = [$this->unboundedQuantifierDepth, $this->totalQuantifierDepth];
        $this->endsAtomicBody = $node->isAtomic;
        if ($node->isAtomic) {
            $this->unboundedQuantifierDepth = 0;
            $this->totalQuantifierDepth = 0;
        }

        $severity = $node->content->accept($this);
        $this->endsAtomicBody = $wasAtomic;
        [$this->unboundedQuantifierDepth, $this->totalQuantifierDepth] = $depths;

        return $node->isAtomic ? $this->reduceSeverity($severity, RedosSeverity::Low) : $severity;
    }

    #[\Override]
    public function visitVersionCondition(VersionConditionNode $node): RedosSeverity
    {
        return RedosSeverity::Safe;
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): RedosSeverity
    {
        return RedosSeverity::Safe;
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): RedosSeverity
    {
        return RedosSeverity::Safe;
    }

    #[\Override]
    public function visitPosixClass(PosixClassNode $node): RedosSeverity
    {
        return RedosSeverity::Safe;
    }

    #[\Override]
    public function visitComment(CommentNode $node): RedosSeverity
    {
        return RedosSeverity::Safe;
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): RedosSeverity
    {
        return RedosSeverity::Safe;
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): RedosSeverity
    {
        // The condition itself may be a lookaround that backtracks; one that
        // asks whether a group is set or a recursion runs reads nothing.
        return $this->maxSeverity(
            self::isReferenceCondition($node) ? RedosSeverity::Safe : $node->condition->accept($this),
            $this->maxSeverity(
                $node->yes->accept($this),
                $node->no->accept($this),
            ),
        );
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): RedosSeverity
    {
        $this->addVulnerability(
            RedosSeverity::Low,
            'Subroutines can lead to complex backtracking and potential ReDoS if not used carefully, especially with recursion. Review the referenced pattern.',
            $node,
            'Avoid excessive recursion or add atomic groups around recursive parts.',
            RedosConfidence::Medium,
            'Medium false-positive risk; recursion depth and input shape matter.',
        );

        // A call runs the group it calls where it stands, as if its body
        // stood in its place: in a loop at each iteration, "(?:(?1))+" over
        // "(a|a)" is "(?:a|a)+", and outside one without the atomic group
        // written around the group, "y(?1)$" backtracking into the
        // "(?:a+)+" of an atomic "x((?:a+)+)". A call met again while its
        // group is read is a recursion, left there. Any other is read once
        // from each place it runs from: a call reached from a DEFINE block,
        // outside any loop, is read again where a repeated call reaches it.
        $group = $this->calledGroup($node);
        if (null === $group || isset($this->groupsReadThrough[spl_object_id($group)])) {
            return RedosSeverity::Low;
        }

        $place = $this->placeOf($node);
        if (!isset($this->callsReadThrough[$place])) {
            $this->groupsReadThrough[spl_object_id($group)] = true;
            $this->callsReadThrough[$place] = $this->maxSeverity(RedosSeverity::Low, $group->child->accept($this));
            unset($this->groupsReadThrough[spl_object_id($group)]);
        }

        return $this->callsReadThrough[$place];
    }

    #[\Override]
    public function visitDefine(DefineNode $node): RedosSeverity
    {
        // The groups of a DEFINE block run only when a subroutine calls them,
        // never where the block stands: its content is analysed on its own,
        // outside the loops around it.
        return $this->readOnItsOwn($node->content);
    }

    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node): RedosSeverity
    {
        return RedosSeverity::Safe;
    }

    /**
     * Visits a CalloutNode and treats it as neutral for ReDoS purposes.
     */
    #[\Override]
    public function visitCallout(CalloutNode $node): RedosSeverity
    {
        return RedosSeverity::Safe;
    }

    /**
     * Numbers the capturing groups from $count, in the order written, and
     * returns how many are open after the node: the branches of a branch
     * reset each count from where it opens, and the most of them stands
     * after it.
     */
    private function indexGroups(NodeInterface $node, int $count): int
    {
        if ($node instanceof SubroutineNode) {
            $this->groupsBeforeCall[spl_object_id($node)] = $count;

            return $count;
        }

        if ($node instanceof GroupNode && GroupType::BranchReset === $node->type) {
            $after = $count;
            foreach ($node->child instanceof AlternationNode ? $node->child->alternatives : [$node->child] as $branch) {
                $after = max($after, $this->indexGroups($branch, $count));
            }

            return $after;
        }

        if ($node instanceof GroupNode && \in_array($node->type, [GroupType::Capturing, GroupType::Named], true)) {
            $count++;
            $this->calledGroups[$count] ??= $node;
            if (null !== $node->name) {
                $this->calledGroups[$node->name] ??= $node;
            }
        }

        foreach ($node->getChildren() as $child) {
            $count = $this->indexGroups($child, $count);
        }

        return $count;
    }

    /**
     * The group a call runs, by number, relative number or name; null for
     * a recursion into the whole pattern or a group not found.
     */
    private function calledGroup(SubroutineNode $node): ?GroupNode
    {
        $reference = $node->reference;
        $signed = str_starts_with($reference, '+') || str_starts_with($reference, '-');
        $digits = $signed ? substr($reference, 1) : $reference;
        if ('' === $digits || '' !== trim($digits, '0123456789')) {
            return $this->calledGroups[$reference] ?? null;
        }

        if (!$signed) {
            return $this->calledGroups[(int) $digits] ?? null;
        }

        $offset = (int) $reference;
        $before = $this->groupsBeforeCall[spl_object_id($node)] ?? 0;

        return $this->calledGroups[$offset < 0 ? $before + 1 + $offset : $before + $offset] ?? null;
    }

    /**
     * The call and what its reading depends on: the loops around it, as
     * far as the checks tell them apart, whether it ends an atomic body,
     * and the items around it and the iteration it ends.
     */
    private function placeOf(SubroutineNode $node): string
    {
        return \sprintf(
            '%d:%d:%d:%d:%d:%d:%d:%d',
            spl_object_id($node),
            min($this->unboundedQuantifierDepth, 2),
            min($this->totalQuantifierDepth, 2),
            min($this->repeatingLoopDepth, 1),
            (int) $this->endsAtomicBody,
            null === $this->previousNode ? 0 : spl_object_id($this->previousNode),
            null === $this->nextNode ? 0 : spl_object_id($this->nextNode),
            null === $this->iterationHead ? 0 : spl_object_id($this->iterationHead),
        );
    }

    /**
     * A part that runs only through a call: read outside the loops around
     * where it stands.
     */
    private function readOnItsOwn(NodeInterface $node): RedosSeverity
    {
        $depths = [$this->unboundedQuantifierDepth, $this->totalQuantifierDepth, $this->repeatingLoopDepth];
        $endsAtomicBody = $this->endsAtomicBody;
        $head = $this->iterationHead;
        $this->unboundedQuantifierDepth = 0;
        $this->totalQuantifierDepth = 0;
        $this->repeatingLoopDepth = 0;
        $this->endsAtomicBody = false;
        $this->iterationHead = null;
        $severity = $node->accept($this);
        [$this->unboundedQuantifierDepth, $this->totalQuantifierDepth, $this->repeatingLoopDepth] = $depths;
        $this->endsAtomicBody = $endsAtomicBody;
        $this->iterationHead = $head;

        return $severity;
    }

    private function holdsCapturingGroup(NodeInterface $node): bool
    {
        if ($node instanceof GroupNode && $this->isCapturingGroup($node)) {
            return true;
        }

        // Every child, a DEFINE block's content included: the groups it
        // declares run through a call.
        foreach ($node->getChildren() as $child) {
            if ($this->holdsCapturingGroup($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * In a loop, a lookaround holding an unbounded repeat reads the subject
     * again from each position the loop gives: quadratic. Only a lookahead
     * can hold one, and a lookbehind through a lookahead it holds.
     */
    private function reportReread(GroupNode $node): void
    {
        if ($this->repeatingLoopDepth > 0 && $this->holdsUnboundedRepeat($node->child)) {
            $this->addVulnerability(
                RedosSeverity::Medium,
                'A lookaround in a repeated group runs again at each repetition, and the unbounded repeat inside it reads the subject again from there: the cost grows with the square of the length. Move the lookaround out of the loop, or bound what it reads.',
                $node,
                'Check the condition once, before the loop, or bound the repeat inside the lookaround.',
                RedosConfidence::Medium,
                'Medium false-positive risk; the cost depends on how far the lookaround reads from each position.',
            );
        }
    }

    private function holdsUnboundedRepeat(NodeInterface $node): bool
    {
        if ($node instanceof QuantifierNode && $this->isUnbounded($node)) {
            return true;
        }

        foreach ($this->getChildren($node) as $child) {
            if ($this->holdsUnboundedRepeat($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if a given quantifier is unbounded (e.g., `*`, `+`, `{n,}`).
     */
    private function isUnbounded(QuantifierNode $node): bool
    {
        return $node->bounds?->isUnbounded() ?? false;
    }

    /**
     * Checks if a given quantifier is bounded but allows for a very large number of repetitions.
     */
    private function isLargeBounded(QuantifierNode $node): bool
    {
        $bounds = $node->bounds;

        return null !== $bounds && null !== $bounds->max && $bounds->max > 1000;
    }

    /**
     * Determines if an AlternationNode contains overlapping alternatives.
     */
    private function hasOverlappingAlternatives(AlternationNode $node): bool
    {
        // A branch that calls a group starts as that group does; a recursion
        // into the whole pattern is left out, read as no overlap, and the
        // other branches are compared without it.
        $branches = [];
        foreach ($node->alternatives as $alt) {
            if ($alt instanceof SubroutineNode) {
                $alt = $this->calledGroup($alt);
                if (null === $alt) {
                    continue;
                }
            }

            $branches[] = $alt;
        }

        $sets = [];

        foreach ($branches as $alt) {
            $set = $this->setsAt($alt)->firstChars($alt);

            // A set not known may meet any other, in whichever order the
            // branches are written: "a|\1" as "\1|a". The dot's set leaves
            // out the newlines of the convention in force without s: under
            // (*ANY), "\x85|." starts apart as ".|\x85" does.
            foreach ($sets as $existing) {
                if ($set->intersects($existing)) {
                    return true;
                }
            }

            $sets[] = $set;
        }

        return false;
    }

    /**
     * The index of the last item of a sequence, past the verbs that cut
     * backtracking.
     *
     * @param list<NodeInterface> $children
     */
    private function tailIndex(array $children): int
    {
        $index = \count($children) - 1;
        while ($index > 0 && $this->isBacktrackingControl($children[$index])) {
            $index--;
        }

        return $index;
    }

    private function isBacktrackingControl(NodeInterface $node): bool
    {
        return $node instanceof PcreVerbNode
            && \in_array(Ascii::upper(explode(':', $node->verb, 2)[0]), ['COMMIT', 'PRUNE', 'SKIP'], true);
    }

    /**
     * Whether the engine cuts backtracking at the verb: "(*SKIP:NAME)" only
     * where it finds a "(*MARK:NAME)" on its way back, being ignored
     * otherwise.
     */
    private function cutsBacktracking(PcreVerbNode $verb): bool
    {
        if (!$this->isBacktrackingControl($verb)) {
            return false;
        }

        if ('SKIP' !== Ascii::upper($verb->name) || null === $verb->argument) {
            return true;
        }

        if (null === $this->skipsPastTheirMark) {
            $this->skipsPastTheirMark = [];
            if (null !== $this->pattern) {
                $this->indexSkips($this->pattern, []);
            }
        }

        return isset($this->skipsPastTheirMark[spl_object_id($verb)]);
    }

    private function hasTrailingBacktrackingControl(NodeInterface $node): bool
    {
        $verbNode = $this->extractTrailingVerb($node);

        return null !== $verbNode && $this->cutsBacktracking($verbNode);
    }

    /**
     * The verb that ends the node, through the groups that only wrap what
     * they hold: a verb inside a lookaround or an atomic group acts there
     * alone, that group never backtracked into once it has matched.
     */
    private function extractTrailingVerb(NodeInterface $node): ?PcreVerbNode
    {
        if ($node instanceof PcreVerbNode) {
            return $node;
        }

        if ($node instanceof SequenceNode && !empty($node->children)) {
            $last = $node->children[\count($node->children) - 1];

            return $this->extractTrailingVerb($last);
        }

        if ($node instanceof GroupNode && \in_array($node->type, [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::InlineFlags, GroupType::BranchReset], true)) {
            return $this->extractTrailingVerb($node->child);
        }

        return null;
    }

    /**
     * Records the skips to a name that follow a mark of their name the
     * engine meets on every way to them and finds again going back: one
     * written before them in the sequence that holds them or in one around
     * it, through the groups that only wrap what they hold. A mark inside
     * a lookaround or an atomic group, which the engine never goes back
     * into once they have matched, or on one branch of an alternation is
     * not found from every way, and a skip that finds none is ignored.
     *
     * @param array<string, true> $marks the marks set on every way to the node
     */
    private function indexSkips(NodeInterface $node, array $marks): void
    {
        if ($node instanceof PcreVerbNode) {
            if ('SKIP' === Ascii::upper($node->name) && null !== $node->argument && isset($marks[$node->argument])) {
                $this->skipsPastTheirMark[spl_object_id($node)] = true;
            }

            return;
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                $this->indexSkips($child, $marks);
                $marks += self::marksAlwaysSet($child);
            }

            return;
        }

        if ($node instanceof GroupNode) {
            $this->indexSkips($node->child, self::onlyWraps($node) ? $marks : []);

            return;
        }

        foreach ($node->getChildren() as $child) {
            $this->indexSkips($child, $marks);
        }
    }

    /**
     * The marks the node sets on every way through it, where the engine
     * finds them going back.
     *
     * @return array<string, true>
     */
    private static function marksAlwaysSet(NodeInterface $node): array
    {
        if ($node instanceof PcreVerbNode) {
            return 'MARK' === Ascii::upper($node->name) && null !== $node->argument ? [$node->argument => true] : [];
        }

        if ($node instanceof GroupNode) {
            return self::onlyWraps($node) ? self::marksAlwaysSet($node->child) : [];
        }

        $marks = [];
        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                $marks += self::marksAlwaysSet($child);
            }
        }

        return $marks;
    }

    /**
     * Whether the group only wraps what it holds: the engine goes back
     * into it as into what it holds.
     */
    private static function onlyWraps(GroupNode $node): bool
    {
        return \in_array($node->type, [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::InlineFlags, GroupType::BranchReset], true);
    }

    /**
     * The characters the node may start or end with, under the options in
     * force there.
     */
    private function edgeChars(NodeInterface $node, bool $fromStart): ByteCharSet
    {
        $sets = $this->setsAt($node);

        return $fromStart ? $sets->firstChars($node) : $sets->lastChars($node);
    }

    /**
     * The items on one side of the node being read, nearest first, past
     * those that read nothing, to the first that always reads a character;
     * and whether the walk ran out of items before one, null when it is cut
     * short past ITEMS_READ_PAST items that may match empty. An item that
     * may match empty and ends with a verb that cuts backtracking ends the
     * walk on its own: the engine always gets through it and never goes
     * back past it, "\s?(*COMMIT)" after "\w+".
     *
     * @param -1|1 $direction
     *
     * @return array{list<NodeInterface>, bool|null}
     */
    private function itemsAround(int $direction): array
    {
        $items = [];
        for ($index = $this->position + $direction; isset($this->siblings[$index]); $index += $direction) {
            $item = $this->siblings[$index];
            if (self::readsNothing($item)) {
                continue;
            }

            if ($this->alwaysReads($item)) {
                $items[] = $item;

                return [$items, false];
            }

            if ($this->hasTrailingBacktrackingControl($item)) {
                return [$items, false];
            }

            $items[] = $item;
            if (\count($items) > self::ITEMS_READ_PAST) {
                return [$items, null];
            }
        }

        return [$items, true];
    }

    /**
     * Whether each item starts, or ends before the loop, apart from it:
     * what may end the one before and start the one after are known and
     * apart.
     *
     * @param list<NodeInterface> $items
     */
    private function apartFrom(array $items, NodeInterface $loop, bool $after): bool
    {
        if ([] === $items) {
            return true;
        }

        $edge = $this->edgeChars($loop, !$after);
        if ($edge->isUnknown()) {
            return false;
        }

        foreach ($items as $item) {
            $other = $this->edgeChars($item, $after);
            if ($other->isUnknown() || $other->intersects($edge)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the item keeps two iterations of a loop around apart: one
     * that may match nothing does not, "\s?" in "(\w+\s?)+" lets two runs
     * of "\w" meet.
     */
    private function separatesIterations(?NodeInterface $item): bool
    {
        return null !== $item && $this->alwaysReads($item);
    }

    /**
     * Whether every match of the item reads a character, a call read
     * through the group it runs: "(?&s)" over "(?<s>"[^"]*")" does.
     */
    private function alwaysReads(NodeInterface $item): bool
    {
        if ($item instanceof LiteralNode) {
            return '' !== $item->value;
        }

        if ($item instanceof CharTypeNode || $item instanceof CharClassNode || $item instanceof DotNode || $item instanceof CharLiteralNode) {
            return true;
        }

        $id = spl_object_id($item);
        if (isset($this->reads[$id])) {
            return $this->reads[$id];
        }

        $nullable = $item->accept($this->nullability);
        if (null === $nullable && $item instanceof SubroutineNode) {
            $nullable = $this->calledGroup($item)?->child->accept($this->nullability);
        }

        return $this->reads[$id] = false === $nullable;
    }

    private function reduceSeverity(RedosSeverity $severity, RedosSeverity $cap): RedosSeverity
    {
        return $severity->rank() > $cap->rank() ? $cap : $severity;
    }

    /**
     * Adds a detected ReDoS vulnerability to the internal list.
     */
    private function addVulnerability(
        RedosSeverity $severity,
        string $message,
        NodeInterface $triggerNode,
        ?string $suggestedRewrite = null,
        RedosConfidence $confidence = RedosConfidence::Medium,
        ?string $falsePositiveRisk = null,
        ?string $triggerOverride = null,
    ): void {
        $pattern = $this->compileNode($triggerNode);
        $trigger = $triggerOverride ?? $this->describeTrigger($triggerNode);

        $this->vulnerabilities[] = new Finding(
            $severity,
            $message,
            $pattern,
            $trigger,
            $suggestedRewrite,
            $confidence,
            $falsePositiveRisk,
        );

        $this->hotspots[] = new Hotspot(
            $triggerNode->getStartPosition(),
            $triggerNode->getEndPosition(),
            $severity,
            $pattern,
            $trigger,
        );

        if ($severity->rank() > $this->culpritSeverity->rank()) {
            $this->culpritSeverity = $severity;
            $this->culpritNode = $triggerNode;
        }
    }

    private function compileNode(NodeInterface $node): string
    {
        return $node->accept(PatternPrinter::asWritten());
    }

    private function describeTrigger(NodeInterface $node): string
    {
        return match (true) {
            $node instanceof QuantifierNode => 'quantifier '.$node->quantifier,
            $node instanceof AlternationNode => 'alternation',
            $node instanceof GroupNode => 'group',
            $node instanceof SubroutineNode => 'subroutine',
            default => $node::class,
        };
    }

    /**
     * Returns the higher of two RedosSeverity values.
     */
    private function maxSeverity(RedosSeverity $a, RedosSeverity $b): RedosSeverity
    {
        return $a->rank() > $b->rank() ? $a : $b;
    }

    /**
     * Detects if a subtree contains a backreference and a variable-length capturing group,
     * which can lead to catastrophic backtracking when repeated.
     *
     * A body that reads its input one way, never matching nothing, gives
     * each iteration one way through, whatever the length of what the
     * reference reads: the engine gives the iterations back one at a time,
     * as in the PCRE2 manual's "(a|b\1)+". The first characters of the
     * branches are only known apart without options that change them:
     * under i, "(a|A\1)+" reads "a" both ways.
     */
    private function hasBackrefLoop(NodeInterface $node): bool
    {
        $state = $this->analyzeBackrefLoop($node);

        return $state['hasBackref'] && $state['hasVariableCapture']
            && !(false === $node->accept($this->nullability) && $this->readsOneWay($node));
    }

    /**
     * Whether the loop around the alternation, read alone before an end
     * that fails, is proven linear: its branches start alike, "a" and "ab",
     * yet it reads no text in two ways. Its text reads alone as it does in
     * place only where no option setting of the pattern and no assertion in
     * it looks past the loop.
     */
    private function loopReadsOneWayAlone(): bool
    {
        return null !== $this->provenLinearAlone
            && null !== $this->loop
            && null === $this->setsInForce
            && !self::holdsAssertion($this->loop)
            && ($this->provenLinearAlone)($this->loop);
    }

    /**
     * Whether the node holds an anchor, an assertion or a lookaround, which
     * may read past the loop it stands in.
     */
    private static function holdsAssertion(NodeInterface $node): bool
    {
        if ($node instanceof AnchorNode || $node instanceof AssertionNode
            || ($node instanceof GroupNode && \in_array($node->type, [GroupType::LookaheadPositive, GroupType::LookaheadNegative, GroupType::LookbehindPositive, GroupType::LookbehindNegative], true))) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::holdsAssertion($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The analyzer that reads the node under the flags in force there.
     */
    private function setsAt(NodeInterface $node): CharSetAnalyzer
    {
        return null !== $this->setsInForce && isset($this->setsInForce[$node]) ? $this->setsInForce[$node] : $this->charSetAnalyzer;
    }

    /**
     * Whether the item reads no character and holds nothing a match could
     * stop on: an option setting, "(?i)", a group that holds no more, a
     * comment.
     */
    private static function readsNothing(NodeInterface $node): bool
    {
        if ($node instanceof LiteralNode) {
            return '' === $node->value;
        }

        if ($node instanceof GroupNode) {
            return \in_array($node->type, [GroupType::InlineFlags, GroupType::NonCapturing, GroupType::Capturing, GroupType::Named, GroupType::Atomic], true)
                && self::readsNothing($node->child);
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                if (!self::readsNothing($child)) {
                    return false;
                }
            }

            return true;
        }

        return $node instanceof CommentNode;
    }

    private static function holdsOptionSetting(NodeInterface $node): bool
    {
        if ($node instanceof GroupNode && GroupType::InlineFlags === $node->type) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::holdsOptionSetting($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the node reads its input one way: each alternation chooses
     * its branch by the first character, no branch matching nothing, each
     * count is fixed, and no other item gives a choice. An atomic group, a
     * possessive repeat and "\R" keep the first way they find, and a
     * reference reads the text its group holds.
     */
    private function readsOneWay(NodeInterface $node): bool
    {
        if ($node instanceof GroupNode) {
            return GroupType::Atomic === $node->type
                || (\in_array($node->type, [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named], true) && $this->readsOneWay($node->child));
        }

        if ($node instanceof QuantifierNode) {
            [$min, $max] = $this->quantifierBounds($node);

            return QuantifierType::Possessive === $node->type || ($min === $max && $this->readsOneWay($node->node));
        }

        if ($node instanceof ScriptRunNode) {
            return $node->isAtomic || null === $node->content || $this->readsOneWay($node->content);
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                if (!$this->readsOneWay($child)) {
                    return false;
                }
            }

            return true;
        }

        if ($node instanceof AlternationNode) {
            return $this->choosesByTheFirstCharacter($node);
        }

        return $node instanceof LiteralNode
            || $node instanceof CharLiteralNode
            || $node instanceof CharClassNode
            || $node instanceof CharTypeNode
            || $node instanceof DotNode
            || $node instanceof UnicodePropNode
            || $node instanceof PosixClassNode
            || $node instanceof ControlCharNode
            || $node instanceof BackrefNode
            || $node instanceof AnchorNode
            || $node instanceof AssertionNode
            || $node instanceof CommentNode;
    }

    private function choosesByTheFirstCharacter(AlternationNode $node): bool
    {
        $firsts = [];
        foreach ($node->alternatives as $alternative) {
            if (false !== $alternative->accept($this->nullability) || !$this->readsOneWay($alternative)) {
                return false;
            }

            $first = $this->setsAt($alternative)->firstChars($alternative);
            if ($first->isUnknown()) {
                return false;
            }

            foreach ($firsts as $other) {
                if ($first->intersects($other)) {
                    return false;
                }
            }

            $firsts[] = $first;
        }

        return true;
    }

    /**
     * @return array{hasBackref: bool, hasVariableCapture: bool}
     */
    private function analyzeBackrefLoop(NodeInterface $node): array
    {
        $hasBackref = $node instanceof BackrefNode;
        $hasVariableCapture = false;

        if ($node instanceof GroupNode && $this->isCapturingGroup($node)) {
            [$min, $max] = $node->child->accept(new LengthRangeCalculator());
            if (null === $max || $min !== $max) {
                $hasVariableCapture = true;
            }
        }

        foreach ($this->getChildren($node) as $child) {
            $childState = $this->analyzeBackrefLoop($child);
            $hasBackref = $hasBackref || $childState['hasBackref'];
            $hasVariableCapture = $hasVariableCapture || $childState['hasVariableCapture'];
        }

        return [
            'hasBackref' => $hasBackref,
            'hasVariableCapture' => $hasVariableCapture,
        ];
    }

    /**
     * @return array{0:int, 1:int|null}
     */
    private function quantifierBounds(QuantifierNode $node): array
    {
        $bounds = $node->bounds;

        return null === $bounds ? [0, null] : [$bounds->min, $bounds->max];
    }

    private function shouldFlagEmptyRepeat(NodeInterface $node, ?int $max): bool
    {
        if (!$this->quantifierAllowsMultiple($max)) {
            return false;
        }

        return true === $node->accept($this->nullability);
    }

    private function shouldDowngradeEmptyRepeat(NodeInterface $node): bool
    {
        if (!$this->hasRecursion($node)) {
            return false;
        }

        return $this->hasAtomicNullableBranch($node);
    }

    private function hasAtomicNullableBranch(NodeInterface $node): bool
    {
        if (true !== $node->accept($this->nullability)) {
            return false;
        }

        if ($node instanceof GroupNode) {
            return $this->hasAtomicNullableBranch($node->child);
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alt) {
                if (true === $alt->accept($this->nullability) && $this->isAtomicNullableNode($alt)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                if ($this->isAtomicNullableNode($child)) {
                    return true;
                }
            }

            return false;
        }

        return $this->isAtomicNullableNode($node);
    }

    private function isAtomicNullableNode(NodeInterface $node): bool
    {
        if ($node instanceof QuantifierNode) {
            [$min] = $this->quantifierBounds($node);
            if (0 !== $min) {
                return false;
            }

            if (QuantifierType::Possessive === $node->type) {
                return true;
            }

            return $node->node instanceof GroupNode && GroupType::Atomic === $node->node->type;
        }

        if ($node instanceof GroupNode && GroupType::Atomic === $node->type) {
            return true === $node->child->accept($this->nullability);
        }

        return false;
    }

    private function quantifierAllowsMultiple(?int $max): bool
    {
        return null === $max || $max > 1;
    }

    private function emptyRepeatSeverity(bool $isUnbounded, ?int $max): RedosSeverity
    {
        if ($isUnbounded) {
            return RedosSeverity::High;
        }

        if (null !== $max && $max <= 3) {
            return RedosSeverity::Low;
        }

        return RedosSeverity::Medium;
    }

    /**
     * @param list<NodeInterface>   $children the items of the sequence
     * @param array<int, bool>|null $reads    whether each item reads a character, null when each
     *                                        does: two loops meet across one that reads nothing,
     *                                        "a+(?i)A+"
     * @param int|null              $tail     the index of the item that ends an atomic body, if
     *                                        any: a pair it closes is matched the first way
     *                                        through
     */
    private function analyzeAdjacentQuantifiers(array $children, ?array $reads, ?int $tail): RedosSeverity
    {
        $max = RedosSeverity::Safe;
        $reading = null === $reads ? null : array_keys(array_filter($reads));
        $count = null === $reading ? \count($children) : \count($reading);
        // The pairs read past ITEMS_READ_PAST items so far, and the first
        // loop and item the end of PAIRS_READ_PAST parted, once it came.
        $pastPairs = 0;
        $cuts = [];

        for ($k = 0; $k < $count - 1; $k++) {
            $leftIndex = null === $reading ? $k : $reading[$k];
            $leftQuantifier = $this->unwrapAdjacentQuantifier($children[$leftIndex]);
            if (null === $leftQuantifier) {
                continue;
            }

            // A loop that repeats once at most, or is shielded, meets none.
            [, $leftMax] = $this->quantifierBounds($leftQuantifier);
            if (!$this->quantifierAllowsMultiple($leftMax) || $this->isQuantifierShielded($leftQuantifier)) {
                continue;
            }

            // A loop meets each item after it as far as the first that
            // always reads a character: "a+x?a+" meets the second "a+"
            // where "x?" matches empty. Past ITEMS_READ_PAST items, the
            // first loop it meets ends the scan, and so does the end of
            // PAIRS_READ_PAST, after which the loops further are taken to
            // meet: the items within ITEMS_READ_PAST are read all the same.
            for ($m = $k + 1; $m < $count; $m++) {
                $rightIndex = null === $reading ? $m : $reading[$m];
                $right = $children[$rightIndex];
                $past = $m - $k > self::ITEMS_READ_PAST;
                if ($past && $pastPairs >= self::PAIRS_READ_PAST) {
                    $cuts[] = [$leftIndex, $rightIndex];

                    break;
                }

                $pastPairs += (int) $past;
                $met = $rightIndex !== $tail ? $this->adjacentLoops($children, $leftIndex, $leftQuantifier, $rightIndex) : RedosSeverity::Safe;
                $max = $this->maxSeverity($max, $met);

                if (($m - $k > self::ITEMS_READ_PAST && RedosSeverity::Safe !== $met) || $this->alwaysReads($right) || $this->hasTrailingBacktrackingControl($right)) {
                    break;
                }
            }
        }

        if ([] !== $cuts) {
            $max = $this->maxSeverity($max, $this->unreadLoops($children, ...$cuts[0]));
        }

        return $max;
    }

    /**
     * The finding of a loop and an item it meets, when the item is a loop
     * too and the two may read the same characters: it names them with
     * the items between them, as written, an option setting that makes
     * them meet above all.
     *
     * @param list<NodeInterface> $children the items of the sequence
     */
    private function adjacentLoops(array $children, int $leftIndex, QuantifierNode $leftQuantifier, int $rightIndex): RedosSeverity
    {
        $rightQuantifier = $this->unwrapAdjacentQuantifier($children[$rightIndex]);

        if (null === $rightQuantifier || $this->isQuantifierShielded($leftQuantifier) || $this->isQuantifierShielded($rightQuantifier)) {
            return RedosSeverity::Safe;
        }

        [, $leftMax] = $this->quantifierBounds($leftQuantifier);
        [, $rightMax] = $this->quantifierBounds($rightQuantifier);

        if (!$this->quantifierAllowsMultiple($leftMax) || !$this->quantifierAllowsMultiple($rightMax)) {
            return RedosSeverity::Safe;
        }

        $leftTail = $this->edgeChars($leftQuantifier->node, false);
        $rightHead = $this->edgeChars($rightQuantifier->node, true);
        $overlapKnown = !$leftTail->isUnknown() && !$rightHead->isUnknown();

        if ($overlapKnown && !$leftTail->intersects($rightHead)) {
            return RedosSeverity::Safe;
        }

        $leftUnbounded = $this->isUnbounded($leftQuantifier);
        $rightUnbounded = $this->isUnbounded($rightQuantifier);
        $leftLarge = $this->isLargeBounded($leftQuantifier);
        $rightLarge = $this->isLargeBounded($rightQuantifier);

        if (!$overlapKnown && !$leftUnbounded && !$rightUnbounded && !$leftLarge && !$rightLarge) {
            return RedosSeverity::Safe;
        }

        $severity = ($leftUnbounded || $rightUnbounded) ? RedosSeverity::Medium : RedosSeverity::Low;
        if ($this->unboundedQuantifierDepth > 0) {
            $severity = $this->maxSeverity($severity, RedosSeverity::High);
        }

        $confidence = $overlapKnown ? RedosConfidence::Medium : RedosConfidence::Low;
        $falsePositiveRisk = $overlapKnown
            ? 'Medium false-positive risk; overlap is inferred from boundary character sets.'
            : 'High false-positive risk; overlap could not be determined precisely.';

        $adjacentSpan = new SequenceNode(\array_slice($children, $leftIndex, $rightIndex - $leftIndex + 1), $children[$leftIndex]->getStartPosition(), $children[$rightIndex]->getEndPosition());

        $this->addVulnerability(
            $severity,
            'Adjacent quantified tokens with overlapping character sets can cause ambiguous backtracking (e.g., a+a+ or a*a*).',
            $adjacentSpan,
            'Merge repetitions, add a delimiter, or make one quantifier possessive to remove ambiguity.',
            $confidence,
            $falsePositiveRisk,
            'adjacent quantifiers',
        );

        return $severity;
    }

    /**
     * The verdict of a sequence whose reading PAIRS_READ_PAST cut short,
     * first at the loop at $leftIndex: the loops past each cut are taken to
     * meet, as loops whose overlap is not known are, and the first cut is
     * named with the items read up to it.
     *
     * @param list<NodeInterface> $children the items of the sequence
     */
    private function unreadLoops(array $children, int $leftIndex, int $rightIndex): RedosSeverity
    {
        $severity = $this->unboundedQuantifierDepth > 0 ? RedosSeverity::High : RedosSeverity::Medium;
        $this->addVulnerability(
            $severity,
            'Loops that may match empty follow one another past the pairs the analysis compares; the ones further are taken to meet.',
            new SequenceNode(\array_slice($children, $leftIndex, $rightIndex - $leftIndex + 1), $children[$leftIndex]->getStartPosition(), $children[$rightIndex]->getEndPosition()),
            'Shorten the run of loops that may match empty, or make them possessive.',
            RedosConfidence::Low,
            'High false-positive risk; the loops past the bound were not compared.',
            'adjacent quantifiers',
        );

        return $severity;
    }

    private function unwrapAdjacentQuantifier(NodeInterface $node): ?QuantifierNode
    {
        if ($node instanceof GroupNode) {
            if (GroupType::Atomic === $node->type) {
                return null;
            }

            return $this->unwrapAdjacentQuantifier($node->child);
        }

        if ($node instanceof SequenceNode && 1 === \count($node->children)) {
            return $this->unwrapAdjacentQuantifier($node->children[0]);
        }

        return $node instanceof QuantifierNode ? $node : null;
    }

    private function isQuantifierShielded(QuantifierNode $node): bool
    {
        return QuantifierType::Possessive === $node->type
            || $this->hasTrailingBacktrackingControl($node->node)
            || ($node->node instanceof GroupNode && GroupType::Atomic === $node->node->type);
    }

    private function isCapturingGroup(GroupNode $group): bool
    {
        return \in_array($group->type, [
            GroupType::Capturing,
            GroupType::Named,
            GroupType::BranchReset,
        ], true);
    }

    private function hasRecursion(NodeInterface $node): bool
    {
        if ($node instanceof SubroutineNode) {
            return true;
        }

        foreach ($this->getChildren($node) as $child) {
            if ($this->hasRecursion($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<NodeInterface>
     */
    private function getChildren(NodeInterface $node): array
    {
        if ($node instanceof SequenceNode) {
            return $node->children;
        }
        if ($node instanceof AlternationNode) {
            return $node->alternatives;
        }
        if ($node instanceof QuantifierNode) {
            return [$node->node];
        }
        if ($node instanceof GroupNode) {
            return [$node->child];
        }
        if ($node instanceof ConditionalNode) {
            return self::isReferenceCondition($node) ? [$node->yes, $node->no] : [$node->condition, $node->yes, $node->no];
        }
        if ($node instanceof ScriptRunNode && null !== $node->content) {
            return [$node->content];
        }

        return [];
    }

    /**
     * Whether the condition asks whether a group is set, "(?(1)…)" or
     * "(?(<name>)…)", or whether a recursion runs, "(?(R)…)": a test that
     * reads no character and calls nothing, neither a backreference nor a
     * subroutine call.
     */
    private static function isReferenceCondition(ConditionalNode $node): bool
    {
        return $node->condition instanceof BackrefNode || $node->condition instanceof SubroutineNode;
    }
}
