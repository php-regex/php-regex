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

use PHPRegex\Linter\Config\TargetConstraint;
use PHPRegex\Parser\Internal\Ascii;

/**
 * The guards on the PHP version of a file the tokenizer read, as the
 * stretches they hold over: the branches of an "if", an "elseif" and an
 * "else", braced or of one statement, and the rest of a block after
 * "if (<guard>) { return; }" (or "throw", "continue", "break", "exit" alone
 * in the body). A ternary and "match (true)" are not read: the tokenizer
 * does not see where their branches end. Neither is the alternative
 * syntax, "if (...):".
 *
 * @internal
 */
final class TokenGuards
{
    private const IGNORABLE = [\T_WHITESPACE => true, \T_COMMENT => true, \T_DOC_COMMENT => true];

    private const COMPARISONS = [
        \T_IS_GREATER_OR_EQUAL => '>=',
        \T_IS_SMALLER_OR_EQUAL => '<=',
        \T_IS_IDENTICAL => '===',
        \T_IS_NOT_IDENTICAL => '!==',
        \T_IS_EQUAL => '==',
        \T_IS_NOT_EQUAL => '!=',
    ];

    private const EXITS = [\T_RETURN => true, \T_THROW => true, \T_CONTINUE => true, \T_BREAK => true, \T_EXIT => true];

    private const DECLARATIONS = [\T_FUNCTION, \T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM];

    private readonly GuardRegions $regions;

    /**
     * Whether an unqualified name is read as PHP's own: the file holds no
     * namespace, or declares nothing named so.
     */
    private readonly bool $unqualifiedIsGlobal;

    /**
     * The index of the "}" that closes the block each token sits in.
     *
     * @var array<int, int|null>
     */
    private array $enclosing = [];

