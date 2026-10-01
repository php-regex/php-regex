<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Redos;

use PhpRegex\Parser\AbstractNodeVisitor;
use PhpRegex\Parser\Analysis\CharSetAnalyzer;
use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\AnchorNode;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\Node\CalloutNode;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\ClassSetOperationNode;
use PhpRegex\Parser\Node\CommentNode;
use PhpRegex\Parser\Node\ConditionalNode;
use PhpRegex\Parser\Node\ControlCharNode;
use PhpRegex\Parser\Node\DefineNode;
use PhpRegex\Parser\Node\DotNode;
use PhpRegex\Parser\Node\ExtendedCharClassNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\KeepNode;
use PhpRegex\Parser\Node\LimitMatchNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\Node\PcreVerbNode;
use PhpRegex\Parser\Node\PosixClassNode;
use PhpRegex\Parser\Node\QuantifierBounds;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\QuantifierType;
use PhpRegex\Parser\Node\RangeNode;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Parser\Node\ScriptRunNode;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\Node\SubroutineNode;
use PhpRegex\Parser\Node\UnicodePropNode;
use PhpRegex\Parser\Node\VersionConditionNode;
use PhpRegex\Parser\Printer\PatternPrinter;

/**
 * Analyzes the AST to detect ReDoS vulnerabilities.
 *
 * @extends AbstractNodeVisitor<RedosSeverity>
 */
final class RedosProfiler extends AbstractNodeVisitor
{
    private int $unboundedQuantifierDepth = 0;

    /**
     * Tracks total nesting of quantifiers (bounded or not) to detect LOW risks.
     */
    private int $totalQuantifierDepth = 0;

    /**
     * Stores all detected ReDoS vulnerabilities during the AST traversal.
     *
     * @var array<\PhpRegex\Redos\Finding>
     */
    private array $vulnerabilities = [];

    /**
     * @var array<\PhpRegex\Redos\Hotspot>
     */
    private array $hotspots = [];

    private bool $inAtomicGroup = false;

    private ?NodeInterface $previousNode = null;

    private ?NodeInterface $nextNode = null;

    private bool $backrefLoopDetected = false;

    private ?NodeInterface $culpritNode = null;

    private RedosSeverity $culpritSeverity = RedosSeverity::Safe;

    public function __construct(private readonly CharSetAnalyzer $charSetAnalyzer = new CharSetAnalyzer()) {}

