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

/**
 * Generates a human-readable explanation of the regex: one line per
 * construct, what a group or a branch holds indented under it.
 *
 * Text output is limited to 1 MiB, including indentation. Oversized
 * explanations throw a \LengthException.
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

    /** Maximum bytes returned, including indentation and line separators. */
    private const MAX_OUTPUT_BYTES = 1_048_576;

    private function render(Explanation $explanation): string
    {
        $output = '';
        $this->writeExplanation($explanation, 0, $output);

        return $output;
    }

    /** Write each line once; enclosing constructs never copy their subtrees. */
    private function writeExplanation(Explanation $explanation, int $level, string &$output): void
    {
        $children = $explanation->children;
        switch ($explanation->kind) {
            case ExplanationKind::Pattern:
                $this->line($explanation->text, 0, $output);
                $this->writeExplanation($children[0], 1, $output);
                break;

            case ExplanationKind::Sequence:
                foreach ($children as $child) {
                    $this->writeExplanation($child, $level, $output);
                }
                break;

            case ExplanationKind::Alternation:
                foreach ($children as $index => $branch) {
                    $this->line(0 === $index ? 'EITHER' : 'OR', $level, $output);
                    $this->writeExplanation($branch, $level + 1, $output);
                }
                break;

            case ExplanationKind::Block:
                $this->line($explanation->text, $level, $output);
                $this->writeExplanation($children[0], $level + 1, $output);
                $this->line($explanation->end, $level, $output);
                break;

            case ExplanationKind::Repetition:
                if (ExplanationKind::Leaf === $children[0]->kind) {
                    $this->line($children[0]->inline().', '.$explanation->text, $level, $output);
                    break;
                }
                $this->line('Quantified group, '.$explanation->text, $level, $output);
                $this->writeExplanation($children[0], $level + 1, $output);
                $this->line($explanation->end, $level, $output);
                break;

            case ExplanationKind::Conditional:
                [$condition, $yes] = $children;
                if (ExplanationKind::Leaf === $condition->kind) {
                    $this->line('IF ('.$condition->inline().') THEN', $level, $output);
                } else {
                    $this->line('IF', $level, $output);
                    $this->writeExplanation($condition, $level + 1, $output);
                    $this->line('THEN', $level, $output);
                }
                $this->writeExplanation($yes, $level + 1, $output);
                if (isset($children[2])) {
                    $this->line('ELSE', $level, $output);
                    $this->writeExplanation($children[2], $level + 1, $output);
                }
                break;

            case ExplanationKind::Leaf:
                $this->line($explanation->inline(), $level, $output);
                break;
        }
    }

    private function line(string $text, int $level, string &$output): void
    {
        // Match the renderer's omission of empty lines, including empty trees.
        if ('' === $text && 0 === $level) {
            return;
        }

        $separator = '' === $output ? '' : "\n";
        $remaining = self::MAX_OUTPUT_BYTES - \strlen($output) - \strlen($separator) - \strlen($text);
        // Check indentation before allocating it, even for directly supplied ASTs.
        if ($remaining < 0 || $level > intdiv($remaining, 2)) {
            throw new \LengthException('Text explanation exceeds the 1048576-byte output limit.');
        }

        $output .= $separator.str_repeat('  ', $level).$text;
    }
}
