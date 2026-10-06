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

namespace PHPRegex\Tests\Functional\Cli;

use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The lint command judges a project over the PHP range its composer.json
 * allows: every pattern is validated at the floor and at each PHP version a
 * rule changes at inside the range. A pattern valid on PHP 8.2 and refused
 * from 8.5 on, "\K" in a lookbehind, is an error under ">=8.2", named with
 * the versions that refuse it; under --php-version 8.2 it is not.
 *
 * Lint rules and ReDoS still run once, at the floor. A pattern invalid at
 * the floor is reported once, there.
 */
final class LintCommandRangeTest extends TestCase
{
    use RunsRegexCli;
    use TemporaryProject;

    private const COMPOSER = '{"name": "acme/app", "require": {"php": ">=8.2"}}';

    /**
     * Compiles on PHP 8.4.26 (preg_match() returns 1); PHP 8.5 compiles
     * without PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK, and PCRE2 then refuses it
     * (pcre2test 10.49: error 199).
     */
    private const KEEP_IN_LOOKBEHIND = "<?php\n\npreg_match('/(?<=a\\Kb)c/', \$s);\n";

    private const FULL_RANGE = [
        ['php' => '8.2', 'pcre' => '10.40'],
        ['php' => '8.3', 'pcre' => '10.42'],
        ['php' => '8.4', 'pcre' => '10.44'],
        ['php' => '8.4.25', 'pcre' => '10.44'],
        ['php' => '8.5', 'pcre' => '10.44'],
        ['php' => '8.5.10', 'pcre' => '10.44'],
    ];

