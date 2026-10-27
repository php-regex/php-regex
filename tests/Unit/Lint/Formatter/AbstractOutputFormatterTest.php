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

namespace PHPRegex\Tests\Unit\Lint\Formatter;

use PHPRegex\Linter\Formatter\AbstractOutputFormatter;
use PHPRegex\Linter\Formatter\OutputConfiguration;
use PHPRegex\Linter\LintReport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type LintResult from LintReport
 */
final class AbstractOutputFormatterTest extends TestCase
{
    private TestableAbstractOutputFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new TestableAbstractOutputFormatter();
    }

    #[Test]
    public function test_construct_with_custom_config(): void
    {
        $config = new OutputConfiguration(ansi: false);
        $formatter = new TestableAbstractOutputFormatter($config);

        $this->assertSame($config, $formatter->getConfig());
        $this->assertFalse($formatter->getConfig()->ansi);
    }

    #[Test]
    public function test_format_error_returns_message(): void
    {
        $message = 'Test error message';

        $result = $this->formatter->formatError($message);

        $this->assertSame($message, $result);
    }

    #[Test]
    public function test_format_hint_when_hints_disabled(): void
    {
        $config = new OutputConfiguration(showHints: false);
        $formatter = new TestableAbstractOutputFormatter($config);

        $result = $formatter->formatHint('Test hint');

        $this->assertSame('', $result);
    }

    #[Test]
    public function test_format_hint_with_normal_verbosity(): void
    {
        $config = new OutputConfiguration(
            verbosity: OutputConfiguration::VERBOSITY_NORMAL,
            showHints: true,
        );
        $formatter = new TestableAbstractOutputFormatter($config);

        $result = $formatter->formatHint('Test hint');

        $this->assertSame('Test hint', $result);
    }

    #[Test]
    public function test_format_hint_with_verbose_verbosity(): void
    {
        $config = new OutputConfiguration(
            verbosity: OutputConfiguration::VERBOSITY_VERBOSE,
            showHints: true,
        );
        $formatter = new TestableAbstractOutputFormatter($config);

        $result = $formatter->formatHint('Test hint');

        $this->assertSame('Test hint', $result);
    }

    #[Test]
    public function test_format_hint_truncates_long_hints_in_normal_mode(): void
    {
        $config = new OutputConfiguration(
            verbosity: OutputConfiguration::VERBOSITY_NORMAL,
            showHints: true,
        );
        $formatter = new TestableAbstractOutputFormatter($config);

        $longHint = str_repeat('a', 210);

        $result = $formatter->formatHint($longHint);

        $this->assertStringEndsWith('...', $result);
        $this->assertSame(200, \strlen($result));
    }

    #[Test]
    public function test_format_hint_does_not_truncate_in_verbose_mode(): void
    {
        $config = new OutputConfiguration(
            verbosity: OutputConfiguration::VERBOSITY_VERBOSE,
            showHints: true,
        );
        $formatter = new TestableAbstractOutputFormatter($config);

        $longHint = str_repeat('a', 210);

        $result = $formatter->formatHint($longHint);

        $this->assertSame($longHint, $result);
        $this->assertSame(210, \strlen($result));
    }

    #[Test]
    public function test_group_results_when_group_by_file_disabled(): void
    {
        $config = new OutputConfiguration(groupByFile: false);
        $formatter = new TestableAbstractOutputFormatter($config);

        $results = [self::lintResult('file1.php', 1), self::lintResult('file2.php', 2)];

        $result = $formatter->groupResults($results);

        $expected = ['' => $results];
        $this->assertSame($expected, $result);
    }

    #[Test]
    public function test_group_results_when_group_by_file_enabled(): void
    {
        $config = new OutputConfiguration(groupByFile: true);
        $formatter = new TestableAbstractOutputFormatter($config);

        $results = [self::lintResult('file1.php', 1), self::lintResult('file2.php', 2), self::lintResult('file1.php', 3)];

        $result = $formatter->groupResults($results);

        $expected = [
            'file1.php' => [self::lintResult('file1.php', 1), self::lintResult('file1.php', 3)],
            'file2.php' => [self::lintResult('file2.php', 2)],
        ];
        $this->assertSame($expected, $result);
    }

    /**
     * @return LintResult
     */
    private static function lintResult(string $file, int $line): array
    {
        return ['file' => $file, 'line' => $line, 'pattern' => null, 'issues' => [], 'optimizations' => []];
    }
}

/**
 * Testable implementation of AbstractOutputFormatter for testing protected methods.
 *
 * @phpstan-import-type LintResult from LintReport
 */
final class TestableAbstractOutputFormatter extends AbstractOutputFormatter
{
    public function format(LintReport $report): string
    {
        return 'test';
    }

    public function getConfig(): OutputConfiguration
    {
        return $this->config;
    }

    public function formatHint(string $hint): string
    {
        return parent::formatHint($hint);
    }

    /**
     * @param array<LintResult> $results
     *
     * @return array<string, array<LintResult>>
     */
    public function groupResults(array $results): array
    {
        return parent::groupResults($results);
    }
}
