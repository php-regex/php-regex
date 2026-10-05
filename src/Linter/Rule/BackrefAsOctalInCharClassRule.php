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
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\NodeInterface;

/**
 * Detects \1-\9 inside character classes where they are treated as
 * octal escapes rather than backreferences.
 *
 * @internal
 */
final class BackrefAsOctalInCharClassRule extends AbstractLintRule
{
    public function getRuleIds(): array
    {
        return ['regex.lint.charclass.backrefAsOctal'];
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

        if (0 === $context->groups->maxCapturingGroup) {
            return [];
        }

        $parts = CharClassSets::collectParts($node->expression);
        foreach ($parts as $part) {
            if (!$part instanceof CharLiteralNode) {
                continue;
            }

            if (CharLiteralType::OctalLegacy !== $part->type) {
                continue;
            }

            // Check if the original representation looks like \1-\9 (a single digit backref)
            if (!LibraryPcre::match('/^\\\\([1-9])$/', $part->originalRepresentation, $m)) {
                continue;
            }

            $num = (int) $m[1];
            if ($num > $context->groups->maxCapturingGroup) {
                continue;
            }

            return [new RuleViolation(
                'regex.lint.charclass.backrefAsOctal',
                \sprintf(
                    'Suspicious \\%d inside character class: this is octal (\\x%02X), not a backreference to group %d.',
                    $num,
                    $part->codePoint,
                    $num,
                ),
                $part->startPosition,
                \sprintf(
                    'Backreferences do not work inside character classes. '
                    .'If you intended to match the same text as group %d, move the check outside the class.',
                    $num,
                ),
            )];
        }

        return [];
    }
}
