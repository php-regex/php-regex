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

namespace PhpRegex\Parser\Node;

use PhpRegex\Parser\NodeVisitorInterface;

/**
 * A Perl extended class, "(?[ \p{L} - [aeiou] ])", PCRE2 10.45: one
 * character of the set its expression builds from classes, types,
 * properties, escaped characters and set operations.
 */
final readonly class ExtendedCharClassNode extends AbstractNode
{
    /**
     * @param string $text the class as written, "(?[" to "])"; empty for a
     *                     class built without a pattern
     */
    public function __construct(
        public NodeInterface $expression,
        int $startPosition,
        int $endPosition,
        public string $text = '',
    ) {
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitExtendedCharClass($this);
    }

    #[\Override]
    public function getChildren(): array
    {
        return [$this->expression];
    }
}
