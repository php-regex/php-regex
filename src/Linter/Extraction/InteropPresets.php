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
use PHPRegex\Parser\Internal\NearMiss;

/**
 * Known regex wrappers shipped by third-party libraries.
 *
 * Codebases that route every pattern through composer/pcre or nette/utils
 * never call preg_* directly, so an extractor that only knows the native
 * functions reports "no patterns found" on them. Each preset maps a
 * library's calls to the position of their pattern argument. The names are
 * the slugs of the packages they read.
 *
 * Every preset but phpunit is matched on the fully qualified class once the
 * imports are resolved. PHPUnit's assertions are matched by method name on
 * the receivers a test calls them on ($this->, self::, static::, parent::),
 * besides PHPUnit\Framework\Assert::, PHPUnit\Framework\TestCase:: and the
 * functions of PHPUnit\Framework: their names are PHPUnit's own.
 *
 * @internal
 */
final class InteropPresets
{
    public const COMPOSER_PCRE = 'composer-pcre';
    public const NETTE_UTILS = 'nette-utils';
    public const SPATIE_REGEX = 'spatie-regex';
    public const ILLUMINATE_SUPPORT = 'illuminate-support';
    public const PHPUNIT = 'phpunit';
    public const WEBMOZART_ASSERT = 'webmozart-assert';
    public const BEBERLEI_ASSERT = 'beberlei-assert';
    public const SYMFONY_VALIDATOR = 'symfony-validator';

    /**
     * Presets enabled unless configuration says otherwise: every one. An
     * explicit list means exactly that list.
     */
    public const DEFAULT_PRESETS = [
        self::COMPOSER_PCRE,
        self::NETTE_UTILS,
        self::SPATIE_REGEX,
        self::ILLUMINATE_SUPPORT,
        self::PHPUNIT,
        self::WEBMOZART_ASSERT,
        self::BEBERLEI_ASSERT,
        self::SYMFONY_VALIDATOR,
    ];

    /**
     * The words of a package name that point to a preset whose slug does
     * not hold them: the framework a component ships with, the function a
     * preset is known by.
     */
    private const SUGGESTED_FOR_WORD = [
        'laravel' => self::ILLUMINATE_SUPPORT,
        'str' => self::ILLUMINATE_SUPPORT,
        'preg' => self::COMPOSER_PCRE,
        'assertion' => self::BEBERLEI_ASSERT,
    ];

    /**
     * PHPUnit's assertions taking the pattern as $pattern, first: PHPUnit
     * 12.5 Assert, and assertRegExp()/assertNotRegExp() of PHPUnit 9 and
     * older.
     */
    private const PHPUNIT_ASSERTIONS = [
        'assertMatchesRegularExpression',
        'assertDoesNotMatchRegularExpression',
        'matchesRegularExpression',
        'assertRegExp',
        'assertNotRegExp',
    ];

