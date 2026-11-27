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

use PHPRegex\Parser\Hir\Utf8;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\NodeWalker;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\TraversalAction;

/**
 * A lookahead whose character PCRE2 may look for in the wrong place.
 *
 * Where a pattern sets no first character of its own, PCRE2 may take the
 * one a lookahead it opens on asserts: the compiler takes "a" from
 * "(?=a)", and the start-up study a character every match starts with,
 * from "(?=a?a)" too. PCRE2 then starts an attempt only on that character,
 * and looks for the character the pattern requires from past it. Where the
 * two are one, a match that holds it once is missed, with the JIT and
 * without: "/(?=a)b?a/" finds nothing in "a" before PCRE2 10.45, which
 * leaves the asserted character out when the required one has the same
 * value (ChangeLog 10.45, item 16). The same letter in the other case has
 * another value: "/(?=a)b?A/i" and "/(?=a?a)b?(?i:A)/" still find nothing
 * in "a" in 10.49. "(*NO_START_OPT)" turns the search off.
 *
 * The characters are read wider than PCRE2 reads them: every one a
 * lookahead may start with where a match may start, past what may match
 * nothing, whatever the alternatives of the pattern start with; a class
 * counts only where it holds one character or a letter and its other
 * case, which PCRE2 compiles to one. A pattern of one alternative that
 * reads a character first, past what sets none ("^" but under m, "$",
 * the lookarounds, a group of nothing else), sets that one, and PCRE2
 * looks for no other. Under UTF a character past ASCII is never the one
 * looked for.
 *
 * The character required is read wider too: any character every match
 * holds as a literal outside the lookarounds and outside what may match
 * nothing, in every alternative of a group, may be the one, the same or
 * the other case of a letter, and without UTF any byte past ASCII may be
 * the other case of another under the tables of a locale. One inside a
 * lookaround is left out: PCRE2 requires none from a negative lookahead
 * or a lookbehind, and one a lookahead requires comes after where the
 * lookahead stands, where the search from past the first character finds
 * it, on a path that runs the lookahead: in an alternative, one counts.
 *
 * @internal
 */
final class AssertedFirstCharacter
{
    private const LOOKAROUNDS = [GroupType::LookaheadPositive, GroupType::LookaheadNegative, GroupType::LookbehindPositive, GroupType::LookbehindNegative];

    /**
     * A lookahead of which two alternatives may start with one letter.
     */
    private ?NodeInterface $mixedCases = null;

    /**
     * @param bool $caseSets  whether a character may match caselessly as a property of more than two, under UTF or "(*UCP)"
     * @param bool $multiline whether "^" may stand under m, which sets no first character
     * @param bool $caseless  whether a letter may match in either case: under i, or in a class of both
     */
    private function __construct(
        private readonly bool $unicode,
        private readonly bool $caseSets,
        private readonly bool $multiline,
        private readonly bool $caseless
    ) {}

    /**
     * The lookahead whose character the target's PCRE2 may look for in the
     * wrong place, or null.
     */
    public static function lookahead(RegexNode $regex, PcreTarget $target): ?NodeInterface
    {
        [$multiline, $caseless] = self::options($regex);
        $reader = new self($regex->isUnicode(), $regex->isUnicode() || str_contains(StartOptions::of($regex->source ?? ''), '(*UCP)'), $multiline, $caseless);
        $asserted = $reader->starts($regex->pattern, null)[0];
        if ([] === $asserted || \in_array('NO_START_OPT', StartOptions::items($regex->source ?? ''), true) || true === $reader->opensOnACharacter($regex->pattern)) {
            return null;
        }

        // The alternatives of a lookahead give the character the case of
        // the first one in every release: "/(?=A|[Aa])/" finds nothing in
        // "a".
        if (null !== $reader->mixedCases) {
            return $reader->mixedCases;
        }

        // Under UTF a character past ASCII asserts its first byte only,
        // which ends no character.
        $sameValueLooked = !$target->supports(PcreFeature::AssertedFirstCharacterCheckedAgainstRequired);
        $written = $reader->required($regex->pattern);
        foreach ($asserted as $character => $lookahead) {
            foreach ($written as $other) {
                if ($other === $character ? $sameValueLooked && ($character < 0x80 || !$reader->unicode) : $reader->mayBeOtherCase($character, $other)) {
                    return $lookahead;
                }
            }
        }

        return null;
    }

