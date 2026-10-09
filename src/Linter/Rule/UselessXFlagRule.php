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

use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\NodeInterface;

/**
 * Detects a useless 'x' flag: x only drops whitespace and "#" comments, and
 * the pattern holds neither.
 *
 * Whitespace is looked for in the body as written, which the AST no longer
 * holds: a space in a class or after a backslash, which x keeps, silences
 * the rule too.
 *
 * Stateful: tracks "#" comments during traversal and emits in finish().
 *
 * @internal
 */
final class UselessXFlagRule extends AbstractLintRule
{
    /**
     * The whitespace x may drop: ASCII whitespace, the byte 0x85 (also the
     * last byte of U+0085), and the UTF-8 of U+200E, U+200F, U+2028, U+2029;
     * and 0xA0 (also the last byte of U+00A0), which x drops under a UTF-8
     * LC_CTYPE, where PHP hands PCRE the locale's tables.
     */
    private const WHITESPACE = '/[\t\n\v\f\r \x85\xA0]|\xE2\x80[\x8E\x8F\xA8\xA9]/';

    private bool $hasComment = false;

    public function getRuleIds(): array
    {
        return ['regex.lint.flag.useless.x'];
    }

    public function getNodeTypes(): array
    {
        return [CommentNode::class];
    }

    public function begin(LintContext $context): void
    {
        $this->hasComment = false;
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if ($node instanceof CommentNode && $node->extended) {
            $this->hasComment = true;
        }

        return [];
    }

    public function finish(LintContext $context): array
    {
        $source = $context->pattern->source;
        if (!$context->pattern->hasFlag('x') || $this->hasComment || '' === $source || 1 === LibraryPcre::match(self::WHITESPACE, $source)) {
            return [];
        }

        // A comment before a quantifier or a condition leaves no node; with
        // no whitespace to end it, only a NUL under (*NUL) can.
        if (str_contains($source, "\0") && str_contains($source, '#')) {
            return [];
        }

        return [new RuleViolation(
            'regex.lint.flag.useless.x',
            "Flag 'x' is useless: the pattern contains no whitespace and no # comment.",
        )];
    }
}
