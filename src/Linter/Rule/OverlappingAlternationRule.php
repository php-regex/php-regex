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

use PHPRegex\Linter\Rule\Support\BranchOverlaps;
use PHPRegex\Linter\Rule\Support\FlagScope;
use PHPRegex\Linter\Rule\Support\LanguageQuestions;
use PHPRegex\Linter\Rule\Support\NodePredicates;
use PHPRegex\Linter\Rule\Support\QuantifierMath;
use PHPRegex\Linter\Rule\Support\SourceQuote;
use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Hir\ClassSetProvider;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Printer\DisplayEscaper;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Text\Ascii;

/**
 * Detects (.|\n) anti-patterns, overlapping literal alternatives, and
 * overlapping alternative character sets.
 *
 * The three rule IDs stay in one rule because a literal-overlap finding
 * suppresses the semantic charset check, preserving the historical
 * emission behavior.
 *
 * @internal
 */
final class OverlappingAlternationRule extends AbstractLintRule
{
    public const ID_DOT_NEWLINE = 'regex.lint.alternation.dotNewline';

    public const ID_OVERLAP = 'regex.lint.alternation.overlap';

    public const ID_CHARSET = 'regex.lint.overlap.charset';

    /**
     * The ways through the lookaheads before an atom that are read, one
     * per choice of lookaheads: past them the branch is not judged.
     */
    private const MAX_LOOKAHEAD_WAYS = 16;

    private int $comparisons = 0;

    /**
     * The comparisons of two branches made since the rule was created:
     * what the cost of a wide alternation is measured by.
     *
     * @internal
     */
    public function comparisons(): int
    {
        return $this->comparisons;
    }

    public function getRuleIds(): array
    {
        return [
            self::ID_DOT_NEWLINE,
            self::ID_OVERLAP,
            self::ID_CHARSET,
        ];
    }

    public function getNodeTypes(): array
    {
        return [AlternationNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof AlternationNode) {
            return [];
        }

        $issues = [];

        // Check for (.|\n) anti-pattern before generic overlap detection.
        // This produces a more specific and actionable message.
        if ($this->isDotNewlineAntiPattern($node)) {
            $hasSFlag = str_contains($context->activeFlags(), 's');
            $hint = $hasSFlag
                ? 'Replace (.|\n) with [\s\S] for clarity, or remove the surrounding group if the "s" flag is already active.'
                : 'Use the "s" (PCRE_DOTALL) flag to make "." match newlines, or use [\s\S] instead of (.|\n).';

            $issues[] = new RuleViolation(
                self::ID_DOT_NEWLINE,
                'Alternation (.|\n) is an anti-pattern for matching any character including newlines.',
                $node->startPosition,
                $hint,
                end: $node->getEndPosition(),
            );
        }

        // Overlapping branches are only flagged inside an unbounded quantifier.
        // Without one (e.g., /\r\n|\r|\n/ or /^(978|979)/) they do not pose a
        // ReDoS risk because there's no exponential backtracking.
        if (!$context->isInsideUnboundedQuantifier()) {
            return $issues;
        }

        // Each literal branch, once, and whether it reads its letters in
        // either case: under i, or through a group turning i on.
        $literals = [];
        $flags = $context->flagsAtEachChild($node);
        foreach ($node->alternatives as $index => $alt) {
            $literal = $this->extractLiteralSequence($alt);
            if (null === $literal || '' === $literal) {
                continue;
            }

            $caseless = str_contains($flags[$index] ?? '', 'i') || NodePredicates::turnsCaselessOn($alt);
            $literals[$literal.'|'.($caseless ? 'i' : '')] = [$literal, $caseless];
        }

        // Literal branches are compared by their text alone: one set of
        // first characters for all of them.
        $texts = array_column($literals, 0);
        $pair = $this->firstOverlap($texts, array_column($literals, 1), array_fill(0, \count($texts), CharSet::single(0)), $context);
        if (null !== $pair) {
            $issues[] = new RuleViolation(
                self::ID_OVERLAP,
                \sprintf('Alternation branches "%s" and "%s" overlap.', self::spell($texts[$pair[0]], $context), self::spell($texts[$pair[1]], $context)),
                $node->startPosition,
                'Consider using atomic groups (?>...) to prevent backtracking. Do not reorder overlapping alternatives as it changes match semantics.',
                end: $node->getEndPosition(),
            );

            // A literal overlap suppresses the semantic charset check.
            return $issues;
        }

        // Check for semantic overlaps using character set analysis
        $charsetIssue = $this->checkSemanticOverlaps($node, $context);
        if (null !== $charsetIssue) {
            $issues[] = $charsetIssue;
        }

        return $issues;
    }

