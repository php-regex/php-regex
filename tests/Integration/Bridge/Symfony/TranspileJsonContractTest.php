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
        JsonContract::assertShape($address, JsonContract::decodeDocument($symfony));
        $this->assertSame($cli, $symfony);
    }

    /**
     * @return iterable<string, array{pattern: string, target: string, exitCode: int, address: string}>
     */
    public static function provideTranspilations(): iterable
    {
        yield 'a valid pattern, javascript' => ['pattern' => '/a+b/i', 'target' => 'javascript', 'exitCode' => 0, 'address' => 'transpile'];
        yield 'a note, javascript' => ['pattern' => '/(?<n>a)\k<n>/x', 'target' => 'javascript', 'exitCode' => 0, 'address' => 'transpile'];
        yield 'a warning, python' => ['pattern' => '/a+/U', 'target' => 'python', 'exitCode' => 0, 'address' => 'transpile'];
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
        JsonContract::assertShape($address, JsonContract::decodeDocument($bridge));
        $this->assertSame($cli, $bridge);
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
}
