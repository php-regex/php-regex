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

use RegexParser\Node\AlternationNode;
use RegexParser\Node\AnchorNode;
use RegexParser\Node\AssertionNode;
use RegexParser\Node\BackrefNode;
use RegexParser\Node\CalloutNode;
use RegexParser\Node\CharClassNode;
use RegexParser\Node\CharLiteralNode;
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
use RegexParser\ReDoS\CharSetAnalyzer;
use RegexParser\ReDoS\ReDoSConfidence;
use RegexParser\ReDoS\ReDoSFinding;
use RegexParser\ReDoS\ReDoSHotspot;
use RegexParser\ReDoS\ReDoSSeverity;

/**
 * Analyzes the AST to detect ReDoS vulnerabilities.
 *
 * @extends AbstractNodeVisitor<ReDoSSeverity>
 */
final class ReDoSProfileNodeVisitor extends AbstractNodeVisitor
{
    private int $unboundedQuantifierDepth = 0;

    /**
     * Tracks total nesting of quantifiers (bounded or not) to detect LOW risks.
     */
    private int $totalQuantifierDepth = 0;

    /**
     * Stores all detected ReDoS vulnerabilities during the AST traversal.
     *
     * @var array<ReDoSFinding>
     */
    private array $vulnerabilities = [];

    /**
     * @var array<ReDoSHotspot>
     */
    private array $hotspots = [];

    private bool $inAtomicGroup = false;

    private ?NodeInterface $previousNode = null;

    private ?NodeInterface $nextNode = null;

    private bool $backrefLoopDetected = false;

    private ?NodeInterface $culpritNode = null;

    private ReDoSSeverity $culpritSeverity = ReDoSSeverity::SAFE;

    public function __construct(private readonly CharSetAnalyzer $charSetAnalyzer = new CharSetAnalyzer()) {}

