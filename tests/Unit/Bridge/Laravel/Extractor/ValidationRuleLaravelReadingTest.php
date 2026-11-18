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

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\ConditionalRules;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationRuleParser;
use Illuminate\Validation\Validator;
use PHPRegex\Laravel\Extractor\ValidationRulePatternSource;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Tests\Fixtures\Laravel\ValidationRules\UpdateUserLanePipedRequest;
use PHPRegex\Tests\Fixtures\Laravel\ValidationRules\UpdateUserLaneRequest;
use PHPRegex\Tests\Support\EngineProbe;
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
     * list is not, by "[] =" or by array_push(), whatever its position.
     */
    #[Test]
    public function test_a_rules_string_is_split_and_a_pushed_element_is_not(): void
    {
        $pushed = ['required'];
        array_push($pushed, 'nullable', 'regex:/x|y/');
        $this->assertSame(['/x|y/'], self::laravelPatterns(['d' => $pushed]));
        $this->assertSame([true, false], array_map(EngineProbe::compiles(...), ['/x|y/', '/x']));

        $project = $this->makeProject(['app/Rules.php' => <<<'PHP'
            <?php
            $rules['a'] = 'regex:/x|y/';
            $rules['b'][] = 'regex:/x|y/';
            $rules['c'] = ['nullable', 'regex:/x|y/'];
            array_push($rules['d'], 'nullable', 'regex:/x|y/');
            PHP]);

        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], []));

        $this->assertSame([[2, '/x'], [3, '/x|y/'], [4, '/x|y/'], [5, '/x|y/']], array_map(static fn (PatternOccurrence $occurrence): array => [$occurrence->line, $occurrence->pattern], $occurrences));
    }

    /**
     * An element pushed onto a list is one rule even when a rule comes
     * before the regex one: Laravel reads "nullable|regex:/x|y/" as a rule
     * named "nullable|regex", and runs no pattern. A literal assigned to a
     * key, or left where nothing says it is one element, stays a rules
     * string.
     */
    #[Test]
    public function test_a_pushed_element_with_a_rule_before_the_regex_one_runs_no_pattern(): void
    {
        $pushed = ['required'];
        array_push($pushed, 'nullable|regex:/x|y/');
        $unshifted = ['required'];
        array_unshift($unshifted, 'nullable|regex:/x|y/');
        $this->assertSame([], self::laravelPatterns(['d' => $pushed, 'e' => $unshifted]));
        $this->assertSame(['/x'], self::laravelPatterns(['a' => 'nullable|regex:/x|y/']));
        $this->assertSame([false, true], array_map(EngineProbe::compiles(...), ['/x', '/x|y/']));

        $project = $this->makeProject(['app/Rules.php' => <<<'PHP'
            <?php
            $rules['a'] = 'nullable|regex:/x|y/';
            $rules['b'][] = 'nullable|regex:/x|y/';
            array_push($rules['d'], 'nullable|regex:/x|y/');
            array_unshift($rules['e'], ('nullable|regex:/x|y/'));
            return 'nullable|regex:/x|y/';
            PHP]);

        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], []));

        $this->assertSame([[2, '/x'], [6, '/x']], array_map(static fn (PatternOccurrence $occurrence): array => [$occurrence->line, $occurrence->pattern], $occurrences));
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
     * \x, octal and \$ escapes before Laravel sees the rule, the blanks and
     * the letters of its name included, a single-quoted one \\ and \' alone.
     * The file's own rules are the oracle, and the engine says whether the
     * pattern Laravel runs compiles.
     */
    #[Test]
    #[DataProvider('provideLiterals')]
    public function test_a_literal_is_read_as_php_reads_it(string $literal, bool $compiles): void
    {
        $project = $this->makeProject(['app/Rules.php' => "<?php\nreturn ['slug' => ".$literal."];\n"]);
        $rules = require $project.'/app/Rules.php';
        $this->assertIsArray($rules);
        $expected = self::laravelPatterns(['slug' => $rules['slug'] ?? null]);
        $this->assertCount(1, $expected);
        $this->assertSame($compiles, EngineProbe::compiles($expected[0]), $expected[0]);

        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], []));

        $this->assertSame($expected, array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $occurrences));
    }

    /**
     * @return iterable<string, array{literal: string, compiles: bool}>
     */
    public static function provideLiterals(): iterable
    {
        yield 'a code point escape' => ['literal' => '"required|regex:/^[\u{0600}-\u{06FF} ]+$/u"', 'compiles' => true];
        yield 'a hexadecimal escape' => ['literal' => '"regex:/^\x41+$/"', 'compiles' => true];
        yield 'an escaped dollar' => ['literal' => '"required|regex:/^[0-9]+\$/"', 'compiles' => true];
        yield 'an octal escape' => ['literal' => '"regex:/(k)\1/"', 'compiles' => true];
        yield 'a doubled backslash' => ['literal' => '"regex:/(x)\\\\1/"', 'compiles' => true];
        yield 'an escaped double quote' => ['literal' => '"regex:/^\"$/"', 'compiles' => true];
        // PCRE2 refuses "\u" without an option PHP never sets.
        yield 'a code point escape in single quotes' => ['literal' => "'regex:/^\\u{e9}$/u'", 'compiles' => false];
        yield 'an escaped single quote' => ['literal' => "'nullable|regex:/^j\\'k$/'", 'compiles' => true];
        // Laravel trims the name a tab starts, then runs the first field of
        // the parameter read as CSV, which has no closing delimiter.
        yield 'an escaped tab before the name' => ['literal' => '"required|\tregex:/^a{1,2}$/"', 'compiles' => false];
        yield 'an escaped line feed after the name' => ['literal' => '"regex\n:/^b$/"', 'compiles' => true];
        yield 'an escaped letter in the name' => ['literal' => '"nullable|\x72egex:/^c$/"', 'compiles' => true];
        yield 'an escaped bar between two rules' => ['literal' => '"required\x7cregex:/^c$/"', 'compiles' => true];
        yield 'a name of hexadecimal escapes' => ['literal' => '"\x72\x65\x67\x65\x78:/^o$/"', 'compiles' => true];
        yield 'a name of octal escapes' => ['literal' => '"required|\162\145\147\145\170:/^p$/"', 'compiles' => true];
        yield 'a name of code point escapes' => ['literal' => '"\u{72}\u{65}\u{67}\u{65}\u{78}:/^q$/"', 'compiles' => true];
        yield 'an escaped colon after the name' => ['literal' => '"regex\x3a/^d$/"', 'compiles' => true];
        yield 'an octal colon in a rules string' => ['literal' => '"required|regex\072/^(e|f)$/"', 'compiles' => false];
        yield 'a code point colon after a negation' => ['literal' => '"not_regex\u{3a}/^g$/"', 'compiles' => true];
        yield 'a binary literal given to a key' => ['literal' => "b'regex:/^(h|i)$/'", 'compiles' => false];
        // PHP keeps a line feed written in the literal: Laravel reads it as
        // a blank before the rule's name.
        yield 'a literal over two lines' => ['literal' => "'required|\nregex:/^(j|k)$/'", 'compiles' => false];
    }

    /**
     * A double-quoted literal whose escapes spell no regex rule once PHP
     * decodes it holds no pattern: Laravel reads "regex=/a/" as a rule named
     * "regex=/a/", and a line feed as no rule at all. A single-quoted one
     * keeps its escapes: 'regex\x3a/a/' is a rule of that name.
     */
    #[Test]
    public function test_a_literal_whose_escapes_spell_no_rule_is_not_read(): void
    {
        $this->assertSame([], self::laravelPatterns(['a' => "regex\x3d/a/", 'b' => "required|regex\x3d/a/", 'e' => 'regex\x3a/a/']));

        $project = $this->makeProject(['app/Rules.php' => <<<'PHP'
            <?php
            return ['a' => "regex\x3d/a/", 'b' => "required|regex\x3d/a/", 'c' => "required\n", 'd' => ["min:2\x7cmax:3"], 'e' => 'regex\x3a/a/'];
            PHP]);

        $this->assertSame([], (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], [])));
    }

    /**
     * A double quote inside a single-quoted literal, with a backslash after
     * it, opens no literal: the rules written after it on the line are read.
     */
    #[Test]
    public function test_a_double_quote_inside_a_single_quoted_literal_hides_no_rule_after_it(): void
    {
        $project = $this->makeProject(['app/Rules.php' => <<<'PHP'
            <?php
            $path = 'C:\dir "x\y'; $rules = ['c' => "regex:/^d$/"];
            $label = 'say "hi\n'; $more = ['e' => "required|regex:/^f$/"];
            return $rules + $more;
            PHP]);
        $rules = require $project.'/app/Rules.php';
        $this->assertIsArray($rules);
        $this->assertSame(['/^d$/', '/^f$/'], self::laravelPatterns($rules));

        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], []));

        $this->assertSame([[2, '/^d$/'], [3, '/^f$/']], array_map(static fn (PatternOccurrence $occurrence): array => [$occurrence->line, $occurrence->pattern], $occurrences));
    }

    /**
     * A rule whose colon an escape writes is read once PHP decodes its
     * literal, whatever quote another literal of the line holds: a double
     * quote inside a single-quoted one, or a single quote inside a
     * double-quoted one.
     */
    #[Test]
    public function test_a_rule_whose_colon_is_escaped_is_read_whatever_quote_another_literal_holds(): void
    {
        $project = $this->makeProject(['app/Rules.php' => <<<'PHP'
            <?php
            $path = 'C:\dir "x\y'; $rules = ['c' => "regex\x3a/^d$/"];
            $label = 'say "hi\n'; $more = ['e' => "required|regex\072/^(f|g)$/"];
            $quote = "it's"; $last = ['h' => "regex\u{3a}/^i$/", 'j' => 'regex:/^k$/'];
            return $rules + $more + $last;
            PHP]);
        $rules = require $project.'/app/Rules.php';
        $this->assertIsArray($rules);
        $expected = self::laravelPatterns($rules);
        $this->assertSame(['/^d$/', '/^(f', '/^i$/', '/^k$/'], $expected);
        $this->assertSame([true, false, true, true], array_map(EngineProbe::compiles(...), $expected));

        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], []));

        $this->assertSame([[2, '/^d$/'], [3, '/^(f'], [4, '/^i$/'], [4, '/^k$/']], array_map(static fn (PatternOccurrence $occurrence): array => [$occurrence->line, $occurrence->pattern], $occurrences));
    }

    /**
     * A quote in a comment, in a heredoc or a nowdoc, or in the text around
     * the PHP tags opens no literal: Laravel never runs the rule it seems to
     * hold.
     */
    #[Test]
    public function test_text_outside_the_php_code_holds_no_rule(): void
    {
        $project = $this->makeProject(['app/Rules.php' => <<<'PHP'
            <p>'regex:/^(a|b)$/'</p>
            <?php
            // 'regex:/^(c|d)$/'
            /** "regex:/^(e|f)$/" */
            $text = <<<TEXT
                'regex:/^(g|h)$/'
                TEXT;
            $quoted = <<<'TEXT'
                "regex:/^(i|j)$/"
                TEXT;
            $rules = ['k' => 'regex:/^k$/'];
            ?>
            "regex:/^(l|m)$/"
            <?php return $rules;
            PHP]);
        ob_start();

        try {
            $rules = require $project.'/app/Rules.php';
        } finally {
            ob_end_clean();
        }
        $this->assertIsArray($rules);
        $this->assertSame(['/^k$/'], self::laravelPatterns($rules));

        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], []));

        $this->assertSame([[11, '/^k$/']], array_map(static fn (PatternOccurrence $occurrence): array => [$occurrence->line, $occurrence->pattern], $occurrences));
    }

    /**
     * A double-quoted rules string that interpolates is split on the "|"
     * its text writes: a rule between two of them, or the start or the end
     * of the string, is read once PHP decodes it, its colon escaped or not.
     * A rule an expression is part of runs a pattern only the running code
     * knows, and is not read: "/^{$prefix}[a-z]+$/" is no pattern Laravel
     * runs.
     */
    #[Test]
    public function test_a_rule_an_interpolation_builds_is_not_read_and_one_beside_it_is(): void
    {
        $project = $this->makeProject(['app/Rules.php' => <<<'PHP'
            <?php
            $min = 2; $max = 9; $prefix = 'ab'; $flags = 'i'; $rule = 'nullable';
            return [
                'a' => "min:$min|regex:/^a$/",
                'b' => "regex:/^(b|c)$/|max:{$max}",
                'c' => "{$rule}|regex\x3a/^(d|e)$/",
                'd' => "required|regex:/^{$prefix}[a-z]+$/",
                'e' => "regex:/^$prefix-\d+$/$flags",
                'f' => ["regex:/^f$/{$flags}"],
                'g' => "{$rule}regex:/^g$/",
            ];
            PHP]);
        $rules = require $project.'/app/Rules.php';
        $this->assertIsArray($rules);
        $this->assertSame(['/^a$/', '/^(b', '/^(d', '/^ab[a-z]+$/', '/^ab-\d+$/i', '/^f$/i'], self::laravelPatterns($rules));

        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], []));

        $this->assertSame([[4, '/^a$/'], [5, '/^(b'], [6, '/^(d']], array_map(static fn (PatternOccurrence $occurrence): array => [$occurrence->line, $occurrence->pattern], $occurrences));
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
            ...self::laravelPatterns(self::sometimes('code', 'regex:/^(a|b)$/')),
            ...self::laravelPatterns(['x' => ValidationRuleParser::filterConditionalRules(['x' => Rule::when(true, 'regex:/^(c|d)$/')])['x'] ?? null]),
            ...self::laravelPatterns(['y' => ['required', 'regex:/^(e|f)$/']]),
            ...self::laravelPatterns(['z' => ['regex:/^(g|h)$/']]),
        ];
        $this->assertSame(['/^(a', '/^(c', '/^(e|f)$/', '/^(g|h)$/'], $expected);
        // A split one loses its closing delimiter: the engine refuses it.
        $this->assertSame([false, false, true, true], array_map(EngineProbe::compiles(...), $expected));

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
     * A string is a rules string where the call takes rules: the second
     * argument of Validator::sometimes(), the second and third of
     * Rule::when(), Rule::unless() and the ConditionalRules they build, by
     * position or by name, and through parentheses that only group it. A
     * string another call takes is one rule, as array_unshift() adds it.
     *
     * @param \Closure(): array<mixed> $rules the rules as Laravel holds them
     */
    #[Test]
    #[DataProvider('provideCallArguments')]
    public function test_a_string_is_split_where_the_call_takes_rules(string $code, \Closure $rules, string $expected, bool $compiles): void
    {
        $this->assertSame([$expected], self::laravelPatterns($rules()));
        $this->assertSame($compiles, EngineProbe::compiles($expected), $expected);

        $project = $this->makeProject(['app/Rules.php' => "<?php\nuse Illuminate\\Validation\\ConditionalRules;\nuse Illuminate\\Validation\\Rule;\n".$code."\n"]);
        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], []));

        $this->assertSame([$expected], array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $occurrences));
    }

    /**
     * @return iterable<string, array{code: string, rules: \Closure(): array<mixed>, expected: string, compiles: bool}>
     */
    public static function provideCallArguments(): iterable
    {
        yield 'a rules string in parentheses' => [
            'code' => "\$validator->sometimes('code', ('regex:/^(a|b)$/'), fn () => true);",
            'rules' => static fn (): array => self::sometimes('code', 'regex:/^(a|b)$/'),
            'expected' => '/^(a',
            'compiles' => false,
        ];
        yield 'a rules string given by name' => [
            'code' => "\$validator->sometimes('code', callback: fn () => true, rules: 'regex:/^(a|b)$/');",
            'rules' => static fn (): array => self::sometimes('code', 'regex:/^(a|b)$/'),
            'expected' => '/^(a',
            'compiles' => false,
        ];
        yield 'the default rules of Rule::when()' => [
            'code' => "return ['code' => Rule::when(\$condition, 'required', 'regex:/^(a|b)$/')];",
            'rules' => static fn (): array => ValidationRuleParser::filterConditionalRules(['code' => Rule::when(false, 'required', 'regex:/^(a|b)$/')]),
            'expected' => '/^(a',
            'compiles' => false,
        ];
        yield 'the rules of Rule::unless() given by name' => [
            'code' => "return ['code' => Rule::unless(\$condition, rules: 'regex:/^(a|b)$/')];",
            'rules' => static fn (): array => ValidationRuleParser::filterConditionalRules(['code' => Rule::unless(false, rules: 'regex:/^(a|b)$/')]),
            'expected' => '/^(a',
            'compiles' => false,
        ];
        yield 'the rules of a ConditionalRules' => [
            'code' => "return ['code' => new ConditionalRules(\$condition, 'regex:/^(a|b)$/')];",
            'rules' => static fn (): array => ValidationRuleParser::filterConditionalRules(['code' => new ConditionalRules(true, 'regex:/^(a|b)$/')]),
            'expected' => '/^(a',
            'compiles' => false,
        ];
        yield 'a rules string in parentheses given to a key' => [
            'code' => "return ['code' => ('regex:/^(a|b)$/')];",
            'rules' => static fn (): array => ['code' => 'regex:/^(a|b)$/'],
            'expected' => '/^(a',
            'compiles' => false,
        ];
        yield 'an element another call adds' => [
            'code' => "array_unshift(\$rules['code'], 'regex:/^(a|b)$/');",
            'rules' => static function (): array {
                $rules = ['code' => ['required']];
                array_unshift($rules['code'], 'regex:/^(a|b)$/');

                return $rules;
            },
            'expected' => '/^(a|b)$/',
            'compiles' => true,
        ];
    }

    /**
     * The rules Validator::sometimes() adds for the attribute, its callback
     * passing.
     *
     * @return array<mixed>
     */
    private static function sometimes(string $attribute, string $rules): array
    {
        $validator = new Validator(new Translator(new ArrayLoader(), 'en'), [], []);
        $validator->sometimes($attribute, $rules, static fn (): bool => true);

        return $validator->getRules();
    }

    /**
     * The regex parameters Laravel passes to preg_match() for these rules.
     *
     * @param array<mixed> $rules
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
