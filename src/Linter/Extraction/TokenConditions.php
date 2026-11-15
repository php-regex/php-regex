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

namespace PHPRegex\Linter\Extraction;

use PHPRegex\Parser\Text\Ascii;

/**
 * The conditions of a file the tokenizer read, as ValueCondition reads
 * them, and the operands of its "&&", "||", "and" and "or".
 *
 * A condition is read from its loosest operator down, as PHP binds them:
 * "or", "and", "||", "&&", a comparison, "!", then one operand. An
 * operator the condition does not evaluate, standing where it would bind
 * looser than one it does ("xor", an assignment, a ternary, "??", "&",
 * "|", "^"), leaves the whole condition unknown; any other one leaves its
 * operand unknown.
 *
 * @internal
 */
final class TokenConditions
{
    /**
     * How many tokens a condition, or the operands of a chain on the left
     * of an operator, are read over: past it, a condition is unknown, and
     * a chain keeps the operands read whole before it.
     */
    private const REACH = 512;

    /**
     * What binds looser than "||" and "&&" but tighter than "and": seen
     * outside any bracket, nothing of the condition is known.
     */
    private const LOOSER_THAN_BOOLEANS = [
        '=' => true, '?' => true, ':' => true, ',' => true, ';' => true,
        \T_COALESCE => true, \T_PLUS_EQUAL => true, \T_MINUS_EQUAL => true, \T_MUL_EQUAL => true, \T_DIV_EQUAL => true,
        \T_CONCAT_EQUAL => true, \T_MOD_EQUAL => true, \T_AND_EQUAL => true, \T_OR_EQUAL => true, \T_XOR_EQUAL => true,
        \T_SL_EQUAL => true, \T_SR_EQUAL => true, \T_POW_EQUAL => true, \T_COALESCE_EQUAL => true,
        \T_YIELD => true, \T_YIELD_FROM => true, \T_PRINT => true, \T_THROW => true, \T_DOUBLE_ARROW => true,
        \T_INCLUDE => true, \T_INCLUDE_ONCE => true, \T_REQUIRE => true, \T_REQUIRE_ONCE => true,
        \T_FN => true, \T_FUNCTION => true,
    ];

    /**
     * What binds looser than "or": seen outside any bracket, nothing of
     * the condition is known.
     */
    private const LOOSER_THAN_LOW_BOOLEANS = [
        \T_FN => true, \T_THROW => true, \T_INCLUDE => true, \T_INCLUDE_ONCE => true, \T_REQUIRE => true, \T_REQUIRE_ONCE => true,
    ];

    /**
     * What binds looser than a comparison but tighter than "&&".
     */
    private const BITWISE = [
        '&' => true, '|' => true, '^' => true,
        \T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG => true, \T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG => true,
    ];

    private const EQUALITIES = [\T_IS_IDENTICAL => '===', \T_IS_NOT_IDENTICAL => '!==', \T_IS_EQUAL => '==', \T_IS_NOT_EQUAL => '!='];

    /**
     * The keywords whose parenthesised head ends an operand written right
     * after it: "if ($a) $b && $c" holds $b alone on the left of "&&".
     */
    private const HEADS = [\T_IF, \T_ELSEIF, \T_WHILE, \T_FOR, \T_FOREACH, \T_SWITCH, \T_DECLARE, \T_CATCH];

    private const ORDERINGS = ['<' => '<', '>' => '>', \T_IS_SMALLER_OR_EQUAL => '<=', \T_IS_GREATER_OR_EQUAL => '>='];

    /**
     * What ends an operand of "&&" or "||", on either side: what binds
     * looser, a bracket of the expression around, the end of a statement.
     * "&&" ends one of "&&" too.
     */
    private const ENDS_A_BOOLEAN_OPERAND = [
        \T_BOOLEAN_OR => true, \T_LOGICAL_AND => true, \T_LOGICAL_OR => true, \T_LOGICAL_XOR => true,
        '?' => true, ':' => true, ',' => true, ';' => true, '=' => true,
        \T_COALESCE => true, \T_PLUS_EQUAL => true, \T_MINUS_EQUAL => true, \T_MUL_EQUAL => true, \T_DIV_EQUAL => true,
        \T_CONCAT_EQUAL => true, \T_MOD_EQUAL => true, \T_AND_EQUAL => true, \T_OR_EQUAL => true, \T_XOR_EQUAL => true,
        \T_SL_EQUAL => true, \T_SR_EQUAL => true, \T_POW_EQUAL => true, \T_COALESCE_EQUAL => true,
        \T_DOUBLE_ARROW => true, \T_AS => true, \T_RETURN => true, \T_ECHO => true, \T_PRINT => true, \T_YIELD => true,
        \T_YIELD_FROM => true, \T_THROW => true, \T_CASE => true, \T_ELSE => true, \T_DO => true, \T_INCLUDE => true,
        \T_INCLUDE_ONCE => true, \T_REQUIRE => true, \T_REQUIRE_ONCE => true, \T_OPEN_TAG => true,
        \T_OPEN_TAG_WITH_ECHO => true, \T_CLOSE_TAG => true, \T_INLINE_HTML => true,
    ];

