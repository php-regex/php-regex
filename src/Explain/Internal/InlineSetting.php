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

namespace PHPRegex\Explain\Internal;

use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;

/**
 * A bare "(?i)", which PCRE applies from where it stands to the end of the
 * group around it, or of the pattern.
 *
 * The tree holds it as an inline-flags group over an empty literal, as it
 * holds an empty scope "(?i:)"; only the span as written tells the two
 * apart, and the empty scope switches nothing for what follows it.
 *
 * @internal
 */
final class InlineSetting
{
    public static function is(GroupNode $node): bool
    {
        return GroupType::InlineFlags === $node->type
            && $node->child instanceof LiteralNode
            && '' === $node->child->value
            && $node->getEndPosition() - $node->getStartPosition() === \strlen($node->flags ?? '') + 3;
    }

    /**
     * How far the setting holds: to the end of the group it stands in, or of
     * the pattern when it stands in none.
     */
    public static function describe(GroupNode $node, bool $inGroup): string
    {
        return \sprintf(
            "Inline flags '%s' (from here to the end of the %s)",
            $node->flags ?? '',
            $inGroup ? 'group' : 'pattern',
        );
    }
}
