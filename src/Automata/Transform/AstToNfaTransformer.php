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

namespace PHPRegex\Automata\Transform;

use PHPRegex\Automata\Alphabet\CharSet;
use PHPRegex\Automata\Builder\NfaBuilder;
use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\Model\Nfa;
use PHPRegex\Automata\Model\NfaFragment;
use PHPRegex\Automata\Model\NfaTransition;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Unicode\CodePointHelper;
use PHPRegex\Parser\Internal\StaticCaches;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;

/**
 * Builds an NFA from a regex AST using Thompson construction.
 *
 * @internal
 */
final class AstToNfaTransformer implements AstToNfaTransformerInterface
{
    /**
     * How many code points are matched at once when scanning Unicode.
     */
    private const SCAN_BLOCK_SIZE = 8192;

    /**
     * How many code points are tested at once for a case mapping.
     */
    private const CASE_BLOCK_SIZE = 256;

    /**
     * Case mappings of every code point that has one, or null until built.
     *
     * @var array<int, array<int>>|null
     */
    /**
     * @var array<int, list<int>>|null
     */
    private static ?array $caseFoldingTable = null;

    /**
     * @var array<string, CharSet>
     */
    private static array $fullCharSet = [];

    /**
     * @var array<string, CharSet>
     */
    private static array $dotCharSet = [];

    /**
     * @var array<string, CharSet>
     */
    private static array $dotAllCharSet = [];

    /**
     * @var array<string, CharSet>
     */
    private static array $wordCharSet = [];

    /**
     * @var array<string, CharSet>
     */
    private static array $spaceCharSet = [];

    /**
     * @var array<string, CharSet>
     */
    private static array $digitCharSet = [];

    /**
     * @var array<string, CharSet>
     */
    private static array $wordCharSetComplement = [];

    /**
     * @var array<string, CharSet>
     */
    private static array $spaceCharSetComplement = [];

    /**
     * @var array<string, CharSet>
     */
    private static array $digitCharSetComplement = [];

    private NfaBuilder $builder;

    private bool $caseInsensitive = false;

    /**
     * Set while building a subtree whose last element is a possessive
     * quantifier an enclosing sequence has proven safe: nothing that follows
     * can take back what it matched.
     */
    private bool $possessiveAsGreedy = false;

    private bool $dotAll = false;

    private bool $unicode = false;

    private int $alphabetMax = CharSet::MAX_CODEPOINT;

    public function __construct(private readonly string $pattern)
    {
        StaticCaches::register(self::class, self::clearCaches(...));
    }

    /**
     * @throws ComplexityException
     */
    public function transform(RegexNode $regex, SolverOptions $options): Nfa
    {
        $this->unicode = \str_contains($regex->flags, 'u');
        $this->alphabetMax = $this->unicode ? CharSet::UNICODE_MAX_CODEPOINT : CharSet::MAX_CODEPOINT;
        $this->builder = new NfaBuilder($options->maxNfaStates, CharSet::MIN_CODEPOINT, $this->alphabetMax);
        $this->caseInsensitive = \str_contains($regex->flags, 'i');
        $this->dotAll = \str_contains($regex->flags, 's');

        if (MatchMode::Full === $options->matchMode) {
            // A whole-string match makes an anchor at the edge of an
            // alternative redundant, and only there.
            $this->analyzeAnchors($regex->pattern, 'full');
        }

        $fragment = $this->buildNode($regex->pattern, $options);
        if (MatchMode::Partial === $options->matchMode) {
            [$startAnchored, $endAnchored] = $this->analyzePartialAnchors($regex->pattern);
            $fragment = $this->wrapPartialMatch($fragment, $startAnchored, $endAnchored);
        }

        return $this->builder->build($fragment);
    }

    /**
     * @throws ComplexityException
     */
    private function buildNode(NodeInterface $node, SolverOptions $options): NfaFragment
    {
        if ($node instanceof SequenceNode) {
            return $this->buildSequence($node, $options);
        }

        if ($node instanceof AlternationNode) {
            return $this->buildAlternation($node, $options);
        }

        if ($node instanceof GroupNode) {
            if (GroupType::Atomic === $node->type) {
                throw new ComplexityException(
                    'Atomic groups commit to their first match and never retry, which is ordered behaviour the solver cannot read as a pure language.',
                    $node->getStartPosition(),
                    $this->pattern,
                );
            }

            return $this->buildNode($node->child, $options);
        }

        if ($node instanceof QuantifierNode) {
            return $this->buildQuantifier($node, $options);
        }

        if ($node instanceof LiteralNode) {
            return $this->buildLiteral($node);
        }

        if ($node instanceof CharLiteralNode) {
            return $this->buildCharFromCodePoint($node->codePoint, $node->getStartPosition());
        }

        if ($node instanceof ControlCharNode) {
            return $this->buildCharFromCodePoint($node->codePoint, $node->getStartPosition());
        }

        if ($node instanceof CharTypeNode) {
            return $this->buildCharType($node);
        }

        if ($node instanceof CharClassNode) {
            return $this->buildCharClass($node);
        }

        if ($node instanceof RangeNode) {
            return $this->buildRange($node);
        }

        if ($node instanceof AnchorNode) {
            return $this->epsilonFragment();
        }

        if ($node instanceof DotNode) {
            return $this->buildDot();
        }

        throw new ComplexityException('Unsupported regex node in automata conversion.', $node->getStartPosition(), $this->pattern);
    }

