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
 * Known regex wrappers shipped by third-party libraries.
 *
 * Codebases that route every pattern through composer/pcre or nette/utils
 * never call preg_* directly, so an extractor that only knows the native
 * functions reports "no patterns found" on them. Each preset maps a
 * library's static methods to the position of their pattern argument.
 *
 * @internal
 */
final class InteropPresets
{
    public const COMPOSER_PCRE = 'composer-pcre';
    public const NETTE_UTILS = 'nette-utils';
    public const SPATIE_REGEX = 'spatie-regex';
    public const LARAVEL_STR = 'laravel-str';

    /**
     * Presets enabled unless configuration says otherwise.
     *
     * composer/pcre is a drop-in wrapper around the native functions with
     * the same argument order, so recognising it can only add coverage.
     */
    public const DEFAULT_PRESETS = [
        self::COMPOSER_PCRE,
    ];

    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        return [
            self::COMPOSER_PCRE,
            self::NETTE_UTILS,
            self::SPATIE_REGEX,
            self::LARAVEL_STR,
        ];
    }

    public static function exists(string $name): bool
    {
        return \in_array($name, self::names(), true);
    }

    /**
     * Static methods contributed by a preset, keyed by "Fqcn::method".
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
            // Nette puts the subject first and takes an array of pattern => replacement.
            self::NETTE_UTILS => [
                'nette\utils\strings::match' => new PatternFunction('Strings::match', 1),
                'nette\utils\strings::matchall' => new PatternFunction('Strings::matchAll', 1),
                'nette\utils\strings::split' => new PatternFunction('Strings::split', 1),
                'nette\utils\strings::replace' => new PatternFunction('Strings::replace', 1, keysArePatterns: true),
            ],
            self::SPATIE_REGEX => [
                'spatie\regex\regex::match' => new PatternFunction('Regex::match'),
                'spatie\regex\regex::matchall' => new PatternFunction('Regex::matchAll'),
                'spatie\regex\regex::replace' => new PatternFunction('Regex::replace'),
            ],
            self::LARAVEL_STR => [
                'illuminate\support\str::match' => new PatternFunction('Str::match'),
                'illuminate\support\str::matchall' => new PatternFunction('Str::matchAll'),
                'illuminate\support\str::ismatch' => new PatternFunction('Str::isMatch'),
                'illuminate\support\str::replacematches' => new PatternFunction('Str::replaceMatches'),
            ],
            default => [],
        };
    }

    /**
     * Lowercase substrings that must appear in a file for the preset to match.
     *
     * Extractors use them to skip files without tokenizing them. The
     * namespace of the classes is one of them: an aliased import such as
     * `use Composer\Pcre\Preg as P;` hides the short class name, while the
     * call is still resolved on the full class name once the file is read.
     *
     * @return array<int, string>
     */
    public static function needles(string $name): array
    {
        return match ($name) {
            self::COMPOSER_PCRE => ['preg::', 'regex::', 'composer\\pcre\\'],
            self::NETTE_UTILS => ['strings::', 'nette\\utils\\'],
            self::SPATIE_REGEX => ['regex::', 'spatie\\regex\\'],
            self::LARAVEL_STR => ['str::', 'illuminate\\support\\'],
            default => [],
        };
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
            $entries[strtolower($class.'::'.$method)] = new PatternFunction($shortClass.'::'.$method);
        }

        return $entries;
    }
}
