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
use PHPRegex\Linter\Rule\Support\EscapeJoin;
use PHPRegex\Linter\Rule\Support\LanguageQuestions;
use PHPRegex\Parser\Internal\Ascii;
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\Internal\PatternParser;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;

/**
 * A style rule: "[a]" is "a". A class holding one metacharacter ("[.]",
 * "[*]", and under x "[#]", "[ ]" or any white space the flag skips) is
 * the clearer escape and stays, as do a negated class, the delimiter, an
 * escape a class reads otherwise ("[\b]" is a backspace, "[\1]" an octal
 * escape where "\1" is a reference), a raw multibyte character without
 * UTF mode, whose bytes the class reads one at a time, and a character
 * that would join the text around the class into another token ("\1[0]"
 * is not "\10", "a{[2]}" not "a{2}").
 *
 * @internal
 */
final class SingleCharClassRule extends AbstractLintRule
{
    private const ID = 'regex.lint.charclass.single';

    /**
     * The characters that mean something else outside a class.
     */
    private const METACHARACTERS = '.^$|?*+()[]{}\\';

    /**
     * The escapes that read the same in a class and outside it.
     */
    private const PLAIN_ESCAPES = ['\n', '\r', '\t', '\f', '\e', '\a'];

    /**
     * The characters the x flag reads otherwise outside a class: "#" opens
     * a comment, and the white space it skips is space, tab, line feed,
     * vertical tab, form feed, carriage return and next line (the byte 0x85
     * without UTF mode), and under UTF mode the left-to-right and
     * right-to-left marks and the line and paragraph separators as well.
     */
    private const EXTENDED_MODE_SKIPS = [' ', "\t", "\n", "\x0B", "\f", "\r", "\x85", "\u{85}", "\u{200E}", "\u{200F}", "\u{2028}", "\u{2029}", '#'];

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
        if (!$node instanceof CharClassNode || $node->isNegated) {
            return [];
        }

        $member = $node->expression;
        $written = LanguageQuestions::text($member, $context);
        if ('' === $written
            || !$this->readsTheSameOutside($member, $written, $context)
            || EscapeJoin::separates($node, $written, $context)
        ) {
            return [];
        }

        return [new RuleViolation(
            self::ID,
            \sprintf('Character class "%s" holds one character, which reads the same without the class.', LanguageQuestions::text($node, $context)),
            $node->getStartPosition(),
            \sprintf('Write "%s" instead.', $written),
            LintSeverity::Style,
        )];
    }

    private function readsTheSameOutside(NodeInterface $member, string $written, LintContext $context): bool
    {
        if ($member instanceof CharLiteralNode) {
            // "\1" to "\9" and the octal escapes written with digits read
            // as references outside a class, once enough groups exist.
            return !Ascii::isDigit($written[1] ?? '');
        }

        if (!$member instanceof LiteralNode) {
            return false;
        }

        $delimiters = [$context->pattern->delimiter, PatternParser::closingDelimiter($context->pattern->delimiter)];
        if (\in_array($member->value, $delimiters, true)) {
            return false;
        }

        if ($written === $member->value) {
            $oneCharacter = $context->pattern->unicodeMode ? 1 === mb_strlen($written, 'UTF-8') : 1 === \strlen($written);
            $extended = str_contains($context->activeFlags(), 'x') && \in_array($written, self::EXTENDED_MODE_SKIPS, true);

            return $oneCharacter && !$extended && !str_contains(self::METACHARACTERS, $written);
        }

        return \in_array($written, self::PLAIN_ESCAPES, true) || 1 === LibraryPcre::match('/^\\\\[^a-zA-Z0-9]$/', $written);
    }
}
