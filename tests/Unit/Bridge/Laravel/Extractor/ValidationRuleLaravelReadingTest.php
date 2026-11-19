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

use Illuminate\Contracts\Translation\Translator as TranslatorContract;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\ConditionalRules;
use Illuminate\Validation\Factory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\AnyOf;
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
use PHPUnit\Framework\Attributes\RequiresMethod;
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
     * Literals joined with "." are the one string PHP builds, read where
     * the concatenation starts: "'regex:/^(a' . 'b)$/'" runs "/^(ab)$/", not
     * "/^(a". An operand that is no literal is as an expression of an
     * interpolation: the rule it is part of is not read, and a rule between
     * two "|" of the literals beside it is.
     */
    #[Test]
    public function test_a_concatenation_is_read_as_the_string_php_builds(): void
    {
        $project = $this->makeProject(['app/Rules.php' => <<<'PHP'
            <?php
            $max = 9; $prefix = 'ab'; $base = 'nullable'; $rule = 'nullable|';
            return [
                'a' => 'regex:/^(a' . 'b)$/',
                'b' => ['required', 'regex:/^(c|' . 'd)$/'],
                'c' => 'required|'
                    // The rule Laravel splits.
                    . 'regex:/^(e|f)$/',
                'd' => 'required|max:' . $max . '|regex:/^(g|h)$/',
                'e' => 'regex:/^' . $prefix . '[a-z]+$/',
                'f' => $base . '|regex:/^(i|j)$/',
                'g' => ['required', $rule . 'regex:/^k$/'],
                'h' => "regex:/^l" . '$/',
                'i' => 'required|max:' . strlen($prefix) . '|regex:/^(m|n)$/',
                'j' => trim($base) . '|regex:/^(o|p)$/',
                'k' => $base . $max . '|regex:/^(q|r)$/',
            ];
            PHP]);
        $rules = require $project.'/app/Rules.php';
        $this->assertIsArray($rules);
        $expected = self::laravelPatterns($rules);
        $this->assertSame(['/^(ab)$/', '/^(c|d)$/', '/^(e', '/^(g', '/^ab[a-z]+$/', '/^(i', '/^l$/', '/^(m', '/^(o', '/^(q'], $expected);
        $this->assertSame([true, true, false, false, true, false, true, false, false, false], array_map(EngineProbe::compiles(...), $expected));

        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], []));

        $this->assertSame([[4, '/^(ab)$/'], [5, '/^(c|d)$/'], [8, '/^(e'], [9, '/^(g'], [11, '/^(i'], [13, '/^l$/'], [14, '/^(m'], [15, '/^(o'], [16, '/^(q']], array_map(static fn (PatternOccurrence $occurrence): array => [$occurrence->line, $occurrence->pattern], $occurrences));
    }

    /**
     * A rule of a concatenation is placed on the line of the literal it
     * starts in, where a marker on that line finds it: the literals before
     * it change nothing.
     */
    #[Test]
    public function test_a_rule_of_a_concatenation_is_placed_on_the_line_of_its_literal(): void
    {
        $project = $this->makeProject(['app/Rules.php' => <<<'PHP'
            <?php
            return [
                'a' => 'required|'
                    . 'regex:/[/',
                'b' => 'regex:/^(a'
                    . '|b)$/',
                'c' => ['required', ('regex:/^c' . '$/')],
            ];
            PHP]);
        $rules = require $project.'/app/Rules.php';
        $this->assertIsArray($rules);
        $expected = self::laravelPatterns($rules);
        $this->assertSame(['/[/', '/^(a', '/^c$/'], $expected);
        $this->assertSame([false, false, true], array_map(EngineProbe::compiles(...), $expected));

        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], []));

        $this->assertSame([[4, '/[/'], [5, '/^(a'], [7, '/^c$/']], array_map(static fn (PatternOccurrence $occurrence): array => [$occurrence->line, $occurrence->pattern], $occurrences));
    }

    /**
     * A heredoc is read as PHP decodes it, a nowdoc as written, where the
     * code says it holds rules: given to a key, assigned, or taken by a call
     * as rules, alone or in a concatenation. Anywhere else its text is
     * not read.
     */
    #[Test]
    public function test_a_heredoc_given_as_rules_is_read_as_php_decodes_it(): void
    {
        $project = $this->makeProject(['app/Rules.php' => <<<'PHP'
            <?php
            $length = strlen(<<<'TEXT'
                regex:/^(x|y)$/
                TEXT);
            return [
                'a' => <<<'RULES'
                    required|regex:/^(a|b)$/
                    RULES,
                'b' => <<<RULES
                    regex\x3a/^c$/
                    RULES,
                'c' => 'required|' . <<<'RULE'
                    regex:/^d$/
                    RULE,
            ];
            PHP]);
        $rules = require $project.'/app/Rules.php';
        $this->assertIsArray($rules);
        $this->assertSame(['/^(a', '/^c$/', '/^d$/'], self::laravelPatterns($rules));

        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], []));

        $this->assertSame([[6, '/^(a'], [9, '/^c$/'], [12, '/^d$/']], array_map(static fn (PatternOccurrence $occurrence): array => [$occurrence->line, $occurrence->pattern], $occurrences));
    }

    /**
     * A comment between a key or an assignment and the rules changes
     * nothing Laravel reads: the rules are given to the key, assigned or
     * pushed all the same.
     */
    #[Test]
    public function test_a_comment_before_the_rules_changes_nothing(): void
    {
        $project = $this->makeProject(['app/Rules.php' => <<<'PHP'
            <?php
            $rules = [
                'a' => /* partner rule */ <<<'RULES'
                    required|regex:/^(a|b)$/
                    RULES,
                'b' => // the lane code
                    'regex:/^(c|d)$/',
            ];
            $rules['e'] = /* split */ 'regex:/^(e|f)$/';
            $rules['g'][ ] /* one element */ = 'regex:/^(g|h)$/';
            return $rules;
            PHP]);
        $rules = require $project.'/app/Rules.php';
        $this->assertIsArray($rules);
        $expected = self::laravelPatterns($rules);
        $this->assertSame(['/^(a', '/^(c', '/^(e', '/^(g|h)$/'], $expected);
        $this->assertSame([false, false, false, true], array_map(EngineProbe::compiles(...), $expected));

        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], []));

        $this->assertSame([[3, '/^(a'], [7, '/^(c'], [9, '/^(e'], [10, '/^(g|h)$/']], array_map(static fn (PatternOccurrence $occurrence): array => [$occurrence->line, $occurrence->pattern], $occurrences));
    }

    /**
     * An operand of a concatenation in parentheses that only group it is
     * the string they hold: "('regex:/^a') . '$/'" runs "/^a$/", and no
     * operand of it is read on its own.
     */
    #[Test]
    public function test_an_operand_in_parentheses_is_part_of_the_concatenation(): void
    {
        $project = $this->makeProject(['app/Rules.php' => <<<'PHP'
            <?php
            function lane($validator): void
            {
                $validator->sometimes('e', ('required|') . 'regex:/^(e|f)$/', fn () => true);
            }
            return [
                'a' => [('regex:/^a') . '$/'],
                'b' => ('required|') . ('regex:/^(b|c)$/'),
                'c' => (('regex:/^d') . ('$/')),
            ];
            PHP]);
        $rules = require $project.'/app/Rules.php';
        $this->assertIsArray($rules);
        $expected = [...self::laravelPatterns(self::sometimes('e', ('required|').'regex:/^(e|f)$/')), ...self::laravelPatterns($rules)];
        $this->assertSame(['/^(e', '/^a$/', '/^(b', '/^d$/'], $expected);
        $this->assertSame([false, true, false, true], array_map(EngineProbe::compiles(...), $expected));

        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], []));

        $this->assertSame([[4, '/^(e'], [7, '/^a$/'], [8, '/^(b'], [9, '/^d$/']], array_map(static fn (PatternOccurrence $occurrence): array => [$occurrence->line, $occurrence->pattern], $occurrences));
    }

    /**
     * A rule whose name and colon two literals write is read, in a file
     * that writes neither elsewhere.
     */
    #[Test]
    public function test_a_rule_whose_name_and_colon_are_two_literals_is_read(): void
    {
        $project = $this->makeProject(['app/Rules.php' => <<<'PHP'
            <?php
            return [
                'a' => 'regex' . ':/[/',
                'b' => "required|not_regex" . ':/^(b|c)$/',
                'c' => ['regex ' . ' :/^d$/'],
            ];
            PHP]);
        $rules = require $project.'/app/Rules.php';
        $this->assertIsArray($rules);
        $expected = self::laravelPatterns($rules);
        $this->assertSame(['/[/', '/^(b', '/^d$/'], $expected);
        $this->assertSame([false, false, true], array_map(EngineProbe::compiles(...), $expected));

        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], []));

        $this->assertSame([[3, '/[/'], [4, '/^(b'], [5, '/^d$/']], array_map(static fn (PatternOccurrence $occurrence): array => [$occurrence->line, $occurrence->pattern], $occurrences));
    }

    /**
     * Laravel reads the rule the literals of a concatenation join, whatever
     * stands between them or cuts the name in two. The rule alone in its
     * file is read all the same.
     */
    #[Test]
    #[DataProvider('provideRuleNamesTheLiteralsCut')]
    public function test_a_rule_whose_name_the_literals_cut_is_read_alone_in_its_file(string $rule): void
    {
        $project = $this->makeProject(['app/Rules.php' => "<?php\nreturn ['a' => ".$rule."];\n"]);
        $rules = require $project.'/app/Rules.php';
        $this->assertIsArray($rules);
        $this->assertSame(['/[/'], self::laravelPatterns($rules));

        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], []));

        $this->assertSame(['/[/'], array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $occurrences));
    }

    /**
     * @return iterable<string, array{rule: string}>
     */
    public static function provideRuleNamesTheLiteralsCut(): iterable
    {
        yield 'a comment before the dot' => ['rule' => "'regex' /* partner rule */ . ':/[/'"];
        yield 'a line comment before the dot' => ['rule' => "'regex' // partner rule\n    . ':/[/'"];
        yield 'the name in brackets' => ['rule' => "('regex') . ':/[/'"];
        yield 'the name in a nowdoc' => ['rule' => "<<<'NAME'\n    regex\n    NAME . ':/[/'"];
        yield 'the name in a heredoc' => ['rule' => "<<<NAME\n    regex\n    NAME . ':/[/'"];
        yield 'the name cut in two' => ['rule' => "'re' . 'gex:/[/'"];
        yield 'the name cut in three, the colon apart' => ['rule' => "'reg' . \"e\" . 'x' . ':/[/'"];
        yield 'the name cut in capitals, an empty comment and brackets between' => ['rule' => "'RE' /**/ . ('GEX:/[/')"];
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
        $this->assertReadAsLaravelRunsIt($code, $rules, $expected, $compiles);
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
     * An element of the array Rule::anyOf() or an AnyOf rule takes is a
     * rules string: the rule validates each alone through
     * Validator::make(). An element of an array inside it is one rule. The
     * code is read on every Laravel; the pattern Laravel runs is checked
     * where Laravel has the call (12.8 and later), by the test below.
     *
     * @param \Closure(): array<mixed> $rules the rules as Laravel holds them
     */
    #[Test]
    #[DataProvider('provideRuleSetArguments')]
    public function test_a_string_element_of_the_rule_sets_any_of_takes_is_split(string $code, \Closure $rules, string $expected, bool $compiles): void
    {
        $this->assertReadInTheCode($code, $expected, $compiles);
    }

    /**
     * The pattern each row of provideRuleSetArguments() expects is the one
     * Laravel runs for its rules.
     *
     * @param \Closure(): array<mixed> $rules the rules as Laravel holds them
     */
    #[Test]
    #[DataProvider('provideRuleSetArguments')]
    #[RequiresMethod(Rule::class, 'anyOf')]
    public function test_laravel_runs_the_pattern_read_in_the_rule_sets_any_of_takes(string $code, \Closure $rules, string $expected, bool $compiles): void
    {
        $this->assertSame([$expected], self::laravelPatterns($rules()));
        $this->assertSame($compiles, EngineProbe::compiles($expected), $expected);
    }

    /**
     * Reading what Rule::anyOf() validates leaves the Validator facade as it
     * found it: a factory it held before is its factory after. The test
     * leaves it as it found it too: what an earlier test left in it is
     * there after.
     */
    #[Test]
    #[RequiresMethod(Rule::class, 'anyOf')]
    public function test_the_any_of_oracle_leaves_the_validator_facade_as_it_found_it(): void
    {
        $application = Facade::getFacadeApplication();
        Facade::setFacadeApplication(null);
        // What the facade holds itself, none without an application.
        $cached = ValidatorFacade::getFacadeRoot();
        $factory = new Factory(new Translator(new ArrayLoader(), 'en'));
        ValidatorFacade::swap($factory);

        try {
            $this->assertSame([['regex:/^(a', 'b)$/']], self::anyOfRules(Rule::anyOf(['regex:/^(a|b)$/'])));
            $this->assertSame($factory, ValidatorFacade::getFacadeRoot());
        } finally {
            if (null === $cached) {
                ValidatorFacade::clearResolvedInstance('validator');
            } else {
                ValidatorFacade::swap($cached);
            }
            $left = ValidatorFacade::getFacadeRoot();
            Facade::setFacadeApplication($application);
        }

        $this->assertSame($cached, $left);
    }

    /**
     * @return iterable<string, array{code: string, rules: \Closure(): array<mixed>, expected: string, compiles: bool}>
     */
    public static function provideRuleSetArguments(): iterable
    {
        yield 'an element of the array Rule::anyOf() takes' => [
            'code' => "return ['code' => Rule::anyOf(['regex:/^(a|b)$/'])];",
            'rules' => static fn (): array => self::anyOfRules(Rule::anyOf(['regex:/^(a|b)$/'])),
            'expected' => '/^(a',
            'compiles' => false,
        ];
        yield 'an element after another of the array Rule::anyOf() takes' => [
            'code' => "return ['code' => Rule::anyOf(['required', 'regex:/^(a|b)$/'])];",
            'rules' => static fn (): array => self::anyOfRules(Rule::anyOf(['required', 'regex:/^(a|b)$/'])),
            'expected' => '/^(a',
            'compiles' => false,
        ];
        yield 'an element of the rules of Rule::anyOf() given by name' => [
            'code' => "return ['code' => Rule::anyOf(rules: ['regex:/^(a|b)$/'])];",
            'rules' => static fn (): array => self::anyOfRules(Rule::anyOf(rules: ['regex:/^(a|b)$/'])),
            'expected' => '/^(a',
            'compiles' => false,
        ];
        yield 'an element of the array Rule::anyOf() takes in parentheses' => [
            'code' => "return ['code' => Rule::anyOf((['regex:/^(a|b)$/']))];",
            'rules' => static fn (): array => self::anyOfRules(Rule::anyOf(['regex:/^(a|b)$/'])),
            'expected' => '/^(a',
            'compiles' => false,
        ];
        yield 'an element of the array() Rule::anyOf() takes' => [
            'code' => "return ['code' => \\Illuminate\\Validation\\Rule::anyOf(array('regex:/^(a|b)$/'))];",
            'rules' => static fn (): array => self::anyOfRules(Rule::anyOf(['regex:/^(a|b)$/'])),
            'expected' => '/^(a',
            'compiles' => false,
        ];
        yield 'an element of the array an AnyOf rule takes' => [
            'code' => "return ['code' => new AnyOf(['regex:/^(a|b)$/'])];",
            'rules' => static fn (): array => self::anyOfRules(new AnyOf(['regex:/^(a|b)$/'])),
            'expected' => '/^(a',
            'compiles' => false,
        ];
        yield 'an element of the array an AnyOf rule imported under another name takes' => [
            'code' => "use Illuminate\\Validation\\Rules\\AnyOf as Either;\nreturn ['code' => new Either(['regex:/^(a|b)$/'])];",
            'rules' => static fn (): array => self::anyOfRules(new AnyOf(['regex:/^(a|b)$/'])),
            'expected' => '/^(a',
            'compiles' => false,
        ];
        yield 'an element of the array another class named AnyOf takes' => [
            'code' => "return ['code' => new \\App\\Rules\\AnyOf(['regex:/^(a|b)$/'])];",
            'rules' => static fn (): array => ['code' => ['regex:/^(a|b)$/']],
            'expected' => '/^(a|b)$/',
            'compiles' => true,
        ];
        yield 'an element of a rules array Rule::anyOf() takes' => [
            'code' => "return ['code' => Rule::anyOf([['required', 'regex:/^(a|b)$/']])];",
            'rules' => static fn (): array => self::anyOfRules(Rule::anyOf([['required', 'regex:/^(a|b)$/']])),
            'expected' => '/^(a|b)$/',
            'compiles' => true,
        ];
        yield 'a rules string of keyed rules Rule::anyOf() takes' => [
            'code' => "return ['code' => Rule::anyOf([['code' => 'regex:/^(a|b)$/']])];",
            'rules' => static fn (): array => self::anyOfRules(Rule::anyOf([['code' => 'regex:/^(a|b)$/']])),
            'expected' => '/^(a',
            'compiles' => false,
        ];
        yield 'an element of an array a call inside Rule::anyOf() takes' => [
            'code' => "return ['code' => Rule::anyOf([Rule::when(\$condition, ['regex:/^(a|b)$/'])])];",
            'rules' => static fn (): array => self::anyOfRules(Rule::anyOf([Rule::when(true, ['regex:/^(a|b)$/'])])),
            'expected' => '/^(a|b)$/',
            'compiles' => true,
        ];
    }

    /**
     * Livewire's #[Validate] and #[Rule] (Livewire 3.0 to 4.x) hand their
     * rule to Laravel as the property's rules: BaseValidate::boot() adds
     * [$property => $rule], or a keyed array as it is, and
     * HandlesValidation::getRules() merges what every attribute adds with
     * array_merge_recursive() before Validator::make(). A string is then a
     * rules string, split on "|", a list holds one rule per element, and
     * two attributes of one property make one list: each string is one
     * rule. The rules are given first or named "rule"; "as" and "message"
     * hold none.
     *
     * @param \Closure(): array<mixed> $rules the rules as Livewire hands them to Laravel
     */
    #[Test]
    #[DataProvider('provideLivewireAttributes')]
    public function test_a_livewire_attribute_is_read_as_livewire_hands_its_rules_to_laravel(string $code, \Closure $rules, string $expected, bool $compiles): void
    {
        $this->assertSame([$expected], self::laravelPatterns($rules()));
        $this->assertSame($compiles, EngineProbe::compiles($expected), $expected);

        $project = $this->makeProject(['app/Livewire/Lane.php' => "<?php\nnamespace App\\Livewire;\nuse Livewire\\Attributes\\Rule;\nuse Livewire\\Attributes\\Validate;\nuse Livewire\\Component;\nfinal class Lane extends Component\n{\n".$code."\n}\n"]);
        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], []));

        $this->assertSame([$expected], array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $occurrences));
    }

    /**
     * @return iterable<string, array{code: string, rules: \Closure(): array<mixed>, expected: string, compiles: bool}>
     */
    public static function provideLivewireAttributes(): iterable
    {
        yield 'a string' => [
            'code' => "#[Validate('regex:/^(a|b)$/')]\npublic \$code;",
            'rules' => static fn (): array => array_merge_recursive(['code' => 'regex:/^(a|b)$/']),
            'expected' => '/^(a',
            'compiles' => false,
        ];
        yield 'a string given by name, beside a name and a message' => [
            'code' => "#[Validate(as: 'lane code', rule: 'regex:/^(a|b)$/', message: 'Pick a lane.')]\npublic \$code;",
            'rules' => static fn (): array => array_merge_recursive(['code' => 'regex:/^(a|b)$/']),
            'expected' => '/^(a',
            'compiles' => false,
        ];
        yield 'a string of the Rule attribute' => [
            'code' => "#[Rule('required|regex:/^(a|b)$/')]\npublic \$code;",
            'rules' => static fn (): array => array_merge_recursive(['code' => 'required|regex:/^(a|b)$/']),
            'expected' => '/^(a',
            'compiles' => false,
        ];
        yield 'a string of the attribute named in full' => [
            'code' => "#[\\Livewire\\Attributes\\Validate('regex:/^(a|b)$/')]\npublic \$code;",
            'rules' => static fn (): array => array_merge_recursive(['code' => 'regex:/^(a|b)$/']),
            'expected' => '/^(a',
            'compiles' => false,
        ];
        yield 'a list' => [
            'code' => "#[Validate(['required', 'regex:/^(a|b)$/'])]\npublic \$code;",
            'rules' => static fn (): array => array_merge_recursive(['code' => ['required', 'regex:/^(a|b)$/']]),
            'expected' => '/^(a|b)$/',
            'compiles' => true,
        ];
        yield 'keyed rules' => [
            'code' => "#[Validate(['code' => 'regex:/^(a|b)$/', 'code.*' => 'required'])]\npublic \$code;",
            'rules' => static fn (): array => array_merge_recursive(['code' => 'regex:/^(a|b)$/', 'code.*' => 'required']),
            'expected' => '/^(a',
            'compiles' => false,
        ];
        yield 'two attributes of one property' => [
            'code' => "#[Validate('required')]\n#[Validate('regex:/^(a|b)$/')]\npublic \$code;",
            'rules' => static fn (): array => array_merge_recursive(['code' => 'required'], ['code' => 'regex:/^(a|b)$/']),
            'expected' => '/^(a|b)$/',
            'compiles' => true,
        ];
        yield 'two attributes of one property in one group' => [
            'code' => "#[Validate('required'), Rule('regex:/^(a|b)$/')]\npublic \$code;",
            'rules' => static fn (): array => array_merge_recursive(['code' => 'required'], ['code' => 'regex:/^(a|b)$/']),
            'expected' => '/^(a|b)$/',
            'compiles' => true,
        ];
        yield 'one attribute of each of two properties' => [
            'code' => "#[Validate('required')]\npublic \$name;\n#[Validate('regex:/^(a|b)$/')]\npublic \$code;",
            'rules' => static fn (): array => array_merge_recursive(['name' => 'required'], ['code' => 'regex:/^(a|b)$/']),
            'expected' => '/^(a',
            'compiles' => false,
        ];
        yield 'a string beside keyed rules for other keys' => [
            'code' => "#[Validate(['other' => 'required'])]\n#[Validate('regex:/^(a|b)$/')]\npublic \$code;",
            'rules' => static fn (): array => array_merge_recursive(['other' => 'required'], ['code' => 'regex:/^(a|b)$/']),
            'expected' => '/^(a',
            'compiles' => false,
        ];
        yield 'a string beside keyed rules for other keys written with array()' => [
            'code' => "#[Validate(array('other' => 'required'))]\n#[Validate('regex:/^(a|b)$/')]\npublic \$code;",
            'rules' => static fn (): array => array_merge_recursive(['other' => 'required'], ['code' => 'regex:/^(a|b)$/']),
            'expected' => '/^(a',
            'compiles' => false,
        ];
        yield 'a string beside an attribute that gives no rule' => [
            'code' => "#[Validate(as: 'lane code')]\n#[Validate('regex:/^(a|b)$/')]\npublic \$code;",
            'rules' => static fn (): array => array_merge_recursive([], ['code' => 'regex:/^(a|b)$/']),
            'expected' => '/^(a',
            'compiles' => false,
        ];
        yield 'a string of a typed property beside keyed rules for its elements' => [
            'code' => "#[Validate('regex:/^(a|b)$/')]\n#[Validate(rule: ['code.*' => 'required'])]\npublic ?string \$code = null;",
            'rules' => static fn (): array => array_merge_recursive(['code' => 'regex:/^(a|b)$/'], ['code.*' => 'required']),
            'expected' => '/^(a',
            'compiles' => false,
        ];
        yield 'a string for a key another component of the file gives too' => [
            'code' => "#[Validate('required')]\npublic \$code;\n}\nfinal class Dock extends Component\n{\n#[Validate('regex:/^(a|b)$/')]\npublic \$code;",
            'rules' => static fn (): array => array_merge_recursive(['code' => 'regex:/^(a|b)$/']),
            'expected' => '/^(a',
            'compiles' => false,
        ];
    }

    /**
     * The strings Livewire attributes give one key of a component reach
     * Laravel as the elements of the list array_merge_recursive() makes of
     * them, whatever property the attributes mark and whether the key is
     * written: each is one rule, which Laravel does not split.
     * "nullable|regex:/^c$/" is then one rule named "nullable|regex", and
     * runs no pattern.
     *
     * @param \Closure(): array<mixed> $rules    the rules as Livewire hands them to Laravel
     * @param list<string>             $expected
     */
    #[Test]
    #[DataProvider('provideMergedLivewireAttributes')]
    public function test_the_rules_livewire_attributes_give_one_key_are_one_element_each(string $code, \Closure $rules, array $expected): void
    {
        $this->assertSame($expected, self::laravelPatterns($rules()));
        $this->assertSame(array_fill(0, \count($expected), true), array_map(EngineProbe::compiles(...), $expected));

        $project = $this->makeProject(['app/Livewire/Lane.php' => "<?php\nnamespace App\\Livewire;\nuse Livewire\\Attributes\\Rule;\nuse Livewire\\Attributes\\Validate;\nuse Livewire\\Component;\nfinal class Lane extends Component\n{\n".$code."\n}\n"]);
        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], []));

        $this->assertSame($expected, array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $occurrences));
    }

    /**
     * @return iterable<string, array{code: string, rules: \Closure(): array<mixed>, expected: list<string>}>
     */
    public static function provideMergedLivewireAttributes(): iterable
    {
        yield 'two regex rules' => [
            'code' => "#[Validate('regex:/^a$/')]\n#[Validate('not_regex:/^b$/')]\npublic \$code;",
            'rules' => static fn (): array => array_merge_recursive(['code' => 'regex:/^a$/'], ['code' => 'not_regex:/^b$/']),
            'expected' => ['/^a$/', '/^b$/'],
        ];
        yield 'a rule before the regex one' => [
            'code' => "#[Validate('required')]\n#[Validate('nullable|regex:/^c$/')]\npublic \$code;",
            'rules' => static fn (): array => array_merge_recursive(['code' => 'required'], ['code' => 'nullable|regex:/^c$/']),
            'expected' => [],
        ];
        yield 'a pattern that holds a bar' => [
            'code' => "#[Validate(rule: 'regex:/^(d|e)$/'), Rule('required')]\npublic \$code;",
            'rules' => static fn (): array => array_merge_recursive(['code' => 'regex:/^(d|e)$/'], ['code' => 'required']),
            'expected' => ['/^(d|e)$/'],
        ];
        yield 'a string beside keyed rules for the property' => [
            'code' => "#[Validate(['code' => 'required'])]\n#[Validate('regex:/^(f|g)$/')]\npublic \$code;",
            'rules' => static fn (): array => array_merge_recursive(['code' => 'required'], ['code' => 'regex:/^(f|g)$/']),
            'expected' => ['/^(f|g)$/'],
        ];
        yield 'keyed rules for the property beside a string' => [
            'code' => "#[Validate(['code' => 'regex:/^(h|i)$/'])]\n#[Validate('required')]\npublic \$code;",
            'rules' => static fn (): array => array_merge_recursive(['code' => 'regex:/^(h|i)$/'], ['code' => 'required']),
            'expected' => ['/^(h|i)$/'],
        ];
        yield 'keyed rules for a key another property gives too' => [
            'code' => "#[Validate(['other' => 'regex:/^(j|k)$/'])]\npublic \$code;\n#[Validate('required')]\npublic \$other;",
            'rules' => static fn (): array => array_merge_recursive(['other' => 'regex:/^(j|k)$/'], ['other' => 'required']),
            'expected' => ['/^(j|k)$/'],
        ];
        yield 'a string for a property the keyed rules of another give too' => [
            'code' => "#[Validate('regex:/^(l|m)$/')]\npublic \$code;\n#[Validate(['code' => 'required'])]\npublic \$other;",
            'rules' => static fn (): array => array_merge_recursive(['code' => 'regex:/^(l|m)$/'], ['code' => 'required']),
            'expected' => ['/^(l|m)$/'],
        ];
        yield 'a string beside an empty list' => [
            'code' => "#[Validate([])]\n#[Validate('regex:/^(n|o)$/')]\npublic \$code;",
            'rules' => static fn (): array => array_merge_recursive(['code' => []], ['code' => 'regex:/^(n|o)$/']),
            'expected' => ['/^(n|o)$/'],
        ];
    }

    /**
     * The extractor reads in the code the one pattern Laravel runs for these
     * rules, which compiles or not as the engine says.
     *
     * @param \Closure(): array<mixed> $rules the rules as Laravel holds them
     */
    private function assertReadAsLaravelRunsIt(string $code, \Closure $rules, string $expected, bool $compiles): void
    {
        $this->assertSame([$expected], self::laravelPatterns($rules()));
        $this->assertReadInTheCode($code, $expected, $compiles);
    }

    /**
     * The extractor reads in the code this one pattern, which compiles or
     * not as the engine says.
     */
    private function assertReadInTheCode(string $code, string $expected, bool $compiles): void
    {
        $this->assertSame($compiles, EngineProbe::compiles($expected), $expected);

        $project = $this->makeProject(['app/Rules.php' => "<?php\nuse Illuminate\\Validation\\ConditionalRules;\nuse Illuminate\\Validation\\Rule;\nuse Illuminate\\Validation\\Rules\\AnyOf;\n".$code."\n"]);
        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project.'/app'], []));

        $this->assertSame([$expected], array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $occurrences));
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
     * The rules of each validator the AnyOf rule makes through the
     * Validator facade, one for each element of its array, as each
     * validator holds them. None runs: the factory hands the rule a
     * validator that fails, so the rule makes the next one.
     *
     * @return list<mixed>
     */
    private static function anyOfRules(AnyOf $rule): array
    {
        $translator = new Translator(new ArrayLoader(), 'en');
        $factory = new Factory($translator);
        $validated = [];
        $factory->resolver(static function (TranslatorContract $translator, array $data, array $rules, array $messages, array $attributes) use (&$validated): Validator {
            array_push($validated, ...array_values((new Validator($translator, $data, $rules, $messages, $attributes))->getRules()));

            return new Validator($translator, [], ['value' => 'required']);
        });

        // The facade answers what it holds itself, none without an
        // application: that is what it holds again after.
        $application = Facade::getFacadeApplication();
        Facade::setFacadeApplication(null);
        $previous = ValidatorFacade::getFacadeRoot();
        ValidatorFacade::swap($factory);

        try {
            self::assertFalse($rule->setValidator(new Validator($translator, [], []))->passes('code', 'a'));
        } finally {
            if (null === $previous) {
                ValidatorFacade::clearResolvedInstance('validator');
            } else {
                ValidatorFacade::swap($previous);
            }
            Facade::setFacadeApplication($application);
        }

        return $validated;
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
