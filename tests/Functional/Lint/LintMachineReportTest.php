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
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A machine format is the output asked for, not a status line: --quiet
 * silences the lines around it, never the report itself. And --json asks
 * for the JSON report, as it does for the other commands.
 */
final class LintMachineReportTest extends TestCase
{
    use RunsRegexCli;
    use TemporaryProject;

    private const PROJECT = [
        'composer.json' => '{"name": "acme/app", "require": {"php": "^8.3"}}',
        'src/a.php' => "<?php\n\npreg_match('/[aa]/', 'a');\n",
    ];

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideQuietMachineFormats')]
    public function test_lint_prints_a_machine_report_under_quiet(array $arguments, string $expected): void
    {
        $this->enterProject(self::PROJECT);

        [$exitCode, $stdout] = $this->runRegex($arguments);

        $this->assertSame(0, $exitCode, $stdout);
        $this->assertStringContainsString($expected, $stdout);
    }

    /**
     * @return iterable<string, array{arguments: list<string>, expected: string}>
     */
    public static function provideQuietMachineFormats(): iterable
    {
        $formats = [
            'json' => '"results": [',
            'github' => '::warning file=src/a.php,line=3',
            'checkstyle' => '<checkstyle version=',
            'junit' => '<testsuite name="php-regex"',
        ];

        foreach ($formats as $format => $expected) {
            yield $format.', -q before the command' => [
                'arguments' => ['-q', 'lint', 'src', '--format='.$format, '--jobs=1', '--no-redos'],
                'expected' => $expected,
            ];
            yield $format.', --quiet after the command' => [
                'arguments' => ['lint', 'src', '--format='.$format, '--jobs=1', '--no-redos', '--quiet'],
                'expected' => $expected,
            ];
        }
    }

    #[Test]
    public function test_lint_json_option_prints_the_json_report(): void
    {
        $this->enterProject(self::PROJECT);

        [$exitCode, $stdout] = $this->runRegex(['lint', 'src', '--json', '--jobs=1', '--no-redos']);

        $this->assertSame(0, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        $this->assertArrayNotHasKey('error', $document, $stdout);
        $this->assertArrayHasKey('results', $document);
        $this->assertArrayHasKey('stats', $document);
    }

    #[Test]
    public function test_lint_json_option_prints_the_same_report_as_the_json_format(): void
    {
        $this->enterProject(self::PROJECT);

        [, $alias] = $this->runRegex(['lint', 'src', '--json', '--jobs=1', '--no-redos']);
        [, $format] = $this->runRegex(['lint', 'src', '--format=json', '--jobs=1', '--no-redos']);

        $this->assertSame($format, $alias);
    }
}