    private function checkSemanticOverlaps(AlternationNode $node, LintContext $context): ?RuleViolation
    {
        // Each alternative reads the flags in effect there, with the ones an
        // earlier alternative carries into it.
        $charSets = [];
        $caselessAt = [];
        $anyUnknown = false;
        $pastAscii = false;
        // The atoms each branch may start with, and the lookaheads before
        // each, which the bytes do not read.
        $starts = [];
        $guarded = false;
        foreach ($context->flagsAtEachChild($node) as $index => $flags) {
            $alternative = $node->alternatives[$index];
            $starts[$index] = self::firstAtoms($alternative);
            $guarded = $guarded || self::holdsLookahead($starts[$index][0]);
            $charSet = $context->firstChars($alternative, $flags);
            // The case the branch starts in: the flags at its first atom, a
            // "(?i:" around it or a bare "(?i)" before it included, not one
            // later in the branch, which leaves the start case-sensitive.
            $caseless = str_contains($context->flagsAt(self::firstLeaf($alternative), $alternative, $flags), 'i');
            $caselessAt[] = $caseless;
            $folded = $caseless ? self::foldCharSet($charSet) : $charSet;
            // Under u a byte past ASCII is the lead or a continuation byte
            // of a character: two characters sharing a lead byte meet there.
            // Without u it is a character the sets class in ASCII alone,
            // where "\W" or "[^a]" takes it under PCRE2's own tables and
            // "\w" under a Latin-1 locale's, and which, read caselessly,
            // folds as the target's tables say: the fold of ASCII leaves it
            // unknown.
            if ($charSet->isUnknown() || ($folded->isUnknown() && $context->pattern->unicodeMode)) {
                $anyUnknown = true;
            } elseif ($context->pattern->unicodeMode || $charSet->intersects(ByteCharSet::fromBytes(0x80, 0xFF))) {
                $pastAscii = true;
            }
            $charSets[] = $folded;
        }

        // At least one charset is unknown: the sets are asked of PCRE. Under
        // u, and without it for a byte past ASCII, a byte read caselessly
        // included, the sets are asked of PCRE too, which reads characters
        // under the target's tables; where it judges no branch, the bytes
        // stand, read under those tables, their ASCII part alone under u,
        // where two characters share a lead byte. A branch behind a
        // lookahead is judged by PCRE alone: the bytes would take the
        // characters the lookahead refuses.
        $sets = match (true) {
            $anyUnknown, $guarded => $this->setsFromPcre($node, $starts, $context),
            $pastAscii => $this->setsFromPcre($node, $starts, $context) ?? ($context->pattern->unicodeMode ? array_map(self::alphabetSet(...), array_map(self::asciiPart(...), $charSets)) : self::everyByte($node, $charSets, $context)),
            default => array_map(self::alphabetSet(...), $charSets),
        };
        if (null === $sets) {
            return null;
        }

        // Two branches whose sets meet are still told apart by the texts
        // they start with: when neither text is a prefix of the other,
        // "associative-array" and "array", an input one branch takes the
        // other never does. A branch that starts with no literal tells
        // nothing.
        $pair = $this->firstOverlap(array_map($this->leadingText(...), $node->alternatives), $caselessAt, $sets, $context);

        return null === $pair ? null : $this->makeCharsetViolation($node, $pair[0], $pair[1], $context);
    }

    /**
     * The first two branches that overlap, in the order of the
     * alternation: their first characters meet and the text of one starts
     * the text of the other, both read in one case when either branch is
     * caseless.
     *
     * @param list<string>  $texts    the text each branch starts with
     * @param list<bool>    $caseless whether each branch matches caselessly
     * @param list<CharSet> $sets     the characters each branch can start with
     *
     * @return ?array{int, int}
     */
    private function firstOverlap(array $texts, array $caseless, array $sets, LintContext $context): ?array
    {
        $overlaps = new BranchOverlaps($texts, array_map(static fn (string $text): string => self::fold($text, $context), $texts), $caseless, $sets);
        $pair = $overlaps->first();
        $this->comparisons += $overlaps->compared();

        return $pair;
    }