    /**
     * The characters a lookahead may start with where a match of the items
     * may start, each with the outermost such lookahead, and whether the
     * items may match nothing.
     *
     * @return array{array<int, NodeInterface>, bool}
     */
    private function starts(NodeInterface $node, ?NodeInterface $lookahead): array
    {
        $characters = [];
        $letters = [];
        $empty = false;
        foreach ($node instanceof AlternationNode ? $node->alternatives : [$node] as $alternative) {
            [$found, $alternativeEmpty] = $this->sequenceStarts(self::items($alternative), $lookahead);
            $characters += $found;
            $empty = $empty || $alternativeEmpty;

            // PCRE2 compares the value alone of what the alternatives of a
            // lookahead start with: a later one that takes a letter in
            // either case keeps the case of the first.
            $folded = array_unique(array_map(static fn (int $character): int => $character | 0x20, array_filter(array_keys($found), static fn (int $character): bool => ($character | 0x20) >= 0x61 && ($character | 0x20) <= 0x7A)));
            if ($this->caseless && [] !== array_intersect($letters, $folded)) {
                $this->mixedCases ??= $lookahead;
            }

            $letters = [...$letters, ...$folded];
        }

        return [$characters, $empty];
    }

    /**
     * @param list<NodeInterface> $items
     *
     * @return array{array<int, NodeInterface>, bool}
     */
    private function sequenceStarts(array $items, ?NodeInterface $lookahead): array
    {
        $characters = [];
        foreach ($items as $item) {
            [$found, $empty] = $this->itemStarts($item, $lookahead);
            $characters += $found;
            if (!$empty) {
                return [$characters, false];
            }
        }

        return [$characters, true];
    }

    /**
     * What may stand first in an item, and whether it may match nothing:
     * any item but one that reads a character may, as far as this reading
     * goes.
     *
     * @return array{array<int, NodeInterface>, bool}
     */
    private function itemStarts(NodeInterface $node, ?NodeInterface $lookahead): array
    {
        if ($node instanceof QuantifierNode) {
            [$characters, $empty] = $this->itemStarts($node->node, $lookahead);

            return [$characters, $empty || 0 === ($node->bounds->min ?? 0)];
        }

        if ($node instanceof GroupNode && \in_array($node->type, self::LOOKAROUNDS, true)) {
            return [GroupType::LookaheadPositive === $node->type ? $this->starts($node->child, $lookahead ?? $node)[0] : [], true];
        }

        if ($node instanceof GroupNode || $node instanceof SequenceNode || $node instanceof AlternationNode) {
            return $this->starts($node instanceof GroupNode ? $node->child : $node, $lookahead);
        }

        if ($node instanceof ScriptRunNode && null !== $node->content) {
            return $this->starts($node->content, $lookahead);
        }

        $characters = $node instanceof CharClassNode ? $this->classCharacters($node) : \array_slice($this->literal($node), 0, 1);
        $reads = [] !== $characters || $node instanceof CharClassNode || $node instanceof DotNode || $node instanceof CharTypeNode || $node instanceof UnicodePropNode || $node instanceof ExtendedCharClassNode;

        return [null === $lookahead ? [] : array_fill_keys($characters, $lookahead), !$reads];
    }

