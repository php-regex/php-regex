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
 * well: "/a+(?>(?:)|b)a/" finds "aa", "/a+(?>|b)a/" nothing. So do the
 * brackets beside a repeat PCRE2 makes possessive as if the byte 0xFF were
 * no word character, where the tables of the target may hold it in "\w":
 * under fr_FR.ISO8859-1 "/(?:\xFF)+\w/" finds "\xFF\xFF", "/\xFF+\w/"
 * nothing.
 *
 * @internal
 */
final class PossessiveBrackets
{
    /**
     * The shape (see AutoPossession::shape()) of each pattern asked about,
     * kept as long as its tree is: a rule asks at each group it would
     * report, and reading it walks the whole pattern.
     *
     * @var \WeakMap<NodeInterface, list<string>>|null
     */
    private static ?\WeakMap $shapes = null;

    /**
     * The repeats PCRE2 may make possessive in each pattern asked about,
     * kept as long as its tree is.
     *
     * @var \WeakMap<NodeInterface, array<int, true>>|null
     */
    private static ?\WeakMap $madePossessive = null;

    private function __construct() {}

    /**
     * Whether the pattern with the group taken out, its body standing where
     * the group stood, may be read otherwise by PCRE2's auto-possessification:
     * a character repeated before such a group stands in one of the two,
     * and the brackets that decide it differ. "(?>)" in "/a(?>)b/" can go.
     * So may a pattern that, a group a repeat reads taken out, holds a
     * repeat PCRE2 may make possessive as if the byte 0xFF were no word
     * character (see AutoPossession::bracketsDecideBesideLastByte()), or a
     * group that stands where a call runs a repeat (see
     * AutoPossession::standsWhereACallRunsARepeat()): "(?:)" in
     * "/(a+(?:)c)x(?1)d/" stays.
     */
    public static function groupDecides(GroupNode $group, LintContext $context): bool
    {
        $pattern = $context->parents()[0] ?? $group;
        self::$shapes ??= new \WeakMap();

        // Taking a group out makes none the engine may skip: a pattern that
        // holds none has no such group for the brackets to decide.
        return ([] !== (self::$shapes[$pattern] ??= AutoPossession::shape($pattern, false))
            && AutoPossession::groupDecides($pattern, $group))
            || AutoPossession::bracketsDecideBesideLastByte($pattern, $context->pattern->flags, $context->target->locale)
            || AutoPossession::standsWhereACallRunsARepeat($pattern, $group);
    }

    /**
     * groupDecides() read where the engine reads: only the brackets PCRE2
     * reads on its way from a repeated character to a group it may skip
     * decide (see AutoPossession::groupDecidesBesideARepeat()). "(?:\\)" in
     * "/(?:\\)?define [a-z]++ (?:,x)*+ /" can go: the engine judges "\\?"
     * against the "d" after it and reads no further. A group that stands
     * where a call runs a repeat stays wherever it stands: "(?:b)" in
     * "/(a+(?:b))c(?1)d/" and "(?:a)" in "~x(?R)a|(?:a)*~".
     */
    public static function groupDecidesBesideARepeat(GroupNode $group, LintContext $context): bool
    {
        $pattern = $context->parents()[0] ?? $group;
        self::$shapes ??= new \WeakMap();

        return ([] !== (self::$shapes[$pattern] ??= AutoPossession::shape($pattern, false))
            && AutoPossession::groupDecidesBesideARepeat($pattern, $group))
            || AutoPossession::bracketsDecideBesideLastByte($pattern, $context->pattern->flags, $context->target->locale)
            || AutoPossession::standsWhereACallRunsARepeat($pattern, $group);
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
        $pattern = $context->parents()[0] ?? $node;
        self::$madePossessive ??= new \WeakMap();

        return self::$madePossessive[$pattern] ??= AutoPossession::repeatsBeforeSkippableGroups($pattern);
    }
}
