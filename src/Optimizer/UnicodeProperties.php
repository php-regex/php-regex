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

namespace PHPRegex\Optimizer;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Syntax\StartOptions;

/**
 * Whether "\d", "\s" and "\w" read Unicode properties in a pattern: under
 * "/u", or a "(*UCP)" in the run of options the pattern opens with, the
 * only place PCRE reads it. They then match more than the ASCII classes
 * they abbreviate: "\d" takes U+0663 under "(*UTF)(*UCP)".
 *
 * @internal
 */
final class UnicodeProperties
{
    public static function on(RegexNode $ast): bool
    {
        return str_contains($ast->flags, 'u')
            || self::hasUcpOption($ast)
            || 1 === LibraryPcre::match('/^(?:\(\*[A-Z_0-9]++(?:=\d++)?\))*?\(\*UCP\)/', $ast->source ?? '');
    }

    private static function hasUcpOption(RegexNode $ast): bool
    {
        // Transformed and caller-built trees need not carry source text.
        // Read only the opening run, including the first alternative's prefix.
        $pending = [$ast->pattern];
        while ([] !== $pending) {
            $node = array_pop($pending);
            if ($node instanceof SequenceNode) {
                array_push($pending, ...array_reverse($node->children));
            } elseif ($node instanceof AlternationNode) {
                $pending = isset($node->alternatives[0]) ? [$node->alternatives[0]] : [];
            } elseif ($node instanceof LimitMatchNode) {
                continue;
            } elseif ($node instanceof PcreVerbNode && [] !== StartOptions::items('(*'.$node->verb.')')) {
                if ('UCP' === $node->verb) {
                    return true;
                }
            } else {
                break;
            }
        }

        return false;
    }
}
