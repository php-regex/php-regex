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

namespace PHPRegex\Linter\Config;

use PHPRegex\Linter\Formatter\LinkFormatter;
use PHPRegex\Linter\Rule\RuleCatalog;

/**
 * What regex.json and regex.dist.json may hold, as one JSON Schema.
 *
 * The loader validates against this definition and regex.schema.json is
 * written from it, so an editor and the lint command accept the same files.
 * Annotations only the loader reads: "x-case-insensitive" lets an enum
 * match whatever the case (editors still suggest the lower-case spelling),
 * "x-expected" is what an error message says the value should be, and
 * "x-item" names what a list holds, for the message about an item refused.
 *
 * @internal
 */
final class LintConfigSchema
{
    /**
     * The libraries extraction.interop can name, by package slug, in the
     * order of InteropPresets::names().
     */
    private const INTEROP_PRESETS = [
        'composer-pcre' => 'composer/pcre: Composer\Pcre\Preg and Composer\Pcre\Regex.',
        'nette-utils' => 'nette/utils: Nette\Utils\Strings, whose pattern is the second argument.',
        'spatie-regex' => 'spatie/regex: Spatie\Regex\Regex.',
        'illuminate-support' => 'illuminate/support: Illuminate\Support\Str::match, matchAll, isMatch and replaceMatches.',
        'phpunit' => 'phpunit/phpunit: the regex assertions and expectations, matched by method name on $this->, self::, static::, parent::, Assert:: and TestCase::, and as functions of PHPUnit\Framework.',
        'webmozart-assert' => 'webmozart/assert: Webmozart\Assert\Assert::regex, notRegex and their nullOr and all variants.',
        'beberlei-assert' => 'beberlei/assert: Assert\Assertion::regex, notRegex and their nullOr and all variants.',
        'symfony-validator' => 'symfony/validator: the Symfony\Component\Validator\Constraints\Regex constraint, as new and as attribute.',
    ];

    private const FORMATS = [
        'console' => 'Human-friendly console output.',
        'json' => 'Machine-readable JSON report.',
        'github' => 'GitHub Actions annotations format.',
        'checkstyle' => 'Checkstyle XML report.',
        'junit' => 'JUnit XML report.',
    ];

    private const REDOS_MODES = [
        'theoretical' => 'Report theoretical ReDoS risks.',
        'confirmed' => 'Only report confirmed ReDoS risks.',
    ];

    private const THRESHOLDS = [
        'low' => 'Report low and higher.',
        'medium' => 'Report medium and higher.',
        'high' => 'Report high and higher.',
        'critical' => 'Report only critical.',
    ];

