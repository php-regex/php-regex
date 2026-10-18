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

namespace PHPRegex\Linter;

use PHPRegex\Parser\Analysis\GroupIndex;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\NodeWalker;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\PropertyNames;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\TraversalAction;

/**
 * A change of meaning between two PCRE2 releases that keeps the tree: a
 * comparison of trees never sees it, the release table does. Each is
 * reported, never rewritten, with the witness the real engines give.
 *
 * @internal the lint reports it for a range of PHP versions
 */
final class SilentMeaningChange
{
    private const ONCE = '"{1}+" makes the group it repeats atomic, as PCRE2 10.34 (PHP 7.4.6) first did; before, it was ignored as "{1}" is: /(a|ab){1}+c/ finds "abc" in "abc" on PCRE2 10.33 and nothing from 10.34.';

    private const SELF_REFERENCE = 'a group that holds a reference to itself may be backtracked into; before, PCRE2 made such a group atomic: /^(a|ab|\1x)+b$/ finds "abb" in "abb" from 10.35 and nothing before.';

    private const REPEATED_ASSERTION = 'a repeated assertion runs as many times as its count asks, the groups it holds reading each pass; before, PCRE2 ran it once: /^(?=(\1?a)){2}/ captures "aa" in "aa" from 10.35 and "a" before.';

    private const UCP_CASING = '"(*UCP)" without UTF folds the case of the characters past ASCII by their Unicode properties: /(*UCP)\xC9/i matches "\xE9" from 10.35 and not before.';

    private function __construct(
        /**
         * The lowest target that reads the pattern the new way.
         */
        public readonly PcreTarget $target,
        public readonly string $message,
        public readonly string $hint,
    ) {}

    /**
     * The first change a later target of the range brings to a pattern the
     * first target reads, in the order of the releases; null when every
     * target matches it alike, as far as the release table knows.
     *
     * @param list<PcreTarget> $later the later targets, in ascending order
     */
    public static function first(RegexNode $ast, PcreTarget $from, array $later): ?self
    {
        $checks = [
            [PcreFeature::PossessiveRepeatOnceIsAtomic, static fn (): ?array => self::repeatsAGroupOncePossessively($ast) ? [self::ONCE, 'Write "{1}" to keep the older reading on every PHP, or an atomic group, "(?>...)", to keep the later one.'] : null],
            [PcreFeature::GroupHoldingItsOwnReferenceBacktracks, static fn (): ?array => self::holdsAGroupReferringToItself($ast) ? [self::SELF_REFERENCE, 'Wrap the group in an atomic group, "(?>(...))", to keep the older reading on every PHP.'] : null],
            [PcreFeature::RepeatedAssertionKeepsItsCount, static fn (): ?array => self::repeatsAnAssertionHoldingAGroup($ast) ? [self::REPEATED_ASSERTION, 'Write the assertion once to keep the older reading on every PHP.'] : null],
            [PcreFeature::UcpFoldsPastAsciiWithoutUtf, static fn (): ?array => self::foldsPastAsciiUnderUcp($ast) ? [self::UCP_CASING, 'Spell both cases in a class, as "[\xC9\xE9]", to read alike on every PHP.'] : null],
            [PcreFeature::LoosePropertyNames, static fn (): ?array => self::bareScript($ast)],
        ];

        foreach ($checks as [$feature, $check]) {
            if ($from->supports($feature)) {
                continue;
            }

            foreach ($later as $target) {
                if (!$target->supports($feature)) {
                    continue;
                }

                $found = $check();
                if (null !== $found) {
                    return new self($target, $found[0], $found[1]);
                }

                break;
            }
        }

        return null;
    }

    /**
     * Whether a possessive "{1}+" stands on an item holding a backtracking
     * point: a group but an atomic one or a lookaround, a script run but an
     * atomic one, or a subroutine call.
     */
    private static function repeatsAGroupOncePossessively(RegexNode $ast): bool
    {
        return self::finds($ast, static function (NodeInterface $node): bool {
            if (!$node instanceof QuantifierNode || QuantifierType::Possessive !== $node->type) {
                return false;
            }

            $bounds = QuantifierBounds::parse($node->quantifier);
            $item = $node->node;

            return null !== $bounds && 1 === $bounds->min && 1 === $bounds->max
                && (($item instanceof GroupNode && !self::isAtomicOrLookaround($item)) || ($item instanceof ScriptRunNode && !$item->atomic) || $item instanceof SubroutineNode);
        });
    }

