<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Linter\Rule;

use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharLiteralType;
use PhpRegex\Parser\Node\NodeInterface;

/**
 * Detects out-of-range Unicode and octal escapes and unknown Unicode
 * character names.
 */
final class SuspiciousEscapeRule extends AbstractLintRule
{
    public function getRuleIds(): array
    {
        return ['regex.lint.escape.suspicious'];
    }

    public function getNodeTypes(): array
    {
        return [CharLiteralNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof CharLiteralNode) {
            return [];
        }

        if (CharLiteralType::Unicode === $node->type && $node->codePoint > 0x10FFFF) {
            return [new RuleViolation(
                'regex.lint.escape.suspicious',
                \sprintf('Suspicious Unicode escape "%s" (out of range).', $node->originalRepresentation),
                $node->startPosition,
            )];
        }

        // Past "\377", an octal escape is a code point in UTF mode only.
        if (\in_array($node->type, [CharLiteralType::Octal, CharLiteralType::OctalLegacy], true) && $node->codePoint > 0xFF && !$context->pattern->unicodeMode) {
            return [new RuleViolation(
                'regex.lint.escape.suspicious',
                \sprintf('Suspicious octal escape "%s" (out of range).', $node->originalRepresentation),
                $node->startPosition,
            )];
        }

        if (CharLiteralType::UnicodeNamed === $node->type && class_exists(\IntlChar::class)) {
            $name = $node->originalRepresentation;
            if (preg_match('/^\\\\N\\{(.+)}$/', $name, $matches)) {
                $char = \IntlChar::charFromName($matches[1]);
                if (null === $char) {
                    return [new RuleViolation(
                        'regex.lint.escape.suspicious',
                        \sprintf('Unknown Unicode character name "%s".', $matches[1]),
                        $node->startPosition,
                    )];
                }
            }
        }

        return [];
    }
}
