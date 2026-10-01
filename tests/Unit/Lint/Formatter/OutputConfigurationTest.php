<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Lint\Formatter;

use PhpRegex\Linter\Formatter\OutputConfiguration;
use PHPUnit\Framework\TestCase;

final class OutputConfigurationTest extends TestCase
{
    public function test_construct_with_defaults(): void
    {
        $config = new OutputConfiguration();

        $this->assertSame(OutputConfiguration::VERBOSITY_NORMAL, $config->verbosity);
        $this->assertTrue($config->ansi);
        $this->assertTrue($config->showProgress);
        $this->assertTrue($config->showOptimizations);
        $this->assertTrue($config->showHints);
        $this->assertTrue($config->groupByFile);
        $this->assertTrue($config->sortBySeverity);
    }

    public function test_construct_with_custom_values(): void
    {
        $config = new OutputConfiguration(
            verbosity: OutputConfiguration::VERBOSITY_VERBOSE,
            ansi: false,
            showProgress: false,
            showOptimizations: false,
            showHints: false,
            groupByFile: false,
            sortBySeverity: false,
        );

        $this->assertSame(OutputConfiguration::VERBOSITY_VERBOSE, $config->verbosity);
        $this->assertFalse($config->ansi);
        $this->assertFalse($config->showProgress);
        $this->assertFalse($config->showOptimizations);
        $this->assertFalse($config->showHints);
        $this->assertFalse($config->groupByFile);
        $this->assertFalse($config->sortBySeverity);
    }

    public function test_quiet_factory_method(): void
    {
        $config = OutputConfiguration::quiet();

        $this->assertSame(OutputConfiguration::VERBOSITY_QUIET, $config->verbosity);
        $this->assertFalse($config->ansi);
        $this->assertFalse($config->showProgress);
        $this->assertFalse($config->showOptimizations);
        $this->assertFalse($config->showHints);
        $this->assertTrue($config->groupByFile);
        $this->assertTrue($config->sortBySeverity);
    }

    public function test_verbose_factory_method(): void
    {
        $config = OutputConfiguration::verbose();

        $this->assertSame(OutputConfiguration::VERBOSITY_VERBOSE, $config->verbosity);
        $this->assertTrue($config->ansi);
        $this->assertTrue($config->showProgress);
        $this->assertTrue($config->showOptimizations);
        $this->assertTrue($config->showHints);
        $this->assertTrue($config->groupByFile);
        $this->assertTrue($config->sortBySeverity);
    }

    public function test_debug_factory_method(): void
    {
        $config = OutputConfiguration::debug();

        $this->assertSame(OutputConfiguration::VERBOSITY_DEBUG, $config->verbosity);
        $this->assertTrue($config->ansi);
        $this->assertTrue($config->showProgress);
        $this->assertTrue($config->showOptimizations);
        $this->assertTrue($config->showHints);
        $this->assertTrue($config->groupByFile);
        $this->assertTrue($config->sortBySeverity);
    }

    public function test_should_show_hints_with_normal_verbosity_and_hints_enabled(): void
    {
        $config = new OutputConfiguration(
            verbosity: OutputConfiguration::VERBOSITY_NORMAL,
            showHints: true,
        );

        $this->assertTrue($config->shouldShowHints());
    }

    public function test_should_show_hints_with_verbose_verbosity_and_hints_enabled(): void
    {
        $config = new OutputConfiguration(
            verbosity: OutputConfiguration::VERBOSITY_VERBOSE,
            showHints: true,
        );

        $this->assertTrue($config->shouldShowHints());
    }

    public function test_should_show_hints_with_debug_verbosity_and_hints_enabled(): void
    {
        $config = new OutputConfiguration(
            verbosity: OutputConfiguration::VERBOSITY_DEBUG,
            showHints: true,
        );

        $this->assertTrue($config->shouldShowHints());
    }

    public function test_should_show_hints_with_quiet_verbosity(): void
    {
        $config = new OutputConfiguration(
            verbosity: OutputConfiguration::VERBOSITY_QUIET,
            showHints: true,
        );

        $this->assertFalse($config->shouldShowHints());
    }

