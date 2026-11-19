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

namespace PHPRegex\Transpiler\Target\JavaScript;

use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Text\Ascii;

/**
 * What a pattern written for PCRE, read as is by a browser under the v
 * flag, does wrong: the HTML pattern attribute compiles its value with
 * "v" alone, and a value the browser refuses to compile validates nothing.
 * Each finding names the construct, where it is written in the pattern
 * (a byte offset into its body) and what the browser does with it.
 *
 * A class under v escapes more than one under u: "(", ")", "[", "{", "}",
 * "/", "-" and "|" must be escaped anywhere in it, and a punctuator from
 * the reserved list must not be written twice in a row ("&&", "--"...).
 */
final class VModeCompatibility
{
    /**
     * What a class escapes under the v flag: the syntax characters, "/",
     * "-", and the reserved punctuators, every one a legal escape there.
     */
    public const CLASS_SET_META = [
        '\\' => true, ']' => true, '-' => true, '^' => true, '[' => true, '(' => true, ')' => true,
        '{' => true, '}' => true, '/' => true, '|' => true, '$' => true, '.' => true, '*' => true,
        '+' => true, '?' => true, '&' => true, '!' => true, '#' => true, '%' => true, ',' => true,
        ':' => true, ';' => true, '<' => true, '=' => true, '>' => true, '@' => true, '`' => true, '~' => true,
    ];

    /**
     * The characters a class under v refuses unescaped, alone: ECMAScript's
     * ClassSetSyntaxCharacter, but for "]" and "\", which close the class
     * or start an escape, and "^", which only negates it first.
     */
    private const CLASS_SET_SYNTAX = ['(' => true, ')' => true, '[' => true, '{' => true, '}' => true, '/' => true, '-' => true, '|' => true];

    /**
     * The punctuators a class under v refuses twice in a row:
     * ClassSetReservedDoublePunctuator.
     */
    private const RESERVED_DOUBLE = [
        '&' => true, '!' => true, '#' => true, '$' => true, '%' => true, '*' => true, '+' => true, ',' => true,
        '.' => true, ':' => true, ';' => true, '<' => true, '=' => true, '>' => true, '?' => true, '@' => true,
        '^' => true, '`' => true, '~' => true,
    ];

    /**
     * The character types JavaScript reads otherwise, or not at all.
     */
    private const CHAR_TYPES = [
        'h' => 'JavaScript has no \h: under v it is an invalid escape.',
        'H' => 'JavaScript has no \H: under v it is an invalid escape.',
        'v' => 'In JavaScript \v is the vertical tab alone, not every vertical space.',
        'V' => 'JavaScript has no \V: under v it is an invalid escape.',
        'R' => 'JavaScript has no \R: under v it is an invalid escape.',
        'N' => 'JavaScript has no \N: under v it is an invalid escape.',
        'X' => 'JavaScript has no \X: under v it is an invalid escape.',
        'C' => 'JavaScript has no \C: under v it is an invalid escape.',
    ];

    private const HEX = '0123456789abcdefABCDEF';

    private const ASSERTIONS = [
        'A' => 'JavaScript has no \A: under v it is an invalid escape; write ^.',
        'z' => 'JavaScript has no \z: under v it is an invalid escape; write $.',
        'Z' => 'JavaScript has no \Z: under v it is an invalid escape; write $.',
        'G' => 'JavaScript has no \G: under v it is an invalid escape.',
    ];

    /**
     * @var list<array{offset: int, construct: string, message: string, refused: bool}>
     */
    private array $findings = [];

    private string $source = '';

    /**
     * What the browser does wrong with the pattern, in the order written.
     *
     * @return list<array{offset: int, construct: string, message: string, refused: bool}>
     */
    public function check(RegexNode $regex): array
    {
        $this->findings = [];
        $this->source = $regex->source ?? '';
        $this->visit($regex->pattern, false);
        $this->scan();
        usort($this->findings, static fn (array $left, array $right): int => $left['offset'] <=> $right['offset']);

        return $this->findings;
    }

