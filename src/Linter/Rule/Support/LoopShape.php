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
use PHPRegex\Parser\Analysis\ByteCharSet;
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
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\UnicodePropNode;

/**
 * Shapes of a loop the engine cannot backtrack into more than linearly,
 * shared by the nested-loop rules.
 *
 * @internal
 */
final class LoopShape
{
    /**
     * Groups that only wrap the iteration, without changing how it matches.
     */
    private const PLAIN_GROUPS = [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named];

    /**
     * \n, \x0B, \x0C, \r, \x85, and U+2028 and U+2029 under u.
     */
    private const VERTICAL_WHITESPACE = [0x0A, 0x0B, 0x0C, 0x0D, 0x85, 0x2028, 0x2029];

    /**
     * The ways a pattern every attempt of which starts at one place may be
     * read in, past which the nested-loop rule speaks of a counted loop:
     * see isReadInAFewWays().
     */
    private const MAX_WAYS = 2048;

    /**
     * The ways a pattern a search may start anywhere may be read in, past
     * which the nested-loop rule speaks of a counted loop.
     */
    private const MAX_WAYS_FROM_ANY_START = 7;

    private function __construct() {}

    /**
     * Whether the loop is the last thing the pattern matches and one
     * iteration is enough: the first way through is the match, and a later
     * iteration that fails just ends the loop. (a+)+ on a{28}b takes 6
     * steps, (a+)+$ on a{20}b 2 621 440.
     *
     * A minimum above one is left out: the engine retries every split of a
     * run too short to reach it, (a+){20,} on a{18}b takes 393 217 steps. A
     * subroutine call runs the loop again where something follows it.
     */
    public static function isTrailingLoopFromOneIteration(QuantifierNode $loop, LintContext $context): bool
    {
        [$min] = QuantifierMath::parseRange($loop);

        return $min <= 1 && $context->endsThePatternForEveryCall($loop);
    }

    /**
     * Whether the loop runs at most twice over one greedy run of a literal
     * character, right before the end of the subject: "(a+){1,2}$". PCRE
     * then gives the last run no way back and the loop stays linear (152 to
     * 602 steps for a{50}c to a{200}c). The bound alone is not enough:
     * "([ab]+){1,2}$", "(a+?){1,2}$", "(a+){1,2}$" under m and
     * "(?:a+){1,2}(?=b)" each take a quadratic number of steps.
     *
     * So do a run made lazy by U, a run of a vertical whitespace character,
     * before which "$" also matches, and any pattern that starts with a
     * verb, such as (*NO_AUTO_POSSESS) or a newline convention, which
     * changes how the run is compiled or where "$" matches.
     */
    public static function isShortRunBeforeTheEnd(QuantifierNode $loop, LintContext $context): bool
    {
        [, $max] = QuantifierMath::parseRange($loop);
        $inner = self::unwrapPlainGroups($loop->node);
        if (null === $max || $max > 2 || !$inner instanceof QuantifierNode || QuantifierType::Lazy === $inner->type) {
            return false;
        }

        $flags = $context->activeFlags();
        $codePoint = match (true) {
            $inner->node instanceof LiteralNode && 1 === \strlen($inner->node->value) => \ord($inner->node->value),
            $inner->node instanceof CharLiteralNode => $inner->node->codePoint,
            $inner->node instanceof CharClassNode && !$inner->node->isNegated
                && $inner->node->expression instanceof LiteralNode
                && 1 === \strlen($inner->node->expression->value) => \ord($inner->node->expression->value),
            $inner->node instanceof CharClassNode && !$inner->node->isNegated
                && $inner->node->expression instanceof CharLiteralNode => $inner->node->expression->codePoint,
            default => null,
        };

        return null !== $codePoint
            && !\in_array($codePoint, self::VERTICAL_WHITESPACE, true)
            && !str_contains($flags, 'm')
            && !str_contains($flags, 'U')
            && !self::patternStartsWithVerb($context)
            && $context->endsThePatternForEveryCall($loop, true);
    }

