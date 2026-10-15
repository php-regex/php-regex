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

use PHPRegex\Parser\Internal\Bytes;
use PHPRegex\Parser\Internal\DisplayEscaper;
use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Token\TokenType;

/**
 * A character the pattern matches that does not show where the pattern is
 * read, written raw: a C0 control (the tab, the line feed and the carriage
 * return included), DEL, and the characters the console writes as escapes
 * because they hide or move text (the C1 controls, the format characters,
 * such as a zero-width space, a byte order mark or a bidirectional control,
 * and the line and paragraph separators). Each is reported once, where it
 * stands, with the escape that spells it: "\t", "\n", "\r", "\xHH" for the
 * other controls, "\x{HEX}" for the others under UTF, their bytes "\xHH"
 * otherwise.
 *
 * Only characters the pattern reads as text are looked at: white space x
 * skips, a comment and a verb's name are not. The rule reads the pattern
 * string, which a PHP escape such as "\t" in a double-quoted literal has
 * already turned into the raw character; the lint command and the editor
 * integrations, which see the PHP source, keep the report only where the
 * source holds the character raw.
 *
 * @internal
 */
final class InvisibleCharacterRule extends AbstractLintRule
{
    public const ID = 'regex.lint.literal.invisible';

    private const LETTER_ESCAPES = ["\t" => '\t', "\n" => '\n', "\r" => '\r'];

    /**
     * The hidden characters above ASCII, in UTF-8, as keys.
     *
     * @var array<string, true>|null
     */
    private static ?array $hidden = null;

    /**
     * The \Q...\E quotes of the pattern, read once a character is found.
     *
     * @var list<array{int, int}>|null
     */
    private ?array $quotes = null;

    public function getRuleIds(): array
    {
        return [self::ID];
    }

    public function getNodeTypes(): array
    {
        return [LiteralNode::class, CharClassNode::class];
    }

    public function begin(LintContext $context): void
    {
        $this->quotes = null;
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        $issues = [];
        foreach (self::literals($node) as $literal) {
            foreach ($this->checkLiteral($literal, $context) as $issue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * The character an issue of this rule names, read from the pattern body
     * at the issue's offset: past the backslash that escapes it, where the
     * issue starts there.
     */
    public static function reportedCharacter(string $source, int $offset): string
    {
        return self::characterAt($source, '\\' === ($source[$offset] ?? '') ? $offset + 1 : $offset);
    }

    /**
     * Whether the character, in UTF-8, is one the rule reports: a C0
     * control, DEL, or a character that hides or moves text.
     */
    private static function isInvisible(string $character): bool
    {
        if (1 === \strlen($character)) {
            return \ord($character) < 0x20 || "\x7F" === $character;
        }

        self::$hidden ??= array_fill_keys(DisplayEscaper::hiddenCharacters(), true);

        return isset(self::$hidden[$character]);
    }

    /**
     * The character that starts at the offset, in UTF-8: the whole sequence
     * its lead byte opens when that is valid UTF-8, the byte alone
     * otherwise.
     */
    private static function characterAt(string $text, int $offset): string
    {
        $lead = \ord($text[$offset] ?? "\0");
        $width = match (true) {
            $lead >= 0xF0 => 4,
            $lead >= 0xE0 => 3,
            $lead >= 0xC0 => 2,
            default => 1,
        };
        $character = substr($text, $offset, $width);

        return 1 === $width || mb_check_encoding($character, 'UTF-8') ? $character : substr($text, $offset, 1);
    }

    /**
     * @return list<RuleViolation>
     */
    private function checkLiteral(LiteralNode $literal, LintContext $context): array
    {
        $source = $context->pattern->source;
        $start = $literal->getStartPosition();
        $written = substr($source, $start, $literal->getEndPosition() - $start);

        $issues = [];
        for ($i = 0, $length = \strlen($written); $i < $length;) {
            $character = self::characterAt($written, $i);
            $offset = $start + $i;
            $i += \strlen($character);
            if (!self::isInvisible($character) || !str_contains($literal->value, $character)) {
                continue;
            }

            $quoted = $this->isQuoted($offset, $context);
            // "\" before a character outside a quote escapes it: the escape
            // replaces both.
            if (!$quoted && $offset - 1 === $start && '\\' === $source[$start]) {
                $offset = $start;
            }

            $escape = self::escape($character, $context->pattern->unicodeMode);
            $issues[] = new RuleViolation(
                self::ID,
                \sprintf('The pattern holds a raw %s U+%04X, which does not show where the pattern is read.', self::kind($character), mb_ord($character, 'UTF-8')),
                $offset,
                $quoted ? \sprintf('Write "\E%s\Q" in its place.', $escape) : \sprintf('Write "%s".', $escape),
                end: $start + $i,
            );
        }

        return $issues;
    }

    /**
     * The literals the node holds: itself, or every literal of a class,
     * the ends of its ranges included.
     *
     * @return iterable<LiteralNode>
     */
    private static function literals(NodeInterface $node): iterable
    {
        if ($node instanceof LiteralNode) {
            yield $node;

            return;
        }

        foreach ($node->getChildren() as $child) {
            yield from self::literals($child);
        }
    }

    private function isQuoted(int $offset, LintContext $context): bool
    {
        if (null === $this->quotes) {
            $this->quotes = [];
            $opener = null;
            $source = $context->pattern->source;
            foreach ((new Lexer())->tokenize($source, $context->pattern->flags)->getTokens() as $token) {
                if (TokenType::QuoteModeStart === $token->type) {
                    $opener = $token->position + 2;
                } elseif (null !== $opener && TokenType::QuoteModeEnd === $token->type) {
                    $this->quotes[] = [$opener, $token->position];
                    $opener = null;
                }
            }

            if (null !== $opener) {
                $this->quotes[] = [$opener, \strlen($source)];
            }
        }

        foreach ($this->quotes as [$from, $to]) {
            if ($offset >= $from && $offset < $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the character is: a control below U+00A0 (C0, DEL, C1), a line
     * or a paragraph separator, or a format character.
     */
    private static function kind(string $character): string
    {
        $lead = \ord($character[0]);
        if ($lead < 0x80 || (0xC2 === $lead && \ord(substr($character, 1, 1)) < 0xA0)) {
            return 'control character';
        }

        return match ($character) {
            "\u{2028}" => 'line separator',
            "\u{2029}" => 'paragraph separator',
            default => 'format character',
        };
    }

    private static function escape(string $character, bool $utf): string
    {
        if (isset(self::LETTER_ESCAPES[$character])) {
            return self::LETTER_ESCAPES[$character];
        }

        if (1 === \strlen($character)) {
            return \sprintf('\x%02X', \ord($character));
        }

        if ($utf) {
            return \sprintf('\x{%X}', mb_ord($character, 'UTF-8'));
        }

        $bytes = '';
        foreach (Bytes::split($character) as $byte) {
            $bytes .= \sprintf('\x%02X', \ord($byte));
        }

        return $bytes;
    }
}