    /**
     * @throws ComplexityException
     */
    private function buildSequence(SequenceNode $node, SolverOptions $options): NfaFragment
    {
        if ([] === $node->children) {
            return $this->epsilonFragment();
        }

        $fragments = [];
        foreach ($node->children as $index => $child) {
            $possessive = $this->possessiveAtEnd($child);
            if (null !== $possessive) {
                $follower = $node->children[$index + 1] ?? null;
                $followerIsLast = \count($node->children) === $index + 2;
                if (null === $follower) {
                    // No follower here: an enclosing sequence already proved
                    // this subtree safe, or nothing vouches for it at all.
                    if (!$this->possessiveAsGreedy) {
                        throw new ComplexityException(
                            'Possessive quantifiers never give back what they matched, which is ordered behaviour the solver cannot read as a pure language.',
                            $possessive->getStartPosition(),
                            $this->pattern,
                        );
                    }
                    $fragments[] = $this->buildNode($child, $options);

                    continue;
                }
                if (!$this->possessiveIsSafe($possessive, $follower, $options, $followerIsLast)) {
                    throw new ComplexityException(
                        'Possessive quantifiers never give back what they matched, which is ordered behaviour the solver cannot read as a pure language.',
                        $possessive->getStartPosition(),
                        $this->pattern,
                    );
                }
                $this->possessiveAsGreedy = true;

                try {
                    $fragments[] = $this->buildNode($child, $options);
                } finally {
                    $this->possessiveAsGreedy = false;
                }

                continue;
            }
            $fragments[] = $this->buildNode($child, $options);
        }

        $current = \array_shift($fragments);
        foreach ($fragments as $fragment) {
            $current = $this->concatenate($current, $fragment);
        }

        return $current;
    }

    /**
     * A possessive quantifier reads the same language as its greedy
     * spelling when nothing that follows it can consume what it would have
     * to give back: the follower consumes no character of its own (an
     * anchor), or its first characters and the atom's share none — Symfony
     * requirements are the common case, "[^/]++" before a "/". A follower
     * that can be skipped (an epsilon path to an accepting state) makes any
     * shorter match possible, so that case stays refused.
     */
    /**
     * The possessive quantifier a subtree ends with, through plain groups
     * and sequences — Symfony puts one inside a named group — or null. An
     * assertion, an alternation or anything else in the tail is a barrier:
     * what follows it is not what follows the quantifier.
     */
    private function possessiveAtEnd(NodeInterface $node): ?QuantifierNode
    {
        while (true) {
            if ($node instanceof QuantifierNode) {
                return QuantifierType::Possessive === $node->type ? $node : null;
            }
            if ($node instanceof SequenceNode) {
                // The parser emits an EmptyNode, never an empty sequence;
                // a null arm would fall through to the return below anyway.
                $node = $node->children[\count($node->children) - 1] ?? null;

                continue;
            }
            if ($node instanceof GroupNode && \in_array($node->type, [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named], true)) {
                $node = $node->child;

                continue;
            }

            return null;
        }
    }

    private function possessiveIsSafe(QuantifierNode $node, NodeInterface $follower, SolverOptions $options, bool $followerIsLast): bool
    {
        $atom = $this->buildNode($node->node, $options);
        $atomStart = $this->builderStateView($atom->startState);
        if ([] !== $atomStart['epsilon'] || 1 !== \count($atomStart['transitions'])) {
            return false;
        }

        $next = $this->buildNode($follower, $options);
        $first = $this->firstConsumedSet($next->startState, $next->acceptStates, $followerIsLast);
        if (null === $first) {
            return false;
        }

        return $atomStart['transitions'][0]->charSet->intersect($first)->isEmpty();
    }

    /**
     * The characters the state can consume before anything else, through
     * epsilon moves; an empty set for a pure zero-width follower such as an
     * anchor, and null when the follower may be skipped — a shorter possessive
     * match could then matter.
     */
    /**
     * @return array{transitions: array<NfaTransition>, epsilon: array<int>, accepting: bool}
     */
    private function builderStateView(int $state): array
    {
        return [
            'transitions' => $this->builder->transitionsOf($state),
            'epsilon' => $this->builder->epsilonTransitionsOf($state),
            'accepting' => $this->builder->isAccepting($state),
        ];
    }

    /**
     * @param array<int> $acceptStates the fragment's own accepting states —
     *                                 the builder flags them only once built
     */
    private function firstConsumedSet(int $state, array $acceptStates, bool $nullableIsSafe): ?CharSet
    {
        $seen = [];
        $queued = [$state => true];
        $stack = [$state];
        $set = CharSet::empty($this->alphabetMax);
        while ([] !== $stack) {
            $current = array_pop($stack);
            $seen[$current] = true;
            $nfaState = $this->builderStateView($current);
            if (\in_array($current, $acceptStates, true) && !$nullableIsSafe) {
                // A follower that may be skipped lets a shorter possessive
                // match matter — something after it may take what it gives
                // back, and this one-step look cannot see that far.
                return null;
            }
            foreach ($nfaState['transitions'] as $transition) {
                $set = $set->union($transition->charSet);
            }
            // Cycles and repeats are cut here, at the push: every state is
            // queued once, so the pop above never needs its own guard.
            foreach ($nfaState['epsilon'] as $target) {
                if (!isset($seen[$target]) && !isset($queued[$target])) {
                    $queued[$target] = true;
                    $stack[] = $target;
                }
            }
        }

        return $set;
    }