    /**
     * Whether the loop is counted, and the pattern around it with it, and
     * the engine reads the pattern in a few ways: however long the subject,
     * one attempt then tries a number of ways the pattern alone bounds, and
     * no subject grows it. The whole pattern is counted, as what comes
     * before the loop decides how often it is entered and what comes after
     * it how often it is tried again: "/^(a?){0,10}(a?){0,10}$/", each
     * loop read in 2,047 ways, runs past PHP's default pcre.backtrack_limit
     * on 7 "a" then "!", and "/^\w*(a?){0,10}$/" on 400 "a" then "!".
     *
     * The ways are counted from the pattern. Items in a row multiply
     * theirs; branches add theirs, or count as the widest of them where no
     * two can start alike. A repeat the next character tells to go on or
     * to stop, its body matching no empty text and starting with no byte
     * that can follow it, is read one way for each way of its body, each
     * time it runs: "[ZJ]{0,3}M" is read one way. One that may stop where
     * it could go on is read once for each count it may stop at: "a{0,3}a"
     * in four ways. Items that end the pattern and cannot fail are tried
     * once, and their ways add to those of what comes before them.
     *
     * The bounds are set from PCRE2 10.49, the interpreter with its start
     * optimizations off, by the smallest pcre.backtrack_limit under which
     * one attempt finishes on the costliest subject a search finds.
     *
     * Where every attempt starts at one place, the pattern opening with "^"
     * read without m, "\A" or "\G" in each branch, or under the A
     * modifier, the attempt is the cost, and 2,048 ways are allowed: it
     * stays under a hundredth of the default pcre.backtrack_limit.
     * "/^(a?){0,10}$/", read in 2,047 ways, takes 2,559 steps, its lazy
     * twin 4,094, and "/^(a{0,4}){0,4}$/", in 781, takes 438; past the
     * bound, "/^(a?){0,12}$/", in 8,191 ways, takes 10,239 steps,
     * "/^(a{0,6}){0,6}$/" 56,024, and "/^(a{0,8}){0,8}b/" and
     * "/^(a{0,16}){0,16}$/" run past the limit on 17 and 7 "a". The bounds
     * alone tell nothing: "/^(a?a?a?a?a?a?){0,4}$/" runs past it on 8 "a".
     * Of 418 random patterns of that kind left out, the costliest takes
     * 5,117 steps.
     *
     * A search that may start anywhere pays the attempt at every offset,
     * and the limit counts each start apart: 7 ways are allowed, so that
     * 100,000 characters cost about twenty milliseconds without the JIT.
     * "/(a?){0,2}(?:b|c)/", read in 7 ways, takes 14 steps a start and
     * 18 to 23 ms on 100,000 "a"; past the bound, "/(a{0,2}){0,2}(?:b|c)/",
     * in 13 ways, takes 19 steps and 26 to 33 ms, "/(a?){0,3}(?:b|c)/", in
     * 15, 30 steps and 44 to 58 ms, "/(a?){0,4}(?:b|c)/" 103 ms, and
     * "/(a?){0,10}(?:b|c)/" 9.7 s. Of 8 random patterns of that kind left
     * out, the costliest takes 26 steps a start, its paths long but read
     * one way each.
     *
     * Not counted, and so not read in a few ways: an unbounded repeat, a
     * reference, a subroutine call, a conditional, a verb, and anything
     * but a literal, one character of a set or an item that reads none.
     */
    public static function isReadInAFewWays(QuantifierNode $loop, LintContext $context): bool
    {
        $pattern = $context->parents()[0] ?? $loop;
        $flags = $context->charSetAnalyzer->flagsAtTheStart(NodePredicates::modifierFlags($context->pattern->flags));
        $bound = $context->pattern->hasFlag('A') || self::startsAtOnePlace($pattern, $flags) ? self::MAX_WAYS : self::MAX_WAYS_FROM_ANY_START;

        return self::ways($pattern, $flags, ByteCharSet::empty(), $context, true) <= $bound;
    }

