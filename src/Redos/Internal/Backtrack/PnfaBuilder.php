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

namespace PHPRegex\Redos\Internal\Backtrack;

use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Hir\ClassSetProvider;
use PHPRegex\Parser\Hir\Utf8;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\UnicodePropNode;

/**
 * Builds the prioritized NFA of a pattern, Thompson style, from its AST.
 *
 * Alternatives are tried left to right, a greedy loop iterates before it
 * leaves and a lazy one the other way round. A lookaround is a search of its
 * own, marked where it stands; its constraint is not kept. A possessive
 * quantifier or an atomic group over one character set reads the longest run
 * and leaves only before a character outside it; any other atomic body is
 * kept as it is, with every way through it. "$", "\z" and "\Z" keep their
 * constraint on the end of the subject, "^", "\A" and "\G" theirs on its
 * start; "\b", "\B" and "^" under /m are not kept. A bounded repeat is
 * unrolled up to the cutoff and read as unbounded past it.
 *
 * Inline options hold where PCRE reads them: one set inside an alternative
 * also holds in the alternatives after it, up to the end of the group around
 * them. The caseless restriction r and the ASCII options are read per scope,
 * like the others, and the engine is asked for each set under the options in
 * force where its atom stands.
 *
 * @internal
 */
final class PnfaBuilder
{
    private const CASELESS = 1;

    private const DOT_ALL = 2;

    private const MULTILINE = 4;

    private const EXTENDED = 8;

    private const UNGREEDY = 16;

    private const RESTRICT = 32;

    private const ASCII_DIGIT = 64;

    private const ASCII_SPACE = 128;

    private const ASCII_WORD = 256;

    private const ASCII_POSIX = 512;

    private const ASCII_POSIX_DIGIT = 1024;

    /**
     * "xx": a class skips its unescaped spaces and tabs.
     */
    private const EXTENDED_MORE = 2048;

    /**
     * The ASCII options by the letter after "a": "(?aD)" for "\d", "(?aS)"
     * for "\s", "(?aW)" for "\w" and "\b", "(?aP)" for the POSIX classes,
     * the digit ones with them, "(?aT)" for the POSIX digit classes only.
     * "-aP" also takes the digit ones off.
     */
    private const ASCII_OPTIONS = [
        'D' => self::ASCII_DIGIT,
        'S' => self::ASCII_SPACE,
        'W' => self::ASCII_WORD,
        'P' => self::ASCII_POSIX | self::ASCII_POSIX_DIGIT,
        'T' => self::ASCII_POSIX_DIGIT,
    ];

    /**
     * What a lone "a" sets and "-a" takes off: every ASCII option.
     */
    private const ASCII_ALL = self::ASCII_DIGIT | self::ASCII_SPACE | self::ASCII_WORD | self::ASCII_POSIX | self::ASCII_POSIX_DIGIT;

    private const OUT_OF_MODEL_TYPES = ['R', 'X', 'C'];

    /**
     * The longest block of atoms looked for as written-out copies.
     */
    private const MAX_COPIED_BLOCK = 4;

    /**
     * What a class query costs in steps: a scan of every code point under
     * /u, of 256 bytes without it.
     */
    private const UNICODE_SCAN_STEPS = 12_000;

    private const BYTE_SCAN_STEPS = 16;

    /**
     * @var list<Pnfa>
     */
    private array $searches = [];

    /**
     * @var list<string>
     */
    private array $abstractions = [];

    private int $loops = 0;

    /**
     * Whether the next loop built stands for a bounded repeat.
     */
    private bool $abstractedLoop = false;

    /**
     * @var array<int, bool> whether a node holds an unbounded repeat, by object id
     */
    private array $unbounded = [];

    /**
     * Where the node being built starts in the pattern.
     */
    private int $offset = 0;

    /**
     * @var array<int, string> the bounded repeats read as unbounded because
     *                         their copies read the same input in two ways, by offset
     */
    private array $ambiguousRepeats = [];

    /**
     * How deep the build is inside atomic bodies kept with every way
     * through them.
     */
    private int $approximating = 0;

    /**
     * @var array<int, string> the atomic bodies kept as written, by offset
     */
    private array $atomicAbstractions = [];

    /**
     * @var array<int, CharSet> the alternations read as one character of the
     *                          union of their branches, by node
     */
    private array $unions = [];

    private ?CharSet $wordSet = null;

    /**
     * The set an atom PCRE refused on its own is read as: every character.
     */
    private ?CharSet $anyCharacter = null;

    /**
     * @var array<string, true> the atoms already charged to the budget
     */
    private array $queried = [];

    /**
     * @var array<int, CharSet>
     */
    private array $singles = [];

    private readonly bool $unicode;

