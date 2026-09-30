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

namespace RegexParser\Cache;

use RegexParser\Node\AlternationNode;
use RegexParser\Node\AnchorNode;
use RegexParser\Node\AssertionNode;
use RegexParser\Node\BackrefNode;
use RegexParser\Node\CalloutNode;
use RegexParser\Node\CharClassNode;
use RegexParser\Node\CharLiteralNode;
use RegexParser\Node\CharTypeNode;
use RegexParser\Node\ClassSetOperationNode;
use RegexParser\Node\CommentNode;
use RegexParser\Node\ConditionalNode;
use RegexParser\Node\ControlCharNode;
use RegexParser\Node\DefineNode;
use RegexParser\Node\DotNode;
use RegexParser\Node\ExtendedCharClassNode;
use RegexParser\Node\GroupNode;
use RegexParser\Node\KeepNode;
use RegexParser\Node\LimitMatchNode;
use RegexParser\Node\LiteralNode;
use RegexParser\Node\PcreVerbNode;
use RegexParser\Node\PosixClassNode;
use RegexParser\Node\QuantifierNode;
use RegexParser\Node\RangeNode;
use RegexParser\Node\RegexNode;
use RegexParser\Node\ScriptRunNode;
use RegexParser\Node\SequenceNode;
use RegexParser\Node\SubroutineNode;
use RegexParser\Node\UnicodePropNode;
use RegexParser\Node\VersionConditionNode;

/**
 * Turns a tree into the string a cache stores, and back: data only, read
 * with unserialize() restricted to the classes a tree is made of, so a
 * stored value that is anything else comes back as nothing.
 *
 * @internal
 */
final class AstSerializer
{
    /**
     * The classes a cached tree is made of, and the only ones unserialized.
     */
    public const NODE_CLASSES = [
        RegexNode::class,
        AlternationNode::class,
        AnchorNode::class,
        AssertionNode::class,
        BackrefNode::class,
        CalloutNode::class,
        CharClassNode::class,
        CharLiteralNode::class,
        CharTypeNode::class,
        ClassSetOperationNode::class,
        CommentNode::class,
        ConditionalNode::class,
        ControlCharNode::class,
        DefineNode::class,
        DotNode::class,
        ExtendedCharClassNode::class,
        GroupNode::class,
        KeepNode::class,
        LimitMatchNode::class,
        LiteralNode::class,
        PcreVerbNode::class,
        PosixClassNode::class,
        QuantifierNode::class,
        RangeNode::class,
        ScriptRunNode::class,
        SequenceNode::class,
        SubroutineNode::class,
        UnicodePropNode::class,
        VersionConditionNode::class,
    ];

    public static function serialize(RegexNode $ast): string
    {
        return serialize($ast);
    }

    public static function unserialize(string $data): ?RegexNode
    {
        // A class outside the list comes back incomplete, and a node property
        // refuses it: a tree someone altered is a miss, not a failure.
        try {
            $value = @unserialize($data, ['allowed_classes' => self::NODE_CLASSES]);
        } catch (\TypeError) {
            return null;
        }

        return $value instanceof RegexNode ? $value : null;
    }
}
