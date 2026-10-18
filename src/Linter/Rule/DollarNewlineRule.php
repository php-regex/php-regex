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

use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\Rule\Support\NodePredicates;
use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Text\Bytes;

/**
 * A "$" that ends a pattern anchored at its start, read without D and
 * without m: it also matches before a newline that ends the subject, so
 * "/^\d+$/" accepts "123\n".
 *
 * Reported only where the difference is certain: the alternative holding
 * the "$" starts with "^" (read without m) or "\A", or the A modifier
 * anchors it; nothing but comments follows the "$", and no subroutine call
 * runs it from elsewhere; what precedes it cannot end with the newline,
 * so that a subject the pattern matches, followed by that newline, is not
 * matched by the pattern itself; and no lookahead in the pattern can read
 * the newline the "$" leaves. "\Z" spells the same end on purpose and is
 * left alone, as is any "$" under (*ANY), whose newlines lie above ASCII.
 *
 * @internal
 */
final class DollarNewlineRule extends AbstractLintRule
{
    private const ID = 'regex.lint.anchor.dollarNewline';

    /**
     * The last character of a newline, under each convention but ANY.
     */
    private const NEWLINE_ENDS = ['LF' => "\n", 'CR' => "\r", 'CRLF' => "\n", 'ANYCRLF' => "\r\n", 'NUL' => "\0"];

    public function getRuleIds(): array
    {
        return [self::ID];
    }

    public function getNodeTypes(): array
    {
        return [AnchorNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof AnchorNode || '$' !== $node->value
            || $context->pattern->hasFlag('D') || str_contains($context->activeFlags(), 'm')
        ) {
            return [];
        }

        $newlineEnds = self::NEWLINE_ENDS[StartOptions::newline($context->pattern->source)] ?? null;
        if (null === $newlineEnds || !$context->endsThePatternForEveryCall($node)) {
            return [];
        }

        $parents = $context->parents();
        $root = $parents[0] ?? $node;
        if (self::holdsALookahead($root)) {
            return [];
        }

        $alternative = $root instanceof AlternationNode ? ($parents[1] ?? $node) : $root;
        if (!$context->pattern->hasFlag('A') && !$this->startsAnchored($alternative, $root, $context)) {
            return [];
        }

        $before = $this->charsBefore($node, $root, $context);
        if ($before->isUnknown()) {
            return [];
        }

        foreach (Bytes::split($newlineEnds) as $newlineEnd) {
            if ($before->intersects(ByteCharSet::fromChar($newlineEnd))) {
                return [];
            }
        }

        return [new RuleViolation(
            self::ID,
            '"$" also matches before a newline that ends the subject, so a value with a trailing newline passes.',
            $node->getStartPosition(),
            'Write \z to match at the very end only, or add the D modifier.',
            LintSeverity::Info,
            end: $node->getEndPosition(),
        )];
    }

    /**
     * Whether every way through the node starts with "^" read without m, or
     * with "\A", past the comments, the option settings and the start
     * options before it.
     */
    private function startsAnchored(NodeInterface $node, NodeInterface $root, LintContext $context): bool
    {
        if ($node instanceof AnchorNode) {
            return '^' === $node->value && !str_contains($context->flagsAt($node, $root, $context->pattern->flags), 'm');
        }

        if ($node instanceof AssertionNode) {
            return 'A' === $node->value;
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                if ($child instanceof CommentNode || NodePredicates::isStandaloneInlineFlagsGroup($child) || self::isStartOption($child, $context)) {
                    continue;
                }

                return $this->startsAnchored($child, $root, $context);
            }

            return false;
        }

        if ($node instanceof GroupNode) {
            return NodePredicates::isTransparentGroup($node->type) && $this->startsAnchored($node->child, $root, $context);
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alternative) {
                if (!$this->startsAnchored($alternative, $root, $context)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * The characters a match can end with right before the "$", each item
     * read under the flags in force where it stands; the zero-width items
     * (anchors, assertions, comments, option settings, lookbehinds) add
     * none. An item the sets cannot read leaves the set unknown.
     */
    private function charsBefore(AnchorNode $dollar, NodeInterface $root, LintContext $context): ByteCharSet
    {
        $set = ByteCharSet::empty();
        $child = $dollar;
        foreach (array_reverse($context->parents()) as $parent) {
            if ($parent instanceof SequenceNode) {
                // The child the walk comes from is one of the sequence's.
                for ($i = (int) array_search($child, $parent->children, true) - 1; $i >= 0; $i--) {
                    $item = $parent->children[$i];
                    if (self::isZeroWidth($item) || self::isStartOption($item, $context)) {
                        continue;
                    }

                    $set = $set->union($context->lastChars($item, $context->flagsAt($item, $root, $context->pattern->flags)));
                    if (!NodePredicates::isOptionalNode($item)) {
                        return $set;
                    }
                }
            }

            $child = $parent;
        }

        return $set;
    }

    private static function isZeroWidth(NodeInterface $node): bool
    {
        return $node instanceof AnchorNode
            || $node instanceof AssertionNode
            || $node instanceof CommentNode
            || ($node instanceof GroupNode && \in_array($node->type, [GroupType::LookbehindPositive, GroupType::LookbehindNegative], true))
            || NodePredicates::isStandaloneInlineFlagsGroup($node);
    }

    /**
     * An option of the run the pattern opens with, as "(*CRLF)".
     */
    private static function isStartOption(NodeInterface $node, LintContext $context): bool
    {
        return $node instanceof PcreVerbNode && $node->getEndPosition() <= \strlen(StartOptions::of($context->pattern->source));
    }

    /**
     * A lookahead may read the newline a "$" leaves, and make it count.
     */
    private static function holdsALookahead(NodeInterface $node): bool
    {
        if ($node instanceof GroupNode && \in_array($node->type, [GroupType::LookaheadPositive, GroupType::LookaheadNegative], true)) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::holdsALookahead($child)) {
                return true;
            }
        }

        return false;
    }
}
