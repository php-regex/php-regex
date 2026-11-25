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
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Transpiler\VModeFinding;

/**
 * One reading of a pattern for VModeCompatibility::check(): the findings of
 * its tree, then of its source, gathered in a scan that serves that one
 * pattern alone.
 *
 * @internal
 */
final class VModeScan
{
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
     * @var array<int, VModeFinding>
     */
    private array $findings = [];

    private function __construct(private readonly string $source) {}

    /**
     * What the browser does wrong with the pattern, in the order written.
     *
     * @return list<VModeFinding>
     */
    public static function of(RegexNode $ast): array
    {
        $scan = new self($ast->source ?? '');
        $scan->visit($ast->pattern);
        $scan->scan();
        usort($scan->findings, static fn (VModeFinding $left, VModeFinding $right): int => $left->offset <=> $right->offset);

        return $scan->findings;
    }

    private function visit(NodeInterface $node): void
    {
        $this->judge($node);

        if ($node instanceof CharClassNode) {
            $this->judgeClassBody($node);
        }

        foreach ($node->getChildren() as $child) {
            $this->visit($child);
        }
    }

    private function judge(NodeInterface $node): void
    {
        $text = $this->text($node);
        match (true) {
            $node instanceof QuantifierNode && QuantifierType::Possessive === $node->type => $this->add($node, 'possessive', 'JavaScript has no possessive quantifier: "'.$text.'" is a syntax error under v.'),
            $node instanceof GroupNode && GroupType::Atomic === $node->type => $this->add($node, 'atomic', 'JavaScript has no atomic group: "(?>" is a syntax error under v.'),
            $node instanceof GroupNode && GroupType::BranchReset === $node->type => $this->add($node, 'branch_reset', 'JavaScript has no branch reset group "(?|".'),
            $node instanceof GroupNode && GroupType::InlineFlags === $node->type => $this->add($node, 'inline_flags', str_contains($text, ':')
                ? 'JavaScript reads the scoped modifiers "'.substr($text, 0, strcspn($text, ':') + 1).'" from ES2025 on only; an older browser refuses the pattern.'
                : 'JavaScript takes no flag setting "'.$text.'" that runs to the end of the group: a syntax error under v.'),
            $node instanceof GroupNode && str_starts_with($text, '(?P<') => $this->add($node, 'named_group', 'JavaScript writes a named group "(?<name>" only: "(?P<" is a syntax error under v.'),
            $node instanceof GroupNode && str_starts_with($text, "(?'") => $this->add($node, 'named_group', 'JavaScript writes a named group "(?<name>" only: "(?\'" is a syntax error under v.'),
            $node instanceof CharTypeNode && isset(self::CHAR_TYPES[$node->value]) => $this->add($node, 'char_type', self::CHAR_TYPES[$node->value], refused: 'v' !== $node->value),
            $node instanceof AssertionNode && isset(self::ASSERTIONS[$node->value]) => $this->add($node, 'assertion', self::ASSERTIONS[$node->value]),
            $node instanceof PosixClassNode => $this->add($node, 'posix_class', 'JavaScript has no POSIX class: under v "'.$text.'" is a nested class of the characters "'.substr($text, 1, -1).'".', refused: false),
            $node instanceof ConditionalNode => $this->add($node, 'conditional', 'JavaScript has no conditional group.'),
            $node instanceof SubroutineNode => $this->add($node, 'subroutine', 'JavaScript has no recursion or subroutine call.'),
            $node instanceof PcreVerbNode => $this->add($node, 'verb', 'JavaScript has no backtracking control verb.'),
            $node instanceof KeepNode => $this->add($node, 'keep', 'JavaScript has no \K.'),
            $node instanceof CommentNode => $this->add($node, 'comment', 'JavaScript has no comment group "(?#": a syntax error under v.'),
            default => null,
        };
    }

