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

namespace PhpRegex\Parser\Cache;

use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\AnchorNode;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\Node\CalloutNode;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\ClassSetOperationNode;
use PhpRegex\Parser\Node\CommentNode;
use PhpRegex\Parser\Node\ConditionalNode;
use PhpRegex\Parser\Node\ControlCharNode;
use PhpRegex\Parser\Node\DefineNode;
use PhpRegex\Parser\Node\DotNode;
use PhpRegex\Parser\Node\ExtendedCharClassNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\KeepNode;
use PhpRegex\Parser\Node\LimitMatchNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\PcreVerbNode;
use PhpRegex\Parser\Node\PosixClassNode;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\RangeNode;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Parser\Node\ScriptRunNode;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\Node\SubroutineNode;
use PhpRegex\Parser\Node\UnicodePropNode;
use PhpRegex\Parser\Node\VersionConditionNode;

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
