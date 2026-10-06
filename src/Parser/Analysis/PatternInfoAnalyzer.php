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

namespace PHPRegex\Parser\Analysis;

use PHPRegex\Parser\BsrConvention;
use PHPRegex\Parser\Hir\AlternationHir;
use PHPRegex\Parser\Hir\AssertionHir;
use PHPRegex\Parser\Hir\AssertionKind;
use PHPRegex\Parser\Hir\AtomicHir;
use PHPRegex\Parser\Hir\CaptureHir;
use PHPRegex\Parser\Hir\ConcatHir;
use PHPRegex\Parser\Hir\Hir;
use PHPRegex\Parser\Hir\HirTranslator;
use PHPRegex\Parser\Hir\RepetitionHir;
use PHPRegex\Parser\Internal\GroupIndex;
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\Internal\LookbehindLength;
use PHPRegex\Parser\Internal\StartOptions;
use PHPRegex\Parser\NewlineConvention;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;

/**
 * Reads, from the tree alone, the facts PCRE2 computes on a compiled pattern
 * (pcre2_pattern_info()) that PHP does not expose, plus the length and the
 * anchoring of what the pattern matches.
 *
 *     $info = (new PatternInfoAnalyzer())->analyze($parser->parse('/(?<y>\d{4})-\d\d/'));
 *     $info->names;          // ['y' => [1]]
 *     $info->minMatchLength; // 7
 *
 * The tree is read as written: a pattern the validator refuses gets an
 * answer, not an error.
 */
final class PatternInfoAnalyzer
{
    /**
     * Rises when a sound bound narrows: the same pattern may then get a
     * tighter length range or a proven anchor it did not have.
     */
    public const ANALYSIS_VERSION = '1';

    private const GROUP_NAME = '[_\p{L}][_\p{L}\p{Nd}]*+';

    public function analyze(RegexNode $regex): PatternInfo
    {
        $numbering = (new GroupNumberingCollector())->collect($regex);
        $groups = GroupIndex::of($regex->pattern);
        $unicode = $regex->isUnicode();

        $names = [];
        foreach ($numbering->namedGroups as $name => $numbers) {
            sort($numbers);
            $names[$name] = $numbers;
        }
        ksort($names, \SORT_STRING);

        $facts = ['backreference' => 0, 'backslashC' => false, 'keep' => false, 'skip' => false, 'lookbehinds' => []];
        $this->collect($regex->pattern, $groups, $facts);

        [$minLength, $maxLength] = $regex->accept(new LengthRangeCalculator($unicode));
        if ($facts['keep']) {
            // "\K" moves where $matches[0] starts: it may hold nothing and, a
            // "\K" read inside a lookbehind starting it before the text the
            // match consumed, more than that text.
            $minLength = 0;
            if ([] !== $facts['lookbehinds']) {
                $maxLength = null;
            }
        }

        $maxLookbehind = 0;
        $measure = new LookbehindLength($groups, $unicode, true);
        foreach ($facts['lookbehinds'] as $lookbehind) {
            foreach ($lookbehind->child instanceof AlternationNode ? $lookbehind->child->alternatives : [$lookbehind->child] as $branch) {
                $maxLookbehind = max($maxLookbehind, $measure->of($branch)[1] ?? 0);
            }
        }

        [$matchLimit, $depthLimit, $heapLimit, $newline, $bsr] = self::startOptions($regex->source ?? '');

        $hir = (new HirTranslator())->translate($regex);
        $source = $regex->source ?? '';
        $groupBefore = static fn (int $position): bool => self::groupBefore($regex->pattern, $position, $source);

        return new PatternInfo(
            $numbering->maxGroupNumber,
            $names,
            $facts['backreference'],
            $minLength,
            $maxLength,
            $maxLookbehind,
            $facts['backslashC'],
            $matchLimit,
            $depthLimit,
            $heapLimit,
            $newline,
            $bsr,
            // Under JIT, PHP's default, a (*SKIP) starts the next attempt
            // where it points even under "A" (/aa(*SKIP)b|a/A matches "aaa"
            // at 2): the modifier alone proves nothing then.
            (str_contains($regex->flags, 'A') && !$facts['skip']) || self::startsAnchored($hir, $groupBefore),
            !$hir->properties->accepts && self::endsAnchored($hir),
        );
    }

