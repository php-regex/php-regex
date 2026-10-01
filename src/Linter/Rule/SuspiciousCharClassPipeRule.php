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

use PHPRegex\Linter\Rule\Support\CharClassSets;
use PHPRegex\Linter\Rule\Support\CodePoints;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;

/**
 * Detects character classes that look like alternation typos, e.g. [foo|bar].
 *
 * @internal
 */
final class SuspiciousCharClassPipeRule extends AbstractLintRule
{
    public function getRuleIds(): array
    {
        return ['regex.lint.charclass.suspiciousPipe'];
    }

    public function getNodeTypes(): array
    {
        return [CharClassNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof CharClassNode) {
            return [];
        }

        $parts = CharClassSets::collectParts($node->expression);
        $letters = 0;
        $pipes = 0;

        foreach ($parts as $part) {
            if (!$part instanceof LiteralNode || 1 !== \strlen($part->value)) {
                return [];
            }

            $value = $part->value;
            if ('|' === $value) {
                $pipes++;

                continue;
            }

            $ord = \ord($value);
            if ($ord > 127) {
                return [];
            }

            if (CodePoints::isAsciiLetter($ord)) {
                $letters++;

                continue;
            }

            return [];
        }

        if ($pipes > 0 && $letters >= 4) {
            return [new RuleViolation(
                'regex.lint.charclass.suspiciousPipe',
                'Character class contains "|" which is literal inside []. It looks like an alternation typo.',
                $node->startPosition,
                'Did you mean an alternation like "(error|failure)" instead of a character class?',
            )];
        }

        return [];
    }
}
