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

/**
 * The prioritized NFA without its epsilon moves, keeping how many distinct
 * epsilon paths lead to each state: an item reads one character of its
 * label, then moves to the ordered list of items its epsilon closure
 * reaches, a state reached by two paths listed twice. A final item stands
 * for the success of the search, under the constraint on the next character
 * its path picked up.
 *
 * The alphabet is cut into classes no label tells apart; a label is the
 * bitmask of its classes, one bit per class, the classes in the order their
 * representatives are tried.
 *
 * @internal
 */
final class ItemAutomaton
{
    /**
     * The end of the subject, as a symbol.
     */
    public const END = -1;

    /**
     * What a path knows of the character before the next one: none, the
     * attempt starts the subject; or a word character, a newline, or any
     * other character. "^", "\A", "^" under /m and "\b" are decided by it.
     */
    public const CONTEXT_START = 0;

    public const CONTEXT_WORD = 1;

    public const CONTEXT_NEWLINE = 2;

    public const CONTEXT_OTHER = 3;

    public const EDGE_LOOP = 1;

    public const EDGE_ABSTRACTED = 2;

    public const EDGE_LOOKAROUND = 4;

    /**
     * @var list<int> the item's state in the pNFA
     */
    public array $states = [];

    /**
     * @var array<int, string> the classes a reading item reads, as a bitmask
     */
    public array $masks = [];

    /**
     * @var array<int, string|null> the classes a final item succeeds before; null for any
     */
    public array $peeks = [];

    /**
     * @var array<int, bool> whether a final item succeeds at the end of the subject
     */
    public array $ends = [];

    /**
     * @var array<int, list<int>> the items after a reading item, in priority order
     */
    public array $successors = [];

    /**
     * @var list<array{context: int, items: list<int>, marks: list<int>}> the
     *                                                                    attempt starts: the subject's start, and when the pattern looks at the
     *                                                                    character before, a later start after each kind of character
     */
    public array $initials = [];

    /**
     * @var array<int, list<int>> the sub-searches met after a reading item
     */
    public array $marksAfter = [];

    /**
     * What the epsilon path to each successor went through, one entry per
     * successor: EDGE_LOOP when it went back into a loop of the pattern,
     * EDGE_ABSTRACTED back into a loop the model abstracted (a bounded repeat
     * read as unbounded), EDGE_LOOKAROUND past a lookaround.
     *
     * @var array<int, list<int>>
     */
    public array $edges = [];

    /**
     * @var array<int, array<int, true>> the contexts each sub-search is met in
     */
    public array $markContexts = [];

    /**
     * @var list<int> the character each class stands for in a witness
     */
    public array $representatives = [];

    /**
     * @var list<CharSet> the characters of each class
     */
    private array $classSets = [];

    /**
     * @var array<int, CharSet>
     */
    private array $labels = [];

    /**
     * @var array<int, CharSet|null>
     */
    private array $peekSets = [];

    /**
     * @var array<int, int> the context a reading item leaves
     */
    private array $contexts = [];

    /**
     * @var array<int, bool> whether a reading item lies after an undecided check
     */
    private array $uncertain = [];

    /**
     * @var array<string, int>
     */
    private array $ids = [];

    /**
     * @var array<string, array{list<int>, list<int>, list<int>}> by state and path context
     */
    private array $closures = [];

    private readonly bool $splitsContext;

    /**
     * @param list<int> $startContexts the contexts the search's attempts start in
     */
    public function __construct(
        public readonly Pnfa $pnfa,
        private readonly Budget $budget,
        array $startContexts,
    ) {
        $this->splitsContext = null !== $pnfa->wordSet || $pnfa->hasLineStart;
        foreach ($startContexts as $context) {
            [$items, $marks] = $this->closure($pnfa->start, $context, false, true);

            $this->initials[] = ['context' => $context, 'items' => $items, 'marks' => $marks];
        }

        for ($item = 0; $item < \count($this->states); $item++) {
            if (!isset($this->labels[$item])) {
                continue;
            }

            [$this->successors[$item], $this->marksAfter[$item], $this->edges[$item]] = $this->closure(
                $pnfa->next[$this->states[$item]],
                $this->contexts[$item],
                $this->uncertain[$item],
            );
        }

        $this->partition();
    }

