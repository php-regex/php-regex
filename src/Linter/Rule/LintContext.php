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

namespace PHPRegex\Linter\Rule;

use PHPRegex\Automata\Solver\DfaCacheInterface;
use PHPRegex\Linter\Rule\Support\Continuation;
use PHPRegex\Linter\Rule\Support\FlagScope;
use PHPRegex\Linter\Rule\Support\LanguageQuestions;
use PHPRegex\Linter\Rule\Support\NodePredicates;
use PHPRegex\Linter\Rule\Support\QuantifierMath;
use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\StartOptions;

/**
 * Per-run lint context: immutable pattern facts plus the mutable traversal
 * cursor (parent stack, alternation branches, active inline flags).
 *
 * Rules read from this context; only the traversal engine mutates it via the
 * {@internal}-tagged mutators.
 *
 * @internal
 */
final class LintContext
{
    /**
     * The DFAs the automata build for the rules' questions, kept for the
     * run: the process's cache unless the run brings its own.
     */
    public readonly DfaCacheInterface $dfas;

    /**
     * The PHP and PCRE2 the pattern is judged for: the rules' questions are
     * read as that release reads them.
     */
    public readonly PcreTarget $target;

    /**
     * @var list<NodeInterface>
     */
    private array $parentStack = [];

    /**
     * @var list<array{id: string, index: int}>
     */
    private array $alternationStack = [];

    private string $activeFlags;

    /**
     * What can be matched after the nodes of the pattern.
     */
    private readonly Continuation $continuation;

    /**
     * The pattern's analyzer reading every byte under the tables of the
     * target's locale, built at its first use.
     */
    private ?CharSetAnalyzer $everyByte = null;

    /**
     * Whether the rules compare the sets of two nodes every byte, read once.
     */
    private ?bool $comparesEveryByte = null;

    /**
     * @param (\Closure(string): bool)|null $ruleEnabled whether the configuration turns a rule on, by its
     *                                                   id; every rule when none is given
     */
    public function __construct(
        public readonly PatternInfo $pattern,
        public readonly GroupIndex $groups,
        public readonly CharSetAnalyzer $charSetAnalyzer,
        private readonly ?\Closure $ruleEnabled = null,
        ?DfaCacheInterface $dfas = null,
        ?PcreTarget $target = null,
    ) {
        // The restriction (*CASELESS_RESTRICT) sets is in force as r is,
        // until an option setting lifts it. The modifiers count once each:
        // "/xx" is "x", which an option setting alone turns into "xx".
        $this->activeFlags = $charSetAnalyzer->flagsAtTheStart(NodePredicates::modifierFlags($pattern->flags));
        $this->continuation = new Continuation($groups);
        $this->dfas = $dfas ?? LanguageQuestions::dfas();
        $this->target = $target ?? PcreTarget::runtime();
    }

    /**
     * Whether the configuration turns the rule on, so that a rule deferring
     * to it knows its finding will be reported.
     */
    public function isRuleEnabled(string $ruleId): bool
    {
        return null === $this->ruleEnabled || ($this->ruleEnabled)($ruleId);
    }

    /**
     * Flags in effect at the current traversal position, including inline
     * flag groups such as (?i) and (?-i:...).
     */
    public function activeFlags(): string
    {
        return $this->activeFlags;
    }

    /**
     * The characters the node can start with, each part of it read under
     * the flags in effect there: $flags at its start (the flags at the
     * current traversal position by default), then the inline flags inside
     * it, as the "(?s)" in "(?:(?s).a|\na)" or the one an earlier
     * alternative carries into "\na(?s)|.a".
     */
    public function firstChars(NodeInterface $node, ?string $flags = null): ByteCharSet
    {
        return $this->charsInForce($node, $flags ?? $this->activeFlags, true);
    }

    /**
     * The characters the node can end with, read as firstChars() reads
     * them.
     */
    public function lastChars(NodeInterface $node, ?string $flags = null): ByteCharSet
    {
        return $this->charsInForce($node, $flags ?? $this->activeFlags, false);
    }