    /**
     * The byte set as a set of the alphabet. One that is not known, a set
     * with a byte above ASCII read caselessly, meets every set that holds
     * a character.
     */
    private static function alphabetSet(ByteCharSet $set): CharSet
    {
        return $set->isUnknown() ? CharSet::universe(false) : CharSet::fromRanges($set->ranges());
    }

    /**
     * The ASCII bytes of a known set. Under u a byte past ASCII is the lead
     * or a continuation byte of a character, which "ö" and "è" share: where
     * PCRE cannot be asked, those bytes tell nothing of the characters, and
     * an overlap past ASCII goes unreported rather than one PCRE would not
     * see.
     */
    private static function asciiPart(ByteCharSet $set): ByteCharSet
    {
        return $set->intersect(ByteCharSet::fromBytes(0x00, 0x7F));
    }

    /**
     * The literal text a branch starts with, through the groups that only
     * capture, up to its first item that is no literal: "callable-" for
     * "callable-(?:string|object)", "" for "a+b".
     */
    private function leadingText(NodeInterface $node): string
    {
        while ($node instanceof GroupNode && \in_array($node->type, [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named], true)) {
            $node = $node->child;
        }

        // A branch of one item holds one character of text at most, which
        // the other text starts with or not as it does with no text at all.
        if (!$node instanceof SequenceNode) {
            return '';
        }

        // The text ends where the case changes: what a "(?i:" or a bare
        // "(?i)" puts after it takes the other case as well, and tells
        // nothing apart.
        $text = '';
        foreach ($node->children as $child) {
            $piece = $this->extractLiteralSequence($child);
            if (null === $piece || NodePredicates::turnsCaselessOn($child)) {
                break;
            }

            $text .= $piece;
        }

        return $text;
    }

    /**
     * The first atom of a branch, whose flags say the case the branch
     * starts in: through the groups that only capture or set options, a
     * bare "(?i)" at the start passed over, into a quantifier, taken once
     * or not; the branch itself when it holds nothing else.
     */
    private static function firstLeaf(NodeInterface $node): NodeInterface
    {
        while (true) {
            if ($node instanceof GroupNode && \in_array($node->type, [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::InlineFlags], true) && !NodePredicates::isStandaloneInlineFlags($node)) {
                $node = $node->child;
            } elseif ($node instanceof SequenceNode) {
                $first = null;
                foreach ($node->children as $child) {
                    if (!NodePredicates::isStandaloneInlineFlagsGroup($child)) {
                        $first = $child;

                        break;
                    }
                }

                if (null === $first) {
                    return $node;
                }

                $node = $first;
            } elseif ($node instanceof QuantifierNode) {
                $node = $node->node;
            } else {
                return $node;
            }
        }
    }

    /**
     * The characters PCRE gives the first atom of each branch, for sets
     * the byte sets cannot hold (above ASCII), as the target reads them:
     * its properties, and without u the tables of its locale. None when a
     * branch is not judged: its first character is not one atom, it may
     * start with what follows it, or a lookahead before it cannot be read.
     *
     * @param array<array{list<array{NodeInterface, list<list<GroupNode>>|null}>, bool, list<list<GroupNode>>|null}> $starts the first atoms of each branch,
     *                                                                                                                       keyed as the branches are
     *
     * @return ?list<CharSet>
     */
    private function setsFromPcre(AlternationNode $node, array $starts, LintContext $context): ?array
    {
        // The restriction "(*CASELESS_RESTRICT)" sets holds where r is in the
        // flags in force, which the probe writes: the verb would set it again
        // where "(?-r)" lifts it.
        $startVerbs = str_replace('(*CASELESS_RESTRICT)', '', StartOptions::of($context->pattern->source));
        $unicodeFlag = str_contains($context->pattern->flags, 'u');

        $flagsAt = $context->flagsAtEachChild($node);
        // The outermost node the traversal stands in: no option group above
        // it sets anything for the alternation.
        $root = $context->parents()[0] ?? $node;
        $sets = [];
        foreach ($node->alternatives as $index => $alternative) {
            // A branch that may read nothing starts with what follows the
            // alternation: not judged.
            [$leaves, $empty] = $starts[$index];
            if ($empty) {
                return null;
            }

            // An atom is read under the options in force where it stands:
            // those a group opening the branch sets included.
            $atomSet = static function (NodeInterface $leaf) use ($alternative, $flagsAt, $index, $root, $startVerbs, $unicodeFlag, $context): ?CharSet {
                $flags = $context->flagsAt($leaf, $alternative, $flagsAt[$index] ?? $context->activeFlags());
                $modifiers = self::probeModifiers($flags, self::asciiOptions($flags, $leaf, $root));
                $read = self::asciiCharacter($leaf, $modifiers);
                if (null !== $read) {
                    return $read;
                }

                $atom = self::spelledAtom($leaf, $context->pattern->unicodeMode, $context->pattern->source);
                $atom = null === $atom ? null : ClassSetProvider::spelledFor($atom, $context->target);

                return null === $atom ? null : ClassSetProvider::query($atom, $context->pattern->unicodeMode, $modifiers, $startVerbs, $unicodeFlag, $context->target->locale);
            };

            $set = [] === $leaves ? null : self::startSet($leaves, $atomSet, $context->pattern->unicodeMode);
            if (null === $set) {
                return null;
            }

            $sets[] = $set;
        }

        return $sets;
    }

