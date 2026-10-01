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

namespace PHPRegex\Parser\Cache;

use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ClassSetOperationNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Node\VersionConditionNode;

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
