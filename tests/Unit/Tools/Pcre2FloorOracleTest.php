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

namespace PhpRegex\Tests\Unit\Tools;

use PhpRegex\Tests\TestUtils\Pcre2FloorOracle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the pure parts of the PCRE2 floor oracle: building the
 * pcre2test input line for a PHP pattern, reading the verdict back from
 * pcre2test's output, and recognising the one engine version it accepts.
 *
 * No binary is run here. Every expected line and output below was checked
 * with a PCRE2 10.40 pcre2test built from the release tarball (and, where
 * noted, with pcre2test 10.48): the line compiles the way PHP compiles the
 * same pattern, because PHP always adds PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK and
 * maps each pattern modifier to one pcre2test modifier.
 */
final class Pcre2FloorOracleTest extends TestCase
{
    /**
     * Verbatim "pcre2test -C" output of the PCRE2 10.40 build used as the
     * floor (built with --disable-shared --enable-unicode).
     */
    private const FLOOR_CONFIGURATION = <<<'EOT'
        PCRE2 version 10.40 2022-04-14
        Compiled with
          8-bit support
          UTF and UCP support (Unicode version 14.0.0)
          No just-in-time compiler support
          Default newline sequence is LF
          \R matches all Unicode newlines
          \C is supported
          Internal link size = 2
          Parentheses nest limit = 250
          Default heap limit = 20000000 kibibytes
          Default match limit = 10000000
          Default depth limit = 10000000
          pcre2test has neither libreadline nor libedit support

        EOT;

    private ?string $stubDirectory = null;

