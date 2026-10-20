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
 * Generates an HTML explanation of the regex: nested lists, each construct
 * an item that shows it as written beside its meaning, which its title
 * tooltip carries too.
 *
 * @extends AbstractNodeVisitor<string>
 */
final class HtmlExplainer extends AbstractNodeVisitor
{
    use WritesExplanations;

    private function render(Explanation $explanation): string
    {
        $children = $explanation->children;

        return match ($explanation->kind) {
            ExplanationKind::Pattern => \sprintf(
                "<div class=\"regex-explain\">\n<strong>%s:</strong>\n<ul>%s</ul>\n</div>",
                $this->e($explanation->text),
                $this->each($children),
            ),
            ExplanationKind::Sequence => $this->each($children),
            ExplanationKind::Alternation => implode("\n", array_map(
                fn (Explanation $branch, int $index): string => $this->block(0 === $index ? 'EITHER' : 'OR', $this->render($branch)),
                $children,
                array_keys($children),
            )),
            ExplanationKind::Block => \sprintf(
                "<li><span title=\"%s\"><strong>%1\$s:</strong></span>\n<ul>%s</ul>\n</li>",
                $this->e($explanation->text),
                $this->each($children),
            ),
            ExplanationKind::Repetition => ExplanationKind::Leaf === $children[0]->kind
                ? $this->leaf($children[0], ' ('.$explanation->text.')')
                : $this->block(\sprintf('Quantified group (%s)', $explanation->text), $this->render($children[0])),
            ExplanationKind::Conditional => $this->conditional($children),
            ExplanationKind::Leaf => $this->leaf($explanation),
        };
    }

    /**
     * @param list<Explanation> $explanations
     */
    private function each(array $explanations): string
    {
        return implode("\n", array_map($this->render(...), $explanations));
    }

    /**
     * An item headed by words in bold, the items it holds listed under.
     */
    private function block(string $heading, string $items): string
    {
        return \sprintf("<li><strong>%s:</strong>\n<ul>%s</ul>\n</li>", $this->e($heading), $items);
    }

    /**
     * A construct as written, in bold, beside its meaning, or its meaning in
     * bold: the whole line in the tooltip.
     *
     * @param string $after what follows it on its line, the count of a repetition
     */
    private function leaf(Explanation $leaf, string $after = ''): string
    {
        $shown = '' === $leaf->syntax
            ? '<strong>'.$this->e($leaf->text).'</strong>'
            : \sprintf('<strong>%s</strong> (%s)', $this->e($leaf->syntax), $this->e($leaf->text));

        return \sprintf(
            '<li><span title="%s">%s%s</span>%s</li>',
            $this->e($leaf->inline().$after),
            '' === $leaf->label ? '' : $this->e($leaf->label).': ',
            $shown,
            $this->e($after),
        );
    }

    /**
     * @param list<Explanation> $children
     */
    private function conditional(array $children): string
    {
        [$condition, $yes] = $children;
        $html = ExplanationKind::Leaf === $condition->kind
            ? \sprintf("<li><strong>IF</strong> (%s) <strong>THEN:</strong>\n", $this->e($condition->inline()))
            : \sprintf("<li><strong>IF:</strong>\n<ul>%s</ul>\n<strong>THEN:</strong>\n", $this->render($condition));
        $html .= \sprintf("<ul>%s</ul>\n", $this->render($yes));

        if (isset($children[2])) {
            $html .= \sprintf("<strong>ELSE:</strong>\n<ul>%s</ul>\n", $this->render($children[2]));
        }

        return $html.'</li>';
    }

    private function e(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
