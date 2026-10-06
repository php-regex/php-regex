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
use PHPRegex\Linter\Formatter\OutputConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LintArgumentParserTest extends TestCase
{
    public function test_parse_collects_flags_and_paths(): void
    {
        $parser = new LintArgumentParser();

        $result = $parser->parse([
            '--format', 'json',
            '--exclude', 'vendor',
            '--min-savings', '2',
            '--jobs', '3',
            '--no-redos',
            '--no-optimize',
            '--quiet',
            'src',
        ]);

        $this->assertNull($result->error);
        $this->assertFalse($result->help);
        $this->assertInstanceOf(LintArguments::class, $result->arguments);

        $arguments = $result->arguments;
        $this->assertSame(['src'], $arguments->paths);
        $this->assertSame(['vendor'], $arguments->exclude);
        $this->assertSame(2, $arguments->minSavings);
        $this->assertSame(3, $arguments->jobs);
        $this->assertSame('json', $arguments->format);
        $this->assertSame(OutputConfiguration::VERBOSITY_QUIET, $arguments->verbosity);
        $this->assertTrue($arguments->quiet);
        $this->assertFalse($arguments->checkRedos);
        $this->assertTrue($arguments->checkValidation);
        $this->assertFalse($arguments->checkOptimizations);
    }

    public function test_parse_defaults_to_the_composer_pcre_preset(): void
    {
        $parser = new LintArgumentParser();

        $result = $parser->parse(['src']);

        $this->assertNull($result->error);
        $this->assertInstanceOf(LintArguments::class, $result->arguments);
        $this->assertSame(['composer-pcre'], $result->arguments->interop);
        $this->assertSame([], $result->arguments->patternFunctions);
    }

    public function test_parse_reads_interop_presets(): void
    {
        $parser = new LintArgumentParser();

        $result = $parser->parse(['--interop=composer-pcre,nette-utils', 'src']);

        $this->assertNull($result->error);
        $this->assertSame(['composer-pcre', 'nette-utils'], $result->arguments?->interop);

        $result = $parser->parse(['--interop', 'spatie-regex', 'src']);

        $this->assertNull($result->error);
        $this->assertSame(['spatie-regex'], $result->arguments?->interop);
    }

    public function test_parse_disables_interop(): void
    {
        $parser = new LintArgumentParser();

        $this->assertSame([], $parser->parse(['--no-interop', 'src'])->arguments?->interop);
        $this->assertSame([], $parser->parse(['--interop=none', 'src'])->arguments?->interop);
    }

    public function test_parse_rejects_an_unknown_interop_preset(): void
    {
        $parser = new LintArgumentParser();

        $result = $parser->parse(['--interop=not-a-preset', 'src']);

        $this->assertNotNull($result->error);
        $this->assertStringContainsString('Invalid value for --interop', (string) $result->error);
    }

    public function test_parse_reports_missing_interop_value(): void
    {
        $parser = new LintArgumentParser();

        $result = $parser->parse(['--interop']);

        $this->assertSame('Missing value for --interop.', $result->error);
    }

    public function test_parse_collects_repeated_pattern_functions(): void
    {
        $parser = new LintArgumentParser();

        $result = $parser->parse([
            '--pattern-function=App\\Str::matches#1',
            '--pattern-function', 'regex_check',
            'src',
        ]);

        $this->assertNull($result->error);
        $this->assertSame(['App\\Str::matches#1', 'regex_check'], $result->arguments?->patternFunctions);
    }

    public function test_parse_reports_missing_pattern_function_value(): void
    {
        $parser = new LintArgumentParser();

        $result = $parser->parse(['--pattern-function']);

        $this->assertSame('Missing value for --pattern-function.', $result->error);
    }

    public function test_parse_supports_help_flag(): void
    {
        $parser = new LintArgumentParser();

        $result = $parser->parse(['--help']);

        $this->assertTrue($result->help);
        $this->assertNotInstanceOf(LintArguments::class, $result->arguments);
    }

    public function test_parse_reports_missing_format_value(): void
    {
        $parser = new LintArgumentParser();

        $result = $parser->parse(['--format']);

        $this->assertSame('Missing value for --format.', $result->error);
    }

    public function test_parse_reports_unknown_option(): void
    {
        $parser = new LintArgumentParser();

        $result = $parser->parse(['--unknown']);

        $this->assertSame('Unknown option: --unknown', $result->error);
    }

    /**
     * @param list<string> $args
     */
    #[Test]
    #[DataProvider('provideMissingBaselineValues')]
    public function test_parse_reports_a_missing_baseline_value(array $args, string $error): void
    {
        $result = (new LintArgumentParser())->parse($args);

        $this->assertSame($error, $result->error);
        $this->assertNotInstanceOf(LintArguments::class, $result->arguments);
    }

    /**
     * @return iterable<string, array{args: list<string>, error: string}>
     */
    public static function provideMissingBaselineValues(): iterable
    {
        yield '--generate-baseline as the last argument' => ['args' => ['src', '--generate-baseline'], 'error' => 'Missing value for --generate-baseline.'];
        yield '--generate-baseline followed by an option' => ['args' => ['--generate-baseline', '--quiet', 'src'], 'error' => 'Missing value for --generate-baseline.'];
        yield '--generate-baseline followed by an empty argument' => ['args' => ['--generate-baseline', '', 'src'], 'error' => 'Missing value for --generate-baseline.'];
        yield '--generate-baseline= with nothing after the sign' => ['args' => ['--generate-baseline=', 'src'], 'error' => 'Missing value for --generate-baseline.'];
        yield '--baseline as the last argument' => ['args' => ['src', '--baseline'], 'error' => 'Missing value for --baseline.'];
        yield '--baseline followed by an option' => ['args' => ['--baseline', '--quiet', 'src'], 'error' => 'Missing value for --baseline.'];
        yield '--baseline followed by an empty argument' => ['args' => ['--baseline', '', 'src'], 'error' => 'Missing value for --baseline.'];
        yield '--baseline= with nothing after the sign' => ['args' => ['--baseline=', 'src'], 'error' => 'Missing value for --baseline.'];
    }

    /**
     * The value is the next argument, or what follows the sign: after the
     * sign it may start with a dash, and the paths around it are kept.
     *
     * @param list<string> $args
     */
    #[Test]
    #[DataProvider('provideBaselineValues')]
    public function test_parse_reads_the_baseline_values(array $args, ?string $baseline, ?string $generateBaseline): void
    {
        $result = (new LintArgumentParser())->parse($args);

        $this->assertNull($result->error);
        $this->assertSame($baseline, $result->arguments?->baseline);
        $this->assertSame($generateBaseline, $result->arguments?->generateBaseline);
        $this->assertSame(['src', 'lib'], $result->arguments?->paths);
    }

    /**
     * @return iterable<string, array{args: list<string>, baseline: string|null, generateBaseline: string|null}>
     */
    public static function provideBaselineValues(): iterable
    {
        yield 'separate arguments' => ['args' => ['src', '--baseline', 'known.json', '--generate-baseline', 'new.json', 'lib'], 'baseline' => 'known.json', 'generateBaseline' => 'new.json'];
        yield 'equals signs' => ['args' => ['src', '--baseline=known.json', '--generate-baseline=new.json', 'lib'], 'baseline' => 'known.json', 'generateBaseline' => 'new.json'];
        yield 'a value after the sign that starts with a dash' => ['args' => ['src', '--baseline=-known.json', '--generate-baseline=-new.json', 'lib'], 'baseline' => '-known.json', 'generateBaseline' => '-new.json'];
        yield 'neither option' => ['args' => ['src', 'lib'], 'baseline' => null, 'generateBaseline' => null];
    }
}