    /**
     * Whether a capturing group holds a back reference to itself, and is
     * not all an atomic group or a lookaround holds: there nothing may
     * backtrack into it either way.
     */
    private static function holdsAGroupReferringToItself(RegexNode $ast): bool
    {
        $groups = GroupIndex::of($ast->pattern);
        $found = false;
        NodeWalker::walk(
            $ast,
            /**
             * @param list<NodeInterface> $ancestors
             */
            static function (NodeInterface $node, array $ancestors) use ($groups, &$found): ?TraversalAction {
                $parent = [] === $ancestors ? null : $ancestors[\count($ancestors) - 1];
                $found = $node instanceof GroupNode && null !== $groups->numberOf($node)
                    && !($parent instanceof GroupNode && self::isAtomicOrLookaround($parent))
                    && self::finds($node->child, static fn (NodeInterface $inner): bool => $inner instanceof BackrefNode && \in_array($node, $groups->groupsReferencedBy($inner), true));

                return $found ? TraversalAction::Stop : null;
            },
        );

        return $found;
    }

    /**
     * Whether a positive assertion that holds a capturing group is repeated
     * more than once: an open count runs one pass more than its minimum.
     */
    private static function repeatsAnAssertionHoldingAGroup(RegexNode $ast): bool
    {
        $groups = GroupIndex::of($ast->pattern);

        return self::finds($ast, static function (NodeInterface $node) use ($groups): bool {
            if (!$node instanceof QuantifierNode || !$node->node instanceof GroupNode
                || !\in_array($node->node->type, [GroupType::LookaheadPositive, GroupType::LookbehindPositive], true)) {
                return false;
            }

            $bounds = QuantifierBounds::parse($node->quantifier);
            $passes = null === $bounds ? 1 : ($bounds->max ?? $bounds->min + 1);

            return $passes > 1 && self::finds($node->node->child, static fn (NodeInterface $inner): bool => $inner instanceof GroupNode && null !== $groups->numberOf($inner));
        });
    }

    /**
     * Whether a pattern opened by "(*UCP)" without UTF matches a character
     * past ASCII caselessly: the "i" modifier or an inline "i", and a
     * character, or the end of a range, past 0x7F.
     */
    private static function foldsPastAsciiUnderUcp(RegexNode $ast): bool
    {
        $options = StartOptions::of($ast->source ?? '');
        if (!str_contains($options, '(*UCP)') || $ast->isUnicode()) {
            return false;
        }

        $caseless = str_contains($ast->flags, 'i') || self::finds($ast, static fn (NodeInterface $node): bool => $node instanceof GroupNode && GroupType::InlineFlags === $node->type
            && str_contains(explode('-', (string) $node->flags)[0], 'i'));

        return $caseless && self::finds($ast, static fn (NodeInterface $node): bool => ($node instanceof CharLiteralNode && $node->codePoint > 0x7F)
            || ($node instanceof LiteralNode && 1 === LibraryPcre::match('/[\x80-\xFF]/', $node->value))
            || ($node instanceof RangeNode && $node->end instanceof CharLiteralNode && $node->end->codePoint > 0x7F));
    }

    /**
     * The message and hint for the first bare script name, "\p{Han}",
     * which PCRE2 10.40 reads as the script extensions.
     *
     * @return array{string, string}|null
     */
    private static function bareScript(RegexNode $ast): ?array
    {
        $name = null;
        self::finds($ast, static function (NodeInterface $node) use (&$name): bool {
            if ($node instanceof UnicodePropNode && 1 === LibraryPcre::match('/^\{\^?(\w+)\}$/', $node->prop, $match) && PropertyNames::isScriptBefore1040($match[1])) {
                $name = $match[1];
            }

            return null !== $name;
        });

        if (null === $name) {
            return null;
        }

        return [
            \sprintf('\p{%s} holds the characters whose script extensions name the script, not only those of the script, as U+30FB for \p{Katakana}, which PHP 8.2 matches and PHP 8.1 does not.', $name),
            \sprintf('No spelling reads alike on both: "\p{sc:%s}" keeps the script alone from PHP 8.2 on, and PHP 8.1 refuses it.', $name),
        ];
    }

    private static function isAtomicOrLookaround(GroupNode $group): bool
    {
        return \in_array($group->type, [GroupType::Atomic, GroupType::LookaheadPositive, GroupType::LookaheadNegative, GroupType::LookbehindPositive, GroupType::LookbehindNegative], true);
    }

    /**
     * Whether some node of the tree passes the test.
     *
     * @param \Closure(NodeInterface): bool $test
     */
    private static function finds(NodeInterface $tree, \Closure $test): bool
    {
        $found = false;
        NodeWalker::walk($tree, static function (NodeInterface $node) use ($test, &$found): ?TraversalAction {
            $found = $test($node);

            return $found ? TraversalAction::Stop : null;
        });

        return $found;
    }
}
