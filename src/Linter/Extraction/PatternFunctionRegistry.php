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

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Text\Ascii;

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
final class PatternFunctionRegistry
{
    /**
     * One pattern finding any needle, the first test of matchesContent();
     * null when there is none.
     */
    private readonly ?string $trigger;

    /**
     * @param array<string, PatternFunction>    $functions        keyed by lowercase function name
     * @param array<string, PatternFunction>    $methods          keyed by lowercase "fqcn::method"
     * @param array<int, string>                $needles          lowercase substrings gating file reads; one ending in
     *                                                            " as" stands for an aliased import, see matchesContent()
     * @param array<string, true>               $dropIns          functions a namespaced copy may stand in for
     * @param array<string, true>               $plain            namespaced functions declared without a pattern
     *                                                            parameter, keyed by lowercase name: an unqualified
     *                                                            call in their namespace calls them, not a global one
     * @param bool                              $declarationsRead whether the project's declarations were read before
     *                                                            the files were shared out: an extractor then does not
     *                                                            read those of its own files again
     * @param array<string, PatternFunction>    $namedMethods     keyed by lowercase method name, matched on $this->,
     *                                                            self::, static:: and parent::
     * @param array<string, PatternFunction>    $constructors     keyed by lowercase fqcn
     * @param array<string, PatternFunction>    $attributes       keyed by lowercase fqcn
     * @param array<string, list<list<string>>> $guarded          needles that open a file only with every word of
     *                                                            one of the classes they map to, see matchesContent()
     */
    private function __construct(
        private readonly array $functions,
        private readonly array $methods,
        private readonly array $needles,
        private readonly array $dropIns = [],
        private readonly array $plain = [],
        private readonly bool $declarationsRead = false,
        private readonly array $namedMethods = [],
        private readonly array $constructors = [],
        private readonly array $attributes = [],
        private readonly array $guarded = [],
    ) {
        // Every needle, guarded or not, in one case-insensitive scan: most
        // files hold none, and are then read once instead of once per needle.
        // An aliased import spells the name, then white space or a comment.
        $parts = [];
        foreach ([...$needles, ...array_keys($guarded)] as $needle) {
            $parts[] = str_ends_with($needle, ' as') ? preg_quote(substr($needle, 0, -3), '~').'(?:\s|/[*/]|#)' : preg_quote($needle, '~');
        }

        $this->trigger = [] === $parts ? null : '~(?:'.implode('|', array_values(array_unique($parts))).')~i';
    }

    /**
     * Only the native calls: the preg_* functions, RegexIterator,
     * RecursiveRegexIterator, and filter_var()/filter_input() with
     * FILTER_VALIDATE_REGEXP.
     */
    public static function native(): self
    {
        $functions = [];

        // The pattern is the first argument, but for one function whose
        // patterns are the keys of the array passed as first argument.
        foreach (array_keys(PregFunctions::PARAMETERS) as $function) {
            $functions[$function] = new PatternFunction($function, 0, keysArePatterns: 'preg_replace_callback_array' === $function);
        }

        // filter_var($value, $filter, $options), filter_input($type,
        // $var_name, $filter, $options): with FILTER_VALIDATE_REGEXP, the
        // option "regexp" is compiled as a delimited pattern.
        $functions['filter_var'] = new PatternFunction('filter_var', 2, parameterNames: ['options'], optionPath: ['options', 'regexp'], optionPathRequired: true, filterArgument: [1, 'filter', 'FILTER_VALIDATE_REGEXP']);
        $functions['filter_input'] = new PatternFunction('filter_input', 3, parameterNames: ['options'], optionPath: ['options', 'regexp'], optionPathRequired: true, filterArgument: [2, 'filter', 'FILTER_VALIDATE_REGEXP']);

        // Libraries such as thecodingmachine/safe re-publish these under their
        // own namespace with the same signature.
        $dropIns = array_fill_keys(array_keys($functions), true);

        // RegexIterator::__construct(Iterator $iterator, string $pattern, ...).
        $constructors = [
            'regexiterator' => new PatternFunction('RegexIterator', 1, kind: PatternFunction::KIND_CONSTRUCTOR),
            'recursiveregexiterator' => new PatternFunction('RecursiveRegexIterator', 1, kind: PatternFunction::KIND_CONSTRUCTOR),
        ];

        return new self($functions, [], ['preg_', 'regexiterator', 'filter_validate_regexp'], $dropIns, constructors: $constructors);
    }

