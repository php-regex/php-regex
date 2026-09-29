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
use RegexParser\Node\ClassOperationNode;
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
 * Decodes the compiled cache payload string produced by the Regex service
 * into a RegexNode instance, without executing the payload.
 *
 * The payload is a small PHP script whose return value is an
 * unserialize() call; this decoder extracts the serialized string and
 * unserializes it with a class allowlist.
 */
final class CachePayloadDecoder
{
    /**
     * The classes a cached tree is made of, and the only ones unserialized.
     *
     * @internal
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
        ClassOperationNode::class,
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

    public static function decode(string $content): ?RegexNode
    {
        $serialized = self::extractSerializedString($content);
        if (null === $serialized) {
            return null;
        }

        $value = @unserialize($serialized, ['allowed_classes' => self::NODE_CLASSES]);

        return $value instanceof RegexNode ? $value : null;
    }

    /**
     * Extracts the serialized AST string from the generated payload without executing it.
     */
    private static function extractSerializedString(string $content): ?string
    {
        $code = ltrim($content);
        if (str_starts_with($code, '<?php')) {
            $code = substr($code, 5);
        }

        $offset = stripos($code, 'unserialize(');
        if (false === $offset) {
            return null;
        }

        $argumentBlock = substr($code, $offset + \strlen('unserialize('));
        $commaPos = strpos($argumentBlock, ',');
        if (false === $commaPos) {
            return null;
        }

        $argument = trim(substr($argumentBlock, 0, $commaPos));
        if ('' === $argument) {
            return null;
        }

        if (\in_array($argument[0], ["'", '"'], true) && $argument[0] === substr($argument, -1)) {
            $argument = substr($argument, 1, -1);
        }

        return stripcslashes($argument);
    }
}
