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
        [$min] = QuantifierMath::parseRange($loop->quantifier);

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
        [, $max] = QuantifierMath::parseRange($loop->quantifier);
        $inner = self::unwrapPlainGroups($loop->node);
        if (null === $max || $max > 2 || !$inner instanceof QuantifierNode || QuantifierType::Lazy === $inner->type) {
            return false;
        }

        $flags = $context->activeFlags();
        $codePoint = match (true) {
            $inner->node instanceof LiteralNode && 1 === \strlen($inner->node->value) => \ord($inner->node->value),
            $inner->node instanceof CharLiteralNode => $inner->node->codePoint,
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

        $innerSet = $context->firstChars($inner->node, $flags[$innerKey]);
        if ($innerSet->isUnknown() || $innerSet->isEmpty()) {
            return false;
        }

        foreach ($items as $key => $item) {
            if ($key !== $innerKey && !self::isLiteralOrOneCharacterOfASet($item)) {
                return false;
            }
        }

        $next = $items[$nextKey];
        if (self::mayTakeACharacterAboveAscii($inner->node, $context) && self::mayTakeACharacterAboveAscii($next, $context)) {
            return false;
        }

        $nextSet = $context->firstChars($next, $flags[$nextKey])->union($context->lastChars($next, $flags[$nextKey]));

        return !$nextSet->isUnknown() && !$nextSet->isEmpty() && !$nextSet->intersects($innerSet);
    }

    /**
     * Whether the node may take a character above ASCII. The character sets
     * stop at 0x7F: a dot or a negated class never meets "\xE9" there,
     * although the engine lets it take one. So two items that both may take
     * such a character are never known apart; an item held to ASCII is, its
     * set being whole.
     *
     * A literal or a class holding a byte above ASCII may take one, as do a
     * dot, a negated class, \D, \W, \S, \h, \v and the like, a Unicode
     * property, a POSIX class and anything not read here. \d, \w and \s
     * are held to ASCII without u, unless the pattern opens with a verb
     * such as (*UCP), which widens them.
     */
    public static function mayTakeACharacterAboveAscii(NodeInterface $node, LintContext $context): bool
    {
        return self::mayTakeAboveAscii($node, str_contains($context->pattern->flags, 'u') || self::patternStartsWithVerb($context));
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
