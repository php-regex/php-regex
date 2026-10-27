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

namespace PHPRegex\Tests\Functional\Lint;

use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\PcreRule;
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `regex lint` judges a pattern under "@regex-target" for the versions of
 * the composer.json range the marker keeps, and the JSON report names
 * them: "target" holds the version judged and "guard" the constraint.
 */
final class LintTargetMarkerTest extends TestCase
{
    use PcreRule;
    use RunsRegexCli;
    use TemporaryProject;

    protected function setUp(): void
    {
        $this->rememberWorkingDirectory();
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryProjects();
    }

    #[Test]
    public function test_the_json_report_names_the_versions_a_marker_keeps(): void
    {
        $this->enterProject([
            'composer.json' => '{"require": {"php": "^8.2"}}',
            'src/a.php' => "<?php\npreg_match('/[11]/', \$s); // @regex-target php>=8.4\npreg_match('/(?a)\\d+/', \$s); // @regex-target php>=8.4\npreg_match('/(?a)\\d+/', \$s);\n",
        ]);

        [$exitCode, $stdout] = $this->runRegex(['lint', 'src', '--format=json', '--jobs=1', '--no-optimize']);

        $this->assertSame(1, $exitCode, $stdout);
        $issues = [];
        foreach (JsonContract::asArray(JsonContract::decodeDocument($stdout)['results'] ?? null) as $result) {
            foreach (JsonContract::asArray(JsonContract::asArray($result)['issues'] ?? null) as $issue) {
                $issue = JsonContract::asArray($issue);
                $issues[] = [$issue['line'] ?? null, $issue['issue_id'] ?? null, $issue['target'] ?? null];
            }
        }

        $this->assertSame([
            [2, 'regex.lint.charclass.redundant', ['php' => '8.4', 'pcre' => self::judgedPcre(80400), 'guard' => '>=8.4', 'guard_pcre' => null]],
            [4, 'regex.group.syntax', null],
        ], $issues);
    }
}