    /**
     * @param array{backreference: int, backslashC: bool, keep: bool, skip: bool, lookbehinds: list<GroupNode>} $facts
     */
    private function collect(NodeInterface $node, GroupIndex $groups, array &$facts): void
    {
        if ($node instanceof BackrefNode) {
            $facts['backreference'] = max($facts['backreference'], ...[0, ...$this->referencedNumbers($node, $groups)]);
        } elseif ($node instanceof ConditionalNode && $node->condition instanceof SubroutineNode && str_starts_with($node->condition->reference, 'R&')) {
            // PCRE2 counts a named recursion condition as a reference to the
            // group, not a numbered one.
            $facts['backreference'] = max($facts['backreference'], ...[0, ...$groups->numbersNamed(substr($node->condition->reference, 2))]);
        } elseif ($node instanceof CharTypeNode && 'C' === $node->value) {
            $facts['backslashC'] = true;
        } elseif ($node instanceof KeepNode) {
            $facts['keep'] = true;
        } elseif ($node instanceof PcreVerbNode && ('SKIP' === $node->verb || str_starts_with($node->verb, 'SKIP:'))) {
            $facts['skip'] = true;
        } elseif ($node instanceof GroupNode && \in_array($node->type, [GroupType::LookbehindPositive, GroupType::LookbehindNegative], true)) {
            $facts['lookbehinds'][] = $node;
        }

        foreach ($node->getChildren() as $child) {
            $this->collect($child, $groups, $facts);
        }
    }

    /**
     * The groups a back reference, or a condition naming a group, may
     * point to: "\1", "\g1", "\g{-1}", "\g-1", "\k<n>", or a condition's bare
     * "2", "+1", "n". "\g'1'" calls the group: it is no reference.
     *
     * @return list<int>
     */
    private function referencedNumbers(BackrefNode $node, GroupIndex $groups): array
    {
        $ref = $node->ref;

        if (1 === LibraryPcre::match('/^(?|\\\\g\{([+-]\d++)\}|\\\\g([+-]\d++)|([+-]\d++))$/', $ref, $matches)) {
            // A relative zero names no group: PCRE refuses it.
            $offset = (int) $matches[1];
            $next = $groups->nextNumberAt($node) ?? 1;
            $number = $offset < 0 ? $next + $offset : $next + $offset - 1;

            return 0 !== $offset && $number > 0 ? [$number] : [];
        }

        if (1 === LibraryPcre::match('/^(?|\\\\g\{(\d++)\}|\\\\g?(\d++)|(\d++))$/', $ref, $matches)) {
            return [(int) $matches[1]];
        }

        if (1 === LibraryPcre::match('/^(?|\\\\k[<{\']('.self::GROUP_NAME.')[>}\']|('.self::GROUP_NAME.'))$/u', $ref, $matches)) {
            return $groups->numbersNamed($matches[1]);
        }

        // Unreachable from a parsed pattern: the parser spells a reference
        // one of the ways above. It guards a hand-built one.
        return [];
    }

    /**
     * The limits, the newline convention and the "\R" convention the
     * options the pattern opens with set; the last setting of each wins.
     *
     * @return array{int|null, int|null, int|null, NewlineConvention|null, BsrConvention|null}
     */
    private static function startOptions(string $source): array
    {
        [$match, $depth, $heap, $newline, $bsr] = [null, null, null, null, null];
        foreach (StartOptions::items($source) as $option) {
            [$name, $value] = explode('=', $option, 2) + [1 => ''];
            match ($name) {
                'LIMIT_MATCH' => $match = (int) $value,
                'LIMIT_DEPTH', 'LIMIT_RECURSION' => $depth = (int) $value,
                'LIMIT_HEAP' => $heap = (int) $value,
                'BSR_ANYCRLF', 'BSR_UNICODE' => $bsr = BsrConvention::from(substr($name, 4)),
                default => $newline = NewlineConvention::tryFrom($name) ?? $newline,
            };
        }

        return [$match, $depth, $heap, $newline, $bsr];
    }

