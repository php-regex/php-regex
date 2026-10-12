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

use PHPRegex\Linter\Config\LintArgumentParser;
use PHPRegex\Linter\Config\LintArguments;
use PHPRegex\Linter\Config\LintConfigLoader;
use PHPRegex\Linter\Config\LintDefaultsBuilder;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * checks.complexity in regex.json, from the file to the arguments of the
 * lint command: off with a threshold of 50 unless the file says otherwise,
 * and kept whatever the command line adds.
 */
final class LintComplexitySettingTest extends TestCase
{
    use TemporaryProject;

    #[Test]
    public function test_the_check_is_off_with_a_threshold_of_50_by_default(): void
    {
        $arguments = LintArguments::fromDefaults([]);

        $this->assertFalse($arguments->checkComplexity);
        $this->assertSame(50, $arguments->complexityThreshold);
    }

    #[Test]
    public function test_regex_json_turns_the_check_on_with_its_threshold(): void
    {
        $arguments = $this->argumentsFrom('{"checks": {"complexity": {"enabled": true, "threshold": 20}}}');

        $this->assertTrue($arguments->checkComplexity);
        $this->assertSame(20, $arguments->complexityThreshold);
    }

    #[Test]
    public function test_a_threshold_alone_does_not_turn_the_check_on(): void
    {
        $arguments = $this->argumentsFrom('{"checks": {"complexity": {"threshold": 20}}}');

        $this->assertFalse($arguments->checkComplexity);
        $this->assertSame(20, $arguments->complexityThreshold);
    }

    #[Test]
    public function test_enabled_alone_keeps_the_default_threshold(): void
    {
        $arguments = $this->argumentsFrom('{"checks": {"complexity": {"enabled": true}}}');

        $this->assertTrue($arguments->checkComplexity);
        $this->assertSame(50, $arguments->complexityThreshold);
    }

    /**
     * @return iterable<string, array{args: list<string>}>
     */
    public static function provideCommandLines(): iterable
    {
        yield 'a path' => ['args' => ['src']];
        yield 'a format' => ['args' => ['--format=json']];
        yield 'a verbosity' => ['args' => ['--quiet']];
        yield 'redos on' => ['args' => ['--redos']];
        yield 'redos off' => ['args' => ['--no-redos']];
        yield 'redos mode' => ['args' => ['--redos-mode=confirmed']];
        yield 'redos threshold' => ['args' => ['--redos-threshold=low']];
        yield 'lint off' => ['args' => ['--no-lint']];
        yield 'a rule' => ['args' => ['--enable-rule=charclass.single']];
        yield 'interop' => ['args' => ['--interop=nette-utils']];
        yield 'no interop' => ['args' => ['--no-interop']];
        yield 'a pattern function' => ['args' => ['--pattern-function=App\\Str::matches']];
        yield 'min savings' => ['args' => ['--min-savings=3']];
        yield 'no optimize' => ['args' => ['--no-optimize']];
        yield 'no validate' => ['args' => ['--no-validate']];
        yield 'jobs' => ['args' => ['--jobs=2']];
        yield 'exclude' => ['args' => ['--exclude=tests']];
        yield 'output' => ['args' => ['--output=report.json']];
        yield 'baseline' => ['args' => ['--baseline=baseline.json']];
        yield 'generate baseline' => ['args' => ['--generate-baseline=baseline.json']];
        yield 'json' => ['args' => ['--json']];
        yield 'redos mode, value apart' => ['args' => ['--redos-mode', 'confirmed']];
        yield 'redos threshold, value apart' => ['args' => ['--redos-threshold', 'low']];
        yield 'exclude, value apart' => ['args' => ['--exclude', 'tests']];
        yield 'min savings, value apart' => ['args' => ['--min-savings', '3']];
        yield 'jobs, value apart' => ['args' => ['--jobs', '2']];
        yield 'output, value apart' => ['args' => ['--output', 'report.json']];
    }

    /**
     * @param list<string> $args
     */
    #[Test]
    #[DataProvider('provideCommandLines')]
    public function test_the_command_line_keeps_the_setting_of_regex_json(array $args): void
    {
        $result = (new LintArgumentParser())->parse($args, ['checkComplexity' => true, 'complexityThreshold' => 20]);

        $this->assertNull($result->error);
        $this->assertInstanceOf(LintArguments::class, $result->arguments);
        $this->assertTrue($result->arguments->checkComplexity);
        $this->assertSame(20, $result->arguments->complexityThreshold);
    }

    /**
     * @return iterable<string, array{defaults: array<string, mixed>, enabled: bool, threshold: int}>
     */
    public static function provideUnreadableDefaults(): iterable
    {
        yield 'switch not a boolean' => ['defaults' => ['checkComplexity' => 'yes'], 'enabled' => false, 'threshold' => 50];
        yield 'threshold not an integer' => ['defaults' => ['checkComplexity' => true, 'complexityThreshold' => 'many'], 'enabled' => true, 'threshold' => 50];
        yield 'threshold zero' => ['defaults' => ['checkComplexity' => true, 'complexityThreshold' => 0], 'enabled' => true, 'threshold' => 50];
    }

    /**
     * @param array<string, mixed> $defaults
     */
    #[Test]
    #[DataProvider('provideUnreadableDefaults')]
    public function test_an_unreadable_default_falls_back(array $defaults, bool $enabled, int $threshold): void
    {
        $arguments = LintArguments::fromDefaults($defaults);

        $this->assertSame($enabled, $arguments->checkComplexity);
        $this->assertSame($threshold, $arguments->complexityThreshold);
    }

    private function argumentsFrom(string $json): LintArguments
    {
        $loaded = (new LintConfigLoader())->load($this->makeProject(['regex.json' => $json]));
        $this->assertNull($loaded->error);

        return LintArguments::fromDefaults((new LintDefaultsBuilder())->build($loaded->config));
    }
}
