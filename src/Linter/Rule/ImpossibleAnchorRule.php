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

use PHPRegex\Linter\Rule\Support\EndOfLineTail;
use PHPRegex\Linter\Rule\Support\FlagScope;
use PHPRegex\Linter\Rule\Support\LanguageQuestions;
use PHPRegex\Linter\Rule\Support\NodePredicates;
use PHPRegex\Linter\Rule\Support\QuestionBudget;
use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Analysis\GroupIndex as PcreGroups;
use PHPRegex\Parser\Analysis\LengthRangeCalculator;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Syntax\StartOptions;

/**
 * Detects start anchors after consuming characters and end anchors before
 * consuming characters, which make the sequence impossible to match, and a
 * word boundary its two neighbours contradict: "\b" between two word
 * characters, "\B" between a word and a non-word character.
 *
 * The rule IDs interleave per child index; keeping them in one rule
 * preserves the historical emission order.
 *
 * @internal
 */
final class ImpossibleAnchorRule extends AbstractLintRule
{
    public const ID_START = 'regex.lint.anchor.impossible.start';

    public const ID_END = 'regex.lint.anchor.impossible.end';

    public const ID_BOUNDARY = 'regex.lint.anchor.impossible.boundary';

    /**
     * The ASCII word characters, which are word characters in every mode.
     */
    private const ASCII_WORD = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_';

    /**
     * What the automata said of the atoms beside a boundary in the pattern
     * being linted, keyed by the pattern they were asked about: a pattern
     * repeats its atoms, and under /u each question reads "\w" over the
     * whole of Unicode. Kept for one pattern only, as the budget is: an
     * answer kept from an earlier pattern would spare a question this one
     * pays for.
     *
     * @var array<string, bool|null>
     */
    private array $wordKinds = [];

    /**
     * What the automata said of whether an atom of the pattern being linted
     * reads a newline character, keyed by the question.
     *
     * @var array<string, false|null>
     */
    private array $newlineReads = [];

    private readonly QuestionBudget $questions;

    /**
     * The sets of the pattern being linted read over every byte, where the
     * analyzer of the context reads ASCII alone; built at its first use.
     */
    private ?CharSetAnalyzer $everyByte = null;

    /**
     * The analyzer of the context and the one reading every byte, under
     * the flags in effect at the items read, keyed by those flags.
     *
     * @var array<string, CharSetAnalyzer>
     */
    private array $ascii = [];

    /**
     * @var array<string, CharSetAnalyzer>
     */
    private array $everyByteAt = [];

    public function __construct()
    {
        $this->questions = new QuestionBudget();
    }

    public function begin(LintContext $context): void
    {
        $this->questions->reset();
        $this->wordKinds = [];
        $this->newlineReads = [];
        $this->everyByte = null;
        $this->ascii = [];
        $this->everyByteAt = [];
    }

    public function getRuleIds(): array
    {
        return [self::ID_START, self::ID_END, self::ID_BOUNDARY];
    }