    private readonly bool $dollarEndOnly;

    private readonly string $source;

    public function __construct(
        private readonly RegexNode $regex,
        private readonly Budget $budget,
        private readonly int $boundedRepeatCutoff,
    ) {
        $this->unicode = str_contains($regex->flags, 'u');
        $this->dollarEndOnly = str_contains($regex->flags, 'D');
        $this->source = $regex->source ?? '';
        $this->collectAbstractions($regex->pattern);
    }

    /**
     * The search of the pattern first, then one per lookaround.
     *
     * @throws ModelLimit
     *
     * @return list<Pnfa>
     */
    public function build(): array
    {
        $flags = 0;
        foreach ([['i', self::CASELESS], ['s', self::DOT_ALL], ['m', self::MULTILINE], ['x', self::EXTENDED], ['U', self::UNGREEDY], ['r', self::RESTRICT]] as [$letter, $bit]) {
            if (str_contains($this->regex->flags, $letter)) {
                $flags |= $bit;
            }
        }

        $main = new Pnfa($this->unicode, $this->budget, $this->regex->pattern);
        $this->searches[] = $main;
        $main->start = $this->node($this->regex->pattern, $main->final, $flags, $main, 0);

        // A lookaround reads the character before its mark: every search
        // keeps the context any of them looks at.
        $wordSet = null;
        $lineStart = false;
        foreach ($this->searches as $search) {
            $wordSet ??= $search->wordSet;
            $lineStart = $lineStart || $search->hasLineStart;
        }

        foreach ($this->searches as $search) {
            $search->wordSet ??= $wordSet;
            $search->hasLineStart = $search->hasLineStart || $lineStart;
        }

        return $this->searches;
    }

    /**
     * The bounded repeats read as unbounded, in the order met.
     *
     * @return list<string>
     */
    public function abstractions(): array
    {
        return [...$this->abstractions, ...array_values($this->ambiguousRepeats), ...array_values($this->atomicAbstractions)];
    }

    /**
     * Whether a set was read as any character: no safe verdict then.
     */
    public function hasApproximatedSets(): bool
    {
        return null !== $this->anyCharacter;
    }

    private function node(NodeInterface $node, int $next, int $flags, Pnfa $pnfa, int $search): int
    {
        $this->offset = $node->getStartPosition();

        return match (true) {
            $node instanceof SequenceNode => $this->sequence($node, $next, $flags, $pnfa, $search),
            $node instanceof AlternationNode && isset($this->unions[spl_object_id($node)]) => $this->char($pnfa, $this->unions[spl_object_id($node)], $next),
            $node instanceof AlternationNode => $this->alternation($node, $next, $flags, $pnfa, $search),
            $node instanceof GroupNode => $this->group($node, $next, $flags, $pnfa, $search),
            $node instanceof QuantifierNode => $this->quantifier($node, $next, $flags, $pnfa, $search),
            $node instanceof LiteralNode => $this->literal($node, $next, $flags, $pnfa),
            $node instanceof AnchorNode, $node instanceof AssertionNode => $this->anchor($node, $next, $flags, $pnfa),
            $node instanceof KeepNode, $node instanceof CommentNode => $next,
            default => $this->char($pnfa, $this->charSet($node, $flags), $next),
        };
    }

    private function sequence(SequenceNode $node, int $next, int $flags, Pnfa $pnfa, int $search): int
    {
        $states = [];
        foreach ($node->children as $index => $child) {
            $states[$index] = $flags;
            $flags = $this->flagsAfter($child, $flags);
        }

        [$children, $states] = $this->normalized(array_values($node->children), $states);
        for ($index = \count($children) - 1; $index >= 0; $index--) {
            $next = $this->node($children[$index], $next, $states[$index], $pnfa, $search);
        }

        return $next;
    }