    private function visit(NodeInterface $node, bool $inClass): void
    {
        $this->judge($node, $inClass);

        if ($node instanceof CharClassNode) {
            $this->judgeClassBody($node);
        }

        foreach ($node->getChildren() as $child) {
            $this->visit($child, $inClass || $node instanceof CharClassNode);
        }
    }

    private function judge(NodeInterface $node, bool $inClass): void
    {
        $text = $this->text($node);
        match (true) {
            $node instanceof QuantifierNode && QuantifierType::Possessive === $node->type => $this->add($node, 'possessive', 'JavaScript has no possessive quantifier: "'.$text.'" is a syntax error under v.'),
            $node instanceof GroupNode && GroupType::Atomic === $node->type => $this->add($node, 'atomic', 'JavaScript has no atomic group: "(?>" is a syntax error under v.'),
            $node instanceof GroupNode && GroupType::BranchReset === $node->type => $this->add($node, 'branch_reset', 'JavaScript has no branch reset group "(?|".'),
            $node instanceof GroupNode && GroupType::InlineFlags === $node->type => $this->add($node, 'inline_flags', str_ends_with($text, ')') && !str_contains($text, ':')
                ? 'JavaScript takes no flag setting "'.$text.'" that runs to the end of the group: a syntax error under v.'
                : 'JavaScript reads the scoped modifiers "'.substr($text, 0, (int) strpos($text.':', ':') + 1).'" from ES2025 on only; an older browser refuses the pattern.'),
            $node instanceof GroupNode && (str_starts_with($text, '(?P<') || str_starts_with($text, "(?'")) => $this->add($node, 'named_group', 'JavaScript writes a named group "(?<name>" only: "'.substr($text, 0, 4).'" is a syntax error under v.'),
            $node instanceof CharTypeNode && isset(self::CHAR_TYPES[$node->value]) => $this->add($node, 'char_type', self::CHAR_TYPES[$node->value], refused: 'v' !== $node->value),
            $node instanceof AssertionNode && isset(self::ASSERTIONS[$node->value]) => $this->add($node, 'assertion', self::ASSERTIONS[$node->value]),
            $node instanceof PosixClassNode => $this->add($node, 'posix_class', 'JavaScript has no POSIX class: under v "'.$text.'" is a nested class of the characters "'.substr($text, 1, -1).'".', refused: false),
            $node instanceof LiteralNode && !$inClass && $this->opensQuote($node) => $this->add($node, 'quote', 'JavaScript has no \Q...\E: under v it is an invalid escape; escape each character instead.', -2),
            $node instanceof ConditionalNode => $this->add($node, 'conditional', 'JavaScript has no conditional group.'),
            $node instanceof SubroutineNode => $this->add($node, 'subroutine', 'JavaScript has no recursion or subroutine call.'),
            $node instanceof PcreVerbNode => $this->add($node, 'verb', 'JavaScript has no backtracking control verb.'),
            $node instanceof KeepNode => $this->add($node, 'keep', 'JavaScript has no \K.'),
            default => null,
        };
    }