    /**
     * @return array{
     *     severity: ReDoSSeverity,
     *     recommendations: array<string>,
     *     vulnerablePattern: ?string,
     *     trigger: ?string,
     *     confidence: ?ReDoSConfidence,
     *     falsePositiveRisk: ?string,
     *     suggestedRewrite: ?string,
     *     findings: array<ReDoSFinding>
     * }
     */
    public function getResult(): array
    {
        $maxSeverity = ReDoSSeverity::SAFE;
        $recommendations = [];
        $pattern = null;
        $trigger = null;
        $confidence = null;
        $falsePositiveRisk = null;
        $suggestedRewrite = null;

        foreach ($this->vulnerabilities as $vuln) {
            if ($this->severityGreaterThan($vuln->severity, $maxSeverity)) {
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
            $maxSeverity = $this->maxSeverity($maxSeverity, ReDoSSeverity::CRITICAL);
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
     * @return array<ReDoSHotspot>
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
    public function visitRegex(RegexNode $node): ReDoSSeverity
    {
        $this->unboundedQuantifierDepth = 0;
        $this->totalQuantifierDepth = 0;
        $this->vulnerabilities = [];
        $this->hotspots = [];
        $this->inAtomicGroup = false;
        $this->previousNode = null;
        $this->nextNode = null;
        $this->backrefLoopDetected = false;
        $this->culpritNode = null;
        $this->culpritSeverity = ReDoSSeverity::SAFE;

        return $node->pattern->accept($this);
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): ReDoSSeverity
    {
        // Save the current atomic state to restore it later
        $wasAtomic = $this->inAtomicGroup;
        $boundarySeparatedPrev = $this->hasMutuallyExclusiveBoundary($this->previousNode, $node->node);
        $boundarySeparatedNext = $this->hasForwardMutuallyExclusiveBoundary($node->node, $this->nextNode);
        $boundarySeparated = $boundarySeparatedPrev || $boundarySeparatedNext;

        $controlVerbShield = $this->hasTrailingBacktrackingControl($node->node);
        $isPossessive = QuantifierType::T_POSSESSIVE === $node->type;

        // If the quantifier is possessive (*+, ++), its content is implicitly atomic.
        // This means it does not backtrack, preventing ReDoS in nested structures.
        if ($isPossessive || $controlVerbShield) {
            $this->inAtomicGroup = true;
        }

        // If we are inside an atomic group (explicit or via possessive quantifier),
        // we visit the child without ReDoS checks (as backtracking is disabled),
        // then restore the state and return immediately.
        if ($this->inAtomicGroup) {
            $result = $node->node->accept($this);
            $this->inAtomicGroup = $wasAtomic; // Restore state is crucial here!

            return $this->reduceSeverity($result, ReDoSSeverity::LOW);
        }

        // --- Standard ReDoS logic for non-atomic quantifiers ---

        $this->totalQuantifierDepth++;
        [, $qMax] = $this->quantifierBounds($node->quantifier);
        $isUnbounded = $this->isUnbounded($node->quantifier);

        // Check if the immediate target is an atomic group (e.g., (? >...)+)
        $isTargetAtomic = $node->node instanceof GroupNode && GroupType::T_GROUP_ATOMIC === $node->node->type;

        $severity = ReDoSSeverity::SAFE;
        $entersUnbounded = $isUnbounded && !$isTargetAtomic;
        $isNestedUnbounded = $entersUnbounded && $this->unboundedQuantifierDepth > 0;

        if ($entersUnbounded) {
            $this->unboundedQuantifierDepth++;

            if ($this->hasBackrefLoop($node->node)) {
                $this->backrefLoopDetected = true;
                $severity = ReDoSSeverity::CRITICAL;
                $this->addVulnerability(
                    ReDoSSeverity::CRITICAL,
                    'Unbounded quantifier combined with backreferences to variable-length captures can cause catastrophic backtracking.',
                    $node,
                    'Use atomic groups (?>...) or possessive quantifiers around the quantified token.',
                    ReDoSConfidence::HIGH,
                    'Low false-positive risk; nested backtracking with backreferences is a known hotspot.',
                );
            }

            if ($isNestedUnbounded) {
                $hasRecursion = $this->hasRecursion($node->node);
                $severity = $boundarySeparated ? ReDoSSeverity::LOW : ($hasRecursion ? ReDoSSeverity::MEDIUM : ReDoSSeverity::CRITICAL);
                if (!$boundarySeparated) {
                    $vulnSeverity = $hasRecursion ? ReDoSSeverity::MEDIUM : ReDoSSeverity::CRITICAL;
                    $this->addVulnerability(
                        $vulnSeverity,
                        'Nested unbounded quantifiers detected. This allows exponential backtracking. Consider using atomic groups (?>...) or possessive quantifiers (*+, ++).',
                        $node,
                        'Replace inner quantifiers with possessive variants or wrap them in (?>...).',
                        ReDoSConfidence::HIGH,
                        'Low false-positive risk; nested unbounded quantifiers are a classic ReDoS pattern.',
                    );
                }
            } else {
                $severity = $boundarySeparated ? ReDoSSeverity::LOW : ReDoSSeverity::MEDIUM;
                if (!$boundarySeparated) {
                    $this->addVulnerability(
                        ReDoSSeverity::MEDIUM,
                        'Unbounded quantifier detected. May cause backtracking on non-matching input. Consider making it possessive (*+) or using atomic groups (?>...).',
                        $node,
                        'Consider using possessive quantifiers or atomic groups to limit backtracking.',
                        ReDoSConfidence::MEDIUM,
                        'Medium false-positive risk; depends on input distribution and surrounding tokens.',
                    );
                }
            }
        } else {
            if ($this->isLargeBounded($node->quantifier)) {
                $severity = ReDoSSeverity::LOW;
                $this->addVulnerability(
                    ReDoSSeverity::LOW,
                    'Large bounded quantifier detected (>1000). May cause slow matching. Consider reducing the upper bound.',
                    $node,
                    'Reduce the upper bound or pre-validate input length.',
                    ReDoSConfidence::LOW,
                    'High false-positive risk; bounded quantifiers may still be safe in context.',
                );
            } elseif ($this->totalQuantifierDepth > 1 && 0 === $this->unboundedQuantifierDepth) {
                $severity = ReDoSSeverity::LOW;
                $this->addVulnerability(
                    ReDoSSeverity::LOW,
                    'Nested bounded quantifiers detected. May cause polynomial backtracking. Consider simplifying the pattern or using atomic groups (?>...).',
                    $node,
                    'Flatten nested quantifiers or introduce atomic groups.',
                    ReDoSConfidence::LOW,
                    'Medium false-positive risk; bounded quantifiers are often acceptable.',
                );
            }
        }

        if ($this->shouldFlagEmptyRepeat($node->node, $qMax)) {
            $repeatEmptySeverity = $this->emptyRepeatSeverity($isUnbounded, $qMax);
            if ($this->unboundedQuantifierDepth > 1) {
                $repeatEmptySeverity = ReDoSSeverity::CRITICAL;
            }

            $repeatConfidence = ReDoSConfidence::HIGH;
            $repeatFalsePositiveRisk = 'Low false-positive risk; repeated empty matches are a known backtracking hotspot.';
            if ($this->shouldDowngradeEmptyRepeat($node->node)) {
                $repeatEmptySeverity = $this->reduceSeverity($repeatEmptySeverity, ReDoSSeverity::MEDIUM);
                $repeatConfidence = ReDoSConfidence::MEDIUM;
                $repeatFalsePositiveRisk = 'Medium false-positive risk; possessive or atomic branches with recursion can reduce backtracking.';
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
        $this->previousNode = null;
        $this->nextNode = null;
        $childSeverity = $node->node->accept($this);
        $this->previousNode = $childPrevious;
        $this->nextNode = $childNext;

        if ($entersUnbounded && !$boundarySeparated && ReDoSSeverity::HIGH === $childSeverity) {
            $hasRecursion = $this->hasRecursion($node->node);
            $vulnSeverity = $hasRecursion ? ReDoSSeverity::MEDIUM : ReDoSSeverity::CRITICAL;
            $severity = $hasRecursion ? ReDoSSeverity::MEDIUM : ReDoSSeverity::CRITICAL;
            $this->addVulnerability(
                $vulnSeverity,
                'Critical nesting of quantifiers detected (Star Height > 1). This is a classic ReDoS risk. Refactor the pattern to avoid nested unbounded quantifiers over the same subpattern.',
                $node,
                'Use atomic groups or restructure the repetition to be deterministic.',
                ReDoSConfidence::HIGH,
                $hasRecursion ? 'Medium false-positive risk; recursion may mitigate some backtracking.' : 'Low false-positive risk; star-height > 1 patterns are highly suspect.',
            );
        }

        if ($entersUnbounded) {
            $this->unboundedQuantifierDepth--;
        }
        $this->totalQuantifierDepth--;

        // Restore state (just in case, though the early return handles the true case)
        $this->inAtomicGroup = $wasAtomic;

        return $this->maxSeverity($severity, $childSeverity);
    }

    #[\Override]
    public function visitAlternation(AlternationNode $node): ReDoSSeverity
    {
        $max = ReDoSSeverity::SAFE;
        $previous = $this->previousNode;
        $next = $this->nextNode;

        if ($this->unboundedQuantifierDepth > 0 && $this->hasOverlappingAlternatives($node)) {
            $this->addVulnerability(
                ReDoSSeverity::CRITICAL,
                'Overlapping alternation branches inside a quantifier. e.g. (a|a)* or (ab|a)*. This can lead to catastrophic backtracking.',
                $node,
                'Make alternatives mutually exclusive or order longer alternatives first.',
                ReDoSConfidence::HIGH,
                'Low false-positive risk; overlapping alternations are a known backtracking trigger.',
            );
            $max = ReDoSSeverity::CRITICAL;
        }

        foreach ($node->alternatives as $alt) {
            $this->previousNode = null;
            $this->nextNode = null;
            $max = $this->maxSeverity($max, $alt->accept($this));
        }

        $this->previousNode = $previous;
        $this->nextNode = $next;

        return $max;
    }

    #[\Override]
    public function visitGroup(GroupNode $node): ReDoSSeverity
    {
        $wasAtomic = $this->inAtomicGroup;
        $previous = $this->previousNode;
        $next = $this->nextNode;
        $isAtomicGroup = GroupType::T_GROUP_ATOMIC === $node->type;
        if ($isAtomicGroup) {
            $this->inAtomicGroup = true;
        }

        $this->previousNode = null;
        $this->nextNode = null;
        $severity = $node->child->accept($this);
        $this->previousNode = $previous;
        $this->nextNode = $next;

        $this->inAtomicGroup = $wasAtomic;

        return $isAtomicGroup ? $this->reduceSeverity($severity, ReDoSSeverity::LOW) : $severity;
    }

    #[\Override]
    public function visitSequence(SequenceNode $node): ReDoSSeverity
    {
        $max = ReDoSSeverity::SAFE;
        $previous = $this->previousNode;
        $next = $this->nextNode;
        $last = null;
        $total = \count($node->children);

        if (!$this->inAtomicGroup) {
            $max = $this->maxSeverity($max, $this->analyzeAdjacentQuantifiers($node));
        }

        foreach ($node->children as $index => $child) {
            $this->previousNode = $last;
            $this->nextNode = $index + 1 < $total ? $node->children[$index + 1] : null;
            $max = $this->maxSeverity($max, $child->accept($this));
            $last = $child;
        }

        $this->previousNode = $previous;
        $this->nextNode = $next;

        return $max;
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): ReDoSSeverity
    {
        return ReDoSSeverity::SAFE;
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): ReDoSSeverity
    {
        return ReDoSSeverity::SAFE;
    }

    #[\Override]
    public function visitDot(DotNode $node): ReDoSSeverity
    {
        return ReDoSSeverity::SAFE;
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node): ReDoSSeverity
    {
        return ReDoSSeverity::SAFE;
    }

    #[\Override]
    public function visitAssertion(AssertionNode $node): ReDoSSeverity
    {
        return ReDoSSeverity::SAFE;
    }

    #[\Override]
    public function visitKeep(KeepNode $node): ReDoSSeverity
    {
        return ReDoSSeverity::SAFE;
    }

    #[\Override]
    public function visitCharClass(CharClassNode $node): ReDoSSeverity
    {
        return ReDoSSeverity::SAFE;
    }

    #[\Override]
    public function visitRange(RangeNode $node): ReDoSSeverity
    {
        return ReDoSSeverity::SAFE;
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): ReDoSSeverity
    {
        return ReDoSSeverity::SAFE;
    }

    #[\Override]
    public function visitControlChar(ControlCharNode $node): ReDoSSeverity
    {
        return ReDoSSeverity::SAFE;
    }

    /**
     * @deprecated the parser no longer builds a ClassOperationNode; this method goes in the next major version
     */
    #[\Override]
    public function visitClassOperation(ClassOperationNode $node): ReDoSSeverity
    {
        return $this->maxSeverity($node->left->accept($this), $node->right->accept($this));
    }

    #[\Override]
    public function visitScriptRun(ScriptRunNode $node): ReDoSSeverity
    {
        if (null === $node->content) {
            return ReDoSSeverity::SAFE;
        }

        if (!$node->atomic) {
            return $node->content->accept($this);
        }

        // The body of "(*asr:...)" is atomic, as in an atomic group.
        $wasAtomic = $this->inAtomicGroup;
        $this->inAtomicGroup = true;
        $severity = $node->content->accept($this);
        $this->inAtomicGroup = $wasAtomic;

        return $this->reduceSeverity($severity, ReDoSSeverity::LOW);
    }

    #[\Override]
    public function visitVersionCondition(VersionConditionNode $node): ReDoSSeverity
    {
        return ReDoSSeverity::SAFE;
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): ReDoSSeverity
    {
        return ReDoSSeverity::SAFE;
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): ReDoSSeverity
    {
        return ReDoSSeverity::SAFE;
    }

    #[\Override]
    public function visitPosixClass(PosixClassNode $node): ReDoSSeverity
    {
        return ReDoSSeverity::SAFE;
    }

    #[\Override]
    public function visitComment(CommentNode $node): ReDoSSeverity
    {
        return ReDoSSeverity::SAFE;
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): ReDoSSeverity
    {
        return ReDoSSeverity::SAFE;
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): ReDoSSeverity
    {
        // The condition itself may be a lookaround that backtracks.
        return $this->maxSeverity(
            $node->condition->accept($this),
            $this->maxSeverity(
                $node->yes->accept($this),
                $node->no->accept($this),
            ),
        );
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): ReDoSSeverity
    {
        $this->addVulnerability(
            ReDoSSeverity::LOW,
            'Subroutines can lead to complex backtracking and potential ReDoS if not used carefully, especially with recursion. Review the referenced pattern.',
            $node,
            'Avoid excessive recursion or add atomic groups around recursive parts.',
            ReDoSConfidence::MEDIUM,
            'Medium false-positive risk; recursion depth and input shape matter.',
        );

        return ReDoSSeverity::LOW;
    }

    #[\Override]
    public function visitDefine(DefineNode $node): ReDoSSeverity
    {
        // Analyze the content of the DEFINE block for ReDoS vulnerabilities
        return $node->content->accept($this);
    }

    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node): ReDoSSeverity
    {
        return ReDoSSeverity::SAFE;
    }

    /**
     * Visits a CalloutNode and treats it as neutral for ReDoS purposes.
     */
    #[\Override]
    public function visitCallout(CalloutNode $node): ReDoSSeverity
    {
        return ReDoSSeverity::SAFE;
    }

    /**
     * Checks if a given quantifier is unbounded (e.g., `*`, `+`, `{n,}`).
     */
    private function isUnbounded(string $quantifier): bool
    {
        return QuantifierBounds::parse($quantifier)?->isUnbounded() ?? false;
    }

    /**
     * Checks if a given quantifier is bounded but allows for a very large number of repetitions.
     */
    private function isLargeBounded(string $quantifier): bool
    {
        $bounds = QuantifierBounds::parse($quantifier);

        return null !== $bounds && null !== $bounds->max && $bounds->max > 1000;
    }

    /**
     * Determines if an AlternationNode contains overlapping alternatives.
     */
    private function hasOverlappingAlternatives(AlternationNode $node): bool
    {
        // If any alternative is a subroutine (recursion), consider it non-overlapping for ReDoS purposes
        foreach ($node->alternatives as $alt) {
            if ($alt instanceof SubroutineNode) {
                return false;
            }
        }

        $sets = [];

        foreach ($node->alternatives as $alt) {
            $set = $this->charSetAnalyzer->firstChars($alt);

            if ($this->startsWithDot($alt)) {
                if (!empty($sets)) {
                    return true;
                }
                $sets[] = $set;

                continue;
            }

            if (!$set->isUnknown()) {
                foreach ($sets as $existing) {
                    if ($set->intersects($existing)) {
                        return true;
                    }
                }
            }

            $sets[] = $set;
        }

        return false;
    }

    /**
     * Generates a "signature" for the starting element of a node, used for overlap detection.
     */
    private function getPrefixSignature(NodeInterface $node): string
    {
        if ($node instanceof DotNode) {
            return 'DOT';
        }
        if ($node instanceof SequenceNode && !empty($node->children)) {
            return $this->getPrefixSignature($node->children[0]);
        }
        if ($node instanceof GroupNode) {
            return $this->getPrefixSignature($node->child);
        }
        if ($node instanceof QuantifierNode) {
            return $this->getPrefixSignature($node->node);
        }

        return '';
    }

    private function startsWithDot(NodeInterface $node): bool
    {
        return 'DOT' === $this->getPrefixSignature($node);
    }

    private function hasTrailingBacktrackingControl(NodeInterface $node): bool
    {
        $verbNode = $this->extractTrailingVerb($node);
        if (null === $verbNode) {
            return false;
        }

        $verbName = strtoupper(explode(':', $verbNode->verb, 2)[0]);

        return \in_array($verbName, ['COMMIT', 'PRUNE', 'SKIP'], true);
    }

    private function extractTrailingVerb(NodeInterface $node): ?PcreVerbNode
    {
        if ($node instanceof PcreVerbNode) {
            return $node;
        }

        if ($node instanceof SequenceNode && !empty($node->children)) {
            $last = $node->children[\count($node->children) - 1];

            return $this->extractTrailingVerb($last);
        }

        if ($node instanceof GroupNode) {
            return $this->extractTrailingVerb($node->child);
        }

        return null;
    }

    private function hasMutuallyExclusiveBoundary(?NodeInterface $previous, NodeInterface $current): bool
    {
        if (null === $previous) {
            return false;
        }

        $previousTail = $this->charSetAnalyzer->lastChars($previous);
        $currentHead = $this->charSetAnalyzer->firstChars($current);

        if ($previousTail->isUnknown() || $currentHead->isUnknown()) {
            return false;
        }

        return !$previousTail->intersects($currentHead);
    }

    private function hasForwardMutuallyExclusiveBoundary(NodeInterface $current, ?NodeInterface $next): bool
    {
        if (null === $next) {
            return false;
        }

        $currentTail = $this->charSetAnalyzer->lastChars($current);
        $nextHead = $this->charSetAnalyzer->firstChars($next);

        if ($currentTail->isUnknown() || $nextHead->isUnknown()) {
            return false;
        }

        return !$currentTail->intersects($nextHead);
    }

    private function reduceSeverity(ReDoSSeverity $severity, ReDoSSeverity $cap): ReDoSSeverity
    {
        return $this->severityGreaterThan($severity, $cap) ? $cap : $severity;
    }

    /**
     * Adds a detected ReDoS vulnerability to the internal list.
     */
    private function addVulnerability(
        ReDoSSeverity $severity,
        string $message,
        NodeInterface $triggerNode,
        ?string $suggestedRewrite = null,
        ReDoSConfidence $confidence = ReDoSConfidence::MEDIUM,
        ?string $falsePositiveRisk = null,
        ?string $triggerOverride = null,
    ): void {
        $pattern = $this->compileNode($triggerNode);
        $trigger = $triggerOverride ?? $this->describeTrigger($triggerNode);

        $this->vulnerabilities[] = new ReDoSFinding(
            $severity,
            $message,
            $pattern,
            $trigger,
            $suggestedRewrite,
            $confidence,
            $falsePositiveRisk,
        );

        $this->hotspots[] = new ReDoSHotspot(
            $triggerNode->getStartPosition(),
            $triggerNode->getEndPosition(),
            $severity,
            $pattern,
            $trigger,
        );

        if ($this->severityGreaterThan($severity, $this->culpritSeverity)) {
            $this->culpritSeverity = $severity;
            $this->culpritNode = $triggerNode;
        }
    }

    private function compileNode(NodeInterface $node): string
    {
        return $node->accept(new CompilerNodeVisitor());
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
     * Compares two ReDoSSeverity values.
     */
    private function severityGreaterThan(ReDoSSeverity $a, ReDoSSeverity $b): bool
    {
        $levels = [
            ReDoSSeverity::SAFE->value => 0,
            ReDoSSeverity::LOW->value => 1,
            ReDoSSeverity::UNKNOWN->value => 2,
            ReDoSSeverity::MEDIUM->value => 3,
            ReDoSSeverity::HIGH->value => 4,
            ReDoSSeverity::CRITICAL->value => 5,
        ];

        return $levels[$a->value] > $levels[$b->value];
    }

    /**
     * Returns the higher of two ReDoSSeverity values.
     */
    private function maxSeverity(ReDoSSeverity $a, ReDoSSeverity $b): ReDoSSeverity
    {
        return $this->severityGreaterThan($a, $b) ? $a : $b;
    }

    /**
     * Detects if a subtree contains a backreference and a variable-length capturing group,
     * which can lead to catastrophic backtracking when repeated.
     */
    private function hasBackrefLoop(NodeInterface $node): bool
    {
        $state = $this->analyzeBackrefLoop($node);

        return $state['hasBackref'] && $state['hasVariableCapture'];
    }

    /**
     * @return array{hasBackref: bool, hasVariableCapture: bool}
     */
    private function analyzeBackrefLoop(NodeInterface $node): array
    {
        $hasBackref = $node instanceof BackrefNode;
        $hasVariableCapture = false;

        if ($node instanceof GroupNode && $this->isCapturingGroup($node)) {
            [$min, $max] = $this->lengthRange($node->child);
            if (null === $max || $min !== $max) {
                $hasVariableCapture = true;
            }
        }

        $children = match (true) {
            $node instanceof SequenceNode => $node->children,
            $node instanceof AlternationNode => $node->alternatives,
            $node instanceof QuantifierNode => [$node->node],
            $node instanceof GroupNode => [$node->child],
            $node instanceof ConditionalNode => [$node->condition, $node->yes, $node->no],
            default => [],
        };

        foreach ($children as $child) {
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
    private function lengthRange(NodeInterface $node): array
    {
        if ($node instanceof LiteralNode) {
            $len = \strlen($node->value);

            return [$len, $len];
        }

        if ($node instanceof CharTypeNode
            || $node instanceof DotNode
            || $node instanceof CharClassNode
            || $node instanceof RangeNode
            || $node instanceof UnicodePropNode
            || $node instanceof CharLiteralNode
            || $node instanceof PosixClassNode
        ) {
            return [1, 1];
        }

        if ($node instanceof AnchorNode
            || $node instanceof AssertionNode
            || $node instanceof KeepNode
            || $node instanceof PcreVerbNode
            || $node instanceof CommentNode
            || $node instanceof CalloutNode
        ) {
            return [0, 0];
        }

        if ($node instanceof SequenceNode) {
            $min = 0;
            $max = 0;
            foreach ($node->children as $child) {
                [$cMin, $cMax] = $this->lengthRange($child);
                $min += $cMin;
                $max = null === $max || null === $cMax ? null : $max + $cMax;
            }

            return [$min, $max];
        }

        if ($node instanceof AlternationNode) {
            $min = null;
            $max = 0;
            foreach ($node->alternatives as $child) {
                [$cMin, $cMax] = $this->lengthRange($child);
                $min = null === $min ? $cMin : min($min, $cMin);
                $max = null === $max || null === $cMax ? null : max($max, $cMax);
            }

            return [$min ?? 0, $max];
        }

        if ($node instanceof GroupNode) {
            return $this->lengthRange($node->child);
        }

        if ($node instanceof QuantifierNode) {
            [$cMin, $cMax] = $this->lengthRange($node->node);
            [$qMin, $qMax] = $this->quantifierBounds($node->quantifier);

            $min = $cMin * $qMin;
            $max = null === $cMax || null === $qMax ? null : $cMax * $qMax;

            return [$min, $max];
        }

        if ($node instanceof BackrefNode || $node instanceof SubroutineNode) {
            return [0, null];
        }

        return [0, null];
    }

    private function nullableStatus(NodeInterface $node): ?bool
    {
        if ($node instanceof RegexNode) {
            return $this->nullableStatus($node->pattern);
        }

        if ($node instanceof LiteralNode) {
            return '' === $node->value;
        }

        if ($node instanceof CharTypeNode
            || $node instanceof DotNode
            || $node instanceof CharClassNode
            || $node instanceof RangeNode
            || $node instanceof UnicodePropNode
            || $node instanceof CharLiteralNode
            || $node instanceof PosixClassNode
            || $node instanceof ControlCharNode
        ) {
            return false;
        }

        if ($node instanceof AnchorNode
            || $node instanceof AssertionNode
            || $node instanceof KeepNode
            || $node instanceof PcreVerbNode
            || $node instanceof CommentNode
            || $node instanceof CalloutNode
            || $node instanceof LimitMatchNode
            || $node instanceof ScriptRunNode
            || $node instanceof VersionConditionNode
            || $node instanceof DefineNode
        ) {
            return true;
        }

        if ($node instanceof BackrefNode || $node instanceof SubroutineNode) {
            return null;
        }

        if ($node instanceof GroupNode) {
            if (\in_array($node->type, [
                GroupType::T_GROUP_LOOKAHEAD_POSITIVE,
                GroupType::T_GROUP_LOOKAHEAD_NEGATIVE,
                GroupType::T_GROUP_LOOKBEHIND_POSITIVE,
                GroupType::T_GROUP_LOOKBEHIND_NEGATIVE,
            ], true)) {
                return true;
            }

            return $this->nullableStatus($node->child);
        }

        if ($node instanceof QuantifierNode) {
            [$min] = $this->quantifierBounds($node->quantifier);
            if (0 === $min) {
                return true;
            }

            $childNullable = $this->nullableStatus($node->node);
            if (null === $childNullable) {
                return null;
            }

            return $childNullable;
        }

        if ($node instanceof SequenceNode) {
            $unknown = false;
            foreach ($node->children as $child) {
                $childNullable = $this->nullableStatus($child);
                if (false === $childNullable) {
                    return false;
                }
                if (null === $childNullable) {
                    $unknown = true;
                }
            }

            return $unknown ? null : true;
        }

        if ($node instanceof AlternationNode) {
            $unknown = false;
            foreach ($node->alternatives as $alt) {
                $altNullable = $this->nullableStatus($alt);
                if (true === $altNullable) {
                    return true;
                }
                if (null === $altNullable) {
                    $unknown = true;
                }
            }

            return $unknown ? null : false;
        }

        if ($node instanceof ConditionalNode) {
            $yes = $this->nullableStatus($node->yes);
            $no = $this->nullableStatus($node->no);

            if (true === $yes || true === $no) {
                return true;
            }
            if (false === $yes && false === $no) {
                return false;
            }

            return null;
        }

        return null;
    }

    /**
     * @return array{0:int, 1:int|null}
     */
    private function quantifierBounds(string $quantifier): array
    {
        $bounds = QuantifierBounds::parse($quantifier);

        return null === $bounds ? [0, null] : [$bounds->min, $bounds->max];
    }

    private function shouldFlagEmptyRepeat(NodeInterface $node, ?int $max): bool
    {
        if (!$this->quantifierAllowsMultiple($max)) {
            return false;
        }

        return true === $this->nullableStatus($node);
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
        if (true !== $this->nullableStatus($node)) {
            return false;
        }

        if ($node instanceof GroupNode) {
            return $this->hasAtomicNullableBranch($node->child);
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alt) {
                if (true === $this->nullableStatus($alt) && $this->isAtomicNullableNode($alt)) {
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
            [$min] = $this->quantifierBounds($node->quantifier);
            if (0 !== $min) {
                return false;
            }

            if (QuantifierType::T_POSSESSIVE === $node->type) {
                return true;
            }

            return $node->node instanceof GroupNode && GroupType::T_GROUP_ATOMIC === $node->node->type;
        }

        if ($node instanceof GroupNode && GroupType::T_GROUP_ATOMIC === $node->type) {
            return true === $this->nullableStatus($node->child);
        }

        return false;
    }

    private function quantifierAllowsMultiple(?int $max): bool
    {
        return null === $max || $max > 1;
    }

    private function emptyRepeatSeverity(bool $isUnbounded, ?int $max): ReDoSSeverity
    {
        if ($isUnbounded) {
            return ReDoSSeverity::HIGH;
        }

        if (null !== $max && $max <= 3) {
            return ReDoSSeverity::LOW;
        }

        return ReDoSSeverity::MEDIUM;
    }

    private function analyzeAdjacentQuantifiers(SequenceNode $node): ReDoSSeverity
    {
        $max = ReDoSSeverity::SAFE;
        $children = $node->children;
        $count = \count($children);

        for ($i = 0; $i < $count - 1; $i++) {
            $left = $children[$i];
            $right = $children[$i + 1];

            $leftQuantifier = $this->unwrapAdjacentQuantifier($left);
            $rightQuantifier = $this->unwrapAdjacentQuantifier($right);

            if (null === $leftQuantifier || null === $rightQuantifier) {
                continue;
            }

            if ($this->isQuantifierShielded($leftQuantifier) || $this->isQuantifierShielded($rightQuantifier)) {
                continue;
            }

            [, $leftMax] = $this->quantifierBounds($leftQuantifier->quantifier);
            [, $rightMax] = $this->quantifierBounds($rightQuantifier->quantifier);

            if (!$this->quantifierAllowsMultiple($leftMax) || !$this->quantifierAllowsMultiple($rightMax)) {
                continue;
            }

            $leftTail = $this->charSetAnalyzer->lastChars($leftQuantifier->node);
            $rightHead = $this->charSetAnalyzer->firstChars($rightQuantifier->node);
            $overlapKnown = !$leftTail->isUnknown() && !$rightHead->isUnknown();

            if ($overlapKnown && !$leftTail->intersects($rightHead)) {
                continue;
            }

            $leftUnbounded = $this->isUnbounded($leftQuantifier->quantifier);
            $rightUnbounded = $this->isUnbounded($rightQuantifier->quantifier);
            $leftLarge = $this->isLargeBounded($leftQuantifier->quantifier);
            $rightLarge = $this->isLargeBounded($rightQuantifier->quantifier);

            if (!$overlapKnown && !$leftUnbounded && !$rightUnbounded && !$leftLarge && !$rightLarge) {
                continue;
            }

            $severity = ($leftUnbounded || $rightUnbounded) ? ReDoSSeverity::MEDIUM : ReDoSSeverity::LOW;
            if ($this->unboundedQuantifierDepth > 0) {
                $severity = $this->maxSeverity($severity, ReDoSSeverity::HIGH);
            }

            $confidence = $overlapKnown ? ReDoSConfidence::MEDIUM : ReDoSConfidence::LOW;
            $falsePositiveRisk = $overlapKnown
                ? 'Medium false-positive risk; overlap is inferred from boundary character sets.'
                : 'High false-positive risk; overlap could not be determined precisely.';

            $adjacentSpan = new SequenceNode([$left, $right], $left->getStartPosition(), $right->getEndPosition());

            $this->addVulnerability(
                $severity,
                'Adjacent quantified tokens with overlapping character sets can cause ambiguous backtracking (e.g., a+a+ or a*a*).',
                $adjacentSpan,
                'Merge repetitions, add a delimiter, or make one quantifier possessive to remove ambiguity.',
                $confidence,
                $falsePositiveRisk,
                'adjacent quantifiers',
            );

            $max = $this->maxSeverity($max, $severity);
        }

        return $max;
    }

    private function unwrapAdjacentQuantifier(NodeInterface $node): ?QuantifierNode
    {
        if ($node instanceof GroupNode) {
            if (GroupType::T_GROUP_ATOMIC === $node->type) {
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
        return QuantifierType::T_POSSESSIVE === $node->type
            || $this->hasTrailingBacktrackingControl($node->node)
            || ($node->node instanceof GroupNode && GroupType::T_GROUP_ATOMIC === $node->node->type);
    }

    private function isCapturingGroup(GroupNode $group): bool
    {
        return \in_array($group->type, [
            GroupType::T_GROUP_CAPTURING,
            GroupType::T_GROUP_NAMED,
            GroupType::T_GROUP_BRANCH_RESET,
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
            return [$node->condition, $node->yes, $node->no];
        }

        return [];
    }
}
