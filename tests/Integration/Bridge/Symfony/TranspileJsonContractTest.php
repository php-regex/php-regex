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

namespace PHPRegex\Tests\Integration\Bridge\Symfony;

use PHPRegex\Symfony\Command\TranspileCommand;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\RunsInAnEmptyProject;
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * regex:transpile --format=json prints the very document the regex command
 * prints for the same pattern and target, its errors included.
 */
final class TranspileJsonContractTest extends TestCase
{
    use RunsInAnEmptyProject;
    use RunsRegexCli;

    #[Test]
    #[DataProvider('provideTranspilations')]
    public function test_transpile_json_is_the_cli_json(string $pattern, string $target, int $exitCode, string $address): void
    {
        [$cliExitCode, $cli] = $this->runRegex(['transpile', $pattern, '--target='.$target, '--format=json']);

        $tester = new CommandTester(new TranspileCommand(Regex::create()));
        $status = $tester->execute(['pattern' => $pattern, '--target' => $target, '--format' => 'json']);
        $symfony = $tester->getDisplay();

        $this->assertSame($exitCode, $cliExitCode, $cli);
        $this->assertSame($exitCode, $status, $symfony);
        JsonContract::assertShape($address, JsonContract::decodeDocument($cli));
        // The bridge judges one PHP version until it reads a range: its document
        // is the command line's without the range the command line judged.
        $this->assertSame(self::withoutRange(JsonContract::decodeDocument($cli)), JsonContract::decodeDocument($symfony));
    }

    /**
     * @return iterable<string, array{pattern: string, target: string, exitCode: int, address: string}>
     */
    public static function provideTranspilations(): iterable
    {
        yield 'a valid pattern, javascript' => ['pattern' => '/a+b/i', 'target' => 'javascript', 'exitCode' => 0, 'address' => 'transpile'];
        yield 'a note, javascript' => ['pattern' => '/(?<n>a)\k<n>/x', 'target' => 'javascript', 'exitCode' => 0, 'address' => 'transpile'];
        yield 'a warning, python' => ['pattern' => '/a+/U', 'target' => 'python', 'exitCode' => 0, 'address' => 'transpile'];
        // A set that takes a byte in PCRE without u, and the same set under u, as the test of the warning runs them in PCRE.
        yield 'a set past ASCII without u' => ['pattern' => '/^.,[^,]+$/', 'target' => 'javascript', 'exitCode' => 0, 'address' => 'transpile'];
        yield 'a set past ASCII under u' => ['pattern' => '/^.,[^,]+$/u', 'target' => 'javascript', 'exitCode' => 0, 'address' => 'transpile'];
        yield 'a syntax error' => ['pattern' => '/(a/', 'target' => 'javascript', 'exitCode' => 1, 'address' => 'error'];
        yield 'a semantic error' => ['pattern' => '/(?<=a+)b/', 'target' => 'javascript', 'exitCode' => 1, 'address' => 'error'];
        yield 'a construct the target lacks' => ['pattern' => '/a++b/', 'target' => 'javascript', 'exitCode' => 1, 'address' => 'error'];
        // Without u a lone byte has no equivalent in a target that reads characters.
        yield 'an invalid UTF-8 byte' => ['pattern' => "/a\xFF/", 'target' => 'javascript', 'exitCode' => 1, 'address' => 'error'];
        yield 'the js alias' => ['pattern' => '/a+b/i', 'target' => 'js', 'exitCode' => 0, 'address' => 'transpile'];
        yield 'the py alias' => ['pattern' => '/a+b/i', 'target' => 'py', 'exitCode' => 0, 'address' => 'transpile'];
        yield 'a target in upper case' => ['pattern' => '/a+b/i', 'target' => 'JS', 'exitCode' => 0, 'address' => 'transpile'];
        yield 'an unknown target' => ['pattern' => '/a+b/i', 'target' => 'ruby', 'exitCode' => 2, 'address' => 'error'];
        yield 'an unknown target, invalid pattern' => ['pattern' => '/(a/', 'target' => 'ruby', 'exitCode' => 2, 'address' => 'error'];
    }

