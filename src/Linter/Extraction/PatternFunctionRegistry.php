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

use PHPRegex\Parser\Internal\Ascii;

/**
 * The set of calls an extractor treats as carrying a regex pattern.
 *
 * Both extraction strategies read the same registry, so the native preg_*
 * functions, the library presets and the project specific helpers declared
 * in configuration are recognised identically whether nikic/php-parser is
 * installed or not.
 *
 * @internal
 */
final readonly class PatternFunctionRegistry
{
    /**
     * Native functions taking the pattern as their first argument.
     */
    private const NATIVE_FUNCTIONS = [
        'preg_match',
        'preg_match_all',
        'preg_replace',
        'preg_replace_callback',
        'preg_split',
        'preg_grep',
        'preg_filter',
    ];

    /**
     * @param array<string, PatternFunction> $functions keyed by lowercase function name
     * @param array<string, PatternFunction> $methods   keyed by lowercase "fqcn::method"
     * @param array<int, string>             $needles   lowercase substrings gating file reads
     * @param array<string, true>            $dropIns   functions a namespaced copy may stand in for
     */
    private function __construct(
        private array $functions,
        private array $methods,
        private array $needles,
        private array $dropIns = [],
    ) {}

    /**
     * Only the native preg_* functions.
     */
    public static function native(): self
    {
        $functions = [];

        foreach (self::NATIVE_FUNCTIONS as $function) {
            $functions[$function] = new PatternFunction($function);
        }

        // The patterns are the keys of the array passed as first argument.
        $functions['preg_replace_callback_array'] = new PatternFunction('preg_replace_callback_array', 0, keysArePatterns: true);

        // Libraries such as thecodingmachine/safe re-publish these under their
        // own namespace with the same signature.
        $dropIns = array_fill_keys(array_keys($functions), true);

        return new self($functions, [], ['preg_'], $dropIns);
    }

    /**
     * The native functions plus the presets enabled by default.
     */
    public static function defaults(): self
    {
        return self::native()->withPresets(InteropPresets::DEFAULT_PRESETS);
    }

    /**
     * @param array<int, string> $presets
     * @param array<int, string> $customFunctions
     */
    public static function create(array $presets, array $customFunctions = []): self
    {
        return self::native()->withPresets($presets)->withCustomFunctions($customFunctions);
    }

    /**
     * @param array<int, string> $presets
     */
    public function withPresets(array $presets): self
    {
        $methods = $this->methods;
        $needles = $this->needles;

        foreach ($presets as $preset) {
            if (!\is_string($preset) || !InteropPresets::exists($preset)) {
                continue;
            }

            $methods = [...$methods, ...InteropPresets::methods($preset)];
            $needles = [...$needles, ...InteropPresets::needles($preset)];
        }

        return new self($this->functions, $methods, self::pruneNeedles($needles), $this->dropIns);
    }

    /**
     * Declare extra calls, as "func", "Some\Cls::method", or either with a
     * "#<index>" suffix pointing at the pattern argument. Append ":keys" when
     * that argument is an array whose keys hold the patterns.
     *
     * @param array<int, string> $specs
     */
    public function withCustomFunctions(array $specs): self
    {
        $functions = $this->functions;
        $methods = $this->methods;
        $needles = $this->needles;

        foreach ($specs as $spec) {
            if (!\is_string($spec)) {
                continue;
            }

            $parsed = self::parseSpec($spec);
            if (null === $parsed) {
                continue;
            }

            [$key, $isMethod, $function, $specNeedles] = $parsed;

            if ($isMethod) {
                $methods[$key] = $function;
            } else {
                $functions[$key] = $function;
            }

            $needles = [...$needles, ...$specNeedles];
        }

        return new self($functions, $methods, self::pruneNeedles($needles), $this->dropIns);
    }

    public function lookupFunction(string $name): ?PatternFunction
    {
        $key = strtolower(ltrim($name, '\\'));

        if (isset($this->functions[$key])) {
            return $this->functions[$key];
        }

        // A namespaced drop-in such as Safe\preg_match() keeps the signature
        // of the function it wraps, so fall back to the unqualified name.
        // Only the native functions are re-published that way; a helper the
        // project declared itself is matched on its exact name.
        $separator = strrpos($key, '\\');
        if (false === $separator) {
            return null;
        }

        $unqualified = substr($key, $separator + 1);

        return isset($this->dropIns[$unqualified]) ? $this->functions[$unqualified] ?? null : null;
    }

    public function lookupMethod(string $class, string $method): ?PatternFunction
    {
        return $this->methods[strtolower(ltrim($class, '\\').'::'.$method)] ?? null;
    }

    /**
     * Whether a file's contents can possibly hold a registered call.
     */
    public function matchesContent(string $content): bool
    {
        foreach ($this->needles as $needle) {
            if (false !== stripos($content, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string, 1: bool, 2: PatternFunction, 3: array<int, string>}|null
     */
    private static function parseSpec(string $spec): ?array
    {
        $spec = trim($spec);
        if ('' === $spec) {
            return null;
        }

        $keysArePatterns = false;
        $argumentIndex = 0;

        $hash = strrpos($spec, '#');
        if (false !== $hash) {
            $suffix = substr($spec, $hash + 1);
            $spec = substr($spec, 0, $hash);

            if (str_ends_with(strtolower($suffix), ':keys')) {
                $keysArePatterns = true;
                $suffix = substr($suffix, 0, -5);
            }

            if ('' !== $suffix) {
                if (!Ascii::isDigit($suffix)) {
                    return null;
                }

                $argumentIndex = (int) $suffix;
            }
        }

        $name = ltrim(trim($spec), '\\');
        if ('' === $name) {
            return null;
        }

        $isMethod = str_contains($name, '::');
        $key = strtolower($name);

        if ($isMethod) {
            [$class, $method] = explode('::', $name, 2);
            if ('' === $class || '' === $method) {
                return null;
            }

            $separator = strrpos($class, '\\');
            $shortClass = false === $separator ? $class : substr($class, $separator + 1);

            // An aliased import hides `Re::` but spells `Re as`, the class
            // global or namespaced, in a group use or not. One space only:
            // `Re\tas` or `Re /* */ as` does not open the file.
            $needle = strtolower($shortClass);

            return [$key, true, new PatternFunction($shortClass.'::'.$method, $argumentIndex, $keysArePatterns), [$needle.'::', $needle.' as']];
        }

        $separator = strrpos($name, '\\');
        $shortName = false === $separator ? $name : substr($name, $separator + 1);

        return [$key, false, new PatternFunction($shortName, $argumentIndex, $keysArePatterns), [strtolower($shortName)]];
    }

    /**
     * Drop needles already covered by a shorter one so files are scanned once
     * per distinct prefix.
     *
     * @param array<int, string> $needles
     *
     * @return array<int, string>
     */
    private static function pruneNeedles(array $needles): array
    {
        $needles = array_values(array_unique(array_map(strtolower(...), $needles)));
        usort($needles, static fn (string $a, string $b): int => \strlen($a) <=> \strlen($b));

        $pruned = [];
        foreach ($needles as $needle) {
            foreach ($pruned as $kept) {
                if (str_starts_with($needle, $kept)) {
                    continue 2;
                }
            }

            $pruned[] = $needle;
        }

        return $pruned;
    }
}
