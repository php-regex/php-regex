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

namespace PHPRegex\Tests\Unit\Lint\Command;

use PHPRegex\Cli\Command\LintArgumentParser;
use PHPRegex\Linter\Config\LintArguments;
use PHPRegex\Linter\Config\LintConfigLoader;
use PHPRegex\Linter\Config\LintDefaultsBuilder;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * reportDynamic in regex.json and its two options: off unless the file or
 * the command line turns it on, the last option given winning, and kept
 * whatever else the command line adds.
 */
final class LintReportDynamicSettingTest extends TestCase
{
    use TemporaryProject;

    protected function tearDown(): void
    {
        $this->removeTemporaryProjects();
    }

    #[Test]
    public function test_the_report_is_off_by_default(): void
    {
        $this->assertFalse(LintArguments::fromDefaults([])->reportDynamic);
        $this->assertFalse($this->argumentsFrom('{}')->reportDynamic);

        $parsed = (new LintArgumentParser())->parse(['src']);
        $this->assertNull($parsed->error);
        $this->assertInstanceOf(LintArguments::class, $parsed->arguments);
        $this->assertFalse($parsed->arguments->reportDynamic);
    }

    #[Test]
    public function test_a_default_that_is_no_boolean_keeps_the_report_off(): void
    {
        $this->assertFalse(LintArguments::fromDefaults(['reportDynamic' => 'yes'])->reportDynamic);
        $this->assertFalse(LintArguments::fromDefaults(['reportDynamic' => 1])->reportDynamic);
        $this->assertTrue(LintArguments::fromDefaults(['reportDynamic' => true])->reportDynamic);
    }

    #[Test]
    public function test_regex_json_turns_the_report_on(): void
    {
        $this->assertTrue($this->argumentsFrom('{"reportDynamic": true}')->reportDynamic);
        $this->assertFalse($this->argumentsFrom('{"reportDynamic": false}')->reportDynamic);
    }

    /**
     * @return iterable<string, array{json: string}>
     */
    public static function provideValuesThatAreNoBoolean(): iterable
    {
        yield 'a string' => ['json' => '{"reportDynamic": "yes"}'];
        yield 'a number' => ['json' => '{"reportDynamic": 1}'];
        yield 'null' => ['json' => '{"reportDynamic": null}'];
        yield 'a list' => ['json' => '{"reportDynamic": [true]}'];
    }

    #[Test]
    #[DataProvider('provideValuesThatAreNoBoolean')]
    public function test_regex_json_refuses_a_value_that_is_no_boolean(string $json): void
    {
        $loaded = (new LintConfigLoader())->load($this->makeProject(['regex.json' => $json]));

        $this->assertNotNull($loaded->error);
        $this->assertStringContainsString('"reportDynamic"', (string) $loaded->error);
        $this->assertStringContainsString('expected a boolean', (string) $loaded->error);
    }

    /**
     * @return iterable<string, array{args: list<string>, defaults: array<string, mixed>, expected: bool}>
     */
    public static function provideOptions(): iterable
    {
        yield 'on' => ['args' => ['--report-dynamic'], 'defaults' => [], 'expected' => true];
        yield 'off, as by default' => ['args' => ['--no-report-dynamic'], 'defaults' => [], 'expected' => false];
        yield 'off over regex.json' => ['args' => ['--no-report-dynamic'], 'defaults' => ['reportDynamic' => true], 'expected' => false];
        yield 'on over regex.json' => ['args' => ['--report-dynamic'], 'defaults' => ['reportDynamic' => false], 'expected' => true];
        yield 'the last one wins, on' => ['args' => ['--no-report-dynamic', '--report-dynamic'], 'defaults' => [], 'expected' => true];
        yield 'the last one wins, off' => ['args' => ['--report-dynamic', '--no-report-dynamic'], 'defaults' => [], 'expected' => false];
        yield 'kept by another option' => ['args' => ['--report-dynamic', '--redos', '--jobs=2', '--no-optimize', '--format=json', '--quiet', '--baseline=b.json'], 'defaults' => [], 'expected' => true];
        yield 'regex.json kept by another option' => ['args' => ['--redos', '--no-lint', '--interop=nette-utils', '--verbose'], 'defaults' => ['reportDynamic' => true], 'expected' => true];
        yield 'kept beside the report of the markers' => ['args' => ['--report-dynamic', '--no-report-unmatched-ignores'], 'defaults' => [], 'expected' => true];
    }

    /**
     * @param list<string>         $args
     * @param array<string, mixed> $defaults
     */
    #[Test]
    #[DataProvider('provideOptions')]
    public function test_the_command_line_sets_the_report(array $args, array $defaults, bool $expected): void
    {
        $result = (new LintArgumentParser())->parse($args, $defaults);

        $this->assertNull($result->error);
        $this->assertInstanceOf(LintArguments::class, $result->arguments);
        $this->assertSame($expected, $result->arguments->reportDynamic);
    }

    /**
     * The option takes no value: what follows it is a path.
     */
    #[Test]
    public function test_the_option_takes_no_value(): void
    {
        $result = (new LintArgumentParser())->parse(['--report-dynamic', 'lib']);

        $this->assertNull($result->error);
        $this->assertInstanceOf(LintArguments::class, $result->arguments);
        $this->assertTrue($result->arguments->reportDynamic);
        $this->assertSame(['lib'], $result->arguments->paths);
    }

    /**
     * The two reports are set apart: one does not move the other.
     */
    #[Test]
    public function test_the_report_leaves_the_report_of_the_markers_as_it_is(): void
    {
        $result = (new LintArgumentParser())->parse(['--report-dynamic']);
        $this->assertInstanceOf(LintArguments::class, $result->arguments);
        $this->assertTrue($result->arguments->reportUnmatchedIgnores);

        $result = (new LintArgumentParser())->parse(['--no-report-unmatched-ignores']);
        $this->assertInstanceOf(LintArguments::class, $result->arguments);
        $this->assertFalse($result->arguments->reportDynamic);
    }

    private function argumentsFrom(string $json): LintArguments
    {
        $loaded = (new LintConfigLoader())->load($this->makeProject(['regex.json' => $json]));
        $this->assertNull($loaded->error);

        return LintArguments::fromDefaults((new LintDefaultsBuilder())->build($loaded->config));
    }
}
