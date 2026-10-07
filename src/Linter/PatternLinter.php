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

namespace PHPRegex\Linter;

use PHPRegex\Linter\Rule\GroupIndex;
use PHPRegex\Linter\Rule\LintContext;
use PHPRegex\Linter\Rule\LintRuleInterface;
use PHPRegex\Linter\Rule\LintRuleRegistry;
use PHPRegex\Linter\Rule\PatternInfo;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Linter\Rule\Support\NodePredicates;
use PHPRegex\Parser\AbstractNodeVisitor;
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Analysis\LengthRangeCalculator;
use PHPRegex\Parser\Internal\Ascii;
use PHPRegex\Parser\Internal\PatternParser;
use PHPRegex\Parser\Node;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ClassSetOperationNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Printer\PatternPrinter;

/**
 * Lints regex patterns for semantic issues like useless flags.
 *
 * @extends AbstractNodeVisitor<Node\NodeInterface>
 */
final class PatternLinter extends AbstractNodeVisitor
{
    /**
     * Rules that are disabled by default (can be enabled via config).
     */
    private const DEFAULT_DISABLED_RULES = [
        'unicode.shorthandWithoutU' => false,
        'charclass.single' => false,
        'literal.multipleSpaces' => false,
        'quantifier.lazyToClass' => false,
    ];

    /**
     * @var array<RuleViolation>
     */
    private array $issues = [];

    private string $flags = '';

    private string $delimiter = '';

    private ?string $patternValue = null;

    private int $maxCapturingGroup = 0;

    /**
     * @var array<string, bool>
     */
    private array $definedNamedGroups = [];

    /**
     * @var array<int, array{node: GroupNode, start: int, end: int, alternation: array<string, int>, alwaysEmpty: bool}>
     */
    private array $capturingGroups = [];

    /**
     * @var array<string, array<int, array{node: GroupNode, start: int, end: int, alternation: array<string, int>, alwaysEmpty: bool}>>
     */
    private array $capturingGroupsByName = [];

    private int $nextCapturingGroupNumber = 1;

    private bool $skipUselessBackref = false;

    /**
     * Groups numbered so far by the subroutine pre-pass, branch resets
     * included.
     */
    private int $openedGroups = 0;

    /**
     * @var array<int, GroupNode> the first group holding each number
     */
    private array $firstGroupByNumber = [];

    /**
     * @var array<string, GroupNode> the first group holding each name
     */
    private array $firstGroupByName = [];

    /**
     * @var list<int|string> the group each call targets, by number or by name
     */
    private array $subroutineCalls = [];

    /**
     * @var array<int, GroupNode>
     */
    private array $subroutineTargets = [];

    private bool $recurses = false;

    private CharSetAnalyzer $charSetAnalyzer;

    private bool $unicodeMode = false;

    private string $source = '';

    /**
     * Per-run context shared with the lint rules: immutable pattern facts
     * plus the mutable traversal cursor (parents, alternation branches,
     * active inline flags).
     */
    private LintContext $context;

    /**
     * @var list<LintRuleInterface>
     */
    private readonly array $rules;

    /**
     * @var array<class-string<NodeInterface>, list<LintRuleInterface>>
     */
    private array $dispatchMap = [];

    /**
     * @param array<string, bool>   $enabledRules
     * @param LintRuleRegistry|null $registry     @internal custom registries are not yet a public extension point
     */
    public function __construct(/**
     * Configuration for which lint rules are enabled.
     */
        private array $enabledRules = [],
        ?LintRuleRegistry $registry = null)
    {
        $this->charSetAnalyzer = new CharSetAnalyzer();
        $this->rules = ($registry ?? new LintRuleRegistry())->all();
        foreach ($this->rules as $rule) {
            foreach ($rule->getNodeTypes() as $nodeType) {
                $this->dispatchMap[$nodeType][] = $rule;
            }
        }
        $this->context = $this->createContext();
    }

    /**
     * Get the full regex pattern including delimiters and flags
     */
    public function getFullPattern(): string
    {
        $closingDelimiter = PatternParser::closingDelimiter($this->delimiter);

        return $this->delimiter.$this->patternValue.$closingDelimiter.$this->flags;
    }

    /**
     * @return array<string>
     */
    public function getWarnings(): array
    {
        return array_map(
            static fn (RuleViolation $issue): string => $issue->message,
            $this->issues,
        );
    }

    /**
     * @return array<RuleViolation>
     */
    public function getIssues(): array
    {
        return $this->issues;
    }

