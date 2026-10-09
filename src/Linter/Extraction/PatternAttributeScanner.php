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
 * Finds the functions and methods that declare a parameter with the
 * PHPRegex\Parser\Attribute\RegexPattern attribute, or with PhpStorm's
 * JetBrains\PhpStorm\Language('RegExp'), as pattern function specs
 * ("App\grep#0", "App\Support\Str::matches#1"): the extractors then read
 * their calls as if the project had configured them.
 *
 * Token based, so it needs no parser: the attribute is resolved through the
 * file's namespace and "use" imports; a closure, an arrow function and a
 * method of an anonymous class are passed over; a function marking several
 * parameters is read at the first.
 *
 * @internal
 */
final class PatternAttributeScanner
{
    private const ATTRIBUTE = 'phpregex\parser\attribute\regexpattern';

    private const PHPSTORM_LANGUAGE = 'jetbrains\phpstorm\language';

    private const IGNORED = [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT];

    /**
     * @param array<string> $files
     *
     * @return list<string>
     */
    public static function specs(array $files): array
    {
        $specs = [];
        foreach ($files as $file) {
            // An attribute is reached through its namespace, imported or
            // written in full: a file naming neither declares none.
            // A file that cannot be read, or that would exhaust the memory
            // left to tokenize it, declares nothing here, and is not even
            // read: the extraction reports a linted one as unread, and
            // vendor/ is not linted.
            $size = is_file($file) && is_readable($file) ? filesize($file) : false;
            $content = false !== $size && MemoryBudget::allowsSize($size, MemoryBudget::TOKENIZE_FACTOR) ? file_get_contents($file) : false;
            if (\is_string($content) && (false !== stripos($content, 'PHPRegex\Parser\Attribute') || false !== stripos($content, 'JetBrains\PhpStorm\Language'))) {
                array_push($specs, ...self::scan($content));
            }
        }

        return array_values(array_unique($specs));
    }