    /**
     * Consecutive copies of the same atoms, written out, read as one bounded
     * repeat of them, "X{k}": the rules for bounded repeats then apply.
     *
     * @param list<NodeInterface> $children
     * @param array<int>          $states   the options in force before each child
     *
     * @return array{list<NodeInterface>, list<int>}
     */
    private function normalized(array $children, array $states): array
    {
        $texts = [];
        foreach ($children as $index => $child) {
            // Copies of an unbounded loop are exact in the model already.
            $texts[$index] = self::isZeroWidth($child) || $this->repeatsUnboundedly($child) ? null : $states[$index].':'.$this->text($child);
        }

        $count = \count($children);
        $result = [];
        $resultStates = [];
        for ($index = 0; $index < $count;) {
            $best = [1, 1];
            for ($length = 1; $length <= self::MAX_COPIED_BLOCK && $index + 2 * $length <= $count; $length++) {
                $copies = 1;
                while ($index + ($copies + 1) * $length <= $count && $this->sameBlock($texts, $index, $index + $copies * $length, $length)) {
                    $copies++;
                }

                if ($copies > 1 && $copies * $length > $best[0] * $best[1]) {
                    $best = [$length, $copies];
                }
            }

            [$length, $copies] = $best;
            if ($copies < 2) {
                $result[] = $children[$index];
                $resultStates[] = $states[$index];
                $index++;

                continue;
            }

            $block = \array_slice($children, $index, $length);
            $first = $block[0];
            $last = $children[$index + $copies * $length - 1];
            $body = 1 === $length ? $first : new SequenceNode($block, $first->getStartPosition(), $block[$length - 1]->getEndPosition());
            $result[] = new QuantifierNode($body, '{'.$copies.'}', QuantifierType::Greedy, $first->getStartPosition(), $last->getEndPosition());
            $resultStates[] = $states[$index];
            $index += $copies * $length;
        }

        return [$result, $resultStates];
    }

    /**
     * @param array<int, string|null> $texts
     */
    private function sameBlock(array $texts, int $first, int $second, int $length): bool
    {
        for ($offset = 0; $offset < $length; $offset++) {
            if (null === $texts[$first + $offset] || $texts[$first + $offset] !== $texts[$second + $offset]) {
                return false;
            }
        }

        return true;
    }

    private function repeatsUnboundedly(NodeInterface $node): bool
    {
        return $this->unbounded[spl_object_id($node)] ??= self::holdsUnboundedRepeat($node);
    }

    private static function holdsUnboundedRepeat(NodeInterface $node): bool
    {
        if ($node instanceof QuantifierNode) {
            $bounds = QuantifierBounds::parse($node->quantifier);
            if (null === $bounds || null === $bounds->max) {
                return true;
            }
        }

        foreach ($node->getChildren() as $child) {
            if (self::holdsUnboundedRepeat($child)) {
                return true;
            }
        }

        return false;
    }

    private static function isZeroWidth(NodeInterface $node): bool
    {
        return $node instanceof AnchorNode || $node instanceof AssertionNode || $node instanceof KeepNode
            || $node instanceof CommentNode
            || ($node instanceof GroupNode && !\in_array($node->type, [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::Atomic, GroupType::BranchReset], true))
            || ($node instanceof LiteralNode && '' === $node->value);
    }

    private function alternation(AlternationNode $node, int $next, int $flags, Pnfa $pnfa, int $search): int
    {
        // An option set in one alternative holds in the ones after it.
        $states = [];
        foreach ($node->alternatives as $index => $alternative) {
            $states[$index] = $flags;
            $flags = $this->flagsAfter($alternative, $flags);
        }

        $targets = [];
        foreach ($node->alternatives as $index => $alternative) {
            $targets[] = $this->node($alternative, $next, $states[$index], $pnfa, $search);
        }

        return 1 === \count($targets) ? $targets[0] : $pnfa->epsilon($targets);
    }

    private function group(GroupNode $node, int $next, int $flags, Pnfa $pnfa, int $search): int
    {
        switch ($node->type) {
            case GroupType::Capturing:
            case GroupType::NonCapturing:
            case GroupType::Named:
            case GroupType::BranchReset:
                return $this->node($node->child, $next, $flags, $pnfa, $search);

            case GroupType::InlineFlags:
                if ($this->isBareOptionSetting($node)) {
                    return $next;
                }

                return $this->node($node->child, $next, $this->applyOptions($flags, $node->flags ?? ''), $pnfa, $search);

            case GroupType::LookaheadPositive:
            case GroupType::LookaheadNegative:
            case GroupType::LookbehindPositive:
            case GroupType::LookbehindNegative:
                // "(?*...)", "(*napla:...)" and their lookbehind forms backtrack
                // into their body: outside the model.
                if ('*' === $node->flags) {
                    throw ModelLimit::outOfModel('A non-atomic lookaround');
                }

                $index = \count($this->searches);
                $sub = new Pnfa(
                    $this->unicode,
                    $this->budget,
                    $node->child,
                    $search,
                    $index,
                    \in_array($node->type, [GroupType::LookbehindPositive, GroupType::LookbehindNegative], true),
                    \in_array($node->type, [GroupType::LookaheadNegative, GroupType::LookbehindNegative], true),
                );
                $this->searches[] = $sub;
                $sub->start = $this->node($node->child, $sub->final, $flags, $sub, $index);

                return $pnfa->mark($index, $next);

            case GroupType::Atomic:
                // A run of one character set: the atomic group keeps its longest
                // run, or its shortest when the quantifier is lazy (under (?U)
                // too), exactly the minimum.
                $union = $this->unionOf($node->child, $flags);
                if (null !== $union) {
                    return $this->char($pnfa, $union, $next);
                }

                $child = $this->unwrap($node->child);
                if ($child instanceof QuantifierNode && null !== ($this->runSet($child->node, $flags) ?? $this->unionOf($child->node, $flags))) {
                    if (!$this->isLazy($child, $flags)) {
                        return $this->quantifier($child, $next, $flags, $pnfa, $search, true);
                    }

                    $bounds = QuantifierBounds::parse($child->quantifier);
                    for ($copy = 0; null !== $bounds && $copy < $bounds->min; $copy++) {
                        $next = $this->node($child->node, $next, $flags, $pnfa, $search);
                    }

                    if (null !== $bounds) {
                        return $next;
                    }
                }

                return $this->approximated($node->getStartPosition(), $node->child, $next, $flags, $pnfa, $search);

            default:
                throw ModelLimit::outOfModel('A '.$node->type->value.' group');
        }
    }

