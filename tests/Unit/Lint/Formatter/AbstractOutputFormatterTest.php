<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Lint\Formatter;

use PhpRegex\Linter\Formatter\AbstractOutputFormatter;
use PhpRegex\Linter\Formatter\OutputConfiguration;
use PhpRegex\Linter\LintReport;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;

final class AbstractOutputFormatterTest extends TestCase
{
    private TestableAbstractOutputFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new TestableAbstractOutputFormatter();
    }

    #[DoesNotPerformAssertions]
    public function test_construct_with_default_config(): void
    {
        $formatter = new TestableAbstractOutputFormatter();

        // Test that config is properly initialized
        $config = $formatter->getConfig();
    }

    public function test_construct_with_custom_config(): void
    {
        $config = new OutputConfiguration(ansi: false);
        $formatter = new TestableAbstractOutputFormatter($config);

        $this->assertSame($config, $formatter->getConfig());
        $this->assertFalse($formatter->getConfig()->ansi);
    }

    public function test_format_error_returns_message(): void
    {
        $message = 'Test error message';

        $result = $this->formatter->formatError($message);

        $this->assertSame($message, $result);
    }

    public function test_get_severity_badge_for_error(): void
    {
        $result = $this->formatter->getSeverityBadge('error');

        $this->assertSame('FAIL', $result);
    }

    public function test_get_severity_badge_for_warning(): void
    {
        $result = $this->formatter->getSeverityBadge('warning');

        $this->assertSame('WARN', $result);
    }

    public function test_get_severity_badge_for_info(): void
    {
        $result = $this->formatter->getSeverityBadge('info');

        $this->assertSame('INFO', $result);
    }

    public function test_get_severity_badge_for_unknown(): void
    {
        $result = $this->formatter->getSeverityBadge('unknown');

        $this->assertSame('NOTE', $result);
    }

    public function test_get_severity_color_for_error(): void
    {
        $result = $this->formatter->getSeverityColor('error');

        $this->assertSame('red', $result);
    }

    public function test_get_severity_color_for_warning(): void
    {
        $result = $this->formatter->getSeverityColor('warning');

        $this->assertSame('yellow', $result);
    }

    public function test_get_severity_color_for_info(): void
    {
        $result = $this->formatter->getSeverityColor('info');

        $this->assertSame('blue', $result);
    }

    public function test_get_severity_color_for_unknown(): void
    {
        $result = $this->formatter->getSeverityColor('unknown');

        $this->assertSame('gray', $result);
    }

    public function test_format_hint_when_hints_disabled(): void
    {
        $config = new OutputConfiguration(showHints: false);
        $formatter = new TestableAbstractOutputFormatter($config);

        $result = $formatter->formatHint('Test hint');

        $this->assertSame('', $result);
    }

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

    public function test_format_redos_hint_when_hints_disabled(): void
    {
        $config = new OutputConfiguration(showHints: false);
        $formatter = new TestableAbstractOutputFormatter($config);

        $result = $formatter->formatReDoSHint('Test ReDoS hint');

        $this->assertSame('', $result);
    }

    public function test_format_redos_hint_with_normal_verbosity(): void
    {
        $config = new OutputConfiguration(
            verbosity: OutputConfiguration::VERBOSITY_NORMAL,
            showHints: true,
        );
        $formatter = new TestableAbstractOutputFormatter($config);

        $result = $formatter->formatReDoSHint('Test ReDoS hint');

        $this->assertSame('Nested quantifiers detected. Suggested (verify behavior): use atomic groups (?>...) or possessive quantifiers (*+, ++).', $result);
    }

    public function test_format_redos_hint_with_verbose_verbosity(): void
    {
        $config = new OutputConfiguration(
            verbosity: OutputConfiguration::VERBOSITY_VERBOSE,
            showHints: true,
        );
        $formatter = new TestableAbstractOutputFormatter($config);

        $result = $formatter->formatReDoSHint('Test ReDoS hint');

        $this->assertSame('Test ReDoS hint', $result);
    }

    public function test_group_results_when_group_by_file_disabled(): void
    {
        $config = new OutputConfiguration(groupByFile: false);
        $formatter = new TestableAbstractOutputFormatter($config);

        $results = [
            ['file' => 'file1.php', 'type' => 'error'],
            ['file' => 'file2.php', 'type' => 'warning'],
        ];

        $result = $formatter->groupResults($results);

        $expected = ['' => $results];
        $this->assertSame($expected, $result);
    }

    public function test_group_results_when_group_by_file_enabled(): void
    {
        $config = new OutputConfiguration(groupByFile: true);
        $formatter = new TestableAbstractOutputFormatter($config);

        $results = [
            ['file' => 'file1.php', 'type' => 'error'],
            ['file' => 'file2.php', 'type' => 'warning'],
            ['file' => 'file1.php', 'type' => 'info'],
        ];

        $result = $formatter->groupResults($results);

        $expected = [
            'file1.php' => [
                ['file' => 'file1.php', 'type' => 'error'],
                ['file' => 'file1.php', 'type' => 'info'],
            ],
            'file2.php' => [
                ['file' => 'file2.php', 'type' => 'warning'],
            ],
        ];
        $this->assertSame($expected, $result);
    }

    public function test_group_results_with_unknown_file(): void
    {
        $config = new OutputConfiguration(groupByFile: true);
        $formatter = new TestableAbstractOutputFormatter($config);

        $results = [
            ['type' => 'error'], // No file key
            ['file' => null, 'type' => 'warning'], // Null file
            ['file' => 123, 'type' => 'info'], // Non-string file
        ];

        $result = $formatter->groupResults($results);

        $expected = [
            'unknown' => $results,
        ];
        $this->assertSame($expected, $result);
    }

    public function test_sort_results_when_sort_by_severity_disabled(): void
    {
        $config = new OutputConfiguration(sortBySeverity: false);
        $formatter = new TestableAbstractOutputFormatter($config);

        $results = [
            ['type' => 'info'],
            ['type' => 'error'],
            ['type' => 'warning'],
        ];

        $result = $formatter->sortResults($results);

        $this->assertSame($results, $result);
    }

    public function test_sort_results_when_sort_by_severity_enabled(): void
    {
        $config = new OutputConfiguration(sortBySeverity: true);
        $formatter = new TestableAbstractOutputFormatter($config);

        $results = [
            ['type' => 'info'],
            ['type' => 'warning'],
            ['type' => 'error'],
        ];

        $result = $formatter->sortResults($results);

        $expected = [
            ['type' => 'error'],
            ['type' => 'warning'],
            ['type' => 'info'],
        ];
        $this->assertSame($expected, $result);
    }

    public function test_sort_results_with_unknown_severity(): void
    {
        $config = new OutputConfiguration(sortBySeverity: true);
        $formatter = new TestableAbstractOutputFormatter($config);

        $results = [
            ['type' => 'unknown'],
            ['type' => 'error'],
            [], // No type
        ];

        $result = $formatter->sortResults($results);

        $expected = [
            ['type' => 'error'],
            ['type' => 'unknown'],
            [], // No type (defaults to 'info' priority)
        ];
        $this->assertSame($expected, $result);
    }

    public function test_sort_results_with_mixed_types(): void
    {
        $config = new OutputConfiguration(sortBySeverity: true);
        $formatter = new TestableAbstractOutputFormatter($config);

        $results = [
            ['type' => 'warning', 'non_string_type' => 123],
            ['type' => 'error'],
            ['type' => null],
            ['type' => 'info'],
        ];

        $result = $formatter->sortResults($results);

        // Error first, then warning, then others (info priority)
        $this->assertSame('error', $result[0]['type']);
        $this->assertSame('warning', $result[1]['type']);
        // The rest should be at info priority level
    }
}

/**
 * Testable implementation of AbstractOutputFormatter for testing protected methods.
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

    public function getSeverityBadge(string $type): string
    {
        return parent::getSeverityBadge($type);
    }

    public function getSeverityColor(string $type): string
    {
        return parent::getSeverityColor($type);
    }

    public function formatHint(string $hint): string
    {
        return parent::formatHint($hint);
    }

    public function formatReDoSHint(string $hint): string
    {
        return parent::formatReDoSHint($hint);
    }

    public function groupResults(array $results): array
    {
        return parent::groupResults($results);
    }

    public function sortResults(array $results): array
    {
        return parent::sortResults($results);
    }
}