    /**
     * The sequence one iteration of the loop matches, through the groups
     * that only wrap it, or null when the iteration is no sequence.
     */
    public static function iteration(QuantifierNode $loop): ?SequenceNode
    {
        $node = self::unwrapPlainGroups($loop->node);

        return $node instanceof SequenceNode ? $node : null;
    }

    /**
     * Whether the inner loop always ends where the next item takes over, so
     * the text splits into iterations one way only: "(?:\\.[^"\\]*)*" in
     * Friedl's unrolled loop, "(?:.*\n)+" without the s flag.
     *
     * The item after the inner loop, the iteration's first one when the
     * inner loop ends it (the next iteration starts there), must never
     * start or end with a character of the inner loop. Any other item in
     * between would let the inner loop stop early and hand its characters
     * over: "(?:.\d.a+)+x" blows up although "\d" is never an "a".
     *
     * Strict on purpose: the inner loop repeats one character and is the
     * only variable item; every other item is a literal or one character of
     * a set, matched one way.
     * Under the i flag the character sets are not known, so nothing is
     * accepted.
     *
     * Each set is read under the flags in effect at its item, inner flags
     * included: in "(?:(?s:.){1,9}\n)+" the dot takes the "\n". The
     * newline convention is the one the pattern's analyzer holds: under
     * (*CR) the dot takes "\n". An i in force at the inner loop, or turned
     * on inside it as in "(?i:a)", refuses the iteration as the i flag does.
     * Where the rules read every byte, under the tables of the target's
     * locale, the sets hold all the items take: under glibc's
     * tr_TR.ISO-8859-9, "\w" takes 0xDD, and "(?:\w+\xDD)+" is no such
     * iteration.
     */
    public static function isSeparatedIteration(SequenceNode $iteration, QuantifierNode $inner, LintContext $context): bool
    {
        $flags = $context->flagsAtEachChild($iteration);
        $items = array_filter($iteration->children, static fn (NodeInterface $child): bool => !$child instanceof CommentNode);
        $keys = array_keys($items);
        $innerPosition = array_search($inner, array_map(self::unwrapPlainGroups(...), array_values($items)), true);
        if (false === $innerPosition) {
            return false;
        }

        $innerKey = $keys[$innerPosition];
        $nextKey = $keys[($innerPosition + 1) % \count($keys)];
        if (str_contains($flags[$innerKey].$flags[$nextKey], 'i')
            || NodePredicates::turnsCaselessOn($inner->node)
            || !NodePredicates::nodeIsSingleChar($inner->node)
        ) {
            return false;
        }

        $everyByte = $context->comparesEveryByte();
        $innerSet = $everyByte ? $context->firstBytes($inner->node, $flags[$innerKey]) : $context->firstChars($inner->node, $flags[$innerKey]);
        if ($innerSet->isUnknown() || $innerSet->isEmpty()) {
            return false;
        }

        foreach ($items as $key => $item) {
            if ($key !== $innerKey && !self::isLiteralOrOneCharacterOfASet($item)) {
                return false;
            }
        }

        $next = $items[$nextKey];
        if (self::mayTakeACharacterPastItsSet($inner->node, $context) && self::mayTakeACharacterPastItsSet($next, $context)) {
            return false;
        }

        $nextSet = $everyByte
            ? $context->firstBytes($next, $flags[$nextKey])->union($context->lastBytes($next, $flags[$nextKey]))
            : $context->firstChars($next, $flags[$nextKey])->union($context->lastChars($next, $flags[$nextKey]));

        return !$nextSet->isUnknown() && !$nextSet->isEmpty() && !$nextSet->intersects($innerSet);
    }

    /**
     * Whether the node may take a character its set does not hold: one
     * above ASCII, where the sets of firstChars() stop. A dot or a negated
     * class never meets "\xE9" there, although the engine lets it take
     * one. So two items that both may take such a character are never
     * known apart; an item held to ASCII is, its set being whole.
     *
     * A literal or a class holding a byte above ASCII may take one, as do a
     * dot, a negated class, \D, \W, \S, \h, \v and the like, a Unicode
     * property, a POSIX class and anything not read here. \d, \w and \s
     * are held to ASCII without u under PCRE2's own tables, unless the
     * pattern opens with a verb such as (*UCP), which widens them. Where the
     * rules read every byte, under another locale's tables, whose "\w" and
     * cases reach past ASCII, a set holds all the node takes: none.
     */
    public static function mayTakeACharacterPastItsSet(NodeInterface $node, LintContext $context): bool
    {
        return !$context->comparesEveryByte() && self::mayTakeAboveAscii($node, str_contains($context->pattern->flags, 'u') || self::patternStartsWithVerb($context));
    }

