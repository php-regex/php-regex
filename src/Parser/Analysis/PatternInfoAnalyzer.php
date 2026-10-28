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
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Hir\AlternationHir;
use PHPRegex\Parser\Hir\AssertionHir;
use PHPRegex\Parser\Hir\AssertionKind;
use PHPRegex\Parser\Hir\AtomicHir;
use PHPRegex\Parser\Hir\CaptureHir;
use PHPRegex\Parser\Hir\ConcatHir;
use PHPRegex\Parser\Hir\Hir;
use PHPRegex\Parser\Hir\HirTranslator;
use PHPRegex\Parser\Hir\RepetitionHir;
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
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Syntax\PatternParser;
use PHPRegex\Parser\Syntax\StartOptions;

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
 * answer, not an error. The longest lookbehind is the one PCRE2 measures at
 * the target, the running PHP's PCRE2 unless one is named.
 *
 * analyzePattern() reads a pattern rather than a tree, and answers only for
 * one the parser accepts at its target:
 *
 *     $info = PatternInfoAnalyzer::analyzePattern(RegexParser::create(), '/(?<y>\d{4})-\d\d/');
 */
final class PatternInfoAnalyzer
{
    /**
     * Rises when a sound bound narrows, or when a fact changes for the same
     * pattern: the same pattern may then get a tighter length range, a
     * proven anchor it did not have, or another longest lookbehind.
     */
    public const ANALYSIS_VERSION = '2';

    public function __construct(private readonly ?PcreTarget $target = null) {}

    /**
     * The facts of a pattern the parser accepts at its target, read at that
     * target: a pattern that parses can still be one PCRE refuses there, as
     * a \K in a lookbehind on PHP 8.5, and its facts would describe nothing
     * the engine runs.
     *
     * @param RegexParser $parser reads the pattern and judges it, its target the one the facts are read at
     * @param string      $regex  the pattern with its delimiters and flags
     *
     * @throws ExceptionInterface when the parser refuses the pattern at its target: the exception parse() throws, or a
     *                            SemanticErrorException carrying the validation error, its code and its hint
     */
    public static function analyzePattern(RegexParser $parser, string $regex): PatternInfo
    {
        $ast = $parser->parse($regex);

        $validation = $parser->validate($regex);
        if (!$validation->isValid) {
            [$pattern] = PatternParser::extractPatternAndFlags($regex, $parser->target());

            throw new SemanticErrorException((string) $validation->error, $validation->errorCode ?? ErrorCode::InternalUnexpectedState, $validation->offset, $pattern, hint: $validation->hint);
        }

        return (new self($parser->target()))->analyze($ast);
    }

