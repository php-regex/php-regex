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

    #[Test]
    public function test_parse_defaults_to_every_preset(): void
    {
        $parser = new LintArgumentParser();

        $result = $parser->parse(['src']);

        $this->assertNull($result->error);
        $this->assertInstanceOf(LintArguments::class, $result->arguments);
        $this->assertSame(['composer-pcre', 'nette-utils', 'spatie-regex', 'illuminate-support', 'phpunit', 'webmozart-assert', 'beberlei-assert', 'symfony-validator'], $result->arguments->interop);
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

    /**
     * An unknown name is a warning, never fatal: the name is left out and
     * the run goes on. laravel-str, renamed illuminate-support before any
     * tag, is one.
     */
    #[Test]
    public function test_parse_ignores_an_unknown_interop_preset(): void
    {
        $parser = new LintArgumentParser();

        $result = $parser->parse(['--interop=not-a-preset,nette-utils,laravel-str', 'src']);

        $this->assertNull($result->error);
        $this->assertSame(['nette-utils'], $result->arguments?->interop);
    }

    /**
     * An explicit list means exactly that list, a preset added later
     * included.
     */
    /**
     * A --pattern-function spec the lint cannot read is left out with a
     * warning, as an unknown preset is: an index that is no number, a "$"
     * naming nothing or no parameter PHP could declare.
     */
    #[Test]
    public function test_parse_warns_about_a_malformed_pattern_function(): void
    {
        $result = (new LintArgumentParser())->parse(['--pattern-function=x#1$', '--pattern-function', 'x#1$1a', '--pattern-function=x#a', '--pattern-function=App\grep#1$expression', 'src']);

        $this->assertNull($result->error);
        $this->assertSame(['App\grep#1$expression'], $result->arguments?->patternFunctions);
        $this->assertSame([
            'Malformed pattern function "x#1$", ignored.',
            'Malformed pattern function "x#1$1a", ignored.',
            'Malformed pattern function "x#a", ignored.',
        ], $result->warnings);
    }

    #[Test]
    public function test_parse_reads_exactly_the_presets_listed(): void
    {
        $parser = new LintArgumentParser();

        $this->assertSame(['phpunit', 'symfony-validator'], $parser->parse(['--interop=phpunit,symfony-validator', 'src'])->arguments?->interop);
        $this->assertSame(['webmozart-assert'], $parser->parse(['src'], ['interop' => ['webmozart-assert']])->arguments?->interop);
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

    public function test_parse_tells_paths_given_from_configured_ones(): void
    {
        $parser = new LintArgumentParser();

        $configured = $parser->parse(['--format=json'], ['paths' => ['lib']]);
        $given = $parser->parse(['src', '--format', 'github'], ['paths' => ['lib']]);

        $this->assertFalse($configured->pathsGiven);
        $this->assertSame(['lib'], $configured->arguments?->paths);
        $this->assertSame('json', $configured->arguments->format);
        $this->assertTrue($given->pathsGiven);
        $this->assertSame(['src'], $given->arguments?->paths);
        $this->assertSame('github', $given->arguments->format);
    }

    /**
     * --baseline and --generate-baseline take their file after a space as
     * well as after "=", as the help's Options rows show.
     *
     * @param list<string> $args
     */
    #[Test]
    #[DataProvider('provideBaselineSpellings')]
    public function test_parse_reads_the_baseline_files_in_either_spelling(array $args): void
    {
        $result = (new LintArgumentParser())->parse($args);

        $this->assertNull($result->error);
        $this->assertSame('base.json', $result->arguments?->baseline);
        $this->assertSame('new.json', $result->arguments->generateBaseline);
        $this->assertSame(['src'], $result->arguments->paths);
    }

    /**
     * @return iterable<string, array{args: list<string>}>
     */
    public static function provideBaselineSpellings(): iterable
    {
        yield 'equals sign' => ['args' => ['--baseline=base.json', '--generate-baseline=new.json', 'src']];
        yield 'space' => ['args' => ['--baseline', 'base.json', '--generate-baseline', 'new.json', 'src']];
        yield 'space, after the path' => ['args' => ['src', '--generate-baseline', 'new.json', '--baseline', 'base.json']];
    }

    /**
     * The space form needs a file after it: nothing, or the next option,
     * is a missing value, as for --output. So is an empty "=" value.
     *
     * @param list<string> $args
     */
    #[Test]
    #[DataProvider('provideMissingBaselineFiles')]
    public function test_parse_reports_a_missing_baseline_file(array $args, string $error): void
    {
        $result = (new LintArgumentParser())->parse($args);

        $this->assertSame($error, $result->error);
        $this->assertNotInstanceOf(LintArguments::class, $result->arguments);
    }

    /**
     * @return iterable<string, array{args: list<string>, error: string}>
     */
    public static function provideMissingBaselineFiles(): iterable
    {
        yield '--baseline last' => ['args' => ['src', '--baseline'], 'error' => 'Missing value for --baseline.'];
        yield '--baseline before an option' => ['args' => ['--baseline', '--json', 'src'], 'error' => 'Missing value for --baseline.'];
        yield '--generate-baseline last' => ['args' => ['src', '--generate-baseline'], 'error' => 'Missing value for --generate-baseline.'];
        yield '--generate-baseline before an option' => ['args' => ['--generate-baseline', '-v', 'src'], 'error' => 'Missing value for --generate-baseline.'];
        yield '--baseline= with no file' => ['args' => ['src', '--baseline='], 'error' => 'Missing value for --baseline.'];
        yield '--generate-baseline= with no file' => ['args' => ['--generate-baseline=', 'src'], 'error' => 'Missing value for --generate-baseline.'];
    }
}
