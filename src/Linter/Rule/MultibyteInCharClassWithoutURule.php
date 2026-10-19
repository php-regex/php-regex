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
use PHPRegex\Parser\Text\Bytes;

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

        $character = self::firstMultibyteCharacter($node->expression, $context->pattern->source);
        if (null === $character) {
            return [];
        }

        [$value, $start, $end] = $character;

        return [new RuleViolation(
            self::ID,
            \sprintf(
                'Character class holds "%s", which without /u is the bytes %s: the class matches each byte on its own.',
                $value,
                implode(' ', array_map(static fn (string $byte): string => \sprintf('\x%02X', \ord($byte)), Bytes::split($value))),
            ),
            $start,
            'Add the /u flag; write the bytes as \x escapes if bytes are meant.',
            LintSeverity::Error,
            end: $end,
        )];
    }

    /**
     * The first multibyte character of the class, where it stands: a
     * literal, or the bytes of one the tree holds apart.
     *
     * @return array{string, int, int}|null
     */
    private static function firstMultibyteCharacter(NodeInterface $node, string $source): ?array
    {
        if ($node instanceof LiteralNode) {
            if (\strlen($node->value) > 1 && 1 === mb_strlen($node->value, 'UTF-8') && mb_check_encoding($node->value, 'UTF-8')) {
                return [$node->value, $node->getStartPosition(), $node->getEndPosition()];
            }

            return SplitCharacter::startedBy($source, $node);
        }

        foreach ($node->getChildren() as $child) {
            $character = self::firstMultibyteCharacter($child, $source);
            if (null !== $character) {
                return $character;
            }
        }

        return null;
    }
}