    /**
     * What ends the right operand of "&&" or "||" after it: what binds
     * looser, the end of a statement or of an item. "&&" ends one of "&&"
     * too. A keyword starts an operand, and ends none.
     */
    private const ENDS_A_RIGHT_BOOLEAN_OPERAND = [
        \T_BOOLEAN_OR => true, \T_LOGICAL_AND => true, \T_LOGICAL_OR => true, \T_LOGICAL_XOR => true,
        '?' => true, ':' => true, ',' => true, ';' => true, '=' => true,
        \T_COALESCE => true, \T_PLUS_EQUAL => true, \T_MINUS_EQUAL => true, \T_MUL_EQUAL => true, \T_DIV_EQUAL => true,
        \T_CONCAT_EQUAL => true, \T_MOD_EQUAL => true, \T_AND_EQUAL => true, \T_OR_EQUAL => true, \T_XOR_EQUAL => true,
        \T_SL_EQUAL => true, \T_SR_EQUAL => true, \T_POW_EQUAL => true, \T_COALESCE_EQUAL => true,
        \T_DOUBLE_ARROW => true, \T_AS => true, \T_CLOSE_TAG => true,
    ];

    /**
     * What ends the right operand of "and" or "or" after it.
     */
    private const ENDS_A_RIGHT_LOW_OPERAND = [',' => true, ';' => true, \T_DOUBLE_ARROW => true, \T_AS => true, \T_CLOSE_TAG => true];

    /**
     * What ends an operand of "and" or "or": the bracket of the expression
     * around and the end of a statement only; "or" and "xor" end one of
     * "and", which binds tighter. An assignment, a ternary, print and
     * yield bind tighter than both: they belong to the operand.
     */
    private const ENDS_A_LOW_OPERAND = [
        ',' => true, ';' => true, \T_DOUBLE_ARROW => true, \T_AS => true, \T_RETURN => true, \T_ECHO => true,
        \T_CASE => true, \T_ELSE => true, \T_DO => true, \T_OPEN_TAG => true, \T_OPEN_TAG_WITH_ECHO => true,
        \T_CLOSE_TAG => true, \T_INLINE_HTML => true,
    ];

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers the closing index of each bracket
     * @param array<int, int>                            $openers the opening index of each closing bracket
     */
    public function __construct(
        private readonly array $tokens,
        private readonly array $closers,
        private readonly array $openers,
    ) {}

    /**
     * The condition the tokens in [$first, $last] write.
     *
     * @param \Closure(string): non-empty-list<string> $functions the lowercase functions a call by the name may reach, in the order PHP tries them
     */
    public function of(int $first, int $last, \Closure $functions): ValueCondition
    {
        $start = PhpTokens::nextSignificant($this->tokens, $first, $last + 1);
        $end = null === $start ? null : PhpTokens::previousSignificant($this->tokens, $last, $start);
        if (null === $start || null === $end || $end - $start > self::REACH) {
            return ValueCondition::unknown();
        }

        if ('(' === $this->tokens[$start] && ($this->closers[$start] ?? null) === $end) {
            return $this->of($start + 1, $end - 1, $functions);
        }

        // An arrow function, throw, include and require take what follows
        // them, "or" and "and" included.
        $operators = $this->operators($start, $end);
        if ([] !== array_intersect_key($operators, self::LOOSER_THAN_LOW_BOOLEANS)) {
            return ValueCondition::unknown();
        }

        foreach ([\T_LOGICAL_OR, \T_LOGICAL_XOR, \T_LOGICAL_AND] as $id) {
            if (isset($operators[$id])) {
                return \T_LOGICAL_XOR === $id ? ValueCondition::unknown() : $this->split($id, $operators[$id], $start, $end, $functions);
            }
        }

        if ([] !== array_intersect_key($operators, self::LOOSER_THAN_BOOLEANS)) {
            return ValueCondition::unknown();
        }

        foreach ([\T_BOOLEAN_OR, \T_BOOLEAN_AND] as $id) {
            if (isset($operators[$id])) {
                return $this->split($id, $operators[$id], $start, $end, $functions);
            }
        }

        if ([] !== array_intersect_key($operators, self::BITWISE)) {
            return ValueCondition::unknown();
        }

        // A comparison does not chain: two of one level are no PHP.
        foreach ([self::EQUALITIES, self::ORDERINGS] as $comparisons) {
            $found = [];
            foreach ($comparisons as $kind => $operator) {
                foreach ($operators[$kind] ?? [] as $at) {
                    $found[] = [$at, $operator];
                }
            }

            if ([] === $found) {
                continue;
            }

            if (1 !== \count($found)) {
                return ValueCondition::unknown();
            }

            [$at, $operator] = $found[0];

            return ValueCondition::compare($operator, $this->of($start, $at - 1, $functions), $this->of($at + 1, $end, $functions));
        }

        if ('!' === $this->tokens[$start]) {
            return ValueCondition::not($this->of($start + 1, $end, $functions));
        }

        return $this->operand($start, $end, $functions);
    }

