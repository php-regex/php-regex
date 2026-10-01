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

namespace PHPRegex\Automata\Transform;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Unicode\CodePointHelper;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
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
 * Validates that a regex AST stays within the supported regular subset.
 *
 * @internal
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
