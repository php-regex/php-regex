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

namespace PHPRegex\Optimizer;

use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\SequenceNode;

/**
 * The alternations of a pattern for Rewriter: an alternative written twice
 * removed, and the start or the end the literal alternatives share factored
 * out, "ab|ac" written "a(?:b|c)".
 *
 * It shares the rewriter's RewriteState, and reads the alternatives the
 * rewriter has already rewritten.
 *
 * @internal
 */
final class AlternationRewrites
{
    public function __construct(private readonly RewriteState $state) {}

    /**
     * @param array<NodeInterface> $alts
     *
     * @return array<NodeInterface>
     */
    public function deduplicateAlternation(array $alts): array
    {
        $seen = [];
        $unique = [];

        foreach ($alts as $alt) {
            // An alternative holding a group is a group of its own number,
            // however it is spelled: "(a)|(a)" keeps its group 2.
            $key = $this->state->spelling($alt);
            if (!isset($seen[$key]) || self::holdsGroup($alt)) {
                $seen[$key] = true;
                $unique[] = $alt;
            }
        }

        return $unique;
    }

    /**
     * Factors the start the literal branches share out of an alternation:
     * "ab|ac" is "a(?:b|c)". It works on the strings the literals stand for,
     * never on their spelling: "\r\n|\r" share "\r", not a backslash.
     *
     * @param array<NodeInterface> $alts
     *
     * @return array<NodeInterface>
     */
    public function factorizeAlternation(array $alts): array
    {
        $literals = self::literalBranches($alts);
        if (null === $literals) {
            return $alts;
        }
        $values = array_map(static fn (LiteralNode $literal): string => $literal->value, $literals);

        $prefix = self::sharedStart($values);
        if ('' === $prefix) {
            return $alts;
        }

        $rests = [];
        foreach ($literals as $alt) {
            $rest = substr($alt->value, \strlen($prefix));
            $rests[] = '' === $rest ? null : new LiteralNode($rest, $alt->startPosition, $alt->endPosition);
        }

        $first = $literals[0];
        $last = $literals[\count($literals) - 1];
        if ([] === array_filter($rests)) {
            // Every branch is the same string: one is enough.
            return [new LiteralNode($prefix, $first->startPosition, $first->endPosition)];
        }

        $group = $this->optionalGroup($rests);
        if (null === $group) {
            return $alts;
        }

        return [new SequenceNode([new LiteralNode($prefix, $first->startPosition, $first->startPosition), $group], $first->startPosition, $last->endPosition)];
    }

    /**
     * Factors the end the literal branches share out of an alternation:
     * "abc|xbc" is "(?:a|x)bc".
     *
     * @param array<NodeInterface> $alts
     *
     * @return array<NodeInterface>
     */
    public function factorizeSuffix(array $alts): array
    {
        $literals = self::literalBranches($alts);
        if (null === $literals) {
            return $alts;
        }
        $values = array_map(static fn (LiteralNode $literal): string => $literal->value, $literals);

        $suffix = self::sharedEnd($values);
        if (\strlen($suffix) < 2) {
            return $alts;
        }

        $rests = [];
        foreach ($literals as $alt) {
            $rest = substr($alt->value, 0, -\strlen($suffix));
            $rests[] = '' === $rest ? null : new LiteralNode($rest, $alt->startPosition, $alt->endPosition);
        }

        $first = $literals[0];
        $last = $literals[\count($literals) - 1];
        if ([] === array_filter($rests)) {
            return [new LiteralNode($suffix, $first->startPosition, $first->endPosition)];
        }

        $group = $this->optionalGroup($rests);
        if (null === $group) {
            return $alts;
        }

        return [new SequenceNode([$group, new LiteralNode($suffix, $last->endPosition, $last->endPosition)], $first->startPosition, $last->endPosition)];
    }

