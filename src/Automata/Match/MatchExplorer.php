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

namespace PHPRegex\Automata\Match;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Parser\Hir\CharSet;

/**
 * Runs two priority NFAs side by side on every subject at once, the way a
 * leftmost-first matcher runs one: threads in priority order, a new start at
 * each position until a match is found, the threads below a match cut. What
 * preg_match() would write is read off at each prefix; the first prefix
 * where the two differ, breadth first, is the shortest subject that tells
 * them apart.
 *
 * Positions are kept only as far as their equality goes: each one a label,
 * renumbered in order of appearance, so the configurations are finitely
 * many and the search ends.
 *
 * @internal
 */
final class MatchExplorer
{
    private const NEWLINE = 0x0A;

    /**
     * @var list<int> one character standing for each class of characters no
     *                state tells apart
     */
    private array $symbols = [];

    public function __construct(
        private readonly PriorityNfa $left,
        private readonly PriorityNfa $right,
        private readonly int $maxConfigurations,
        private readonly string $pattern,
    ) {}

    /**
     * The shortest subject preg_match() treats differently, or null when
     * there is none.
     *
     * @throws ComplexityException
     */
    public function counterExample(): ?string
    {
        // The same automaton runs alike everywhere: [0-9] and \d read alike.
        if ($this->left->fingerprint() === $this->right->fingerprint()) {
            return null;
        }

        $sameShape = $this->left->hasTheShapeOf($this->right);
        $this->symbols = $this->alphabet();

        [$left, $right, $key] = $this->canonical(
            $this->start($this->left),
            $this->start($this->right),
            true,
        );

        /** @var \SplQueue<array{0: array{t: list<array{0: int, 1: array<int, int>, 2: int|null}>, b: array<int, int>|null}, 1: array{t: list<array{0: int, 1: array<int, int>, 2: int|null}>, b: array<int, int>|null}, 2: bool, 3: string}> $queue */
        $queue = new \SplQueue();
        $queue->enqueue([$left, $right, true, $key]);
        /** @var array<string, array{0: string, 1: int}|null> $previous */
        $previous = [$key => null];

        while (!$queue->isEmpty()) {
            [$left, $right, , $key] = $queue->dequeue();

            if ($this->differ($left, $right, $sameShape)) {
                return $this->subject($key, $previous);
            }

            // Nothing left to run: both outcomes stay as they are.
            if ([] === $left['t'] && [] === $right['t']) {
                continue;
            }

            $fresh = self::labelCount($left, $right);
            foreach ($this->symbolsFor($left, $right) as $symbol) {
                [$nextLeft, $nextRight, $nextKey] = $this->canonical(
                    $this->step($this->left, $left, $symbol, $fresh),
                    $this->step($this->right, $right, $symbol, $fresh),
                    false,
                    $fresh,
                );

                if (\array_key_exists($nextKey, $previous)) {
                    continue;
                }

                if (\count($previous) >= $this->maxConfigurations) {
                    throw new ComplexityException(\sprintf('The match solver explored more than %d configurations for these patterns.', $this->maxConfigurations), 0, $this->pattern);
                }

                $previous[$nextKey] = [$key, $symbol];
                $queue->enqueue([$nextLeft, $nextRight, false, $nextKey]);
            }
        }

        return null;
    }

    /**
     * The characters worth reading here: two characters the waiting threads
     * read alike lead to the same next configuration, so one of them stands
     * for both. The newline counts apart wherever "$" waits.
     *
     * @param array{t: list<array{0: int, 1: array<int, int>, 2: int|null}>, b: array<int, int>|null} $left
     * @param array{t: list<array{0: int, 1: array<int, int>, 2: int|null}>, b: array<int, int>|null} $right
     *
     * @return list<int>
     */
    private function symbolsFor(array $left, array $right): array
    {
        $sets = [];
        $newline = false;
        foreach ([[$this->left, $left], [$this->right, $right]] as [$nfa, $side]) {
            foreach ($side['t'] as [$state, , $waiting]) {
                if (null !== $waiting) {
                    continue;
                }

                $kind = $nfa->kinds[$state];
                if (PriorityNfa::CHAR === $kind) {
                    $sets[$nfa->sets[$state]->key()] = $nfa->sets[$state];
                } elseif (PriorityNfa::END_OR_FINAL_NEWLINE === $kind) {
                    $newline = true;
                }
            }
        }

        $symbols = [];
        foreach ($this->symbols as $symbol) {
            $signature = $newline && self::NEWLINE === $symbol ? 'n' : '';
            foreach ($sets as $set) {
                $signature .= $set->contains($symbol) ? '1' : '0';
            }
            $symbols[$signature] ??= $symbol;
        }

        return array_values($symbols);
    }

