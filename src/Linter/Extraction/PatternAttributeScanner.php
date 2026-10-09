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

use PHPRegex\Parser\Internal\LibraryPcre;

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

    private const IDENTIFIER = '/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/';

    /**
     * @param array<string> $files
     *
     * @return list<string>
     */
    public static function specs(array $files): array
    {
        $specs = [];
        foreach ($files as $file) {
            // The attribute is named RegexPattern, and PhpStorm's names its
            // language "RegExp": a file holding neither word, whatever its
            // imports (a group, a parent namespace), declares none. One
            // search covers both.
            $content = self::read($file);
            if (null !== $content && false !== stripos($content, 'regexp')) {
                array_push($specs, ...self::scan($content));
            }
        }

        return array_values(array_unique($specs));
    }

    /**
     * The namespaced functions the files declare under one of the given
     * short names, marked or not ("App\grep"): an unqualified call in their
     * namespace reaches them before a global function of that name.
     *
     * @param array<string> $files
     * @param array<string> $names short function names
     *
     * @return list<string>
     */
    public static function namespacedFunctions(array $files, array $names): array
    {
        // Only identifiers name a function, and none holds a character a
        // pattern reads apart: they go into the search as written.
        $identifiers = array_filter($names, static fn (string $name): bool => 1 === LibraryPcre::match(self::IDENTIFIER, $name));
        $wanted = array_fill_keys(array_map(strtolower(...), $identifiers), true);

        // A file is tokenized only when it declares a function of one of the
        // names, which a search tells far more cheaply. Only whitespace may
        // stand between `function`, `&` and the name: `function /* */ grep(`
        // is not seen, and a global pattern function then captures the
        // calls its namespace makes to it.
        $declares = '/\\bfunction\\s+&?\\s*(?:'.implode('|', array_keys($wanted)).')\\s*\\(/i';
        $functions = [];
        foreach ($files as $file) {
            $content = self::read($file);
            if (null === $content || false === stripos($content, 'namespace') || 1 !== LibraryPcre::match($declares, $content)) {
                continue;
            }

            foreach (self::declarations($content)[1] as $function) {
                if (isset($wanted[strtolower(substr($function, (int) strrpos($function, '\\') + 1))])) {
                    $functions[] = $function;
                }
            }
        }

        return array_values(array_unique($functions));
    }

    /**
     * @return list<string>
     */
    public static function scan(string $content): array
    {
        return self::declarations($content)[0];
    }

    /**
     * The file's text, or null when it cannot be read or would exhaust the
     * memory left to tokenize it: then it is not even read. The extraction
     * reports a linted file as unread; vendor/ is not linted.
     */
    private static function read(string $file): ?string
    {
        // One stat for the size; a file that cannot be opened reads as none.
        $size = @filesize($file);
        $content = false !== $size && MemoryBudget::allowsSize($size, MemoryBudget::TOKENIZE_FACTOR) ? @file_get_contents($file) : false;

        return \is_string($content) ? $content : null;
    }

    /**
     * The pattern function specs the content declares, and every function
     * it declares in a namespace (not a method), marked or not.
     *
     * @return array{list<string>, list<string>}
     */
    private static function declarations(string $content): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($content),
            static fn (\PhpToken $token): bool => !\in_array($token->id, self::IGNORED, true),
        ));

        $specs = [];
        $functions = [];
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
                // A method may be named with a reserved word: match, list.
                if (!isset($tokens[$next]) || 1 !== LibraryPcre::match(self::IDENTIFIER, $tokens[$next]->text)) {
                    continue; // a closure
                }

                $class = [] !== $classes ? $classes[\count($classes) - 1] : null;
                $isMethod = null !== $class && $class[1] === $depth;
                if (!$isMethod && '' !== $namespace) {
                    $functions[] = $namespace.'\\'.$tokens[$next]->text;
                }

                $index = self::markedParameter($tokens, $next + 1, $namespace, $uses);
                if (null === $index) {
                    continue;
                }

                if ($isMethod) {
                    if (null !== $class[0]) {
                        $specs[] = $class[0].'::'.$tokens[$next]->text.'#'.$index;
                    }
                } else {
                    $specs[] = ltrim($namespace.'\\'.$tokens[$next]->text, '\\').'#'.$index;
                }
            }
        }

        return [$specs, $functions];
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
