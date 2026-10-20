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

/**
 * The namespace and the imports a file declares, read from its tokens into
 * the context its names resolve in.
 *
 * A name in a call, a constant or a class is read as PHP resolves it, so
 * the walk that finds the calls and the walk that reads their values must
 * know the same namespace and the same aliases at every token. Both read
 * the declarations here, as they meet them, and resume where each ends.
 *
 * @internal
 */
final class TokenNameResolver
{
    /**
     * Record the namespace a "namespace X;" or "namespace X { ... }" opens.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return int index to resume scanning from
     */
    public static function readNamespace(array $tokens, int $index, int $totalTokens, NameResolutionContext $context): int
    {
        $nameIndex = PhpTokens::nextSignificant($tokens, $index + 1, $totalTokens);
        if (null === $nameIndex) {
            return $index;
        }

        $name = PhpTokens::name($tokens[$nameIndex]);
        if (null === $name) {
            // "namespace { ... }" declares the global namespace.
            if ('{' === $tokens[$nameIndex]) {
                $context->enterNamespace('');

                return $nameIndex;
            }

            return $index;
        }

        $context->enterNamespace($name);

        return $nameIndex;
    }

    /**
     * Record the aliases an import statement brings into scope.
     *
     * Closure "use ($var)" and trait imports are skipped; the latter cannot be
     * told apart from a class import by tokens alone, and treating one as an
     * alias is harmless.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return int index to resume scanning from
     */
    public static function readUse(array $tokens, int $index, int $totalTokens, NameResolutionContext $context): int
    {
        $cursor = PhpTokens::nextSignificant($tokens, $index + 1, $totalTokens);
        if (null === $cursor) {
            return $index;
        }

        // Closure capture: use ($foo, $bar)
        if ('(' === $tokens[$cursor]) {
            return $index;
        }

        // "use Str;" in a class body uses a trait, and imports nothing.
        if (self::usesATrait($tokens, $index)) {
            return self::skipToStatementEnd($tokens, $cursor, $totalTokens);
        }

        // What the statement imports: classes, functions or constants; an
        // item of a group may say otherwise.
        $kind = \T_CLASS;
        $token = $tokens[$cursor];
        if (\is_array($token) && (\T_CONST === $token[0] || \T_FUNCTION === $token[0])) {
            $kind = $token[0];
            $next = PhpTokens::nextSignificant($tokens, $cursor + 1, $totalTokens);
            if (null === $next) {
                return $index;
            }

            $cursor = $next;
        }

        $prefix = '';
        $name = '';
        $alias = null;
        $expectAlias = false;
        $itemKind = $kind;

        for (; $cursor < $totalTokens; $cursor++) {
            $token = $tokens[$cursor];

            if (PhpTokens::isIgnorable($token)) {
                continue;
            }

            if (';' === $token) {
                self::commitImport($context, $prefix, $name, $alias, $itemKind);

                return $cursor;
            }

            if ('{' === $token) {
                // Group import: the name read so far is the shared prefix.
                $prefix = '' === $name ? $prefix : rtrim($name, '\\').'\\';
                $name = '';
                $alias = null;
                $expectAlias = false;

                continue;
            }

            if ('}' === $token) {
                self::commitImport($context, $prefix, $name, $alias, $itemKind);
                $prefix = '';
                $name = '';
                $alias = null;
                $expectAlias = false;
                $itemKind = $kind;

                continue;
            }

            if (',' === $token) {
                self::commitImport($context, $prefix, $name, $alias, $itemKind);
                $name = '';
                $alias = null;
                $expectAlias = false;
                $itemKind = $kind;

                continue;
            }

            // use A\{B, function c, const D}
            if (\is_array($token) && (\T_CONST === $token[0] || \T_FUNCTION === $token[0]) && '' !== $prefix && '' === $name) {
                $itemKind = $token[0];

                continue;
            }

            if (\is_array($token) && \T_DOUBLE_COLON === $token[0]) {
                // A trait conflict block ("use A, B { B::x insteadof A; }")
                // imports nothing.
                return self::skipToStatementEnd($tokens, $cursor, $totalTokens);
            }

            if (\is_array($token) && \T_AS === $token[0]) {
                $expectAlias = true;

                continue;
            }

            if (\is_array($token) && \T_NS_SEPARATOR === $token[0]) {
                continue;
            }

            $read = PhpTokens::name($token);
            if (null === $read) {
                continue;
            }

            if ($expectAlias) {
                $alias = $read;

                continue;
            }

            $name = $read;
        }

        return $cursor - 1;
    }

    /**
     * Whether the "use" at $index sits in a brace no namespace opens, the
     * body of a class, an enum or a trait: an import is written at the top
     * of the file or of a braced namespace.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private static function usesATrait(array $tokens, int $index): bool
    {
        $depth = 0;
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if ('}' === $token) {
                $depth++;

                continue;
            }

            if ('{' !== $token && !(\is_array($token) && (\T_CURLY_OPEN === $token[0] || \T_DOLLAR_OPEN_CURLY_BRACES === $token[0]))) {
                continue;
            }

            if ($depth > 0) {
                $depth--;

                continue;
            }

            // The brace the "use" sits in: "namespace {" or "namespace A\B {".
            $before = PhpTokens::previousSignificant($tokens, $i - 1);
            if (null !== $before && \is_array($tokens[$before]) && \in_array($tokens[$before][0], [\T_STRING, \T_NAME_QUALIFIED], true)) {
                $before = PhpTokens::previousSignificant($tokens, $before - 1);
            }

            return null === $before || !\is_array($tokens[$before]) || \T_NAMESPACE !== $tokens[$before][0];
        }

        return false;
    }

    private static function commitImport(NameResolutionContext $context, string $prefix, string $name, ?string $alias, int $kind): void
    {
        if ('' === $name) {
            return;
        }

        $target = $prefix.$name;
        if (null === $alias) {
            $separator = strrpos($name, '\\');
            $alias = false === $separator ? $name : substr($name, $separator + 1);
        }

        if ('' === $alias) {
            return;
        }

        if (\T_FUNCTION === $kind) {
            $context->importFunction($alias, $target);

            return;
        }

        if (\T_CONST === $kind) {
            $context->importConstant($alias, $target);

            return;
        }

        $context->importClass($alias, $target);
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private static function skipToStatementEnd(array $tokens, int $index, int $totalTokens): int
    {
        for ($i = $index; $i < $totalTokens; $i++) {
            if (';' === $tokens[$i]) {
                return $i;
            }
        }

        return $totalTokens - 1;
    }
}
