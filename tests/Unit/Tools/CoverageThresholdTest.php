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

namespace PHPRegex\Tests\Unit\Tools;

use PHPRegex\Tests\TestUtils\CoverageThreshold;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The line coverage CI compares with the threshold kept in
 * .github/coverage-threshold: the share of executable lines a run reached,
 * read from the project totals of a Clover report.
 */
final class CoverageThresholdTest extends TestCase
{
    private const SCRIPT = __DIR__.'/../../Tools/check_coverage.php';

    private ?string $directory = null;

    protected function tearDown(): void
    {
        if (null !== $this->directory) {
            foreach (glob($this->directory.'/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($this->directory);
            $this->directory = null;
        }
    }

    #[Test]
    public function test_line_coverage_reads_the_project_totals(): void
    {
        $this->assertEqualsWithDelta(93.25, CoverageThreshold::lineCoverage(self::clover(400, 373)), \PHP_FLOAT_EPSILON);
    }

    #[Test]
    public function test_line_coverage_ignores_the_per_file_totals(): void
    {
        $clover = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <coverage generated="0">
              <project timestamp="0">
                <file name="/src/A.php">
                  <metrics loc="10" ncloc="10" statements="10" coveredstatements="0"/>
                </file>
                <metrics files="1" loc="10" ncloc="10" statements="20" coveredstatements="15"/>
              </project>
            </coverage>
            XML;

        $this->assertEqualsWithDelta(75.0, CoverageThreshold::lineCoverage($clover), \PHP_FLOAT_EPSILON);
    }

    /**
     * @return iterable<string, array{clover: string}>
     */
    public static function provideUnreadableReports(): iterable
    {
        yield 'not XML' => ['clover' => 'not a report'];
        yield 'empty' => ['clover' => ''];
        yield 'no project totals' => ['clover' => '<?xml version="1.0"?><coverage><project/></coverage>'];
        yield 'no statement measured' => ['clover' => self::clover(0, 0)];
        yield 'more lines covered than measured' => ['clover' => self::clover(10, 11)];
    }

    #[Test]
    #[DataProvider('provideUnreadableReports')]
    public function test_line_coverage_is_null_for_a_report_it_cannot_read(string $clover): void
    {
        $this->assertNull(CoverageThreshold::lineCoverage($clover));
    }

    /**
     * @return iterable<string, array{contents: string, expected: float|null}>
     */
    public static function provideThresholds(): iterable
    {
        yield 'a decimal number' => ['contents' => "92.5\n", 'expected' => 92.5];
        yield 'an integer' => ['contents' => '90', 'expected' => 90.0];
        yield 'surrounding white space' => ['contents' => "  91.0 \n\n", 'expected' => 91.0];
        yield 'zero' => ['contents' => '0', 'expected' => 0.0];
        yield 'a hundred' => ['contents' => '100', 'expected' => 100.0];
        yield 'empty' => ['contents' => '', 'expected' => null];
        yield 'not a number' => ['contents' => 'ninety', 'expected' => null];
        yield 'a percent sign' => ['contents' => '92%', 'expected' => null];
        yield 'two numbers' => ['contents' => "92\n93", 'expected' => null];
        yield 'negative' => ['contents' => '-1', 'expected' => null];
        yield 'above a hundred' => ['contents' => '100.5', 'expected' => null];
    }

    #[Test]
    #[DataProvider('provideThresholds')]
    public function test_threshold_reads_one_percentage(string $contents, ?float $expected): void
    {
        $this->assertSame($expected, CoverageThreshold::threshold($contents));
    }

    /**
     * @return iterable<string, array{statements: int, covered: int, threshold: string, exitCode: int, output: string}>
     */
    public static function provideScriptRuns(): iterable
    {
        yield 'above the threshold' => ['statements' => 1000, 'covered' => 935, 'threshold' => "93.0\n", 'exitCode' => 0, 'output' => 'Line coverage: 93.50% (threshold 93.00%)'];
        yield 'exactly the threshold' => ['statements' => 1000, 'covered' => 930, 'threshold' => '93', 'exitCode' => 0, 'output' => 'Line coverage: 93.00% (threshold 93.00%)'];
        yield 'below the threshold' => ['statements' => 1000, 'covered' => 929, 'threshold' => '93', 'exitCode' => 1, 'output' => 'Line coverage: 92.90% (threshold 93.00%)'];
        yield 'an unreadable threshold' => ['statements' => 1000, 'covered' => 935, 'threshold' => 'ninety', 'exitCode' => 2, 'output' => 'No percentage in'];
        yield 'an unreadable report' => ['statements' => 0, 'covered' => 0, 'threshold' => '93', 'exitCode' => 2, 'output' => 'No line coverage in'];
    }

    #[Test]
    #[DataProvider('provideScriptRuns')]
    public function test_script_compares_the_report_with_the_threshold(int $statements, int $covered, string $threshold, int $exitCode, string $output): void
    {
        $this->directory = sys_get_temp_dir().'/coverage-threshold-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
        file_put_contents($this->directory.'/clover.xml', self::clover($statements, $covered));
        file_put_contents($this->directory.'/threshold', $threshold);

        [$code, $printed] = self::runScript([$this->directory.'/clover.xml', $this->directory.'/threshold']);

        $this->assertSame($exitCode, $code, $printed);
        $this->assertStringContainsString($output, $printed);
    }

    #[Test]
    public function test_script_reports_a_missing_report(): void
    {
        [$code, $printed] = self::runScript([sys_get_temp_dir().'/no-such-clover-'.bin2hex(random_bytes(4)).'.xml']);

        $this->assertSame(2, $code, $printed);
        $this->assertStringContainsString('Cannot read', $printed);
    }

    #[Test]
    public function test_script_without_arguments_prints_its_usage(): void
    {
        [$code, $printed] = self::runScript([]);

        $this->assertSame(2, $code, $printed);
        $this->assertStringContainsString('Usage: php tests/Tools/check_coverage.php <clover.xml> [<threshold file>]', $printed);
    }

    #[Test]
    public function test_the_committed_threshold_is_a_percentage(): void
    {
        $contents = file_get_contents(__DIR__.'/../../../.github/coverage-threshold');

        $this->assertIsString($contents);
        $this->assertNotNull(CoverageThreshold::threshold($contents), 'The threshold file holds one number from 0 to 100.');
    }

    private static function clover(int $statements, int $covered): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <coverage generated="0">
              <project timestamp="0">
                <metrics files="1" loc="{$statements}" ncloc="{$statements}" classes="1" methods="1" coveredmethods="0" conditionals="0" coveredconditionals="0" statements="{$statements}" coveredstatements="{$covered}" elements="{$statements}" coveredelements="{$covered}"/>
              </project>
            </coverage>
            XML;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string}
     */
    private static function runScript(array $arguments): array
    {
        $process = proc_open(
            [\PHP_BINARY, '-d', 'auto_prepend_file=', self::SCRIPT, ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output.$errors];
    }
}
