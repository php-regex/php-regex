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

use PHPRegex\Automata\Solver\DfaCacheInterface;
use PHPRegex\Linter\Exception\UnknownLintRuleException;
use PHPRegex\Linter\Rule\GroupIndex;
use PHPRegex\Linter\Rule\LintContext;
use PHPRegex\Linter\Rule\LintRuleInterface;
use PHPRegex\Linter\Rule\LintRuleRegistry;
use PHPRegex\Linter\Rule\PatternInfo;
use PHPRegex\Linter\Rule\RuleCatalog;
use PHPRegex\Linter\Rule\RuleId;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Linter\Rule\Support\FlagScope;
use PHPRegex\Parser\AbstractNodeVisitor;
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Analysis\GroupIndex as PcreGroups;
use PHPRegex\Parser\Analysis\LengthRangeCalculator;
use PHPRegex\Parser\Node;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ClassSetOperationNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Printer\PatternPrinter;

/**
 * Lints regex patterns for semantic issues like useless flags.
 *
 * @phpstan-import-type CapturingGroupInfo from GroupIndex
 *
 * @extends AbstractNodeVisitor<Node\NodeInterface>
 */
final class PatternLinter extends AbstractNodeVisitor
{
    /**
     * @var list<RuleViolation>
     */
    private array $issues = [];

    private string $flags = '';

    private string $delimiter = '';

    private ?string $patternValue = null;

    /**
     * The capturing groups numbered as PCRE numbers them, and the calls.
     */
    private PcreGroups $groups;

    /**
     * @var array<int, CapturingGroupInfo>
     */
    private array $capturingGroups = [];

    /**
     * @var array<string, array<int, CapturingGroupInfo>>
     */
    private array $capturingGroupsByName = [];

    private bool $skipUselessBackref = false;

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
     * @var list<LintRuleInterface<NodeInterface>>
     */
    private array $rules = [];

    /**
     * @var array<class-string<NodeInterface>, list<LintRuleInterface<NodeInterface>>>
     */
    private array $dispatchMap = [];

    /**
     * Where the rules' DFAs are kept: the process's cache when none is given.
     */
    private ?DfaCacheInterface $dfaCache = null;

    private ?PcreTarget $target = null;

    /**
     * The rules to turn on or off by id, short or in full.
     *
     * @var array<string, bool>
     */
    private array $enabledRules;

    /**
     * @param array<string, bool> $enabledRules the rules to turn on or off by id, as regex.json's
     *                                          checks.lint.rules names them, without "regex.lint." or
     *                                          in full; the others keep their default
     *
     * @throws UnknownLintRuleException when the map names a rule the linter does not run
     */
    public function __construct(array $enabledRules = [])
    {
        $this->enabledRules = self::known($enabledRules);
        $this->charSetAnalyzer = new CharSetAnalyzer();
        $this->groups = PcreGroups::empty();
        $this->useRules(new LintRuleRegistry());
        $this->context = $this->createContext();
    }

    /**
     * A linter running the rules of a registry other than the library's.
     *
     * @internal custom rule registries are not a public extension point
     *
     * @param array<string, bool> $enabledRules
     */
    public static function withRegistry(array $enabledRules, LintRuleRegistry $registry): self
    {
        $linter = new self();
        $linter->enabledRules = self::known($enabledRules, $registry);
        $linter->useRules($registry);

        return $linter;
    }

    /**
     * A linter keeping the DFAs its rules' questions build in the cache
     * given, for a run that judges many patterns, and reading those
     * questions as the PHP and PCRE2 judged read them.
     *
     * @internal the DFA cache and the target of a run are the analysis service's business
     *
     * @param array<string, bool> $enabledRules
     */
    public static function withDfaCache(array $enabledRules, DfaCacheInterface $dfaCache, ?PcreTarget $target = null): self
    {
        $linter = new self($enabledRules);
        $linter->dfaCache = $dfaCache;
        $linter->target = $target;

        return $linter;
    }