    /**
     * Whether the pattern, of one alternative, reads a character first,
     * which PCRE2 then looks for instead of any a lookahead asserts:
     * "/(?=A)a/i" finds "a" in every release. True when it sets one, false
     * when it sets none, null when the items read nothing PCRE2 takes a
     * first character from: a lookaround, an assertion, "\K", "$", "^"
     * but under m, an option setting, a callout, a comment, a verb but
     * "(*ACCEPT)", a group of nothing else. A group of one alternative
     * that reads one first, repeated at least once, reads it too. Under
     * UTF or "(*UCP)" a character past ASCII, and "i", "k" and "s", which
     * may be properties under i, are left out.
     */
    private function opensOnACharacter(NodeInterface $pattern): ?bool
    {
        if ($pattern instanceof AlternationNode) {
            return false;
        }

        foreach (self::items($pattern) as $item) {
            $opens = $this->itemOpensOnACharacter($item);
            if (null !== $opens) {
                return $opens;
            }
        }

        return null;
    }

    private function itemOpensOnACharacter(NodeInterface $item): ?bool
    {
        if (self::setsNoFirstCharacter($item) || ($item instanceof LiteralNode && '' === $item->value)) {
            return null;
        }

        if ($item instanceof AnchorNode) {
            return '^' === $item->value && $this->multiline ? false : null;
        }

        if ($item instanceof QuantifierNode) {
            $opens = $this->itemOpensOnACharacter($item->node);

            return null === $opens || ($item->bounds->min ?? 0) > 0 ? $opens : false;
        }

        if ($item instanceof GroupNode && \in_array($item->type, [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::Atomic, GroupType::InlineFlags], true)) {
            return $this->opensOnACharacter($item->child);
        }

        $first = $this->literal($item)[0] ?? null;

        return null !== $first && ($first < 0x80 ? !$this->caseSets || !\in_array($first | 0x20, [0x69, 0x6B, 0x73], true) : !$this->caseSets);
    }

    /**
     * Whether m may hold anywhere, and whether a letter may match in either
     * case: from the flags, an option setting, or a class of a letter and
     * its other case.
     *
     * @return array{bool, bool}
     */
    private static function options(RegexNode $regex): array
    {
        $multiline = str_contains($regex->flags, 'm');
        $caseless = str_contains($regex->flags, 'i');
        NodeWalker::walk($regex->pattern, static function (NodeInterface $node) use (&$multiline, &$caseless): ?TraversalAction {
            if ($node instanceof GroupNode && GroupType::InlineFlags === $node->type) {
                $multiline = $multiline || str_contains((string) $node->flags, 'm');
                $caseless = $caseless || str_contains((string) $node->flags, 'i');
            }

            $caseless = $caseless || ($node instanceof CharClassNode && !$node->isNegated);

            return null;
        });

        return [$multiline, $caseless];
    }

    private static function setsNoFirstCharacter(NodeInterface $node): bool
    {
        return match (true) {
            $node instanceof GroupNode => GroupType::InlineFlags === $node->type
                ? $node->child instanceof LiteralNode && '' === $node->child->value
                : \in_array($node->type, self::LOOKAROUNDS, true),
            $node instanceof PcreVerbNode => !str_starts_with($node->verb, 'ACCEPT'),
            default => $node instanceof AssertionNode || $node instanceof KeepNode || $node instanceof CalloutNode || $node instanceof CommentNode || $node instanceof LimitMatchNode,
        };
    }

