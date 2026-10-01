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

namespace PhpRegex\Automata\Transform;

use PhpRegex\Automata\Exception\ComplexityException;
use PhpRegex\Automata\Options\SolverOptions;
use PhpRegex\Automata\Unicode\CodePointHelper;
use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\AnchorNode;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\Node\CalloutNode;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\ConditionalNode;
use PhpRegex\Parser\Node\ControlCharNode;
use PhpRegex\Parser\Node\DefineNode;
use PhpRegex\Parser\Node\DotNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\KeepNode;
use PhpRegex\Parser\Node\LimitMatchNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\NodeInterface;
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
 * Validates that a regex AST stays within the supported regular subset.
 *
 * @internal
 */
final class RegularSubsetValidator
{
    private string $pattern = '';

    private bool $unicode = false;

    /**
     * @throws \PhpRegex\Automata\Exception\ComplexityException
     */
    public function assertSupported(RegexNode $regex, string $pattern, SolverOptions $options): void
    {
        $this->pattern = $pattern;
        $this->unicode = \str_contains($regex->flags, 'u');

        $this->assertSupportedFlags($regex->flags);
        $this->assertNode($regex->pattern, false);
    }

    /**
     * @throws \PhpRegex\Automata\Exception\ComplexityException
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
     * @throws \PhpRegex\Automata\Exception\ComplexityException
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
            if (GroupType::LookaheadPositive === $node->type
                || GroupType::LookaheadNegative === $node->type
                || GroupType::LookbehindPositive === $node->type
                || GroupType::LookbehindNegative === $node->type
                || GroupType::ScanSubstring === $node->type
                || GroupType::InlineFlags === $node->type
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
     * @throws \PhpRegex\Automata\Exception\ComplexityException
     */
    private function assertCharType(CharTypeNode $node): void
    {
        $supported = ['d', 'D', 'w', 'W', 's', 'S'];
        if (!\in_array($node->value, $supported, true)) {
            $this->unsupported($node, 'Unsupported character type: '.$node->value.'.');
        }
    }

    /**
     * @throws \PhpRegex\Automata\Exception\ComplexityException
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
     * @throws \PhpRegex\Automata\Exception\ComplexityException
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
