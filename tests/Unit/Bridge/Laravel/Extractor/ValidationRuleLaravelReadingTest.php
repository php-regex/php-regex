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
                if (\in_array($name, ['Regex', 'NotRegex'], true)) {
                    self::assertIsString($parameters[0]);
                    $patterns[] = $parameters[0];
                }
            }
        }

        return $patterns;
    }
}