    /**
     * @return list<string>
     */
    public static function scan(string $content): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($content),
            static fn (\PhpToken $token): bool => !\in_array($token->id, self::IGNORED, true),
        ));

        $specs = [];
        $namespace = '';
        $uses = [];
        $depth = 0;
        /** @var list<array{string|null, int}> $classes the class name (null when anonymous) and the depth of its body */
        $classes = [];
        $pendingClass = false;
        $count = \count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token->is(\T_NAMESPACE) && 0 === $depth && isset($tokens[$i + 1]) && !$tokens[$i + 1]->is(\T_NS_SEPARATOR)) {
                $namespace = $tokens[$i + 1]->is([\T_STRING, \T_NAME_QUALIFIED]) ? $tokens[$i + 1]->text : '';
                $uses = [];
            } elseif ($token->is(\T_USE) && [] === $classes && $depth <= 1 && isset($tokens[$i + 1]) && '(' !== $tokens[$i + 1]->text) {
                $i = self::readUse($tokens, $i + 1, $uses);
            } elseif ($token->is([\T_CLASS, \T_TRAIT, \T_ENUM, \T_INTERFACE])) {
                $anonymous = $i > 0 && $tokens[$i - 1]->is(\T_NEW);
                $name = !$anonymous && isset($tokens[$i + 1]) && $tokens[$i + 1]->is(\T_STRING) ? $tokens[$i + 1]->text : null;
                $pendingClass = [null === $name ? null : ltrim($namespace.'\\'.$name, '\\')];
            } elseif ('{' === $token->text || $token->is([\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                if (false !== $pendingClass) {
                    $classes[] = [$pendingClass[0], $depth];
                    $pendingClass = false;
                }
            } elseif ('}' === $token->text) {
                if ([] !== $classes && $classes[\count($classes) - 1][1] === $depth) {
                    array_pop($classes);
                }
                $depth--;
            } elseif ($token->is(\T_FUNCTION)) {
                $next = $i + 1;
                if (isset($tokens[$next]) && '&' === $tokens[$next]->text) {
                    $next++;
                }
                if (!isset($tokens[$next]) || !$tokens[$next]->is(\T_STRING)) {
                    continue; // a closure
                }

                $index = self::markedParameter($tokens, $next + 1, $namespace, $uses);
                if (null === $index) {
                    continue;
                }

                $class = [] !== $classes ? $classes[\count($classes) - 1] : null;
                if (null !== $class && $class[1] === $depth) {
                    if (null !== $class[0]) {
                        $specs[] = $class[0].'::'.$tokens[$next]->text.'#'.$index;
                    }
                } else {
                    $specs[] = ltrim($namespace.'\\'.$tokens[$next]->text, '\\').'#'.$index;
                }
            }
        }

        return $specs;
    }

    /**
     * Reads "use A\B as C, D;" or "use A\{B, C as D};" into $uses (alias in
     * lower case => name); "use function" and "use const" are passed over.
     *
     * @param list<\PhpToken>       $tokens
     * @param array<string, string> $uses
     */
    private static function readUse(array $tokens, int $i, array &$uses): int
    {
        if (isset($tokens[$i]) && $tokens[$i]->is([\T_FUNCTION, \T_CONST])) {
            while (isset($tokens[$i]) && ';' !== $tokens[$i]->text) {
                $i++;
            }

            return $i;
        }

        $prefix = '';
        $name = null;
        $alias = null;
        $count = \count($tokens);
        for (; $i < $count; $i++) {
            $text = $tokens[$i]->text;
            if ($tokens[$i]->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED])) {
                if (null !== $name && $tokens[$i - 1]->is(\T_AS)) {
                    $alias = $text;
                } else {
                    $name = ltrim($text, '\\');
                }
            } elseif (\in_array($text, [',', ';', '}'], true)) {
                if (null !== $name) {
                    $full = '' === $prefix ? $name : $prefix.'\\'.$name;
                    $uses[strtolower($alias ?? substr($full, (int) strrpos('\\'.$full, '\\')))] = $full;
                }
                $name = null;
                $alias = null;
                if (';' === $text) {
                    return $i;
                }
            } elseif ('{' === $text) {
                $prefix = rtrim((string) $name, '\\');
                $name = null;
            }
        }

        return $i;
    }

    /**
     * The zero-based position of the first parameter that carries the
     * attribute, reading the parameter list that opens at $i.
     *
     * @param list<\PhpToken>       $tokens
     * @param array<string, string> $uses
     */
    private static function markedParameter(array $tokens, int $i, string $namespace, array $uses): ?int
    {
        if (!isset($tokens[$i]) || '(' !== $tokens[$i]->text) {
            return null;
        }

        $index = 0;
        $nesting = 0;
        $inAttribute = 0;
        $count = \count($tokens);
        for ($i++; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token->is(\T_ATTRIBUTE)) {
                $inAttribute = $nesting + 1;
                $nesting++;
            } elseif (\in_array($token->text, ['(', '['], true)) {
                $nesting++;
            } elseif (\in_array($token->text, [')', ']'], true)) {
                if (0 === $nesting) {
                    return null;
                }
                if ($inAttribute === $nesting) {
                    $inAttribute = 0;
                }
                $nesting--;
            } elseif (',' === $token->text && 0 === $nesting) {
                $index++;
            } elseif ($inAttribute === $nesting && $inAttribute > 0
                && $token->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE])) {
                $name = strtolower(self::resolve($token, $namespace, $uses));
                if (self::ATTRIBUTE === $name || (self::PHPSTORM_LANGUAGE === $name && self::namesRegExp($tokens, $i + 1))) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * Whether the argument list that opens at $i, if any, holds the string
     * "RegExp", PhpStorm's name for a regex: #[Language('RegExp')].
     *
     * @param list<\PhpToken> $tokens
     */
    private static function namesRegExp(array $tokens, int $i): bool
    {
        if (!isset($tokens[$i]) || '(' !== $tokens[$i]->text) {
            return false;
        }

        for ($i++; isset($tokens[$i]) && ')' !== $tokens[$i]->text; $i++) {
            if ($tokens[$i]->is(\T_CONSTANT_ENCAPSED_STRING) && 'regexp' === strtolower(substr($tokens[$i]->text, 1, -1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $uses
     */
    private static function resolve(\PhpToken $name, string $namespace, array $uses): string
    {
        if ($name->is(\T_NAME_FULLY_QUALIFIED)) {
            return ltrim($name->text, '\\');
        }

        if ($name->is(\T_NAME_RELATIVE)) {
            return ltrim($namespace.'\\'.substr($name->text, \strlen('namespace\\')), '\\');
        }

        $first = strtolower(explode('\\', $name->text, 2)[0]);
        if (isset($uses[$first])) {
            return $uses[$first].substr($name->text, \strlen($first));
        }

        return ltrim($namespace.'\\'.$name->text, '\\');
    }
}