    /**
     * @throws ComplexityException
     */
    private function buildAlternation(AlternationNode $node, SolverOptions $options): NfaFragment
    {
        $start = $this->builder->createState();
        $end = $this->builder->createState();

        foreach ($node->alternatives as $alternative) {
            $fragment = $this->buildNode($alternative, $options);
            $this->builder->addEpsilon($start, $fragment->startState);
            foreach ($fragment->acceptStates as $acceptState) {
                $this->builder->addEpsilon($acceptState, $end);
            }
        }

        return new NfaFragment($start, [$end]);
    }

    /**
     * @throws ComplexityException
     */
    /**
     * Possessive quantifiers reach here either through a sequence, which has
     * already refused them or proven them safe (possessiveAsGreedy), or as
     * the bare root of the pattern, where nothing vouches for them.
     */
    private function buildQuantifier(QuantifierNode $node, SolverOptions $options): NfaFragment
    {
        if (QuantifierType::Possessive === $node->type && !$this->possessiveAsGreedy) {
            throw new ComplexityException(
                'Possessive quantifiers never give back what they matched, which is ordered behaviour the solver cannot read as a pure language.',
                $node->getStartPosition(),
                $this->pattern,
            );
        }
        [$min, $max] = $this->parseQuantifierRange($node->quantifier);

        if (0 === $min && 0 === $max) {
            return $this->epsilonFragment();
        }

        if (null === $max) {
            if (0 === $min) {
                return $this->buildStar($node->node, $options);
            }

            $fragment = $this->repeatNode($node->node, $options, $min);
            $star = $this->buildStar($node->node, $options);

            return $this->concatenate($fragment, $star);
        }

        return $this->buildBoundedRepeat($node->node, $options, $min, $max);
    }

    /**
     * @throws ComplexityException
     */
    private function buildLiteral(LiteralNode $node): NfaFragment
    {
        if ('' === $node->value) {
            return $this->epsilonFragment();
        }
        $start = $this->builder->createState();
        $current = $start;
        if ($this->unicode) {
            $codePoints = CodePointHelper::toCodePoints($node->value);
            if ([] === $codePoints) {
                throw new ComplexityException('Invalid UTF-8 literal in /u pattern.', $node->getStartPosition(), $this->pattern);
            }

            foreach ($codePoints as $codePoint) {
                $next = $this->builder->createState();
                $charSet = $this->applyCaseInsensitive(CharSet::fromCodePoint($codePoint, $this->alphabetMax));
                $this->builder->addTransition($current, $charSet, $next);
                $current = $next;
            }
        } else {
            $length = \strlen($node->value);
            for ($i = 0; $i < $length; $i++) {
                $next = $this->builder->createState();
                $codePoint = \ord($node->value[$i]);
                $charSet = $this->applyCaseInsensitive(CharSet::fromCodePoint($codePoint, $this->alphabetMax));
                $this->builder->addTransition($current, $charSet, $next);
                $current = $next;
            }
        }

        return new NfaFragment($start, [$current]);
    }

    /**
     * @throws ComplexityException
     */
    private function buildCharFromCodePoint(int $codePoint, int $position): NfaFragment
    {
        if ($codePoint < CharSet::MIN_CODEPOINT || $codePoint > $this->alphabetMax) {
            throw new ComplexityException('Character outside supported alphabet.', $position, $this->pattern);
        }

        $start = $this->builder->createState();
        $end = $this->builder->createState();
        $charSet = $this->applyCaseInsensitive(CharSet::fromCodePoint($codePoint, $this->alphabetMax));
        $this->builder->addTransition($start, $charSet, $end);

        return new NfaFragment($start, [$end]);
    }

    /**
     * @throws ComplexityException
     */
    private function buildCharType(CharTypeNode $node): NfaFragment
    {
        $charSet = match ($node->value) {
            'd' => $this->digitCharSet(),
            'D' => $this->digitCharSetComplement(),
            'w' => $this->wordCharSet(),
            'W' => $this->wordCharSetComplement(),
            's' => $this->spaceCharSet(),
            'S' => $this->spaceCharSetComplement(),
            default => throw new ComplexityException('Unsupported character type: '.$node->value.'.', $node->getStartPosition(), $this->pattern),
        };

        $start = $this->builder->createState();
        $end = $this->builder->createState();
        $this->builder->addTransition($start, $this->applyCaseInsensitive($charSet), $end);

        return new NfaFragment($start, [$end]);
    }

    /**
     * @throws ComplexityException
     */
    private function buildCharClass(CharClassNode $node): NfaFragment
    {
        $charSet = $this->applyCaseInsensitive($this->buildCharClassExpression($node->expression));
        if ($node->isNegated) {
            $charSet = $charSet->complement();
        }

        $start = $this->builder->createState();
        $end = $this->builder->createState();
        $this->builder->addTransition($start, $charSet, $end);

        return new NfaFragment($start, [$end]);
    }

    /**
     * @throws ComplexityException
     */
    private function buildRange(RangeNode $node): NfaFragment
    {
        $startCode = $this->extractCodePoint($node->start);
        $endCode = $this->extractCodePoint($node->end);

        $charSet = CharSet::fromRange($startCode, $endCode, $this->alphabetMax);
        $charSet = $this->applyCaseInsensitive($charSet);

        $start = $this->builder->createState();
        $end = $this->builder->createState();
        $this->builder->addTransition($start, $charSet, $end);

        return new NfaFragment($start, [$end]);
    }

    private function buildDot(): NfaFragment
    {
        $charSet = $this->dotAll ? $this->dotAllCharSet() : $this->dotCharSet();

        $start = $this->builder->createState();
        $end = $this->builder->createState();
        $this->builder->addTransition($start, $charSet, $end);

        return new NfaFragment($start, [$end]);
    }