    /**
     * The characters the atoms may start with, each narrowed by the
     * lookaheads before it, way by way; null when one of them is not
     * judged.
     *
     * @param list<array{NodeInterface, list<list<GroupNode>>|null}> $leaves
     * @param \Closure(NodeInterface): ?CharSet                      $atomSet what PCRE gives an atom
     */
    private static function startSet(array $leaves, \Closure $atomSet, bool $unicode): ?CharSet
    {
        $set = CharSet::empty();
        foreach ($leaves as [$leaf, $ways]) {
            $read = null === $ways ? null : $atomSet($leaf);
            if (null === $read) {
                return null;
            }

            foreach ($ways as $lookaheads) {
                $passed = $read;
                foreach ($lookaheads as $lookahead) {
                    $passed = self::narrowed($passed, $lookahead, $atomSet, $unicode);
                    if (null === $passed) {
                        return null;
                    }
                }

                $set = $set->union($passed);
            }
        }

        return $set;
    }

    /**
     * The characters a lookahead lets the branch start with: a positive
     * one those its body may start with, all of them where the body may
     * read nothing; a negative one all but those its body refuses
     * whatever follows them. Null for a body the probe cannot read.
     *
     * @param \Closure(NodeInterface): ?CharSet $atomSet what PCRE gives an atom
     */
    private static function narrowed(CharSet $set, GroupNode $lookahead, \Closure $atomSet, bool $unicode): ?CharSet
    {
        if (GroupType::LookaheadPositive === $lookahead->type) {
            [$leaves, $empty, $emptyWays] = self::firstAtoms($lookahead->child);
            if ($empty) {
                return [[]] === $emptyWays ? $set : null;
            }

            $allowed = self::startSet($leaves, $atomSet, $unicode);

            return null === $allowed ? null : $set->intersect($allowed);
        }

        $refused = self::refusedAtoms($lookahead->child, $unicode);
        $refusedSet = null === $refused ? null : self::startSet(array_map(static fn (NodeInterface $atom): array => [$atom, [[]]], $refused), $atomSet, $unicode);

        return null === $refusedSet ? null : $set->subtract($refusedSet);
    }

    /**
     * The character an ASCII character written as it is reads in its own
     * case: itself, under every table and every option but "i", which a
     * locale's tables or Unicode fold. Asked of PCRE, it would cost a scan
     * of every code point under u.
     */
    private static function asciiCharacter(NodeInterface $node, string $modifiers): ?CharSet
    {
        if (!$node instanceof LiteralNode || '' === $node->value || \ord($node->value[0]) > 0x7F || str_contains($modifiers, 'i')) {
            return null;
        }

        return CharSet::single(\ord($node->value[0]));
    }

