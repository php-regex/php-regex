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
use PHPRegex\Linter\Rule\Support\LanguageQuestions;
use PHPRegex\Linter\Rule\Support\NodePredicates;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\SequenceNode;

/**
 * A style rule: a positive lookahead that ends a lookahead asserts what its
 * body written in its place asserts, "(?=a(?=b))" what "(?=ab)" does, the
 * outer lookahead being positive or negative; so does a positive lookbehind
 * that starts a lookbehind, "(?<=(?<=a)b)" and "(?<=ab)". Nothing in the
 * outer assertion follows the inner one in the direction it reads, and an
 * assertion is atomic, so the inner one only adds a test where the outer one
 * would read on anyway.
 *
 * Silent where the rewrite would not hold: an inner negative assertion, one
 * that does not end (or start) its parent, a quantified one, a non-atomic
 * one on either side ("(*napla:...)"), a body holding a verb, a callout or
 * "\K", and a lookbehind whose body holds alternatives, which nested in the
 * parent's body would need PCRE2 10.43 if their lengths differ. The body
 * keeps a group around it when it holds alternatives or sets an option, so
 * that neither reaches what surrounds it.
 *
 * @internal
 */
final class RedundantLookaroundRule extends AbstractLintRule
{
    private const ID = 'regex.lint.lookaround.redundant';

    private const AHEAD = [GroupType::LookaheadPositive, GroupType::LookaheadNegative];

    private const BEHIND = [GroupType::LookbehindPositive, GroupType::LookbehindNegative];

    public function getRuleIds(): array
    {
        return [self::ID];
    }

    public function getNodeTypes(): array
    {
        return [GroupNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof GroupNode || '*' === $node->flags) {
            return [];
        }

        $behind = \in_array($node->type, self::BEHIND, true);
        if (!$behind && !\in_array($node->type, self::AHEAD, true)) {
            return [];
        }

        $issues = [];
        $branches = $node->child instanceof AlternationNode ? $node->child->alternatives : [$node->child];
        foreach ($branches as $branch) {
            $inner = self::edgeItem($branch, $behind);
            if (!$inner instanceof GroupNode || !$this->isInlinable($inner, $behind)) {
                continue;
            }

            $issues[] = $this->violation($node, $inner, $behind, $context);
        }

        return $issues;
    }

    private function isInlinable(GroupNode $inner, bool $behind): bool
    {
        if ('*' === $inner->flags || ($behind ? GroupType::LookbehindPositive : GroupType::LookaheadPositive) !== $inner->type) {
            return false;
        }

        return !($behind && $inner->child instanceof AlternationNode) && !self::holdsAControlItem($inner->child);
    }

    private function violation(GroupNode $outer, GroupNode $inner, bool $behind, LintContext $context): RuleViolation
    {
        $body = LanguageQuestions::text($inner->child, $context);
        if ($inner->child instanceof AlternationNode || self::setsAnOption($inner->child)) {
            $body = '(?:'.$body.')';
        }

        $outerStart = $outer->getStartPosition();
        $written = LanguageQuestions::text($outer, $context);
        $rewrite = substr($written, 0, $inner->getStartPosition() - $outerStart).$body.substr($written, $inner->getEndPosition() - $outerStart);

        return new RuleViolation(
            self::ID,
            \sprintf(
                '"%s" %s, which can hold its body: "%s" asserts the same.',
                LanguageQuestions::text($inner, $context),
                $behind ? 'starts a lookbehind' : 'ends a lookahead',
                $rewrite,
            ),
            $inner->getStartPosition(),
            \sprintf('Write "%s".', $rewrite),
            LintSeverity::Style,
        );
    }

    /**
     * The last item of the branch, or its first for a lookbehind, past the
     * comments.
     */
    private static function edgeItem(NodeInterface $branch, bool $first): ?NodeInterface
    {
        if (!$branch instanceof SequenceNode) {
            return $branch;
        }

        $items = $first ? $branch->children : array_reverse($branch->children);
        foreach ($items as $item) {
            if (!$item instanceof CommentNode) {
                return $item;
            }
        }

        return null;
    }

    /**
     * A standalone option setting at the top of the body, as the "(?i)" of
     * "(?i)a", which would reach past the body once it is written inline.
     */
    private static function setsAnOption(NodeInterface $body): bool
    {
        foreach ($body instanceof SequenceNode ? $body->children : [$body] as $item) {
            if (NodePredicates::isStandaloneInlineFlagsGroup($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A verb, a callout or "\K", which act on the assertion around them.
     */
    private static function holdsAControlItem(NodeInterface $node): bool
    {
        if ($node instanceof PcreVerbNode || $node instanceof CalloutNode || $node instanceof KeepNode) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::holdsAControlItem($child)) {
                return true;
            }
        }

        return false;
    }
}