    /**
     * Whether the rules read the characters of the pattern every byte,
     * under the tables of the target's locale, where firstChars() reads
     * them in ASCII alone, under PCRE2's own: under another locale than
     * "C", whose tables give the other case of a letter, ASCII's included,
     * and the bytes of "\d", "\s", "\w" and the POSIX classes above ASCII
     * as its C library says, and without UTF, where a byte is a character.
     * A set of bytes then holds every character the node takes; one the
     * tables cannot tell is not known.
     */
    public function readsEveryByte(): bool
    {
        return CharacterTables::BUILT_IN !== $this->target->locale && !$this->pattern->unicodeMode;
    }

    /**
     * Whether the rules that compare the characters of two nodes, a loop
     * and what follows it or two runs, read them every byte: where the
     * rules read every byte, unless the pattern reads a character
     * caselessly where the case is not settled. Where the engine's paths
     * read the case of a byte otherwise, as macOS's tr_TR.ISO8859-9 reads
     * "I", "i" and 0xDD, every caseless set holding it, a caseless "[a-z]"
     * among them, would not be known; Turkish casing reads "i" and "I"
     * apart from the tables. The rules keep the ASCII read there.
     */
    public function comparesEveryByte(): bool
    {
        return $this->comparesEveryByte ??= $this->readsEveryByte()
            && (!$this->readsCaselessly()
                || (!str_contains(StartOptions::of($this->pattern->source), '(*TURKISH_CASING)') && CharacterTables::forLocale($this->target->locale)->settlesEveryCase()));
    }

    /**
     * The characters the node can start with, read as firstChars() reads
     * them, every byte under the tables of the target's locale.
     */
    public function firstBytes(NodeInterface $node, ?string $flags = null): ByteCharSet
    {
        return $this->charsInForce($node, $flags ?? $this->activeFlags, true, true);
    }

    /**
     * The characters the node can end with, read as firstBytes() reads
     * them.
     */
    public function lastBytes(NodeInterface $node, ?string $flags = null): ByteCharSet
    {
        return $this->charsInForce($node, $flags ?? $this->activeFlags, false, true);
    }

    /**
     * The pattern's analyzer reading every byte under the tables of the
     * target's locale, under the flags the pattern opens with.
     */
    public function everyByteAnalyzer(): CharSetAnalyzer
    {
        return $this->everyByte ??= new CharSetAnalyzer(
            $this->pattern->flags,
            StartOptions::of($this->pattern->source),
            true,
            locale: $this->target->locale,
            ucpFoldsPastAscii: $this->target->supports(PcreFeature::UcpFoldsPastAsciiWithoutUtf),
        );
    }

    /**
     * The flags in effect at a node inside $within, whose start reads
     * $flags (the flags at the current traversal position by default).
     */
    public function flagsAt(NodeInterface $node, NodeInterface $within, ?string $flags = null): string
    {
        $flags ??= $this->activeFlags;

        return FlagScope::flagsAt($node, $within, $flags) ?? $flags;
    }

    /**
     * The flags in effect at each item of a sequence, or at each
     * alternative of an alternation, whose start reads $flags (the flags at
     * the current traversal position by default): a standalone (?s) holds
     * for the items after it and for the alternatives after its own.
     *
     * @return array<string> the flags, keyed as the children are
     */
    public function flagsAtEachChild(SequenceNode|AlternationNode $node, ?string $flags = null): array
    {
        return FlagScope::flagsAtEachChild($node, $flags ?? $this->activeFlags);
    }

    /**
     * @return list<NodeInterface>
     */
    public function parents(): array
    {
        return $this->parentStack;
    }

    /**
     * Check if the current node is inside an unbounded quantifier (*, +, {n,}).
     * Used to determine if overlapping alternations pose a ReDoS risk.
     *
     * The search stops at an atomic lookaround, an atomic group or a
     * possessive quantifier: once it holds, the engine never comes back
     * into it, so a loop around it cannot retry its branches. A loop that
     * is the tail of such a group is not retried either.
     */
    public function isInsideUnboundedQuantifier(): bool
    {
        $parents = array_reverse($this->parentStack);
        foreach ($parents as $index => $parent) {
            if ($parent instanceof GroupNode && (self::isAtomicLookaround($parent) || GroupType::Atomic === $parent->type)) {
                return false;
            }

            if ($parent instanceof QuantifierNode && QuantifierType::Possessive === $parent->type) {
                return false;
            }

            if ($parent instanceof QuantifierNode && QuantifierMath::isUnbounded($parent)) {
                return !$this->isAtomicTail($parent, \array_slice($parents, $index + 1));
            }
        }

        return false;
    }