    /**
     * The atoms a node may read its first character with, each with the
     * lookaheads that stand before it, whether it may read nothing, and
     * the lookaheads that hold past it when it does. An item the probe
     * does not read (an anchor, a reference) is an atom of its own, which
     * leaves the branch unjudged. Groups and script runs start with what
     * their body starts with, a branch reset group and an alternation with
     * what any branch does; a lookaround reads nothing, a sequence goes on
     * past an item that may read nothing, and a quantifier that may run no
     * time reads nothing. A lookahead narrows what the atoms after it in
     * the sequence start with; a lookbehind looks before them. The
     * lookaheads are kept way by way: "(?:(?=a)|(?=b))" lets through what
     * either one does, [[(?=a)], [(?=b)]], and a way without one, [[]],
     * lets everything through. Null stands for more ways than are read.
     *
     * @return array{list<array{NodeInterface, list<list<GroupNode>>|null}>, bool, list<list<GroupNode>>|null}
     */
    private static function firstAtoms(NodeInterface $node): array
    {
        if ($node instanceof GroupNode) {
            // A bare "(?i)" reads nothing: it sets the options of what
            // follows it, which flagsAt() reads.
            if (NodePredicates::isStandaloneInlineFlags($node)) {
                return [[], true, [[]]];
            }

            if (\in_array($node->type, [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::Atomic, GroupType::BranchReset, GroupType::InlineFlags], true)) {
                return self::firstAtoms($node->child);
            }

            // A lookaround looks at characters without taking them: the
            // branch takes its first character with what follows, among
            // those a lookahead lets through.
            if (GroupType::LookaheadPositive === $node->type || GroupType::LookaheadNegative === $node->type) {
                return [[], true, [[$node]]];
            }

            // A lookbehind looks at what stands before, as the scan of a
            // captured substring does.
            if (\in_array($node->type, [GroupType::LookbehindPositive, GroupType::LookbehindNegative, GroupType::ScanSubstring], true)) {
                return [[], true, [[]]];
            }
        }

        if ($node instanceof SequenceNode) {
            $atoms = [];
            $ways = [[]];
            foreach ($node->children as $child) {
                [$childAtoms, $childEmpty, $childWays] = self::firstAtoms($child);
                foreach ($childAtoms as [$atom, $before]) {
                    $atoms[] = [$atom, self::bothWays($ways, $before)];
                }

                if (!$childEmpty) {
                    return [$atoms, false, [[]]];
                }

                $ways = self::bothWays($ways, $childWays);
            }

            // A sequence read to its end may read nothing.
            return [$atoms, true, $ways];
        }

        if ($node instanceof AlternationNode) {
            $atoms = [];
            $ways = [];
            foreach ($node->alternatives as $alternative) {
                [$alternativeAtoms, $alternativeEmpty, $alternativeWays] = self::firstAtoms($alternative);
                $atoms = [...$atoms, ...$alternativeAtoms];
                if ($alternativeEmpty) {
                    $ways = self::eitherWay($ways, $alternativeWays);
                }
            }

            // An alternation may read nothing when one of its branches may.
            return [$atoms, [] !== $ways, [] === $ways ? [[]] : $ways];
        }

        if ($node instanceof QuantifierNode) {
            [$min, $max] = QuantifierMath::parseRange($node);
            if (0 === $max) {
                return [[], true, [[]]];
            }

            [$atoms, $empty, $ways] = self::firstAtoms($node->node);

            // A body that may run no time narrows nothing past it.
            return [$atoms, $empty || 0 === $min, 0 === $min ? [[]] : $ways];
        }

        if ($node instanceof LiteralNode && '' === $node->value) {
            return [[], true, [[]]];
        }

        // One character alone is always a run, and the engine enters the
        // body before it checks the run.
        if ($node instanceof ScriptRunNode && null !== $node->content) {
            return self::firstAtoms($node->content);
        }

        return [[[$node, [[]]]], false, [[]]];
    }

    /**
     * The ways through one set of lookaheads then the other: each way of
     * the first with each of the second. Null past the ways read.
     *
     * @param list<list<GroupNode>>|null $first
     * @param list<list<GroupNode>>|null $then
     *
     * @return list<list<GroupNode>>|null
     */
    private static function bothWays(?array $first, ?array $then): ?array
    {
        if (null === $first || null === $then || \count($first) * \count($then) > self::MAX_LOOKAHEAD_WAYS) {
            return null;
        }

        $ways = [];
        foreach ($first as $way) {
            foreach ($then as $next) {
                $ways[] = [...$way, ...$next];
            }
        }

        return $ways;
    }