    public function getNodeTypes(): array
    {
        return [SequenceNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof SequenceNode) {
            return [];
        }

        // A DEFINE body never executes on its own, so an impossibility
        // verdict about it says nothing about the pattern.
        foreach ($context->parents() as $parent) {
            if ($parent instanceof DefineNode) {
                return [];
            }
        }

        $issues = [];
        $children = $node->children;
        $count = \count($children);
        $flagsAtChild = array_values($context->flagsAtEachChild($node));
        $spelled = false;

        // What the items before the current one read, kept as the loop goes:
        // whether they may all read nothing, whether one may end the match,
        // and the characters the last of them reads (an empty set when none
        // reads one, null when unknown), read up to the item $read only as
        // far as an end anchor asks.
        $prefixCanBeEmpty = true;
        $accepted = false;
        $prefix = null;
        $before = ByteCharSet::empty();
        $read = 0;
        $tailCanBeEmpty = null;

        for ($i = 0; $i < $count; $i++) {
            $child = $children[$i];
            $flags = $flagsAtChild[$i];

            if (NodePredicates::isStartAnchorNode($child)) {
                $skipForMultiline = $child instanceof AnchorNode
                    && '^' === $child->value
                    && str_contains($flags, 'm');

                if (!$skipForMultiline && $i > 0 && !$prefixCanBeEmpty && !$accepted) {
                    $issues[] = new RuleViolation(
                        self::ID_START,
                        \sprintf(
                            "Start anchor '%s' appears after consuming characters, making it impossible to match.",
                            NodePredicates::anchorDisplay($child),
                        ),
                        $child->getStartPosition(),
                        end: $child->getEndPosition(),
                    );
                }

                if ($skipForMultiline && !$accepted) {
                    $issue = null;
                    if ($i > 0 && !$prefixCanBeEmpty) {
                        $this->readUpTo($children, $flagsAtChild, $i, $read, $before, $context);
                        $issue = $this->circumflexAfterNoLineEnd($child, $before, $context) ?? self::circumflexBesideTheEnd($child, $children, $i);
                    }
                    $issue ??= $this->circumflexWhereALookaheadEnds($child, $node, $i, $context);
                    if (null !== $issue) {
                        $issues[] = $issue;
                    }
                }
            }

            if (NodePredicates::isEndAnchorNode($child) && $i + 1 < $count) {
                $tailCanBeEmpty ??= self::emptyFromEachItem($children);
                if (!$tailCanBeEmpty[$i + 1]) {
                    $this->readUpTo($children, $flagsAtChild, $i, $read, $before, $context);
                }

                if (!$tailCanBeEmpty[$i + 1]
                    && !$this->tailCanContinueEndOfLine($child, null === $before || $before->isEmpty() ? null : $before, \array_slice($children, $i + 1), $flags, $node, $context)
                ) {
                    $issues[] = new RuleViolation(
                        self::ID_END,
                        \sprintf(
                            "End anchor '%s' appears before consuming characters, making it impossible to match.",
                            NodePredicates::anchorDisplay($child),
                        ),
                        $child->getStartPosition(),
                        end: $child->getEndPosition(),
                    );
                }
            }

            if ($child instanceof AssertionNode && ('b' === $child->value || 'B' === $child->value) && $i > 0 && $i + 1 < $count) {
                if (false === $spelled) {
                    $spelled = LanguageQuestions::spelledFlags($context, $node);
                }
                $boundary = $this->impossibleBoundary($child, $children[$i - 1], $flagsAtChild[$i - 1], $children[$i + 1], $flagsAtChild[$i + 1], $spelled, $context);
                if (null !== $boundary) {
                    $issues[] = $boundary;
                }
            }

            // A reference or a call may read a group that matched nothing; an
            // "(*ACCEPT)" may end the match before any anchor after it.
            $prefixCanBeEmpty = $prefixCanBeEmpty && (NodePredicates::canBeEmpty($child)
                || ($prefix ??= new EndOfLineTail($context->charSetAnalyzer, "\n", $context->groups->pcre ?? PcreGroups::empty()))->canMatchNothingAnywhere($child));
            $accepted = $accepted || NodePredicates::holdsAccept($child);
        }

        return $issues;
    }

    /**
     * Reads the items from $read up to $upTo, keeping in $before the
     * characters the last of them that reads any can end with: unknown
     * (null) after an item that may read nothing.
     *
     * @param array<int, NodeInterface> $children
     * @param array<int, string>        $flagsAtChild
     */
    private function readUpTo(array $children, array $flagsAtChild, int $upTo, int &$read, ?ByteCharSet &$before, LintContext $context): void
    {
        for (; $read < $upTo; $read++) {
            $last = $this->lastCharacters($children[$read], $flagsAtChild[$read], $context);
            if (null === $last || !$last->isEmpty()) {
                $before = $last;
            }
        }
    }

