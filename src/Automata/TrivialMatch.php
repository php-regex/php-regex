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

namespace PHPRegex\Automata;

/**
 * A string function that returns true for exactly the subjects preg_match()
 * returns 1 for, as the automata proved.
 */
final readonly class TrivialMatch
{
    private const PRINTABLE = ' !"#$%&\'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~';

    /**
     * @param list<string> $literals the literal, or the literals of OneOf; none for IsEmpty
     */
    public function __construct(public TrivialMatchKind $kind, public array $literals) {}

    /**
     * What the function says for a subject.
     */
    public function accepts(string $subject): bool
    {
        return match ($this->kind) {
            TrivialMatchKind::Contains => str_contains($subject, $this->literals[0]),
            TrivialMatchKind::StartsWith => str_starts_with($subject, $this->literals[0]),
            TrivialMatchKind::EndsWith => str_ends_with($subject, $this->literals[0]),
            TrivialMatchKind::Equals => $this->literals[0] === $subject,
            TrivialMatchKind::OneOf => \in_array($subject, $this->literals, true),
            TrivialMatchKind::IsEmpty => '' === $subject,
        };
    }

    /**
     * The call as PHP code, on the subject given as code: "str_starts_with($s, 'foo')".
     */
    public function phpExpression(string $subject): string
    {
        $literal = self::phpString($this->literals[0] ?? '');

        return match ($this->kind) {
            TrivialMatchKind::Contains => \sprintf('str_contains(%s, %s)', $subject, $literal),
            TrivialMatchKind::StartsWith => \sprintf('str_starts_with(%s, %s)', $subject, $literal),
            TrivialMatchKind::EndsWith => \sprintf('str_ends_with(%s, %s)', $subject, $literal),
            TrivialMatchKind::Equals => \sprintf('%s === %s', $literal, $subject),
            TrivialMatchKind::OneOf => \sprintf('in_array(%s, [%s], true)', $subject, implode(', ', array_map(self::phpString(...), $this->literals))),
            TrivialMatchKind::IsEmpty => \sprintf("'' === %s", $subject),
        };
    }

    /**
     * A PHP string literal: single-quoted when the text is printable ASCII,
     * double-quoted with escapes otherwise.
     */
    private static function phpString(string $text): string
    {
        if (strspn($text, self::PRINTABLE) === \strlen($text)) {
            return "'".addcslashes($text, "'\\")."'";
        }

        $escaped = '';
        $utf8 = mb_check_encoding($text, 'UTF-8');
        foreach (str_split($text) as $byte) {
            $code = \ord($byte);
            $escaped .= match (true) {
                "\n" === $byte => '\n',
                "\r" === $byte => '\r',
                "\t" === $byte => '\t',
                '\\' === $byte, '"' === $byte, '$' === $byte => '\\'.$byte,
                $code < 0x20, 0x7F === $code, $code >= 0x80 && !$utf8 => \sprintf('\x%02X', $code),
                default => $byte,
            };
        }

        return '"'.$escaped.'"';
    }
}
