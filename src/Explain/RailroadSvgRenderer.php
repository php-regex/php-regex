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
use PHPRegex\Parser\Node\RegexNode;

/**
 * Draws a pattern as a standalone railroad-diagram SVG, its stylesheet
 * embedded. Accepted on the RegexNode of a parsed pattern it gives the
 * diagram; on any other node, the empty string: a diagram is drawn for a
 * whole pattern.
 *
 * @extends AbstractNodeVisitor<string>
 */
final class RailroadSvgRenderer extends AbstractNodeVisitor
{
    #[\Override]
    public function visitRegex(RegexNode $node): string
    {
        return (new RailroadLayoutVisitor())->visitRegex($node);
    }

    #[\Override]
    protected function defaultReturn(): string
    {
        return '';
    }
}
