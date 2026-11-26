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
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\Syntax\StartOptions;

/**
 * Where PCRE2 makes a repeat possessive beside a newline sequence: a dot
 * or "\N" repeated some number of times before "\R", and "\R" repeated
 * before a dot or "\N". PCRE2 holds that the two share no character, as a
 * dot takes no newline. Yet "\R" takes every newline, and the dot leaves
 * out the one of the convention only, "\n" by default: the repeat keeps the
 * "\r" that "\R" needed. "/^.*\R\z/" finds no match in "a\r", where
 * "/^[^\n]*\R\z/" finds it, and "/^\R?.\z/" none in "\r", with the JIT and
 * without, from PCRE2 10.39 to 10.49; "(*NO_AUTO_POSSESS)" brings them
 * back. Under "(*ANY)" the dot takes no newline of "\R", and nothing is
 * lost.
 *
 * PCRE2 reads what may come right after the repeat: past the end of the
 * groups it stands in, into the groups that follow, and past what may
 * match nothing. The repeat is found wherever the other one may come next
 * that way, which holds every place PCRE2 finds it. A repeat counted once,
 * "{2}", is never made possessive.
 *
 * @internal
 */
final class NewlineSequencePossession
{
    /**
     * A dot or "\N" may come first.
     */
    private const LINE = 1;

    /**
     * "\R" may come first.
     */
    private const SEQUENCE = 2;

    /**
     * Nothing may be read at all.
     */
    private const EMPTY = 4;

    /**
     * The first repeat of the pattern PCRE2 may make possessive beside a
     * newline sequence, or null.
     */
    public static function repeat(RegexNode $regex): ?QuantifierNode
    {
        if ('ANY' === StartOptions::newline($regex->source ?? '')) {
            return null;
        }

        return self::find($regex->pattern, 0);
    }

    /**
     * Whether the pattern, spliced into a larger one, may hold a repeat
     * PCRE2 makes possessive beside what the text around it holds, or stand
     * after one: a dot, "\N" or "\R" repeated that may end it, or a dot or
     * "\N" that may start it. "/^(?P<a>(?:.)*)(?P<b>\R)$/" finds "a\r"
     * where "/^(?P<a>.*)(?P<b>\R)$/" finds nothing, and
     * "/^(?P<a>\R?)(?P<b>[^\n])$/" finds "\r" where
     * "/^(?P<a>\R?)(?P<b>.)$/" finds nothing. The convention is the larger
     * pattern's, which the pattern does not say.
     *
     * A pattern that may match nothing lets PCRE2 read past it, from a
     * repeat of "\R" before it to a dot after it, past "\d*" and not past
     * "[0-9]*": "/^(?P<b>\R?)(?P<a>[0-9]*)(?P<c>.)$/" finds "\r", and
     * "/^(?P<b>\R?)(?P<a>\d*)(?P<c>.)$/" nothing.
     *
     * Two patterns that write such an end the same way, up to the first
     * item from that end that may not match nothing, meet the text around
     * them alike: "/.[0-9]/" and "/.\d/" both start with a dot, and PCRE2
     * reads no further. Where either may meet it and their ends are written
     * apart, they are read apart.
     */
    public static function meetApartWhereSpliced(RegexNode $left, RegexNode $right): bool
    {
        if (0 !== ((self::leads($left->pattern) | self::leads($right->pattern)) & (self::LINE | self::EMPTY)) && self::end($left, true) !== self::end($right, true)) {
            return true;
        }

        return (null !== self::find($left->pattern, self::LINE | self::SEQUENCE) || null !== self::find($right->pattern, self::LINE | self::SEQUENCE))
            && self::end($left, false) !== self::end($right, false);
    }

    /**
     * The items at the start of the pattern, or at its end, up to the first
     * one from there that may not match nothing, each printed as written.
     * A non-capturing group that does not repeat stands for its body, which
     * PCRE2 reads into and past the end of as it reads it bare: "(?:a?)"
     * and "a?" end alike; "(?:.)*" and ".*", whose group repeats, do not.
     *
     * @return list<string>
     */
    private static function end(RegexNode $regex, bool $start): array
    {
        $items = self::items($regex->pattern);
        $printer = PatternPrinter::asWritten();
        $end = [];
        foreach ($start ? $items : array_reverse($items) as $item) {
            $end[] = $item->accept($printer);
            if (0 === (self::leads($item) & self::EMPTY)) {
                break;
            }
        }

        return $end;
    }