    private function quantifier(QuantifierNode $node, int $next, int $flags, Pnfa $pnfa, int $search, bool $atomic = false): int
    {
        $bounds = QuantifierBounds::parse($node->quantifier);
        if (null === $bounds) {
            throw ModelLimit::outOfModel('The quantifier '.$node->quantifier);
        }

        $min = $bounds->min;
        $max = $bounds->max;
        if (null !== $max && $max > 1) {
            // The copies of a bounded repeat each may match empty: PCRE tries
            // every way to share the input among them, outside the model.
            if (self::nullable($node->node)) {
                throw ModelLimit::outOfModel('A bounded repeat of a body that can match empty');
            }

            // Copies that read the same input in two ways: the unbounded loop
            // carries that ambiguity, the unrolled copies hide it.
            if ($max <= $this->boundedRepeatCutoff && $this->repeatsAmbiguously($node->node, $flags)) {
                $abstracted = true;
                $this->ambiguousRepeats[$node->getStartPosition()] ??= \sprintf(
                    '%s at offset %d analysed as {%d,} (its copies read the same input in two ways)',
                    $node->quantifier,
                    $node->getStartPosition(),
                    $min,
                );
                $max = null;
            }
        }

        if (null !== $max && $max > $this->boundedRepeatCutoff) {
            $abstracted = true;
            $max = null;
        }

        // The loop a bounded repeat becomes is the model's, not the
        // pattern's: no proof rests on it alone.
        $this->abstractedLoop = $abstracted ?? false;

        if (0 === $max) {
            return $next;
        }

        $possessive = $atomic || QuantifierType::Possessive === $node->type;
        $greedy = $possessive || !$this->isLazy($node, $flags);

        // A possessive run of one character set leaves only before a
        // character outside the set: the one way PCRE reads it.
        $run = $possessive ? $this->runSet($node->node, $flags) ?? $this->unionOf($node->node, $flags) : null;
        $leave = $next;
        if (null !== $run) {
            $greedy = true;
            $leave = $pnfa->peek(CharSet::universe($this->unicode)->subtract($run), true, $next);
        }

        $body = $node->node;
        if ($possessive && null === $run) {
            // A possessive repeat is an atomic group around the repeat: a
            // body other than one run is kept with every way through it.
            $this->atomicAbstractions[$body->getStartPosition()] ??= self::atomicEntry($body->getStartPosition());
            $this->approximating++;

            try {
                return $this->repeat($body, $min, $max, $next, $leave, $greedy, $flags, $pnfa, $search);
            } finally {
                $this->approximating--;
            }
        }

        return $this->repeat($body, $min, $max, $next, $leave, $greedy, $flags, $pnfa, $search);
    }

    private function repeat(NodeInterface $body, int $min, ?int $max, int $next, int $leave, bool $greedy, int $flags, Pnfa $pnfa, int $search): int
    {
        if (null === $max) {
            $entry = $this->loop($body, $next, $leave, 0 === $min, $greedy, $flags, $pnfa, $search);
            for ($copy = 1; $copy < $min; $copy++) {
                $entry = $this->node($body, $entry, $flags, $pnfa, $search);
            }

            return $entry;
        }

        // The optional copies nest: a later one is only tried when the one
        // before it matched.
        $entry = $next;
        for ($copy = $min; $copy < $max; $copy++) {
            $taken = $this->node($body, $entry, $flags, $pnfa, $search);
            $entry = $pnfa->epsilon($greedy ? [$taken, $leave] : [$leave, $taken]);
        }

        for ($copy = 0; $copy < $min; $copy++) {
            $entry = $this->node($body, $entry, $flags, $pnfa, $search);
        }

        return $entry;
    }

