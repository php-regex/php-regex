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

/**
 * What a construct means, in the words both the text and the HTML
 * explanations show: each writes this tree out in its format, so a meaning
 * is worded once.
 *
 * @internal
 */
final class Explanation
{
    /**
     * @param string     $text     the meaning of a leaf, the heading of a block or of the pattern,
     *                             how many times a repetition repeats
     * @param string     $label    the kind of construct a leaf is, "Anchor" or "Character Type";
     *                             empty when its meaning says it, as a quoted literal does
     * @param string     $syntax   the construct as written, "^" or "\d", which the HTML shows
     *                             beside the meaning; empty when the meaning shows it
     * @param list<self> $children the pieces of a sequence, the branches of an alternation, what a
     *                             block, a pattern or a repetition holds; for a conditional, the
     *                             condition, the branch taken when it holds, then the other, if any
     * @param string     $end      the line that closes a block in the text explanation
     */
    public function __construct(
        public readonly ExplanationKind $kind,
        public readonly string $text = '',
        public readonly string $label = '',
        public readonly string $syntax = '',
        public readonly array $children = [],
        public readonly string $end = '',
    ) {}

    public static function leaf(string $text, string $label = '', string $syntax = ''): self
    {
        return new self(ExplanationKind::Leaf, $text, $label, $syntax);
    }

    /**
     * The explanation on one line: a leaf's label and meaning, or the
     * heading and the pieces of anything else, one after the other.
     */
    public function inline(): string
    {
        if (ExplanationKind::Leaf === $this->kind) {
            return '' === $this->label ? $this->text : $this->label.': '.$this->text;
        }

        $pieces = array_map(static fn (self $child): string => $child->inline(), $this->children);

        return implode(', ', '' === $this->text ? $pieces : [$this->text, ...$pieces]);
    }
}