    /**
     * The characters of a class, written unescaped, that the v flag
     * refuses: alone, or twice in a row. "&&" is read as an intersection
     * only between single operands, "[a&&b&&c]": never a range, nor two
     * characters side by side.
     */
    private function judgeClassBody(CharClassNode $class): void
    {
        $items = $class->expression instanceof RangeNode || [] === $class->expression->getChildren() ? [$class->expression] : $class->expression->getChildren();
        $intersection = $this->isIntersection($items);
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
                        : 'Under v a class refuses "'.$character.$character.'": escape one of them, "\\'.$character.'".', -1, '&' !== $character || !$intersection);
                }

                $previous = null === $character ? null : [$character, $part->getEndPosition()];
            }

            if ($item instanceof RangeNode) {
                $previous = null;
            }
        }
    }

    /**
     * Whether the items of a class are single operands joined by "&&".
     *
     * @param list<NodeInterface> $items
     */
    private function isIntersection(array $items): bool
    {
        foreach ($items as $index => $item) {
            if ($item instanceof RangeNode || (0 === $index % 3) === ('&' === $this->unescaped($item))) {
                return false;
            }
        }

        return 1 === \count($items) % 3;
    }

    /**
     * What the source writes that the browser refuses before reading any
     * construct: a \Q...\E quote, an escape JavaScript does not know under
     * v, a brace or a "]" that stands for itself outside a class, a class
     * whose first character is "]". Under v a "[" in a class opens a nested
     * one, a POSIX class "[:alpha:]" included.
     */
    private function scan(): void
    {
        $source = $this->source;
        $length = \strlen($source);
        $depth = 0;
        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];
            // A pattern never ends on a backslash: it escapes the next byte.
            if ('\\' === $char) {
                $next = $source[$i + 1];
                if ('Q' === $next) {
                    $this->findings[] = new VModeFinding($i, 'quote', 'JavaScript has no \Q...\E: under v it is an invalid escape; escape each character instead.', true);
                    $end = strpos($source, '\\E', $i);
                    $i = false === $end ? $length : $end + 1;

                    continue;
                }

                $this->judgeEscape($i, $next, $depth > 0);
                $i++;
                // A braced argument, \p{L} or \u{41}, is no quantifier; in a
                // class, \q{...} is a string the class takes as written.
                if (str_contains('pPuxqgoN', $next) && '{' === ($source[$i + 1] ?? '') && false !== $close = strpos($source, '}', $i)) {
                    if ('q' === $next) {
                        $this->findings = array_filter($this->findings, static fn (VModeFinding $finding): bool => $finding->offset < $i || $finding->offset > $close);
                    }

                    $i = $close;
                }

                continue;
            }

            if ('[' === $char) {
                $depth++;
                $first = $i + 1 + ('^' === ($source[$i + 1] ?? '') ? 1 : 0);
                if (']' === ($source[$first] ?? '')) {
                    $this->findings[] = new VModeFinding($first, 'class_syntax', 'Under v "[]" is the empty class, and the "]" after it a syntax error: write "\\]".', true);
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
                $quantifier = self::quantifierEnd($source, $i);
                if (null !== $quantifier) {
                    $i = $quantifier;

                    continue;
                }

                $this->findings[] = new VModeFinding($i, 'brace', 'Under v a "{" that opens no quantifier is a syntax error: write "\\{".', true);
            } elseif ('}' === $char || ']' === $char) {
                $this->findings[] = new VModeFinding($i, 'brace', 'Under v a lone "'.$char.'" is a syntax error: write "\\'.$char.'".', true);
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
        if (str_contains('hHvVRXCAzZGK', $next)) {
            return;
        }

        // What follows the escape is read in place: a copy of the rest of the
        // pattern for each escape would cost the square of its length.
        $source = $this->source;
        $at = $offset + 2;
        $following = $source[$at] ?? '';
        if (('p' === $next || 'P' === $next) && '{' === $following) {
            $this->judgeProperty($offset, $next, substr($source, $at + 1, strcspn($source, '}', $at) - 1));

            return;
        }

        $known = match (true) {
            str_contains('^$\\.*+?()[]{}|/', $next) => true,
            // \N alone is a character type, judged as one; \N{U+41} names a code point.
            'N' === $next => '{U+' !== substr($source, $at, 3),
            $inClass && isset(JavaScriptPrinter::CLASS_SET_META[$next]) => true,
            str_contains('dDwWsSfnrt', $next) => true,
            'b' === $next || 'B' === $next => 'B' !== $next || !$inClass,
            '0' === $next => !Ascii::isDigit($following),
            'c' === $next => Ascii::isAlpha($following),
            'x' === $next => 2 === strspn($source, self::HEX, $at, 2),
            'u' === $next => 4 === strspn($source, self::HEX, $at, 4) || self::bracedHex($source, $at),
            'k' === $next => !$inClass && '<' === $following,
            'q' === $next => $inClass && '{' === $following,
            Ascii::isDigit($next) => !$inClass,
            default => false,
        };

        if (!$known) {
            $this->findings[] = new VModeFinding($offset, 'escape', \sprintf('JavaScript knows no escape "\\%s%s" under v: a syntax error.', $next, 'x' === $next && '{' === $following ? '{' : ''), true);
        }
    }

    /**
     * A property JavaScript does not name as written: it takes "\p{Lu}",
     * "\p{Letter}" or "\p{Script=Greek}", never PCRE's "\p{Greek}",
     * "\p{lu}" or "\p{Xan}".
     */
    private function judgeProperty(int $offset, string $next, string $name): void
    {
        if (!JavaScriptProperties::readsAsWritten($name, 'P' === $next)) {
            $this->findings[] = new VModeFinding($offset, 'property', \sprintf('JavaScript knows no property "\\%s{%s}" under v: a syntax error.', $next, $name), true);
        }
    }

    /**
     * Whether the source writes "{", one hex digit or more, then "}" at the
     * offset.
     */
    private static function bracedHex(string $source, int $at): bool
    {
        $digits = strspn($source, self::HEX, $at + 1);

        return '{' === ($source[$at] ?? '') && $digits > 0 && '}' === ($source[$at + 1 + $digits] ?? '');
    }

    /**
     * The offset of the "}" that closes the quantifier "{n}", "{n,}" or
     * "{n,m}" written at the offset, or null.
     */
    private static function quantifierEnd(string $source, int $offset): ?int
    {
        $at = $offset + 1;
        $low = strspn($source, '0123456789', $at);
        if (0 === $low) {
            return null;
        }

        $at += $low;
        if (',' === ($source[$at] ?? '')) {
            $at += 1 + strspn($source, '0123456789', $at + 1);
        }

        return '}' === ($source[$at] ?? '') ? $at : null;
    }

    /**
     * The ASCII character a part of a class writes as is, or null: an
     * escape, a character type, a nested class or a character past ASCII,
     * none of which the v flag refuses alone or twice.
     */
    private function unescaped(NodeInterface $node): ?string
    {
        $text = $this->text($node);

        return 1 === \strlen($text) ? $text : null;
    }

    private function add(NodeInterface $node, string $construct, string $message, int $shift = 0, bool $refused = true): void
    {
        $this->findings[] = new VModeFinding($node->getStartPosition() + $shift, $construct, $message, $refused);
    }

    private function text(NodeInterface $node): string
    {
        return substr($this->source, $node->getStartPosition(), $node->getEndPosition() - $node->getStartPosition());
    }
}