    /**
     * Whether every path through the node starts with "\A", "\G" or a "^"
     * outside multiline mode, read as PCRE2 reads its first item. An option
     * setting such as "(?i)", a comment, a "(?(DEFINE)...)" group and the
     * options a pattern opens with are not items. Any other group is one,
     * even an empty one: "(?:)\Aa", "()\Aa", "(?i:)\Aa" and "(?:(?i))\Aa"
     * are not anchored. So is "\b" or a lookahead before the anchor.
     *
     * @param \Closure(int): bool $groupBefore whether a group stands before the item at that offset
     */
    private static function startsAnchored(Hir $hir, \Closure $groupBefore): bool
    {
        return match (true) {
            $hir instanceof AssertionHir => (AssertionKind::SubjectStart === $hir->kind || AssertionKind::MatchStart === $hir->kind)
                && !$groupBefore($hir->startPosition),
            $hir instanceof ConcatHir => self::startsAnchored($hir->parts[0], $groupBefore),
            $hir instanceof AlternationHir => self::allAnchored($hir->branches, static fn (Hir $branch): bool => self::startsAnchored($branch, $groupBefore)),
            $hir instanceof CaptureHir, $hir instanceof AtomicHir => self::startsAnchored($hir->body, $groupBefore),
            $hir instanceof RepetitionHir => $hir->min > 0 && self::startsAnchored($hir->body, $groupBefore),
            default => false,
        };
    }

    /**
     * Whether a group stands before the node at the offset, in the sequence
     * holding it or in one around it. The tree read as items drops a group
     * that holds none, while PCRE2 counts it: an anchor found first in the
     * items is not first when such a group precedes it. An option setting
     * is not a group there.
     */
    private static function groupBefore(NodeInterface $node, int $position, string $source): bool
    {
        foreach ($node->getChildren() as $child) {
            if ($node instanceof SequenceNode && $child->getEndPosition() <= $position) {
                if ($child instanceof GroupNode && !self::isOptionSetting($child, $source)) {
                    return true;
                }

                continue;
            }

            if ($child->getStartPosition() <= $position && $position < $child->getEndPosition()) {
                return self::groupBefore($child, $position, $source);
            }
        }

        return false;
    }

    /**
     * "(?i)" and its kind: options set for the rest of the group, not a
     * group of their own.
     */
    private static function isOptionSetting(GroupNode $node, string $source): bool
    {
        return GroupType::InlineFlags === $node->type
            && ')' === ($source[$node->getStartPosition() + 2 + \strlen((string) $node->flags)] ?? '');
    }

    /**
     * Whether every path through the node ends with "\z", or "$" under "D"
     * outside multiline mode.
     */
    private static function endsAnchored(Hir $hir): bool
    {
        return match (true) {
            $hir instanceof AssertionHir => AssertionKind::SubjectEnd === $hir->kind,
            $hir instanceof ConcatHir => self::endsAnchored($hir->parts[\count($hir->parts) - 1]),
            $hir instanceof AlternationHir => self::allAnchored($hir->branches, self::endsAnchored(...)),
            $hir instanceof CaptureHir, $hir instanceof AtomicHir => self::endsAnchored($hir->body),
            $hir instanceof RepetitionHir => $hir->min > 0 && self::endsAnchored($hir->body),
            default => false,
        };
    }

    /**
     * @param list<Hir>           $branches
     * @param \Closure(Hir): bool $anchored
     */
    private static function allAnchored(array $branches, \Closure $anchored): bool
    {
        foreach ($branches as $branch) {
            if (!$anchored($branch)) {
                return false;
            }
        }

        return [] !== $branches;
    }
}