    /**
     * @throws ComplexityException
     */
    private function buildCharClassExpression(NodeInterface $node): CharSet
    {
        if ($node instanceof AlternationNode) {
            $set = CharSet::empty($this->alphabetMax);
            foreach ($node->alternatives as $alternative) {
                $set = $set->union($this->buildCharClassExpression($alternative));
            }

            return $set;
        }

        if ($node instanceof RangeNode) {
            $startCode = $this->extractCodePoint($node->start);
            $endCode = $this->extractCodePoint($node->end);

            return CharSet::fromRange($startCode, $endCode, $this->alphabetMax);
        }

        if ($node instanceof LiteralNode) {
            if ('' === $node->value) {
                return CharSet::empty($this->alphabetMax);
            }

            if ($this->unicode) {
                $codePoints = CodePointHelper::toCodePoints($node->value);
                if ([] === $codePoints) {
                    throw new ComplexityException('Invalid UTF-8 literal in /u pattern.', $node->getStartPosition(), $this->pattern);
                }

                $ranges = [];
                foreach ($codePoints as $codePoint) {
                    $ranges[] = [$codePoint, $codePoint];
                }
                $set = CharSet::fromRanges($ranges, $this->alphabetMax);
            } else {
                $ranges = [];
                $length = \strlen($node->value);
                for ($i = 0; $i < $length; $i++) {
                    $codePoint = \ord($node->value[$i]);
                    $ranges[] = [$codePoint, $codePoint];
                }
                $set = CharSet::fromRanges($ranges, $this->alphabetMax);
            }

            return $set;
        }

        if ($node instanceof CharLiteralNode) {
            return CharSet::fromCodePoint($node->codePoint, $this->alphabetMax);
        }

        if ($node instanceof ControlCharNode) {
            return CharSet::fromCodePoint($node->codePoint, $this->alphabetMax);
        }

        if ($node instanceof CharTypeNode) {
            return match ($node->value) {
                'd' => $this->digitCharSet(),
                'D' => $this->digitCharSetComplement(),
                'w' => $this->wordCharSet(),
                'W' => $this->wordCharSetComplement(),
                's' => $this->spaceCharSet(),
                'S' => $this->spaceCharSetComplement(),
                default => throw new ComplexityException('Unsupported character type in class: '.$node->value.'.', $node->getStartPosition(), $this->pattern),
            };
        }

        if ($node instanceof CharClassNode) {
            $set = $this->buildCharClassExpression($node->expression);

            return $node->isNegated ? $set->complement() : $set;
        }

        throw new ComplexityException('Unsupported character class expression.', $node->getStartPosition(), $this->pattern);
    }

    /**
     * @throws ComplexityException
     */
    private function buildStar(NodeInterface $node, SolverOptions $options): NfaFragment
    {
        $start = $this->builder->createState();
        $end = $this->builder->createState();
        $fragment = $this->buildNode($node, $options);

        $this->builder->addEpsilon($start, $end);
        $this->builder->addEpsilon($start, $fragment->startState);
        foreach ($fragment->acceptStates as $acceptState) {
            $this->builder->addEpsilon($acceptState, $fragment->startState);
            $this->builder->addEpsilon($acceptState, $end);
        }

        return new NfaFragment($start, [$end]);
    }

    /**
     * @throws ComplexityException
     */
    private function repeatNode(NodeInterface $node, SolverOptions $options, int $count): NfaFragment
    {
        if (0 === $count) {
            return $this->epsilonFragment();
        }

        $fragment = $this->buildNode($node, $options);
        for ($i = 1; $i < $count; $i++) {
            $fragment = $this->concatenate($fragment, $this->buildNode($node, $options));
        }

        return $fragment;
    }

    /**
     * @throws ComplexityException
     */
    private function buildBoundedRepeat(
        NodeInterface $node,
        SolverOptions $options,
        int $min,
        int $max,
    ): NfaFragment {
        if (0 === $max) {
            return $this->epsilonFragment();
        }

        if (0 === $min) {
            $start = $this->builder->createState();
            $acceptStates = [$start];
            $currentAccepts = [$start];

            for ($i = 1; $i <= $max; $i++) {
                $fragment = $this->buildNode($node, $options);
                foreach ($currentAccepts as $acceptState) {
                    $this->builder->addEpsilon($acceptState, $fragment->startState);
                }

                $currentAccepts = $fragment->acceptStates;
                $acceptStates = \array_merge($acceptStates, $currentAccepts);
            }

            return new NfaFragment($start, \array_values(\array_unique($acceptStates)));
        }

        $fragment = $this->buildNode($node, $options);
        $start = $fragment->startState;
        $acceptStates = [];
        $currentAccepts = $fragment->acceptStates;

        if (1 >= $min) {
            $acceptStates = \array_merge($acceptStates, $currentAccepts);
        }

        for ($i = 2; $i <= $max; $i++) {
            $next = $this->buildNode($node, $options);
            foreach ($currentAccepts as $acceptState) {
                $this->builder->addEpsilon($acceptState, $next->startState);
            }

            $currentAccepts = $next->acceptStates;
            if ($i >= $min) {
                $acceptStates = \array_merge($acceptStates, $currentAccepts);
            }
        }

        return new NfaFragment($start, \array_values(\array_unique($acceptStates)));
    }