    /**
     * An atomic body other than one run, kept as written: listed, and its
     * reading states marked, so that a pump through it is not proven.
     */
    private function approximated(int $offset, NodeInterface $body, int $next, int $flags, Pnfa $pnfa, int $search): int
    {
        $this->atomicAbstractions[$offset] ??= self::atomicEntry($offset);
        $this->approximating++;

        try {
            return $this->node($body, $next, $flags, $pnfa, $search);
        } finally {
            $this->approximating--;
        }
    }

    private static function atomicEntry(int $offset): string
    {
        return \sprintf('atomic group at offset %d over-approximated', $offset);
    }

    /**
     * Whether repeating the body reads some input in two ways: the loop over
     * it is ambiguous. A body of one character never is.
     */
    private function repeatsAmbiguously(NodeInterface $body, int $flags): bool
    {
        if (null !== $this->runSet($body, $flags)) {
            return false;
        }

        if (self::holdsLookaround($body)) {
            return true;
        }

        $offset = $this->offset;
        $loop = new Pnfa($this->unicode, $this->budget, $body);
        $loop->start = $this->loop($body, $loop->final, $loop->final, false, true, $flags, $loop, -1);
        $this->offset = $offset;

        return (new AmbiguityFinder(new ItemAutomaton($loop, $this->budget, [ItemAutomaton::CONTEXT_START]), $this->budget))->isAmbiguous();
    }

