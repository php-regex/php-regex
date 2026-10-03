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
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;

/**
 * Detects a multibyte character written in a character class without /u:
 * PCRE then reads the class byte by byte, so "[é]" is the class of the bytes
 * \xC3 and \xA9 and matches "à", whose first byte is \xC3.
 *
 * @internal
 */
final class MultibyteInCharClassWithoutURule extends AbstractLintRule
{
    private const ID = 'regex.lint.unicode.multibyteInClassWithoutU';

    public function getRuleIds(): array
    {
        return [self::ID];
    }

    public function getNodeTypes(): array
    {
        return [CharClassNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if ($context->pattern->unicodeMode || !$node instanceof CharClassNode) {
            return [];
        }

        $literal = self::firstMultibyteLiteral($node->expression);
        if (null === $literal) {
            return [];
        }

        return [new RuleViolation(
            self::ID,
            \sprintf(
                'Character class holds "%s", which without /u is the bytes %s: the class matches each byte on its own.',
                $literal->value,
                implode(' ', array_map(static fn (string $byte): string => \sprintf('\x%02X', \ord($byte)), str_split($literal->value))),
            ),
            $literal->getStartPosition(),
            'Add the /u flag; write the bytes as \x escapes if bytes are meant.',
            LintSeverity::Error,
        )];
    }

    private static function firstMultibyteLiteral(NodeInterface $node): ?LiteralNode
    {
        if ($node instanceof LiteralNode) {
            return \strlen($node->value) > 1 && 1 === mb_strlen($node->value, 'UTF-8') && mb_check_encoding($node->value, 'UTF-8') ? $node : null;
        }

        foreach ($node->getChildren() as $child) {
            $literal = self::firstMultibyteLiteral($child);
            if (null !== $literal) {
                return $literal;
            }
        }

        return null;
    }
}