    protected function tearDown(): void
    {
        if (null !== $this->stubDirectory) {
            foreach (glob($this->stubDirectory.'/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($this->stubDirectory);
            $this->stubDirectory = null;
        }
    }

    /**
     * @return iterable<string, array{flags: string, modifiers: string}>
     */
    public static function provideFlagMappings(): iterable
    {
        yield 'no flag' => ['flags' => '', 'modifiers' => 'allow_lookaround_bsk'];
        yield 'i' => ['flags' => 'i', 'modifiers' => 'caseless,allow_lookaround_bsk'];
        yield 'm' => ['flags' => 'm', 'modifiers' => 'multiline,allow_lookaround_bsk'];
        yield 's' => ['flags' => 's', 'modifiers' => 'dotall,allow_lookaround_bsk'];
        yield 'x' => ['flags' => 'x', 'modifiers' => 'extended,allow_lookaround_bsk'];
        yield 'u sets UTF and UCP, as PHP does' => ['flags' => 'u', 'modifiers' => 'utf,ucp,allow_lookaround_bsk'];
        yield 'U' => ['flags' => 'U', 'modifiers' => 'ungreedy,allow_lookaround_bsk'];
        yield 'J' => ['flags' => 'J', 'modifiers' => 'dupnames,allow_lookaround_bsk'];
        yield 'D' => ['flags' => 'D', 'modifiers' => 'dollar_endonly,allow_lookaround_bsk'];
        yield 'A' => ['flags' => 'A', 'modifiers' => 'anchored,allow_lookaround_bsk'];
        yield 'n' => ['flags' => 'n', 'modifiers' => 'no_auto_capture,allow_lookaround_bsk'];
        yield 'several flags keep the order of the flag string' => ['flags' => 'JUiu', 'modifiers' => 'dupnames,ungreedy,caseless,utf,ucp,allow_lookaround_bsk'];
    }

    #[Test]
    #[DataProvider('provideFlagMappings')]
    public function test_pcre2test_line_maps_each_php_flag(string $flags, string $modifiers): void
    {
        $this->assertSame('/abc/'.$modifiers, Pcre2FloorOracle::pcre2testLine('abc', '/', $flags));
    }

    #[Test]
    public function test_pcre2test_line_reads_a_doubled_x_as_extended(): void
    {
        // PHP reads "xx" as "x" given twice: preg_match('/[a b]/xx', ' ')
        // returns 1 like "/x", while pcre2test's extended_more ignores the
        // space inside the class ("/[a b]/extended_more" gives "No match" on
        // 10.40 and 10.48). The extractor never emits "xx" (its flags are
        // deduplicated), so this guards hand-built input.
        $line = Pcre2FloorOracle::pcre2testLine('[a b]', '/', 'xx');

        $this->assertStringStartsWith('/[a b]/', $line);
        $modifiers = explode(',', substr($line, \strlen('/[a b]/')));

        $this->assertNotContains('extended_more', $modifiers);
        $this->assertSame(['extended', 'allow_lookaround_bsk'], array_values(array_unique($modifiers)));
    }

    /**
     * PHP flags with no pcre2test mapping: "r" (PHP 8.4, caseless_restrict),
     * "S" (accepted and ignored by PHP), "e" (removed from PHP). A case
     * carrying one must never be compiled in a context that silently drops
     * it.
     *
     * @return iterable<string, array{flags: string}>
     */
    public static function provideUnmappedFlags(): iterable
    {
        yield 'r' => ['flags' => 'r'];
        yield 'S' => ['flags' => 'S'];
        yield 'e' => ['flags' => 'e'];
        yield 'unmapped after a mapped flag' => ['flags' => 'ir'];
    }

    #[Test]
    #[DataProvider('provideUnmappedFlags')]
    public function test_pcre2test_line_refuses_an_unmapped_flag(string $flags): void
    {
        $this->expectException(\RuntimeException::class);

        Pcre2FloorOracle::pcre2testLine('abc', '/', $flags);
    }

    /**
     * @return iterable<string, array{body: string, delimiter: string, line: string}>
     */
    public static function provideDelimiterLines(): iterable
    {
        // pcre2test, like PHP, hands "\/" to PCRE2 as written: "/a\/b/"
        // matches "a/b" on 10.40. Escaping it again would shift offsets.
        yield 'escaped delimiter stays as written' => ['body' => 'a\/b', 'delimiter' => '/', 'line' => '/a\/b/allow_lookaround_bsk'];

        yield 'original delimiter is kept' => ['body' => 'a/b', 'delimiter' => '!', 'line' => '!a/b!allow_lookaround_bsk'];

        yield 'a newline in the body is kept, pcre2test reads the continuation line' => [
            'body' => "a\nb",
            'delimiter' => '/',
            'line' => "/a\nb/allow_lookaround_bsk",
        ];

        yield 'empty body' => ['body' => '', 'delimiter' => '/', 'line' => '//allow_lookaround_bsk'];
    }

    #[Test]
    #[DataProvider('provideDelimiterLines')]
    public function test_pcre2test_line_keeps_the_body_verbatim(string $body, string $delimiter, string $line): void
    {
        $this->assertSame($line, Pcre2FloorOracle::pcre2testLine($body, $delimiter, ''));
    }

    /**
     * @return iterable<string, array{output: string, expected: array{verdict: string, offset: int|null, pcre2Code: int|null}}>
     */
    public static function provideOutputs(): iterable
    {
        // pcre2test -q output on 10.40: the echoed line, then the failure.
        yield '10.40 rejection' => [
            'output' => "/[abc/allow_lookaround_bsk\nFailed: error 106 at offset 4: missing terminating ] for character class\n\n",
            'expected' => ['verdict' => 'reject', 'offset' => 4, 'pcre2Code' => 106],
        ];

        // 10.48 adds a "here:" line under the failure.
        yield '10.48 rejection with its here line' => [
            'output' => "/a{2,1}/allow_lookaround_bsk\nFailed: error 104 at offset 5: numbers out of order in {} quantifier\n        here: a{2,1 |<--| }\n\n",
            'expected' => ['verdict' => 'reject', 'offset' => 5, 'pcre2Code' => 104],
        ];

        yield 'compiled pattern is echoed only' => [
            'output' => "/(?<=b\\Kc)d/allow_lookaround_bsk\n\n",
            'expected' => ['verdict' => 'accept', 'offset' => null, 'pcre2Code' => null],
        ];

        yield 'compiled multi-line pattern' => [
            'output' => "/a\nb/allow_lookaround_bsk\n\n",
            'expected' => ['verdict' => 'accept', 'offset' => null, 'pcre2Code' => null],
        ];
    }

    /**
     * @param array{verdict: string, offset: int|null, pcre2Code: int|null} $expected
     */
    #[Test]
    #[DataProvider('provideOutputs')]
    public function test_parse_output_reads_the_compile_verdict(string $output, array $expected): void
    {
        $this->assertSame($expected, Pcre2FloorOracle::parseOutput($output));
    }

    /**
     * Output that carries no compile verdict: a pcre2test diagnostic about
     * the input line itself, or nothing at all. Guessing "accept" there
     * would record a floor verdict nobody observed.
     *
     * @return iterable<string, array{output: string}>
     */
    public static function provideOutputsWithoutVerdict(): iterable
    {
        // Verbatim 10.40 output for an input line it cannot read.
        yield 'unknown modifier diagnostic' => ['output' => "/abc/no_such_modifier\n** Unrecognized modifier 'o' in 'no_such_modifier'\n\n"];
        yield 'empty output' => ['output' => ''];

        // A diagnostic about the line must win over a failure line in the
        // same segment: the failure may belong to another pattern.
        yield 'diagnostic before a failure line' => [
            'output' => "/[x/allow_lookaround_bsk\n** Unrecognized modifier 'p' in 'pcre2-separator/'\nFailed: error 106 at offset 2: missing terminating ] for character class\n\n",
        ];

        yield 'diagnostic after a failure line' => [
            'output' => "/[x/allow_lookaround_bsk\nFailed: error 106 at offset 2: missing terminating ] for character class\n** Unrecognized modifier 'p' in 'pcre2-separator/'\n\n",
        ];
    }

    #[Test]
    #[DataProvider('provideOutputsWithoutVerdict')]
    public function test_parse_output_refuses_output_without_a_verdict(string $output): void
    {
        $this->expectException(\RuntimeException::class);

        Pcre2FloorOracle::parseOutput($output);
    }

    /**
     * What "pcre2test -version" prints.
     *
     * @return iterable<string, array{versionOutput: string, expected: bool}>
     */
    public static function provideVersionOutputs(): iterable
    {
        yield '10.40 build (verified output)' => ['versionOutput' => "PCRE2 version 10.40 2022-04-14\n", 'expected' => true];
        yield '10.40 without trailing newline' => ['versionOutput' => 'PCRE2 version 10.40 2022-04-14', 'expected' => true];
        yield '10.48 (verified output)' => ['versionOutput' => "PCRE2 version 10.48 2026-08-31\n", 'expected' => false];
        yield 'longer version sharing the prefix' => ['versionOutput' => "PCRE2 version 10.400 2030-01-01\n", 'expected' => false];
        yield 'older release' => ['versionOutput' => "PCRE2 version 10.39 2021-10-29\n", 'expected' => false];
        yield 'not a pcre2test' => ['versionOutput' => "grep (GNU grep) 3.11\n", 'expected' => false];
        yield 'empty' => ['versionOutput' => '', 'expected' => false];
    }

    #[Test]
    #[DataProvider('provideVersionOutputs')]
    public function test_accepts_only_the_floor_version(string $versionOutput, bool $expected): void
    {
        $this->assertSame($expected, Pcre2FloorOracle::acceptsVersionLine($versionOutput));
    }

    #[Test]
    public function test_accepts_the_floor_build_configuration(): void
    {
        $this->assertTrue(Pcre2FloorOracle::acceptsConfiguration(self::FLOOR_CONFIGURATION));
    }

    /**
     * One-line departures from the floor build that change how patterns
     * compile; the replacement lines are the ones pcre2test.c prints for
     * each build option.
     *
     * @return iterable<string, array{from: string, to: string}>
     */
    public static function provideConfigurationDepartures(): iterable
    {
        yield 'CRLF default newline' => ['from' => 'Default newline sequence is LF', 'to' => 'Default newline sequence is CRLF'];
        yield '\R limited to CR, LF, CRLF' => ['from' => '\R matches all Unicode newlines', 'to' => '\R matches CR, LF, or CRLF only'];
        yield 'link size 4' => ['from' => 'Internal link size = 2', 'to' => 'Internal link size = 4'];
        yield '\C disabled' => ['from' => '\C is supported', 'to' => '\C is not supported'];
        yield 'no Unicode' => ['from' => 'UTF and UCP support (Unicode version 14.0.0)', 'to' => 'No Unicode support'];
        yield 'lower parentheses nest limit' => ['from' => 'Parentheses nest limit = 250', 'to' => 'Parentheses nest limit = 220'];
    }

    #[Test]
    #[DataProvider('provideConfigurationDepartures')]
    public function test_refuses_a_floor_build_with_another_configuration(string $from, string $to): void
    {
        $configuration = self::FLOOR_CONFIGURATION;

        $this->assertStringContainsString($from, $configuration);

        $this->assertFalse(Pcre2FloorOracle::acceptsConfiguration(str_replace($from, $to, $configuration)));
    }

    #[Test]
    public function test_check_binary_accepts_the_floor_build(): void
    {
        $binary = $this->stub("PCRE2 version 10.40 2022-04-14\n", self::FLOOR_CONFIGURATION, []);

        $this->assertTrue(Pcre2FloorOracle::checkBinary($binary)[0]);
    }

    #[Test]
    public function test_check_binary_refuses_the_right_version_with_another_configuration(): void
    {
        $binary = $this->stub(
            "PCRE2 version 10.40 2022-04-14\n",
            str_replace('Default newline sequence is LF', 'Default newline sequence is CRLF', self::FLOOR_CONFIGURATION),
            [],
        );

        $this->assertFalse(Pcre2FloorOracle::checkBinary($binary)[0]);
    }

    #[Test]
    public function test_check_binary_refuses_another_version(): void
    {
        $binary = $this->stub("PCRE2 version 10.48 2026-08-31\n", self::FLOOR_CONFIGURATION, []);

        $this->assertFalse(Pcre2FloorOracle::checkBinary($binary)[0]);
    }

    #[Test]
    public function test_observe_all_reads_each_case_from_one_batch(): void
    {
        $rejected = Pcre2FloorOracle::pcre2testLine('[abc', '/', '');
        $binary = $this->stub("PCRE2 version 10.40 2022-04-14\n", self::FLOOR_CONFIGURATION, [
            'after' => [$rejected => "Failed: error 106 at offset 4: missing terminating ] for character class\n"],
        ]);

        $observations = Pcre2FloorOracle::observeAll($binary, [
            ['id' => 'sample:1', 'pattern' => 'abc', 'delimiter' => '/', 'flags' => ''],
            ['id' => 'sample:3', 'pattern' => '[abc', 'delimiter' => '/', 'flags' => ''],
            ['id' => 'sample:5', 'pattern' => 'abc', 'delimiter' => '/', 'flags' => 'i'],
        ]);

        $this->assertSame([
            'sample:1' => ['verdict' => 'accept', 'offset' => null, 'pcre2Code' => null],
            'sample:3' => ['verdict' => 'reject', 'offset' => 4, 'pcre2Code' => 106],
            'sample:5' => ['verdict' => 'accept', 'offset' => null, 'pcre2Code' => null],
        ], $observations);
    }

    #[Test]
    public function test_observe_all_refuses_a_batch_where_a_line_ran_into_the_next(): void
    {
        // Transcript of the real 10.40 pcre2test on this batch: "/ab(\/..."
        // ends in an escaped delimiter, so pcre2test keeps reading the next
        // lines as the same pattern until a line starting with "/", which it
        // then reads as that pattern's modifiers ("** Unrecognized modifier
        // 'p' ..."). The diagnostic lands after the next case's marker, and
        // "[x" still prints its own failure: taking the segments at face
        // value records a compile verdict for "ab(\" that was never observed.
        $binary = $this->stub("PCRE2 version 10.40 2022-04-14\n", self::FLOOR_CONFIGURATION, [
            'continues' => [Pcre2FloorOracle::pcre2testLine('ab(\\', '/', '')],
            'after' => [Pcre2FloorOracle::pcre2testLine('[x', '/', '') => "Failed: error 106 at offset 2: missing terminating ] for character class\n"],
        ]);

        $this->expectException(\RuntimeException::class);

        Pcre2FloorOracle::observeAll($binary, [
            ['id' => 'sample:1', 'pattern' => 'ab(\\', 'delimiter' => '/', 'flags' => ''],
            ['id' => 'sample:3', 'pattern' => '[x', 'delimiter' => '/', 'flags' => ''],
            ['id' => 'sample:5', 'pattern' => 'ok', 'delimiter' => '/', 'flags' => ''],
        ]);
    }

    #[Test]
    public function test_observe_all_refuses_a_segment_that_does_not_echo_the_line_written(): void
    {
        $written = Pcre2FloorOracle::pcre2testLine('ok', '/', '');
        $binary = $this->stub("PCRE2 version 10.40 2022-04-14\n", self::FLOOR_CONFIGURATION, [
            'echo' => [$written => '/ok/caseless,allow_lookaround_bsk'],
        ]);

        $this->expectException(\RuntimeException::class);

        Pcre2FloorOracle::observeAll($binary, [
            ['id' => 'sample:1', 'pattern' => 'abc', 'delimiter' => '/', 'flags' => ''],
            ['id' => 'sample:3', 'pattern' => 'ok', 'delimiter' => '/', 'flags' => ''],
        ]);
    }

    /**
     * Writes a stand-in pcre2test into a fresh temporary directory: a bash
     * launcher running a PHP script with no php.ini. "-version" and "-C"
     * print the canned texts; "-q infile outfile" echoes every input line to
     * outfile the way pcre2test does, appending the canned output after
     * listed lines ("after"), replacing the echo of listed lines ("echo"),
     * or, for listed lines ("continues"), reading the following lines as a
     * continuation of that pattern until a line starting with "/", which is
     * then reported as an unrecognized modifier list, as pcre2test does.
     *
     * @param array{after?: array<string, string>, echo?: array<string, string>, continues?: list<string>} $script
     */
    private function stub(string $version, string $configuration, array $script): string
    {
        $directory = sys_get_temp_dir().'/pcre2-stub-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $this->stubDirectory = $directory;

        file_put_contents($directory.'/version.txt', $version);
        file_put_contents($directory.'/configuration.txt', $configuration);
        file_put_contents($directory.'/script.json', json_encode($script, \JSON_THROW_ON_ERROR));
        file_put_contents($directory.'/pcre2test.php', <<<'PHP'
            <?php
            $directory = __DIR__;
            $arguments = array_slice($argv, 1);

            if (['-version'] === $arguments) {
                echo file_get_contents($directory.'/version.txt');
                exit(0);
            }

            if (['-C'] === $arguments) {
                echo file_get_contents($directory.'/configuration.txt');
                exit(1);
            }

            if (3 !== count($arguments) || '-q' !== $arguments[0]) {
                fwrite(STDERR, 'unexpected arguments: '.implode(' ', $arguments)."\n");
                exit(2);
            }

            $script = json_decode((string) file_get_contents($directory.'/script.json'), true);
            $lines = explode("\n", (string) file_get_contents($arguments[1]));

            if ('' === end($lines)) {
                array_pop($lines);
            }

            $output = '';
            $open = false;

            foreach ($lines as $line) {
                $output .= ($script['echo'][$line] ?? $line)."\n";

                if ($open) {
                    if (str_starts_with($line, '/')) {
                        $output .= sprintf("** Unrecognized modifier '%s' in '%s'\n", substr($line, 1, 1), substr($line, 1));
                        $open = false;
                    }

                    continue;
                }

                if (in_array($line, $script['continues'] ?? [], true)) {
                    $open = true;

                    continue;
                }

                $output .= $script['after'][$line] ?? '';
            }

            file_put_contents($arguments[2], $output);
            PHP);

        $launcher = $directory.'/pcre2test';
        file_put_contents($launcher, \sprintf("#!/usr/bin/env bash\nexec %s -n %s \"\$@\"\n", escapeshellarg(\PHP_BINARY), escapeshellarg($directory.'/pcre2test.php')));
        chmod($launcher, 0o755);

        return $launcher;
    }
}
