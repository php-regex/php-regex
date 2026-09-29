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

namespace RegexParser\Automata\Transform;

use RegexParser\Automata\Options\SolverOptions;
use RegexParser\Automata\Unicode\CodePointHelper;
use RegexParser\Exception\ComplexityException;
use RegexParser\Node\AlternationNode;
use RegexParser\Node\AnchorNode;
use RegexParser\Node\AssertionNode;
use RegexParser\Node\BackrefNode;
use RegexParser\Node\CalloutNode;
use RegexParser\Node\CharClassNode;
use RegexParser\Node\CharLiteralNode;
use RegexParser\Node\CharTypeNode;
use RegexParser\Node\ClassOperationNode;
use RegexParser\Node\ConditionalNode;
use RegexParser\Node\ControlCharNode;
use RegexParser\Node\DefineNode;
use RegexParser\Node\DotNode;
use RegexParser\Node\GroupNode;
use RegexParser\Node\GroupType;
use RegexParser\Node\KeepNode;
use RegexParser\Node\LimitMatchNode;
use RegexParser\Node\LiteralNode;
use RegexParser\Node\NodeInterface;
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
 * Validates that a regex AST stays within the supported regular subset.
 */
final class RegularSubsetValidator
{
    private string $pattern = '';

    private bool $unicode = false;

    /**
     * @throws ComplexityException
     */
    public function assertSupported(RegexNode $regex, string $pattern, SolverOptions $options): void
    {
        $this->pattern = $pattern;
        $this->unicode = \str_contains($regex->flags, 'u');

        $this->assertSupportedFlags($regex->flags);
        $this->assertNode($regex->pattern, false);
    }

    /**
     * @throws ComplexityException
     */
    private function assertSupportedFlags(string $flags): void
    {
        $unsupported = [];
        $allowed = ['i', 's', 'u'];
        foreach (\str_split($flags) as $flag) {
            if (!\in_array($flag, $allowed, true)) {
                $unsupported[] = $flag;
            }
        }

        if ([] !== $unsupported) {
            throw new ComplexityException('Unsupported regex flags for automata: '.\implode(', ', $unsupported).'.');
        }
    }

    /**
     * @throws ComplexityException
     */
    private function assertNode(NodeInterface $node, bool $inCharClass): void
    {
        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                $this->assertNode($child, $inCharClass);
            }

            return;
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alternative) {
                $this->assertNode($alternative, $inCharClass);
            }

            return;
        }

        if ($node instanceof GroupNode) {
            if (GroupType::T_GROUP_LOOKAHEAD_POSITIVE === $node->type
                || GroupType::T_GROUP_LOOKAHEAD_NEGATIVE === $node->type
                || GroupType::T_GROUP_LOOKBEHIND_POSITIVE === $node->type
                || GroupType::T_GROUP_LOOKBEHIND_NEGATIVE === $node->type
                || GroupType::T_GROUP_SCAN_SUBSTRING === $node->type
                || GroupType::T_GROUP_INLINE_FLAGS === $node->type
            ) {
                $this->unsupported($node, 'Unsupported group type: '.$node->type->value.'.');
            }

            $this->assertNode($node->child, $inCharClass);

            return;
        }

        if ($node instanceof QuantifierNode) {
            $this->assertNode($node->node, $inCharClass);

            return;
        }

        if ($node instanceof LiteralNode) {
            if ($inCharClass && 1 !== $this->literalLength($node->value, $node)) {
                $this->unsupported($node, 'Multi-character literals are not supported in character classes.');
            }

            return;
        }

        if ($node instanceof CharLiteralNode) {
            return;
        }

        if ($node instanceof ControlCharNode) {
            return;
        }

        if ($node instanceof CharTypeNode) {
            $this->assertCharType($node);

            return;
        }

        if ($node instanceof CharClassNode) {
            $this->assertNode($node->expression, true);

            return;
        }

        if ($node instanceof RangeNode) {
            $this->assertRangeEndpoint($node->start);
            $this->assertRangeEndpoint($node->end);

            return;
        }

        if ($node instanceof ClassOperationNode) {
            $this->assertNode($node->left, true);
            $this->assertNode($node->right, true);

            return;
        }

        if ($node instanceof AnchorNode) {
            if (!\in_array($node->value, ['^', '$'], true)) {
                $this->unsupported($node, 'Unsupported anchor: '.$node->value.'.');
            }

            return;
        }

        if ($node instanceof DotNode) {
            return;
        }

        if ($node instanceof PosixClassNode
            || $node instanceof UnicodePropNode
            || $node instanceof AssertionNode
            || $node instanceof BackrefNode
            || $node instanceof ConditionalNode
            || $node instanceof SubroutineNode
            || $node instanceof ScriptRunNode
            || $node instanceof VersionConditionNode
            || $node instanceof PcreVerbNode
            || $node instanceof DefineNode
            || $node instanceof LimitMatchNode
            || $node instanceof CalloutNode
            || $node instanceof KeepNode
        ) {
            $this->unsupported($node, 'Unsupported regex feature in automata conversion.');
        }

        $this->unsupported($node, 'Unsupported regex node in automata conversion.');
    }

    /**
     * @throws ComplexityException
     */
    private function assertCharType(CharTypeNode $node): void
    {
        $supported = ['d', 'D', 'w', 'W', 's', 'S'];
        if (!\in_array($node->value, $supported, true)) {
            $this->unsupported($node, 'Unsupported character type: '.$node->value.'.');
        }
    }

    /**
     * @throws ComplexityException
     */
    private function assertRangeEndpoint(NodeInterface $node): void
    {
        if ($node instanceof LiteralNode) {
            if (1 !== $this->literalLength($node->value, $node)) {
                $this->unsupported($node, 'Invalid range endpoint in character class.');
            }

            return;
        }

        if ($node instanceof CharLiteralNode || $node instanceof ControlCharNode) {
            return;
        }

        $this->unsupported($node, 'Unsupported range endpoint in character class.');
    }

    /**
     * @throws ComplexityException
     */
    private function unsupported(NodeInterface $node, string $message): never
    {
        throw new ComplexityException($message, $node->getStartPosition(), $this->pattern);
    }

    private function literalLength(string $value, NodeInterface $node): int
    {
        if (!$this->unicode) {
            return \strlen($value);
        }

        if (!CodePointHelper::isValidUtf8($value)) {
            $this->unsupported($node, 'Invalid UTF-8 literal in /u pattern.');
        }

        $chars = \preg_split('//u', $value, -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $chars) {
            $this->unsupported($node, 'Invalid UTF-8 literal in /u pattern.');
        }

        return \count($chars);
    }
}
