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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pattern the floor accepts is validated at each later PHP of the range
 * too: the first that refuses it gives one error, placed where the pattern
 * stands and where the validator points, naming every stretch of versions
 * that refuse it.
 */
final class AnalysisServiceRangeTest extends TestCase
{
    /**
     * PHP 8.5 compiles without PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK: PCRE2 then
     * refuses "\K" in a lookbehind (pcre2test 10.49: error 199), which PHP
     * 8.4.26 compiles (preg_match() returns 1).
     */
    private const KEEP_IN_LOOKBEHIND = '/(?<=a\Kb)c/';

    #[Test]
    public function test_a_later_php_refusing_the_pattern_gives_one_error_where_the_pattern_stands(): void
    {
        $analysis = $this->analysis('8.2', [['8.4', null], ['8.5', null]]);

        $issues = $this->rangeIssues($analysis, self::KEEP_IN_LOOKBEHIND);

        $this->assertCount(1, $issues);
        $issue = $issues[0];
        $refusal = RegexParser::create(['php_version' => '8.5'])->validate(self::KEEP_IN_LOOKBEHIND);
        $this->assertSame('error', $issue['type']);
        $this->assertSame('src/a.php', $issue['file']);
        $this->assertSame(3, $issue['line']);
        $this->assertSame(17, $issue['column']);
        $this->assertSame(42, $issue['fileOffset'] ?? null);
        $this->assertSame($refusal->offset, $issue['position'] ?? null);
        $this->assertSame(10, $issue['position'] ?? null);
        $this->assertSame('On PHP 8.5 and later: '.$refusal->error, $issue['message']);
        $this->assertSame('preg_match', $issue['source'] ?? null);
        $this->assertInstanceOf(ValidationResult::class, $issue['validation'] ?? null);
        $this->assertSame($refusal->error, $issue['validation']->error);
        $this->assertSame(['php' => '8.5', 'pcre' => '10.44'], $issue['target'] ?? null);
        // The tip a floor error with this message gets: none here.
        $this->assertArrayHasKey('tip', $issue);
        $this->assertNull($issue['tip']);
    }

    #[Test]
    public function test_a_stretch_of_refusing_versions_that_ends_is_named_with_its_end(): void
    {
        // A variable-length lookbehind needs PCRE2 10.43: a range given
        // 10.40 at 8.4 and 10.44 at 8.5 refuses it at 8.4 only.
        $analysis = $this->analysis('8.4', [['8.4', '10.40'], ['8.5', '10.44']]);

        $issues = $this->rangeIssues($analysis, '/(?<=a{1,2})x/');

        $this->assertCount(1, $issues);
        $message = $issues[0]['message'] ?? null;
        $this->assertIsString($message);
        $this->assertStringStartsWith('On PHP 8.4 and later, before 8.5: ', $message);
        $this->assertSame(['php' => '8.4', 'pcre' => '10.40'], $issues[0]['target'] ?? null);
    }

    #[Test]
    public function test_two_stretches_are_both_named(): void
    {
        $analysis = $this->analysis('8.4', [['8.4', '10.40'], ['8.4.10', '10.44'], ['8.5', '10.42']]);

        $issues = $this->rangeIssues($analysis, '/(?<=a{1,2})x/');

        $this->assertCount(1, $issues);
        $message = $issues[0]['message'] ?? null;
        $this->assertIsString($message);
        $this->assertStringStartsWith('On PHP 8.4 and later, before 8.4.10; PHP 8.5 and later: ', $message);
    }

    #[Test]
    public function test_a_pattern_every_php_of_the_range_accepts_gives_no_range_error(): void
    {
        $analysis = $this->analysis('8.2', [['8.4', null], ['8.5', null]]);

        $this->assertSame([], $this->rangeIssues($analysis, '/a/'));
    }

    /**
     * @param list<array{string, string|null}> $range PHP version, PCRE2 release (null: the bundled one)
     */
    private function analysis(string $floor, array $range): AnalysisService
    {
        $parsers = [];
        foreach ($range as [$php, $pcre]) {
            $parsers[] = RegexParser::create(['php_version' => $php] + (null === $pcre ? [] : ['pcre_version' => $pcre]));
        }

        return (new AnalysisService(RegexParser::create(['php_version' => $floor])))->withParser(RegexParser::create(['php_version' => $floor]), $parsers);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rangeIssues(AnalysisService $analysis, string $pattern): array
    {
        $occurrence = new PatternOccurrence($pattern, 'src/a.php', 3, 'preg_match', column: 17, fileOffset: 42);

        return array_values(array_filter(
            $analysis->lint([$occurrence]),
            static fn (array $issue): bool => null !== ($issue['target'] ?? null),
        ));
    }
}