    public function analyze(RegexNode $ast): PatternInfo
    {
        $groups = GroupIndex::of($ast->pattern);
        $numbering = $groups->numbering();
        $unicode = $ast->isUnicode();

        $names = [];
        foreach ($numbering->namedGroups as $name => $numbers) {
            sort($numbers);
            $names[$name] = $numbers;
        }
        ksort($names, \SORT_STRING);

        $facts = ['backreference' => 0, 'backslashC' => false, 'keep' => false, 'skip' => false, 'lookbehinds' => []];
        $this->collect($ast->pattern, $groups, $facts);

        [$minLength, $maxLength] = $ast->accept(new LengthRangeCalculator($unicode));
        if ($facts['keep']) {
            // "\K" moves where $matches[0] starts: it may hold nothing and, a
            // "\K" read inside a lookbehind starting it before the text the
            // match consumed, more than that text.
            $minLength = 0;
            if ([] !== $facts['lookbehinds']) {
                $maxLength = null;
            }
        }

        $maxLookbehind = $this->maxLookbehind($facts['lookbehinds'], $groups, $unicode);

        [$matchLimit, $depthLimit, $heapLimit, $newline, $bsr] = self::startOptions($ast->source ?? '');

        $hir = (new HirTranslator())->translate($ast);
        $source = $ast->source ?? '';
        $groupBefore = static fn (int $position): bool => self::groupBefore($ast->pattern, $position, $source);

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
            (str_contains($ast->flags, 'A') && !$facts['skip']) || self::startsAnchored($hir, $groupBefore),
            !$hir->properties->accepts && self::endsAnchored($hir),
        );
    }

    /**
     * The longest branch of the lookbehinds PCRE2 measures (MAXLOOKBEHIND,
     * less the one character "\b", "\B" and "\A" look back at): those
     * outside any other lookbehind, and those it meets while measuring one.
     * It does not meet one past a "(*ACCEPT)" or a "(*FAIL)" that ends the
     * branch, in a DEFINE group from PCRE2 10.35, or in a lookahead before
     * 10.34; inside a lookahead it meets, it measures every lookbehind.
     * Before 10.34 the longest branch it measures for them counts, of a
     * group too.
     *
     * @param list<GroupNode> $outermost the lookbehinds outside any other
     */
    private function maxLookbehind(array $outermost, GroupIndex $groups, bool $unicode): int
    {
        $target = $this->target ?? PcreTarget::runtime();

        // The lookbehinds met while one is measured wait their turn: a
        // valid pattern gives each the same length wherever it is measured.
        $waiting = $outermost;
        $measure = new LookbehindLength(
            $groups,
            $unicode,
            // Lengths are read as PCRE2 10.43 and later read them, whatever
            // the target: a pattern the target refuses gets an answer too.
            true,
            static function (GroupNode $lookaround) use (&$waiting): void {
                self::collectLookbehinds($lookaround, $waiting);
            },
            inLookaheads: $target->supports(PcreFeature::LookbehindInLookaheadMeasured),
            skipsDefine: $target->supports(PcreFeature::DefineSkippedInLookbehind),
            wrapsLengths: !$target->supports(PcreFeature::LookbehindLengthOverflowRefused),
        );

        $max = 0;
        $measured = [];
        for ($next = 0; $next < \count($waiting); $next++) {
            $lookbehind = $waiting[$next];
            $id = spl_object_id($lookbehind);
            if (isset($measured[$id])) {
                continue;
            }
            $measured[$id] = $lookbehind;

            foreach ($lookbehind->child instanceof AlternationNode ? $lookbehind->child->alternatives : [$lookbehind->child] as $branch) {
                // A branch the target refuses as too long counts for nothing.
                [$length, , $tooLong] = $measure->branch($branch);
                if (null === $tooLong) {
                    $max = max($max, $length[1] ?? 0);
                }
            }
        }

        // Before PCRE2 10.34 every branch measured counts, of a group in a
        // lookbehind or one it calls too.
        return $target->supports(PcreFeature::LongestLookbehindCountsItsBranchesOnly) ? $max : max($max, $measure->longestBranch());
    }

    /**
     * Adds the lookbehind itself, or those a lookahead holds outside any
     * other lookbehind, wherever they stand in it.
     *
     * @param list<GroupNode> $into
     */
    private static function collectLookbehinds(NodeInterface $node, array &$into): void
    {
        if (self::isLookbehind($node)) {
            $into[] = $node;

            return;
        }

        foreach ($node->getChildren() as $child) {
            self::collectLookbehinds($child, $into);
        }
    }

    /**
     * @phpstan-assert-if-true GroupNode $node
     */
    private static function isLookbehind(NodeInterface $node): bool
    {
        return $node instanceof GroupNode && (GroupType::LookbehindPositive === $node->type || GroupType::LookbehindNegative === $node->type);
    }

    /**
     * @param array{backreference: int, backslashC: bool, keep: bool, skip: bool, lookbehinds: list<GroupNode>} $facts
     */
    private function collect(NodeInterface $node, GroupIndex $groups, array &$facts, bool $inLookbehind = false): void
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
        } elseif ($node instanceof PcreVerbNode && 'SKIP' === $node->name) {
            $facts['skip'] = true;
        } elseif (self::isLookbehind($node) && !$inLookbehind) {
            // A lookbehind inside another is measured, or not, with it.
            $facts['lookbehinds'][] = $node;
        }

        foreach ($node->getChildren() as $child) {
            $this->collect($child, $groups, $facts, $inLookbehind || self::isLookbehind($node));
        }
    }

    /**
     * The numbers of the groups a back reference, or a condition naming a
     * group, may point to; none for a reference that names no group.
     *
     * @return list<int>
     */
    private function referencedNumbers(BackrefNode $node, GroupIndex $groups): array
    {
        $numbers = [];
        foreach ($groups->groupsReferencedBy($node) as $group) {
            $numbers[] = (int) $groups->numberOf($group);
        }

        return $numbers;
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
