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

use PHPRegex\Tests\Support\RunsRegexCli;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A run that gives up on a pattern — past one of the library's own limits —
 * says how many it gave up on, in the summary and in the JSON report, and
 * keeps its exit code: a pattern not analyzed is a warning, and warnings
 * alone leave 0.
 */
final class LintNotAnalyzedTest extends TestCase
{
    use RunsRegexCli;
    use TemporaryProject;

    #[Test]
    public function test_the_summary_and_the_json_report_count_the_patterns_not_analyzed(): void
    {
        $this->enterProject();
        // One past the 100,000 characters max_pattern_length allows by
        // default, and one pattern the library reads.
        file_put_contents('a.php', "<?php\npreg_match('/".str_repeat('a', 100001)."/', \$s);\npreg_match('/b/', \$s);\n");

        [$exitCode, $stdout] = $this->runRegex(['lint', 'a.php']);
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('PASS 1 warnings (1 not analyzed) found', $stdout);

        [$exitCode, $stdout] = $this->runRegex(['lint', 'a.php', '--format=json']);
        $this->assertSame(0, $exitCode);
        $document = json_decode($stdout, true);
        $this->assertIsArray($document);
        $this->assertIsArray($document['stats']);
        $this->assertSame(1, $document['stats']['warnings']);
        $this->assertSame(1, $document['stats']['not_analyzed']);
    }
}
