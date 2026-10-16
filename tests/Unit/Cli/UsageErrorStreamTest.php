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

namespace PHPRegex\Tests\Unit\Cli;

use PHPRegex\Cli\Command\CommandInterface;
use PHPRegex\Cli\Command\CompareCommand;
use PHPRegex\Cli\Command\DiagramCommand;
use PHPRegex\Cli\Command\GraphCommand;
use PHPRegex\Cli\Command\HelpCommand;
use PHPRegex\Cli\Command\ParseCommand;
use PHPRegex\Cli\Command\RedosCommand;
use PHPRegex\Cli\Command\ValidateCommand;
use PHPRegex\Cli\Command\VersionCommand;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A usage error is written to the error stream and nothing to stdout, so a
 * redirected output never holds the message.
 */
final class UsageErrorStreamTest extends TestCase
{
    /**
     * @param list<string>                                       $arguments
     * @param array{php_version?: string, pcre_version?: string} $regexOptions
     */
    #[Test]
    #[DataProvider('provideUsageErrors')]
    public function test_a_usage_error_goes_to_the_error_stream(CommandInterface $command, string $name, array $arguments, array $regexOptions, string $message): void
    {
        $output = OutputFactory::create();
        $input = new Input($name, $arguments, new GlobalOptions(false, false, false, true, null, null), $regexOptions);

        ob_start();
        $exitCode = $command->run($input, $output);
        $stdout = (string) ob_get_clean();

        $this->assertSame(2, $exitCode, $stdout);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString($message, OutputFactory::errors($output));
    }

    /**
     * @return iterable<string, array{CommandInterface, string, list<string>, array<string, mixed>, string}>
     */
    public static function provideUsageErrors(): iterable
    {
        $unwritable = '/nonexistent/dir/out';

        yield 'compare, a missing pattern' => [new CompareCommand(), 'compare', ['/a/'], [], 'Usage: regex compare'];
        yield 'diagram, an SVG file that cannot be written' => [new DiagramCommand(), 'diagram', ['/a/', '--format=svg', '--output='.$unwritable.'.svg'], [], 'Unable to write SVG'];
        yield 'diagram, a text file that cannot be written' => [new DiagramCommand(), 'diagram', ['/a/', '--output='.$unwritable.'.txt'], [], 'Unable to write output'];
        yield 'graph, a file that cannot be written' => [new GraphCommand(), 'graph', ['/a/', '--output='.$unwritable.'.dot'], [], 'Unable to write the graph'];
        yield 'redos, an unknown option' => [new RedosCommand(), 'redos', ['/a/', '--bogus'], [], 'Error:'];
        yield 'version, an unknown option' => [new VersionCommand(), 'version', ['--bogus'], [], 'Unknown option: --bogus'];
        yield 'version, an argument' => [new VersionCommand(), 'version', ['extra'], [], 'Unexpected argument: extra'];
        yield 'help, an unknown option' => [new HelpCommand(), 'help', ['--bogus'], [], 'Unknown option: --bogus'];
        yield 'help, a second argument' => [new HelpCommand(), 'help', ['analyze', 'extra'], [], 'Unexpected argument: extra'];
        yield 'parse, a second pattern' => [new ParseCommand(), 'parse', ['/a/', '/b/'], [], 'Unexpected argument: /b/'];
        yield 'validate, an invalid library option' => [new ValidateCommand(), 'validate', ['/a/'], ['php_version' => 'abc'], 'Invalid option:'];
    }
}
