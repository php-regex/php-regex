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

namespace PHPRegex\Linter\Rule;

use PHPRegex\Linter\Rule\Support\EscapeJoin;
use PHPRegex\Linter\Rule\Support\LanguageQuestions;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\UnicodePropNode;

/**
 * Detects non-capturing groups that wrap a single atom and can be removed.
 * An empty group is group.empty's; a group that keeps an escape apart from
 * the digit after it, as in "(a)\1(?:0)", or braces from a count, as in
 * "a{(?:2)}", cannot go. A group a quantifier repeats goes only around one
 * character, as in "(?:a)+": without the group, "(?:\Qab\E)+" repeats the
 * "b" alone, "(?:é)+" without UTF mode the last byte of the letter, and
 * "(?:^)+" is refused.
 *
 * @internal
 */
final class RedundantGroupRule extends AbstractLintRule
{
    public function getRuleIds(): array
    {
        return ['regex.lint.group.redundant'];
    }

    public function getNodeTypes(): array
    {
        return [GroupNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof GroupNode) {
            return [];
        }

        if (GroupType::NonCapturing !== $node->type
            || ($node->child instanceof LiteralNode && '' === $node->child->value)
            || !$this->isRedundantGroup($node->child)
            || EscapeJoin::groupSeparatesAnEscape($node, $context)
            || (self::isQuantified($context) && !self::readsOneCharacter($node->child, $context))
        ) {
            return [];
        }

        return [new RuleViolation(
            'regex.lint.group.redundant',
            'Redundant non-capturing group; it can be removed without changing behavior.',
            $node->startPosition,
        )];
    }

    private function isRedundantGroup(NodeInterface $node): bool
    {
        if ($node instanceof SequenceNode) {
            if (1 !== \count($node->children)) {
                return false;
            }

            return $this->isRedundantGroup($node->children[0]);
        }

        if ($node instanceof AlternationNode || $node instanceof QuantifierNode) {
            return false;
        }

        return $node instanceof LiteralNode
            || $node instanceof CharTypeNode
            || $node instanceof CharClassNode
            || $node instanceof CharLiteralNode
            || $node instanceof DotNode
            || $node instanceof AnchorNode
            || $node instanceof AssertionNode
            || $node instanceof KeepNode
            || $node instanceof UnicodePropNode
            || $node instanceof PosixClassNode
            || $node instanceof ControlCharNode
            || $node instanceof CommentNode
            || $node instanceof CalloutNode
            || $node instanceof ScriptRunNode;
    }

    /**
     * Whether the atom reads one character, which a quantifier repeats
     * whole: a literal written as one character (not quoted with \Q, one
     * byte without UTF mode), an escape, a class, a dot or a property.
     */
    private static function readsOneCharacter(NodeInterface $node, LintContext $context): bool
    {
        if ($node instanceof SequenceNode) {
            return self::readsOneCharacter($node->children[0], $context); // never taken: the parser folds a one-item sequence into its item
        }

        if ($node instanceof LiteralNode) {
            $length = $context->pattern->unicodeMode ? mb_strlen($node->value, 'UTF-8') : \strlen($node->value);

            return 1 === $length && !str_contains(LanguageQuestions::text($node, $context), '\Q');
        }

        return $node instanceof CharTypeNode
            || $node instanceof CharClassNode
            || $node instanceof CharLiteralNode
            || $node instanceof DotNode
            || $node instanceof UnicodePropNode
            || $node instanceof ControlCharNode;
    }

    /**
     * Whether the group being checked is the operand of a quantifier, read
     * from the traversal cursor.
     */
    private static function isQuantified(LintContext $context): bool
    {
        $parents = $context->parents();

        return end($parents) instanceof QuantifierNode;
    }
}
