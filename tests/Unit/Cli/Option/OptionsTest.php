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

namespace PHPRegex\Tests\Unit\Cli\Option;

use PHPRegex\Cli\Option\Arguments;
use PHPRegex\Cli\Option\Option;
use PHPRegex\Cli\Option\Options;
use PHPRegex\Cli\Option\ParsedOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase
{
    /**
     * @param list<string>                                                                                $args
     * @param array{options: list<array{string, ?string, bool}>, arguments: list<string>, error: ?string} $expected
     */
    #[Test]
    #[DataProvider('providePatternCommandLines')]
    public function test_a_pattern_command_line_reads_as_its_declaration_says(array $args, array $expected): void
    {
        $this->assertSame($expected, self::read(self::declaration(Arguments::Pattern)->parse($args)));
    }

    /**
     * @return iterable<string, array{args: list<string>, expected: array{options: list<array{string, ?string, bool}>, arguments: list<string>, error: ?string}}>
     */
    public static function providePatternCommandLines(): iterable
    {
        yield 'a flag' => ['args' => ['/a/', '--json'], 'expected' => self::parsed([['--json', null, false]], ['/a/'])];
        yield 'a value after "="' => ['args' => ['--format=json', '/a/'], 'expected' => self::parsed([['--format', 'json', true]], ['/a/'])];
        yield 'an empty value after "="' => ['args' => ['--format=', '/a/'], 'expected' => self::parsed([['--format', '', true]], ['/a/'])];
        yield 'a value holding "="' => ['args' => ['--format=a=b', '/a/'], 'expected' => self::parsed([['--format', 'a=b', true]], ['/a/'])];
        yield 'a value after a space' => ['args' => ['--format', 'json', '/a/'], 'expected' => self::parsed([['--format', 'json', false]], ['/a/'])];
        yield 'no value at the end' => ['args' => ['/a/', '--format'], 'expected' => self::parsed([], ['/a/'], 'Missing value for --format.')];
        yield 'an empty value after a space' => ['args' => ['/a/', '--format', ''], 'expected' => self::parsed([], ['/a/'], 'Missing value for --format.')];
        yield 'an option in place of the value' => ['args' => ['/a/', '--format', '-x'], 'expected' => self::parsed([], ['/a/'], 'Missing value for --format.')];
        yield 'the message of an option of its own' => ['args' => ['/a/', '--repeat'], 'expected' => self::parsed([], ['/a/'], 'Missing or invalid value for --repeat.')];
        yield 'a value taken whatever it is' => ['args' => ['/a/', '--interop', '-x'], 'expected' => self::parsed([['--interop', '-x', false]], ['/a/'])];
        yield 'an empty value taken whatever it is' => ['args' => ['/a/', '--interop', ''], 'expected' => self::parsed([['--interop', '', false]], ['/a/'])];
        yield 'no value to take' => ['args' => ['/a/', '--interop'], 'expected' => self::parsed([], ['/a/'], 'Missing value for --interop.')];
        yield 'a value after "=" only' => ['args' => ['--rule=x', '/a/'], 'expected' => self::parsed([['--rule', 'x', true]], ['/a/'])];
        yield 'a value after "=" only, given after a space' => ['args' => ['--rule', 'x', '/a/'], 'expected' => self::parsed([], [], 'Unknown option: --rule')];
        yield 'a flag given a value' => ['args' => ['/a/', '--json=1'], 'expected' => self::parsed([], ['/a/'], 'Unknown option: --json=1')];
        yield 'a short alias' => ['args' => ['-j', '2', '/a/'], 'expected' => self::parsed([['--jobs', '2', false]], ['/a/'])];
        yield 'a short alias given "="' => ['args' => ['-j=2', '/a/'], 'expected' => self::parsed([], [], 'Unknown option: -j=2')];
        yield 'a hidden option' => ['args' => ['/a/', '--quiet'], 'expected' => self::parsed([['--quiet', null, false]], ['/a/'])];
        yield 'an option the application reads' => ['args' => ['/a/', '--php-version=8.1'], 'expected' => self::parsed([], ['/a/'], 'Unknown option: --php-version=8.1')];
        yield 'an unknown option' => ['args' => ['/a/', '--bogus'], 'expected' => self::parsed([], ['/a/'], 'Unknown option: --bogus')];
        yield 'an unknown short option' => ['args' => ['/a/', '-x'], 'expected' => self::parsed([], ['/a/'], 'Unknown option: -x')];
        yield 'a dash alone' => ['args' => ['/a/', '-'], 'expected' => self::parsed([], ['/a/'], 'Unknown option: -')];
        yield 'the end of the options' => ['args' => ['--', '-x'], 'expected' => self::parsed([], ['-x'])];
        yield 'options after the end' => ['args' => ['--', '/a/', '--json'], 'expected' => self::parsed([], ['/a/'], 'Unexpected argument: --json')];
        yield 'a second end' => ['args' => ['--', '--'], 'expected' => self::parsed([], ['--'])];
        yield 'the options read before an error' => ['args' => ['--json', '--format=x', '--bogus', '--format=y'], 'expected' => self::parsed([['--json', null, false], ['--format', 'x', true]], [], 'Unknown option: --bogus')];
        yield 'every occurrence in order' => ['args' => ['--format=a', '/a/', '--format', 'b'], 'expected' => self::parsed([['--format', 'a', true], ['--format', 'b', false]], ['/a/'])];
        yield 'a second pattern' => ['args' => ['/a/', '/b/', '--bogus'], 'expected' => self::parsed([], ['/a/'], 'Unexpected argument: /b/')];
        yield 'an empty pattern leaves its place' => ['args' => ['', '/a/'], 'expected' => self::parsed([], ['/a/'])];
        yield 'an empty second pattern' => ['args' => ['/a/', ''], 'expected' => self::parsed([], ['/a/'], 'Unexpected argument: ')];
        yield 'no pattern' => ['args' => ['--json'], 'expected' => self::parsed([['--json', null, false]], [], 'Missing pattern.')];
        yield 'only an empty pattern' => ['args' => [''], 'expected' => self::parsed([], [], 'Missing pattern.')];
    }

    /**
     * @param list<string>                                                                                $args
     * @param array{options: list<array{string, ?string, bool}>, arguments: list<string>, error: ?string} $expected
     */
    #[Test]
    #[DataProvider('provideOtherReadings')]
    public function test_the_arguments_a_command_takes_set_how_its_command_line_reads(Arguments $arguments, array $args, array $expected): void
    {
        $this->assertSame($expected, self::read(self::declaration($arguments)->parse($args)));
    }

    /**
     * @return iterable<string, array{arguments: Arguments, args: list<string>, expected: array{options: list<array{string, ?string, bool}>, arguments: list<string>, error: ?string}}>
     */
    public static function provideOtherReadings(): iterable
    {
        yield 'a loose pattern starting with one dash' => ['arguments' => Arguments::LoosePattern, 'args' => ['-x'], 'expected' => self::parsed([], ['-x'])];
        yield 'a loose pattern, an unknown option' => ['arguments' => Arguments::LoosePattern, 'args' => ['/a/', '--x'], 'expected' => self::parsed([], ['/a/'], 'Unknown option: --x')];
        yield 'a loose pattern, empty, takes its place' => ['arguments' => Arguments::LoosePattern, 'args' => ['', '/a/'], 'expected' => self::parsed([], [''], 'Unexpected argument: /a/')];
        yield 'a loose pattern, empty' => ['arguments' => Arguments::LoosePattern, 'args' => [''], 'expected' => self::parsed([], [''], 'Missing pattern.')];
        yield 'a loose pattern after the end' => ['arguments' => Arguments::LoosePattern, 'args' => ['--', '--x'], 'expected' => self::parsed([], ['--x'])];
        yield 'patterns, every one kept' => ['arguments' => Arguments::Patterns, 'args' => ['/a/', '-x', '/b/', '/c/'], 'expected' => self::parsed([], ['/a/', '-x', '/b/', '/c/'])];
        yield 'patterns, none' => ['arguments' => Arguments::Patterns, 'args' => [], 'expected' => self::parsed([], [])];
        yield 'patterns, an unknown option' => ['arguments' => Arguments::Patterns, 'args' => ['/a/', '--x'], 'expected' => self::parsed([], ['/a/'], 'Unknown option: --x')];
        yield 'patterns after the end' => ['arguments' => Arguments::Patterns, 'args' => ['--', '--x', '--'], 'expected' => self::parsed([], ['--x', '--'])];
        yield 'paths' => ['arguments' => Arguments::Paths, 'args' => ['src', '', 'tests'], 'expected' => self::parsed([], ['src', '', 'tests'])];
        yield 'paths, no end of the options' => ['arguments' => Arguments::Paths, 'args' => ['src', '--'], 'expected' => self::parsed([], ['src'], 'Unknown option: --')];
        yield 'paths, an unknown short option' => ['arguments' => Arguments::Paths, 'args' => ['-x'], 'expected' => self::parsed([], [], 'Unknown option: -x')];
        yield 'none' => ['arguments' => Arguments::None, 'args' => [], 'expected' => self::parsed([], [])];
        yield 'none, an argument' => ['arguments' => Arguments::None, 'args' => ['x', '--y'], 'expected' => self::parsed([], [], 'Unexpected argument: x')];
        yield 'none, an empty argument' => ['arguments' => Arguments::None, 'args' => [''], 'expected' => self::parsed([], [], 'Unexpected argument: ')];
        yield 'none, an option first' => ['arguments' => Arguments::None, 'args' => ['--', 'x'], 'expected' => self::parsed([], [], 'Unknown option: --')];
        yield 'a command name' => ['arguments' => Arguments::Command, 'args' => ['lint'], 'expected' => self::parsed([], ['lint'])];
        yield 'a command name, empty' => ['arguments' => Arguments::Command, 'args' => [''], 'expected' => self::parsed([], [''])];
        yield 'a command name, none' => ['arguments' => Arguments::Command, 'args' => [], 'expected' => self::parsed([], [])];
        yield 'a command name, a second one' => ['arguments' => Arguments::Command, 'args' => ['lint', 'x'], 'expected' => self::parsed([], ['lint'], 'Unexpected argument: x')];
        yield 'a command name, an option' => ['arguments' => Arguments::Command, 'args' => ['lint', '-x'], 'expected' => self::parsed([], ['lint'], 'Unknown option: -x')];
        yield 'passing through what is not declared' => ['arguments' => Arguments::PassThrough, 'args' => ['lint', '--bogus', '--php-version', '8.1', '--', '-x', '--quiet=1'], 'expected' => self::parsed([['--php-version', '8.1', false]], ['lint', '--bogus', '--', '-x', '--quiet=1'])];
        yield 'passing through, every option read' => ['arguments' => Arguments::PassThrough, 'args' => ['--json', '--', '--quiet'], 'expected' => self::parsed([['--json', null, false], ['--quiet', null, false]], ['--'])];
        yield 'passing through, a missing value' => ['arguments' => Arguments::PassThrough, 'args' => ['a', '--php-version', '--json', 'b'], 'expected' => self::parsed([], ['a'], 'Missing value for --php-version.')];
        yield 'passing through, an empty value' => ['arguments' => Arguments::PassThrough, 'args' => ['--php-version='], 'expected' => self::parsed([['--php-version', '', true]], [])];
    }

    #[Test]
    public function test_the_help_lists_the_options_shown_in_their_spelling(): void
    {
        $this->assertSame([
            ['--format <format>', 'Output format'],
            ['--json', 'Same as --format=json'],
            ['--repeat <n>', 'Repeat the input'],
            ['--interop <presets>', 'Libraries'],
            ['--rule=<id>', 'A rule'],
            ['-j, --jobs <n>', 'Workers'],
            ['-v, --verbose', 'Detailed output'],
            ['--php-version <ver>', 'Target PHP version'],
        ], self::declaration(Arguments::Pattern)->help());
    }

    #[Test]
    public function test_the_usage_names_the_arguments_a_command_takes(): void
    {
        $this->assertSame(['<pattern>'], self::declaration(Arguments::Pattern)->arguments());
        $this->assertSame(['<pattern>'], self::declaration(Arguments::LoosePattern)->arguments());
        $this->assertSame(['<pattern1>', '<pattern2>'], self::declaration(Arguments::Patterns)->arguments());
        $this->assertSame(['<path>'], self::declaration(Arguments::Paths)->arguments());
        $this->assertSame(['[command]'], self::declaration(Arguments::Command)->arguments());
        $this->assertSame([], self::declaration(Arguments::None)->arguments());
        $this->assertSame([], self::declaration(Arguments::PassThrough)->arguments());
    }

    #[Test]
    public function test_a_declaration_keeps_the_usage_line_of_its_errors(): void
    {
        $this->assertSame("Usage: regex test <pattern>\n", self::declaration(Arguments::Pattern)->usage);
        $this->assertSame('', (new Options(Arguments::None, []))->usage);
    }

    #[Test]
    public function test_the_parsed_command_line_answers_by_option_name(): void
    {
        $parsed = self::declaration(Arguments::Pattern)->parse(['--format=a', '/a/', '--json', '--format', 'b', '-j', '3']);

        $this->assertTrue($parsed->has('--json'));
        $this->assertTrue($parsed->has('--jobs'));
        $this->assertFalse($parsed->has('--verbose'));
        $this->assertSame('b', $parsed->value('--format'));
        $this->assertSame('3', $parsed->value('--jobs'));
        $this->assertNull($parsed->value('--json'));
        $this->assertNull($parsed->value('--repeat'));
        $this->assertSame('/a/', $parsed->argument());
        $this->assertSame('', self::declaration(Arguments::Pattern)->parse([])->argument());
    }

    #[Test]
    public function test_an_option_describes_how_it_is_spelled(): void
    {
        $jobs = Option::value('--jobs', '<n>', 'Workers', ['-j']);

        $this->assertSame(['-j', '--jobs'], $jobs->names());
        $this->assertSame('-j, --jobs <n>', $jobs->label());
        $this->assertSame('Missing value for --jobs.', $jobs->missingValue());
        $this->assertSame('Missing or invalid value for --jobs.', $jobs->whenMissing('Missing or invalid value for --jobs.')->missingValue());
        $this->assertFalse($jobs->hidden);
        $this->assertTrue($jobs->hide()->hidden);
        $this->assertFalse($jobs->global);
        $this->assertTrue($jobs->readByTheApplication()->global);
        $this->assertSame('--rule=<id>', Option::value('--rule', '<id>', 'A rule')->attachedOnly()->label());
        $this->assertSame('--quiet', Option::flag('--quiet')->label());
    }

    private static function declaration(Arguments $arguments): Options
    {
        return new Options($arguments, [
            Option::value('--format', '<format>', 'Output format'),
            Option::flag('--json', 'Same as --format=json'),
            Option::value('--repeat', '<n>', 'Repeat the input')->whenMissing('Missing or invalid value for --repeat.'),
            Option::value('--interop', '<presets>', 'Libraries')->takingAnyValue(),
            Option::value('--rule', '<id>', 'A rule')->attachedOnly(),
            Option::value('--jobs', '<n>', 'Workers', ['-j']),
            Option::flag('--verbose', 'Detailed output', ['-v']),
            Option::flag('--quiet')->hide(),
            Option::value('--php-version', '<ver>', 'Target PHP version')->readByTheApplication(),
        ], "Usage: regex test <pattern>\n");
    }

    /**
     * @param list<array{string, ?string, bool}> $options
     * @param list<string>                       $arguments
     *
     * @return array{options: list<array{string, ?string, bool}>, arguments: list<string>, error: ?string}
     */
    private static function parsed(array $options, array $arguments, ?string $error = null): array
    {
        return ['options' => $options, 'arguments' => $arguments, 'error' => $error];
    }

    /**
     * @return array{options: list<array{string, ?string, bool}>, arguments: list<string>, error: ?string}
     */
    private static function read(ParsedOptions $parsed): array
    {
        $options = [];
        foreach ($parsed->options as $option) {
            $options[] = [$option->name, $option->value, $option->attached];
        }

        return self::parsed($options, $parsed->arguments, $parsed->error);
    }
}