    private static function holdsGroup(NodeInterface $node): bool
    {
        if ($node instanceof GroupNode && \in_array($node->type, [GroupType::Capturing, GroupType::Named, GroupType::BranchReset], true)) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::holdsGroup($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The branches left once the shared part is gone, as one group, optional
     * when one of them is empty; null when no group keeps their order.
     *
     * @param non-empty-list<LiteralNode|null> $rests at least one not null
     */
    private function optionalGroup(array $rests): ?NodeInterface
    {
        $emptyBranch = self::emptyBranchQuantifier($rests);
        // An inline flag may turn "U" on or off around the group, which
        // swaps what "?" and "??" mean: the alternation stays as written.
        if (false === $emptyBranch || (null !== $emptyBranch && $this->state->inlineFlags)) {
            return null;
        }

        // Under "U" the spellings swap: "?" is lazy, "??" greedy.
        if (null !== $emptyBranch && $this->state->lazyByDefault) {
            $emptyBranch = QuantifierType::Greedy === $emptyBranch ? QuantifierType::Lazy : QuantifierType::Greedy;
        }

        $kept = array_values(array_filter($rests, static fn (?LiteralNode $rest): bool => null !== $rest));

        $first = $kept[0];
        $last = $kept[\count($kept) - 1];
        $group = new GroupNode(1 === \count($kept) ? $first : new AlternationNode($kept, $first->startPosition, $last->endPosition), GroupType::NonCapturing);

        return null === $emptyBranch ? $group : new QuantifierNode($group, '?', $emptyBranch, $first->startPosition, $last->endPosition);
    }

    /**
     * The branches, when every one is a plain literal.
     *
     * @param array<NodeInterface> $alts
     *
     * @return non-empty-list<LiteralNode>|null
     */
    private static function literalBranches(array $alts): ?array
    {
        if (\count($alts) < 2) {
            return null;
        }

        $literals = [];
        foreach ($alts as $alt) {
            if (!$alt instanceof LiteralNode || $alt->isRaw) {
                return null;
            }
            $literals[] = $alt;
        }

        return $literals;
    }

    /**
     * The longest start every string shares, cut back to whole characters
     * when they are all UTF-8.
     *
     * @param list<string> $values
     */
    private static function sharedStart(array $values): string
    {
        $prefix = $values[0];
        foreach ($values as $value) {
            $length = 0;
            $limit = min(\strlen($prefix), \strlen($value));
            while ($length < $limit && $prefix[$length] === $value[$length]) {
                $length++;
            }
            $prefix = substr($prefix, 0, $length);
        }

        if (self::allUtf8($values)) {
            while ('' !== $prefix && !mb_check_encoding($prefix, 'UTF-8')) {
                $prefix = substr($prefix, 0, -1);
            }
        }

        return $prefix;
    }

    /**
     * The longest end every string shares, cut back to whole characters
     * when they are all UTF-8.
     *
     * @param list<string> $values
     */
    private static function sharedEnd(array $values): string
    {
        $suffix = strrev(self::sharedStart(array_map(strrev(...), $values)));
        if (self::allUtf8($values)) {
            while ('' !== $suffix && !mb_check_encoding($suffix, 'UTF-8')) {
                $suffix = substr($suffix, 1);
            }
        }

        return $suffix;
    }

    /**
     * @param list<string> $values
     */
    private static function allUtf8(array $values): bool
    {
        foreach ($values as $value) {
            if (!mb_check_encoding($value, 'UTF-8')) {
                return false;
            }
        }

        return true;
    }

    /**
     * How the branches left once the shared part is factored out keep the
     * order PCRE tries them in: an empty branch tried first makes them a lazy
     * option, "a|ab" is "a(?:b)??"; tried last, a greedy one, "ab|a" is
     * "a(?:b)?". Null when no branch is empty, false when the empty one sits
     * in the middle, where no quantifier keeps the order.
     *
     * @param array<NodeInterface|null> $branches
     */
    private static function emptyBranchQuantifier(array $branches): QuantifierType|false|null
    {
        $empty = array_search(null, $branches, true);

        return match ($empty) {
            false => null,
            0 => QuantifierType::Lazy,
            \count($branches) - 1 => QuantifierType::Greedy,
            default => false,
        };
    }
}