    /**
     * The characters of a class, written unescaped, that the v flag
     * refuses: alone, or twice in a row.
     */
    private function judgeClassBody(CharClassNode $class): void
    {
        $items = $class->expression instanceof RangeNode || [] === $class->expression->getChildren() ? [$class->expression] : $class->expression->getChildren();
        $previous = null;
        foreach ($items as $item) {
            foreach ($item instanceof RangeNode ? [$item->start, $item->end] : [$item] as $part) {
                $character = $this->unescaped($part);
                if (null !== $character && isset(self::CLASS_SET_SYNTAX[$character])) {
                    $this->add($part, 'class_syntax', 'Under v a class takes "'.$character.'" escaped only: write "\\'.$character.'".');
                }

                if (null !== $character && null !== $previous && isset(self::RESERVED_DOUBLE[$character]) && $previous[0] === $character && $previous[1] === $part->getStartPosition()) {
                    $this->add($part, 'class_double_punctuator', '&' === $character
                        ? 'Under v "&&" in a class is the intersection of its sides, not two "&": escape one of them, "\\&".'
                        : 'Under v a class refuses "'.$character.$character.'": escape one of them, "\\'.$character.'".', -1, '&' !== $character || !$this->hasOperands($part->getStartPosition() - 1));
                }

                $previous = null === $character ? null : [$character, $part->getEndPosition()];
            }

            if ($item instanceof RangeNode) {
                $previous = null;
            }
        }
    }

    /**
     * What the source writes that the browser refuses before reading any
     * construct: an escape JavaScript does not know under v, a brace or a
     * "]" that stands for itself outside a class, a class whose first
     * character is "]". A \Q...\E quote is left to its own finding.
     */
    private function scan(): void
    {
        $source = $this->source;
        $length = \strlen($source);
        $depth = 0;
        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];
            if ('\\' === $char && $i + 1 < $length) {
                $next = $source[$i + 1];
                if ('Q' === $next) {
                    $end = strpos($source, '\\E', $i + 2);
                    $i = false === $end ? $length : $end + 1;

                    continue;
                }

                $this->judgeEscape($i, $next, $depth > 0);
                $i++;
                // A braced argument, \p{L} or \u{41}, is no quantifier.
                if (str_contains('pPuxqgoN', $next) && '{' === ($source[$i + 1] ?? '') && false !== $close = strpos($source, '}', $i + 1)) {
                    $i = $close;
                }

                continue;
            }

            if ('[' === $char) {
                $posix = $depth > 0 ? self::posixClassLength($source, $i) : 0;
                if ($posix > 0) {
                    $i += $posix - 1;

                    continue;
                }

                $depth++;
                $first = $i + 1 + ('^' === ($source[$i + 1] ?? '') ? 1 : 0);
                if (']' === ($source[$first] ?? '')) {
                    $this->findings[] = ['offset' => $first, 'construct' => 'class_syntax', 'message' => 'Under v "[]" is the empty class, and the "]" after it a syntax error: write "\\]".', 'refused' => true];
                    $i = $first;
                }

                continue;
            }

            if ($depth > 0) {
                if (']' === $char) {
                    $depth--;
                }

                continue;
            }

