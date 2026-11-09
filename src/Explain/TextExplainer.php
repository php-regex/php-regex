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
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Printer\DisplayEscaper;

/**
 * Generates a human-readable explanation of the regex: one line per
 * construct, what a group or a branch holds indented under it.
 *
 * @extends AbstractNodeVisitor<string>
 */
final class TextExplainer extends AbstractNodeVisitor
{
    use WritesExplanations;

    /**
     * @param PcreTarget|null $target the PHP, PCRE2 release and locale the
     *                                pattern is judged for: what moved across
     *                                releases or locales, such as "\w", is
     *                                explained as that engine reads it; without
     *                                one, the explanation names the release or
     *                                the locale the reading depends on
     */
    public function __construct(private readonly ?PcreTarget $target = null) {}

    private function render(Explanation $explanation): string
    {
        $budget = new RenderingBudget();
        $lines = [];
        foreach ($this->renderLines($explanation) as [$text, $level]) {
            $budget->reserve(2 * $level + \strlen($text) + ([] === $lines ? 0 : 1));
            $lines[] = str_repeat('  ', $level).$text;
        }

        return implode("\n", $lines);
    }

    /**
     * Yield unindented lines so the budget is checked before expansion.
     *
     * @return \Generator<int, array{string, int}>
     */
    private function renderLines(Explanation $explanation, int $level = 0): \Generator
    {
        $children = $explanation->children;
        switch ($explanation->kind) {
            case ExplanationKind::Pattern:
                yield [$explanation->text, 0];
                yield from $this->renderLines($children[0], 1);

                break;
            case ExplanationKind::Sequence:
                foreach ($children as $child) {
                    yield from $this->renderLines($child, $level);
                }

                break;
            case ExplanationKind::Alternation:
                foreach ($children as $index => $branch) {
                    yield [0 === $index ? 'EITHER' : 'OR', $level];
                    yield from $this->renderLines($branch, $level + 1);
                }

                break;
            case ExplanationKind::Block:
                yield [$explanation->text, $level];
                yield from $this->renderLines($children[0], $level + 1);
                yield [$explanation->end, $level];

                break;
            case ExplanationKind::Repetition:
                if (ExplanationKind::Leaf === $children[0]->kind) {
                    yield [$this->inline($children[0]).', '.$explanation->text, $level];
                } else {
                    yield ['Quantified group, '.$explanation->text, $level];
                    yield from $this->renderLines($children[0], $level + 1);
                    yield [$explanation->end, $level];
                }

                break;
            case ExplanationKind::Conditional:
                if (ExplanationKind::Leaf === $children[0]->kind) {
                    yield ['IF ('.$this->inline($children[0]).') THEN', $level];
                } else {
                    yield ['IF', $level];
                    yield from $this->renderLines($children[0], $level + 1);
                    yield ['THEN', $level];
                }
                yield from $this->renderLines($children[1], $level + 1);
                if (isset($children[2])) {
                    yield ['ELSE', $level];
                    yield from $this->renderLines($children[2], $level + 1);
                }

                break;
            default: // ExplanationKind::Leaf
                yield [$this->inline($explanation), $level];

                break;
        }
    }

    private function inline(Explanation $explanation): string
    {
        // Keep existing explanation spellings (such as \d) while escaping
        // controls before the renderer adds its own indentation and newlines.
        return str_replace('\\\\', '\\', DisplayEscaper::escapeText($explanation->inline()));
    }
}
