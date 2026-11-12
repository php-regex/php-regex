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

use PHPRegex\Cli\Command\LintOutputRenderer;
use PHPRegex\Cli\Output;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\PcreRule;
use PHPRegex\Tests\Support\RangeFixtureReadings;
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
    use PcreRule;
    use RangeFixtureReadings;
    use RunsRegexCli;
    use TemporaryProject;

    private const COMPOSER = '{"name": "acme/app", "require": {"php": ">=8.2"}}';

    /**
     * Compiles on PHP 8.4.26 (preg_match() returns 1); PHP 8.5 compiles
     * without PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK, and PCRE2 then refuses it
     * (pcre2test 10.49: error 199).
     */
    private const KEEP_IN_LOOKBEHIND = "<?php\n\npreg_match('/(?<=a\\Kb)c/', \$s);\n";

    /**
     * "a{,3}" is text before PCRE2 10.43 and a quantifier from it
     * (preg_match('/^a{,3}$/', 'aa') returns 1 on 10.49, 0 on the text
     * "a{,3}"): judged from 8.1, the first PHP of the range whose PCRE2
     * reads the quantifier is named, 8.4 unless this machine links a newer
     * PCRE2 to an older minor.
     */
    private const QUANTIFIER_WITHOUT_MIN = "<?php\n\npreg_match('/a{,3}/', \$s);\n";

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
        $this->assertSame(['php' => '8.5', 'pcre' => self::judgedPcre(80500), 'guard' => null, 'guard_pcre' => null], $issue['target'] ?? null);
    }

    /**
     * The lint reports and edits nothing: the hint says what PHP 8.4 and
     * earlier read, and that no spelling keeps it in general, or gives the
     * one spelling that does when the \K opens a lookahead.
     */
    #[Test]
    public function test_lint_hints_what_the_refused_keep_meant_and_how_to_respell_it_when_it_can(): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::KEEP_IN_LOOKBEHIND."preg_match('/(?=\\Kab)a/', \$s);\n"]);

        [$exitCode, $stdout] = $this->runRegex(['lint', 'src', '--format=json', '--jobs=1', '--no-redos']);

        $this->assertSame(1, $exitCode, $stdout);
        $hints = [];
        foreach (self::issues(JsonContract::decodeDocument($stdout)) as $issue) {
            $validation = $issue['validation'] ?? null;
            $this->assertIsArray($validation);
            $hints[] = $validation['hint'] ?? null;
        }

        $this->assertSame([
            'Up to PHP 8.4 a \\K in a lookaround resets the start of the match from inside it: "(?=\\Ka)" matches an empty string in "a", and "(?<=\\Ka)b" matches "ab" in "ab". No spelling keeps that in general: rewrite the pattern without it.',
            'This \\K opens the lookahead: write "\\K(?=" for "(?=\\K", which every PHP reads alike.',
        ], $hints);
        $this->assertStringContainsString("preg_match('/(?=\\Kab)a/', \$s);", (string) file_get_contents('src/a.php'));
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
            ['php' => '8.3', 'pcre' => self::judgedPcre(80300)],
            ['php' => '8.5.3', 'pcre' => self::judgedPcre(80503)],
            ['php' => '8.5.10', 'pcre' => self::judgedPcre(80510)],
        ], JsonContract::asArray($document['target'] ?? null)['range'] ?? null);

        $issues = self::issues($document);
        $this->assertCount(1, $issues, $stdout);
        $this->assertSame('regex.keep.in_lookaround', $issues[0]['issue_id'] ?? null);
        $message = $issues[0]['message'] ?? null;
        $this->assertIsString($message);
        $this->assertStringContainsString('PHP 8.5.3 and later', (string) $message);
        $this->assertSame(['php' => '8.5.3', 'pcre' => self::judgedPcre(80503), 'guard' => null, 'guard_pcre' => null], $issues[0]['target'] ?? null);
    }

    #[Test]
    public function test_lint_with_one_php_version_judges_that_version_only(): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::KEEP_IN_LOOKBEHIND]);

        [$exitCode, $stdout] = $this->runRegex(['lint', 'src', '--format=json', '--jobs=1', '--no-redos', '--php-version=8.2']);

        $this->assertSame(0, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        $this->assertSame([], $document['results'] ?? null);
        $this->assertSame([['php' => '8.2', 'pcre' => self::judgedPcre(80200)]], JsonContract::asArray($document['target'] ?? null)['range'] ?? null);
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
            ['php' => '8.2', 'pcre' => self::judgedPcre(80200), 'locale' => 'C', 'source' => 'composer.json require.php', 'range' => self::fullRange()],
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
        $this->assertSame(['php' => '8.5', 'pcre' => self::judgedPcre(80500), 'guard' => null, 'guard_pcre' => null], $byPattern['/(?<=a\Kb)c/'][0]['target'] ?? null);
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
        $this->assertCount(\count(self::fullRange()), $pcres);
        $this->assertSame(array_fill(0, \count($pcres), '10.43'), $pcres);
        $this->assertSame(['php' => '8.5', 'pcre' => '10.43', 'guard' => null, 'guard_pcre' => null], self::issues($document)[0]['target'] ?? null);
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
     * A constraint in regex.json is judged as require.php is: every point it
     * allows, the floor first, named as regex.json's.
     */
    #[Test]
    public function test_lint_judges_a_regex_json_constraint_as_a_range(): void
    {
        self::assertEngineReadsQuantifierWithoutMin();

        $this->enterProject(['regex.json' => '{"phpVersion": ">=8.1"}', 'src/a.php' => self::QUANTIFIER_WITHOUT_MIN]);

        [$exitCode, $stdout] = $this->runRegex(['lint', 'src', '--format=json', '--jobs=1', '--no-redos']);

        $this->assertSame(0, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('lint', $document);
        $points = [80100, 80200, 80300, 80400, 80425, 80500, 80510];
        $this->assertSame(
            ['php' => '8.1', 'pcre' => self::judgedPcre(80100), 'locale' => 'C', 'source' => 'regex.json', 'range' => self::rows($points)],
            $document['target'] ?? null,
        );
        $this->assertSame(self::meaningChanges($points), self::warnings($document), $stdout);
    }

    /**
     * {"min", "max"} stops at its max: "8.3" leaves 8.4 out, and its
     * reading of "a{,3}" with it.
     */
    #[Test]
    public function test_lint_judges_a_regex_json_object_up_to_its_max(): void
    {
        self::assertEngineReadsQuantifierWithoutMin();

        $this->enterProject(['regex.json' => '{"phpVersion": {"min": "8.1", "max": "8.3"}}', 'src/a.php' => self::QUANTIFIER_WITHOUT_MIN]);

        [$exitCode, $stdout] = $this->runRegex(['lint', 'src', '--format=json', '--jobs=1', '--no-redos']);

        $this->assertSame(0, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('lint', $document);
        $points = [80100, 80200, 80300];
        $this->assertSame(
            ['php' => '8.1', 'pcre' => self::judgedPcre(80100), 'locale' => 'C', 'source' => 'regex.json', 'range' => self::rows($points)],
            $document['target'] ?? null,
        );
        $this->assertSame(self::meaningChanges($points), self::warnings($document), $stdout);
    }

    /**
     * A single version stays exact: "8.1" judges 8.1 alone, whatever
     * require.php allows.
     */
    #[Test]
    public function test_lint_judges_a_single_regex_json_version_alone(): void
    {
        self::assertEngineReadsQuantifierWithoutMin();

        $this->enterProject([
            'composer.json' => '{"name": "acme/app", "require": {"php": ">=8.1"}}',
            'regex.json' => '{"phpVersion": "8.1"}',
            'src/a.php' => self::QUANTIFIER_WITHOUT_MIN,
        ]);

        [$exitCode, $stdout] = $this->runRegex(['lint', 'src', '--format=json', '--jobs=1', '--no-redos']);

        $this->assertSame(0, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        $this->assertSame([], $document['results'] ?? null, $stdout);
        $this->assertSame(self::rows([80100]), JsonContract::asArray($document['target'] ?? null)['range'] ?? null);
    }

    #[Test]
    public function test_lint_judges_a_php_version_constraint_over_regex_json(): void
    {
        self::assertEngineReadsQuantifierWithoutMin();

        $this->enterProject(['regex.json' => '{"phpVersion": "8.1"}', 'src/a.php' => self::QUANTIFIER_WITHOUT_MIN]);

        [$exitCode, $stdout] = $this->runRegex(['--php-version=>=8.1', 'lint', 'src', '--format=json', '--jobs=1', '--no-redos']);

        $this->assertSame(0, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('lint', $document);
        $points = [80100, 80200, 80300, 80400, 80425, 80500, 80510];
        $target = JsonContract::asArray($document['target'] ?? null);
        $this->assertSame('--php-version', $target['source'] ?? null);
        $this->assertSame(self::rows($points), $target['range'] ?? null);
        $this->assertSame(self::meaningChanges($points), self::warnings($document), $stdout);
    }

    /**
     * A pattern a later PHP of a named range refuses is the error a
     * require.php range gives it, in the same words.
     */
    #[Test]
    public function test_lint_reports_a_pattern_a_later_php_of_a_named_range_refuses(): void
    {
        self::assertEngineKeepInLookbehind();

        $this->enterProject(['regex.json' => '{"phpVersion": {"min": 80200, "max": 80699}}', 'src/a.php' => self::KEEP_IN_LOOKBEHIND]);

        [$exitCode, $stdout] = $this->runRegex(['lint', 'src', '--format=json', '--jobs=1', '--no-redos']);

        $this->assertSame(1, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('lint', $document);
        $issues = self::issues($document);
        $this->assertCount(1, $issues, $stdout);
        $this->assertSame('error', $issues[0]['severity'] ?? null);
        $this->assertSame('regex.keep.in_lookaround', $issues[0]['issue_id'] ?? null);
        $message = $issues[0]['message'] ?? null;
        $this->assertIsString($message);
        $this->assertStringStartsWith('On PHP 8.5 and later: ', (string) $message);
        $this->assertSame(['php' => '8.5', 'pcre' => self::judgedPcre(80500), 'guard' => null, 'guard_pcre' => null], $issues[0]['target'] ?? null);
    }

    /**
     * The banner's Target row names the versions judged after the floor,
     * not only the floor.
     */
    #[Test]
    public function test_lint_banner_target_row_names_the_points_judged(): void
    {
        $target = ProjectTarget::resolve(null, null, [], $this->makeProject(['composer.json' => '{"name": "acme/app", "require": {"php": ">=8.4"}}']), []);

        $banner = (new LintOutputRenderer())->renderBanner(new Output(false, false), $target);

        $this->assertSame(1, preg_match('/^Target\s*: (.*)$/m', $banner, $row), $banner);
        $this->assertStringStartsWith('PHP 8.4, PCRE2 '.self::judgedPcre(80400), $row[1]);
        $this->assertStringContainsString('composer.json require.php', $row[1]);
        foreach (['8.4.25', '8.5', '8.5.10'] as $point) {
            $this->assertStringContainsString($point, $row[1]);
        }
    }

    /**
     * Each version of the range as the JSON report names it.
     *
     * @param list<int> $points
     *
     * @return list<array{php: string, pcre: string}>
     */
    private static function rows(array $points): array
    {
        return array_map(static fn (int $point): array => ['php' => ProjectTarget::phpLabel($point), 'pcre' => self::judgedPcre($point)], $points);
    }

    /**
     * The warning "a{,3}" gets over the points, as [issue_id, target php]:
     * none when the floor already reads the quantifier or no point does,
     * else the first point whose PCRE2 is 10.43 or later.
     *
     * @param list<int> $points the floor first
     *
     * @return list<array{string, string}>
     */
    private static function meaningChanges(array $points): array
    {
        $readsQuantifier = static fn (int $point): bool => version_compare(self::judgedPcre($point), '10.43', '>=');
        if ($readsQuantifier($points[0])) {
            return [];
        }

        foreach (\array_slice($points, 1) as $point) {
            if ($readsQuantifier($point)) {
                return [['regex.lint.compat.meaningChanges', ProjectTarget::phpLabel($point)]];
            }
        }

        return [];
    }

    /**
     * The warnings of the report, as [issue_id, target php].
     *
     * @param array<mixed> $document
     *
     * @return list<array{mixed, mixed}>
     */
    private static function warnings(array $document): array
    {
        $warnings = [];
        foreach (self::issues($document) as $issue) {
            if ('warning' === ($issue['severity'] ?? null)) {
                $warnings[] = [$issue['issue_id'] ?? null, JsonContract::asArray($issue['target'] ?? null)['php'] ?? null];
            }
        }

        return $warnings;
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

    /**
     * Every version of the range, each with the PCRE2 it bundles, the
     * running one for the running PHP's minor.
     *
     * @return list<array{php: string, pcre: string}>
     */
    private static function fullRange(): array
    {
        return [
            ['php' => '8.2', 'pcre' => self::judgedPcre(80200)],
            ['php' => '8.3', 'pcre' => self::judgedPcre(80300)],
            ['php' => '8.4', 'pcre' => self::judgedPcre(80400)],
            ['php' => '8.4.25', 'pcre' => self::judgedPcre(80425)],
            ['php' => '8.5', 'pcre' => self::judgedPcre(80500)],
            ['php' => '8.5.10', 'pcre' => self::judgedPcre(80510)],
        ];
    }
}