    /**
     * The character that puts a later attempt start in the context: a word
     * character, a newline, another one; none for the subject's start, nor
     * for a lookaround's body.
     */
    public function contextCharacter(int $context): ?int
    {
        // A lookaround's body starts after the outer search's own prefix.
        if (null !== $this->pnfa->parent) {
            return null;
        }

        $universe = CharSet::universe($this->pnfa->unicode);
        $word = $this->pnfa->wordSet ?? CharSet::empty();

        return match ($context) {
            self::CONTEXT_WORD => $word->isEmpty() ? null : $word->representative(),
            self::CONTEXT_NEWLINE => 0x0A,
            self::CONTEXT_OTHER => $universe->subtract($word)->subtract(CharSet::single(0x0A))->representative(),
            default => null,
        };
    }

    /**
     * Where the pattern writes the character the item reads.
     */
    public function offsetOf(int $item): int
    {
        return $this->pnfa->offsets[$this->states[$item]] ?? 0;
    }

    /**
     * Whether a final item succeeds whatever comes next: once in the items,
     * every continuation is accepted.
     */
    public function isUnconditional(int $item): bool
    {
        return $this->isFinal($item) && null === $this->peeks[$item] && $this->ends[$item];
    }

    /**
     * Whether the item reads inside an atomic body kept with every way
     * through it.
     */
    public function isApproximated(int $item): bool
    {
        return isset($this->pnfa->approximated[$this->states[$item]]);
    }

    public function isFinal(int $item): bool
    {
        return !isset($this->masks[$item]);
    }

    public function count(): int
    {
        return \count($this->states);
    }

    public function classCount(): int
    {
        return \count($this->representatives);
    }

    /**
     * The class a character falls in.
     */
    public function classOf(int $codePoint): int
    {
        foreach ($this->classSets as $class => $set) {
            if ($set->contains($codePoint)) {
                return $class;
            }
        }

        return 0;
    }

    /**
     * Whether a reading item reads the class.
     */
    public function reads(int $item, int $class): bool
    {
        return self::has($this->masks[$item], $class);
    }

    /**
     * Whether a final item succeeds before the symbol: a class, or the end.
     */
    public function accepts(int $item, int $symbol): bool
    {
        if (self::END === $symbol) {
            return $this->ends[$item];
        }

        $peek = $this->peeks[$item];

        return null === $peek || self::has($peek, $symbol);
    }

    /**
     * The items after the ordered list reads the class, in priority order,
     * each once; a final item that succeeds cuts the ones after it.
     *
     * @param list<int> $items
     *
     * @return list<int>
     */
    public function stepOrdered(array $items, int $class): array
    {
        $next = [];
        foreach ($items as $item) {
            $this->budget->step();
            if ($this->isFinal($item)) {
                if ($this->accepts($item, $class)) {
                    break;
                }

                continue;
            }

            if (!self::has($this->masks[$item], $class)) {
                continue;
            }

            foreach ($this->successors[$item] as $successor) {
                $next[$successor] = true;
            }
        }

        return array_keys($next);
    }

    /**
     * The items after the set reads the class, or null when one of its
     * final items succeeds before it.
     *
     * @param array<int, true> $items
     *
     * @return array<int, true>|null
     */
    public function stepSet(array $items, int $class): ?array
    {
        $next = [];
        foreach ($items as $item => $_) {
            $this->budget->step();
            if ($this->isFinal($item)) {
                if ($this->accepts($item, $class)) {
                    return null;
                }

                continue;
            }

            if (!self::has($this->masks[$item], $class)) {
                continue;
            }

            foreach ($this->successors[$item] as $successor) {
                $next[$successor] = true;
            }
        }

        ksort($next);

        return $next;
    }

