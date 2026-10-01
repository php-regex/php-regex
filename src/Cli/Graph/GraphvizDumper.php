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

namespace PhpRegex\Cli\Graph;

use PhpRegex\Automata\Model\Nfa;
use PhpRegex\Automata\Model\NfaState;

final class GraphvizDumper
{
    public function dump(Nfa $nfa): string
    {
        $output = "digraph NFA {\n";
        $output .= "  rankdir=LR;\n";
        $output .= "  node [shape=circle, fontname=\"Helvetica\", fontsize=10];\n";
        $output .= "  edge [fontname=\"Helvetica\", fontsize=10];\n";
        $output .= "  start [shape=point, width=0];\n";
        $output .= "  start -> {$nfa->startState};\n";

        // Define nodes
        foreach ($nfa->states as $id => $state) {
            $shape = $state->isAccepting ? 'doublecircle' : 'circle';
            $label = (string) $id;
            $color = $state->isAccepting ? 'black' : 'gray';

            if ($id === $nfa->startState) {
                $color = 'black';
            }

            $output .= "  {$id} [label=\"{$label}\", shape={$shape}, color={$color}];\n";
        }

        // Define edges
        foreach ($nfa->states as $id => $state) {
            $this->dumpTransitions($id, $state, $output);
        }

        $output .= "}\n";

        return $output;
    }

    private function dumpTransitions(int $sourceId, NfaState $state, string &$output): void
    {
        // Epsilon transitions
        foreach ($state->epsilonTransitions as $targetId) {
            $output .= "  {$sourceId} -> {$targetId} [label=\"ε\", style=dashed, color=gray];\n";
        }

        // CharSet transitions
        $transitionsByTarget = [];
        foreach ($state->transitions as $transition) {
            $transitionsByTarget[$transition->target][] = $transition->charSet;
        }

        foreach ($transitionsByTarget as $targetId => $charSets) {
            $labels = [];
            foreach ($charSets as $charSet) {
                $labels[] = $charSet->toString();
            }
            $label = implode(', ', $labels);
            $label = str_replace(['"', '\\'], ['\\"', '\\\\'], $label);

            $output .= "  {$sourceId} -> {$targetId} [label=\"{$label}\"];\n";
        }
    }
}