    private static function holdsLookaround(NodeInterface $node): bool
    {
        if ($node instanceof GroupNode && \in_array($node->type, [GroupType::LookaheadPositive, GroupType::LookaheadNegative, GroupType::LookbehindPositive, GroupType::LookbehindNegative], true)) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::holdsLookaround($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the node can match the empty string.
     */
    private static function nullable(NodeInterface $node): bool
    {
        return match (true) {
            $node instanceof LiteralNode => '' === $node->value,
            $node instanceof SequenceNode => array_reduce($node->children, static fn (bool $all, NodeInterface $child): bool => $all && self::nullable($child), true),
            $node instanceof AlternationNode => array_reduce($node->alternatives, static fn (bool $any, NodeInterface $child): bool => $any || self::nullable($child), false),
            $node instanceof GroupNode => \in_array($node->type, [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::BranchReset, GroupType::Atomic, GroupType::InlineFlags], true)
                ? self::nullable($node->child)
                : true,
            $node instanceof QuantifierNode => 0 === (QuantifierBounds::parse($node->quantifier)->min ?? 0) || self::nullable($node->node),
            $node instanceof AnchorNode, $node instanceof AssertionNode, $node instanceof KeepNode, $node instanceof CommentNode => true,
            $node instanceof CharLiteralNode, $node instanceof ControlCharNode, $node instanceof CharClassNode, $node instanceof CharTypeNode,
            $node instanceof DotNode, $node instanceof UnicodePropNode, $node instanceof ExtendedCharClassNode => false,
            default => true,
        };
    }

    private function char(Pnfa $pnfa, CharSet $set, int $next): int
    {
        $state = $pnfa->char($set, $next, $this->offset);
        if ($this->approximating > 0 || $set === $this->anyCharacter) {
            $pnfa->approximated[$state] = true;
        }

        return $state;
    }

    /**
     * Every bounded repeat past the cutoff, once each, in the order written,
     * known before any state is built: the list holds even when the budget
     * stops the build.
     */
    private function collectAbstractions(NodeInterface $node): void
    {
        if ($node instanceof QuantifierNode) {
            $bounds = QuantifierBounds::parse($node->quantifier);
            if (null !== $bounds && null !== $bounds->max && $bounds->max > $this->boundedRepeatCutoff) {
                $this->abstractions[] = \sprintf(
                    '%s at offset %d analysed as {%d,}',
                    $node->quantifier,
                    $node->getStartPosition(),
                    $bounds->min,
                );
            }
        }

        foreach ($node->getChildren() as $child) {
            $this->collectAbstractions($child);
        }
    }

    private function isLazy(QuantifierNode $node, int $flags): bool
    {
        if (QuantifierType::Possessive === $node->type) {
            return false;
        }

        return (QuantifierType::Lazy === $node->type) !== (0 !== ($flags & self::UNGREEDY));
    }

    /**
     * An unbounded loop over one copy of the body. An iteration that read
     * nothing ends the loop, as in PCRE.
     */
    private function loop(NodeInterface $body, int $next, int $leave, bool $optional, bool $greedy, int $flags, Pnfa $pnfa, int $search): int
    {
        $loop = $this->loops++;
        if ($this->abstractedLoop) {
            $pnfa->abstractedLoops[$loop] = true;
            $this->abstractedLoop = false;
        }

        $head = $pnfa->epsilon();
        $end = $pnfa->leave($loop, $head, $next);
        $enter = $pnfa->enter($loop, $this->node($body, $end, $flags, $pnfa, $search));
        $pnfa->setTargets($head, $greedy ? [$enter, $leave] : [$leave, $enter]);

        return $optional ? $head : $enter;
    }

    private function literal(LiteralNode $node, int $next, int $flags, Pnfa $pnfa): int
    {
        $characters = Utf8::decode($node->value, $this->unicode);
        if (null === $characters) {
            throw ModelLimit::outOfModel('A literal that is not UTF-8');
        }

        for ($index = \count($characters) - 1; $index >= 0; $index--) {
            $next = $this->char($pnfa, $this->characterSet($characters[$index], $flags), $next);
        }

        return $next;
    }

    private function anchor(AnchorNode|AssertionNode $node, int $next, int $flags, Pnfa $pnfa): int
    {
        $none = CharSet::empty();

        return match ($node->value) {
            '^' => 0 !== ($flags & self::MULTILINE) ? $pnfa->lineStart($next) : $pnfa->start($next),
            'A' => $pnfa->start($next),
            'G' => $pnfa->continuation($next),
            '$' => match (true) {
                0 !== ($flags & self::MULTILINE) => $pnfa->peek(CharSet::single(0x0A), true, $next),
                $this->dollarEndOnly => $pnfa->peek($none, true, $next),
                default => $this->endOrFinalNewline($next, $pnfa),
            },
            'z' => $pnfa->peek($none, true, $next),
            'Z' => $this->endOrFinalNewline($next, $pnfa),
            'b', 'B' => $pnfa->boundary('B' === $node->value, $this->wordSet($flags), $next),
            default => throw ModelLimit::outOfModel('The assertion \\'.$node->value),
        };
    }

    /**
     * The characters "\b" reads as word characters where it stands: "\w"
     * under the options in force, ASCII only under aW. One search keeps one
     * set: boundaries that read two different ones are outside the model.
     */
    private function wordSet(int $flags): CharSet
    {
        $set = $this->query('\\w', $flags);
        if (null !== $this->wordSet && $this->wordSet->key() !== $set->key()) {
            throw ModelLimit::outOfModel('Word boundaries under different ASCII options');
        }

        return $this->wordSet = $set;
    }

    /**
     * The end of the subject, or a newline that ends it.
     */
    private function endOrFinalNewline(int $next, Pnfa $pnfa): int
    {
        $none = CharSet::empty();
        $pnfa->readsFinalNewline = true;
        $atEnd = $pnfa->peek($none, true, $next);
        $beforeNewline = $this->char($pnfa, CharSet::single(0x0A), $pnfa->peek($none, true, $next));

        return $pnfa->epsilon([$atEnd, $beforeNewline]);
    }

    /**
     * The set one character-matching node stands for.
     */
    private function charSet(NodeInterface $node, int $flags): CharSet
    {
        if ($node instanceof CharLiteralNode || $node instanceof ControlCharNode) {
            return $this->characterSet($node->codePoint, $flags);
        }

        if ($node instanceof CharTypeNode && \in_array($node->value, self::OUT_OF_MODEL_TYPES, true)) {
            throw ModelLimit::outOfModel('\\'.$node->value);
        }

        if ($node instanceof CharClassNode) {
            // Under xx "[a b]" is "[ab]": the engine reads such a class.
            $readsItsSpaces = 0 === ($flags & self::EXTENDED_MORE) || false === strpbrk($this->text($node), " \t");
            $direct = 0 === ($flags & self::CASELESS) && $readsItsSpaces ? $this->directClass($node) : null;
            if (null !== $direct) {
                return $direct;
            }

            return $this->query($this->text($node), $flags);
        }

        if ($node instanceof DotNode || $node instanceof CharTypeNode
            || $node instanceof UnicodePropNode || $node instanceof ExtendedCharClassNode) {
            return $this->query($this->text($node), $flags);
        }

        throw ModelLimit::outOfModel('The node '.(new \ReflectionClass($node))->getShortName());
    }

    private function characterSet(int $codePoint, int $flags): CharSet
    {
        if ($codePoint > ($this->unicode ? 0x10FFFF : 0xFF)) {
            throw ModelLimit::outOfModel('A character beyond the alphabet');
        }

        if (0 === ($flags & self::CASELESS)) {
            // One shared set per character: the states reading it share a label.
            return $this->singles[$codePoint] ??= CharSet::single($codePoint);
        }

        return $this->query(\sprintf('\\x{%X}', $codePoint), $flags);
    }

    /**
     * The set of a class made of characters and ranges only, without asking
     * the engine; null when it holds anything else.
     */
    private function directClass(CharClassNode $node): ?CharSet
    {
        $members = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];
        $set = CharSet::empty();
        foreach ($members as $member) {
            if ($member instanceof RangeNode) {
                $from = $this->singleCodePoint($member->start);
                $to = $this->singleCodePoint($member->end);
                if (null === $from || null === $to) {
                    return null;
                }

                $set = $set->union(CharSet::range($from, $to));

                continue;
            }

            $codePoint = $this->singleCodePoint($member);
            if (null === $codePoint) {
                return null;
            }

            $set = $set->union(CharSet::single($codePoint));
        }

        return $node->isNegated ? CharSet::universe($this->unicode)->subtract($set) : $set;
    }

    private function singleCodePoint(NodeInterface $node): ?int
    {
        if ($node instanceof CharLiteralNode || $node instanceof ControlCharNode) {
            return $node->codePoint <= ($this->unicode ? 0x10FFFF : 0xFF) ? $node->codePoint : null;
        }

        if ($node instanceof LiteralNode) {
            $characters = Utf8::decode($node->value, $this->unicode);

            return null !== $characters && 1 === \count($characters) ? $characters[0] : null;
        }

        return null;
    }

    /**
     * The one character set a possessive run reads, when the repeated node
     * is one atom matching one character of a set (an alternation is not:
     * PCRE commits to the first alternative that matches).
     */
    private function runSet(NodeInterface $node, int $flags): ?CharSet
    {
        $node = $this->unwrap($node);
        if ($node instanceof LiteralNode) {
            $characters = Utf8::decode($node->value, $this->unicode);

            return null !== $characters && 1 === \count($characters) ? $this->characterSet($characters[0], $flags) : null;
        }

        if ($node instanceof CharLiteralNode || $node instanceof ControlCharNode || $node instanceof CharClassNode
            || $node instanceof DotNode || $node instanceof UnicodePropNode || $node instanceof ExtendedCharClassNode
            || ($node instanceof CharTypeNode && !\in_array($node->value, self::OUT_OF_MODEL_TYPES, true))) {
            return $this->charSet($node, $flags);
        }

        return null;
    }

    /**
     * The one character set an alternation of one-character branches reads
     * where it gives nothing back, inside an atomic group or a possessive
     * repeat: one character of the union of the branches, the options an
     * earlier branch sets holding in the later ones. The alternation is
     * then read as that one set. Null for any other node.
     */
    private function unionOf(NodeInterface $node, int $flags): ?CharSet
    {
        $node = $this->unwrap($node);
        if (!$node instanceof AlternationNode) {
            return null;
        }

        $union = null;
        foreach ($node->alternatives as $alternative) {
            $atom = null;
            $atomFlags = $flags;
            foreach ($alternative instanceof SequenceNode ? $alternative->children : [$alternative] as $child) {
                if ($child instanceof GroupNode && GroupType::InlineFlags === $child->type && $this->isBareOptionSetting($child)) {
                    $atomFlags = null === $atom ? $this->applyOptions($atomFlags, $child->flags ?? '') : $atomFlags;

                    continue;
                }

                if ($child instanceof CommentNode || ($child instanceof LiteralNode && '' === $child->value)) {
                    continue;
                }

                if (null !== $atom) {
                    return null;
                }

                $atom = $child;
            }

            $set = null === $atom ? null : $this->runSet($atom, $atomFlags);
            if (null === $set) {
                return null;
            }

            $union = $union?->union($set) ?? $set;
            $flags = $this->flagsAfter($alternative, $flags);
        }

        if (null !== $union) {
            $this->unions[spl_object_id($node)] = $union;
        }

        return $union;
    }

    private function unwrap(NodeInterface $node): NodeInterface
    {
        while ($node instanceof GroupNode
            && \in_array($node->type, [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::BranchReset], true)) {
            $node = $node->child;
        }

        while ($node instanceof SequenceNode && 1 === \count($node->children)) {
            $node = $this->unwrap($node->children[0]);
        }

        return $node;
    }

    private function query(string $atom, int $flags): CharSet
    {
        $modifiers = self::modifiers($flags);

        // A scan of the Unicode range costs the engine tens of milliseconds:
        // each distinct atom is charged before it runs, the same whether the
        // set is cached or not, so that the verdict does not depend on what
        // ran before.
        if (!isset($this->queried[$modifiers.':'.$atom])) {
            $this->queried[$modifiers.':'.$atom] = true;
            $this->budget->step($this->unicode ? self::UNICODE_SCAN_STEPS : self::BYTE_SCAN_STEPS);
        }

        // The options are the whole scope: a pattern-wide /r is a bit of
        // them already, and "(?-r)" takes it off.
        $set = ClassSetProvider::query($atom, $this->unicode, $modifiers);
        if (null !== $set) {
            return $set;
        }

        // PCRE refused the atom on its own, or gave up scanning it under its
        // limits: every character stands for it.
        // A larger set can hide the continuation that rejects: the states
        // reading it are marked, so that only an exponential pump through
        // exact sets is proven.
        $entry = \sprintf('%s read as any character', $atom);
        if (!\in_array($entry, $this->abstractions, true)) {
            $this->abstractions[] = $entry;
        }

        return $this->anyCharacter ??= CharSet::universe($this->unicode);
    }

    /**
     * The options in force that change what an atom matches, as an inline
     * group writes them: the letters set only.
     */
    private static function modifiers(int $flags): string
    {
        $modifiers = (0 !== ($flags & self::CASELESS) ? 'i' : '')
            .(0 !== ($flags & self::DOT_ALL) ? 's' : '')
            .(0 !== ($flags & self::EXTENDED_MORE) ? 'xx' : (0 !== ($flags & self::EXTENDED) ? 'x' : ''))
            .(0 !== ($flags & self::RESTRICT) ? 'r' : '');
        foreach (['D' => self::ASCII_DIGIT, 'S' => self::ASCII_SPACE, 'W' => self::ASCII_WORD, 'P' => self::ASCII_POSIX] as $letter => $bit) {
            if (0 !== ($flags & $bit)) {
                $modifiers .= 'a'.$letter;
            }
        }

        // "aP" holds the digit classes already.
        if (self::ASCII_POSIX_DIGIT === ($flags & (self::ASCII_POSIX | self::ASCII_POSIX_DIGIT))) {
            $modifiers .= 'aT';
        }

        return $modifiers;
    }

    private function text(NodeInterface $node): string
    {
        return substr($this->source, $node->getStartPosition(), $node->getEndPosition() - $node->getStartPosition());
    }

    private function isBareOptionSetting(GroupNode $node): bool
    {
        $text = $this->text($node);
        $length = \strlen($text);

        return $length >= 3 && str_starts_with($text, '(?') && str_ends_with($text, ')')
            && $length - 3 === strspn($text, '^-abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', 2, $length - 3);
    }

    /**
     * The options in force after the node: those an option setting changes,
     * or one written anywhere in a sequence, up to the end of the group
     * around it. A group's own end restores what was set inside it.
     */
    private function flagsAfter(NodeInterface $node, int $flags): int
    {
        if ($node instanceof GroupNode && GroupType::InlineFlags === $node->type && $this->isBareOptionSetting($node)) {
            return $this->applyOptions($flags, $node->flags ?? '');
        }

        // "(?i)" inside a branch also holds in the branches after it.
        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                $flags = $this->flagsAfter($child, $flags);
            }
        }

