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

use PHPRegex\Linter\Rule\Support\NodePredicates;
use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Token\TokenType;

/**
 * An anchor binds tighter than "|": "/^a|b|c$/" is "^a" or "b" or "c$".
 * A rule about intent, never a claim the pattern is wrong: it speaks when
 * the first or the last alternative of the pattern is anchored and some
 * alternative is anchored on neither side, and leaves alone an alternation
 * whose every alternative is anchored on one side, as the trim idiom
 * "/^\s+|\s+$/".
 *
 * An alternative is anchored when it opens with "^", "\A" or "\G", past
 * the options, verbs and comments before it, or closes with "$", "\z" or
 * "\Z", before the ones after it; the anchor may sit in a group that
 * changes nothing about where the alternative matches, or be all a
 * lookaround asserts: "(?:b$)", "b(?=$)". An alternative of verbs and
 * comments only, "(*FAIL)" or "(?#...)", is no bare alternative: it never
 * matches, or matches nothing, which alternation.empty reports.
 *
 * @internal
 */
final class AlternationPrecedenceRule extends AbstractLintRule
{
    private const ID = 'regex.lint.anchor.alternationPrecedence';

    /**
     * Groups an alternative matches through as if they were not there; a
     * branch reset group holding several alternatives is no anchor, its
     * child being an alternation.
     */
    private const TRANSPARENT_GROUPS = [GroupType::NonCapturing, GroupType::Capturing, GroupType::Named, GroupType::Atomic, GroupType::BranchReset, GroupType::InlineFlags];

    /**
     * Verbs that end the match attempt when the engine backtracks into
     * them, that accept at once, or that fail.
     */
    private const CUTTING_VERBS = ['COMMIT', 'PRUNE', 'SKIP', 'ACCEPT', 'F', 'FAIL'];

    public function getRuleIds(): array
    {
        return [self::ID];
    }

    public function getNodeTypes(): array
    {
        return [AlternationNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof AlternationNode || [] !== $context->parents() || \count($node->alternatives) < 2) {
            return [];
        }

        $alternatives = array_values($node->alternatives);
        $first = $alternatives[0];
        $last = $alternatives[\count($alternatives) - 1];
        // An alternative that is the anchor alone, as in "a|$", means "or
        // the end": the anchor is the alternative, not one that slipped.
        $start = self::isOnlyAnAnchor($first) ? null : self::anchor($first, true);
        $end = self::isOnlyAnAnchor($last) ? null : self::anchor($last, false);
        // Under A every alternative starts at the start of the subject.
        if (null === $start && null === $end || str_contains($context->pattern->flags, 'A')) {
            return [];
        }

        // An anchor anywhere but the start of the first alternative and the
        // end of the last shows the anchors are placed alternative by
        // alternative, as in "^ +| +$|,": SonarPHP stays silent, and so does
        // the rule.
        $lastIndex = \count($alternatives) - 1;
        foreach ($alternatives as $index => $alternative) {
            if ((0 !== $index && null !== self::anchor($alternative, true)) || ($lastIndex !== $index && null !== self::anchor($alternative, false))) {
                return [];
            }
        }

        // A verb that cuts the match or fails, in an alternative that reads
        // something, leaves the rule silent; one of verbs and comments only,
        // as "(*F)" in "^a|(*F)|b", is passed over.
        foreach ($alternatives as $alternative) {
            if ([] !== self::items($alternative) && self::holdsACuttingVerb($alternative)) {
                return [];
            }
        }

        $bare = null;
        foreach ($alternatives as $alternative) {
            if ([] !== self::items($alternative) && null === self::anchor($alternative, true) && null === self::anchor($alternative, false)) {
                $bare = $alternative;

                break;
            }
        }

        if (null === $bare) {
            return [];
        }

        $source = $context->pattern->source;
        $quotes = self::quotes($source, $context->pattern->flags);
        $texts = [];
        foreach ($alternatives as $index => $alternative) {
            $opening = 0 === $index && null !== $start;
            $closing = \count($alternatives) - 1 === $index && null !== $end;
            $texts[] = self::branchText($alternative, $opening ? $start : ($closing ? $end : null), $opening, $source, $quotes);
        }

        $grouped = (null === $start ? '' : self::around($first, true, $source).self::text($start[0], $source))
            .'(?:'.implode('|', $texts).')'
            .(null === $end ? '' : self::text($end[0], $source).self::around($last, false, $source));

        return [new RuleViolation(
            self::ID,
            \sprintf('An anchor holds for its own alternative only: "%s" matches anywhere in the subject.', self::branchText($bare, null, true, $source, $quotes)),
            $node->getStartPosition(),
            \sprintf('If the anchors are meant for every alternative, group them: "%s".', $grouped),
        )];
    }