    /**
     * "^" under m after a character that ends no line under the newline
     * convention: "\n" by default, "\r" under "(*CR)", NUL under "(*NUL)",
     * the "\n" of "\r\n" under "(*CRLF)", either of them under "(*ANYCRLF)",
     * and under "(*ANY)" the vertical tab, the form feed, NEL and the last
     * byte of a separator too. "/a$^/m" matches nothing.
     */
    private function circumflexAfterNoLineEnd(AnchorNode|AssertionNode $anchor, ?ByteCharSet $before, LintContext $context): ?RuleViolation
    {
        if (null === $before || $before->isEmpty()) {
            return null;
        }

        $ends = match (StartOptions::newline($context->pattern->source)) {
            'CR' => ByteCharSet::fromChar("\r"),
            'NUL' => ByteCharSet::fromChar("\0"),
            'ANYCRLF' => ByteCharSet::fromChar("\r")->union(ByteCharSet::fromChar("\n")),
            'ANY' => ByteCharSet::fromBytes(0x0A, 0x0D)->union(ByteCharSet::fromBytes(0x80, 0xFF)),
            default => ByteCharSet::fromChar("\n"),
        };

        return $before->intersects($ends) ? null : self::circumflexViolation($anchor, "Start anchor '^' comes after a character that ends no line, making it impossible to match.");
    }

    /**
     * "^" under m where "\z" holds, the items between reading nothing: at
     * the end of the subject, after the items before it read a character,
     * where "^" never holds. "/\n^\z/m" matches nothing.
     *
     * @param array<int, NodeInterface> $children
     */
    private static function circumflexBesideTheEnd(AnchorNode|AssertionNode $anchor, array $children, int $index): ?RuleViolation
    {
        foreach ([-1, 1] as $step) {
            for ($at = $index + $step; isset($children[$at]); $at += $step) {
                if ($children[$at] instanceof AssertionNode && 'z' === $children[$at]->value) {
                    return self::circumflexAtTheEnd($anchor);
                }

                if (!self::readsNothing($children[$at])) {
                    break;
                }
            }
        }

        return null;
    }

    /**
     * "^" under m in a lookahead, after items of a fixed length that read a
     * character, where the items after the lookahead read as many before
     * "\z": "^" then stands at the end of the subject. "/a(?=\n^)\n\z/m"
     * matches nothing.
     */
    private function circumflexWhereALookaheadEnds(AnchorNode|AssertionNode $anchor, SequenceNode $body, int $index, LintContext $context): ?RuleViolation
    {
        $parents = $context->parents();
        $lookahead = $parents[\count($parents) - 1] ?? null;
        $outer = $parents[\count($parents) - 2] ?? null;
        if (!$lookahead instanceof GroupNode || GroupType::LookaheadPositive !== $lookahead->type || $lookahead->child !== $body || !$outer instanceof SequenceNode) {
            return null;
        }

        $lengths = new LengthRangeCalculator($context->pattern->unicodeMode);
        $ahead = self::fixedLength(\array_slice($body->children, 0, $index), $lengths);
        $at = array_search($lookahead, $outer->children, true);
        if (null === $ahead || 0 === $ahead || !\is_int($at)) {
            return null;
        }

        $after = 0;
        foreach (\array_slice($outer->children, $at + 1) as $item) {
            if ($item instanceof AssertionNode && 'z' === $item->value) {
                return $after === $ahead ? self::circumflexAtTheEnd($anchor) : null;
            }

            $length = self::fixedLength([$item], $lengths);
            if (null === $length || $after + $length > $ahead) {
                return null;
            }
            $after += $length;
        }

        return null;
    }

    /**
     * The length every match of the items reads, null when it may vary or
     * an "(*ACCEPT)" may end the match among them.
     *
     * @param array<int, NodeInterface> $items
     */
    private static function fixedLength(array $items, LengthRangeCalculator $lengths): ?int
    {
        $total = 0;
        foreach ($items as $item) {
            if (NodePredicates::holdsAccept($item)) {
                return null;
            }

            [$min, $max] = $item->accept($lengths);
            if ($min !== $max) {
                return null;
            }
            $total += $min;
        }

        return $total;
    }