    /**
     * The items of an alternative, a sequence inside it read in line.
     *
     * @return list<NodeInterface>
     */
    private static function items(NodeInterface $node): array
    {
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
     * The characters PCRE2 may require of every match: a literal, or a
     * class of one character or of a letter and its other case, outside
     * what may match nothing, in each alternative of a group. PCRE2
     * requires one of them at most, the last. One a lookahead requires
     * comes after where the lookahead stands, where the search from past
     * the first character finds it, but only on a path that runs the
     * lookahead: in an alternative, the character another one requires may
     * stand first, and "/(?=a)b?(?:(?=xa)|a)/" finds nothing in "a" before
     * 10.45. A lookahead counts there, a negative one or a lookbehind,
     * which PCRE2 requires nothing of, nowhere.
     *
     * @return list<int>
     */
    private function required(NodeInterface $node, bool $inAlternative = false): array
    {
        $alternatives = match (true) {
            $node instanceof AlternationNode => $node->alternatives,
            $node instanceof ConditionalNode => [$node->yes, $node->no],
            default => null,
        };
        if (null !== $alternatives) {
            $common = null;
            foreach ($alternatives as $alternative) {
                $characters = $this->required($alternative, true);
                $common = null === $common ? $characters : array_values(array_intersect($common, $characters));
            }

            return $common ?? [];
        }

        return match (true) {
            $node instanceof SequenceNode => array_values(array_unique(array_merge(...array_map(fn (NodeInterface $child): array => $this->required($child, $inAlternative), $node->children)))),
            $node instanceof QuantifierNode => ($node->bounds->min ?? 0) > 0 && 0 !== $node->bounds?->max ? $this->required($node->node, $inAlternative) : [],
            $node instanceof GroupNode => \in_array($node->type, self::LOOKAROUNDS, true) && (!$inAlternative || GroupType::LookaheadPositive !== $node->type) ? [] : $this->required($node->child, $inAlternative),
            $node instanceof ScriptRunNode => null === $node->content ? [] : $this->required($node->content, $inAlternative),
            $node instanceof CharClassNode => $this->classCharacters($node),
            default => array_values(array_unique($this->literal($node))),
        };
    }

    /**
     * The characters of a literal or of an escape of one.
     *
     * @return list<int>
     */
    private function literal(NodeInterface $node): array
    {
        if ($node instanceof CharLiteralNode || $node instanceof ControlCharNode) {
            return [$node->codePoint];
        }

        return $node instanceof LiteralNode ? Utf8::decode($node->value, $this->unicode) ?? [] : [];
    }

    /**
     * The characters of a positive class that holds one, or two that may
     * be cases of one, written as characters or ranges: PCRE2 compiles one
     * to that character, and two cases of a letter to it caseless. None for
     * any other class, "[ab]" included, which PCRE2 neither looks for first
     * nor requires.
     *
     * @return list<int>
     */
    private function classCharacters(CharClassNode $node): array
    {
        if ($node->isNegated) {
            return [];
        }

        $characters = [];
        foreach ($node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression] as $member) {
            [$from, $to] = $member instanceof RangeNode ? [$this->single($member->start), $this->single($member->end)] : array_fill(0, 2, $this->single($member));
            if (null === $from || null === $to || $to - $from > 1) {
                return [];
            }

            $characters = array_values(array_unique([...$characters, $from, $to]));
            if (\count($characters) > 2) {
                return [];
            }
        }

        // Two characters make one only as cases of a letter.
        return 2 === \count($characters) && !$this->mayBeOtherCase($characters[0], $characters[1]) ? [] : $characters;
    }

    private function single(NodeInterface $node): ?int
    {
        $characters = $this->literal($node);

        return 1 === \count($characters) ? $characters[0] : null;
    }

    /**
     * Whether two characters may be cases of one: two ASCII letters that
     * differ in case alone, or, without UTF, a byte past ASCII and another,
     * or "i" or "I", which the tables of a locale may pair: Turkish ones
     * pair "i" with "\xDD" and "I" with "\xFD".
     */
    private function mayBeOtherCase(int $character, int $other): bool
    {
        if ($character < 0x80 && $other < 0x80) {
            $lower = $character | 0x20;

            return $lower === ($other | 0x20) && $lower >= 0x61 && $lower <= 0x7A;
        }

        $lesser = min($character, $other);

        return !$this->unicode && ($lesser > 0x7F || 0x69 === ($lesser | 0x20));
    }
}