    /**
     * The chain the "&&", "||", "and" or "or" at $index starts, from the
     * operand on its left to the last one: "A && B && C" runs B where A
     * holds, and C where A and B do. Each operand, from its first to its
     * last token, in order. Null when the operator is not the first of
     * its chain, whose first one reads it whole, or when an operand is
     * empty. An operand on the left longer than REACH tokens is left out.
     *
     * @return list<array{int, int}>|null
     */
    public function chain(int $index): ?array
    {
        $id = $this->id($index);
        $low = \T_LOGICAL_AND === $id || \T_LOGICAL_OR === $id;
        $looser = \T_LOGICAL_AND === $id ? [\T_LOGICAL_OR => true, \T_LOGICAL_XOR => true] : (\T_BOOLEAN_AND === $id ? [\T_BOOLEAN_OR => true] : []);

        $left = $this->leftOperand($index, $id, ($low ? self::ENDS_A_LOW_OPERAND : self::ENDS_A_BOOLEAN_OPERAND) + $looser, $low);
        if (false === $left) {
            return null;
        }

        $operands = null === $left ? [] : [$left];
        // Each operand ends at the next operator of the chain, which goes
        // on after it.
        $ends = ($low ? self::ENDS_A_RIGHT_LOW_OPERAND + [\T_LOGICAL_OR => true] : self::ENDS_A_RIGHT_BOOLEAN_OPERAND) + $looser + [$id => true];
        $from = $index;
        while (true) {
            $right = $this->rightOperand($from, $ends, $low);
            if (null === $right) {
                return null;
            }

            $operands[] = [$right[0], $right[1]];
            if (null === $right[2] || $this->id($right[2]) !== $id) {
                break;
            }

            $from = $right[2];
        }

        return \count($operands) > 1 ? $operands : null;
    }

    /**
     * The operand on the left of the operator at $index; false when
     * another operator of its chain stands before it, or when the operand
     * is empty; null when it reaches past REACH tokens. The operand of
     * "and" or "or" holds a ternary whole: a "?" whose ":" stands after
     * the operator opens the arm the operand is written in, and ends it.
     *
     * @param array<int|string, true> $ends
     *
     * @return array{int, int}|false|null
     */
    private function leftOperand(int $index, int|string $id, array $ends, bool $low): array|false|null
    {
        $first = null;
        $last = null;
        $colons = 0;
        $walked = 0;
        for ($i = PhpTokens::previousSignificant($this->tokens, $index - 1); null !== $i; $i = PhpTokens::previousSignificant($this->tokens, $i - 1)) {
            if (++$walked > self::REACH) {
                return null;
            }

            $token = $this->tokens[$i];
            $tokenId = $this->id($i);
            if ($tokenId === $id) {
                return false;
            }

            if (')' === $token || ']' === $token) {
                $opener = $this->openers[$i] ?? null;
                $keyword = null === $opener || ']' === $token ? null : PhpTokens::previousSignificant($this->tokens, $opener - 1);
                if (null === $opener) {
                    return false;
                }

                if (null !== $keyword && \in_array($this->id($keyword), self::HEADS, true)) {
                    break;
                }

                $last ??= $i;
                $first = $i = $opener;

                continue;
            }

            if ($low && ':' === $token) {
                $colons++;
            } elseif ($low && '?' === $token && $colons > 0) {
                $colons--;
            } elseif (PhpTokens::opensNesting($token) || '}' === $token || '?' === $token || isset($ends[$tokenId])) {
                break;
            }

            $last ??= $i;
            $first = $i;
        }

        return null === $first || null === $last ? false : [$first, $last];
    }