        return $flags;
    }

    private function applyOptions(int $flags, string $options): int
    {
        $bits = ['i' => self::CASELESS, 's' => self::DOT_ALL, 'm' => self::MULTILINE, 'U' => self::UNGREEDY, 'r' => self::RESTRICT];
        if (str_starts_with($options, '^')) {
            // "(?^" takes i, m, n, s, x, xx and r back off; U and the ASCII
            // options stay.
            $flags &= ~(self::CASELESS | self::DOT_ALL | self::MULTILINE | self::EXTENDED | self::EXTENDED_MORE | self::RESTRICT);
            $options = substr($options, 1);
        }

        // The group's letters are gathered first and applied together, as
        // PCRE does: "x" alone takes xx off, so "(?xx)(?x)" leaves a class's
        // spaces in, while "(?xxix)" keeps xx. "-x" takes both off.
        $set = 0;
        $unset = 0;
        $on = true;
        $previous = '';
        $length = \strlen($options);
        for ($index = 0; $index < $length; $index++) {
            $letter = $options[$index];
            $bit = 0;
            if ('-' === $letter) {
                $on = false;
            } elseif ('x' === $letter) {
                $bit = $on && 'x' !== $previous ? self::EXTENDED : self::EXTENDED | self::EXTENDED_MORE;
            } elseif ('a' === $letter) {
                // "a" alone, or "a" and the one letter naming its option.
                $ascii = self::ASCII_OPTIONS[$options[$index + 1] ?? ''] ?? null;
                if (null !== $ascii) {
                    $index++;
                }

                $bit = $ascii ?? self::ASCII_ALL;
            } elseif (isset($bits[$letter])) {
                $bit = $bits[$letter];
            }

            if ($on) {
                $set |= $bit;
            } else {
                $unset |= $bit;
            }

            $previous = $letter;
        }

        if (self::EXTENDED === ($set & (self::EXTENDED | self::EXTENDED_MORE))) {
            $unset |= self::EXTENDED_MORE;
        }

        return ($flags | $set) & ~$unset;
    }
}
