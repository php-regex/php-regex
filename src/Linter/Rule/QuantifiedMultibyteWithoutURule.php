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
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\SequenceNode;

/**
 * Detects a quantifier written after a multibyte character without /u: PCRE
 * repeats the last byte only, so "/^é+$/" matches "é\xA9" and not "éé". The
 * tree holds the leading bytes, then the quantified last byte.
 *
 * @internal
 */
final class QuantifiedMultibyteWithoutURule extends AbstractLintRule
{
    private const ID = 'regex.lint.unicode.quantifiedMultibyteWithoutU';

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
        if ($context->pattern->unicodeMode || !$node instanceof SequenceNode) {
            return [];
        }

        $violations = [];
        $children = $node->children;
        foreach ($children as $index => $quantifier) {
            if (0 === $index || !$quantifier instanceof QuantifierNode || !$quantifier->node instanceof LiteralNode) {
                continue;
            }

            $before = $children[$index - 1];
            $character = $before instanceof LiteralNode ? self::character($before->value, $quantifier->node->value) : null;
            if (null === $character) {
                continue;
            }

            $violations[] = new RuleViolation(
                self::ID,
                \sprintf('Quantifier "%s" repeats only the last byte of "%s" without /u.', $quantifier->quantifier, $character),
                $quantifier->getStartPosition(),
                \sprintf('Add the /u flag, or group the character: (?:%s)%s.', $character, $quantifier->quantifier),
                LintSeverity::Error,
            );
        }

        return $violations;
    }

    /**
     * The UTF-8 character the leading bytes and the repeated last byte spell.
     */
    private static function character(string $before, string $last): ?string
    {
        if (1 !== \strlen($last) || \ord($last) < 0x80 || \ord($last) > 0xBF) {
            return null;
        }

        for ($length = 1; $length <= 3 && $length <= \strlen($before); $length++) {
            $candidate = substr($before, -$length).$last;
            if (mb_check_encoding($candidate, 'UTF-8') && 1 === mb_strlen($candidate, 'UTF-8')) {
                return $candidate;
            }
        }

        return null;
    }
}