    /**
     * Whether the node is the tail of an atomic group or of the body of a
     * possessive quantifier taken at most once before its other iterations,
     * looking only through sequences it ends, alternatives and groups that
     * do not change what is retried. Once such a group has matched, the
     * engine never comes back into it, and nothing after the node inside it
     * can fail and send the engine back into the node: "(?>(?:a+)+)" never
     * retries its loop, "(?>(?:a+)+b)" does, when "b" fails.
     */
    public function isTheTailOfAnAtomicGroup(NodeInterface $node): bool
    {
        return $this->isAtomicTail($node, array_reverse($this->parentStack));
    }

    /**
     * Whether nothing can be matched after the node wherever it runs: where
     * it is written, nothing follows it, and no subroutine call runs it
     * again at another point of the match. With $endAnchorsMayFollow, "$",
     * "\z" and "\Z" may follow it as well.
     */
    public function endsThePatternForEveryCall(NodeInterface $node, bool $endAnchorsMayFollow = false): bool
    {
        return $this->continuation->endsThePatternForEveryCall($node, $this->parentStack, $endAnchorsMayFollow);
    }

    /**
     * Whether only items that may match nothing, and hold nothing that can
     * fail, can follow the node wherever it runs: "b*" or "(?:b|)", never
     * an anchor, a lookaround, a verb or a reference.
     */
    public function onlyEmptyMatchesFollowForEveryCall(NodeInterface $node): bool
    {
        return $this->continuation->onlyEmptyMatchesFollowForEveryCall($node, $this->parentStack);
    }

    /**
     * Whether a subroutine call can run the node again at another point of
     * the match: the node is, or sits inside, a group some call targets, or
     * the pattern recurses into itself whole.
     */
    public function isReenteredBySubroutine(NodeInterface $node): bool
    {
        return $this->continuation->isReenteredBySubroutine($node, $this->parentStack);
    }

    /**
     * Whether only comments are written after the item in the sequence.
     */
    public function onlyCommentsFollowIn(SequenceNode $sequence, NodeInterface $item): bool
    {
        return $this->continuation->onlyCommentsFollowIn($sequence, $item);
    }

    /**
     * Whether the items written after the node in each sequence around it
     * pass the test, the innermost sequence first.
     *
     * @param \Closure(list<NodeInterface>): bool $test
     */
    public function followersPass(NodeInterface $node, \Closure $test): bool
    {
        return $this->continuation->followersPass($node, $this->parentStack, $test);
    }

    /**
     * @return array<string, int>
     */
    public function currentAlternationSignature(): array
    {
        $signature = [];
        foreach ($this->alternationStack as $entry) {
            $signature[$entry['id']] = $entry['index'];
        }

        return $signature;
    }

    /**
     * @internal engine-only mutator
     */
    public function setActiveFlags(string $flags): void
    {
        $this->activeFlags = $flags;
    }

    /**
     * @internal engine-only mutator
     */
    public function pushParent(NodeInterface $node): void
    {
        $this->parentStack[] = $node;
    }

    /**
     * @internal engine-only mutator
     */
    public function popParent(): void
    {
        array_pop($this->parentStack);
    }

    /**
     * @internal engine-only mutator
     */
    public function pushAlternationBranch(string $id, int $index): void
    {
        $this->alternationStack[] = ['id' => $id, 'index' => $index];
    }

    /**
     * @internal engine-only mutator
     */
    public function popAlternationBranch(): void
    {
        array_pop($this->alternationStack);
    }

    /**
     * Whether the pattern may read a character caselessly: under i, or
     * after an option setting that may turn it on, "(?i)" or "(?mi:", read
     * from the pattern as written, a "(?i" a class or an escape holds
     * included.
     */
    private function readsCaselessly(): bool
    {
        return $this->pattern->hasFlag('i') || 1 === LibraryPcre::match('/\(\?\^?[a-zA-Z]*i/', $this->pattern->source);
    }