    private function concatenate(NfaFragment $first, NfaFragment $second): NfaFragment
    {
        foreach ($first->acceptStates as $acceptState) {
            $this->builder->addEpsilon($acceptState, $second->startState);
        }

        return new NfaFragment($first->startState, $second->acceptStates);
    }

    private function epsilonFragment(): NfaFragment
    {
        $state = $this->builder->createState();

        return new NfaFragment($state, [$state]);
    }

    private function wordCharSet(): CharSet
    {
        $key = $this->cacheKey();
        if (isset(self::$wordCharSet[$key])) {
            return self::$wordCharSet[$key];
        }

        if ($this->unicode) {
            $ranges = $this->buildUnicodeRanges('\\w');
            $set = CharSet::fromRanges($ranges, $this->alphabetMax);
        } else {
            $letters = CharSet::fromRange(\ord('A'), \ord('Z'), $this->alphabetMax)
                ->union(CharSet::fromRange(\ord('a'), \ord('z'), $this->alphabetMax));
            $digits = CharSet::fromRange(\ord('0'), \ord('9'), $this->alphabetMax);
            $underscore = CharSet::fromCodePoint(\ord('_'), $this->alphabetMax);
            $set = $letters->union($digits)->union($underscore);
        }

        self::$wordCharSet[$key] = $set;

        return $set;
    }

    private function spaceCharSet(): CharSet
    {
        $key = $this->cacheKey();
        if (isset(self::$spaceCharSet[$key])) {
            return self::$spaceCharSet[$key];
        }

        if ($this->unicode) {
            $ranges = $this->buildUnicodeRanges('\\s');
            $set = CharSet::fromRanges($ranges, $this->alphabetMax);
        } else {
            $space = CharSet::fromCodePoint(\ord(' '), $this->alphabetMax);
            $tab = CharSet::fromCodePoint(0x09, $this->alphabetMax);
            $newline = CharSet::fromCodePoint(0x0A, $this->alphabetMax);
            $carriage = CharSet::fromCodePoint(0x0D, $this->alphabetMax);
            $formFeed = CharSet::fromCodePoint(0x0C, $this->alphabetMax);
            $vertical = CharSet::fromCodePoint(0x0B, $this->alphabetMax);
            $set = $space->union($tab)->union($newline)->union($carriage)->union($formFeed)->union($vertical);
        }

        self::$spaceCharSet[$key] = $set;

        return $set;
    }

    private function digitCharSet(): CharSet
    {
        $key = $this->cacheKey();
        if (isset(self::$digitCharSet[$key])) {
            return self::$digitCharSet[$key];
        }

        if ($this->unicode) {
            $ranges = $this->buildUnicodeRanges('\\d');
            $set = CharSet::fromRanges($ranges, $this->alphabetMax);
        } else {
            $set = CharSet::fromRange(\ord('0'), \ord('9'), $this->alphabetMax);
        }

        self::$digitCharSet[$key] = $set;

        return $set;
    }

    private function wordCharSetComplement(): CharSet
    {
        $key = $this->cacheKey();
        if (isset(self::$wordCharSetComplement[$key])) {
            return self::$wordCharSetComplement[$key];
        }

        $set = $this->wordCharSet()->complement();
        self::$wordCharSetComplement[$key] = $set;

        return $set;
    }

    private function spaceCharSetComplement(): CharSet
    {
        $key = $this->cacheKey();
        if (isset(self::$spaceCharSetComplement[$key])) {
            return self::$spaceCharSetComplement[$key];
        }

        $set = $this->spaceCharSet()->complement();
        self::$spaceCharSetComplement[$key] = $set;

        return $set;
    }

    private function digitCharSetComplement(): CharSet
    {
        $key = $this->cacheKey();
        if (isset(self::$digitCharSetComplement[$key])) {
            return self::$digitCharSetComplement[$key];
        }

        $set = $this->digitCharSet()->complement();
        self::$digitCharSetComplement[$key] = $set;

        return $set;
    }

    /**
     * @throws ComplexityException
     */
    private function extractCodePoint(NodeInterface $node): int
    {
        if ($node instanceof LiteralNode) {
            if ('' === $node->value) {
                return 0;
            }

            if ($this->unicode) {
                if (!CodePointHelper::isValidUtf8($node->value)) {
                    throw new ComplexityException('Invalid UTF-8 literal in /u pattern.', $node->getStartPosition(), $this->pattern);
                }

                $codePoint = CodePointHelper::singleCodePoint($node->value);
                if (null === $codePoint) {
                    throw new ComplexityException('Invalid range endpoint in character class.', $node->getStartPosition(), $this->pattern);
                }

                return $codePoint;
            }

            return \ord($node->value[0]);
        }

        if ($node instanceof CharLiteralNode) {
            return $node->codePoint;
        }

        if ($node instanceof ControlCharNode) {
            return $node->codePoint;
        }

        throw new ComplexityException('Unsupported range endpoint in character class.', $node->getStartPosition(), $this->pattern);
    }

    /**
     * @return array{0:int, 1:int|null}
     */
    private function parseQuantifierRange(string $quantifier): array
    {
        return match ($quantifier) {
            '*' => [0, null],
            '+' => [1, null],
            '?' => [0, 1],
            default => \preg_match('/^\{(\d++)(?:,(\d*+))?\}$/', $quantifier, $matches) ?
                (isset($matches[2]) ? ('' === $matches[2] ?
                    [(int) $matches[1], null] :
                    [(int) $matches[1], (int) $matches[2]]
                ) :
                    [(int) $matches[1], (int) $matches[1]]
                ) :
                [1, 1],
        };
    }

