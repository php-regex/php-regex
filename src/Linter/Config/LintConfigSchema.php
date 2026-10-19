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
     * Every lint rule id, with what it reports and whether it runs when
     * regex.json does not mention it.
     */
    public const LINT_RULES = [
        'unicode.shorthandWithoutU' => ['Warn when \w, \d, \s are used without /u flag (ASCII-only matching).', false],
        'unicode.propertyWithoutU' => ['Error when \p{...} Unicode properties are used without /u flag.', true],
        'unicode.bracedHexWithoutU' => ['Error when \x{...} escapes with code points > 0xFF are used without /u flag.', true],
        'unicode.multibyteInClassWithoutU' => ['Error when a multibyte character sits in a character class without /u flag (the class matches each byte).', true],
        'unicode.quantifiedMultibyteWithoutU' => ['Error when a quantifier follows a multibyte character without /u flag (only its last byte repeats).', true],
        'flag.useless.i' => ['Warn when /i flag is used but pattern has no case-sensitive characters.', true],
        'flag.useless.m' => ['Warn when /m flag is used but pattern has no anchors.', true],
        'flag.useless.s' => ['Warn when /s flag is used but pattern has no unescaped dot outside a character class.', true],
        'flag.useless.D' => ['Warn when /D flag is used but no $ anchor is read without m.', true],
        'flag.useless.x' => ['Warn when /x flag is used but pattern has no whitespace and no # comment.', true],
        'flag.redundant' => ['Warn about redundant flag modifiers.', true],
        'flag.override' => ['Warn when inline flags override outer flags.', true],
        'charclass.redundant' => ['Warn about redundant character classes like [a].', true],
        'charclass.suspiciousRange' => ['Warn about suspicious character ranges like [A-z].', true],
        'charclass.suspiciousPipe' => ['Warn about literal pipe in character class (likely meant alternation).', true],
        'charclass.duplicateChars' => ['Warn about duplicate elements in character classes.', true],
        'charclass.backrefAsOctal' => ['Warn when \1-\9 in character class is octal, not a backreference.', true],
        'charclass.literalMetachar' => ['Warn about unescaped metacharacters in character classes.', true],
        'alternation.empty' => ['Warn about empty alternatives in alternations.', true],
        'alternation.duplicateDisjunction' => ['Warn about duplicate branches in alternations.', true],
        'alternation.overlap' => ['Warn about overlapping alternatives that may cause backtracking.', true],
        'alternation.dotNewline' => ['Warn about (.|\n) anti-pattern for matching any character.', true],
        'quantifier.useless' => ['Warn about useless quantifiers like {1}.', true],
        'quantifier.zero' => ['Warn about quantifiers that always match zero times.', true],
        'quantifier.nested' => ['Warn about nested quantifiers that may cause catastrophic backtracking.', true],
        'quantifier.concatenation' => ['Warn about concatenated quantifiers that can be optimized.', true],
        'quantifier.assertion' => ['Warn about a quantifier on a lookaround, which lets the match skip it or changes nothing.', true],
        'quantifier.lazyEnd' => ['Warn about a lazy quantifier nothing follows, which always matches its minimum.', true],
        'group.redundant' => ['Warn about redundant non-capturing groups.', true],
        'group.quantifiedCapture' => ['Warn about quantified capturing groups where only last match is retained.', true],
        'anchor.impossible.start' => ['Warn about impossible start anchor positions.', true],
        'anchor.impossible.end' => ['Warn about impossible end anchor positions.', true],
        'backref.undefined' => ['Warn about references to undefined groups.', true],
        'backref.useless' => ['Warn about useless backreferences.', true],
        'dotstar.nested' => ['Warn about nested dot-star patterns that cause severe backtracking.', true],
        'escape.suspicious' => ['Warn about suspicious escape sequences.', true],
        'range.useless' => ['Warn about useless character ranges.', true],
        'overlap.charset' => ['Warn about overlapping character sets in alternations.', true],
        'quantifier.emptyRepeat' => ['Warn about an unbounded quantifier on an item that can match the empty string.', true],
        'anchor.alternationPrecedence' => ['Warn when an anchor on the first or last alternative leaves another alternative unanchored, as in ^a|b.', true],
        'quantifier.possessiveImpossible' => ['Warn about a possessive repeat that takes every character the atom after it could read.', true],
        'anchor.impossible.boundary' => ['Warn about a word boundary its two neighbouring characters contradict.', true],
        'lookaround.impossible' => ['Warn about a lookahead the pattern after it contradicts.', true],
        'group.alwaysEmptyCapture' => ['Warn about a capturing group that always captures an empty string.', true],
        'group.empty' => ['Warn about an empty non-capturing or atomic group.', true],
        'charclass.single' => ['Report a character class holding a single character (style).', false],
        'literal.multipleSpaces' => ['Report a run of literal spaces, clearer as a counted repeat (style).', false],
        'quantifier.lazyToClass' => ['Report a lazy dot before a single closing character, faster as a negated class (perf).', false],
        'quantifier.uselessLazy' => ['Report a lazy quantifier that matches what the greedy one matches (style).', false],
        'lookaround.edgeQuantifier' => ['Report a repeat at the edge of a lookaround past its minimum, which is never checked (perf).', false],
        'anchor.dollarNewline' => ['Report a final $ in a pattern anchored at its start, which also matches before a trailing newline (info).', false],
        'literal.invisible' => ['Warn about a control character, or a character that hides or moves text, written raw in a pattern.', true],
        'lookaround.redundant' => ['Report a lookaround at the edge of a lookaround read the same way, which can hold its body (style).', false],
    ];

    /**
     * The issues of an analysis the rules map turns off like a lint rule,
     * though no lint rule reports them: what each reports, and whether it
     * is on when regex.json does not mention it.
     */
    private const ANALYSIS_RULES = [
        'redos.search' => ['Report the quadratic cost of an unanchored search whose every attempt is proven linear. It runs under the ReDoS check and is medium: a threshold of medium or low shows it.', true],
        'compat.meaningChanges' => ['Warn when a later PHP of the project range parses the pattern into another meaning, as "a{,3}" (text before PCRE2 10.43, a quantifier from it).', true],
    ];

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
     * The ids of the lint rules, which regex.json may name beside the
     * analysis issues it turns off the same way.
     *
     * @return list<string>
     */
    public static function lintRuleIds(): array
    {
        return array_keys(self::LINT_RULES);
    }

    /**
     * Whether checks.lint.rules may name the id: a lint rule, or an
     * analysis issue turned off the same way.
     */
    public static function isRuleId(string $id): bool
    {
        return isset(self::LINT_RULES[$id]) || isset(self::ANALYSIS_RULES[$id]);
    }

    /**
     * Whether a rules map, as checks.lint.rules writes it, leaves on an
     * analysis issue it turns off like a lint rule: unless it says false
     * for it, under its full identifier or without "regex.lint.".
     *
     * @param array<string, bool> $rules
     */
    public static function leavesOn(array $rules, string $issueId): bool
    {
        $short = str_starts_with($issueId, 'regex.lint.') ? substr($issueId, \strlen('regex.lint.')) : $issueId;

        return false !== ($rules[$issueId] ?? null) && false !== ($rules[$short] ?? null);
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
        foreach ([...self::LINT_RULES, ...self::ANALYSIS_RULES] as $id => [$description, $default]) {
            $rules[$id] = [
                'type' => 'boolean',
                'description' => $description,
                'default' => $default,
            ];
        }

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
                    'description' => 'Enable or disable specific lint rules. All rules are enabled by default except anchor.dollarNewline, unicode.shorthandWithoutU, charclass.single, literal.multipleSpaces, quantifier.lazyToClass, quantifier.uselessLazy, lookaround.edgeQuantifier and lookaround.redundant.',
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
