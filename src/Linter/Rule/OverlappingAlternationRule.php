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
use PHPRegex\Linter\Rule\Support\NodePredicates;
use PHPRegex\Linter\Rule\Support\QuantifierMath;
use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPRegex\Parser\Engine\LibraryPcre;
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
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
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
        foreach ($context->flagsAtEachChild($node) as $index => $flags) {
            $alternative = $node->alternatives[$index];
            $charSet = $context->firstChars($alternative, $flags);
            // The case the branch starts in: the flags at its first atom, a
            // "(?i:" around it or a bare "(?i)" before it included, not one
            // later in the branch, which leaves the start case-sensitive.
            $caseless = str_contains($context->flagsAt(self::firstLeaf($alternative), $alternative, $flags), 'i');
            $caselessAt[] = $caseless;
            $charSet = $caseless ? self::foldCharSet($charSet) : $charSet;
            // Under u a byte past ASCII is the lead or a continuation byte
            // of a character: two characters sharing a lead byte meet there.
            if ($charSet->isUnknown()) {
                $anyUnknown = true;
            } elseif ($context->pattern->unicodeMode && $charSet->intersects(ByteCharSet::fromRange(0x80, 0xFF))) {
                $pastAscii = true;
            }
            $charSets[] = $charSet;
        }

        // At least one charset is unknown, a byte past ASCII read caselessly
        // included, which folds as the target's tables say: the sets are
        // asked of PCRE. Under u, bytes past ASCII are asked of PCRE too,
        // which reads characters; where it judges no branch, the bytes
        // stand, two characters sharing a lead byte meeting there.
        $sets = match (true) {
            $anyUnknown => $this->setsFromPcre($node, $context),
            $pastAscii => $this->setsFromPcre($node, $context) ?? array_map(self::alphabetSet(...), $charSets),
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
     * branch is not judged: its first character is not one atom, or it may
     * start with what follows it.
     *
     * @return ?list<CharSet>
     */
    private function setsFromPcre(AlternationNode $node, LintContext $context): ?array
    {
        $startVerbs = StartOptions::of($context->pattern->source);
        $unicodeFlag = str_contains($context->pattern->flags, 'u');

        $flagsAt = $context->flagsAtEachChild($node);
        // The outermost node the traversal stands in: no option group above
        // it sets anything for the alternation.
        $root = $context->parents()[0] ?? $node;
        $sets = [];
        foreach ($node->alternatives as $index => $alternative) {
            // A branch that may read nothing starts with what follows the
            // alternation: not judged.
            [$leaves, $empty] = self::firstAtoms($alternative);
            if ($empty) {
                return null;
            }

            $set = null;
            foreach ($leaves as $leaf) {
                $atom = self::spelledAtom($leaf, $context->pattern->unicodeMode, $context->pattern->source);
                $atom = null === $atom ? null : ClassSetProvider::spelledFor($atom, $context->target);
                // The atom is read under the options in force where it
                // stands: those a group opening the branch sets included.
                $flags = $context->flagsAt($leaf, $alternative, $flagsAt[$index] ?? $context->activeFlags());
                $modifiers = self::probeModifiers($flags, self::asciiOptions($flags, $leaf, $root));
                $read = null === $atom ? null : ClassSetProvider::query($atom, $context->pattern->unicodeMode, $modifiers, $startVerbs, $unicodeFlag, $context->target->locale);
                if (null === $read) {
                    return null;
                }

                $set = $set?->union($read) ?? $read;
            }

            if (null === $set) {
                return null;
            }

            $sets[] = $set;
        }

        return $sets;
    }

    /**
     * The atoms a node may read its first character with, and whether it
     * may read nothing. An item the probe does not read (an anchor, a
     * lookaround, a reference) is an atom of its own, which leaves the
     * branch unjudged. Groups start with what their body starts with, a
     * branch reset group and an alternation with what any branch does; a
     * sequence goes on past an item that may read nothing, and a quantifier
     * that may run no time reads nothing.
     *
     * @return array{list<NodeInterface>, bool}
     */
    private static function firstAtoms(NodeInterface $node): array
    {
        if ($node instanceof GroupNode) {
            // A bare "(?i)" reads nothing: it sets the options of what
            // follows it, which flagsAt() reads.
            if (NodePredicates::isStandaloneInlineFlags($node)) {
                return [[], true];
            }

            if (\in_array($node->type, [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::Atomic, GroupType::BranchReset, GroupType::InlineFlags], true)) {
                return self::firstAtoms($node->child);
            }
        }

        if ($node instanceof SequenceNode || $node instanceof AlternationNode) {
            $sequence = $node instanceof SequenceNode;
            $atoms = [];
            $empty = false;
            foreach ($sequence ? $node->children : $node->alternatives as $child) {
                [$childAtoms, $childEmpty] = self::firstAtoms($child);
                $atoms = [...$atoms, ...$childAtoms];
                if ($sequence && !$childEmpty) {
                    return [$atoms, false];
                }
                $empty = $empty || $childEmpty;
            }

            // A sequence read to its end may read nothing; an alternation
            // may when one of its branches may.
            return [$atoms, $sequence || $empty];
        }

        if ($node instanceof QuantifierNode) {
            [$min, $max] = QuantifierMath::parseRange($node);
            if (0 === $max) {
                return [[], true];
            }

            [$atoms, $empty] = self::firstAtoms($node->node);

            return [$atoms, $empty || 0 === $min];
        }

        if ($node instanceof LiteralNode && '' === $node->value) {
            return [[], true];
        }

        return [[$node], false];
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

        $inline = FlagScope::flagsAt($atom, $root, '') ?? $flags;
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
                self::asWritten($node->alternatives[$i], $context),
                self::asWritten($node->alternatives[$j], $context),
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
     * A branch as the pattern writes it, kept short enough to read: the
     * reader tells the findings of two alternations apart by it. The piece
     * of the body is spelled in the pattern's mode, a hidden character
     * "\x{HEX}" under u and each byte past ASCII "\xHH" otherwise; the
     * printed form when the body does not hold the branch. The layout x
     * skips is left out, as it would fill the columns before the first
     * atom of a branch laid out over lines.
     */
    private static function asWritten(NodeInterface $node, LintContext $context): string
    {
        $text = self::withoutLayout($node, $context->pattern->source);
        if ('' === $text) {
            $text = $node->accept(PatternPrinter::asWritten());
        }

        $shown = DisplayEscaper::escapeFragment($text, $context->pattern->unicodeMode);
        if (mb_strlen($shown, 'UTF-8') <= 30) {
            return $shown;
        }

        // Cut as the reader sees it, on thirty columns at most, and never
        // inside an escape: "\x{202E}", "\xE9" and "\d" stay whole.
        $kept = '';
        LibraryPcre::matchAll('/\\\\x\{[0-9A-Fa-f]+\}|\\\\x[0-9A-Fa-f]{2}|\\\\.|./su', $shown, $tokens);
        foreach ($tokens[0] ?? [] as $token) {
            if (mb_strlen($kept.$token, 'UTF-8') > 30) {
                break;
            }

            $kept .= $token;
        }

        return $kept.'…';
    }

    /**
     * The piece of the body a node covers, without the layout x skips. A
     * leaf and a class are taken as written, an escaped space and a space
     * in a class included; a comment x reads ("# ..." up to the line end)
     * goes. Between its children a node holds its own syntax, "(?:", ")",
     * "|" or a quantifier, which no whitespace belongs to, and the
     * whitespace x skipped: that whitespace goes. The parser places every
     * child inside its parent, in order, the body of a verb included.
     */
    private static function withoutLayout(NodeInterface $node, string $source): string
    {
        $start = $node->getStartPosition();
        $end = $node->getEndPosition();
        if ($node instanceof CommentNode && '#' === ($source[$start] ?? '')) {
            return '';
        }

        $written = substr($source, $start, $end - $start);
        $children = $node instanceof CharClassNode ? [] : $node->getChildren();
        if ([] === $children) {
            return $written;
        }

        $text = '';
        $at = $start;
        foreach ($children as $child) {
            $text .= self::withoutWhitespace(substr($source, $at, $child->getStartPosition() - $at)).self::withoutLayout($child, $source);
            $at = $child->getEndPosition();
        }

        return $text.self::withoutWhitespace(substr($source, $at, $end - $at));
    }

    private static function withoutWhitespace(string $syntax): string
    {
        return str_replace([' ', "\t", "\n", "\r", "\f", "\v"], '', $syntax);
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
