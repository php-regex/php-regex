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

namespace PHPRegex\Cli\Graph;

use PHPRegex\Automata\Model\Nfa;
use PHPRegex\Automata\Model\NfaState;

/**
 * @internal
 */
final class MermaidDumper
{
    public function dump(Nfa $nfa): string
    {
        $output = "stateDiagram-v2\n";
        $output .= "    direction LR\n";
        $output .= "    [*] --> {$nfa->startState}\n";

        // Define nodes (states)
        foreach ($nfa->states as $id => $state) {
            if ($state->isAccepting) {
                // Mermaid specific syntax for final states (using styles or notes usually)
                // Standard stateDiagram doesn't have "double circle" exactly, but we can mark it
                $output .= "    {$id} : State {$id}\n";
                $output .= "    note right of {$id}\n      Final\n    end note\n";
            }
        }

        // Define transitions
        foreach ($nfa->states as $id => $state) {
            $this->dumpTransitions($id, $state, $output);
        }

        return $output;
    }

    private function dumpTransitions(int $sourceId, NfaState $state, string &$output): void
    {
        foreach ($state->epsilonTransitions as $targetId) {
            $output .= "    {$sourceId} --> {$targetId} : ε\n";
        }

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
            // Escape special characters for Mermaid labels
            $label = str_replace(['"', ':'], ['\'', ' '], $label);

            $output .= "    {$sourceId} --> {$targetId} : {$label}\n";
        }
    }
}
