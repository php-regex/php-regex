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

namespace PHPRegex\Tests\Support;

/**
 * The library's own PHP files, read as tokens: what they call, what they
 * throw, where they exit. Paths are relative to the repository root.
 */
final class LibrarySource
{
    private const ROOT = __DIR__.'/../..';

    /**
     * @return array<string, list<array{0: int, 1: string, 2: int}|string>> tokens by relative path
     */
    public static function files(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::ROOT.'/src', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            $path = str_replace('\\', '/', substr((string) realpath($file->getPathname()), \strlen((string) realpath(self::ROOT)) + 1));
            $files[$path] = token_get_all((string) file_get_contents($file->getPathname()));
        }

        ksort($files);

        return $files;
    }

    /**
     * Calls to a function whose name matches $name, with the line and
     * whether an "@" silences the call.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<array{function: string, line: int, silenced: bool}>
     */
    public static function functionCalls(array $tokens, string $name): array
    {
        $calls = [];
        foreach ($tokens as $index => $token) {
            if (!\is_array($token) || !\in_array($token[0], [\T_STRING, \T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $function = ltrim($token[1], '\\');
            if (1 !== preg_match($name, $function)) {
                continue;
            }

            $before = self::previous($tokens, $index);
            if (\is_array($before) && \in_array($before[0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION, \T_CONST, \T_NEW], true)) {
                continue;
            }

            if ('(' !== self::next($tokens, $index)) {
                continue;
            }

            $calls[] = ['function' => $function, 'line' => $token[2], 'silenced' => '@' === $before];
        }

        return $calls;
    }

    /**
     * The lines of every "exit" or "die".
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<int>
     */
    public static function exits(array $tokens): array
    {
        $lines = [];
        foreach ($tokens as $token) {
            if (\is_array($token) && \T_EXIT === $token[0]) {
                $lines[] = $token[2];
            }
        }

        return $lines;
    }

    /**
     * The class of every "throw new X", resolved against the namespace and
     * the imports of the file; "self", "static" and "parent" are left out.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<array{class: string, line: int}>
     */
    public static function thrownClasses(array $tokens): array
    {
        $namespace = '';
        $imports = [];
        $depth = 0;
        $thrown = [];
        $count = \count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if ('{' === $token || (\is_array($token) && \in_array($token[0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;

                continue;
            }

            if ('}' === $token) {
                $depth--;

                continue;
            }

            if (!\is_array($token)) {
                continue;
            }

            if (\T_NAMESPACE === $token[0]) {
                $name = self::next($tokens, $index);
                $namespace = \is_array($name) ? $name[1] : '';

                continue;
            }

            if (\T_USE === $token[0] && 0 === $depth) {
                $imported = self::nextIndex($tokens, $index);
                $name = $tokens[$imported];
                if (!\is_array($name) || !\in_array($name[0], [\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_STRING], true)) {
                    continue;
                }

                $fqcn = ltrim($name[1], '\\');
                $alias = substr($fqcn, (int) strrpos('\\'.$fqcn, '\\'));
                $as = self::nextIndex($tokens, $imported);
                if (\is_array($tokens[$as]) && \T_AS === $tokens[$as][0]) {
                    $aliasToken = self::next($tokens, $as);
                    $alias = \is_array($aliasToken) ? $aliasToken[1] : $alias;
                }

                $imports[strtolower($alias)] = $fqcn;

                continue;
            }

            if (\T_THROW !== $token[0]) {
                continue;
            }

            $new = self::nextIndex($tokens, $index);
            if (!\is_array($tokens[$new]) || \T_NEW !== $tokens[$new][0]) {
                continue;
            }

            $name = self::next($tokens, $new);
            if (!\is_array($name) || \in_array(strtolower($name[1]), ['self', 'static', 'parent'], true)) {
                continue;
            }

            $thrown[] = ['class' => self::resolve($name, $namespace, $imports), 'line' => $token[2]];
        }

        return $thrown;
    }

    /**
     * @param array{0: int, 1: string, 2: int} $name
     * @param array<string, string>            $imports
     */
    private static function resolve(array $name, string $namespace, array $imports): string
    {
        if (\T_NAME_FULLY_QUALIFIED === $name[0]) {
            return ltrim($name[1], '\\');
        }

        $segments = explode('\\', $name[1]);
        $first = strtolower($segments[0]);
        if (isset($imports[$first])) {
            $segments[0] = $imports[$first];

            return implode('\\', $segments);
        }

        return ('' === $namespace ? '' : $namespace.'\\').$name[1];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private static function next(array $tokens, int $index): array|string|null
    {
        return $tokens[self::nextIndex($tokens, $index)] ?? null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function nextIndex(array $tokens, int $index): int
    {
        $count = \count($tokens);
        for ($next = $index + 1; $next < $count; $next++) {
            if (!\is_array($tokens[$next]) || !\in_array($tokens[$next][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                return $next;
            }
        }

        return $count;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private static function previous(array $tokens, int $index): array|string|null
    {
        for ($previous = $index - 1; $previous >= 0; $previous--) {
            if (!\is_array($tokens[$previous]) || !\in_array($tokens[$previous][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                return $tokens[$previous];
            }
        }

        return null;
    }
}