    private function applyCaseInsensitive(CharSet $charSet): CharSet
    {
        if (!$this->caseInsensitive) {
            return $charSet;
        }

        if ($charSet->isEmpty() || $charSet->isFull()) {
            return $charSet;
        }

        if ($this->isCaseInvariantCharSet($charSet)) {
            return $charSet;
        }

        if (!$this->unicode) {
            $expanded = $charSet;
            for ($i = \ord('A'); $i <= \ord('Z'); $i++) {
                $lower = $i + 32;
                if ($charSet->contains($i)) {
                    $expanded = $expanded->union(CharSet::fromCodePoint($lower, $this->alphabetMax));
                }
                if ($charSet->contains($lower)) {
                    $expanded = $expanded->union(CharSet::fromCodePoint($i, $this->alphabetMax));
                }
            }

            return $expanded;
        }

        if (!\function_exists('mb_strtolower') || !\function_exists('mb_strtoupper')) {
            throw new ComplexityException('Unicode case folding requires the mbstring extension.', 0, $this->pattern);
        }

        // Only a few thousand code points have a case mapping at all, so the
        // set is walked against that table rather than code point by code
        // point: folding "[\x{0}-\x{10FFFF}]" costs the same as folding "[a]".
        // PCRE folds case as equivalence classes: a set holding "k" matches
        // "K" and the Kelvin sign U+212A, whose own lower case is "k" — so a
        // group joins the set when ANY of its members is in it.
        $additions = [];
        foreach (self::caseEquivalenceTable() as $members) {
            $inSet = false;
            foreach ($members as $member) {
                if ($member <= $this->alphabetMax && $charSet->contains($member)) {
                    $inSet = true;

                    break;
                }
            }
            if (!$inSet) {
                continue;
            }

            foreach ($members as $member) {
                if ($member <= $this->alphabetMax) {
                    $additions[$member] = true;
                }
            }
        }

        if ([] === $additions) {
            return $charSet;
        }

        $codePoints = array_keys($additions);
        sort($codePoints);

        return $charSet->union(CharSet::fromRanges($this->toRanges($codePoints), $this->alphabetMax));
    }

    /**
     * The sets are keyed by the alphabet and the mode, a handful of each;
     * the table is built once.
     */
    private static function clearCaches(): void
    {
        self::$caseFoldingTable = null;
        self::$fullCharSet = [];
        self::$dotCharSet = [];
        self::$dotAllCharSet = [];
        self::$wordCharSet = [];
        self::$spaceCharSet = [];
        self::$digitCharSet = [];
        self::$wordCharSetComplement = [];
        self::$spaceCharSetComplement = [];
        self::$digitCharSetComplement = [];
    }

    /**
     * Case mappings of every code point that has one, built once per process.
     *
     * @return array<int, array<int>>
     */
    /**
     * Case as PCRE matches it: equivalence classes, keyed by the class's
     * lower case. Every member joins when any member is matched, so "k"
     * carries the Kelvin sign U+212A and "s" the long s U+017F.
     *
     * @return array<string, list<int>>
     */
    private static function caseEquivalenceTable(): array
    {
        // mbstring folds the Turkish dotless i with "i" (mb_strtoupper of
        // U+0131 is "I"); PCRE does not — preg_match('/i/iu', "\u{131}") is
        // 0, and so is the same for U+0130. Keep the pair out entirely.
        $turkish = [0x130 => true, 0x131 => true];

        $groups = [];
        foreach (self::caseFoldingTable() as $codePoint => $folded) {
            if (isset($turkish[$codePoint])) {
                continue;
            }
            $char = (string) CodePointHelper::toString($codePoint);
            // The lowercase of the uppercase, so a character that is its own
            // lowercase (the long s U+017F) still joins the class of "s".
            $key = \mb_strtolower(\mb_strtoupper($char, 'UTF-8'), 'UTF-8');
            $groups[$key] ??= [];
            $groups[$key][$codePoint] = true;
            foreach ($folded as $other) {
                $groups[$key][$other] = true;
            }
        }

        return array_map(array_keys(...), $groups);
    }

    /**
     * @return array<int, list<int>> the case variants of each code point
     *                               that has any, keyed by the code point
     */
    private static function caseFoldingTable(): array
    {
        if (null !== self::$caseFoldingTable) {
            return self::$caseFoldingTable;
        }

        $table = [];

        for ($blockStart = CharSet::MIN_CODEPOINT; $blockStart <= CharSet::UNICODE_MAX_CODEPOINT; $blockStart += self::CASE_BLOCK_SIZE) {
            $blockEnd = min($blockStart + self::CASE_BLOCK_SIZE - 1, CharSet::UNICODE_MAX_CODEPOINT);
            [$text, $codePointAt] = self::encodeBlock($blockStart, $blockEnd);

            // Most of Unicode has no case at all: a block that comes back
            // unchanged holds nothing worth looking at character by character.
            if ('' === $text
                || ($text === \mb_strtolower($text, 'UTF-8') && $text === \mb_strtoupper($text, 'UTF-8'))) {
                continue;
            }

            foreach ($codePointAt as $codePoint) {
                $char = CodePointHelper::toString($codePoint);
                if (null === $char) {
                    continue;
                }

                $folded = [];
                foreach ([\mb_strtolower($char, 'UTF-8'), \mb_strtoupper($char, 'UTF-8')] as $variant) {
                    $variantCodePoint = $variant === $char ? null : CodePointHelper::singleCodePoint($variant);
                    if (null !== $variantCodePoint) {
                        $folded[] = $variantCodePoint;
                    }
                }

                if ([] !== $folded) {
                    $table[$codePoint] = $folded;
                }
            }
        }

        return self::$caseFoldingTable = $table;
    }