            if ('{' === $char) {
                $quantifier = self::quantifierLength($source, $i);
                if ($quantifier > 0) {
                    $i += $quantifier - 1;

                    continue;
                }

                $this->findings[] = ['offset' => $i, 'construct' => 'brace', 'message' => 'Under v a "{" that opens no quantifier is a syntax error: write "\\{".', 'refused' => true];
            } elseif ('}' === $char || ']' === $char) {
                $this->findings[] = ['offset' => $i, 'construct' => 'brace', 'message' => 'Under v a lone "'.$char.'" is a syntax error: write "\\'.$char.'".', 'refused' => true];
            }
        }
    }

    /**
     * An escape JavaScript refuses under v: an escaped character that is no
     * syntax character (nor, in a class, a punctuator v lets a class
     * escape), or a letter or digit that names no escape of JavaScript.
     * The letters the constructs judge (\h, \A, \K...) are theirs.
     */
    private function judgeEscape(int $offset, string $next, bool $inClass): void
    {
        if (str_contains('hHvVRNXCAzZGKE', $next)) {
            return;
        }

        $rest = substr($this->source, $offset + 2);
        $known = match (true) {
            str_contains('^$\\.*+?()[]{}|/', $next) => true,
            $inClass && isset(self::CLASS_SET_META[$next]) => true,
            str_contains('dDwWsSfnrt', $next) => true,
            'b' === $next || 'B' === $next => 'B' !== $next || !$inClass,
            '0' === $next => !Ascii::isDigit(substr($rest, 0, 1)),
            'c' === $next => Ascii::isAlpha(substr($rest, 0, 1)),
            'x' === $next => 2 === strspn($rest, self::HEX, 0, 2),
            'u' === $next => 4 === strspn($rest, self::HEX, 0, 4) || ('{' === ($rest[0] ?? '') && strspn($rest, self::HEX, 1) > 0 && '}' === ($rest[1 + strspn($rest, self::HEX, 1)] ?? '')),
            'p' === $next || 'P' === $next => str_starts_with($rest, '{'),
            'k' === $next => !$inClass && str_starts_with($rest, '<'),
            'q' === $next => $inClass && str_starts_with($rest, '{'),
            Ascii::isDigit($next) => !$inClass,
            default => false,
        };

        if (!$known) {
            $this->findings[] = ['offset' => $offset, 'construct' => 'escape', 'message' => \sprintf('JavaScript knows no escape "\\%s%s" under v: a syntax error.', $next, 'x' === $next && str_starts_with($rest, '{') ? '{' : ''), 'refused' => true];
        }
    }

    /**
     * The length of the POSIX class "[:name:]" or "[:^name:]" written at the
     * offset, or 0.
     */
    private static function posixClassLength(string $source, int $offset): int
    {
        if (':' !== ($source[$offset + 1] ?? '')) {
            return 0;
        }

        $at = $offset + 2 + ('^' === ($source[$offset + 2] ?? '') ? 1 : 0);
        $name = strspn($source, 'abcdefghijklmnopqrstuvwxyz', $at);

        return $name > 0 && ':]' === substr($source, $at + $name, 2) ? $at + $name + 2 - $offset : 0;
    }

    /**
     * The length of the quantifier "{n}", "{n,}" or "{n,m}" written at the
     * offset, or 0.
     */
    private static function quantifierLength(string $source, int $offset): int
    {
        $at = $offset + 1;
        $low = strspn($source, '0123456789', $at);
        if (0 === $low) {
            return 0;
        }

        $at += $low;
        if (',' === ($source[$at] ?? '')) {
            $at += 1 + strspn($source, '0123456789', $at + 1);
        }

        return '}' === ($source[$at] ?? '') ? $at + 1 - $offset : 0;
    }

    /**
     * Whether the "&&" written at the offset stands between two operands,
     * an intersection the browser reads, not at an end of its class.
     */
    private function hasOperands(int $offset): bool
    {
        $before = substr($this->source, 0, $offset);

        return !str_ends_with($before, '[') && !str_ends_with($before, '[^') && ']' !== ($this->source[$offset + 2] ?? ']');
    }

    /**
     * The character a literal of a class writes as is, one byte or one
     * UTF-8 character, or null for one written as an escape.
     */
    private function unescaped(NodeInterface $node): ?string
    {
        if (!$node instanceof LiteralNode && !$node instanceof CharLiteralNode) {
            return null;
        }

        $text = $this->text($node);

        return '' === $text || '\\' === $text[0] || 1 !== mb_strlen($text, 'UTF-8') ? null : $text;
    }

    private function opensQuote(LiteralNode $node): bool
    {
        $start = $node->getStartPosition();

        return $start >= 2 && '\Q' === substr($this->source, $start - 2, 2) && ($start < 3 || '\\' !== $this->source[$start - 3]);
    }

    private function add(NodeInterface $node, string $construct, string $message, int $shift = 0, bool $refused = true): void
    {
        $this->findings[] = ['offset' => max(0, $node->getStartPosition() + $shift), 'construct' => $construct, 'message' => $message, 'refused' => $refused];
    }

    private function text(NodeInterface $node): string
    {
        return substr($this->source, $node->getStartPosition(), $node->getEndPosition() - $node->getStartPosition());
    }
}