    public function test_should_show_hints_with_hints_disabled(): void
    {
        $config = new OutputConfiguration(
            verbosity: OutputConfiguration::VERBOSITY_NORMAL,
            showHints: false,
        );

        $this->assertFalse($config->shouldShowHints());
    }

    public function test_should_show_detailed_redos_with_verbose_verbosity(): void
    {
        $config = new OutputConfiguration(verbosity: OutputConfiguration::VERBOSITY_VERBOSE);

        $this->assertTrue($config->shouldShowDetailedReDoS());
    }

    public function test_should_show_detailed_redos_with_debug_verbosity(): void
    {
        $config = new OutputConfiguration(verbosity: OutputConfiguration::VERBOSITY_DEBUG);

        $this->assertTrue($config->shouldShowDetailedReDoS());
    }

    public function test_should_show_detailed_redos_with_normal_verbosity(): void
    {
        $config = new OutputConfiguration(verbosity: OutputConfiguration::VERBOSITY_NORMAL);

        $this->assertFalse($config->shouldShowDetailedReDoS());
    }

    public function test_should_show_detailed_redos_with_quiet_verbosity(): void
    {
        $config = new OutputConfiguration(verbosity: OutputConfiguration::VERBOSITY_QUIET);

        $this->assertFalse($config->shouldShowDetailedReDoS());
    }

    public function test_should_show_optimizations_with_normal_verbosity_and_optimizations_enabled(): void
    {
        $config = new OutputConfiguration(
            verbosity: OutputConfiguration::VERBOSITY_NORMAL,
            showOptimizations: true,
        );

        $this->assertTrue($config->shouldShowOptimizations());
    }

    public function test_should_show_optimizations_with_verbose_verbosity_and_optimizations_enabled(): void
    {
        $config = new OutputConfiguration(
            verbosity: OutputConfiguration::VERBOSITY_VERBOSE,
            showOptimizations: true,
        );

        $this->assertTrue($config->shouldShowOptimizations());
    }

    public function test_should_show_optimizations_with_debug_verbosity_and_optimizations_enabled(): void
    {
        $config = new OutputConfiguration(
            verbosity: OutputConfiguration::VERBOSITY_DEBUG,
            showOptimizations: true,
        );

        $this->assertTrue($config->shouldShowOptimizations());
    }

    public function test_should_show_optimizations_with_quiet_verbosity(): void
    {
        $config = new OutputConfiguration(
            verbosity: OutputConfiguration::VERBOSITY_QUIET,
            showOptimizations: true,
        );

        $this->assertFalse($config->shouldShowOptimizations());
    }

    public function test_should_show_optimizations_with_optimizations_disabled(): void
    {
        $config = new OutputConfiguration(
            verbosity: OutputConfiguration::VERBOSITY_NORMAL,
            showOptimizations: false,
        );

        $this->assertFalse($config->shouldShowOptimizations());
    }

    public function test_should_show_progress_with_normal_verbosity_and_progress_enabled(): void
    {
        $config = new OutputConfiguration(
            verbosity: OutputConfiguration::VERBOSITY_NORMAL,
            showProgress: true,
        );

        $this->assertTrue($config->shouldShowProgress());
    }

    public function test_should_show_progress_with_verbose_verbosity_and_progress_enabled(): void
    {
        $config = new OutputConfiguration(
            verbosity: OutputConfiguration::VERBOSITY_VERBOSE,
            showProgress: true,
        );

        $this->assertTrue($config->shouldShowProgress());
    }

    public function test_should_show_progress_with_debug_verbosity_and_progress_enabled(): void
    {
        $config = new OutputConfiguration(
            verbosity: OutputConfiguration::VERBOSITY_DEBUG,
            showProgress: true,
        );

        $this->assertTrue($config->shouldShowProgress());
    }

    public function test_should_show_progress_with_quiet_verbosity(): void
    {
        $config = new OutputConfiguration(
            verbosity: OutputConfiguration::VERBOSITY_QUIET,
            showProgress: true,
        );

        $this->assertFalse($config->shouldShowProgress());
    }

    public function test_should_show_progress_with_progress_disabled(): void
    {
        $config = new OutputConfiguration(
            verbosity: OutputConfiguration::VERBOSITY_NORMAL,
            showProgress: false,
        );

        $this->assertFalse($config->shouldShowProgress());
    }
}