    /**
     * The native functions plus the presets enabled by default.
     */
    public static function defaults(): self
    {
        return self::native()->withPresets(InteropPresets::DEFAULT_PRESETS);
    }

    /**
     * Whether a configured spec (--pattern-function, extraction.functions)
     * is one the registry reads: "func", "Some\Cls::method", with
     * "#<index>", ":keys" and "$<name>" as withCustomFunctions() says.
     */
    public static function isSpec(string $spec): bool
    {
        return null !== self::parseSpec($spec);
    }

    /**
     * The warning for a configured spec the registry cannot read: it is
     * left out and the run goes on, whoever reads the configuration.
     */
    public static function malformedSpecWarning(string $spec): string
    {
        return \sprintf('Malformed pattern function "%s", ignored.', $spec);
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
        $functions = $this->functions;
        $methods = $this->methods;
        $namedMethods = $this->namedMethods;
        $constructors = $this->constructors;
        $attributes = $this->attributes;
        $needles = $this->needles;
        $guarded = $this->guarded;

        foreach ($presets as $preset) {
            if (!\is_string($preset) || !InteropPresets::exists($preset)) {
                continue;
            }

            $functions = [...$functions, ...InteropPresets::functions($preset)];
            $methods = [...$methods, ...InteropPresets::methods($preset)];
            $namedMethods = [...$namedMethods, ...InteropPresets::namedMethods($preset)];
            $constructors = [...$constructors, ...InteropPresets::constructors($preset)];
            $attributes = [...$attributes, ...InteropPresets::attributes($preset)];

            $words = InteropPresets::classWords($preset);
            if (null === $words) {
                $needles = [...$needles, ...InteropPresets::needles($preset)];

                continue;
            }

            foreach (InteropPresets::needles($preset) as $needle) {
                $guarded[$needle] ??= [];
                if (!\in_array($words, $guarded[$needle], true)) {
                    $guarded[$needle][] = $words;
                }
            }
        }

        return new self($functions, $methods, self::pruneNeedles($needles), $this->dropIns, $this->plain, $this->declarationsRead, $namedMethods, $constructors, $attributes, $guarded);
    }

    /**
     * Declare extra calls, as "func", "Some\Cls::method", or either with a
     * "#<index>" suffix pointing at the pattern argument. Append ":keys" when
     * that argument is an array whose keys hold the patterns. A call passes
     * the pattern by name as "pattern", "patterns" or "regex", or under the
     * one name a "$name" suffix gives: "grep#1$expression".
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

        return new self($functions, $methods, self::pruneNeedles($needles), $this->dropIns, $this->plain, $this->declarationsRead, $this->namedMethods, $this->constructors, $this->attributes, $this->guarded);
    }

    /**
     * Add the pattern functions the project's code declares (#[RegexPattern],
     * #[Language('RegExp')]), as "name#<index>" specs, or
     * "name#<index>$parameter" to read a named argument under the name of
     * the parameter marked.
     *
     * A function the registry already knows keeps its entry: a configured
     * spec (--pattern-function, extraction.functions), a preset or a native
     * function always wins over a scanned declaration. Several declarations
     * of one function read the union of the parameters they mark, whatever
     * their order. $plain names the namespaced functions declared without a
     * pattern parameter, which an unqualified call reaches before a global
     * function of the same name.
     *
     * @param array<string> $specs
     * @param array<string> $plain
     */
    public function withDeclaredFunctions(array $specs, array $plain = []): self
    {
        /** @var array<string, array{bool, PatternFunction, list<string>, array<int, list<string>>}> $declared */
        $declared = [];
        foreach ($specs as $spec) {
            $parsed = self::parseSpec($spec);
            if (null === $parsed || isset($this->functions[$parsed[0]]) || isset($this->methods[$parsed[0]])) {
                continue;
            }

            [$key, $isMethod, $function, $specNeedles] = $parsed;
            $declared[$key] ??= [$isMethod, $function, $specNeedles, []];
            $declared[$key][3][$function->argumentIndex] = [...$declared[$key][3][$function->argumentIndex] ?? [], ...$function->parameterNames];
        }

        $functions = $this->functions;
        $methods = $this->methods;
        $needles = $this->needles;
        foreach ($declared as $key => [$isMethod, $function, $specNeedles, $names]) {
            // A position named differently by two declarations is read under
            // either name; PatternFunction sorts them, whatever the order of
            // the declarations.
            ksort($names);
            $indexes = array_keys($names);
            $parameterNames = array_values($names);
            $union = new PatternFunction(
                $function->label,
                $indexes[0],
                moreArgumentIndexes: \array_slice($indexes, 1),
                parameterNames: $parameterNames[0],
                moreParameterNames: \array_slice($parameterNames, 1),
            );
            if ($isMethod) {
                $methods[$key] = $union;
            } else {
                $functions[$key] = $union;
            }
            $needles = [...$needles, ...$specNeedles];
        }

        $plainFunctions = $this->plain;
        foreach ($plain as $name) {
            $plainFunctions[Ascii::lower(ltrim($name, '\\'))] = true;
        }

        return new self($functions, $methods, self::pruneNeedles($needles), $this->dropIns, $plainFunctions, $this->declarationsRead, $this->namedMethods, $this->constructors, $this->attributes, $this->guarded);
    }

