<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Linter\Rule;

use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\NodeInterface;

/**
 * Detects redundant inline flags and inline flags that override a global
 * modifier.
 *
 * The two rule IDs interleave per flag; keeping them in one rule preserves
 * the historical emission order.
 */
final class InlineFlagsRule extends AbstractLintRule
{
    public function getRuleIds(): array
    {
        return ['regex.lint.flag.redundant', 'regex.lint.flag.override'];
    }

    public function getNodeTypes(): array
    {
        return [GroupNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof GroupNode) {
            return [];
        }

        if (GroupType::InlineFlags !== $node->type || null === $node->flags) {
            return [];
        }

        $flags = (string) $node->flags;
        if ('' === $flags) {
            return [];
        }

        $resetAll = str_starts_with($flags, '^');
        if ($resetAll) {
            $flags = substr($flags, 1);
        }

        [$set, $unset] = str_contains($flags, '-')
            ? explode('-', $flags, 2)
            : [$flags, ''];

        $baseFlags = $resetAll ? '' : $context->activeFlags();
        $issues = [];

        foreach (str_split($set) as $flag) {
            if ('' === $flag) {
                continue;
            }
            if (str_contains($baseFlags, $flag)) {
                $issues[] = new RuleViolation(
                    'regex.lint.flag.redundant',
                    \sprintf(
                        "Inline flag '%s' is redundant; it is already %s.",
                        $flag,
                        $this->originOf($flag, $context, $resetAll),
                    ),
                    $node->startPosition,
                );
            }
        }

        foreach (str_split($unset) as $flag) {
            if ('' === $flag) {
                continue;
            }

            if (!str_contains($baseFlags, $flag)) {
                $issues[] = new RuleViolation(
                    'regex.lint.flag.redundant',
                    \sprintf("Inline flag '-%s' is redundant; the flag is not set at this position.", $flag),
                    $node->startPosition,
                    \sprintf("Remove '-%s' from the inline flag group; it has no effect unless the flag is enabled first.", $flag),
                );
            } else {
                $issues[] = new RuleViolation(
                    'regex.lint.flag.override',
                    \sprintf(
                        "Inline flag '-%s' overrides a flag %s.",
                        $flag,
                        $this->originOf($flag, $context, $resetAll),
                    ),
                    $node->startPosition,
                    'Consider removing the outer flag or limiting it to specific groups.',
                );
            }
        }

        return $issues;
    }

    /**
     * Tell apart a flag inherited from the pattern modifiers and one enabled
     * by an inline flag group earlier in the pattern.
     */
    private function originOf(string $flag, LintContext $context, bool $resetAll): string
    {
        return !$resetAll && str_contains($context->pattern->flags, $flag)
            ? 'set globally'
            : 'set by an earlier inline flag group';
    }
}
