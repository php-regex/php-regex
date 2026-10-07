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

use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;

/**
 * Detects a lazy quantifier nothing follows: the match ends as soon as it
 * may, so the quantifier matches its minimum and "/a\d+?/" matches "a1" of
 * "a123". The same holds when only items that may match nothing, and hold
 * no anchor, lookaround or other test, follow it: "a+?b*" on "aab"
 * matches "a", the "b*" matching nothing after the first "a".
 *
 * @internal
 */
final class LazyEndRule extends AbstractLintRule
{
    private const ID = 'regex.lint.quantifier.lazyEnd';

    public function getRuleIds(): array
    {
        return [self::ID];
    }

    public function getNodeTypes(): array
    {
        return [QuantifierNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof QuantifierNode || QuantifierType::Possessive === $node->type) {
            return [];
        }

        $lazy = (QuantifierType::Lazy === $node->type) !== str_contains($context->activeFlags(), 'U');
        $bounds = QuantifierBounds::parse($node->quantifier);
        // A subroutine call runs the quantifier again where something may
        // follow it, and there it takes more than its minimum.
        if (!$lazy || null === $bounds || $bounds->min === $bounds->max) {
            return [];
        }

        $endsThePattern = $context->endsThePatternForEveryCall($node);
        if (!$endsThePattern && !$context->onlyEmptyMatchesFollowForEveryCall($node)) {
            return [];
        }

        $where = $endsThePattern ? 'ends the pattern' : 'is followed only by what may match nothing';

        return [new RuleViolation(
            self::ID,
            QuantifierType::Lazy === $node->type
                ? \sprintf('Lazy quantifier "%s?" %s, so it always matches its minimum.', $node->quantifier, $where)
                : \sprintf('Quantifier "%s" is lazy under the U flag and %s, so it always matches its minimum.', $node->quantifier, $where),
            $node->getStartPosition(),
            0 === $bounds->min
                ? 'Remove the quantified item, make the quantifier greedy, or anchor what must follow it.'
                : 'Write the minimum count, make the quantifier greedy, or anchor what must follow it.',
        )];
    }
}
