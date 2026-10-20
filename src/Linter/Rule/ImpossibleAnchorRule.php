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
use PHPRegex\Linter\Rule\Support\LanguageQuestions;
use PHPRegex\Linter\Rule\Support\NodePredicates;
use PHPRegex\Linter\Rule\Support\QuestionBudget;
use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPRegex\Parser\Analysis\GroupIndex as PcreGroups;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
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
    /**
     * The ASCII word characters, which are word characters in every mode.
     */
    private const ASCII_WORD = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_';

    /**
     * What the automata said of the atoms beside a boundary, keyed by the
     * pattern they were asked about: a pattern repeats its atoms, and under
     * /u each question reads "\w" over the whole of Unicode.
     *
     * @var array<string, bool|null>
     */
    private array $wordKinds = [];

    /**
     * What the automata said of whether an atom reads a newline character,
     * keyed by the question.
     *
     * @var array<string, false|null>
     */
    private array $newlineReads = [];

    private readonly QuestionBudget $questions;

    public function __construct()
    {
        $this->questions = new QuestionBudget();
    }

    public function getRuleIds(): array
    {
        return ['regex.lint.anchor.impossible.start', 'regex.lint.anchor.impossible.end', 'regex.lint.anchor.impossible.boundary'];
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
        // whether they may all read nothing, and the characters the last of
        // them reads (an empty set when none reads one, null when unknown).
        $prefixCanBeEmpty = true;
        $before = ByteCharSet::empty();
        $tailCanBeEmpty = null;

        for ($i = 0; $i < $count; $i++) {
            $child = $children[$i];
            $flags = $flagsAtChild[$i];

            if (NodePredicates::isStartAnchorNode($child)) {
                $skipForMultiline = $child instanceof AnchorNode
                    && '^' === $child->value
                    && str_contains($flags, 'm');

                if (!$skipForMultiline && $i > 0 && !$prefixCanBeEmpty) {
                    $issues[] = new RuleViolation(
                        'regex.lint.anchor.impossible.start',
                        \sprintf(
                            "Start anchor '%s' appears after consuming characters, making it impossible to match.",
                            NodePredicates::anchorDisplay($child),
                        ),
                        $child->getStartPosition(),
                        end: $child->getEndPosition(),
                    );
                }
            }

            if (NodePredicates::isEndAnchorNode($child) && $i + 1 < $count) {
                $tailCanBeEmpty ??= self::emptyFromEachItem($children);
                if (!$tailCanBeEmpty[$i + 1]
                    && !$this->tailCanContinueEndOfLine($child, null === $before || $before->isEmpty() ? null : $before, \array_slice($children, $i + 1), $flags, $node, $context)
                ) {
                    $issues[] = new RuleViolation(
                        'regex.lint.anchor.impossible.end',
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

            $prefixCanBeEmpty = $prefixCanBeEmpty && NodePredicates::canBeEmpty($child);
            $last = EndOfLineTail::lastCharacters([$child], $context->charSetAnalyzer);
            if (null === $last || !$last->isEmpty()) {
                $before = $last;
            }
        }

        return $issues;
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
            'regex.lint.anchor.impossible.boundary',
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
        if (!$this->questions->allows($context)) {
            return null;
        }

        if (true === LanguageQuestions::isSubset($atomPattern, $wordPattern, $context->dfas, $context->target)) {
            return $this->wordKinds[$atomPattern] = true;
        }

        if (!$this->questions->allows($context)) {
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
     * "\r" or "\n": a tail that can continue a line end starts with one of
     * them, which is all this checks. Under "(*ANY)" some newlines lie above
     * ASCII, which the sets do not hold: the rule says nothing.
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
        if (1 < \strlen($newline) || 'CRLF' === $convention || ($multiline && !$anchor instanceof AssertionNode)) {
            return $tail->canStartWithNewline($nodes, $dotAll);
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
            if (!LanguageQuestions::isOneCharacter($atom, $context->pattern->unicodeMode)) {
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

            if (!$this->questions->allows($context)) {
                return null;
            }

            $characterPattern = LanguageQuestions::pattern($context, \sprintf('\\x%02x', \ord($character)), $flags, $spelled);

            return $this->newlineReads[$key] = true === LanguageQuestions::areDisjoint($atomPattern, $characterPattern, $context->dfas, $context->target) ? false : null;
        };

        return new EndOfLineTail($context->charSetAnalyzer, $newline, $context->groups->pcre ?? PcreGroups::empty(), $reads, $before);
    }
}
