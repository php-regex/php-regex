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
use PHPRegex\Linter\Rule\Support\QuestionBudget;
use PHPRegex\Parser\Internal\PatternParser;
use PHPRegex\Parser\Internal\StartOptions;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\SequenceNode;

/**
 * A perf rule: '".*?"' backtracks one character at a time where
 * '"[^"\n]*"' reads the run at once, and matches the same text. Reported
 * only when nothing that can fail follows the closing character (a later
 * failure lets the lazy dot cross it, which the class cannot), never under
 * a newline convention other than "\n" (the dot then stops at another
 * character), and only once the automata prove the two patterns write the
 * same $matches on every subject.
 *
 * @internal
 */
final class LazyToClassRule extends AbstractLintRule
{
    private const ID = 'regex.lint.quantifier.lazyToClass';

    /**
     * The characters a class needs escaped.
     */
    private const CLASS_SPECIALS = ['\\', ']', '^', '-'];

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
        if (!$node instanceof QuantifierNode || !$node->node instanceof DotNode || QuantifierType::Possessive === $node->type) {
            return [];
        }

        $flags = $context->activeFlags();
        $lazy = (QuantifierType::Lazy === $node->type) !== str_contains($flags, 'U');
        $bounds = QuantifierBounds::parse($node->quantifier);
        if (!$lazy || null === $bounds || null !== $bounds->max || $bounds->min > 1) {
            return [];
        }

        $parents = $context->parents();
        $sequence = end($parents);
        if (!$sequence instanceof SequenceNode) {
            return [];
        }

        $children = array_values($sequence->children);
        $index = array_search($node, $children, true);
        $closing = \is_int($index) ? ($children[$index + 1] ?? null) : null;
        if (!$closing instanceof LiteralNode
            || 1 !== \strlen($closing->value)
            || \ord($closing->value) > 0x7F
            || "\n" === $closing->value
            || "\r" === $closing->value
            || 'LF' !== StartOptions::newline($context->pattern->source)
            || !$context->endsThePatternForEveryCall($closing)
        ) {
            return [];
        }

        $pattern = $context->pattern;
        $delimiters = [$pattern->delimiter, PatternParser::closingDelimiter($pattern->delimiter)];
        $member = \in_array($closing->value, [...self::CLASS_SPECIALS, ...$delimiters], true) ? '\\'.$closing->value : $closing->value;
        $class = '[^'.$member.(str_contains($flags, 's') ? '' : '\n').']'.(0 === $bounds->min ? '*' : '+');

        $start = $node->getStartPosition();
        $end = $node->getEndPosition();
        $original = $pattern->delimiter.$pattern->source.$delimiters[1].$pattern->flags;
        $rewritten = $pattern->delimiter.substr($pattern->source, 0, $start).$class.substr($pattern->source, $end).$delimiters[1].$pattern->flags;
        if (!$this->questions->allows($context) || true !== LanguageQuestions::matchTheSame($original, $rewritten)) {
            return [];
        }

        $before = \is_int($index) && $index > 0 && $children[$index - 1] instanceof LiteralNode ? LanguageQuestions::text($children[$index - 1], $context) : '';
        $suggested = $before.$class.LanguageQuestions::text($closing, $context);

        return [new RuleViolation(
            self::ID,
            \sprintf('Lazy quantifier "%s" backtracks one character at a time up to the closing "%s"; a negated class reads the run at once and matches the same text.', LanguageQuestions::text($node, $context), LanguageQuestions::text($closing, $context)),
            $start,
            \sprintf('Write "%s" instead.', $suggested),
            LintSeverity::Perf,
        )];
    }
}