    /**
     * @return array{severity: \PhpRegex\Redos\RedosSeverity, recommendations: array<string>, vulnerablePattern: ?string, trigger: ?string, confidence: ?\PhpRegex\Redos\RedosConfidence, falsePositiveRisk: ?string, suggestedRewrite: ?string, findings: array<\PhpRegex\Redos\Finding>}
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
     * @return array<\PhpRegex\Redos\Hotspot>
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
        $this->vulnerabilities = [];
        $this->hotspots = [];
        $this->inAtomicGroup = false;
        $this->previousNode = null;
        $this->nextNode = null;
        $this->backrefLoopDetected = false;
        $this->culpritNode = null;
        $this->culpritSeverity = RedosSeverity::Safe;

        return $node->pattern->accept($this);
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): RedosSeverity
    {
        // Save the current atomic state to restore it later
        $wasAtomic = $this->inAtomicGroup;
        $boundarySeparatedPrev = $this->hasMutuallyExclusiveBoundary($this->previousNode, $node->node);
        $boundarySeparatedNext = $this->hasForwardMutuallyExclusiveBoundary($node->node, $this->nextNode);
        $boundarySeparated = $boundarySeparatedPrev || $boundarySeparatedNext;

        $controlVerbShield = $this->hasTrailingBacktrackingControl($node->node);
        $isPossessive = QuantifierType::Possessive === $node->type;

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

            return $this->reduceSeverity($result, RedosSeverity::Low);
        }

        // --- Standard ReDoS logic for non-atomic quantifiers ---

        $this->totalQuantifierDepth++;
        [, $qMax] = $this->quantifierBounds($node->quantifier);
        $isUnbounded = $this->isUnbounded($node->quantifier);

        // Check if the immediate target is an atomic group (e.g., (? >...)+)
        $isTargetAtomic = $node->node instanceof GroupNode && GroupType::Atomic === $node->node->type;

        $severity = RedosSeverity::Safe;
        $entersUnbounded = $isUnbounded && !$isTargetAtomic;
        $isNestedUnbounded = $entersUnbounded && $this->unboundedQuantifierDepth > 0;

        if ($entersUnbounded) {
            $this->unboundedQuantifierDepth++;

            if ($this->hasBackrefLoop($node->node)) {
                $this->backrefLoopDetected = true;
                $severity = RedosSeverity::Critical;
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
                $severity = $boundarySeparated ? RedosSeverity::Low : ($hasRecursion ? RedosSeverity::Medium : RedosSeverity::Critical);
                if (!$boundarySeparated) {
                    $vulnSeverity = $hasRecursion ? RedosSeverity::Medium : RedosSeverity::Critical;
                    $this->addVulnerability(
                        $vulnSeverity,
                        'Nested unbounded quantifiers detected. This allows exponential backtracking. Consider using atomic groups (?>...) or possessive quantifiers (*+, ++).',
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
            if ($this->isLargeBounded($node->quantifier)) {
                $severity = RedosSeverity::Low;
                $this->addVulnerability(
                    RedosSeverity::Low,
                    'Large bounded quantifier detected (>1000). May cause slow matching. Consider reducing the upper bound.',
                    $node,
                    'Reduce the upper bound or pre-validate input length.',
                    RedosConfidence::Low,
                    'High false-positive risk; bounded quantifiers may still be safe in context.',
                );
            } elseif ($this->totalQuantifierDepth > 1 && 0 === $this->unboundedQuantifierDepth) {
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

        if ($entersUnbounded) {
            $this->unboundedQuantifierDepth--;
        }
        $this->totalQuantifierDepth--;

        // Restore state (just in case, though the early return handles the true case)
        $this->inAtomicGroup = $wasAtomic;

        return $this->maxSeverity($severity, $childSeverity);
    }

    #[\Override]
    public function visitAlternation(AlternationNode $node): RedosSeverity
    {
        $max = RedosSeverity::Safe;
        $previous = $this->previousNode;
        $next = $this->nextNode;

        if ($this->unboundedQuantifierDepth > 0 && $this->hasOverlappingAlternatives($node)) {
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
    public function visitGroup(GroupNode $node): RedosSeverity
    {
        $wasAtomic = $this->inAtomicGroup;
        $previous = $this->previousNode;
        $next = $this->nextNode;
        $isAtomicGroup = GroupType::Atomic === $node->type;
        if ($isAtomicGroup) {
            $this->inAtomicGroup = true;
        }

        $this->previousNode = null;
        $this->nextNode = null;
        $severity = $node->child->accept($this);
        $this->previousNode = $previous;
        $this->nextNode = $next;

        $this->inAtomicGroup = $wasAtomic;

        return $isAtomicGroup ? $this->reduceSeverity($severity, RedosSeverity::Low) : $severity;
    }

    #[\Override]
    public function visitSequence(SequenceNode $node): RedosSeverity
    {
        $max = RedosSeverity::Safe;
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

        if (!$node->atomic) {
            return $node->content->accept($this);
        }

        // The body of "(*asr:...)" is atomic, as in an atomic group.
        $wasAtomic = $this->inAtomicGroup;
        $this->inAtomicGroup = true;
        $severity = $node->content->accept($this);
        $this->inAtomicGroup = $wasAtomic;

        return $this->reduceSeverity($severity, RedosSeverity::Low);
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

        return RedosSeverity::Low;
    }

    #[\Override]
    public function visitDefine(DefineNode $node): RedosSeverity
    {
        // Analyze the content of the DEFINE block for ReDoS vulnerabilities
        return $node->content->accept($this);
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

    private function reduceSeverity(RedosSeverity $severity, RedosSeverity $cap): RedosSeverity
    {
        return $this->severityGreaterThan($severity, $cap) ? $cap : $severity;
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

        if ($this->severityGreaterThan($severity, $this->culpritSeverity)) {
            $this->culpritSeverity = $severity;
            $this->culpritNode = $triggerNode;
        }
    }

    private function compileNode(NodeInterface $node): string
    {
        return $node->accept(new PatternPrinter());
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
     * Compares two RedosSeverity values.
     */
    private function severityGreaterThan(RedosSeverity $a, RedosSeverity $b): bool
    {
        $levels = [
            RedosSeverity::Safe->value => 0,
            RedosSeverity::Low->value => 1,
            RedosSeverity::Unknown->value => 2,
            RedosSeverity::Medium->value => 3,
            RedosSeverity::High->value => 4,
            RedosSeverity::Critical->value => 5,
        ];

        return $levels[$a->value] > $levels[$b->value];
    }

    /**
     * Returns the higher of two RedosSeverity values.
     */
    private function maxSeverity(RedosSeverity $a, RedosSeverity $b): RedosSeverity
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
                GroupType::LookaheadPositive,
                GroupType::LookaheadNegative,
                GroupType::LookbehindPositive,
                GroupType::LookbehindNegative,
                GroupType::ScanSubstring,
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

            if (QuantifierType::Possessive === $node->type) {
                return true;
            }

            return $node->node instanceof GroupNode && GroupType::Atomic === $node->node->type;
        }

        if ($node instanceof GroupNode && GroupType::Atomic === $node->type) {
            return true === $this->nullableStatus($node->child);
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

    private function analyzeAdjacentQuantifiers(SequenceNode $node): RedosSeverity
    {
        $max = RedosSeverity::Safe;
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

            $severity = ($leftUnbounded || $rightUnbounded) ? RedosSeverity::Medium : RedosSeverity::Low;
            if ($this->unboundedQuantifierDepth > 0) {
                $severity = $this->maxSeverity($severity, RedosSeverity::High);
            }

            $confidence = $overlapKnown ? RedosConfidence::Medium : RedosConfidence::Low;
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
     * @return array<\PhpRegex\Parser\Node\NodeInterface>
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
