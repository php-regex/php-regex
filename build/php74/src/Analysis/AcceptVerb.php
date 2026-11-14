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

namespace PHPRegex\Parser\Analysis;

use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;

/**
 * Where a "(*ACCEPT)" may end the match: "/(*sr:a(*ACCEPT))b/" matches "a".
 *
 * @internal
 */
final class AcceptVerb
{
    /**
     * The groups an "(*ACCEPT)" only ends, not the match.
     *
     * @var list<GroupType>|null
     */
    private static ?array $ASSERTIONS = null;

    /**
     * @return list<GroupType>
     */
    private static function ASSERTIONS(): array
    {
        return self::$ASSERTIONS ??= [
            GroupType::LookaheadPositive(),
            GroupType::LookaheadNegative(),
            GroupType::LookbehindPositive(),
            GroupType::LookbehindNegative(),
            GroupType::ScanSubstring(),
        ];
    }

    /**
     * Whether a "(*ACCEPT)" inside the node may end the match there: not one
     * in a lookaround, which only ends the assertion, nor in a (DEFINE) or a
     * group a subroutine call runs, which ends only the call.
     */
    public static function mayEndMatch(NodeInterface $node): bool
    {
        if ($node instanceof PcreVerbNode) {
            return 'ACCEPT' === $node->name;
        }

        switch (true) {
            case true === $node instanceof GroupNode:
                $children = \in_array($node->type, self::ASSERTIONS(), true) ? [] : [$node->child];
                break;
            case true === $node instanceof SequenceNode:
                $children = $node->children;
                break;
            case true === $node instanceof AlternationNode:
                $children = $node->alternatives;
                break;
            case true === $node instanceof QuantifierNode:
                $children = [$node->node];
                break;
            case true === $node instanceof ConditionalNode:
                $children = [$node->yes, $node->no];
                break;
            case true === $node instanceof ScriptRunNode:
                $children = null === $node->content ? [] : [$node->content];
                break;
            default:
                $children = [];
                break;
        }

        foreach ($children as $child) {
            if (self::mayEndMatch($child)) {
                return true;
            }
        }

        return false;
    }
}