    /**
     * Whether the item reads no character wherever it stands, and lets the
     * match go on: an anchor, an assertion, a lookaround, an option
     * setting, a verb other than "(*ACCEPT)".
     */
    private static function readsNothing(NodeInterface $item): bool
    {
        if (NodePredicates::holdsAccept($item)) {
            return false;
        }

        return $item instanceof AnchorNode
            || $item instanceof AssertionNode
            || $item instanceof KeepNode
            || $item instanceof CommentNode
            || $item instanceof CalloutNode
            || $item instanceof PcreVerbNode
            || ($item instanceof GroupNode && (NodePredicates::isStandaloneInlineFlags($item) || \in_array($item->type, [GroupType::LookaheadPositive, GroupType::LookaheadNegative, GroupType::LookbehindPositive, GroupType::LookbehindNegative], true)));
    }

    private static function circumflexAtTheEnd(AnchorNode|AssertionNode $anchor): RuleViolation
    {
        return self::circumflexViolation($anchor, "Start anchor '^' stands at the end of the subject after consuming characters, making it impossible to match.");
    }

    private static function circumflexViolation(AnchorNode|AssertionNode $anchor, string $message): RuleViolation
    {
        return new RuleViolation(self::ID_START, $message, $anchor->getStartPosition(), end: $anchor->getEndPosition());
    }

    /**
     * The characters the item reads last, as the analyzer of the context
     * reads them and as one reading every byte does: the first reads ASCII
     * alone, where "[^\x00-\x7F]" reads nothing and "[^\x00-\x7E]" reads
     * "\x7F" alone, and the engine reads the bytes or the characters above
     * ASCII too. Null where either cannot tell. Both read the item under
     * the flags in effect at it, $flags, and at each node inside it: under
     * i, "[^a-z]" leaves out the capitals, which it takes after "(?-i)".
     */
    private function lastCharacters(NodeInterface $child, string $flags, LintContext $context): ?ByteCharSet
    {
        // The flags hold u wherever the pattern sets it: no inline flag turns
        // it off, "(?^)" resets i, m, n, r, s and x only.
        $this->ascii[$flags] ??= $context->charSetAnalyzer->withFlags($flags);
        $ascii = self::lastCharactersUnder($child, $this->ascii[$flags]);
        if (null === $ascii) {
            return null;
        }

        $this->everyByte ??= new CharSetAnalyzer($context->pattern->flags, StartOptions::of($context->pattern->source), true);
        $this->everyByteAt[$flags] ??= $this->everyByte->withFlags($flags);
        $everyByte = self::lastCharactersUnder($child, $this->everyByteAt[$flags]);

        return null === $everyByte ? null : $ascii->union($everyByte);
    }

    /**
     * The characters the item reads last under the analyzer at its start,
     * followed through the option settings inside it.
     */
    private static function lastCharactersUnder(NodeInterface $child, CharSetAnalyzer $analyzer): ?ByteCharSet
    {
        return EndOfLineTail::lastCharacters([$child], $analyzer, FlagScope::containsInlineFlags($child) ? $analyzer->inForce($child) : null);
    }

    /**
     * "\b" between two word characters or two non-word characters, "\B"
     * between a word and a non-word character, both neighbours atoms of one
     * character whose sets the automata compare with "\w" under the
     * pattern's mode: "é" is a word character under /u, which turns UCP on,
     * or under "(*UCP)", and its last byte is not one in byte mode.
     */
    private function impossibleBoundary(AssertionNode $boundary, NodeInterface $before, string $flagsBefore, NodeInterface $after, string $flagsAfter, ?string $spelled, LintContext $context): ?RuleViolation
    {
        $unicode = $context->pattern->unicodeMode;
        if (!LanguageQuestions::isOneCharacter($before, $unicode) || !LanguageQuestions::isOneCharacter($after, $unicode)) {
            return null;
        }

        $kindBefore = $this->wordKind($before, $flagsBefore, $spelled, $context);
        $kindAfter = null === $kindBefore ? null : $this->wordKind($after, $flagsAfter, $spelled, $context);
        if (null === $kindBefore || null === $kindAfter) {
            return null;
        }

        $sameKind = $kindBefore === $kindAfter;
        if ('b' === $boundary->value ? !$sameKind : $sameKind) {
            return null;
        }

        return new RuleViolation(
            self::ID_BOUNDARY,
            'b' === $boundary->value
                ? \sprintf("Word boundary '\\b' sits between two %s characters, so it can never match.", $kindBefore ? 'word' : 'non-word')
                : "Non-boundary '\\B' sits between a word and a non-word character, so it can never match.",
            $boundary->getStartPosition(),
            'b' === $boundary->value ? 'Remove the boundary, or fix the characters around it.' : 'Use \\b, or fix the characters around it.',
            end: $boundary->getEndPosition(),
        );
    }