    /**
     * The same registry, saying the project's declarations were read
     * before the files were shared out.
     */
    public function withDeclarationsRead(): self
    {
        return new self($this->functions, $this->methods, $this->needles, $this->dropIns, $this->plain, true, $this->namedMethods, $this->constructors, $this->attributes, $this->guarded);
    }

    /**
     * Whether the project's declarations were read before the files were
     * shared out: an extractor then does not read those of its own files.
     */
    public function declarationsRead(): bool
    {
        return $this->declarationsRead;
    }

    /**
     * The global pattern functions the registry reads that are no native
     * one, in lower case: those a configuration or a declaration added.
     *
     * @return list<string>
     */
    public function customGlobalFunctions(): array
    {
        return array_values(array_filter(
            array_keys($this->functions),
            fn (string $name): bool => !str_contains($name, '\\') && !isset($this->dropIns[$name]),
        ));
    }

    /**
     * The pattern function a call to a function reaches. $namespaced is
     * the name an unqualified call has in its namespace, which PHP calls
     * first when it is declared, marked or not; $name the global one PHP
     * falls back to.
     */
    public function lookupCall(?string $namespaced, string $name): ?PatternFunction
    {
        if (null !== $namespaced) {
            $function = $this->lookupFunction($namespaced);
            if (null !== $function || isset($this->plain[Ascii::lower(ltrim($namespaced, '\\'))])) {
                return $function;
            }
        }

        return $this->lookupFunction($name);
    }

    public function lookupFunction(string $name): ?PatternFunction
    {
        $key = Ascii::lower(ltrim($name, '\\'));

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
        return $this->methods[Ascii::lower(ltrim($class, '\\').'::'.$method)] ?? null;
    }

    /**
     * The pattern function a method of that name is, called on $this->,
     * self::, static:: or parent::: PHPUnit's assertions.
     */
    public function lookupNamedMethod(string $method): ?PatternFunction
    {
        return $this->namedMethods[Ascii::lower($method)] ?? null;
    }

    /**
     * The pattern function "new Class(...)" is, the class fully qualified.
     */
    public function lookupConstructor(string $class): ?PatternFunction
    {
        return $this->constructors[Ascii::lower(ltrim($class, '\\'))] ?? null;
    }

    /**
     * The pattern function "#[Class(...)]" is, the class fully qualified.
     */
    public function lookupAttribute(string $class): ?PatternFunction
    {
        return $this->attributes[Ascii::lower(ltrim($class, '\\'))] ?? null;
    }