    /**
     * Collapse a sorted list of code points into ranges.
     *
     * @param array<int> $codePoints
     *
     * @return array<int, array{0:int, 1:int}>
     */
    private function toRanges(array $codePoints): array
    {
        $ranges = [];
        $start = null;
        $previous = null;

        foreach ($codePoints as $codePoint) {
            if (null === $start) {
                $start = $previous = $codePoint;

                continue;
            }

            if ($codePoint === $previous + 1) {
                $previous = $codePoint;

                continue;
            }

            $ranges[] = [$start, (int) $previous];
            $start = $previous = $codePoint;
        }

        if (null !== $start) {
            $ranges[] = [$start, (int) $previous];
        }

        return $ranges;
    }

    private function isCaseInvariantCharSet(CharSet $charSet): bool
    {
        $key = $this->cacheKey();

        return (isset(self::$wordCharSet[$key]) && $charSet === self::$wordCharSet[$key])
            || (isset(self::$wordCharSetComplement[$key]) && $charSet === self::$wordCharSetComplement[$key])
            || (isset(self::$spaceCharSet[$key]) && $charSet === self::$spaceCharSet[$key])
            || (isset(self::$spaceCharSetComplement[$key]) && $charSet === self::$spaceCharSetComplement[$key])
            || (isset(self::$digitCharSet[$key]) && $charSet === self::$digitCharSet[$key])
            || (isset(self::$digitCharSetComplement[$key]) && $charSet === self::$digitCharSetComplement[$key]);
    }

    private function wrapPartialMatch(
        NfaFragment $fragment,
        bool $startAnchored,
        bool $endAnchored,
    ): NfaFragment {
        $charSet = $this->fullCharSet();

        $start = $fragment->startState;
        if (!$startAnchored) {
            $start = $this->builder->createState();
            $this->builder->addTransition($start, $charSet, $start);
            $this->builder->addEpsilon($start, $fragment->startState);
        }

        if (!$endAnchored) {
            foreach ($fragment->acceptStates as $acceptState) {
                $this->builder->addTransition($acceptState, $charSet, $acceptState);
            }
        }

        return new NfaFragment($start, $fragment->acceptStates);
    }

    /**
     * @return array{0: bool, 1: bool}
     */
    private function analyzePartialAnchors(NodeInterface $node): array
    {
        [$startAnchor, $noStartAnchor, $endAnchor, $noEndAnchor] = $this->analyzeAnchors($node, 'partial');

        if ($startAnchor && $noStartAnchor) {
            throw new ComplexityException(
                'Mixed start anchors across alternatives are not supported in partial match mode.',
                0,
                $this->pattern,
            );
        }

        if ($endAnchor && $noEndAnchor) {
            throw new ComplexityException(
                'Mixed end anchors across alternatives are not supported in partial match mode.',
                0,
                $this->pattern,
            );
        }

        return [$startAnchor, $endAnchor];
    }

    /**
     * Anchors compile to nothing, which only tells the truth where they carry
     * no meaning: at the edges of an alternative. A "^" or a "$" anywhere else
     * changes what the pattern matches — "/a^b/" matches nothing at all — and
     * dropping it would hand back a confidently wrong answer, so the pattern
     * is refused instead.
     *
     * @throws ComplexityException
     *
     * @return array{0: bool, 1: bool, 2: bool, 3: bool} start anchor seen, one
     *                                                   missing, end anchor
     *                                                   seen, one missing
     */
    private function analyzeAnchors(NodeInterface $node, string $mode): array
    {
        $alternatives = $node instanceof AlternationNode ? $node->alternatives : [$node];
        $seenStartAnchor = false;
        $seenNoStartAnchor = false;
        $seenEndAnchor = false;
        $seenNoEndAnchor = false;

        foreach ($alternatives as $alternative) {
            $sequence = $alternative instanceof SequenceNode ? $alternative->children : [$alternative];
            if ([] === $sequence) {
                $seenNoStartAnchor = true;
                $seenNoEndAnchor = true;

                continue;
            }

            $lastIndex = \count($sequence) - 1;
            $hasStart = $sequence[0] instanceof AnchorNode && '^' === $sequence[0]->value;
            $hasEnd = $sequence[$lastIndex] instanceof AnchorNode && '$' === $sequence[$lastIndex]->value;

            $seenStartAnchor = $seenStartAnchor || $hasStart;
            $seenNoStartAnchor = $seenNoStartAnchor || !$hasStart;
            $seenEndAnchor = $seenEndAnchor || $hasEnd;
            $seenNoEndAnchor = $seenNoEndAnchor || !$hasEnd;

            foreach ($sequence as $index => $child) {
                if ($child instanceof AnchorNode) {
                    $isStart = 0 === $index && '^' === $child->value;
                    $isEnd = $lastIndex === $index && '$' === $child->value;
                    if (!$isStart && !$isEnd) {
                        throw new ComplexityException(
                            \sprintf('Anchors in %s match mode must appear at the start or end of each alternative.', $mode),
                            $child->getStartPosition(),
                            $this->pattern,
                        );
                    }

                    continue;
                }

                if ($this->containsAnchor($child)) {
                    throw new ComplexityException(
                        \sprintf('Nested anchors are not supported in %s match mode.', $mode),
                        $child->getStartPosition(),
                        $this->pattern,
                    );
                }
            }
        }

        return [$seenStartAnchor, $seenNoStartAnchor, $seenEndAnchor, $seenNoEndAnchor];
    }

