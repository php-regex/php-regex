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

namespace PHPRegex\Linter\Rule\Support;

use PHPRegex\Linter\Rule\LintContext;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\NodeInterface;

/**
 * How a finding names the group it is about, so two findings of one rule
 * on one pattern read apart: by its number or its name, and the group as
 * the pattern writes it.
 *
 * @internal
 */
final class GroupLabel
{
    private function __construct() {}

    /**
     * A capturing group by its name or number, then as written:
     * 'capturing group 2 "(b+)"', 'named group "(?<run>a+)"'. Null for a
     * node that captures nothing or a group whose number is not known.
     */
    public static function of(NodeInterface $node, LintContext $context): ?string
    {
        if (!$node instanceof GroupNode) {
            return null;
        }

        if (null !== $node->name) {
            return \sprintf('named group "%s"', SourceQuote::of($node, $context));
        }

        $number = $context->groups->pcre?->numberOf($node);

        return null === $number ? null : \sprintf('capturing group %d "%s"', $number, SourceQuote::of($node, $context));
    }

    /**
     * A node as written, then where it starts in the body, for a node a
     * number or a name does not tell apart: '"(?:a+)" at offset 1'.
     */
    public static function at(NodeInterface $node, LintContext $context): string
    {
        return \sprintf('"%s" at offset %d', SourceQuote::of($node, $context), $node->getStartPosition());
    }
}