    /**
     * Whether a file's contents can possibly hold a registered call.
     *
     * A needle ending in " as" stands for an aliased import: the name, then
     * any white space or comment PHP allows before the keyword, "as" in any
     * case. "use Composer\Pcre\Preg\tas P;" opens the file as
     * "use Composer\Pcre\Preg as P;" does.
     */
    public function matchesContent(string $content): bool
    {
        if (null === $this->trigger || 1 !== LibraryPcre::match($this->trigger, $content)) {
            return false;
        }

        $lowered = Ascii::lower($content);
        $aliases = [];
        foreach ($this->needles as $needle) {
            if (str_ends_with($needle, ' as')) {
                $aliases[] = substr($needle, 0, -3);

                continue;
            }

            if (str_contains($lowered, Ascii::lower($needle))) {
                return true;
            }
        }

        foreach ($aliases as $name) {
            if (self::importsAs($content, $lowered, $name)) {
                return true;
            }
        }

        // A preset matched on a fully qualified class reaches it only through
        // a name written with every word of the class: an import, an alias,
        // a group use, a namespace declaration, a qualified name.
        foreach ($this->guarded as $needle => $classes) {
            $spelled = false;
            foreach ($classes as $words) {
                $spelled = true;
                foreach ($words as $word) {
                    if (!str_contains($lowered, Ascii::lower($word))) {
                        $spelled = false;

                        break;
                    }
                }

                if ($spelled) {
                    break;
                }
            }

            if ($spelled && (str_ends_with($needle, ' as') ? self::importsAs($content, $lowered, substr($needle, 0, -3)) : str_contains($lowered, Ascii::lower($needle)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the content imports a class of that short name under an
     * alias, any white space or comment before "as".
     *
     * @param string $lowered the content, A to Z lowered
     */
    private static function importsAs(string $content, string $lowered, string $name): bool
    {
        // Only a file holding the name can import it.
        return str_contains($lowered, Ascii::lower($name))
            && 1 === LibraryPcre::match('~'.preg_quote($name, '~').'(?:\s++|/\*.*?\*/|(?://|#(?!\[))[^\r\n]*+)++as\b~is', $content);
    }

    /**
     * @return array{0: string, 1: bool, 2: PatternFunction, 3: array<int, string>}|null
     */
    private static function parseSpec(string $spec): ?array
    {
        $spec = trim($spec, " \t\n\r\0\x0B");
        if ('' === $spec) {
            return null;
        }

        $keysArePatterns = false;
        $argumentIndex = 0;
        // A spec that names no parameter is read under the usual names.
        $parameterNames = PatternFunction::PATTERN_PARAMETER_NAMES;

        $hash = strrpos($spec, '#');
        if (false !== $hash) {
            $suffix = substr($spec, $hash + 1);
            $spec = substr($spec, 0, $hash);

            // "#1$expression": the parameter's name, for a named argument.
            $dollar = strpos($suffix, '$');
            if (false !== $dollar) {
                $parameterName = substr($suffix, $dollar + 1);
                $suffix = substr($suffix, 0, $dollar);
                if (1 !== LibraryPcre::match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $parameterName)) {
                    return null;
                }

                $parameterNames = [$parameterName];
            }

            if (str_ends_with(Ascii::lower($suffix), ':keys')) {
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

        $name = ltrim(trim($spec, " \t\n\r\0\x0B"), '\\');
        if ('' === $name) {
            return null;
        }

        $isMethod = str_contains($name, '::');
        $key = Ascii::lower($name);

        if ($isMethod) {
            [$class, $method] = explode('::', $name, 2);
            if ('' === $class || '' === $method) {
                return null;
            }

            $separator = strrpos($class, '\\');
            $shortClass = false === $separator ? $class : substr($class, $separator + 1);

            // An aliased import hides `Re::` but spells `Re as`, the class
            // global or namespaced, in a group use or not, whatever white
            // space or comment stands before the keyword: see matchesContent().
            $needle = Ascii::lower($shortClass);

            return [$key, true, new PatternFunction($shortClass.'::'.$method, $argumentIndex, $keysArePatterns, parameterNames: $parameterNames), [$needle.'::', $needle.' as']];
        }

        $separator = strrpos($name, '\\');
        $shortName = false === $separator ? $name : substr($name, $separator + 1);

        return [$key, false, new PatternFunction($shortName, $argumentIndex, $keysArePatterns, parameterNames: $parameterNames), [Ascii::lower($shortName)]];
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
        $needles = array_values(array_unique(array_map(Ascii::lower(...), $needles)));
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