    /**
     * The items of a sequence one after the other, a non-capturing group
     * that does not repeat read as the items of its body.
     *
     * @return list<NodeInterface>
     */
    private static function items(NodeInterface $node): array
    {
        if ($node instanceof GroupNode && GroupType::NonCapturing === $node->type) {
            return self::items($node->child);
        }

        if (!$node instanceof SequenceNode) {
            return [$node];
        }

        $items = [];
        foreach ($node->children as $child) {
            array_push($items, ...self::items($child));
        }

        return $items;
    }

    /**
     * @param int $after what may come right after the node: a dot or "\N", "\R", or neither
     */
    private static function find(NodeInterface $node, int $after): ?QuantifierNode
    {
        if ($node instanceof QuantifierNode) {
            $repeated = self::kind($node->node);
            if (0 !== $repeated) {
                $other = self::LINE === $repeated ? self::SEQUENCE : self::LINE;

                return (null === $node->bounds || $node->bounds->min !== $node->bounds->max) && 0 !== ($after & $other) ? $node : null;
            }

            // A group counted several times is written as many times: a
            // copy ends before the next one, or before what follows them.
            return self::find($node->node, $after | self::leads($node->node));
        }

        if ($node instanceof SequenceNode) {
            $found = null;
            foreach (array_reverse($node->children) as $child) {
                $found = self::find($child, $after) ?? $found;
                $leads = self::leads($child);
                $after = 0 !== ($leads & self::EMPTY) ? $after | $leads : $leads;
            }

            return $found;
        }

        // What a lookaround holds ends where the lookaround ends.
        if (self::isLookaround($node)) {
            $after = 0;
        }

        foreach ($node->getChildren() as $child) {
            $found = self::find($child, $after);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * What a match of the node may read first, and whether it may read
     * nothing; anything not read here may read nothing.
     */
    private static function leads(NodeInterface $node): int
    {
        if ($node instanceof QuantifierNode) {
            $leads = self::leads($node->node);

            return null === $node->bounds || 0 === $node->bounds->min ? $leads | self::EMPTY : $leads;
        }

        if ($node instanceof SequenceNode) {
            $leads = self::EMPTY;
            foreach ($node->children as $child) {
                $own = self::leads($child);
                $leads |= $own;
                if (0 === ($own & self::EMPTY)) {
                    return $leads & ~self::EMPTY;
                }
            }

            return $leads;
        }

        if ($node instanceof AlternationNode) {
            $leads = 0;
            foreach ($node->alternatives as $alternative) {
                $leads |= self::leads($alternative);
            }

            return $leads;
        }

        if ($node instanceof GroupNode && !self::isLookaround($node)) {
            return self::leads($node->child);
        }

        if (($node instanceof LiteralNode && '' !== $node->value)
            || $node instanceof CharLiteralNode || $node instanceof ControlCharNode || $node instanceof CharClassNode
            || $node instanceof ExtendedCharClassNode || $node instanceof UnicodePropNode || $node instanceof CharTypeNode || $node instanceof DotNode) {
            return self::kind($node);
        }

        return self::EMPTY;
    }

    /**
     * A dot or "\N", "\R", or neither.
     */
    private static function kind(NodeInterface $node): int
    {
        if ($node instanceof DotNode || ($node instanceof CharTypeNode && 'N' === $node->value)) {
            return self::LINE;
        }

        return $node instanceof CharTypeNode && 'R' === $node->value ? self::SEQUENCE : 0;
    }

    private static function isLookaround(NodeInterface $node): bool
    {
        return $node instanceof GroupNode && \in_array($node->type, [GroupType::LookaheadPositive, GroupType::LookaheadNegative, GroupType::LookbehindPositive, GroupType::LookbehindNegative], true);
    }
}
