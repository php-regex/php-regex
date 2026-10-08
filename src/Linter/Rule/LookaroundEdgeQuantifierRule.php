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

use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\Rule\Support\LanguageQuestions;
use PHPRegex\Linter\Rule\Support\NodePredicates;
use PHPRegex\Linter\Rule\Support\QuestionBudget;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;

/**
 * A perf rule: a lookahead only checks that its body can match, so the
 * last item repeated past its minimum changes nothing, "(?=a{2,6})"
 * asserting what "(?=a{2})" asserts; the same holds for the first item of
 * a lookbehind. Reported once the automata prove both bodies assert the
 * same at every position, and only for a body that holds a language alone:
 * a capture keeps the run, a reference or a call reads more, so either
 * keeps it silent; never in a non-atomic lookahead, which the engine may
 * come back into.
 *
 * @internal
 */
final class LookaroundEdgeQuantifierRule extends AbstractLintRule
{
    private const ID = 'regex.lint.lookaround.edgeQuantifier';

    private readonly QuestionBudget $questions;

    public function __construct()
    {
        $this->questions = new QuestionBudget();
    }

    public function getRuleIds(): array
    {
        return [self::ID];
    }

    public function getNodeTypes(): array
    {
        return [GroupNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof GroupNode || '*' === $node->flags) {
            return [];
        }

        $behind = \in_array($node->type, [GroupType::LookbehindPositive, GroupType::LookbehindNegative], true);
        if (!$behind && !\in_array($node->type, [GroupType::LookaheadPositive, GroupType::LookaheadNegative], true)) {
            return [];
        }

        // What the body sets or reads beyond its language: a capture, a
        // reference, a call, a verb, a callout, \K.
        if (self::holdsMoreThanALanguage($node->child)) {
            return [];
        }

        // A lookbehind of variable length reads the end of the subject at
        // its own position ("\z", "$", "\b", a lookahead see nothing past
        // it), one of fixed length the real end: cutting a repeat may turn
        // the first into the second.
        if ($behind && self::testsAPosition($node->child)) {
            return [];
        }

        $issues = [];
        $branches = $node->child instanceof AlternationNode ? $node->child->alternatives : [$node->child];
        foreach ($branches as $branch) {
            $repeat = self::edgeItem($branch, $behind);
            if (!$repeat instanceof QuantifierNode) {
                continue;
            }

            $issue = $this->checkRepeat($repeat, $node, $behind, $context);
            if (null !== $issue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    private function checkRepeat(QuantifierNode $repeat, GroupNode $lookaround, bool $behind, LintContext $context): ?RuleViolation
    {
        $bounds = QuantifierBounds::parse($repeat->quantifier);
        if (null === $bounds || $bounds->min === $bounds->max) {
            return null;
        }

        $item = LanguageQuestions::text($repeat->node, $context);
        $minimum = match ($bounds->min) {
            0 => '',
            1 => $item,
            default => $item.'{'.$bounds->min.'}',
        };

        $start = $repeat->getStartPosition();
        $end = $repeat->getEndPosition();
        $bodyStart = $lookaround->child->getStartPosition();
        $body = LanguageQuestions::text($lookaround->child, $context);

        // The lookahead holds where a prefix of the rest is in its body's
        // language, the lookbehind where a suffix of what precedes is: the
        // two bodies assert the same when those closures are equal.
        $shortened = substr($body, 0, $start - $bodyStart).$minimum.substr($body, $end - $bodyStart);
        $spelled = LanguageQuestions::spelledFlags($context, $lookaround);
        if (null === $spelled || !$this->questions->allows($context)) {
            return null;
        }

        $flags = $context->activeFlags();
        $closure = static fn (string $part): string => LanguageQuestions::pattern($context, $behind ? '(?s:.*)(?:'.$part.')' : '(?:'.$part.')(?s:.*)', $flags, $spelled);
        $written = $closure($body);
        $minimal = $closure($shortened);
        if (true !== LanguageQuestions::isSubset($written, $minimal) || true !== LanguageQuestions::isSubset($minimal, $written)) {
            return null;
        }

        $groupStart = $lookaround->getStartPosition();
        $written = LanguageQuestions::text($lookaround, $context);
        $asserted = substr($written, 0, $start - $groupStart).$minimum.substr($written, $end - $groupStart);

        return new RuleViolation(
            self::ID,
            \sprintf(
                '"%s" %s: only its minimum is ever checked, "%s" asserts the same.',
                LanguageQuestions::text($repeat, $context),
                $behind ? 'starts a lookbehind' : 'ends a lookahead',
                $asserted,
            ),
            $start,
            \sprintf('Write "%s".', $asserted),
            LintSeverity::Perf,
        );
    }

    private static function holdsMoreThanALanguage(NodeInterface $node): bool
    {
        if ($node instanceof BackrefNode
            || $node instanceof SubroutineNode
            || $node instanceof PcreVerbNode
            || $node instanceof CalloutNode
            || $node instanceof KeepNode
            || $node instanceof ConditionalNode
            || $node instanceof DefineNode
            || ($node instanceof GroupNode && \in_array($node->type, [GroupType::Capturing, GroupType::Named, GroupType::BranchReset], true))
        ) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::holdsMoreThanALanguage($child)) {
                return true;
            }
        }

        return false;
    }

    private static function testsAPosition(NodeInterface $node): bool
    {
        if ($node instanceof AnchorNode || $node instanceof AssertionNode || ($node instanceof GroupNode && !NodePredicates::isTransparentGroup($node->type))) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::testsAPosition($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The item a lookahead's branch ends with, or a lookbehind's starts
     * with, comments aside.
     */
    private static function edgeItem(NodeInterface $branch, bool $behind): ?NodeInterface
    {
        if (!$branch instanceof SequenceNode) {
            return $branch;
        }

        $items = array_values(array_filter($branch->children, static fn (NodeInterface $child): bool => !$child instanceof CommentNode));
        if ([] === $items) {
            return null;
        }

        return $behind ? $items[0] : $items[\count($items) - 1];
    }
}