    /**
     * The ways through one set of lookaheads or the other. A way with no
     * lookahead lets everything through, whatever the others let. Null
     * past the ways read.
     *
     * @param list<list<GroupNode>>|null $ways
     * @param list<list<GroupNode>>|null $other
     *
     * @return list<list<GroupNode>>|null
     */
    private static function eitherWay(?array $ways, ?array $other): ?array
    {
        if (\in_array([], $ways ?? [], true) || \in_array([], $other ?? [], true)) {
            return [[]];
        }

        if (null === $ways || null === $other) {
            return null;
        }

        $either = [...$ways];
        foreach ($other as $way) {
            if (!\in_array($way, $either, true)) {
                $either[] = $way;
            }
        }

        return \count($either) > self::MAX_LOOKAHEAD_WAYS ? null : $either;
    }

    /**
     * The atoms whose characters a negative lookahead's body refuses
     * whatever follows them: a body that may match that one character,
     * "a", "[ab]", "a|\d", "ab?" or "a+", which matches as soon as one "a"
     * stands there. A body that goes on with what must hold after the
     * character, a character it needs, "ab" or "a{2}", or an assertion,
     * "a$", "a\b" or "a(?=b)", refuses "a" only there: none. Null for a
     * body that does not start with one character, or may match nothing,
     * "a*".
     *
     * @return list<NodeInterface>|null
     */
    private static function refusedAtoms(NodeInterface $node, bool $unicode): ?array
    {
        if ($node instanceof GroupNode) {
            return \in_array($node->type, [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::Atomic, GroupType::BranchReset], true)
                || (GroupType::InlineFlags === $node->type && !NodePredicates::isStandaloneInlineFlags($node))
                ? self::refusedAtoms($node->child, $unicode)
                : null;
        }

        if ($node instanceof AlternationNode) {
            $atoms = [];
            foreach ($node->alternatives as $alternative) {
                $alternativeAtoms = self::refusedAtoms($alternative, $unicode);
                if (null === $alternativeAtoms) {
                    return null;
                }

                $atoms = [...$atoms, ...$alternativeAtoms];
            }

            return $atoms;
        }

        $items = $node instanceof SequenceNode ? array_values($node->children) : [$node];
        $first = $items[0] ?? null;

        // A literal of several characters needs those after its first.
        if ($first instanceof LiteralNode && '' !== $first->value && !LanguageQuestions::isOneCharacter($first, $unicode)) {
            return [];
        }

        // A character repeated reads it once, then as many more as the count
        // asks: "a+" is "a" then "a*", "a{2}" is "a" then "a".
        $atom = $first;
        $more = 0;
        if ($first instanceof QuantifierNode && LanguageQuestions::isOneCharacter($first->node, $unicode)) {
            $more = QuantifierMath::parseRange($first)[0] - 1;
            $atom = $first->node;
        }

        if (null === $atom || $more < 0 || !LanguageQuestions::isOneCharacter($atom, $unicode)) {
            return null;
        }

        if ($more > 0) {
            return [];
        }

        foreach (\array_slice($items, 1) as $item) {
            if (!self::matchesEmptyFreely($item)) {
                return [];
            }
        }

        return [$atom];
    }

    /**
     * Whether the item may match the empty string wherever it stands, with
     * no condition on what is around it: "b?", "(?:b|)" or "\K", not "$",
     * "\b" or "(?=b)".
     */
    private static function matchesEmptyFreely(NodeInterface $node): bool
    {
        if ($node instanceof QuantifierNode) {
            return 0 === QuantifierMath::parseRange($node)[0] || self::matchesEmptyFreely($node->node);
        }

        if ($node instanceof GroupNode) {
            return NodePredicates::isStandaloneInlineFlags($node)
                || (\in_array($node->type, [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::Atomic, GroupType::BranchReset, GroupType::InlineFlags], true) && self::matchesEmptyFreely($node->child));
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                if (!self::matchesEmptyFreely($child)) {
                    return false;
                }
            }

            return true;
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alternative) {
                if (self::matchesEmptyFreely($alternative)) {
                    return true;
                }
            }

