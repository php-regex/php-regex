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
use PHPRegex\Linter\Rule\Support\QuantifierMath;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\SequenceNode;

/**
 * A style rule: two or more literal spaces in a row are hard to count, and
 * " {n}" says how many. Only spaces written bare are counted: under x or
 * xx they are no items at all, and quoted ("\Q  \E"), escaped or class
 * spaces are not bare. A quantifier on the last space enters the count:
 * "a  +" is "a {2,}".
 *
 * @internal
 */
final class MultipleSpacesRule extends AbstractLintRule
{
    private const ID = 'regex.lint.literal.multipleSpaces';

    public function getRuleIds(): array
    {
        return [self::ID];
    }

    public function getNodeTypes(): array
    {
        return [SequenceNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof SequenceNode) {
            return []; // never taken: getNodeTypes() dispatches sequences only
        }

        $issues = [];
        $run = [];
        foreach ($node->children as $child) {
            if ($this->isBareSpace($child, $context)) {
                $run[] = $child;

                continue;
            }

            if ($child instanceof QuantifierNode && $this->isBareSpace($child->node, $context)) {
                $run[] = $child;
            }

            $issues = [...$issues, ...$this->report($run)];
            $run = [];
        }

        return [...$issues, ...$this->report($run)];
    }

    /**
     * @param list<NodeInterface> $run
     *
     * @return list<RuleViolation>
     */
    private function report(array $run): array
    {
        if (\count($run) < 2) {
            return [];
        }

        $last = $run[\count($run) - 1];
        $count = $last instanceof QuantifierNode ? \count($run) - 1 : \count($run);
        $repeat = '{'.$count.'}';
        if ($last instanceof QuantifierNode) {
            [$lower, $upper] = QuantifierMath::parseRange($last->quantifier);
            $min = $count + $lower;
            $max = null === $upper ? null : $count + $upper;
            $repeat = match (true) {
                null === $max => '{'.$min.',}',
                $min === $max => '{'.$min.'}',
                default => '{'.$min.','.$max.'}',
            }.match ($last->type) {
                QuantifierType::Lazy => '?',
                QuantifierType::Possessive => '+',
                default => '',
            };
        }

        return [new RuleViolation(
            self::ID,
            'A run of literal spaces is hard to count.',
            $run[0]->getStartPosition(),
            \sprintf('Write " %s" instead.', $repeat),
            LintSeverity::Style,
        )];
    }

    private function isBareSpace(NodeInterface $node, LintContext $context): bool
    {
        return $node instanceof LiteralNode && ' ' === $node->value && ' ' === LanguageQuestions::text($node, $context);
    }
}
