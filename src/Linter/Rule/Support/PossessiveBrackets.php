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

use PHPRegex\Automata\Transform\AutoPossession;
use PHPRegex\Linter\Rule\LintContext;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\NodeInterface;

/**
 * Whether taking a group out would change which repeats PCRE2 makes
 * possessive. PCRE2 makes a character repeated before a possessive or
 * atomic group that may match nothing possessive, as if the group could
 * not be skipped, and the brackets decide which: "/a+(?:b)?+a/" finds
 * nothing in "aa" where "/a+b?+a/" finds it, and "/(?:a)+(?:b)?+a/" finds
 * "aa" where "/a+(?:b)?+a/" does not, with and without the JIT, from
 * PCRE2 10.34 to 10.49. An empty group the engine enters decides it as
 * well: "/a+(?>(?:)|b)a/" finds "aa", "/a+(?>|b)a/" nothing.
 *
 * @internal
 */
final class PossessiveBrackets
{
    private function __construct() {}

    /**
     * Whether the pattern with the group taken out, its body standing where
     * the group stood, may be read otherwise by PCRE2's auto-possessification:
     * a character repeated before such a group stands in one of the two,
     * and the brackets that decide it differ. "(?>)" in "/a(?>)b/" can go.
     */
    public static function groupDecides(GroupNode $group, LintContext $context): bool
    {
        return AutoPossession::groupDecides($context->parents()[0] ?? $group, $group);
    }

    /**
     * The repeats of one character PCRE2 may make possessive in the pattern
     * the node stands in, by the offset each starts at: dropping one, or
     * fixing its count, gives back what PCRE2 took from the repeat before
     * it. "/[ab]{2,}a?(?:b){0,2}+a/" finds nothing in "aaa", where
     * "/[ab]{2,}(?:b){0,2}+a/" finds it.
     *
     * @return array<int, true>
     */
    public static function repeatsMadePossessive(NodeInterface $node, LintContext $context): array
    {
        return AutoPossession::repeatsBeforeSkippableGroups($context->parents()[0] ?? $node);
    }
}