    /**
     * @return list<RuleViolation>
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
        $this->capturingGroups = [];
        $this->capturingGroupsByName = [];
        $this->skipUselessBackref = false;

        $this->patternValue = $node->pattern->accept(PatternPrinter::asWritten());

        $this->groups = PcreGroups::of($node->pattern);
        $this->collectCapturingGroupInfo($node->pattern);

        $this->context = $this->createContext();
        foreach ($this->rules as $rule) {
            $rule->begin($this->context);
        }

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
    public function visitComment(CommentNode $node): NodeInterface
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
        $this->dispatch($node);
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
        // and for the alternatives after it; the group, conditional, DEFINE
        // or script run holding them restores the flags at its end.
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
        $this->visitScope($node, $this->context->activeFlags());

        return $node;
    }

    #[\Override]
    public function visitGroup(GroupNode $node): NodeInterface
    {
        $this->dispatch($node);
        $this->visitScope($node, FlagScope::flagsInside($node, $this->context->activeFlags()));

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
        $this->visitScope($node, $this->context->activeFlags());

        return $node;
    }

    #[\Override]
    public function visitDefine(DefineNode $node): NodeInterface
    {
        $this->dispatch($node);
        $this->visitScope($node, $this->context->activeFlags());

        return $node;
    }

    /**
     * The rules map, each identifier of which a rules map may name or a
     * rule of the registry reports, short or in full.
     *
     * @param array<string, bool> $enabledRules
     *
     * @throws UnknownLintRuleException
     *
     * @return array<string, bool>
     */
    private static function known(array $enabledRules, ?LintRuleRegistry $registry = null): array
    {
        $reported = [];
        foreach ($registry?->all() ?? [] as $rule) {
            array_push($reported, ...$rule->getRuleIds());
        }

        foreach (array_keys($enabledRules) as $id) {
            $id = (string) $id;
            if (!RuleCatalog::isConfigurable($id) && !\in_array(RuleId::full($id), $reported, true)) {
                throw new UnknownLintRuleException($id, RuleCatalog::suggestion($id));
            }
        }

        return $enabledRules;
    }

    /**
     * Visit the children of a group, a conditional, a DEFINE or a script
     * run, the first reading $inside: a standalone "(?s)" among them holds
     * for the ones after it, up to the end of the node, where the flags
     * before it come back. A standalone "(?s)" itself sets them after it.
     */
    private function visitScope(GroupNode|ConditionalNode|DefineNode|ScriptRunNode $node, string $inside): void
    {
        $before = $this->context->activeFlags();
        $this->context->setActiveFlags($inside);
        $this->context->pushParent($node);
        foreach ($node->getChildren() as $child) {
            $child->accept($this);
        }
        $this->context->popParent();
        $this->context->setActiveFlags(FlagScope::flagsAfter($node, $before));
    }

    private function createContext(): LintContext
    {
        $numbering = $this->groups->numbering();

        return new LintContext(
            new PatternInfo(
                $this->flags,
                $this->delimiter,
                $this->patternValue ?? '',
                $this->unicodeMode,
                $this->source,
            ),
            new GroupIndex(
                $numbering->maxGroupNumber,
                array_fill_keys(array_keys($numbering->namedGroups), true),
                $this->capturingGroups,
                $this->capturingGroupsByName,
                $this->skipUselessBackref,
                $this->groups->subroutineTargets(),
                $this->groups->recursesWhole(),
                $this->groups,
            ),
            $this->charSetAnalyzer,
            $this->isRuleEnabled(...),
            $this->dfaCache,
            $this->target,
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
     * Keep the findings of the rules the configuration turns on: a rule
     * that reports under several identifiers runs when one of them is on.
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
     * Whether the configuration turns the rule on.
     */
    private function isRuleEnabled(string $ruleId): bool
    {
        return RuleCatalog::enables($this->enabledRules, $ruleId);
    }

    /**
     * Each capturing group with where it stands: the branch it takes of
     * each alternation around it, and whether it always captures nothing.
     *
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
                $info = [
                    'node' => $node,
                    'start' => $node->getStartPosition(),
                    'end' => $node->getEndPosition(),
                    'alternation' => $alternation,
                    'alwaysEmpty' => $this->nodeIsAlwaysEmpty($node->child),
                ];

                // Every capturing group of the tree has its number.
                $number = $this->groups->numberOf($node);
                if (null !== $number) {
                    $this->capturingGroups[$number] = $info;
                }

                if (GroupType::Named === $node->type && null !== $node->name) {
                    $this->capturingGroupsByName[$node->name][] = $info;
                }
            }
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

        foreach ($node->getChildren() as $child) {
            $this->collectCapturingGroupInfo($child, $alternation);
        }
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

    private function useRules(LintRuleRegistry $registry): void
    {
        // A rule whose every identifier is off would only find what
        // append() drops: it is not run. No rule leaves anything in the
        // context for another one to read.
        $this->rules = array_values(array_filter(
            $registry->all(),
            fn (LintRuleInterface $rule): bool => [] !== array_filter($rule->getRuleIds(), $this->isRuleEnabled(...)),
        ));
        $this->dispatchMap = [];
        foreach ($this->rules as $rule) {
            foreach ($rule->getNodeTypes() as $nodeType) {
                $this->dispatchMap[$nodeType][] = $rule;
            }
        }
    }
}