    private function containsAnchor(NodeInterface $node): bool
    {
        if ($node instanceof AnchorNode) {
            return true;
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                if ($this->containsAnchor($child)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alternative) {
                if ($this->containsAnchor($alternative)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof GroupNode) {
            return $this->containsAnchor($node->child);
        }

        if ($node instanceof QuantifierNode) {
            return $this->containsAnchor($node->node);
        }

        return false;
    }

    private function fullCharSet(): CharSet
    {
        $key = $this->cacheKey();
        self::$fullCharSet[$key] ??= CharSet::full($this->alphabetMax);

        return self::$fullCharSet[$key];
    }

    private function dotCharSet(): CharSet
    {
        $key = $this->cacheKey();
        self::$dotCharSet[$key] ??= CharSet::full($this->alphabetMax)
            ->subtract(CharSet::fromCodePoint(\ord("\n"), $this->alphabetMax));

        return self::$dotCharSet[$key];
    }

    private function dotAllCharSet(): CharSet
    {
        $key = $this->cacheKey();
        self::$dotAllCharSet[$key] ??= CharSet::full($this->alphabetMax);

        return self::$dotAllCharSet[$key];
    }

    private function cacheKey(): string
    {
        return ($this->unicode ? 'u:' : 'b:').$this->alphabetMax;
    }

    /**
     * The code points an escape such as "\\w" stands for under /u.
     *
     * The whole Unicode range is walked, so it is matched a block at a time:
     * one preg_match_all over a few thousand characters instead of one call
     * per code point turns seconds into milliseconds.
     *
     * @param string $atom a regex matching a single character
     *
     * @return array<int, array{0:int, 1:int}>
     */
    private function buildUnicodeRanges(string $atom): array
    {
        $pattern = '/'.$atom.'/u';
        $ranges = [];
        $rangeStart = null;

        for ($blockStart = CharSet::MIN_CODEPOINT; $blockStart <= $this->alphabetMax; $blockStart += self::SCAN_BLOCK_SIZE) {
            $blockEnd = min($blockStart + self::SCAN_BLOCK_SIZE - 1, $this->alphabetMax);
            $matched = $this->matchingCodePoints($pattern, $blockStart, $blockEnd);

            for ($codePoint = $blockStart; $codePoint <= $blockEnd; $codePoint++) {
                if (isset($matched[$codePoint])) {
                    $rangeStart ??= $codePoint;

                    continue;
                }

                if (null !== $rangeStart) {
                    $ranges[] = [$rangeStart, $codePoint - 1];
                    $rangeStart = null;
                }
            }
        }

        if (null !== $rangeStart) {
            $ranges[] = [$rangeStart, $this->alphabetMax];
        }

        return $ranges;
    }

    /**
     * The code points of a block that the pattern matches.
     *
     * @return array<int, true>
     */
    private function matchingCodePoints(string $pattern, int $blockStart, int $blockEnd): array
    {
        [$text, $codePointAt] = self::encodeBlock($blockStart, $blockEnd);

        $matches = [];
        if ('' === $text || !preg_match_all($pattern, $text, $found, \PREG_OFFSET_CAPTURE)) {
            return $matches;
        }

        /** @var array<array{0: string, 1: int}> $occurrences */
        $occurrences = $found[0];
        foreach ($occurrences as [, $offset]) {
            if (isset($codePointAt[$offset])) {
                $matches[$codePointAt[$offset]] = true;
            }
        }

        return $matches;
    }

    /**
     * Encode a block of code points as one UTF-8 string, skipping the
     * surrogates, which are not characters.
     *
     * Converting the whole block at once is what makes scanning the Unicode
     * range affordable: encoding it one code point at a time costs more than
     * the matching that follows.
     *
     * @return array{0: string, 1: array<int, int>} the text, and the code
     *                                              point each byte offset
     *                                              starts
     */
    private static function encodeBlock(int $blockStart, int $blockEnd): array
    {
        $codePoints = [];
        $codePointAt = [];
        $offset = 0;

        for ($codePoint = $blockStart; $codePoint <= $blockEnd; $codePoint++) {
            if (($codePoint >= 0xD800 && $codePoint <= 0xDFFF) || $codePoint > CharSet::UNICODE_MAX_CODEPOINT) {
                continue;
            }

            $codePoints[] = $codePoint;
            $codePointAt[$offset] = $codePoint;
            $offset += match (true) {
                $codePoint < 0x80 => 1,
                $codePoint < 0x800 => 2,
                $codePoint < 0x10000 => 3,
                default => 4,
            };
        }

        if ([] === $codePoints) {
            return ['', []];
        }

        // One conversion for the whole block where mbstring is there, and the
        // same encoding done by hand where it is not: the rest of the package
        // works without the extension, and so does this.
        $text = \function_exists('mb_convert_encoding')
            ? (string) mb_convert_encoding(pack('N*', ...$codePoints), 'UTF-8', 'UTF-32BE')
            : self::encodeCodePoints($codePoints);

        return [$text, $codePointAt];
    }

    /**
     * @param array<int> $codePoints
     */
    private static function encodeCodePoints(array $codePoints): string
    {
        $text = '';

        foreach ($codePoints as $codePoint) {
            $text .= CodePointHelper::toString($codePoint) ?? '';
        }

        return $text;
    }
}
