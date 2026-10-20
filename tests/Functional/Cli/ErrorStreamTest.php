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

namespace PHPRegex\Tests\Functional\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An error goes to stderr, so a redirection keeps it out of the output it
 * captures: `regex diagram ... --format=svg > out.svg` never writes the
 * message into out.svg.
 */
final class ErrorStreamTest extends TestCase
{
    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideFailingRuns')]
    public function test_an_error_is_written_to_stderr_only(array $arguments, string $message): void
    {
        $command = [\PHP_BINARY, \dirname(__DIR__, 3).'/src/Cli/bin/regex', '--no-ansi', ...$arguments];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(2, proc_close($process), $stdout.$stderr);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString($message, $stderr);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function provideFailingRuns(): iterable
    {
        yield 'an unknown format' => [['diagram', '/a/', '--format=bogus'], "Unsupported format 'bogus'"];
        yield 'an output file that cannot be written' => [['diagram', '/a/', '--format=svg', '--output=/nonexistent/dir/x.svg'], '/nonexistent/dir/x.svg'];
        yield 'an unknown option' => [['analyze', '/a/', '--bogus'], 'Unknown option: --bogus'];
        yield 'a missing pattern' => [['explain'], 'Error:'];
        yield 'an unknown transpile target' => [['transpile', '/a/', '--target=perl'], 'perl'];
        yield 'an unknown lint rule' => [['lint', '--disable-rule=quantifer.nested'], 'Unknown lint rule "quantifer.nested" in --disable-rule.'];
        yield 'an unknown lint rule, with the usage naming the rule options' => [['lint', '--enable-rule=nope.nope'], '[--enable-rule=<id>] [--disable-rule=<id>]'];
    }

    /**
     * A version the library refuses is named by the option the command line
     * gave it with, not by the library's own key.
     *
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideInvalidVersions')]
    public function test_an_invalid_version_names_its_command_line_option(array $arguments, string $message): void
    {
        $command = [\PHP_BINARY, \dirname(__DIR__, 3).'/src/Cli/bin/regex', '--no-ansi', ...$arguments];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $printed = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(2, proc_close($process), $printed);
        $this->assertStringContainsString($message, $printed);
        $this->assertStringNotContainsString('php_version', $printed);
        $this->assertStringNotContainsString('pcre_version', $printed);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function provideInvalidVersions(): iterable
    {
        yield 'a PHP version' => [['--php-version=abc', 'validate', '/a/'], 'Invalid option: --php-version must be a version string like "8.2"'];
        yield 'a PCRE2 release' => [['--pcre-version=abc', 'validate', '/a/'], 'Invalid option: --pcre-version must be a PCRE2 release like "10.44", not "abc".'];
        yield 'a PHP version, as JSON' => [['--php-version=abc', 'analyze', '/a/', '--json'], '"error": "Invalid option: --php-version must be a version string'];
        yield 'a PHP version, for lint' => [['--php-version=abc', 'lint', __FILE__, '--jobs=1'], 'Invalid option: --php-version must be a version string like "8.2"'];
        yield 'a PCRE2 release, for lint' => [['--pcre-version=abc', 'lint', __FILE__, '--jobs=1'], 'Invalid option: --pcre-version must be a PCRE2 release like "10.44", not "abc".'];
        yield 'a locale' => [['--locale=xx_YY.NOWHERE', 'validate', '/a/'], 'Invalid option: --locale names "xx_YY.NOWHERE", which this machine cannot set'];
        yield 'a locale beside a PHP version' => [['--locale=xx_YY.NOWHERE', '--php-version=8.4', 'validate', '/a/'], 'Invalid option: --locale names "xx_YY.NOWHERE", which this machine cannot set'];
        yield 'a locale, for lint' => [['--locale=xx_YY.NOWHERE', 'lint', __FILE__, '--jobs=1'], 'Invalid option: --locale names "xx_YY.NOWHERE", which this machine cannot set'];
    }
}