    /**
     * Whether the file holds a "goto": a label after an early exit may be
     * jumped to, and no early exit is read.
     */
    private readonly bool $jumps;

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $offsets where each token starts in the source
     * @param array<int, int>                            $closers the index of the token closing each bracket
     */
    private function __construct(
        private readonly array $tokens,
        private readonly array $offsets,
        private readonly array $closers,
        string $content,
    ) {
        $this->regions = new GuardRegions();
        $namespaced = false;
        foreach ($tokens as $index => $token) {
            if (\is_array($token) && \T_NAMESPACE === $token[0] && \is_array($next = $this->tokens[$this->next($index)] ?? null) && \in_array($next[0], [\T_STRING, \T_NAME_QUALIFIED], true)) {
                $namespaced = true;

                break;
            }
        }
        $this->unqualifiedIsGlobal = !$namespaced || !VersionGuard::shadowedIn($content);
        $jumps = false;
        foreach ($tokens as $token) {
            $jumps = $jumps || (\is_array($token) && \T_GOTO === $token[0]);
        }
        $this->jumps = $jumps;
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $offsets where each token starts in the source
     * @param array<int, int>                            $closers the index of the token closing each bracket
     */
    public static function regions(array $tokens, array $offsets, array $closers, string $content): GuardRegions
    {
        if (!str_contains($content, 'PHP_VERSION')) {
            return new GuardRegions();
        }

        $guards = new self($tokens, $offsets, $closers, $content);
        $guards->readBlocks();
        foreach ($tokens as $index => $token) {
            if (\is_array($token) && \T_IF === $token[0] && !self::followsElse($tokens, $index)) {
                $guards->readIf($index);
            }
        }

        return $guards->regions;
    }

    /**
     * Each token's block: the "}" closing the innermost "{" open before it,
     * null at the top of the file.
     */
    private function readBlocks(): void
    {
        $open = [];
        foreach ($this->tokens as $index => $token) {
            $this->enclosing[$index] = [] === $open ? null : ($this->closers[$open[\count($open) - 1]] ?? null);
            if ('{' === $token || (\is_array($token) && (\T_CURLY_OPEN === $token[0] || \T_DOLLAR_OPEN_CURLY_BRACES === $token[0]))) {
                $open[] = $index;
            } elseif ('}' === $token) {
                array_pop($open);
            }
        }
    }

    /**
     * An "if" and its "elseif" and "else" branches, from its keyword.
     */
    private function readIf(int $index): void
    {
        $failed = null;
        $branches = 0;
        $leaves = false;
        $end = null;
        $keyword = $index;
        while (true) {
            $open = $this->next($keyword);
            $close = $this->closers[$open] ?? null;
            if ('(' !== ($this->tokens[$open] ?? null) || null === $close) {
                return;
            }

            $body = $this->body($close);
            if (null === $body) {
                return;
            }

            $guard = $this->guard($open + 1, $close);
            $holds = null === $guard ? $failed : ($failed?->intersect($guard->holds) ?? $guard->holds);
            if (null !== $holds) {
                $this->regions->add($body[0], $body[1], $holds);
            }
            $failed = null === $guard ? $failed : ($failed?->intersect($guard->fails) ?? $guard->fails);
            $leaves = 0 === $branches && null !== $guard && $this->leaves($body[2], $body[3]);
            $branches++;
            $end = $body[3];

            $next = $this->next($end);
            $token = $this->tokens[$next] ?? null;
            if (\is_array($token) && \T_ELSEIF === $token[0]) {
                $keyword = $next;

                continue;
            }

            if (\is_array($token) && \T_ELSE === $token[0]) {
                $after = $this->next($next);
                if (\is_array($this->tokens[$after] ?? null) && \T_IF === $this->tokens[$after][0]) {
                    $keyword = $after;

                    continue;
                }

                $else = $this->bodyAt($after);
                if (null !== $else && null !== $failed) {
                    $this->regions->add($else[0], $else[1], $failed);
                }

                return;
            }

            break;
        }

        // "if (<guard>) { return; }" alone: the rest of the block runs
        // where the guard fails.
        if ($leaves && 1 === $branches && null !== $failed && !$this->jumps) {
            $this->restOfBlock($index, $end, $failed);
        }
    }

    /**
     * The rest of the block holding the "if" at $if, after the token at
     * $after, as far as the next "case" or
     * "default" of a switch, but for the functions and the classes declared
     * there: PHP declares those of a file before running it, whatever it
     * returns.
     */
    private function restOfBlock(int $if, int $after, TargetConstraint $constraint): void
    {
        $blockEnd = $this->enclosing[$if] ?? \count($this->tokens);
        $from = $this->offsets[$after] + 1;
        $boundary = $from;
        for ($i = $after + 1; $i < $blockEnd; $i++) {
            $token = $this->tokens[$i];
            if (isset($this->closers[$i])) {
                $i = $this->closers[$i];
                $boundary = '}' === $this->tokens[$i] ? $this->offsets[$i] + 1 : $boundary;

                continue;
            }

            if (';' === $token) {
                $boundary = $this->offsets[$i] + 1;

                continue;
            }

            if (!\is_array($token)) {
                continue;
            }

            if (\T_CASE === $token[0] || \T_DEFAULT === $token[0]) {
                $this->regions->add($from, $this->offsets[$i] - 1, $constraint);

                return;
            }

            $body = $this->declarationBody($i);
            if (null !== $body) {
                $this->regions->add($from, $boundary - 1, $constraint);
                $i = $body;
                $from = $this->offsets[$body] + 1;
                $boundary = $from;
            }
        }

        $this->regions->add($from, $blockEnd < \count($this->tokens) ? $this->offsets[$blockEnd] : \PHP_INT_MAX, $constraint);
    }

    /**
     * The "}" closing the body of the function, the class, the interface,
     * the trait or the enum a token declares; null when it declares none.
     */
    private function declarationBody(int $index): ?int
    {
        $token = $this->tokens[$index];
        if (!\is_array($token) || !\in_array($token[0], self::DECLARATIONS, true)) {
            return null;
        }

        $next = $this->tokens[$this->next($index)] ?? null;
        $previous = $this->tokens[$this->previous($index)] ?? null;
        $declares = match ($token[0]) {
            // A closure takes its parameters right away.
            \T_FUNCTION => '(' !== $next,
            // "Foo::class" and "new class" declare nothing.
            \T_CLASS => !(\is_array($previous) && (\T_DOUBLE_COLON === $previous[0] || \T_NEW === $previous[0])),
            default => true,
        };
        if (!$declares) {
            return null;
        }

        for ($i = $index + 1, $count = \count($this->tokens); $i < $count; $i++) {
            if ('{' === $this->tokens[$i]) {
                return $this->closers[$i] ?? null;
            }
            if (';' === $this->tokens[$i]) {
                return null;
            }
        }

        return null;
    }

    /**
     * The body after the ")" of a condition.
     *
     * @return array{int, int, int, int}|null the first and last byte it spans, its first and last token
     */
    private function body(int $close): ?array
    {
        return $this->bodyAt($this->next($close));
    }

    /**
     * The body starting at a token: "{" to its "}", or one statement to
     * its ";". Null for the alternative syntax, or a statement that does
     * not end.
     *
     * @return array{int, int, int, int}|null the first and last byte it spans, its first and last token
     */
    private function bodyAt(int $start): ?array
    {
        $token = $this->tokens[$start] ?? null;
        if (null === $token || ':' === $token) {
            return null;
        }

        if ('{' === $token) {
            $close = $this->closers[$start] ?? null;

            return null === $close ? null : [$this->offsets[$start], $this->offsets[$close], $start, $close];
        }

        // One statement, to the ";" at its own depth.
        for ($i = $start, $count = \count($this->tokens); $i < $count; $i++) {
            $current = $this->tokens[$i];
            if (isset($this->closers[$i])) {
                $i = $this->closers[$i];

                continue;
            }
            if (';' === $current) {
                return [$this->offsets[$start], $this->offsets[$i], $start, $i];
            }
            if ('}' === $current) {
                return null;
            }
        }

        return null;
    }

    /**
     * Whether the body, from its first token to its last, is one statement
     * that leaves the block: "return", "throw", "continue", "break" or
     * "exit" to the first ";".
     */
    private function leaves(int $first, int $last): bool
    {
        $start = '{' === $this->tokens[$first] ? $this->next($first) : $first;
        $end = '{' === $this->tokens[$first] ? $this->previous($last) : $last;
        $token = $this->tokens[$start] ?? null;
        if (!\is_array($token) || !isset(self::EXITS[$token[0]])) {
            return false;
        }

        for ($i = $start; $i < $end; $i++) {
            if (isset($this->closers[$i])) {
                $i = $this->closers[$i];

                continue;
            }
            if (';' === $this->tokens[$i]) {
                return false;
            }
        }

        return ';' === $this->tokens[$end];
    }

    /**
     * What the condition between two tokens says of the PHP version: a
     * comparison, a call to version_compare(), an "&&" of those, any of
     * them in parentheses. Null for anything else.
     */
    private function guard(int $from, int $to): ?VersionGuard
    {
        $parts = [];
        $start = $from;
        for ($i = $from; $i < $to; $i++) {
            $token = $this->tokens[$i];
            if (isset($this->closers[$i])) {
                $i = $this->closers[$i];

                continue;
            }
            if (\is_array($token) && (\T_BOOLEAN_AND === $token[0] || \T_LOGICAL_AND === $token[0])) {
                $parts[] = [$start, $i];
                $start = $i + 1;
            }
        }
        $parts[] = [$start, $to];

        if (1 < \count($parts)) {
            $guard = null;
            foreach ($parts as [$partFrom, $partTo]) {
                $part = $this->guard($partFrom, $partTo);
                if (null === $part) {
                    return null;
                }
                $guard = $guard?->and($part) ?? $part;
            }

            return $guard;
        }

        $significant = $this->significant($from, $to);

        // In parentheses, alone.
        $first = $significant[0] ?? $to;
        if ('(' === $this->tokens[$first] && ($this->closers[$first] ?? null) === $significant[\count($significant) - 1]) {
            return $this->guard($first + 1, $significant[\count($significant) - 1]);
        }

        return 3 === \count($significant) ? $this->comparison($significant) : $this->versionCompare($significant);
    }

    /**
     * PHP_VERSION_ID compared to an integer literal, either side.
     *
     * @param list<int> $significant the indexes of the three tokens
     */
    private function comparison(array $significant): ?VersionGuard
    {
        [$left, $operator, $right] = array_map(fn (int $index): array|string => $this->tokens[$index], $significant);
        $operator = \is_array($operator) ? (self::COMPARISONS[$operator[0]] ?? null) : (\in_array($operator, ['<', '>'], true) ? $operator : null);
        if (null === $operator) {
            return null;
        }

        if ($this->names($left, 'PHP_VERSION_ID') && null !== ($value = self::integer($right))) {
            return VersionGuard::versionId($operator, $value, true);
        }

        if ($this->names($right, 'PHP_VERSION_ID') && null !== ($value = self::integer($left))) {
            return VersionGuard::versionId($operator, $value, false);
        }

        return null;
    }

    /**
     * version_compare(PHP_VERSION, '<version>', '<operator>').
     *
     * @param list<int> $significant
     */
    private function versionCompare(array $significant): ?VersionGuard
    {
        $tokens = array_map(fn (int $index): array|string => $this->tokens[$index], $significant);
        if (8 !== \count($tokens) || !$this->names($tokens[0], 'version_compare') || '(' !== $tokens[1] || !$this->names($tokens[2], 'PHP_VERSION')
            || ',' !== $tokens[3] || ',' !== $tokens[5] || ')' !== $tokens[7]) {
            return null;
        }

        $version = self::string($tokens[4]);
        $operator = self::string($tokens[6]);

        return null === $version || null === $operator ? null : VersionGuard::versionCompare($version, $operator);
    }

    /**
     * Whether the token is the name of PHP's own constant or function:
     * written with a leading backslash, or unqualified where the file
     * declares nothing named so.
     *
     * @param array{int, string, int}|string $token
     */
    private function names(array|string $token, string $name): bool
    {
        return \is_array($token) && match ($token[0]) {
            \T_NAME_FULLY_QUALIFIED => 0 === strcasecmp('\\'.$name, $token[1]) && ('version_compare' === $name || '\\'.$name === $token[1]),
            \T_STRING => $this->unqualifiedIsGlobal && ('version_compare' === $name ? 0 === strcasecmp($name, $token[1]) : $name === $token[1]),
            default => false,
        };
    }

    /**
     * An integer literal's value as PHP reads it: decimal, hexadecimal
     * ("0x"), octal ("0" or "0o") or binary ("0b"), digits separated by "_"
     * or not.
     *
     * @param array{int, string, int}|string $token
     */
    private static function integer(array|string $token): ?int
    {
        if (!\is_array($token) || \T_LNUMBER !== $token[0]) {
            return null;
        }

        $digits = Ascii::lower(str_replace('_', '', $token[1]));

        return match (true) {
            str_starts_with($digits, '0x') => (int) hexdec(substr($digits, 2)),
            str_starts_with($digits, '0b') => (int) bindec(substr($digits, 2)),
            str_starts_with($digits, '0o') => (int) octdec(substr($digits, 2)),
            str_starts_with($digits, '0') => (int) octdec($digits),
            default => (int) $digits,
        };
    }

    /**
     * A single-quoted string literal's value, or a double-quoted one
     * holding no "$" nor "\".
     *
     * @param array{int, string, int}|string $token
     */
    private static function string(array|string $token): ?string
    {
        if (!\is_array($token) || \T_CONSTANT_ENCAPSED_STRING !== $token[0] || \strlen($token[1]) < 2) {
            return null;
        }

        $value = substr($token[1], 1, -1);

        return str_contains($value, '\\') || ('"' === $token[1][0] && str_contains($value, '$')) ? null : $value;
    }

    /**
     * The tokens between two indexes that are no whitespace nor comment.
     *
     * @return list<int>
     */
    private function significant(int $from, int $to): array
    {
        $significant = [];
        for ($i = $from; $i < $to; $i++) {
            $token = $this->tokens[$i];
            if (!\is_array($token) || !isset(self::IGNORABLE[$token[0]])) {
                $significant[] = $i;
            }
        }

        return $significant;
    }

    private function next(int $index): int
    {
        for ($i = $index + 1, $count = \count($this->tokens); $i < $count; $i++) {
            $token = $this->tokens[$i];
            if (!\is_array($token) || !isset(self::IGNORABLE[$token[0]])) {
                return $i;
            }
        }

        return $i;
    }

    /**
     * The significant token before one: there is one before a "}", the "{"
     * it closes.
     */
    private function previous(int $index): int
    {
        $i = $index - 1;
        while (\is_array($this->tokens[$i]) && isset(self::IGNORABLE[$this->tokens[$i][0]])) {
            $i--;
        }

        return $i;
    }

    /**
     * Whether the "if" at $index is the "if" of an "else if", read with the
     * "if" before it.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private static function followsElse(array $tokens, int $index): bool
    {
        // An "if" is never the first token: the open tag comes first.
        $i = $index - 1;
        while (\is_array($tokens[$i]) && isset(self::IGNORABLE[$tokens[$i][0]])) {
            $i--;
        }

        return \is_array($tokens[$i]) && \T_ELSE === $tokens[$i][0];
    }
}