    /**
     * The operand after the operator at $index, up to the last token
     * before an end at its own depth, with the token that ends it, null at
     * the end of the file. The operand of "and" or "or" holds a ternary
     * whole: a ":" no "?" of the operand opened ends it.
     *
     * @param array<int|string, true> $ends
     *
     * @return array{int, int, int|null}|null
     */
    private function rightOperand(int $index, array $ends, bool $low): ?array
    {
        $first = PhpTokens::nextSignificant($this->tokens, $index + 1, \PHP_INT_MAX);
        $last = null;
        $open = 0;
        for ($i = $first; null !== $i; $i = PhpTokens::nextSignificant($this->tokens, $i + 1, \PHP_INT_MAX)) {
            $token = $this->tokens[$i];
            if (PhpTokens::opensNesting($token)) {
                $closer = $this->closers[$i] ?? null;
                if (null === $closer) {
                    return null;
                }

                $last = $i = $closer;

                continue;
            }

            if ($low && '?' === $token) {
                $open++;
            } elseif ($low && ':' === $token && $open > 0) {
                $open--;
            } elseif (')' === $token || ']' === $token || '}' === $token || ':' === $token || isset($ends[$this->id($i)])) {
                break;
            }

            $last = $i;
        }

        return null === $first || null === $last ? null : [$first, $last, $i];
    }

    /**
     * The operators outside any bracket of [$start, $end], by kind, each
     * with where it stands, in order.
     *
     * @return array<int|string, list<int>>
     */
    private function operators(int $start, int $end): array
    {
        $operators = [];
        for ($i = $start; $i <= $end; $i++) {
            $token = $this->tokens[$i];
            if (PhpTokens::opensNesting($token)) {
                $i = $this->closers[$i] ?? $end;

                continue;
            }

            if (!PhpTokens::isIgnorable($token)) {
                $operators[$this->id($i)][] = $i;
            }
        }

        return $operators;
    }

    /**
     * "A op B op C", cut at each operator once: (A op B) op C.
     *
     * @param list<int>                                $at        where the operator stands, in order
     * @param \Closure(string): non-empty-list<string> $functions
     */
    private function split(int $id, array $at, int $start, int $end, \Closure $functions): ValueCondition
    {
        $or = \T_LOGICAL_OR === $id || \T_BOOLEAN_OR === $id;
        $bounds = [...$at, $end + 1];
        $condition = $this->of($start, $bounds[0] - 1, $functions);
        for ($rank = 1, $count = \count($bounds); $rank < $count; $rank++) {
            $operand = $this->of($bounds[$rank - 1] + 1, $bounds[$rank] - 1, $functions);
            $condition = $or ? ValueCondition::or($condition, $operand) : ValueCondition::and($condition, $operand);
        }

        return $condition;
    }

    /**
     * One operand: a variable, a byte of one ($v[0], $v[-1]), a string,
     * an integer under one minus sign or none, true, false, null, or a
     * call by its name, each argument passed by position.
     *
     * @param \Closure(string): non-empty-list<string> $functions
     */
    private function operand(int $start, int $end, \Closure $functions): ValueCondition
    {
        $token = $this->tokens[$start];
        if ($start === $end) {
            return $this->single($token);
        }

        if (\T_START_HEREDOC === $this->id($start)) {
            return $this->heredoc($start, $end);
        }

        if ('-' === $token) {
            $number = PhpTokens::nextSignificant($this->tokens, $start + 1, $end + 1);

            return $number === $end && \is_array($this->tokens[$end]) && \T_LNUMBER === $this->tokens[$end][0] ? ValueCondition::literal(-PhpTokens::integerValue($this->tokens[$end][1])) : ValueCondition::unknown();
        }

        $open = PhpTokens::nextSignificant($this->tokens, $start + 1, $end + 1);
        if (null === $open || ($this->closers[$open] ?? null) !== $end) {
            return ValueCondition::unknown();
        }

        if (\is_array($token) && \T_VARIABLE === $token[0] && '$this' !== $token[1] && '[' === $this->tokens[$open]) {
            return $this->byte(substr($token[1], 1), $open + 1, $end - 1);
        }

        $name = PhpTokens::name($token);
        if (null === $name || '(' !== $this->tokens[$open]) {
            return ValueCondition::unknown();
        }

        $arguments = [];
        $from = $open + 1;
        for ($i = $from; $i <= $end; $i++) {
            $current = $this->tokens[$i];
            if ($i < $end && PhpTokens::opensNesting($current)) {
                $i = $this->closers[$i] ?? $end;

                continue;
            }

            if ($i < $end && ',' !== $current) {
                continue;
            }

            $argument = $this->bounds($from, $i - 1);
            if (null === $argument) {
                // f(), or the comma PHP lets end the arguments.
                if ($i === $end && ([] !== $arguments || $from === $open + 1)) {
                    break;
                }

                return ValueCondition::unknown();
            }

            $next = PhpTokens::nextSignificant($this->tokens, $argument[0] + 1, $i);
            if (\T_ELLIPSIS === $this->id($argument[0]) || (null !== $next && ':' === $this->tokens[$next] && null !== PhpTokens::identifier($this->tokens[$argument[0]]))) {
                return ValueCondition::unknown();
            }

            $arguments[] = $this->of($from, $i - 1, $functions);
            $from = $i + 1;
        }

        return ValueCondition::call($functions($name), $arguments);
    }

