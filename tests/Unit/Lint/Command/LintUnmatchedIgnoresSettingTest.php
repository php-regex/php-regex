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
 * reportUnmatchedIgnores in regex.json and its two options: on unless the
 * file or the command line turns it off, the last option given winning,
 * and kept whatever else the command line adds.
 */
final class LintUnmatchedIgnoresSettingTest extends TestCase
{
    use TemporaryProject;

    protected function tearDown(): void
    {
        $this->removeTemporaryProjects();
    }

    #[Test]
    public function test_the_report_is_on_by_default(): void
    {
        $this->assertTrue(LintArguments::fromDefaults([])->reportUnmatchedIgnores);
        $this->assertTrue($this->argumentsFrom('{}')->reportUnmatchedIgnores);
    }

    #[Test]
    public function test_a_default_that_is_no_boolean_keeps_the_report_on(): void
    {
        $this->assertTrue(LintArguments::fromDefaults(['reportUnmatchedIgnores' => 'no'])->reportUnmatchedIgnores);
        $this->assertFalse(LintArguments::fromDefaults(['reportUnmatchedIgnores' => false])->reportUnmatchedIgnores);
    }

    #[Test]
    public function test_regex_json_turns_the_report_off(): void
    {
        $this->assertFalse($this->argumentsFrom('{"reportUnmatchedIgnores": false}')->reportUnmatchedIgnores);
        $this->assertTrue($this->argumentsFrom('{"reportUnmatchedIgnores": true}')->reportUnmatchedIgnores);
    }

    #[Test]
    public function test_regex_json_refuses_a_value_that_is_no_boolean(): void
    {
        $loaded = (new LintConfigLoader())->load($this->makeProject(['regex.json' => '{"reportUnmatchedIgnores": "no"}']));

        $this->assertNotNull($loaded->error);
        $this->assertStringContainsString('reportUnmatchedIgnores', (string) $loaded->error);
    }

    /**
     * @return iterable<string, array{args: list<string>, defaults: array<string, mixed>, expected: bool}>
     */
    public static function provideOptions(): iterable
    {
        yield 'off' => ['args' => ['--no-report-unmatched-ignores'], 'defaults' => [], 'expected' => false];
        yield 'on over regex.json' => ['args' => ['--report-unmatched-ignores'], 'defaults' => ['reportUnmatchedIgnores' => false], 'expected' => true];
        yield 'the last one wins' => ['args' => ['--no-report-unmatched-ignores', '--report-unmatched-ignores'], 'defaults' => [], 'expected' => true];
        yield 'kept by another option' => ['args' => ['--no-report-unmatched-ignores', '--redos', '--jobs=2', '--no-optimize', '--format=json', '--quiet', '--baseline=b.json'], 'defaults' => [], 'expected' => false];
        yield 'regex.json kept by another option' => ['args' => ['--redos', '--no-lint', '--interop=nette-utils', '--verbose'], 'defaults' => ['reportUnmatchedIgnores' => false], 'expected' => false];
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
        $this->assertSame($expected, $result->arguments->reportUnmatchedIgnores);
    }

    private function argumentsFrom(string $json): LintArguments
    {
        $loaded = (new LintConfigLoader())->load($this->makeProject(['regex.json' => $json]));
        $this->assertNull($loaded->error);

        return LintArguments::fromDefaults((new LintDefaultsBuilder())->build($loaded->config));
    }
}