    /**
     * @param array<int, true> $items
     */
    public function acceptsAtEnd(array $items): bool
    {
        foreach ($items as $item => $_) {
            if ($this->isFinal($item) && $this->ends[$item]) {
                return true;
            }
        }

        return false;
    }

    public static function has(string $mask, int $class): bool
    {
        return 0 !== (\ord($mask[$class >> 3]) & (1 << ($class & 7)));
    }

    public static function isEmpty(string $mask): bool
    {
        return \strlen($mask) === strspn($mask, "\0");
    }

    /**
     * The first class of the mask, the one whose representative is tried
     * first; -1 when the mask is empty.
     */
    public static function first(string $mask): int
    {
        $byte = strspn($mask, "\0");
        if ($byte === \strlen($mask)) {
            return -1;
        }

        $bits = \ord($mask[$byte]);
        $bit = 0;
        while (0 === ($bits & (1 << $bit))) {
            $bit++;
        }

        return $byte * 8 + $bit;
    }

    /**
     * Cuts the alphabet into the classes no label and no constraint tells
     * apart, orders them by their representative, and writes every label
     * as the bitmask of its classes.
     */
    private function partition(): void
    {
        $universe = CharSet::universe($this->pnfa->unicode);
        $sets = [$universe->key() => $universe];
        $keyOf = [];
        foreach ([...$this->labels, ...$this->peekSets] as $set) {
            if (null !== $set && !isset($keyOf[spl_object_id($set)])) {
                $key = $set->key();
                $keyOf[spl_object_id($set)] = $key;
                $sets[$key] ??= $set;
            }
        }

        $bounds = [];
        foreach ($sets as $set) {
            foreach ($set->ranges as [$from, $to]) {
                $bounds[$from] = true;
                $bounds[$to + 1] = true;
            }
        }

        $points = array_keys($bounds);
        sort($points);
        $position = array_flip($points);

        // The sets each elementary interval lies in.
        $signatures = array_fill(0, \count($points), '');
        $keys = array_keys($sets);
        foreach ($keys as $index => $key) {
            foreach ($sets[$key]->ranges as [$from, $to]) {
                // The partition costs what it writes: one mark per interval.
                $this->budget->step(1 + $position[$to + 1] - $position[$from]);
                for ($interval = $position[$from]; $interval < $position[$to + 1]; $interval++) {
                    $signatures[$interval] .= $index.',';
                }
            }
        }

        $groups = [];
        foreach ($signatures as $interval => $signature) {
            // Outside the universe: the surrogates, and past the last character.
            if (!str_starts_with($signature, '0,')) {
                continue;
            }

            $groups[$signature][] = [$points[$interval], $points[$interval + 1] - 1];
        }

        $classes = [];
        foreach ($groups as $signature => $ranges) {
            $set = CharSet::fromSorted($ranges);
            $representative = (int) $set->representative();
            $classes[] = [CharSet::preference($representative), $representative, (string) $signature, $set];
        }

        usort($classes, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $length = max(1, (int) ceil(\count($classes) / 8));
        $bytes = array_fill(0, \count($keys), array_fill(0, $length, 0));
        foreach ($classes as $class => [, $representative, $signature, $set]) {
            $this->representatives[] = $representative;
            $this->classSets[] = $set;
            foreach (explode(',', rtrim($signature, ',')) as $index) {
                $bytes[(int) $index][$class >> 3] |= 1 << ($class & 7);
            }
        }

        $masks = [];
        foreach ($keys as $index => $key) {
            $masks[$key] = implode('', array_map(\chr(...), $bytes[$index]));
        }

        foreach ($this->labels as $item => $label) {
            $this->masks[$item] = $masks[$keyOf[spl_object_id($label)]];
        }

        foreach ($this->peekSets as $item => $peek) {
            $this->peeks[$item] = null === $peek ? null : $masks[$keyOf[spl_object_id($peek)]];
        }
    }

    /**
     * The epsilon closure of a state: the items it reaches, in priority
     * order, a state reached by two paths listed twice, and the sub-searches
     * it passes. A path keeps the context of the character read last, to
     * decide "^", "\A", "^" under /m and "\b", and whether it went through
     * a lookaround, whose constraint the model does not keep: a success after
     * one is not counted.
     *
     * @return array{list<int>, list<int>, list<int>} the items, the
     *                                                sub-searches met, and what
     *                                                each item's path went through
     */
    private function closure(int $state, int $context, bool $uncertain, bool $attemptStart = false): array
    {
        $cacheKey = 'c'.$state.'|'.$context.($uncertain ? 'u' : '').($attemptStart ? 's' : '');
        if (isset($this->closures[$cacheKey])) {
            return $this->closures[$cacheKey];
        }

        $pnfa = $this->pnfa;
        $entries = [];
        $through = [];
        $seen = [];
        $marks = [];
        // Each frame: the state, the constraint on the next character, whether
        // the end may come next, the loops this path entered, whether it went
        // through an undecided check, and what it went through (EDGE_*).
        $stack = [[$state, null, true, [], $uncertain, 0]];
        while ([] !== $stack) {
            /** @var array{int, CharSet|null, bool, array<int, true>, bool, int} $frame */
            $frame = array_pop($stack);
            [$current, $peek, $end, $entered, $undecided, $edge] = $frame;
            // A frame costs what it carries: the loops it remembers.
            $this->budget->step(1 + \count($entered));

            switch ($pnfa->kinds[$current]) {
                case Pnfa::EPSILON:
                    $targets = $pnfa->targets[$current];
                    for ($index = \count($targets) - 1; $index >= 0; $index--) {
                        $stack[] = [$targets[$index], $peek, $end, $entered, $undecided, $edge];
                    }

                    break;

                case Pnfa::CHAR:
                    $label = null === $peek ? $pnfa->sets[$current] : $pnfa->sets[$current]->intersect($peek);
                    foreach ($this->byContext($label) as [$part, $left]) {
                        $item = $this->item($current, $part, null, false, null === $peek && !$this->splitsContext, $left, $undecided);
                        $seen[$item] = ($seen[$item] ?? 0) + 1;
                        if ($seen[$item] <= 2) {
                            $entries[] = $item;
                            $through[] = $edge;
                        }
                    }

                    break;

                case Pnfa::PEEK:
                case Pnfa::BOUNDARY:
                    [$set, $allowsEnd] = Pnfa::PEEK === $pnfa->kinds[$current]
                        ? [$pnfa->sets[$current], $pnfa->ends[$current]]
                        : $this->boundary($pnfa->negated[$current], self::CONTEXT_WORD === $context);
                    $narrowed = null === $peek ? $set : $peek->intersect($set);
                    $endAllowed = $end && $allowsEnd;
                    if (!$narrowed->isEmpty() || $endAllowed) {
                        $stack[] = [$pnfa->next[$current], $narrowed, $endAllowed, $entered, $undecided, $edge];
                    }

                    break;

                case Pnfa::CONTINUATION:
                    // "\G" holds where an attempt starts: the call's offset or
                    // PHP's retry offset, after any character.
                    if ($attemptStart) {
                        $stack[] = [$pnfa->next[$current], $peek, $end, $entered, $undecided, $edge];
                    }

                    break;

                case Pnfa::START:
                    if (self::CONTEXT_START === $context) {
                        $stack[] = [$pnfa->next[$current], $peek, $end, $entered, $undecided, $edge];
                    }

                    break;

                case Pnfa::LINE_START:
                    if (self::CONTEXT_START === $context || self::CONTEXT_NEWLINE === $context) {
                        $stack[] = [$pnfa->next[$current], $peek, $end, $entered, $undecided, $edge];
                    }

                    break;

                case Pnfa::ENTER:
                    $entered[$pnfa->loops[$current]] = true;
                    $stack[] = [$pnfa->next[$current], $peek, $end, $entered, $undecided, $edge];

                    break;

                case Pnfa::LEAVE:
                    $loop = $pnfa->loops[$current];
                    if (isset($entered[$loop])) {
                        $stack[] = [$pnfa->exits[$current], $peek, $end, $entered, $undecided, $edge];

                        break;
                    }

                    $back = isset($pnfa->abstractedLoops[$loop]) ? self::EDGE_ABSTRACTED : self::EDGE_LOOP;
                    $stack[] = [$pnfa->next[$current], $peek, $end, $entered, $undecided, $edge | $back];

                    break;

                case Pnfa::MARK:
                    // A lookaround: its constraint is not kept, so the paths
                    // after it may fail. Its body starts in this context.
                    $marks[$pnfa->marks[$current]] = true;
                    $this->markContexts[$pnfa->marks[$current]][$context] = true;
                    $stack[] = [$pnfa->next[$current], $peek, $end, $entered, true, $edge | self::EDGE_LOOKAROUND];

                    break;

                default:
                    $item = $this->item($current, null, $peek, $end, false, self::CONTEXT_OTHER, $undecided);
                    $seen[$item] = ($seen[$item] ?? 0) + 1;
                    if ($seen[$item] <= 2) {
                        $entries[] = $item;
                        $through[] = $edge;
                    }
            }
        }

        return $this->closures[$cacheKey] = [$entries, array_keys($marks), $through];
    }

    /**
     * The label cut by the context its characters leave, when the search
     * looks at the character before: word characters, the newline, others.
     *
     * @return list<array{CharSet, int}>
     */
    private function byContext(CharSet $label): array
    {
        if ($label->isEmpty()) {
            return [];
        }

        if (!$this->splitsContext) {
            return [[$label, self::CONTEXT_OTHER]];
        }

        $parts = [];
        $rest = $label;
        if (null !== $this->pnfa->wordSet) {
            $word = $rest->intersect($this->pnfa->wordSet);
            if (!$word->isEmpty()) {
                $parts[] = [$word, self::CONTEXT_WORD];
            }

            $rest = $rest->subtract($this->pnfa->wordSet);
        }

        if ($this->pnfa->hasLineStart) {
            $newline = $rest->intersect(CharSet::single(0x0A));
            if (!$newline->isEmpty()) {
                $parts[] = [$newline, self::CONTEXT_NEWLINE];
            }

            $rest = $rest->subtract(CharSet::single(0x0A));
        }

        if (!$rest->isEmpty()) {
            $parts[] = [$rest, self::CONTEXT_OTHER];
        }

        return $parts;
    }

    /**
     * What "\b" (or "\B") asks of the next character, after a word
     * character or not: the characters allowed, and whether the end is.
     *
     * @return array{CharSet, bool}
     */
    private function boundary(bool $negated, bool $afterWord): array
    {
        $word = $this->pnfa->wordSet ?? CharSet::empty();
        $other = CharSet::universe($this->pnfa->unicode)->subtract($word);

        // "\b" holds where the word class changes; the end is not a word character.
        return $afterWord !== $negated ? [$other, true] : [$word, false];
    }

    private function item(int $state, ?CharSet $label, ?CharSet $peek, bool $end, bool $whole, int $context, bool $uncertain): int
    {
        if (null === $label && $uncertain) {
            // A success through an undecided check is not counted.
            $peek = CharSet::empty();
            $end = false;
        }

        $tail = $context.($uncertain ? 'u' : '');
        $key = match (true) {
            $whole => 'S'.$state.'|'.$tail,
            null !== $label => $state.'|'.$label->key().'|'.$tail,
            default => 'F|'.(null === $peek ? '*' : $peek->key()).'|'.($end ? '1' : '0'),
        };

        if (isset($this->ids[$key])) {
            return $this->ids[$key];
        }

        $this->budget->step();
        $item = \count($this->states);
        $this->ids[$key] = $item;
        $this->states[] = $state;
        if (null !== $label) {
            $this->labels[$item] = $label;
            $this->contexts[$item] = $context;
            $this->uncertain[$item] = $uncertain;
        } else {
            $this->peekSets[$item] = $peek;
            $this->ends[$item] = $end;
        }

        return $item;
    }
}
