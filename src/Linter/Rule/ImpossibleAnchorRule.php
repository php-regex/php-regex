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

use PHPRegex\Automata\Solver\InMemoryDfaCache;
use PHPRegex\Linter\Rule\Support\LanguageQuestions;
use PHPRegex\Linter\Rule\Support\NodePredicates;
use PHPRegex\Linter\Rule\Support\QuestionBudget;
use PHPRegex\Parser\Internal\StartOptions;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\SequenceNode;

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
     * The DFAs the automata built for this rule's questions, kept for the
     * ones that read the same pattern again.
     */
    private readonly InMemoryDfaCache $dfas;

    private readonly QuestionBudget $questions;

    public function __construct()
    {
        $this->dfas = new InMemoryDfaCache();
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
        $children = array_values($node->children);
        $count = \count($children);
        $flagsAtChild = null;
        $spelled = false;

        for ($i = 0; $i < $count; $i++) {
            $child = $children[$i];
            $flags = $this->effectiveFlagsAt($children, $i, $context);

            if (NodePredicates::isStartAnchorNode($child)) {
                $skipForMultiline = $child instanceof AnchorNode
                    && '^' === $child->value
                    && str_contains($flags, 'm');

                if (!$skipForMultiline) {
                    $anchorLabel = NodePredicates::anchorDisplay($child);
                    $prefix = array_values(array_slice($children, 0, $i));
                    if ([] !== $prefix && !NodePredicates::sequenceCanBeEmpty($prefix)) {
                        $issues[] = new RuleViolation(
                            'regex.lint.anchor.impossible.start',
                            \sprintf(
                                "Start anchor '%s' appears after consuming characters, making it impossible to match.",
                                $anchorLabel,
                            ),
                            $child->getStartPosition(),
                        );
                    }
                }
            }

            if (NodePredicates::isEndAnchorNode($child)) {
                $anchorLabel = NodePredicates::anchorDisplay($child);
                $tail = array_values(array_slice($children, $i + 1));
                if ([] !== $tail
                    && !NodePredicates::sequenceCanBeEmpty($tail)
                    && !$this->tailCanContinueEndOfLine($child, $tail, $flags, $node, $context)
                ) {
                    $issues[] = new RuleViolation(
                        'regex.lint.anchor.impossible.end',
                        \sprintf(
                            "End anchor '%s' appears before consuming characters, making it impossible to match.",
                            $anchorLabel,
                        ),
                        $child->getStartPosition(),
                    );
                }
            }

            if ($child instanceof AssertionNode && ('b' === $child->value || 'B' === $child->value) && $i > 0 && $i + 1 < $count) {
                $flagsAtChild ??= array_values($context->flagsAtEachChild($node));
                if (false === $spelled) {
                    $spelled = LanguageQuestions::spelledFlags($context, $node);
                }
                $boundary = $this->impossibleBoundary($child, $children[$i - 1], $flagsAtChild[$i - 1], $children[$i + 1], $flagsAtChild[$i + 1], $spelled, $context);
                if (null !== $boundary) {
                    $issues[] = $boundary;
                }
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

        if (true === LanguageQuestions::isSubset($atomPattern, $wordPattern, $this->dfas)) {
            return $this->wordKinds[$atomPattern] = true;
        }

        if (!$this->questions->allows($context)) {
            return null;
        }

        return $this->wordKinds[$atomPattern] = true === LanguageQuestions::areDisjoint($atomPattern, $wordPattern, $this->dfas) ? false : null;
    }

    /**
     * The enclosing-scope flags folded with every bare (?flags) group that
     * precedes the child inside its own sequence.
     *
     * @param array<int, NodeInterface> $children
     */
    private function effectiveFlagsAt(array $children, int $index, LintContext $context): string
    {
        $flags = $context->activeFlags();

        for ($j = 0; $j < $index; $j++) {
            $sibling = $children[$j];
            if ($sibling instanceof GroupNode
                && null !== $sibling->flags
                && NodePredicates::isStandaloneInlineFlagsGroup($sibling)) {
                $flags = NodePredicates::applyInlineFlags($flags, $sibling->flags);
            }
        }

        return $flags;
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
     * @param array<int, NodeInterface> $nodes
     */
    private function tailCanContinueEndOfLine(NodeInterface $anchor, array $nodes, string $flags, SequenceNode $sequence, LintContext $context): bool
    {
        $multiline = str_contains($flags, 'm');
        $dotAll = str_contains($flags, 's');

        // The checks below know a newline of one character: under
        // "(*CRLF)", "(*ANYCRLF)" and "(*ANY)" the rule says nothing.
        $newline = match (StartOptions::newline($context->pattern->source)) {
            'LF' => "\n",
            'CR' => "\r",
            'NUL' => "\0",
            default => null,
        };
        if (null === $newline) {
            return true;
        }

        if ($anchor instanceof AssertionNode) {
            if ('z' === $anchor->value) {
                return false;
            }

            return NodePredicates::tailCanMatchNewline($nodes, $context->charSetAnalyzer, $dotAll, $newline)
                && $this->sequenceEndsPattern($sequence, $context);
        }

        if ($context->pattern->hasFlag('D') && !$multiline) {
            return false;
        }

        if ($multiline) {
            return NodePredicates::tailCanStartWithNewline($nodes, $context->charSetAnalyzer, $dotAll, $newline);
        }

        return NodePredicates::tailCanMatchNewline($nodes, $context->charSetAnalyzer, $dotAll, $newline)
            && $this->sequenceEndsPattern($sequence, $context);
    }

    /**
     * Whether only always-empty content follows this sequence inside its
     * enclosing scopes — the newline a `$`/`\Z` continuation consumes is
     * the subject's last byte, so anything consuming after the enclosing
     * group would make the match impossible after all.
     */
    private function sequenceEndsPattern(SequenceNode $node, LintContext $context): bool
    {
        $child = $node;

        foreach (array_reverse($context->parents()) as $parent) {
            if ($parent instanceof SequenceNode) {
                $index = array_search($child, $parent->children, true);

                if (false !== $index) {
                    $followers = array_values(array_slice($parent->children, (int) $index + 1));
                    if (!NodePredicates::alwaysMatchesEmpty($followers, true)) {
                        return false;
                    }
                }
            }

            $child = $parent;
        }

        return true;
    }
}
