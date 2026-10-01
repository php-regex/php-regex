<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Parser;

/**
 * What a walk does after a node, when the callback asks for more than going on.
 */
enum TraversalAction
{
    /**
     * The node's children are not walked; the walk goes on after them.
     */
    case SkipChildren;

    /**
     * The walk ends here.
     */
    case Stop;
}
