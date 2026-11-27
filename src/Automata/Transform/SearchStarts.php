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

namespace PHPRegex\Automata\Transform;

use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\UnicodePropNode;

/**
 * Which starts a search tries under "(*CRLF)", "(*ANYCRLF)" and "(*ANY)",
 * where "\r\n" is one newline. After a try that failed at a "\r", PCRE2
 * goes past the "\n" that follows it, unless the pattern writes a "\r" or
 * a "\n" itself: then the search tries every start, the one between the
 * two as well, with the JIT and without. "/(*ANYCRLF)\na/" finds "\na" in
 * "\r\na", where "/(*ANYCRLF)\sa/" finds nothing without the JIT.
 *
 * A pattern that each alternative starts with "^", or with ".*", is
 * searched by lines instead: after its first try PCRE2 goes from one line
 * start to the next, and the JIT does not go as the interpreter does.
 * "/(*ANYCRLF)^\n/m" finds the "\n" of "\r\n" with the JIT, and nothing
 * without it.
 *
 * @internal
 */
final class SearchStarts
{
    /**
     * Whether a search for the pattern tries every start, with the JIT and
     * without: the pattern writes a "\r" or a "\n" outside a class, and
     * some alternative of it starts with neither "^" nor ".*". A "\r" or a
     * "\n" written in a class alone is left out: PCRE2 counts it in some
     * classes, "[\r\n]", and not in others, "[^\n]".
     */
    public static function triesEveryStart(RegexNode $regex): bool
    {
        return self::writesCrOrLf($regex->pattern) && !self::mayStartByLines($regex->pattern);
    }

    /**
     * Whether the node writes a "\r" or a "\n" as a character of its own,
     * however it is spelled and wherever it stands: counted no time, or in
     * a branch no subject takes, PCRE2 reads it all the same.
     */
    private static function writesCrOrLf(NodeInterface $node): bool
    {
        if ($node instanceof LiteralNode) {
            return str_contains($node->value, "\r") || str_contains($node->value, "\n");
        }

        if ($node instanceof CharLiteralNode || $node instanceof ControlCharNode) {
            return 0x0D === $node->codePoint || 0x0A === $node->codePoint;
        }

        if ($node instanceof CharClassNode || $node instanceof ExtendedCharClassNode) {
            return false;
        }

        foreach ($node->getChildren() as $child) {
            if (self::writesCrOrLf($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether PCRE2 may search the node by lines: each alternative of it
     * starts with "^", with a dot or "\N" repeated any number of times, or
     * with a group or a lookahead that does. Anything not read here may.
     */
    private static function mayStartByLines(NodeInterface $node): bool
    {
        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alternative) {
                if (!self::mayStartByLines($alternative)) {
                    return false;
                }
            }

            return true;
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                if (!self::isPassedOver($child)) {
                    return self::mayStartByLines($child);
                }
            }

            // An alternative that holds nothing starts with no "^".
            return false;
        }

        if ($node instanceof QuantifierNode) {
            if (self::isOneCharacter($node->node)) {
                return ($node->node instanceof DotNode || ($node->node instanceof CharTypeNode && 'N' === $node->node->value))
                    && (null === $node->bounds || (0 === $node->bounds->min && null === $node->bounds->max));
            }

            // A group that may be skipped is no start of a line to PCRE2.
            return null === $node->bounds || (0 !== $node->bounds->min && self::mayStartByLines($node->node));
        }

        if ($node instanceof GroupNode) {
            return GroupType::LookaheadNegative !== $node->type && self::mayStartByLines($node->child);
        }

        if ($node instanceof AnchorNode) {
            return '^' === $node->value;
        }

        return !(self::isOneCharacter($node) || $node instanceof AssertionNode || ($node instanceof LiteralNode && '' === $node->value));
    }

    /**
     * Whether PCRE2 writes nothing for the node where it stands: a comment,
     * an option the pattern opens with or sets, an item counted no time.
     */
    private static function isPassedOver(NodeInterface $node): bool
    {
        return $node instanceof CommentNode || $node instanceof PcreVerbNode || $node instanceof LimitMatchNode
            || ($node instanceof QuantifierNode && null !== $node->bounds && 0 === $node->bounds->max)
            || ($node instanceof GroupNode && GroupType::InlineFlags === $node->type && $node->child instanceof LiteralNode && '' === $node->child->value);
    }

    private static function isOneCharacter(NodeInterface $node): bool
    {
        return ($node instanceof LiteralNode && '' !== $node->value)
            || $node instanceof CharLiteralNode || $node instanceof ControlCharNode || $node instanceof CharClassNode
            || $node instanceof ExtendedCharClassNode || $node instanceof UnicodePropNode || $node instanceof CharTypeNode || $node instanceof DotNode;
    }
}