    /**
     * TestCase's expectations, each with the name of its pattern parameter.
     */
    private const PHPUNIT_EXPECTATIONS = [
        'expectOutputRegex' => 'expectedRegex',
        'expectExceptionMessageMatches' => 'regularExpression',
        'expectUserDeprecationMessageMatches' => 'expectedUserDeprecationMessageRegularExpression',
    ];

    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        return self::DEFAULT_PRESETS;
    }

    public static function exists(string $name): bool
    {
        return \in_array($name, self::names(), true);
    }

    /**
     * The preset a name that is none most likely means, or null: the slug
     * of the package spelled another way ("composer/pcre", "Nette_Utils"),
     * a preset one or two letters off ("phpunt"), or the preset a word of
     * the name belongs to ("laravel-str", "webmozart").
     */
    public static function suggestion(string $name): ?string
    {
        $slug = trim(strtr(Ascii::lower($name), ['/' => '-', '_' => '-', ' ' => '-', '.' => '-']), '-');
        if (self::exists($slug)) {
            return $slug;
        }

        $closest = NearMiss::closest($slug, self::names());
        if (null !== $closest) {
            return $closest;
        }

        foreach (explode('-', $slug) as $word) {
            if (isset(self::SUGGESTED_FOR_WORD[$word])) {
                return self::SUGGESTED_FOR_WORD[$word];
            }

            foreach (self::names() as $preset) {
                if (\in_array($word, explode('-', $preset), true) && 'assert' !== $word) {
                    return $preset;
                }
            }
        }

        return null;
    }

    /**
     * The error for a configured name that is no preset, $where it was
     * read ("--interop"), with the preset it most likely means or, when
     * none, every preset: dropped, the name would leave a list that reads
     * fewer calls than meant, the native ones only when it was the only one.
     */
    public static function unknownError(string $name, ?string $where = null): string
    {
        $suggestion = self::suggestion($name);
        $unknown = \sprintf('Unknown extraction preset "%s"%s', $name, null === $where ? '' : ' in '.$where);

        return null !== $suggestion
            ? \sprintf('%s: did you mean "%s"?', $unknown, $suggestion)
            : \sprintf('%s: the presets are %s.', $unknown, implode(', ', self::names()));
    }

    /**
     * The warning for an entry of the extraction list $key ("interop",
     * "functions") that is no string, named by its type: false says "bool",
     * not an empty name.
     */
    public static function notAStringWarning(string $key, mixed $entry): string
    {
        return \sprintf('Extraction %s entry ignored: expected a string, got %s.', $key, get_debug_type($entry));
    }

    /**
     * Static methods contributed by a preset, keyed by "fqcn::method" in
     * lowercase.
     *
     * @return array<string, PatternFunction>
     */
    public static function methods(string $name): array
    {
        return match ($name) {
            self::COMPOSER_PCRE => [
                ...self::composerPcre('Composer\Pcre\Preg', 'Preg', [
                    'match', 'matchStrictGroups', 'matchWithOffsets',
                    'matchAll', 'matchAllStrictGroups', 'matchAllWithOffsets',
                    'isMatch', 'isMatchStrictGroups', 'isMatchWithOffsets',
                    'isMatchAll', 'isMatchAllStrictGroups', 'isMatchAllWithOffsets',
                    'replace', 'replaceCallback', 'replaceCallbackStrictGroups',
                    'split', 'splitWithOffsets', 'grep',
                ]),
                ...self::composerPcre('Composer\Pcre\Regex', 'Regex', [
                    'match', 'matchStrictGroups', 'matchWithOffsets',
                    'matchAll', 'matchAllStrictGroups', 'matchAllWithOffsets',
                    'isMatch',
                    'replace', 'replaceCallback', 'replaceCallbackStrictGroups',
                ]),
                // The pattern lives in the array keys, as with preg_replace_callback_array().
                'composer\pcre\preg::replacecallbackarray' => new PatternFunction('Preg::replaceCallbackArray', 0, keysArePatterns: true),
                'composer\pcre\regex::replacecallbackarray' => new PatternFunction('Regex::replaceCallbackArray', 0, keysArePatterns: true),
            ],
            // Nette puts the subject first and takes an array of pattern =>
            // replacement; utf8: true appends "u" to every pattern.
            self::NETTE_UTILS => [
                'nette\utils\strings::match' => new PatternFunction('Strings::match', 1, utf8Argument: [5, 'utf8']),
                'nette\utils\strings::matchall' => new PatternFunction('Strings::matchAll', 1, utf8Argument: [6, 'utf8']),
                'nette\utils\strings::split' => new PatternFunction('Strings::split', 1, utf8Argument: [5, 'utf8']),
                'nette\utils\strings::replace' => new PatternFunction('Strings::replace', 1, keysArePatterns: true, replacementIndex: 2, utf8Argument: [6, 'utf8']),
            ],
            self::SPATIE_REGEX => [
                'spatie\regex\regex::match' => new PatternFunction('Regex::match'),
                'spatie\regex\regex::matchall' => new PatternFunction('Regex::matchAll'),
                'spatie\regex\regex::replace' => new PatternFunction('Regex::replace'),
            ],
            self::ILLUMINATE_SUPPORT => [
                'illuminate\support\str::match' => new PatternFunction('Str::match'),
                'illuminate\support\str::matchall' => new PatternFunction('Str::matchAll'),
                'illuminate\support\str::ismatch' => new PatternFunction('Str::isMatch'),
                'illuminate\support\str::replacematches' => new PatternFunction('Str::replaceMatches'),
            ],
            self::PHPUNIT => [
                ...self::onClass('PHPUnit\Framework', 'Assert', self::phpunitAssertions()),
                ...self::onClass('PHPUnit\Framework', 'TestCase', [...self::phpunitAssertions(), ...self::phpunitExpectations()]),
            ],
            // The value first, the pattern second.
            self::WEBMOZART_ASSERT => self::onClass('Webmozart\Assert', 'Assert', self::assertionMethods([
                'regex', 'notRegex', 'nullOrRegex', 'nullOrNotRegex',
                'allRegex', 'allNotRegex', 'allNullOrRegex', 'allNullOrNotRegex',
            ])),
            self::BEBERLEI_ASSERT => self::onClass('Assert', 'Assertion', self::assertionMethods([
                'regex', 'notRegex', 'nullOrRegex', 'nullOrNotRegex', 'allRegex', 'allNotRegex',
            ])),
            default => [],
        };
    }

    /**
     * Methods a preset matches by name alone, on $this->, self::, static::
     * and parent::, keyed by lowercase method name.
     *
     * @return array<string, PatternFunction>
     */
    public static function namedMethods(string $name): array
    {
        if (self::PHPUNIT !== $name) {
            return [];
        }

        $methods = [];
        foreach ([...self::phpunitAssertions(), ...self::phpunitExpectations()] as $method => $function) {
            $methods[Ascii::lower($method)] = new PatternFunction($method, $function->argumentIndex, parameterNames: $function->parameterNames, kind: PatternFunction::KIND_NAMED_METHOD);
        }

        return $methods;
    }

    /**
     * Functions contributed by a preset, keyed by lowercase fully qualified
     * name.
     *
     * @return array<string, PatternFunction>
     */
    public static function functions(string $name): array
    {
        if (self::PHPUNIT !== $name) {
            return [];
        }

        $functions = [];
        foreach (self::phpunitAssertions() as $method => $function) {
            $functions[Ascii::lower('PHPUnit\Framework\\'.$method)] = new PatternFunction($method, $function->argumentIndex, parameterNames: $function->parameterNames);
        }

        return $functions;
    }

    /**
     * Constructors contributed by a preset, keyed by lowercase fully
     * qualified class.
     *
     * @return array<string, PatternFunction>
     */
    public static function constructors(string $name): array
    {
        if (self::SYMFONY_VALIDATOR !== $name) {
            return [];
        }

        // Regex::__construct(?string $pattern, ?string $message, ?string
        // $htmlPattern, ...), and the legacy options array ['pattern' =>
        // ...]. htmlPattern is an HTML attribute, no PCRE.
        return ['symfony\component\validator\constraints\regex' => new PatternFunction('Regex', 0, kind: PatternFunction::KIND_CONSTRUCTOR, optionPath: ['pattern'])];
    }

    /**
     * Attributes contributed by a preset, keyed by lowercase fully qualified
     * class.
     *
     * @return array<string, PatternFunction>
     */
    public static function attributes(string $name): array
    {
        if (self::SYMFONY_VALIDATOR !== $name) {
            return [];
        }

        return ['symfony\component\validator\constraints\regex' => new PatternFunction('Regex', 0, kind: PatternFunction::KIND_ATTRIBUTE, optionPath: ['pattern'])];
    }

    /**
     * Lowercase substrings that must appear in a file for the preset to match.
     *
     * Extractors use them to skip files without tokenizing them. An aliased
     * import, `use Composer\Pcre\Preg as P;` or `use Composer\{Pcre\Preg as P}`,
     * hides `Preg::` but always spells the short class name before `as`: a
     * needle ending in " as" stands for the name, any white space or comment,
     * then the keyword, as PHP reads it. The call is still resolved on the
     * full class name once the file is read. The method names of the
     * assertion presets are their needles: a file of other assertions is not
     * opened.
     *
     * @return array<int, string>
     */
    public static function needles(string $name): array
    {
        return match ($name) {
            self::COMPOSER_PCRE => ['preg::', 'regex::', 'preg as', 'regex as'],
            self::NETTE_UTILS => ['strings::', 'strings as'],
            self::SPATIE_REGEX => ['regex::', 'regex as'],
            self::ILLUMINATE_SUPPORT => ['str::', 'str as'],
            // Every call spells the whole method name.
            self::PHPUNIT => [
                'matchesregularexpression', 'assertdoesnotmatchregularexpression',
                'expectoutputregex', 'expectexceptionmessagematches', 'expectuserdeprecationmessagematches',
                'assertregexp', 'assertnotregexp',
            ],
            self::WEBMOZART_ASSERT, self::BEBERLEI_ASSERT => ['regex('],
            self::SYMFONY_VALIDATOR => ['regex(', 'regex as'],
            default => [],
        };
    }

    /**
     * The words of the fully qualified class a preset matches, lowercase,
     * each of which a file must spell for the preset's needles to open it:
     * an import, an alias, a group use, a namespace declaration or a
     * qualified name writes every segment of Illuminate\Support\Str
     * somewhere, and the call is resolved on that name. Null for a preset
     * whose needles open the file alone: composer/pcre's spell its classes,
     * and PHPUnit's methods are matched by name.
     *
     * @return list<string>|null
     */
    public static function classWords(string $name): ?array
    {
        return match ($name) {
            self::NETTE_UTILS => ['nette', 'utils', 'strings'],
            self::SPATIE_REGEX => ['spatie', 'regex'],
            self::ILLUMINATE_SUPPORT => ['illuminate', 'support', 'str'],
            self::WEBMOZART_ASSERT => ['webmozart', 'assert'],
            self::BEBERLEI_ASSERT => ['assert', 'assertion'],
            self::SYMFONY_VALIDATOR => ['symfony', 'component', 'validator', 'constraints', 'regex'],
            default => null,
        };
    }

    /**
     * @return array<string, PatternFunction> keyed by method name as PHPUnit spells it
     */
    private static function phpunitAssertions(): array
    {
        $functions = [];
        foreach (self::PHPUNIT_ASSERTIONS as $method) {
            $functions[$method] = new PatternFunction($method);
        }

        return $functions;
    }

    /**
     * @return array<string, PatternFunction> keyed by method name as PHPUnit spells it
     */
    private static function phpunitExpectations(): array
    {
        $functions = [];
        foreach (self::PHPUNIT_EXPECTATIONS as $method => $parameter) {
            $functions[$method] = new PatternFunction($method, parameterNames: [$parameter]);
        }

        return $functions;
    }

    /**
     * @param list<string> $methods
     *
     * @return array<string, PatternFunction> keyed by method name
     */
    private static function assertionMethods(array $methods): array
    {
        $functions = [];
        foreach ($methods as $method) {
            $functions[$method] = new PatternFunction($method, 1);
        }

        return $functions;
    }

    /**
     * The class is given as its namespace and its short name: the library
     * need not be installed, its name is never resolved.
     *
     * @param array<string, PatternFunction> $functions keyed by method name
     *
     * @return array<string, PatternFunction> keyed by lowercase "fqcn::method"
     */
    private static function onClass(string $namespace, string $shortClass, array $functions): array
    {
        $entries = [];
        foreach ($functions as $method => $function) {
            $entries[Ascii::lower($namespace.'\\'.$shortClass.'::'.$method)] = new PatternFunction($shortClass.'::'.$method, $function->argumentIndex, parameterNames: $function->parameterNames);
        }

        return $entries;
    }

    /**
     * @param array<int, string> $methods
     *
     * @return array<string, PatternFunction>
     */
    private static function composerPcre(string $class, string $shortClass, array $methods): array
    {
        $entries = [];

        foreach ($methods as $method) {
            $entries[Ascii::lower($class.'::'.$method)] = new PatternFunction($shortClass.'::'.$method);
        }

        return $entries;
    }
}