    /**
     * @param array{t: list<array{0: int, 1: array<int, int>, 2: int|null}>, b: array<int, int>|null} $left
     * @param array{t: list<array{0: int, 1: array<int, int>, 2: int|null}>, b: array<int, int>|null} $right
     */
    private function differ(array $left, array $right, bool $sameShape): bool
    {
        $leftOutcome = $this->outcome($this->left, $left);
        $rightOutcome = $this->outcome($this->right, $right);

        if (!$sameShape) {
            return null !== $leftOutcome || null !== $rightOutcome;
        }

        if (null === $leftOutcome || null === $rightOutcome) {
            return $leftOutcome !== $rightOutcome;
        }

        for ($slot = 0, $slots = 2 * $this->left->groupCount + 2; $slot < $slots; $slot++) {
            if (($leftOutcome[$slot] ?? null) !== ($rightOutcome[$slot] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The threads at the start of the subject.
     *
     * @return array{t: list<array{0: int, 1: array<int, int>, 2: int|null}>, b: array<int, int>|null}
     */
    private function start(PriorityNfa $nfa): array
    {
        $threads = [];
        $visited = [];
        $this->add($nfa, $threads, $visited, $nfa->start, [0 => 0], 0, true);

        return $this->cut($nfa, ['t' => $threads, 'b' => null], 0);
    }

    /**
     * Reads one character: label 0 is the position before it, $fresh the
     * one after.
     *
     * @param array{t: list<array{0: int, 1: array<int, int>, 2: int|null}>, b: array<int, int>|null} $side
     *
     * @return array{t: list<array{0: int, 1: array<int, int>, 2: int|null}>, b: array<int, int>|null}
     */
    private function step(PriorityNfa $nfa, array $side, int $symbol, int $fresh): array
    {
        $threads = [];
        $visited = [];
        foreach ($side['t'] as [$state, $registers, $waiting]) {
            if (null !== $waiting) {
                continue;
            }

            $kind = $nfa->kinds[$state];
            if (PriorityNfa::CHAR === $kind && $nfa->sets[$state]->contains($symbol)) {
                $this->add($nfa, $threads, $visited, $nfa->next[$state], $registers, $fresh, false);
            } elseif (PriorityNfa::END_OR_FINAL_NEWLINE === $kind && self::NEWLINE === $symbol && !isset($visited['w'.$state])) {
                // "$" holds here if this newline ends the subject: wait and see.
                $visited['w'.$state] = true;
                $threads[] = [$state, $registers, 0];
            }
        }

        // Until a match is found, a match may start at the next position too,
        // after every start tried before it.
        if (null === $side['b']) {
            $this->add($nfa, $threads, $visited, $nfa->start, [0 => $fresh], $fresh, false);
        }

        return $this->cut($nfa, ['t' => $threads, 'b' => $side['b']], $fresh);
    }

    /**
     * Follows what reads nothing from a state, in priority order, and lists
     * the threads that wait on the subject; the first path to a state wins.
     *
     * @param list<array{0: int, 1: array<int, int>, 2: int|null}> $threads
     * @param array<int|string, true>                              $visited
     * @param array<int, int>                                      $registers
     */
    private function add(PriorityNfa $nfa, array &$threads, array &$visited, int $state, array $registers, int $label, bool $atStart): void
    {
        if (isset($visited[$state])) {
            return;
        }
        $visited[$state] = true;

        switch ($nfa->kinds[$state]) {
            case PriorityNfa::SPLIT:
                foreach ($nfa->targets[$state] as $target) {
                    $this->add($nfa, $threads, $visited, $target, $registers, $label, $atStart);
                }

                return;
            case PriorityNfa::TAG:
                $registers[$nfa->slots[$state]] = $label;
                $this->add($nfa, $threads, $visited, $nfa->next[$state], $registers, $label, $atStart);

                return;
            case PriorityNfa::START:
                if ($atStart) {
                    $this->add($nfa, $threads, $visited, $nfa->next[$state], $registers, $label, $atStart);
                }

                return;
            default:
                $threads[] = [$state, $registers, null];
        }
    }

    /**
     * A thread that reached the end of the pattern is the match, for now;
     * the threads PCRE would have tried after it are gone.
     *
     * @param array{t: list<array{0: int, 1: array<int, int>, 2: int|null}>, b: array<int, int>|null} $side
     *
     * @return array{t: list<array{0: int, 1: array<int, int>, 2: int|null}>, b: array<int, int>|null}
     */
    private function cut(PriorityNfa $nfa, array $side, int $label): array
    {
        foreach ($side['t'] as $index => [$state, $registers]) {
            if (PriorityNfa::MATCH === $nfa->kinds[$state]) {
                $registers[1] = $label;

                return ['t' => \array_slice($side['t'], 0, $index), 'b' => $registers];
            }
        }

        return $side;
    }

    /**
     * What preg_match() reports if the subject ends here: the first thread
     * an end anchor lets through, or the match found so far.
     *
     * @param array{t: list<array{0: int, 1: array<int, int>, 2: int|null}>, b: array<int, int>|null} $side
     *
     * @return array<int, int>|null
     */
    private function outcome(PriorityNfa $nfa, array $side): ?array
    {
        foreach ($side['t'] as [$state, $registers, $waiting]) {
            if (PriorityNfa::CHAR === $nfa->kinds[$state]) {
                continue;
            }

            $outcome = $this->finish($nfa, $nfa->next[$state], $registers, $waiting ?? 0, null !== $waiting);
            if (null !== $outcome) {
                return $outcome;
            }
        }

        return $side['b'];
    }

    /**
     * Follows an end anchor to the end of the pattern in priority order, its
     * tags taking the position the match ends at. Before the newline that
     * ends the subject, "\z" fails where "$" holds. What follows an end
     * anchor reads nothing, as the builder made sure.
     *
     * @param array<int, int> $registers
     *
     * @return array<int, int>|null
     */
    private function finish(PriorityNfa $nfa, int $state, array $registers, int $label, bool $beforeFinalNewline): ?array
    {
        $kind = $nfa->kinds[$state];
        if (PriorityNfa::SPLIT === $kind) {
            foreach ($nfa->targets[$state] as $target) {
                $outcome = $this->finish($nfa, $target, $registers, $label, $beforeFinalNewline);
                if (null !== $outcome) {
                    return $outcome;
                }
            }

            return null;
        }

        if (PriorityNfa::TAG === $kind) {
            $registers[$nfa->slots[$state]] = $label;
        }

        return match ($kind) {
            PriorityNfa::MATCH => [1 => $label] + $registers,
            PriorityNfa::CHAR, PriorityNfa::START => null, // never after an end anchor: the builder refuses them there
            PriorityNfa::END => $beforeFinalNewline ? null : $this->finish($nfa, $nfa->next[$state], $registers, $label, false),
            default => $this->finish($nfa, $nfa->next[$state], $registers, $label, $beforeFinalNewline),
        };
    }

    /**
     * Renumbers the labels in order of appearance, the current position
     * first, so that two runs that differ only in where they are meet.
     *
     * @param array{t: list<array{0: int, 1: array<int, int>, 2: int|null}>, b: array<int, int>|null} $left
     * @param array{t: list<array{0: int, 1: array<int, int>, 2: int|null}>, b: array<int, int>|null} $right
     *
     * @return array{0: array{t: list<array{0: int, 1: array<int, int>, 2: int|null}>, b: array<int, int>|null}, 1: array{t: list<array{0: int, 1: array<int, int>, 2: int|null}>, b: array<int, int>|null}, 2: string}
     */
    private function canonical(array $left, array $right, bool $atStart, int $position = 0): array
    {
        $labels = [$position => 0];
        $relabel = static function (?int $label) use (&$labels): ?int {
            if (null === $label) {
                return null;
            }

            return $labels[$label] ??= \count($labels);
        };

        $key = $atStart ? 's' : 'm';
        $sides = [];
        foreach ([$left, $right] as $side) {
            $threads = [];
            foreach ($side['t'] as [$state, $registers, $waiting]) {
                ksort($registers);
                $registers = array_map($relabel, $registers);
                $waiting = $relabel($waiting);
                $threads[] = [$state, $registers, $waiting];
                $key .= '|'.$state.':'.json_encode($registers).':'.($waiting ?? '-');
            }

            $best = $side['b'];
            if (null !== $best) {
                ksort($best);
                $best = array_map($relabel, $best);
            }
            $key .= '#'.(null === $best ? '-' : json_encode($best)).'/';

            /** @var array{t: list<array{0: int, 1: array<int, int>, 2: int|null}>, b: array<int, int>|null} $canonical */
            $canonical = ['t' => $threads, 'b' => $best];
            $sides[] = $canonical;
        }

        return [$sides[0], $sides[1], $key];
    }

    /**
     * @param array{t: list<array{0: int, 1: array<int, int>, 2: int|null}>, b: array<int, int>|null} $left
     * @param array{t: list<array{0: int, 1: array<int, int>, 2: int|null}>, b: array<int, int>|null} $right
     */
    private static function labelCount(array $left, array $right): int
    {
        $highest = 0;
        foreach ([$left, $right] as $side) {
            foreach ($side['t'] as [, $registers, $waiting]) {
                $highest = max($highest, $waiting ?? 0, ...array_values($registers) ?: [0]);
            }
            $highest = max($highest, ...array_values($side['b'] ?? []) ?: [0]);
        }

        return $highest + 1;
    }

    /**
     * @param array<string, array{0: string, 1: int}|null> $previous
     */
    private function subject(string $key, array $previous): string
    {
        $symbols = [];
        while (null !== $previous[$key]) {
            [$key, $symbol] = $previous[$key];
            $symbols[] = $symbol;
        }

        $subject = '';
        foreach (array_reverse($symbols) as $symbol) {
            $subject .= $this->left->unicode ? mb_chr($symbol, 'UTF-8') : \chr($symbol);
        }

        return $subject;
    }

    /**
     * One character per class of characters that every set of both automata
     * treats alike, the newline on its own for "$": characters inside the
     * same sets are one class, however many ranges they spread over.
     *
     * @return list<int>
     */
    private function alphabet(): array
    {
        $universe = CharSet::universe($this->left->unicode);
        $sets = [CharSet::single(self::NEWLINE)];
        foreach ([$this->left, $this->right] as $nfa) {
            foreach ($nfa->sets as $set) {
                $sets[$set->key()] = $set;
            }
        }
        $sets = array_values($sets);

        $bounds = [];
        foreach ([$universe, ...$sets] as $set) {
            foreach ($set->ranges as [$from, $to]) {
                $bounds[$from] = true;
                $bounds[$to + 1] = true;
            }
        }
        $bounds = array_keys($bounds);
        sort($bounds);

        // The sets a character belongs to name its class; the first printable
        // character of the class stands for it.
        $symbols = [];
        for ($index = 0, $count = \count($bounds) - 1; $index < $count; $index++) {
            $symbol = CharSet::range($bounds[$index], $bounds[$index + 1] - 1)->intersect($universe)->representative();
            if (null === $symbol) {
                continue;
            }

            $signature = '';
            foreach ($sets as $set) {
                $signature .= $set->contains($symbol) ? '1' : '0';
            }

            if (!isset($symbols[$signature]) || (!self::printable($symbols[$signature]) && self::printable($symbol))) {
                $symbols[$signature] = $symbol;
            }
        }

        $symbols = array_values($symbols);
        sort($symbols);

        return $symbols;
    }

    private static function printable(int $codePoint): bool
    {
        return $codePoint >= 0x20 && $codePoint <= 0x7E;
    }
}
