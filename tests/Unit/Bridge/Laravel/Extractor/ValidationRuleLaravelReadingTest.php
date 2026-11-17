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

namespace PHPRegex\Tests\Unit\Bridge\Laravel\Extractor;

use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationRuleParser;
use PHPRegex\Laravel\Extractor\ValidationRulePatternSource;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Tests\Fixtures\Laravel\ValidationRules\UpdateUserLanePipedRequest;
use PHPRegex\Tests\Fixtures\Laravel\ValidationRules\UpdateUserLaneRequest;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The extractor lints the pattern Laravel hands to preg_match(): Laravel's
 * own ValidationRuleParser is the oracle. A rules string is split on every
 * "|", one inside a pattern included, an array element is one rule, and the
 * parameter of a regex rule is all the text after its first ":", whatever
 * its delimiter.
 */
final class ValidationRuleLaravelReadingTest extends TestCase
{
    use TemporaryProject;

    private const FIXTURES = __DIR__.'/../../../../Fixtures/Laravel/ValidationRules/';

    /**
     * @param \Closure(): array<string, mixed> $rules
     * @param list<string>                     $expected
     */
    #[Test]
    #[DataProvider('provideRequests')]
    public function test_the_extractor_reads_the_patterns_laravel_runs(string $file, \Closure $rules, array $expected): void
    {
        $this->assertSame($expected, self::laravelPatterns($rules()));

        $project = $this->makeProject(['app/Http/Requests/'.$file => (string) file_get_contents(self::FIXTURES.$file)]);
        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], []));

        $this->assertSame($expected, array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $occurrences));
    }

    /**
     * @return iterable<string, array{file: string, rules: \Closure(): array<string, mixed>, expected: list<string>}>
     */
    public static function provideRequests(): iterable
    {
        yield 'array rules' => [
            'file' => 'UpdateUserLaneRequest.php',
            'rules' => static fn (): array => (new UpdateUserLaneRequest())->rules(),
            'expected' => ['/^#[0-9a-f]{6}$/', '/^(?:north|south)-\d+$/', '{^[a-z]+$}', '(^admin$)i'],
        ];

        // Laravel splits "required|regex:/^(?:north|south)-\d+$/" into
        // "required", "regex:/^(?:north" and "south)-\d+$/".
        yield 'rules strings' => [
            'file' => 'UpdateUserLanePipedRequest.php',
            'rules' => static fn (): array => (new UpdateUserLanePipedRequest())->rules(),
            'expected' => ['/^#[0-9a-f]{6}$/', '/^(?:north', '{^[a-z]+$}', '(^admin$)i', '/^[A-Z]{3}$/', '<^XXX$>'],
        ];
    }

    /**
     * A pattern between braces is linted as written: "{^a$}" matches "a",
     * "/{^a$}/" does not.
     */
    #[Test]
    #[DataProvider('provideBracketDelimiters')]
    public function test_a_pattern_between_brackets_or_any_other_delimiter_is_read_as_written(string $pattern): void
    {
        $this->assertSame(1, preg_match($pattern, 'a'));

        $project = $this->makeProject(['app/Rules.php' => "<?php\n\$rules = ['slug' => ['required', 'regex:".$pattern."']];\n"]);
        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], []));

        $this->assertSame([$pattern], array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $occurrences));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideBracketDelimiters(): iterable
    {
        yield 'braces' => ['pattern' => '{^a$}'];
        yield 'parentheses' => ['pattern' => '(^a$)'];
        yield 'brackets' => ['pattern' => '[^a$]'];
        yield 'angle brackets' => ['pattern' => '<^a$>'];
        yield 'plus signs' => ['pattern' => '+^a$+'];
        yield 'equals signs' => ['pattern' => '=^a$=i'];
    }

    /**
     * A pattern without delimiters is linted as written, as preg_match()
     * refuses it, where it was wrapped in "/…/" and passed.
     */
    #[Test]
    #[DataProvider('provideUndelimitedPatterns')]
    public function test_a_pattern_without_delimiters_is_read_as_written(string $pattern): void
    {
        $this->assertFalse(@preg_match($pattern, 'a'));

        $project = $this->makeProject(['app/Rules.php' => "<?php\n\$rules = ['slug' => 'required|regex:".$pattern."'];\n"]);
        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], []));

        $this->assertSame([$pattern], array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $occurrences));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideUndelimitedPatterns(): iterable
    {
        yield 'anchored letter' => ['pattern' => '^a$'];
        yield 'letter first' => ['pattern' => 'a+'];
    }

    /**
     * A rules string assigned to a key is split, an element pushed onto a
     * list is not.
     */
    #[Test]
    public function test_a_rules_string_is_split_and_a_pushed_element_is_not(): void
    {
        $project = $this->makeProject(['app/Rules.php' => <<<'PHP'
            <?php
            $rules['a'] = 'regex:/x|y/';
            $rules['b'][] = 'regex:/x|y/';
            $rules['c'] = ['nullable', 'regex:/x|y/'];
            PHP]);

        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], []));

        $this->assertSame([[2, '/x'], [3, '/x|y/'], [4, '/x|y/']], array_map(static fn (PatternOccurrence $occurrence): array => [$occurrence->line, $occurrence->pattern], $occurrences));
    }

    /**
     * Laravel reads the name of a regex rule without case, and "notregex"
     * as "not_regex": the parameter is all the text after the first ":",
     * commas included.
     *
     * @param list<string>|string $rules
     * @param list<string>        $patterns
     * @param list<string>        $sources
     */
    #[Test]
    #[DataProvider('provideRuleNames')]
    public function test_a_regex_rule_is_read_whatever_the_case_of_its_name(array|string $rules, array $patterns, array $sources): void
    {
        $this->assertSame($patterns, self::laravelPatterns(['slug' => $rules]));

        $literal = \is_string($rules) ? "'".$rules."'" : "['".implode("', '", $rules)."']";
        $project = $this->makeProject(['app/Rules.php' => "<?php\n\$rules = ['slug' => ".$literal."];\n"]);
        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], []));

        $this->assertSame($patterns, array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $occurrences));
        $this->assertSame($sources, array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->source, $occurrences));
    }

    /**
     * @return iterable<string, array{rules: list<string>|string, patterns: list<string>, sources: list<string>}>
     */
    public static function provideRuleNames(): iterable
    {
        yield 'an upper-case name' => ['rules' => ['required', 'REGEX:/^a,b$/'], 'patterns' => ['/^a,b$/'], 'sources' => ['validation:regex']];
        yield 'a capitalized name in a rules string' => ['rules' => 'required|Regex:/^a$/', 'patterns' => ['/^a$/'], 'sources' => ['validation:regex']];
        yield 'an upper-case negation' => ['rules' => ['NOT_REGEX:/^a,b$/'], 'patterns' => ['/^a,b$/'], 'sources' => ['validation:not_regex']];
        yield 'a negation without its underscore' => ['rules' => ['notregex:/^a,b$/'], 'patterns' => ['/^a,b$/'], 'sources' => ['validation:not_regex']];
        yield 'a negation without its underscore in a rules string' => ['rules' => 'nullable|NotRegex:/^a$/', 'patterns' => ['/^a$/'], 'sources' => ['validation:not_regex']];
        // parseStringRule() trims the name before Str::studly(), and
        // ruleIsRegex() reads it untrimmed: a name with blanks around it
        // runs the rule on the first field of str_getcsv(), its commas
        // splitting the parameter.
        yield 'a name after a blank in a rules string' => ['rules' => 'required | regex:/^b+$/', 'patterns' => ['/^b+$/'], 'sources' => ['validation:regex']];
        yield 'a blank between the name and its colon' => ['rules' => ['regex :/^h$/'], 'patterns' => ['/^h$/'], 'sources' => ['validation:regex']];
        yield 'a negation after a blank' => ['rules' => 'nullable | not_regex:/^n$/', 'patterns' => ['/^n$/'], 'sources' => ['validation:not_regex']];
        yield 'a name after a blank, its parameter cut at a comma' => ['rules' => 'required | regex:/^a{1,2}$/', 'patterns' => ['/^a{1'], 'sources' => ['validation:regex']];
    }

    /**
     * A literal is read as PHP reads it: a double-quoted one decodes \u{…},
     * \x, octal and \$ escapes before Laravel sees the rule, a single-quoted
     * one \\ and \' alone. The file's own rules are the oracle.
     */
    #[Test]
    #[DataProvider('provideLiterals')]
    public function test_a_literal_is_read_as_php_reads_it(string $literal): void
    {
        $project = $this->makeProject(['app/Rules.php' => "<?php\nreturn ['slug' => ".$literal."];\n"]);
        $rules = require $project.'/app/Rules.php';
        $this->assertIsArray($rules);
        $expected = self::laravelPatterns(['slug' => $rules['slug'] ?? null]);
        $this->assertCount(1, $expected);

        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], []));

        $this->assertSame($expected, array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $occurrences));
    }

    /**
     * @return iterable<string, array{literal: string}>
     */
    public static function provideLiterals(): iterable
    {
        yield 'a code point escape' => ['literal' => '"required|regex:/^[\u{0600}-\u{06FF} ]+$/u"'];
        yield 'a hexadecimal escape' => ['literal' => '"regex:/^\x41+$/"'];
        yield 'an escaped dollar' => ['literal' => '"required|regex:/^[0-9]+\$/"'];
        yield 'an octal escape' => ['literal' => '"regex:/(k)\1/"'];
        yield 'a doubled backslash' => ['literal' => '"regex:/(x)\\\\1/"'];
        yield 'an escaped double quote' => ['literal' => '"regex:/^\"$/"'];
        yield 'a code point escape in single quotes' => ['literal' => "'regex:/^\\u{e9}$/u'"];
        yield 'an escaped single quote' => ['literal' => "'nullable|regex:/^j\\'k$/'"];
    }

    /**
     * A rules string given to a call is a rules string: Validator::sometimes()
     * explodes [$key => $rules], and ConditionalRules::rules() explodes a
     * string on "|". An element of an array literal, an argument of the call
     * or not, is one rule.
     */
    #[Test]
    public function test_a_rules_string_given_to_a_call_is_split(): void
    {
        $expected = [
            ...self::laravelPatterns(['code' => 'regex:/^(a|b)$/']),
            ...self::laravelPatterns(['x' => ValidationRuleParser::filterConditionalRules(['x' => Rule::when(true, 'regex:/^(c|d)$/')])['x'] ?? null]),
            ...self::laravelPatterns(['y' => ['required', 'regex:/^(e|f)$/']]),
            ...self::laravelPatterns(['z' => ['regex:/^(g|h)$/']]),
        ];
        $this->assertSame(['/^(a', '/^(c', '/^(e|f)$/', '/^(g|h)$/'], $expected);

        $project = $this->makeProject(['app/Rules.php' => <<<'PHP'
            <?php
            use Illuminate\Validation\Rule;
            function rules($validator, $condition) {
                $validator->sometimes('code', 'regex:/^(a|b)$/', fn () => true);
                return ['x' => [Rule::when($condition, 'regex:/^(c|d)$/')], 'y' => ['required', 'regex:/^(e|f)$/'], 'z' => Rule::when($condition, ['regex:/^(g|h)$/'])];
            }
            PHP]);
        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], []));

        $this->assertSame($expected, array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $occurrences));
    }

    /**
     * The regex parameters Laravel passes to preg_match() for these rules.
     *
     * @param array<string, mixed> $rules
     *
     * @return list<string>
     */
    private static function laravelPatterns(array $rules): array
    {
        $patterns = [];
        $exploded = (new ValidationRuleParser([]))->explode($rules);
        self::assertIsArray($exploded->rules);
        foreach ($exploded->rules as $attributeRules) {
            self::assertIsArray($attributeRules);
            foreach ($attributeRules as $rule) {
                self::assertIsString($rule);
                [$name, $parameters] = ValidationRuleParser::parse($rule);
                self::assertIsArray($parameters);
                self::assertIsString($name);
                // The validator calls validate{$name}(): PHP finds a method
                // whatever the case of its name.
                if (\in_array(strtolower($name), ['regex', 'notregex'], true)) {
                    self::assertIsString($parameters[0]);
                    $patterns[] = $parameters[0];
                }
            }
        }

        return $patterns;
    }
}
