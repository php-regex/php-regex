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
use PHPRegex\Parser\Internal\PatternParser;
use PHPRegex\Parser\Internal\StartOptions;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;

/**
 * A style rule: a lazy quantifier whose greedy form writes the same
 * $matches on every subject, as "(a+?)b" and "(a+)b", only makes the
 * reader wonder why it is lazy. Reported once the automata prove the
 * pattern without the lazy marker matches as written; never under U, where
 * "+?" is the greedy one, nor where nothing that can fail follows a
 * variable count (quantifier.lazyEnd speaks for it), nor in a pattern that
 * may match the empty string or sets a match limit.
 *
 * @internal
 */
final class UselessLazyRule extends AbstractLintRule
{
    private const ID = 'regex.lint.quantifier.uselessLazy';

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
        return [QuantifierNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof QuantifierNode || QuantifierType::Lazy !== $node->type || str_contains($context->activeFlags(), 'U')) {
            return [];
        }

        $bounds = QuantifierBounds::parse($node->quantifier);
        if (null === $bounds) {
            return [];
        }

        if ($bounds->min !== $bounds->max && ($context->endsThePatternForEveryCall($node) || $context->onlyEmptyMatchesFollowForEveryCall($node))) {
            return [];
        }

        // The automata compare one preg_match() call. After an empty match,
        // preg_match_all(), preg_replace() and preg_split() try again at the
        // same offset for a non-empty one, where the two forms part; and a
        // match limit the pattern sets stops the one that backtracks more.
        $pattern = $context->pattern;
        if (NodePredicates::canBeEmpty($context->parents()[0] ?? $node) || str_contains(StartOptions::of($pattern->source), 'LIMIT_')) {
            return [];
        }

        $start = $node->getStartPosition();
        $end = $node->getEndPosition();
        if ('?' !== substr($pattern->source, $end - 1, 1)) {
            return [];
        }

        $closing = PatternParser::closingDelimiter($pattern->delimiter);
        $original = $pattern->delimiter.$pattern->source.$closing.$pattern->flags;
        $greedy = $pattern->delimiter.substr($pattern->source, 0, $end - 1).substr($pattern->source, $end).$closing.$pattern->flags;
        if (!$this->questions->allows($context) || true !== LanguageQuestions::matchTheSame($original, $greedy)) {
            return [];
        }

        $written = LanguageQuestions::text($node, $context);

        return [new RuleViolation(
            self::ID,
            \sprintf('Lazy quantifier "%s" matches what "%s" matches: the lazy marker changes nothing.', $written, substr($written, 0, -1)),
            $start,
            'Drop the "?".',
            LintSeverity::Style,
        )];
    }
}