    #[\Override]
    public function visitRegex(RegexNode $node): NodeInterface
    {
        $this->flags = $node->flags;
        $this->delimiter = $node->delimiter;
        $this->unicodeMode = $node->isUnicode();
        $this->source = $node->source ?? '';
        $this->charSetAnalyzer = CharSetAnalyzer::forRegex($node);
        $this->issues = [];
        $this->maxCapturingGroup = 0;
        $this->definedNamedGroups = [];
        $this->capturingGroups = [];
        $this->capturingGroupsByName = [];
        $this->nextCapturingGroupNumber = 1;
        $this->skipUselessBackref = false;

        // Use a simple visitor to compile the pattern string for diagnostics
        $compiler = new PatternPrinter();
        $this->patternValue = $node->pattern->accept($compiler);

        $this->collectCapturingGroupInfo($node->pattern);
        $this->collectSubroutineTargets($node->pattern);

        // First pass: count capturing groups
        $this->countCapturingGroups($node->pattern);

        $this->context = $this->createContext();
        foreach ($this->rules as $rule) {
            $rule->begin($this->context);
        }

        // Second pass: traverse and lint
        $node->pattern->accept($this);

        foreach ($this->rules as $rule) {
            $this->append($rule->finish($this->context));
        }

        return $node;
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): NodeInterface
    {
        $this->dispatch($node);

        return $node;
    }

    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node): NodeInterface
    {
        $this->dispatch($node);
        $node->expression->accept($this);

        return $node;
    }

    #[\Override]
    public function visitClassSetOperation(ClassSetOperationNode $node): NodeInterface
    {
        $node->left?->accept($this);
        $node->right->accept($this);

        return $node;
    }

    #[\Override]
    public function visitCharClass(CharClassNode $node): NodeInterface
    {
        $this->dispatch($node);

        return $node;
    }

    #[\Override]
    public function visitDot(DotNode $node): NodeInterface
    {
        $this->dispatch($node);

        return $node;
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node): NodeInterface
    {
        $this->dispatch($node);

        return $node;
    }

    // Implement other visit methods as no-op
    #[\Override]
    public function visitAlternation(AlternationNode $node): NodeInterface
    {
        $this->dispatch($node);

        $this->context->pushParent($node);
        $altKey = $this->alternationKey($node);
        foreach ($node->alternatives as $index => $alt) {
            $this->context->pushAlternationBranch($altKey, $index);
            $alt->accept($this);
            $this->context->popAlternationBranch();
        }
        $this->context->popParent();

        return $node;
    }

    #[\Override]
    public function visitSequence(SequenceNode $node): NodeInterface
    {
        $this->dispatch($node);

        // A standalone (?s) changes the flags for the rest of the sequence
        // and for the alternatives after it; the group, conditional or
        // DEFINE holding them restores the flags at its end.
        $this->context->pushParent($node);
        foreach ($node->children as $child) {
            $child->accept($this);
        }
        $this->context->popParent();

        return $node;
    }

    #[\Override]
    public function visitScriptRun(ScriptRunNode $node): NodeInterface
    {
        $this->dispatch($node);

        if (null !== $node->content) {
            $previousFlags = $this->context->activeFlags();
            $this->context->pushParent($node);
            $node->content->accept($this);
            $this->context->popParent();
            $this->context->setActiveFlags($previousFlags);
        }

        return $node;
    }

    #[\Override]
    public function visitGroup(GroupNode $node): NodeInterface
    {
        $this->dispatch($node);
        $previousFlags = $this->context->activeFlags();
        $standalone = NodePredicates::isStandaloneInlineFlagsGroup($node);

        if (GroupType::InlineFlags === $node->type && null !== $node->flags && !$standalone) {
            $this->context->setActiveFlags(NodePredicates::applyInlineFlags($previousFlags, $node->flags));
        }

        $this->context->pushParent($node);
        $node->child->accept($this);
        $this->context->popParent();

        // A standalone (?s) holds for what follows it, up to the end of the
        // enclosing group; a group with content restores the flags.
        $this->context->setActiveFlags($standalone ? NodePredicates::applyInlineFlags($previousFlags, (string) $node->flags) : $previousFlags);

        return $node;
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): NodeInterface
    {
        $this->dispatch($node);

        return $node;
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): NodeInterface
    {
        $this->dispatch($node);

        return $node;
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): NodeInterface
    {
        $this->dispatch($node);

        $this->context->pushParent($node);
        $node->node->accept($this);
        $this->context->popParent();

        return $node;
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): NodeInterface
    {
        $this->dispatch($node);

        return $node;
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): NodeInterface
    {
        $this->dispatch($node);

        return $node;
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): NodeInterface
    {
        $this->dispatch($node);

        return $node;
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): NodeInterface
    {
        $this->dispatch($node);

        $previousFlags = $this->context->activeFlags();
        $this->context->pushParent($node);
        $node->condition->accept($this);
        $node->yes->accept($this);
        $node->no->accept($this);
        $this->context->popParent();
        $this->context->setActiveFlags($previousFlags);

        return $node;
    }

    #[\Override]
    public function visitDefine(DefineNode $node): NodeInterface
    {
        $this->dispatch($node);

        $previousFlags = $this->context->activeFlags();
        $this->context->pushParent($node);
        $node->content->accept($this);
        $this->context->popParent();
        $this->context->setActiveFlags($previousFlags);

        return $node;
    }

    private function createContext(): LintContext
    {
        return new LintContext(
            new PatternInfo(
                $this->flags,
                $this->delimiter,
                $this->patternValue ?? '',
                $this->unicodeMode,
                $this->source,
            ),
            new GroupIndex(
                $this->maxCapturingGroup,
                $this->definedNamedGroups,
                $this->capturingGroups,
                $this->capturingGroupsByName,
                $this->skipUselessBackref,
                $this->subroutineTargets,
                $this->recurses,
            ),
            $this->charSetAnalyzer,
        );
    }

    /**
     * Run every registered rule subscribed to this node's class.
     */
    private function dispatch(NodeInterface $node): void
    {
        foreach ($this->dispatchMap[$node::class] ?? [] as $rule) {
            $this->append($rule->check($node, $this->context));
        }
    }

    /**
     * Single append point: enablement filtering happens here, exactly as the
     * historical addIssue() did.
     *
     * @param list<RuleViolation> $issues
     */
    private function append(array $issues): void
    {
        foreach ($issues as $issue) {
            if ($this->isRuleEnabled($issue->id)) {
                $this->issues[] = $issue;
            }
        }
    }

    /**
     * Check if a lint rule is enabled.
     */
    private function isRuleEnabled(string $ruleId): bool
    {
        // Remove the 'regex.lint.' prefix if present for lookup
        $shortId = str_starts_with($ruleId, 'regex.lint.')
            ? substr($ruleId, \strlen('regex.lint.'))
            : $ruleId;

        // Explicit config takes precedence
        if (\array_key_exists($shortId, $this->enabledRules)) {
            return $this->enabledRules[$shortId];
        }

        // Check default disabled rules
        if (\array_key_exists($shortId, self::DEFAULT_DISABLED_RULES)) {
            return self::DEFAULT_DISABLED_RULES[$shortId];
        }

        // All other rules enabled by default
        return true;
    }

    private function countCapturingGroups(NodeInterface $node): void
    {
        if ($node instanceof GroupNode && (GroupType::Capturing === $node->type || GroupType::Named === $node->type)) {
            $this->maxCapturingGroup++;
            if (null !== $node->name) {
                $this->definedNamedGroups[$node->name] = true;
            }
        }

        // Recursively count in children
        if ($node instanceof GroupNode) {
            $this->countCapturingGroups($node->child);
        } elseif ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alt) {
                $this->countCapturingGroups($alt);
            }
        } elseif ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                $this->countCapturingGroups($child);
            }
        } elseif ($node instanceof QuantifierNode) {
            $this->countCapturingGroups($node->node);
        } elseif ($node instanceof ConditionalNode) {
            $this->countCapturingGroups($node->condition);
            $this->countCapturingGroups($node->yes);
            $this->countCapturingGroups($node->no);
        } elseif ($node instanceof DefineNode) {
            $this->countCapturingGroups($node->content);
        } elseif ($node instanceof CharClassNode) {
            $this->countCapturingGroups($node->expression);
        }
        // Other node types don't contain groups
    }

    /**
     * @param array<string, int> $alternation
     */
    private function collectCapturingGroupInfo(NodeInterface $node, array $alternation = []): void
    {
        if ($this->skipUselessBackref) {
            return;
        }

        if ($node instanceof GroupNode) {
            if (GroupType::BranchReset === $node->type) {
                $this->skipUselessBackref = true;

                return;
            }

            if (GroupType::Capturing === $node->type || GroupType::Named === $node->type) {
                $number = $this->nextCapturingGroupNumber++;
                $info = [
                    'node' => $node,
                    'start' => $node->getStartPosition(),
                    'end' => $node->getEndPosition(),
                    'alternation' => $alternation,
                    'alwaysEmpty' => $this->nodeIsAlwaysEmpty($node->child),
                ];

                $this->capturingGroups[$number] = $info;

                if (GroupType::Named === $node->type && null !== $node->name) {
                    $this->capturingGroupsByName[$node->name][] = $info;
                }
            }

            $this->collectCapturingGroupInfo($node->child, $alternation);

            return;
        }

        if ($node instanceof AlternationNode) {
            $key = $this->alternationKey($node);
            foreach ($node->alternatives as $index => $alt) {
                $nextAlternation = $alternation;
                $nextAlternation[$key] = $index;
                $this->collectCapturingGroupInfo($alt, $nextAlternation);
            }

            return;
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                $this->collectCapturingGroupInfo($child, $alternation);
            }

            return;
        }

        if ($node instanceof QuantifierNode) {
            $this->collectCapturingGroupInfo($node->node, $alternation);

            return;
        }

        if ($node instanceof ConditionalNode) {
            $this->collectCapturingGroupInfo($node->condition, $alternation);
            $this->collectCapturingGroupInfo($node->yes, $alternation);
            $this->collectCapturingGroupInfo($node->no, $alternation);

            return;
        }

        if ($node instanceof DefineNode) {
            $this->collectCapturingGroupInfo($node->content, $alternation);

            return;
        }

        if ($node instanceof CharClassNode) {
            $this->collectCapturingGroupInfo($node->expression, $alternation);

            return;
        }

        if ($node instanceof RangeNode) {
            $this->collectCapturingGroupInfo($node->start, $alternation);
            $this->collectCapturingGroupInfo($node->end, $alternation);
        }
    }

    /**
     * Collects the groups a subroutine call runs again, and whether the
     * pattern recurses into itself whole. A call by number reaches the first
     * group holding that number, which inside a branch reset is the one in
     * the earliest alternative; a call by name reaches the first group so
     * named.
     */
    private function collectSubroutineTargets(NodeInterface $pattern): void
    {
        $this->openedGroups = 0;
        $this->firstGroupByNumber = [];
        $this->firstGroupByName = [];
        $this->subroutineCalls = [];
        $this->subroutineTargets = [];
        $this->recurses = false;

        $this->collectSubroutineCalls($pattern);

        foreach ($this->subroutineCalls as $target) {
            if (0 === $target) {
                $this->recurses = true;

                continue;
            }

            $group = \is_int($target) ? ($this->firstGroupByNumber[$target] ?? null) : ($this->firstGroupByName[$target] ?? null);
            if (null !== $group) {
                $this->subroutineTargets[spl_object_id($group)] = $group;
            }
        }
    }

    private function collectSubroutineCalls(NodeInterface $node): void
    {
        if ($node instanceof SubroutineNode) {
            $target = $this->subroutineTarget($node);
            if (null !== $target) {
                $this->subroutineCalls[] = $target;
            }

            return;
        }

        if ($node instanceof ConditionalNode) {
            // A recursion test such as (?(R1)...) reads where the match
            // stands; it calls nothing.
            if (!$node->condition instanceof SubroutineNode) {
                $this->collectSubroutineCalls($node->condition);
            }
            $this->collectSubroutineCalls($node->yes);
            $this->collectSubroutineCalls($node->no);

            return;
        }

        if ($node instanceof GroupNode && (GroupType::Capturing === $node->type || GroupType::Named === $node->type)) {
            $this->firstGroupByNumber[++$this->openedGroups] ??= $node;
            if (null !== $node->name) {
                $this->firstGroupByName[$node->name] ??= $node;
            }
        }

        if ($node instanceof GroupNode && GroupType::BranchReset === $node->type) {
            // Each alternative numbers its groups from the same start; the
            // count after the reset is the highest any alternative reached.
            $start = $this->openedGroups;
            $end = $start;
            $alternatives = $node->child instanceof AlternationNode ? $node->child->alternatives : [$node->child];
            foreach ($alternatives as $alternative) {
                $this->openedGroups = $start;
                $this->collectSubroutineCalls($alternative);
                $end = max($end, $this->openedGroups);
            }
            $this->openedGroups = $end;

            return;
        }

        foreach ($node->getChildren() as $child) {
            $this->collectSubroutineCalls($child);
        }
    }

    /**
     * The group a call targets: a number (0 for the whole pattern), relative
     * numbers resolved against the groups opened before the call, or a name;
     * null for a relative call that reaches before the first group, which
     * PCRE refuses.
     */
    private function subroutineTarget(SubroutineNode $node): int|string|null
    {
        $reference = $node->reference;
        if ('R' === $reference) {
            return 0;
        }

        $sign = $reference[0] ?? '';
        $relative = '+' === $sign || '-' === $sign;
        if (!Ascii::isDigit($relative ? substr($reference, 1) : $reference)) {
            return $reference;
        }

        $number = (int) $reference;
        if ($relative) {
            $number += $this->openedGroups + ('-' === $sign ? 1 : 0);

            return $number > 0 ? $number : null;
        }

        return $number;
    }

    private function nodeIsAlwaysEmpty(NodeInterface $node): bool
    {
        [$min, $max] = $node->accept(new LengthRangeCalculator());

        return 0 === $min && 0 === $max;
    }

    private function alternationKey(AlternationNode $node): string
    {
        return (string) spl_object_id($node);
    }

    // Add other visit methods as needed, default to no-op
}