    /**
     * The bytes the node can start with, read in its own case, to compare
     * with the bytes of another node or of a text. Under PCRE2's own
     * tables the sets stop at ASCII: a node that may take a character
     * above it, a dot, a negated class or, under u, "\d", starts with
     * every byte above ASCII as well, the lead bytes of UTF-8 among them.
     * Under another locale's tables, without u, the set holds every byte
     * the node takes. Not known for a node read caselessly: under u "k"
     * takes the Kelvin sign, whose bytes its set does not hold.
     */
    public static function firstBytesInItsOwnCase(NodeInterface $node, string $flags, LintContext $context): ByteCharSet
    {
        if (str_contains($flags, 'i') || NodePredicates::turnsCaselessOn($node)) {
            return ByteCharSet::unknown();
        }

        if ($context->readsEveryByte()) {
            return $context->firstBytes($node, $flags);
        }

        $set = $context->firstChars($node, $flags);

        return self::mayTakeACharacterPastItsSet($node, $context) ? $set->union(ByteCharSet::fromBytes(0x80, 0xFF)) : $set;
    }

    /**
     * Whether the node takes every character above ASCII, so that the sets,
     * which stop at 0x7F, say all it takes there: a dot, a negated class of
     * ASCII members, and without u "\\D", "\\W" and "\\S".
     */
    public static function takesEveryCharacterAboveAscii(NodeInterface $node, LintContext $context): bool
    {
        $node = NodePredicates::unwrapTransparentNode($node);
        if ($node instanceof DotNode) {
            return true;
        }

        if ($node instanceof CharClassNode) {
            return $node->isNegated && !self::mayTakeAboveAscii($node->expression, true);
        }

        if (!$node instanceof CharTypeNode) {
            return false;
        }

        $unicode = str_contains($context->pattern->flags, 'u') || self::patternStartsWithVerb($context);

        return !$unicode && \in_array($node->value, ['D', 'W', 'S'], true);
    }