    /**
     * The anchor the node opens ($start) or closes with, and the node the
     * grouped form leaves out of its alternative: the anchor itself, or the
     * lookaround asserting it.
     *
     * @return array{NodeInterface, NodeInterface}|null
     */
    private static function anchor(NodeInterface $node, bool $start): ?array
    {
        $items = self::items($node);
        if ([] === $items) {
            return null;
        }

        $item = $start ? $items[0] : $items[\count($items) - 1];
        if (self::isAnchor($item, $start)) {
            return [$item, $item];
        }

        if (!$item instanceof GroupNode) {
            return null;
        }

        if (\in_array($item->type, self::TRANSPARENT_GROUPS, true)) {
            return self::anchor($item->child, $start);
        }

        $asserted = self::isPositiveLookaround($item) && self::isOnlyAnAnchor($item->child) ? self::anchor($item->child, $start) : null;

        return null === $asserted ? null : [$asserted[0], $item];
    }

    /**
     * Whether a verb that ends the match attempt, accepts or fails sits
     * anywhere in the node: "(*COMMIT)^a|b" and "^(*COMMIT)a|b" never match
     * "b" in "xb", and the grouped form of "(*F)^a|b" matches nothing.
     * "(*MARK:m)" and "(*THEN)" leave the bare alternative free.
     */
    private static function holdsACuttingVerb(NodeInterface $node): bool
    {
        if ($node instanceof PcreVerbNode && \in_array(explode(':', $node->verb, 2)[0], self::CUTTING_VERBS, true)) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::holdsACuttingVerb($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the node reads nothing but an anchor, through the groups and
     * lookarounds that leave it in force.
     */
    private static function isOnlyAnAnchor(NodeInterface $node): bool
    {
        $items = self::items($node);
        if (1 !== \count($items)) {
            return false;
        }

        $item = $items[0];
        if ($item instanceof GroupNode && (\in_array($item->type, self::TRANSPARENT_GROUPS, true) || self::isPositiveLookaround($item))) {
            return self::isOnlyAnAnchor($item->child);
        }

        return self::isAnchor($item, true) || self::isAnchor($item, false);
    }

    private static function isAnchor(NodeInterface $node, bool $start): bool
    {
        if ($node instanceof AnchorNode) {
            return ($start ? '^' : '$') === $node->value;
        }

        return $node instanceof AssertionNode && \in_array($node->value, $start ? ['A', 'G'] : ['z', 'Z'], true);
    }

    private static function isPositiveLookaround(GroupNode $group): bool
    {
        return GroupType::LookaheadPositive === $group->type || GroupType::LookbehindPositive === $group->type;
    }

    /**
     * The items of the node that read or assert something: its comments,
     * verbs, option settings and empty text left out.
     *
     * @return list<NodeInterface>
     */
    private static function items(NodeInterface $node): array
    {
        return array_values(array_filter(
            $node instanceof SequenceNode ? $node->children : [$node],
            static fn (NodeInterface $item): bool => !$item instanceof CommentNode
                && !$item instanceof PcreVerbNode
                && !($item instanceof LiteralNode && '' === $item->value)
                && !($item instanceof GroupNode && NodePredicates::isStandaloneInlineFlagsGroup($item)),
        ));
    }

    /**
     * The alternative as written, without the comments at its ends (under
     * /x a "#" comment would swallow what the grouped form puts after it);
     * when the grouped form takes its anchor from it ($start: the one it
     * opens with), without the anchor, nor the verbs and options on the
     * anchor's side, which the grouped form keeps outside.
     *
     * Text quoted with \Q...\E keeps its quotes, which the positions of
     * the nodes inside leave out: "\Qb)\E" is not "b)". No alternative, no
     * anchor and no comment starts or ends inside a quote, so the text
     * stretched over the quotes it starts or ends in is still the
     * alternative's alone; a quote left open to the end is closed.
     *
     * @param array{NodeInterface, NodeInterface}|null $anchor
     * @param list<array{int, int, int, int}>          $quotes
     */
    private static function branchText(NodeInterface $alternative, ?array $anchor, bool $start, string $source, array $quotes): string
    {
        $children = $alternative instanceof SequenceNode ? array_values($alternative->children) : [$alternative];
        $hole = null;
        if (null !== $anchor) {
            [, $hole] = $anchor;
            $items = self::items($alternative);
            $edge = $start ? $items[0] : $items[\count($items) - 1];
            $at = (int) array_search($edge, $children, true);
            $children = $start ? \array_slice($children, $at) : \array_slice($children, 0, $at + 1);
            if ($edge === $hole) {
                $children = $start ? \array_slice($children, 1) : \array_slice($children, 0, -1);
            }
        }

        while ([] !== $children && $children[0] instanceof CommentNode) {
            array_shift($children);
        }
        while ([] !== $children && $children[\count($children) - 1] instanceof CommentNode) {
            array_pop($children);
        }

        if ([] === $children) {
            return '';
        }

        $from = $children[0]->getStartPosition();
        $to = $children[\count($children) - 1]->getEndPosition();
        $close = '';
        foreach ($quotes as [$opener, $content, $closer, $end]) {
            if ($from >= $content && $from < $closer) {
                $from = $opener;
            }
            if ($to > $content && $to <= $closer) {
                $to = $end;
                $close = $closer === $end ? '\\E' : '';
            }
        }

        if (null !== $hole && $hole->getStartPosition() >= $from && $hole->getEndPosition() <= $to) {
            return substr($source, $from, $hole->getStartPosition() - $from).substr($source, $hole->getEndPosition(), $to - $hole->getEndPosition()).$close;
        }

        return substr($source, $from, $to - $from).$close;
    }

    /**
     * The \Q...\E quotes of the source, as PCRE reads them: not in a
     * comment, which under the x flag runs from "#" to the end of the line.
     * Each is the offset of its "\Q", of the text it quotes, of the end of
     * that text, and of the end of its "\E"; a quote left open runs to the
     * end of the source, its text and itself ending there.
     *
     * @return list<array{int, int, int, int}>
     */
    private static function quotes(string $source, string $flags): array
    {
        $quotes = [];
        $opener = null;
        foreach ((new Lexer())->tokenize($source, $flags)->getTokens() as $token) {
            if (TokenType::QuoteModeStart === $token->type) {
                $opener = $token->position;
            } elseif (null !== $opener && TokenType::QuoteModeEnd === $token->type) {
                $quotes[] = [$opener, $opener + 2, $token->position, $token->position + 2];
                $opener = null;
            }
        }

        if (null !== $opener) {
            $quotes[] = [$opener, $opener + 2, \strlen($source), \strlen($source)];
        }

        return $quotes;
    }

    /**
     * The verbs and options before the first item of the alternative
     * ($start), or after its last one, as written.
     */
    private static function around(NodeInterface $alternative, bool $start, string $source): string
    {
        $items = self::items($alternative);
        $edge = $start ? $items[0]->getStartPosition() : $items[\count($items) - 1]->getEndPosition();
        $text = '';
        foreach ($alternative instanceof SequenceNode ? $alternative->children : [] as $child) {
            if (!$child instanceof CommentNode && ($start ? $child->getEndPosition() <= $edge : $child->getStartPosition() >= $edge)) {
                $text .= self::text($child, $source);
            }
        }

        return $text;
    }

    private static function text(NodeInterface $node, string $source): string
    {
        return substr($source, $node->getStartPosition(), max(0, $node->getEndPosition() - $node->getStartPosition()));
    }
}