    /**
     * The JSON Schema (draft 2020-12) of regex.json.
     *
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://raw.githubusercontent.com/php-regex/php-regex/2.x/src/Linter/regex.schema.json',
            'title' => 'PHPRegex Lint Configuration',
            'description' => 'Configuration of the regex lint command (regex.json or regex.dist.json). Keys regex.json sets replace the ones regex.dist.json sets: objects key by key, lists whole.',
            'type' => 'object',
            'additionalProperties' => false,
            '$defs' => [
                'nonEmptyString' => [
                    'type' => 'string',
                    'minLength' => 1,
                ],
                'stringList' => [
                    'description' => 'A single string or a list of strings.',
                    'x-expected' => 'a list of non-empty strings',
                    'anyOf' => [
                        ['$ref' => '#/$defs/nonEmptyString'],
                        [
                            'type' => 'array',
                            'items' => ['$ref' => '#/$defs/nonEmptyString'],
                        ],
                    ],
                ],
                'optimizationOptions' => self::optimizationOptions(),
                'checksRedos' => self::checksRedos(),
                'checksOptimizations' => self::checksOptimizations(),
                'checksLint' => self::checksLint(),
                'checksComplexity' => self::checksComplexity(),
            ],
            'properties' => [
                '$schema' => [
                    'type' => 'string',
                    'description' => 'JSON Schema reference for editor integration.',
                    'format' => 'uri-reference',
                    'examples' => ['./vendor/php-regex/regex-linter/regex.schema.json'],
                ],
                '$id' => [
                    'type' => 'string',
                    'description' => 'Schema identifier (usually a URL).',
                    'format' => 'uri-reference',
                ],
                'paths' => [
                    '$ref' => '#/$defs/stringList',
                    'description' => 'Directories or files to scan for regex patterns. Omit to scan the working directory.',
                    'examples' => ['src', ['src', 'tests']],
                ],
                'exclude' => [
                    '$ref' => '#/$defs/stringList',
                    'description' => 'Paths to exclude from scanning. Omit to use the CLI default (vendor).',
                    'examples' => ['vendor', ['vendor', 'tests', 'Fixtures']],
                ],
                'phpVersion' => [
                    'description' => 'The PHP version the patterns are judged for, as "8.3" or a PHP_VERSION_ID like 80300, or "runtime" for the PHP running the command with the PCRE2 it links. Omit to read it from composer.json (config.platform.php, else the lowest PHP require.php allows), else to use the running PHP. The --php-version option wins over it.',
                    'x-expected' => 'a version like "8.3", a PHP_VERSION_ID like 80300, or "runtime"',
                    'type' => ['string', 'integer'],
                    'pattern' => '^([0-9]+\.[0-9]+(\.[0-9]+)?|[Rr][Uu][Nn][Tt][Ii][Mm][Ee])$',
                    'minimum' => 10000,
                    'examples' => ['8.2', '8.4', 80300],
                ],
                'pcreVersion' => [
                    'description' => 'The PCRE2 release the patterns are judged for, as "10.44". Omit to use the release bundled with the target PHP, or the running PCRE2 when the target is the running PHP. The --pcre-version option wins over it.',
                    'x-expected' => 'a PCRE2 release like "10.44"',
                    'type' => 'string',
                    'pattern' => '^[0-9]+\.[0-9]{2}$',
                    'examples' => ['10.42', '10.44'],
                ],
                'locale' => [
                    'description' => 'The LC_CTYPE locale the patterns run under, as setlocale() names it: its character tables decide the other case of each byte and the bytes of each type without UCP, and with them the size of some classes. Omit to use the locale of the PHP running the command, "C" unless it called setlocale(). A locale this machine cannot set is an error. The --locale option wins over it.',
                    'x-expected' => 'a locale name like "tr_TR.UTF-8"',
                    'type' => 'string',
                    'minLength' => 1,
                    'examples' => ['C', 'tr_TR.UTF-8', 'fr_FR.ISO8859-1'],
                ],
                'extraction' => self::extraction(),
                'jobs' => [
                    'type' => 'integer',
                    'description' => 'Number of parallel workers. Omit to auto-detect based on CPU cores.',
                    'minimum' => 1,
                    'examples' => [4],
                ],
                'format' => self::enumProperty('Output format for lint results.', self::FORMATS, 'console'),
                'ide' => self::ide(),
                'reportUnmatchedIgnores' => [
                    'type' => 'boolean',
                    'description' => 'Report as regex.ignore.unmatched each @regex-ignore marker, each issue identifier it names, and each baseline entry of a file the run reads, that silences nothing. The --no-report-unmatched-ignores option turns it off for one run.',
                    'default' => true,
                ],
                'checks' => [
                    'type' => 'object',
                    'description' => 'Which checks run, and how.',
                    'additionalProperties' => false,
                    'properties' => [
                        'validation' => [
                            'type' => 'boolean',
                            'description' => 'Enable regex syntax validation.',
                            'default' => true,
                        ],
                        'redos' => [
                            '$ref' => '#/$defs/checksRedos',
                            'description' => 'ReDoS analysis settings.',
                        ],
                        'optimizations' => [
                            '$ref' => '#/$defs/checksOptimizations',
                            'description' => 'Optimization suggestion settings.',
                        ],
                        'lint' => [
                            '$ref' => '#/$defs/checksLint',
                            'description' => 'Lint rule settings.',
                        ],
                        'complexity' => [
                            '$ref' => '#/$defs/checksComplexity',
                            'description' => 'Pattern complexity settings: regex.lint.complexity, a warning for each pattern whose complexity score reaches the threshold.',
                        ],
                    ],
                ],
            ],
            'examples' => [
                [
                    '$schema' => './vendor/php-regex/regex-linter/regex.schema.json',
                    'paths' => ['src'],
                    'exclude' => ['vendor', 'tests'],
                    'phpVersion' => '8.2',
                    'format' => 'console',
                    'jobs' => 4,
                    'ide' => 'phpstorm',
                    'checks' => [
                        'validation' => true,
                        'redos' => [
                            'enabled' => true,
                            'mode' => 'theoretical',
                            'threshold' => 'high',
                        ],
                        'optimizations' => [
                            'enabled' => true,
                            'minSavings' => 2,
                            'options' => [
                                'digits' => true,
                                'word' => true,
                                'possessive' => false,
                                'minQuantifierCount' => 4,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The interop presets extraction.interop may name.
     *
     * @return list<string>
     */
    public static function interopPresets(): array
    {
        return array_keys(self::INTEROP_PRESETS);
    }