    /**
     * The ways the engine can read the node, one more than MAX_WAYS for
     * any number past it and for a node that is not counted. $after holds
     * the bytes that can follow the node, not known where anything can.
     * With $ends, the node ends the pattern: the items that end it and
     * cannot fail are tried once, the first way through them that matches
     * being the match, and their ways add to those of what comes before
     * them: in "/^(a?){0,4}x(a?){0,9}/" the engine never comes back past
     * the "x" it reached, and the pattern is read in 31 ways and 1,023.
     * The last item that may fail ends the pattern too, and so do its own
     * last items.
     */
    private static function ways(NodeInterface $node, string $flags, ByteCharSet $after, LintContext $context, bool $ends = false): int
    {
        if ($node instanceof SequenceNode) {
            // The items that end the pattern and cannot fail are tried once,
            // after what comes before them: their ways add up. The others
            // multiply theirs.
            $ways = 1;
            $last = 0;
            $flagsAt = FlagScope::flagsAtEachChild($node, $flags);
            foreach (array_reverse($node->children, true) as $index => $child) {
                $read = self::ways($child, $flagsAt[$index], $after, $context, $ends);
                if ($ends && self::cannotFail($child)) {
                    $last += $read;
                } else {
                    // The last item that may fail still ends the pattern.
                    $ways = min(self::MAX_WAYS + 1, $ways * $read);
                    $ends = false;
                }

                $after = self::bytesFrom($child, $flagsAt[$index], $after, $context);
            }

            return min(self::MAX_WAYS + 1, $ways + $last);
        }

        if ($node instanceof AlternationNode) {
            // Branches no two of which start alike are told apart by the
            // next character: the engine reads one of them.
            $sum = 0;
            $widest = 0;
            $starts = ByteCharSet::empty();
            $apart = true;
            foreach (FlagScope::flagsAtEachChild($node, $flags) as $index => $flagsAtBranch) {
                $branch = self::ways($node->alternatives[$index], $flagsAtBranch, $after, $context, $ends);
                $sum = min(self::MAX_WAYS + 1, $sum + $branch);
                $widest = max($widest, $branch);
                $start = self::bytesFrom($node->alternatives[$index], $flagsAtBranch, $after, $context);
                $apart = $apart && !$start->intersects($starts);
                $starts = $starts->union($start);
            }

            return $apart ? $widest : $sum;
        }

        if ($node instanceof GroupNode) {
            // What follows a lookaround is read from where it started.
            return self::ways($node->child, FlagScope::flagsInside($node, $flags), NodePredicates::isTransparentGroup($node->type) ? $after : ByteCharSet::unknown(), $context, $ends && \in_array($node->type, self::PLAIN_GROUPS, true));
        }

        if ($node instanceof QuantifierNode) {
            [$min, $max] = QuantifierMath::parseRange($node);
            if (null === $max) {
                return self::MAX_WAYS + 1;
            }

            // After one run of the body comes another, or what follows.
            $start = self::firstBytesInItsOwnCase($node->node, $flags, $context);
            $once = self::ways($node->node, $flags, $max > 1 ? $after->union($start) : $after, $context);
            if (!NodePredicates::canBeEmpty($node->node) && !$start->intersects($after)) {
                return self::power($once, $max);
            }

            $ways = 0;
            for ($count = $min; $count <= $max && $ways <= self::MAX_WAYS; $count++) {
                $ways += self::power($once, $count);
            }

            return min(self::MAX_WAYS + 1, $ways);
        }

        return self::isLiteralOrOneCharacterOfASet($node) || self::takesNoCharacter($node) ? 1 : self::MAX_WAYS + 1;
    }

    /**
     * The ways of a body run $count times in a row, one more than MAX_WAYS
     * past it.
     */
    private static function power(int $ways, int $count): int
    {
        $power = 1;
        for ($run = 0; $run < $count && $power <= self::MAX_WAYS && $ways > 1; $run++) {
            $power *= $ways;
        }

        return min(self::MAX_WAYS + 1, $power);
    }

    /**
     * The bytes that can stand where the node starts: those it starts
     * with, and those that can follow it where it may match nothing. An
     * item that reads no character, whose set is not known, leaves what
     * follows it as it is.
     */
    private static function bytesFrom(NodeInterface $node, string $flags, ByteCharSet $after, LintContext $context): ByteCharSet
    {
        if (self::takesNoCharacter($node)) {
            return $after;
        }

        $start = self::firstBytesInItsOwnCase($node, $flags, $context);

        return NodePredicates::canBeEmpty($node) ? $start->union($after) : $start;
    }

    /**
     * Whether every attempt of the pattern starts at one place: each way
     * through it opens with "^" read without m, "\A" or "\G", past the
     * groups that only wrap it. An attempt from any other offset fails on
     * its first item.
     */
    private static function startsAtOnePlace(NodeInterface $node, string $flags): bool
    {
        if ($node instanceof AlternationNode) {
            foreach (FlagScope::flagsAtEachChild($node, $flags) as $index => $flagsAtBranch) {
                if (!self::startsAtOnePlace($node->alternatives[$index], $flagsAtBranch)) {
                    return false;
                }
            }

            return true;
        }

        if ($node instanceof SequenceNode) {
            return self::startsAtOnePlace($node->children[0], $flags);
        }

        if ($node instanceof GroupNode) {
            return \in_array($node->type, self::PLAIN_GROUPS, true) && self::startsAtOnePlace($node->child, FlagScope::flagsInside($node, $flags));
        }

        return ($node instanceof AnchorNode && '^' === $node->value && !str_contains($flags, 'm'))
            || ($node instanceof AssertionNode && \in_array($node->value, ['A', 'G'], true));
    }

