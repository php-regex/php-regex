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

namespace PHPRegex\Explain;

use PHPRegex\Parser\AbstractNodeVisitor;

/**
 * Generates a human-readable explanation of the regex: one line per
 * construct, what a group or a branch holds indented under it.
 *
 * @extends AbstractNodeVisitor<string>
 */
final class TextExplainer extends AbstractNodeVisitor
{
    use WritesExplanations;

    private function render(Explanation $explanation, int $level = 0): string
    {
        $indent = str_repeat('  ', $level);
        $children = $explanation->children;

        $lines = match ($explanation->kind) {
            // The pattern stands at the top, and holds one piece, as a block does.
            ExplanationKind::Pattern => [$explanation->text, $this->render($children[0], 1)],
            ExplanationKind::Sequence => $this->each($children, $level),
            ExplanationKind::Alternation => $this->branches($children, $level),
            ExplanationKind::Block => [$indent.$explanation->text, $this->render($children[0], $level + 1), $indent.$explanation->end],
            ExplanationKind::Repetition => $this->repetition($explanation, $level),
            ExplanationKind::Conditional => $this->conditional($children, $level),
            ExplanationKind::Leaf => [$indent.$explanation->inline()],
        };

        return implode("\n", array_filter($lines, static fn (string $line): bool => '' !== $line));
    }

    /**
     * @param list<Explanation> $explanations
     *
     * @return list<string>
     */
    private function each(array $explanations, int $level): array
    {
        return array_map(fn (Explanation $explanation): string => $this->render($explanation, $level), $explanations);
    }

    /**
     * @param list<Explanation> $branches
     *
     * @return list<string>
     */
    private function branches(array $branches, int $level): array
    {
        $lines = [];
        foreach ($branches as $index => $branch) {
            $lines[] = str_repeat('  ', $level).(0 === $index ? 'EITHER' : 'OR');
            $lines[] = $this->render($branch, $level + 1);
        }

        return $lines;
    }

    /**
     * A construct on one line takes the count after it; anything longer is
     * a block.
     *
     * @return list<string>
     */
    private function repetition(Explanation $repetition, int $level): array
    {
        $indent = str_repeat('  ', $level);
        $repeated = $repetition->children[0];
        if (ExplanationKind::Leaf === $repeated->kind) {
            return [\sprintf('%s%s (%s)', $indent, $repeated->inline(), $repetition->text)];
        }

        return [
            \sprintf('%sQuantified group (%s)', $indent, $repetition->text),
            $this->render($repeated, $level + 1),
            $indent.$repetition->end,
        ];
    }

    /**
     * A condition on one line stands in the line of the IF; a lookaround
     * is a block under it.
     *
     * @param list<Explanation> $children
     *
     * @return list<string>
     */
    private function conditional(array $children, int $level): array
    {
        $indent = str_repeat('  ', $level);
        [$condition, $yes] = $children;
        $lines = ExplanationKind::Leaf === $condition->kind
            ? [\sprintf('%sIF (%s) THEN', $indent, $condition->inline())]
            : [$indent.'IF', $this->render($condition, $level + 1), $indent.'THEN'];
        $lines[] = $this->render($yes, $level + 1);

        if (isset($children[2])) {
            $lines[] = $indent.'ELSE';
            $lines[] = $this->render($children[2], $level + 1);
        }

        return $lines;
    }
}