    /**
     * A heredoc or a nowdoc that interpolates nothing.
     */
    private function heredoc(int $start, int $end): ValueCondition
    {
        $opening = $this->tokens[$start];
        $closing = $this->tokens[$end];
        if (!\is_array($opening) || !\is_array($closing) || \T_END_HEREDOC !== $closing[0]) {
            // A heredoc joined to what follows it.
            return ValueCondition::unknown();
        }

        $body = '';
        for ($i = $start + 1; $i < $end; $i++) {
            $token = $this->tokens[$i];
            if (!\is_array($token) || \T_ENCAPSED_AND_WHITESPACE !== $token[0]) {
                return ValueCondition::unknown();
            }

            $body .= $token[1];
        }

        $value = PhpStringLiteral::decodeHeredoc($opening[1], $body, $closing[1]);

        return null === $value ? ValueCondition::unknown() : ValueCondition::literal($value);
    }

    /**
     * $name[<integer>], the integer under one minus sign or none.
     */
    private function byte(string $name, int $first, int $last): ValueCondition
    {
        $bounds = $this->bounds($first, $last);
        if (null === $bounds) {
            return ValueCondition::unknown();
        }

        [$start, $end] = $bounds;
        $token = $this->tokens[$end];
        if (!\is_array($token) || \T_LNUMBER !== $token[0]) {
            return ValueCondition::unknown();
        }

        $integer = PhpTokens::integerValue($token[1]);
        if ($start === $end) {
            return ValueCondition::byte($name, $integer);
        }

        return '-' === $this->tokens[$start] && PhpTokens::nextSignificant($this->tokens, $start + 1, $end + 1) === $end ? ValueCondition::byte($name, -$integer) : ValueCondition::unknown();
    }

    /**
     * @param array{int, string, int}|string $token
     */
    private function single(array|string $token): ValueCondition
    {
        [$id, $text] = \is_array($token) ? [$token[0], $token[1]] : [$token, $token];
        if (\T_VARIABLE === $id) {
            return '$this' === $text ? ValueCondition::unknown() : ValueCondition::variable(substr($text, 1));
        }

        if (\T_CONSTANT_ENCAPSED_STRING === $id) {
            return ValueCondition::literal(PhpTokens::stringValue($text));
        }

        if (\T_LNUMBER === $id) {
            return ValueCondition::literal(PhpTokens::integerValue($text));
        }

        $name = PhpTokens::name($token);

        return match (null === $name ? null : Ascii::lower(ltrim($name, '\\'))) {
            'true' => ValueCondition::literal(true),
            'false' => ValueCondition::literal(false),
            'null' => ValueCondition::literal(null),
            default => ValueCondition::unknown(),
        };
    }

    /**
     * The first and last significant tokens of [$first, $last].
     *
     * @return array{int, int}|null
     */
    private function bounds(int $first, int $last): ?array
    {
        $start = PhpTokens::nextSignificant($this->tokens, $first, $last + 1);
        $end = null === $start ? null : PhpTokens::previousSignificant($this->tokens, $last, $start);

        return null === $start || null === $end ? null : [$start, $end];
    }

    private function id(int $index): int|string
    {
        $token = $this->tokens[$index];

        return \is_array($token) ? $token[0] : $token;
    }
}