    #[Test]
    public function test_lint_reports_a_pattern_a_later_php_of_the_range_refuses(): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::KEEP_IN_LOOKBEHIND]);

        [$exitCode, $stdout] = $this->runRegex(['lint', 'src', '--format=json', '--jobs=1', '--no-redos']);

        $this->assertSame(1, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('lint', $document);

        $issues = self::issues($document);
        $this->assertCount(1, $issues, $stdout);
        $issue = $issues[0];
        $this->assertSame('error', $issue['severity'] ?? null);
        $this->assertSame('regex.keep.in_lookaround', $issue['issue_id'] ?? null);
        $message = $issue['message'] ?? null;
        $this->assertIsString($message);
        $this->assertStringContainsString('PHP 8.5 and later', (string) $message);
        // The lowest target that refuses it.
        $this->assertSame(['php' => '8.5', 'pcre' => '10.44'], $issue['target'] ?? null);
    }

    /**
     * "~8.3.0 || >=8.5.3" allows PHP 8.5.3 to 8.5.9, which no gate point
     * stands for: the branch's lowest version is judged and named.
     */
    #[Test]
    public function test_lint_names_the_lowest_version_of_an_or_branch(): void
    {
        $this->enterProject([
            'composer.json' => '{"name": "acme/app", "require": {"php": "~8.3.0 || >=8.5.3"}}',
            'src/a.php' => self::KEEP_IN_LOOKBEHIND,
        ]);

        [$exitCode, $stdout] = $this->runRegex(['lint', 'src', '--format=json', '--jobs=1', '--no-redos']);

        $this->assertSame(1, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('lint', $document);
        $this->assertSame([
            ['php' => '8.3', 'pcre' => '10.42'],
            ['php' => '8.5.3', 'pcre' => '10.44'],
            ['php' => '8.5.10', 'pcre' => '10.44'],
        ], JsonContract::asArray($document['target'] ?? null)['range'] ?? null);

        $issues = self::issues($document);
        $this->assertCount(1, $issues, $stdout);
        $this->assertSame('regex.keep.in_lookaround', $issues[0]['issue_id'] ?? null);
        $message = $issues[0]['message'] ?? null;
        $this->assertIsString($message);
        $this->assertStringContainsString('PHP 8.5.3 and later', (string) $message);
        $this->assertSame(['php' => '8.5.3', 'pcre' => '10.44'], $issues[0]['target'] ?? null);
    }

    #[Test]
    public function test_lint_with_one_php_version_judges_that_version_only(): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::KEEP_IN_LOOKBEHIND]);

        [$exitCode, $stdout] = $this->runRegex(['lint', 'src', '--format=json', '--jobs=1', '--no-redos', '--php-version=8.2']);

        $this->assertSame(0, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        $this->assertSame([], $document['results'] ?? null);
        $this->assertSame([['php' => '8.2', 'pcre' => '10.40']], JsonContract::asArray($document['target'] ?? null)['range'] ?? null);
    }

    #[Test]
    public function test_lint_json_target_lists_the_range_judged_floor_first(): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER, 'src/a.php' => "<?php\n\npreg_match('/a/', \$s);\n"]);

        [$exitCode, $stdout] = $this->runRegex(['lint', 'src', '--format=json', '--jobs=1', '--no-redos']);

        $this->assertSame(0, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('lint', $document);
        $this->assertSame(
            ['php' => '8.2', 'pcre' => '10.40', 'source' => 'composer.json require.php', 'range' => self::FULL_RANGE],
            $document['target'] ?? null,
        );
    }

    #[Test]
    public function test_lint_issue_target_is_present_on_every_issue_and_null_at_the_floor(): void
    {
        $this->enterProject([
            'composer.json' => self::COMPOSER,
            'src/a.php' => implode("\n", [
                '<?php',
                '',
                "preg_match('/(a/', \$s);",
                "preg_match('/[aa]b/', \$s);",
                // Invalid at the floor: reported there, once, and not again
                // for the \K a later PHP refuses.
                "preg_match('/(?<=a\\Kb)c(/', \$s);",
                "preg_match('/(?<=a\\Kb)c/', \$s);",
                '',
            ]),
        ]);

        [$exitCode, $stdout] = $this->runRegex(['lint', 'src', '--format=json', '--jobs=1', '--no-redos']);

        $this->assertSame(1, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('lint', $document);

        $byPattern = [];
        foreach (JsonContract::asArray($document['results'] ?? null) as $result) {
            $result = JsonContract::asArray($result);
            foreach (JsonContract::asArray($result['issues'] ?? null) as $issue) {
                $issue = JsonContract::asArray($issue);
                $this->assertArrayHasKey('target', $issue);
                $pattern = $result['pattern'] ?? null;
                $this->assertIsString($pattern);
                $byPattern[$pattern][] = $issue;
            }
        }

        // Each issue was checked to hold the key above: here its value.
        $this->assertArrayHasKey('/(a/', $byPattern, $stdout);
        $this->assertNull($byPattern['/(a/'][0]['target']);
        $this->assertArrayHasKey('/[aa]b/', $byPattern, $stdout);
        $this->assertNull($byPattern['/[aa]b/'][0]['target']);
        $this->assertArrayHasKey('/(?<=a\Kb)c(/', $byPattern, $stdout);
        $this->assertCount(1, $byPattern['/(?<=a\Kb)c(/'], $stdout);
        $this->assertNull($byPattern['/(?<=a\Kb)c(/'][0]['target']);
        $this->assertSame('regex.group.unclosed', $byPattern['/(?<=a\Kb)c(/'][0]['issue_id'] ?? null);
        $this->assertSame(['php' => '8.5', 'pcre' => '10.44'], $byPattern['/(?<=a\Kb)c/'][0]['target'] ?? null);
    }

    #[Test]
    public function test_lint_judges_every_point_with_the_pinned_pcre2(): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::KEEP_IN_LOOKBEHIND]);

        [$exitCode, $stdout] = $this->runRegex(['lint', 'src', '--format=json', '--jobs=1', '--no-redos', '--pcre-version=10.43']);

        $this->assertSame(1, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        $range = JsonContract::asArray(JsonContract::asArray($document['target'] ?? null)['range'] ?? null);
        $pcres = array_values(array_map(static fn (mixed $entry): mixed => JsonContract::asArray($entry)['pcre'] ?? null, $range));
        $this->assertCount(\count(self::FULL_RANGE), $pcres);
        $this->assertSame(array_fill(0, \count($pcres), '10.43'), $pcres);
        $this->assertSame(['php' => '8.5', 'pcre' => '10.43'], self::issues($document)[0]['target'] ?? null);
    }

    #[Test]
    public function test_lint_console_names_the_php_versions_that_refuse_the_pattern(): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::KEEP_IN_LOOKBEHIND]);

        [$exitCode, $stdout] = $this->runRegex(['lint', 'src', '--format=console', '--jobs=1', '--no-redos']);

        $this->assertSame(1, $exitCode, $stdout);
        $this->assertStringContainsString('PHP 8.5 and later', $stdout);
    }

    /**
     * @param array<mixed> $document
     *
     * @return list<array<mixed>>
     */
    private static function issues(array $document): array
    {
        $issues = [];
        foreach (JsonContract::asArray($document['results'] ?? null) as $result) {
            foreach (JsonContract::asArray(JsonContract::asArray($result)['issues'] ?? null) as $issue) {
                $issues[] = JsonContract::asArray($issue);
            }
        }

        return $issues;
    }
}