    /**
     * @return array<string, mixed>
     */
    private static function optimizationOptions(): array
    {
        $flag = static fn (string $description, bool $default): array => [
            'type' => 'boolean',
            'description' => $description,
            'default' => $default,
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'digits' => $flag('Optimize digit character classes like [0-9] to \d.', true),
                'word' => $flag('Optimize word character classes like [A-Za-z0-9_] to \w.', true),
                'ranges' => $flag('Optimize consecutive character ranges.', true),
                'canonicalizeCharClasses' => $flag('Canonicalize character classes (sort ranges, remove duplicates).', true),
                'possessive' => $flag('Suggest possessive quantifiers (*+, ++, {m,n}+) to prevent ReDoS.', false),
                'factorize' => $flag('Factorize common prefixes/suffixes in alternations.', false),
                'minQuantifierCount' => [
                    'type' => 'integer',
                    'description' => 'Minimum quantifier count required to suggest optimizations (min: 2).',
                    'minimum' => 2,
                    'default' => 4,
                ],
                'verifyWithAutomata' => $flag('Verify optimization suggestions with the automata solver when possible.', true),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function checksRedos(): array
    {
        $mode = self::enumProperty('ReDoS analysis mode. To turn the analysis off, set enabled to false.', self::REDOS_MODES, 'theoretical');
        $mode['x-case-insensitive'] = true;
        $mode['x-expected'] = 'theoretical or confirmed (checks.redos.enabled set to false turns the analysis off)';

        $threshold = self::enumProperty('Minimum severity threshold for reporting ReDoS issues.', self::THRESHOLDS, 'high');
        $threshold['x-case-insensitive'] = true;

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'enabled' => [
                    'type' => 'boolean',
                    'description' => 'Enable ReDoS vulnerability analysis. Disabled by default for performance; setting mode or threshold does not enable it.',
                    'default' => false,
                ],
                'mode' => $mode,
                'threshold' => $threshold,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function checksOptimizations(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'enabled' => [
                    'type' => 'boolean',
                    'description' => 'Enable regex optimization suggestions. Setting minSavings or options does not enable them.',
                    'default' => true,
                ],
                'minSavings' => [
                    'type' => 'integer',
                    'description' => 'Minimum character savings threshold to report optimization suggestions.',
                    'minimum' => 1,
                    'default' => 1,
                ],
                'options' => [
                    '$ref' => '#/$defs/optimizationOptions',
                    'description' => 'What the optimizer may rewrite.',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function checksLint(): array
    {
        $rules = [];
        foreach (RuleCatalog::configurableIds() as $id) {
            $rules[$id] = [
                'type' => 'boolean',
                'description' => RuleCatalog::description($id),
                'default' => RuleCatalog::enables([], $id),
            ];
        }
        $off = RuleCatalog::rulesOffByDefault();
        $last = array_pop($off) ?? '';

        return [
            'type' => 'object',
            'description' => 'Lint check settings.',
            'additionalProperties' => false,
            'properties' => [
                'enabled' => [
                    'type' => 'boolean',
                    'description' => 'Enable lint checks for regex anti-patterns and style issues. Setting rules does not enable them.',
                    'default' => true,
                ],
                'rules' => [
                    'type' => 'object',
                    'description' => 'Enable or disable specific lint rules. All rules are enabled by default except '.implode(', ', $off).' and '.$last.'.',
                    'additionalProperties' => false,
                    'properties' => $rules,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function checksComplexity(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'enabled' => [
                    'type' => 'boolean',
                    'description' => 'Report regex.lint.complexity, a warning for each pattern whose complexity score reaches the threshold. Disabled by default; setting threshold does not enable it.',
                    'default' => false,
                ],
                'threshold' => [
                    'type' => 'integer',
                    'description' => 'The complexity score from which a pattern is reported: the score Regex::validate() returns as complexityScore.',
                    'minimum' => 1,
                    'default' => 50,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function extraction(): array
    {
        $presets = [];
        foreach (self::INTEROP_PRESETS as $name => $description) {
            $presets[] = ['const' => $name, 'description' => $description];
        }

        return [
            'type' => 'object',
            'description' => 'Which calls the linter reads patterns from, beyond the native preg_* functions, RegexIterator and filter_var().',
            'additionalProperties' => false,
            'properties' => [
                'interop' => [
                    'type' => 'array',
                    'description' => 'Libraries whose calls carry a regex pattern, beyond the native preg_* functions, RegexIterator and filter_var(). Every preset is on by default; a list reads exactly the presets it names, those a later release adds left off. Set to [] to read the native calls only. A name that is no preset is an error, which names the preset it most likely means.',
                    'x-expected' => 'a list of distinct presets among '.implode(', ', array_keys(self::INTEROP_PRESETS)),
                    'x-item' => 'preset',
                    'default' => array_keys(self::INTEROP_PRESETS),
                    'uniqueItems' => true,
                    'items' => ['oneOf' => $presets],
                    'examples' => [['composer-pcre'], ['composer-pcre', 'nette-utils'], []],
                ],
                'functions' => [
                    'type' => 'array',
                    'description' => 'Project helpers carrying a pattern, as "function" or "Some\Class::method". Append "#<index>" when the pattern is not the first argument, and "#<index>:keys" when that argument is an array whose keys hold the patterns. A call may pass the pattern by name as pattern:, patterns: or regex:; append "$<name>" to read it under the helper\'s own parameter name instead, as in "App\grep#1$expression".',
                    'x-expected' => 'a list of distinct non-empty strings',
                    'default' => [],
                    'uniqueItems' => true,
                    'items' => ['$ref' => '#/$defs/nonEmptyString'],
                    'examples' => [['App\Support\Str::matches'], ['App\Support\Str::matches#1', 'regex_check']],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function ide(): array
    {
        $choices = [['const' => '', 'description' => 'Disable clickable links.']];
        foreach (LinkFormatter::IDE_LINK_FORMATS as $name => $template) {
            $choices[] = ['const' => $name, 'description' => 'Use '.$template];
        }
        $choices[] = [
            'type' => 'string',
            'minLength' => 1,
            'pattern' => '.*%(?:f|file%|relFile%|l|line%|c|column%).*',
            'description' => 'Custom URL template with placeholders.',
        ];

        return [
            'description' => 'IDE name or custom URL template for clickable file links. Use placeholders like %f, %l, %c, or %relFile%.',
            'x-expected' => 'one of '.implode(', ', array_keys(LinkFormatter::IDE_LINK_FORMATS)).', an empty string, or a URL template holding %f or %l',
            'default' => '',
            'oneOf' => $choices,
            'examples' => ['phpstorm', 'vscode', 'vscode://file/%f:%l'],
        ];
    }

    /**
     * @param array<string, string> $values value => what it means
     *
     * @return array<string, mixed>
     */
    private static function enumProperty(string $description, array $values, string $default): array
    {
        $metadata = [];
        foreach ($values as $value => $meaning) {
            $metadata[] = ['value' => $value, 'description' => $meaning];
        }

        return [
            'type' => 'string',
            'description' => $description,
            'enum' => array_keys($values),
            'enumDescriptions' => array_values($values),
            'x-intellij-enum-metadata' => $metadata,
            'default' => $default,
        ];
    }
}