    /**
     * True when every character the atom reads is a word character, false
     * when none is, null when it reads both kinds or the automata cannot
     * say. A dot reads both kinds whatever the flags, and an ASCII
     * character is of the same kind in every mode, the characters it folds
     * with included ("k" with the Kelvin sign), under every option; the
     * automata decide the rest, unless the pattern sets an option the
     * questions cannot carry ($spelled null).
     */
    private function wordKind(NodeInterface $atom, string $flags, ?string $spelled, LintContext $context): ?bool
    {
        if ($atom instanceof DotNode) {
            return null;
        }

        $character = match (true) {
            $atom instanceof LiteralNode => $atom->value,
            $atom instanceof CharLiteralNode && $atom->codePoint < 0x80 => \chr($atom->codePoint),
            default => null,
        };
        if (null !== $character && 1 === \strlen($character) && \ord($character) < 0x80) {
            return 1 === strspn($character, self::ASCII_WORD);
        }

        if (null === $spelled) {
            return null;
        }

        $atomPattern = LanguageQuestions::pattern($context, LanguageQuestions::text($atom, $context), $flags, $spelled);
        if (\array_key_exists($atomPattern, $this->wordKinds)) {
            return $this->wordKinds[$atomPattern];
        }

        // Past the budget there is no answer, and none is kept: the next
        // pattern asks again.
        $wordPattern = LanguageQuestions::pattern($context, '\\w', $flags, $spelled);
        if (!$this->questions->allows()) {
            return null;
        }

        if (true === LanguageQuestions::isSubset($atomPattern, $wordPattern, $context->dfas, $context->target)) {
            return $this->wordKinds[$atomPattern] = true;
        }

        if (!$this->questions->allows()) {
            return null;
        }

        return $this->wordKinds[$atomPattern] = true === LanguageQuestions::areDisjoint($atomPattern, $wordPattern, $context->dfas, $context->target) ? false : null;
    }

    /**
     * Whether the items from each index to the end may all read nothing,
     * keyed by the index; the end itself, past the last item, may.
     *
     * @param list<NodeInterface> $children
     *
     * @return array<int, bool>
     */
    private static function emptyFromEachItem(array $children): array
    {
        $empty = [\count($children) => true];
        for ($index = \count($children) - 1; $index >= 0; $index--) {
            $empty[$index] = $empty[$index + 1] && NodePredicates::canBeEmpty($children[$index]);
        }

        return $empty;
    }