    /**
     * A group a subroutine call runs, between the node and the atomic group,
     * runs the node without the group around it: "(?1)" in
     * "(?>x((?:a+)+))|y(?1)$" retries the loop when "$" fails.
     *
     * @param list<NodeInterface> $parents the parents of the node, the closest first
     */
    private function isAtomicTail(NodeInterface $node, array $parents): bool
    {
        $child = $node;
        foreach ($parents as $parent) {
            if ($parent instanceof SequenceNode) {
                // The last item read by its key: end() takes the list by
                // reference and copies it, once per quantifier of a sequence.
                $last = array_key_last($parent->children);
                if (null === $last || $child !== $parent->children[$last]) {
                    return false;
                }
            } elseif ($parent instanceof GroupNode) {
                if (GroupType::Atomic === $parent->type) {
                    return true;
                }

                if ($this->groups->isSubroutineTarget($parent)) {
                    return false;
                }

                if (!\in_array($parent->type, [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::InlineFlags, GroupType::BranchReset], true)) {
                    return false;
                }
            } elseif ($parent instanceof QuantifierNode) {
                // A possessive "X++" is an atomic group around "X+": a
                // second iteration that fails leaves the first, unless the
                // count needs it.
                return QuantifierType::Possessive === $parent->type && QuantifierMath::parseRange($parent)[0] <= 1;
            } elseif (!$parent instanceof AlternationNode) {
                return false;
            }

            $child = $parent;
        }

        return false;
    }

    /**
     * The analyzer's sets, with the flags followed through the groups, the
     * sequences and the alternations that can change them. Any other node,
     * and one with no inline flags inside, is read whole by the analyzer
     * under the flags at its start. A lookaround reads nothing, as the
     * analyzer has it, whatever options its body sets: "(?=(?i:b))b"
     * starts with "b" alone. With $everyByte, the analyzer reading every
     * byte under the tables of the target's locale reads them.
     */
    private function charsInForce(NodeInterface $node, string $flags, bool $fromStart, bool $everyByte = false): ByteCharSet
    {
        if (FlagScope::containsInlineFlags($node)) {
            if ($node instanceof GroupNode) {
                return NodePredicates::isTransparentGroup($node->type)
                    ? $this->charsInForce($node->child, FlagScope::flagsInside($node, $flags), $fromStart, $everyByte)
                    : ByteCharSet::empty();
            }

            if ($node instanceof QuantifierNode) {
                return $this->charsInForce($node->node, $flags, $fromStart, $everyByte);
            }

            if ($node instanceof AlternationNode) {
                $set = ByteCharSet::empty();
                foreach ($this->flagsAtEachChild($node, $flags) as $index => $flagsAtAlternative) {
                    $set = $set->union($this->charsInForce($node->alternatives[$index], $flagsAtAlternative, $fromStart, $everyByte));
                }

                return $set;
            }

            if ($node instanceof SequenceNode) {
                $flagsAtChild = $this->flagsAtEachChild($node, $flags);
                $indexes = $fromStart ? array_keys($flagsAtChild) : array_reverse(array_keys($flagsAtChild));
                $set = ByteCharSet::empty();
                foreach ($indexes as $index) {
                    $set = $set->union($this->charsInForce($node->children[$index], $flagsAtChild[$index], $fromStart, $everyByte));
                    if (!NodePredicates::isOptionalNode($node->children[$index])) {
                        break;
                    }
                }

                return $set;
            }
        }

        $analyzer = $this->analyzerFor($flags, $everyByte);

        return $fromStart ? $analyzer->firstChars($node) : $analyzer->lastChars($node);
    }

    /**
     * The pattern's analyzer under other flags, the one reading every byte
     * when asked. No inline flag turns the u modifier off: "(?^)" resets
     * i, m, n, s and x only.
     */
    private function analyzerFor(string $flags, bool $everyByte = false): CharSetAnalyzer
    {
        return ($everyByte ? $this->everyByteAnalyzer() : $this->charSetAnalyzer)->withFlags(str_contains($this->pattern->flags, 'u') ? $flags.'u' : $flags);
    }

    /**
     * A lookaround the engine leaves for good once it holds; "(*napla:...)"
     * and "(?*...)" are the non-atomic exceptions, marked "*".
     */
    private static function isAtomicLookaround(GroupNode $group): bool
    {
        return !NodePredicates::isTransparentGroup($group->type)
            && GroupType::ScanSubstring !== $group->type
            && '*' !== $group->flags;
    }
}