    #[Test]
    #[DataProvider('provideFormatSpellings')]
    public function test_transpile_format_is_case_insensitive(string $format, string $pattern, string $target, int $exitCode, string $address): void
    {
        [$cliExitCode, $cli] = $this->runRegex(['transpile', $pattern, '--target='.$target, '--format='.$format]);

        $tester = new CommandTester(new TranspileCommand(Regex::create()));
        $status = $tester->execute(['pattern' => $pattern, '--target' => $target, '--format' => $format]);
        $bridge = $tester->getDisplay();

        $this->assertSame($exitCode, $cliExitCode, $cli);
        $this->assertSame($exitCode, $status, $bridge);
        JsonContract::assertShape($address, JsonContract::decodeDocument($cli));
        // The bridge judges one PHP version until it reads a range: its document
        // is the command line's without the range the command line judged.
        $this->assertSame(self::withoutRange(JsonContract::decodeDocument($cli)), JsonContract::decodeDocument($bridge));
    }

    /**
     * @return iterable<string, array{format: string, pattern: string, target: string, exitCode: int, address: string}>
     */
    public static function provideFormatSpellings(): iterable
    {
        yield 'upper case' => ['format' => 'JSON', 'pattern' => '/a+b/i', 'target' => 'javascript', 'exitCode' => 0, 'address' => 'transpile'];
        yield 'mixed case' => ['format' => 'Json', 'pattern' => '/a+b/i', 'target' => 'python', 'exitCode' => 0, 'address' => 'transpile'];
        yield 'upper case, invalid pattern' => ['format' => 'JSON', 'pattern' => '/(a/', 'target' => 'javascript', 'exitCode' => 1, 'address' => 'error'];
        yield 'upper case, unknown target' => ['format' => 'JSON', 'pattern' => '/a+b/i', 'target' => 'ruby', 'exitCode' => 2, 'address' => 'error'];
    }

    /**
     * Without u the dot takes one byte in PCRE and one character in the
     * target: the document carries the warning, and none under u.
     */
    #[Test]
    public function test_transpile_json_warns_of_a_set_that_takes_a_byte_without_u(): void
    {
        // Oracle: "é" is two bytes to the dot, and one character under u.
        $this->assertSame(0, preg_match('/^.,[^,]+$/', 'é,b'));
        $this->assertSame(1, preg_match('/^.,[^,]+$/u', 'é,b'));

        $tester = new CommandTester(new TranspileCommand(Regex::create()));
        $tester->execute(['pattern' => '/^.,[^,]+$/', '--target' => 'javascript', '--format' => 'json']);
        $document = JsonContract::decodeDocument($tester->getDisplay());
        $this->assertSame(['Without the u flag "." at offset 1 takes one byte in PCRE and one character in this target, so the two differ on a subject holding a character outside ASCII; under u, for UTF-8 subjects, they agree. Add u.'], $document['warnings'] ?? null);

        $tester->execute(['pattern' => '/^.,[^,]+$/u', '--target' => 'javascript', '--format' => 'json']);
        $underU = JsonContract::decodeDocument($tester->getDisplay());
        $this->assertSame([], $underU['warnings'] ?? null);
    }

    #[Test]
    public function test_transpile_invalid_pattern_prints_the_envelope(): void
    {
        $tester = new CommandTester(new TranspileCommand(Regex::create()));
        $status = $tester->execute(['pattern' => '/(?<=a+)b/', '--format' => 'json']);

        $this->assertSame(1, $status);
        $document = JsonContract::decodeDocument($tester->getDisplay());
        $this->assertSame('pattern', $document['stage'] ?? null);
        $this->assertIsString($document['error'] ?? null);
        $validation = $document['validation'] ?? null;
        $this->assertIsArray($validation);
        $this->assertSame('regex.lookbehind.unbounded', $validation['error_code'] ?? null);
        $this->assertSame(0, $validation['offset'] ?? null);
    }

    /**
     * A command-line transpile document without the PHP range it judged.
     *
     * @param array<mixed> $document
     *
     * @return array<mixed>
     */
    private static function withoutRange(array $document): array
    {
        unset($document['php_target'], $document['range']);

        return $document;
    }
}