    /**
     * `$` and `\Z` also match just before the subject's final newline (and
     * a multiline `$` before any newline), so a tail that can continue one
     * of those newline matches is not impossible. `\z` only matches at the
     * absolute end and stays strict, as does `$` under the D modifier
     * (which multiline disables, as PCRE does). The exact-newline
     * continuations additionally require the sequence to end the pattern:
     * `$` before the final newline leaves nothing for content that follows
     * the enclosing group.
     *
     * Under "(*CRLF)" a newline starts with "\r", under "(*ANYCRLF)" with
     * "\r" or "\n": a tail that can continue a line end under m starts with
     * one of them, which is all this checks; after "\Z", or "$" without m,
     * it matches the newline that ends the subject whole. Under "(*ANY)"
     * some newlines lie above ASCII, which the sets do not hold: the rule
     * says nothing.
     *
     * @param ByteCharSet|null          $before the characters the item read just before the anchor can be, null
     *                                          when unknown or when the anchor may come first
     * @param array<int, NodeInterface> $nodes
     */
    private function tailCanContinueEndOfLine(NodeInterface $anchor, ?ByteCharSet $before, array $nodes, string $flags, SequenceNode $sequence, LintContext $context): bool
    {
        $convention = StartOptions::newline($context->pattern->source);
        $newline = match ($convention) {
            'LF' => "\n",
            'CR', 'CRLF' => "\r",
            'NUL' => "\0",
            'ANYCRLF' => "\r\n",
            default => null,
        };
        if (null === $newline) {
            return true;
        }

        $multiline = str_contains($flags, 'm');
        $atTheEnd = $anchor instanceof AssertionNode
            ? 'z' === $anchor->value
            : $context->pattern->hasFlag('D') && !$multiline;

        $tail = $this->tail($newline, $flags, $sequence, $context, $before);
        if ($tail->canMatchNothingHere($nodes, $atTheEnd)) {
            return true;
        }

        if ($atTheEnd) {
            return false;
        }

        $dotAll = str_contains($flags, 's');
        if ($multiline && !$anchor instanceof AssertionNode) {
            return $tail->canStartWithNewline($nodes, $dotAll);
        }

        // "$" without the m modifier and "\Z" before the newline that ends
        // the subject: "\r\n" under "(*CRLF)", one of "\r\n", "\r" and "\n"
        // under "(*ANYCRLF)".
        // A tail that stops inside the newline leaves the rest of it to what
        // follows, one that reads it whole leaves the end of the subject.
        if ('CRLF' === $convention || 'ANYCRLF' === $convention) {
            [$inside, $whole] = $tail->finalNewlineStops($nodes, $convention, $dotAll);

            return $inside || ($whole && $context->followersPass($sequence, $tail->canMatchNothingAtTheEnd(...)));
        }

        // The newline the tail matches is the subject's last character: what
        // follows the sequence, up to the end of the pattern, matches nothing.
        return $tail->canMatchTheLastNewline($nodes, $dotAll)
            && $context->followersPass($sequence, $tail->canMatchNothingAtTheEnd(...));
    }

    /**
     * The reader of the items after an anchor of the sequence. A set the
     * analyzer cannot read is put to the automata, within the budget: an
     * atom disjoint from the newline never reads it.
     */
    private function tail(string $newline, string $flags, SequenceNode $sequence, LintContext $context, ?ByteCharSet $before): EndOfLineTail
    {
        $spelled = false;
        $reads = function (NodeInterface $atom, string $character) use ($flags, $sequence, $context, &$spelled): ?bool {
            // An atom that may read more than one character, "\X" or "\R", may
            // start with a newline and go on: its whole match says nothing.
            if (!$atom instanceof ExtendedCharClassNode && !LanguageQuestions::isOneCharacter($atom, $context->pattern->unicodeMode)) {
                return null;
            }

            if (false === $spelled) {
                $spelled = LanguageQuestions::spelledFlags($context, $sequence);
            }

            if (null === $spelled) {
                return null;
            }

            $atomPattern = LanguageQuestions::pattern($context, LanguageQuestions::text($atom, $context), $flags, $spelled);
            $key = $atomPattern."\0".$character;
            if (\array_key_exists($key, $this->newlineReads)) {
                return $this->newlineReads[$key];
            }

            if (!$this->questions->allows()) {
                return null;
            }

            $characterPattern = LanguageQuestions::pattern($context, \sprintf('\\x%02x', \ord($character)), $flags, $spelled);

            return $this->newlineReads[$key] = true === LanguageQuestions::areDisjoint($atomPattern, $characterPattern, $context->dfas, $context->target) ? false : null;
        };

        // A byte above ASCII may be a word character under u, under (*UCP)
        // and under the tables of a locale other than PCRE2's own, the "C"
        // locale of the target.
        $wordAboveAscii = $context->pattern->unicodeMode
            || str_contains(StartOptions::of($context->pattern->source), '(*UCP)')
            || 'C' !== $context->target->locale;

        return new EndOfLineTail($context->charSetAnalyzer, $newline, $context->groups->pcre ?? PcreGroups::empty(), $reads, $before, $wordAboveAscii);
    }
}