    /**
     * Whether the item always has a way to match, the empty text at
     * worst: a repeat that may run no time, a group or a row of such
     * items, a choice of which one is, an empty text or a comment. An
     * anchor, an assertion or a lookaround may fail.
     */
    private static function cannotFail(NodeInterface $node): bool
    {
        if ($node instanceof QuantifierNode) {
            [$min] = QuantifierMath::parseRange($node);

            return 0 === $min || self::cannotFail($node->node);
        }

        if ($node instanceof GroupNode) {
            return \in_array($node->type, self::PLAIN_GROUPS, true) && self::cannotFail($node->child);
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                if (!self::cannotFail($child)) {
                    return false;
                }
            }

            return true;
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alternative) {
                if (self::cannotFail($alternative)) {
                    return true;
                }
            }

            return false;
        }

        return $node instanceof CommentNode || ($node instanceof LiteralNode && '' === $node->value);
    }

    /**
     * Whether the item reads no character and holds nothing to try again:
     * an anchor, an assertion, a comment or an empty text. An option
     * setting is a group, read as one: "(?i)" makes what it starts with
     * unknown.
     */
    private static function takesNoCharacter(NodeInterface $node): bool
    {
        return $node instanceof AnchorNode
            || $node instanceof AssertionNode
            || $node instanceof CommentNode
            || ($node instanceof LiteralNode && '' === $node->value);
    }

    /**
     * Whether the item matches its text one way: a literal, or one character
     * of a set.
     */
    private static function isLiteralOrOneCharacterOfASet(NodeInterface $node): bool
    {
        if ($node instanceof LiteralNode) {
            return '' !== $node->value;
        }

        return ($node instanceof CharLiteralNode
                || $node instanceof CharClassNode
                || $node instanceof DotNode
                || $node instanceof CharTypeNode
                || $node instanceof UnicodePropNode
                || $node instanceof PosixClassNode)
            && NodePredicates::nodeIsSingleChar($node);
    }

    /**
     * Whether the pattern opens with a verb, such as (*UTF), (*CR) or
     * (*LIMIT_MATCH=10). The first parent on the traversal cursor is the
     * whole pattern; the verbs sit at the start of its first alternative.
     */
    private static function patternStartsWithVerb(LintContext $context): bool
    {
        $node = $context->parents()[0] ?? null;
        while ($node instanceof AlternationNode) {
            $node = $node->alternatives[0] ?? null;
        }

        $first = $node instanceof SequenceNode ? ($node->children[0] ?? null) : $node;

        return $first instanceof PcreVerbNode || $first instanceof LimitMatchNode;
    }

    private static function mayTakeAboveAscii(NodeInterface $node, bool $unicode): bool
    {
        if ($node instanceof LiteralNode) {
            // What is left once every ASCII byte is trimmed off either end
            // starts and ends with a byte above ASCII.
            return '' !== trim($node->value, "\x00..\x7F");
        }

        if ($node instanceof CharLiteralNode || $node instanceof ControlCharNode) {
            return $node->codePoint > 0x7F;
        }

        if ($node instanceof CharTypeNode) {
            return $unicode || !\in_array($node->value, ['d', 'w', 's'], true);
        }

        if ($node instanceof DotNode || ($node instanceof CharClassNode && $node->isNegated)) {
            return true;
        }

        if ($node instanceof AnchorNode
            || $node instanceof AssertionNode
            || $node instanceof CommentNode
            || $node instanceof KeepNode
            || $node instanceof PcreVerbNode
            || $node instanceof LimitMatchNode
            || $node instanceof CalloutNode
        ) {
            return false;
        }

        $children = $node->getChildren();
        if ([] === $children) {
            return true;
        }

        foreach ($children as $child) {
            if (self::mayTakeAboveAscii($child, $unicode)) {
                return true;
            }
        }

        return false;
    }

    private static function unwrapPlainGroups(NodeInterface $node): NodeInterface
    {
        while ($node instanceof GroupNode && \in_array($node->type, self::PLAIN_GROUPS, true)) {
            $node = $node->child;
        }

        return $node;
    }
}