            return false;
        }

        return ($node instanceof LiteralNode && '' === $node->value) || $node instanceof KeepNode || $node instanceof CommentNode;
    }

    /**
     * Whether a lookahead stands before one of the atoms, on any way, or
     * before them in more ways than are read.
     *
     * @param list<array{NodeInterface, list<list<GroupNode>>|null}> $leaves
     */
    private static function holdsLookahead(array $leaves): bool
    {
        foreach ($leaves as [, $ways]) {
            if ([[]] !== $ways) {
                return true;
            }
        }

        return false;
    }

    /**
     * The atom as the probe takes it, or null for an item that is no atom.
     * A class or an escape is taken from the body as written: printed
     * alone, its characters past ASCII would come out as bytes, which u
     * reads as code points.
     */
    private static function spelledAtom(NodeInterface $node, bool $unicode, string $source): ?string
    {
        if ($node instanceof LiteralNode) {
            if ('' === $node->value) {
                return null;
            }

            $codePoint = $unicode ? mb_ord(mb_substr($node->value, 0, 1, 'UTF-8'), 'UTF-8') : \ord($node->value[0]);

            return false === $codePoint ? null : \sprintf($unicode ? '\\x{%X}' : '\\x%02X', $codePoint);
        }

        if ($node instanceof CharClassNode || $node instanceof CharTypeNode || $node instanceof DotNode
            || $node instanceof UnicodePropNode || $node instanceof CharLiteralNode
        ) {
            $start = $node->getStartPosition();
            $written = substr($source, $start, $node->getEndPosition() - $start);

            return '' !== $written ? $written : $node->accept(PatternPrinter::asWritten());
        }

        return null;
    }

    /**
     * The options the probe reads an atom under, from the flags in effect at
     * it and the options of "a" that stand there.
     */
    private static function probeModifiers(string $flags, string $ascii): string
    {
        return (str_contains($flags, 'i') ? 'i' : '')
            .(str_contains($flags, 's') ? 's' : '')
            .(str_contains($flags, 'xx') ? 'xx' : (str_contains($flags, 'x') ? 'x' : ''))
            .(str_contains($flags, 'r') ? 'r' : '')
            .$ascii;
    }

    /**
     * The options of "a" in force at an atom, as written: "aD" or "aW" for
     * each one set, "a" alone for all of them, '' outside them. The probe
     * gets the very restrictions of \d, \s, \w and the POSIX classes the
     * atom stands under. PHP has no modifier for them, so they are read
     * from the option groups alone, from $root with no modifier: the D and
     * S found there are those of "(?aD)" and "(?aS)", never the pattern's
     * own /D and /S.
     */
    private static function asciiOptions(string $flags, NodeInterface $atom, NodeInterface $root): string
    {
        if (!str_contains($flags, 'a')) {
            return '';
        }

        // The atom stands under $root, where the traversal found it; were it
        // not found, the pattern's /D and /S would still not be read here.
        $inline = FlagScope::flagsAt($atom, $root, '') ?? '';
        $ascii = '';
        foreach (['D', 'S', 'W', 'P', 'T'] as $option) {
            $ascii .= str_contains($inline, $option) ? 'a'.$option : '';
        }

        return '' === $ascii ? 'a' : $ascii;
    }

    private function makeCharsetViolation(AlternationNode $node, int $i, int $j, LintContext $context): RuleViolation
    {
        return new RuleViolation(
            self::ID_CHARSET,
            \sprintf(
                'Alternation branches "%s" and "%s" have overlapping character sets, which may cause unnecessary backtracking.',
                SourceQuote::of($node->alternatives[$i], $context),
                SourceQuote::of($node->alternatives[$j], $context),
            ),
            $node->startPosition,
            'Consider using atomic groups (?>...) to prevent backtracking. Do not reorder overlapping alternatives as it changes match semantics.',
            end: $node->getEndPosition(),
        );
    }

    private function extractLiteralSequence(NodeInterface $node): ?string
    {
        if ($node instanceof LiteralNode) {
            return $node->value;
        }

        if ($node instanceof GroupNode) {
            if (\in_array($node->type, [
                GroupType::LookaheadPositive,
                GroupType::LookaheadNegative,
                GroupType::LookbehindPositive,
                GroupType::LookbehindNegative,
                GroupType::ScanSubstring,
            ], true)) {
                return null;
            }

            return $this->extractLiteralSequence($node->child);
        }

        if ($node instanceof SequenceNode) {
            $value = '';
            foreach ($node->children as $child) {
                $literal = $this->extractLiteralSequence($child);
                if (null === $literal) {
                    return null;
                }
                $value .= $literal;
            }

            return $value;
        }

        return null;
    }

    /**
     * Detect (.|\n) and (.\n|.) anti-patterns where the developer uses
     * alternation with dot and newline to match any character.
     */
    private function isDotNewlineAntiPattern(AlternationNode $node): bool
    {
        $alts = $node->alternatives;
        if (2 !== \count($alts)) {
            return false;
        }

        $hasDot = false;
        $hasNewline = false;

        foreach ($alts as $alt) {
            if ($alt instanceof DotNode) {
                $hasDot = true;
            } elseif ($this->isNewlineEscape($alt)) {
                $hasNewline = true;
            }
        }

        return $hasDot && $hasNewline;
    }

    private function isNewlineEscape(NodeInterface $node): bool
    {
        if ($node instanceof LiteralNode && "\n" === $node->value) {
            return true;
        }

        if ($node instanceof CharLiteralNode && 0x0A === $node->codePoint) {
            return true;
        }

        return false;
    }

    /**
     * The text with its letters in one case, as PCRE folds them: every
     * letter under u, ASCII ones without.
     */
    private static function fold(string $text, LintContext $context): string
    {
        // The simple case folding, as PCRE compares caselessly: a final
        // sigma folds to the sigma, "ß" stays itself.
        return $context->pattern->unicodeMode ? mb_convert_case($text, \MB_CASE_FOLD_SIMPLE, 'UTF-8') : Ascii::lower($text);
    }

    /**
     * The bytes each branch starts with, read above ASCII under the
     * target's tables, for an alternation without u whose branches PCRE
     * does not judge. A byte is a character there, and the complement of a
     * class takes the bytes above ASCII it does not hold, which the ASCII
     * read leaves out: "[^é]" takes 0x90. What the target's tables decide
     * is read from them, another locale's included: the bytes of "\d",
     * "\s" and "\w", and the other case of a character, a byte's read
     * caselessly included. Where they cannot tell, the ASCII read of the
     * branch stands: an overlap there goes unreported rather than one PCRE
     * would not see. A branch known neither way, a byte read caselessly
     * whose case the engine's paths read otherwise, leaves the alternation
     * unjudged.
     *
     * @param list<ByteCharSet> $ascii the sets of the ASCII read, branch by branch
     *
     * @return ?list<CharSet>
     */
    private static function everyByte(AlternationNode $node, array $ascii, LintContext $context): ?array
    {
        $analyzer = new CharSetAnalyzer($context->pattern->flags, StartOptions::of($context->pattern->source), true, locale: $context->target->locale);
        $flagsAt = $context->flagsAtEachChild($node);
        $sets = [];
        foreach ($node->alternatives as $index => $alternative) {
            $set = $analyzer->withFlags($flagsAt[$index] ?? $context->activeFlags())->firstChars($alternative);
            $set = $set->isUnknown() ? $ascii[$index] ?? $set : $set;
            if ($set->isUnknown()) {
                return null;
            }

            $sets[] = self::alphabetSet($set);
        }

        return $sets;
    }

    /**
     * The character set with the other case of each ASCII letter added, so
     * that two sets containing only ASCII letters can be compared for
     * overlap under the i flag. Returns the input unchanged when it holds
     * a byte above ASCII (those code points are out of range for the set).
     */
    private static function foldCharSet(ByteCharSet $set): ByteCharSet
    {
        if ($set->isUnknown()) {
            return $set;
        }

        for ($byte = 0x80; $byte <= 0xFF; $byte++) {
            if ($set->intersects(ByteCharSet::fromChar(\chr($byte)))) {
                return ByteCharSet::unknown();
            }
        }

        $folded = $set;
        for ($lower = \ord('a'); $lower <= \ord('z'); $lower++) {
            $upper = $lower - 0x20;
            $lowerSet = ByteCharSet::fromChar(\chr($lower));
            $upperSet = ByteCharSet::fromChar(\chr($upper));
            if ($set->intersects($lowerSet) || $set->intersects($upperSet)) {
                $folded = $folded->union($lowerSet)->union($upperSet);
            }
        }

        return $folded;
    }

    /**
     * A branch's text as the pattern could hold it: a backslash doubled, a
     * hidden character "\x{HEX}" in UTF mode and its bytes "\xHH"
     * otherwise.
     */
    private static function spell(string $text, LintContext $context): string
    {
        return DisplayEscaper::escapeFragment(str_replace('\\', '\\\\', $text), $context->pattern->unicodeMode);
    }
}
