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

namespace PHPRegex\Parser\Hir;

/**
 * A node of the normalized form of a pattern: what the pattern matches, with
 * the syntax gone. Options are applied where they hold, a character, a class,
 * a dot or a caseless letter is one set of characters, groups that only
 * group are gone, and every quantifier is a repetition with its bounds.
 * What PCRE does in order stays in order: the alternatives, the greed of a
 * repetition, atomic groups.
 *
 * Each node carries the properties of its matches, worked out when it is
 * built. Positions are those of the AST node it comes from.
 *
 * @internal the form may change while the analyses move onto it
 */
abstract readonly class Hir
{
    public function __construct(
        public Properties $properties,
        public int $startPosition,
        public int $endPosition,
    ) {}

    /**
     * @return list<self>
     */
    abstract public function children(): array;
}
