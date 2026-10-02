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

use PHPRegex\Cli\Command\GraphCommand;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Output;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pattern the parser refuses is a pattern the reader wrote: the graph
 * command answers with the parser's own words, not with the catch-all
 * failure of a graph that could not be drawn.
 */
final class GraphCommandParseErrorTest extends TestCase
{
    #[Test]
    public function test_a_parse_error_is_reported_as_a_parse_error(): void
    {
        $command = new GraphCommand();
        $input = new Input(
            'graph',
            ['/(a/'],
            new GlobalOptions(false, false, false, true, null, null),
            [],
        );
        $errorStream = \fopen('php://memory', 'w+');
        $this->assertNotFalse($errorStream);
        $output = new Output(false, false, errorStream: $errorStream);

        $exitCode = 0;
        $buffer = $this->captureOutput(static function () use ($command, $input, $output, &$exitCode): void {
            $exitCode = $command->run($input, $output);
        });

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Error: Expected ) at end of input', $buffer);
        $this->assertStringNotContainsString('Graph generation failed', $buffer);
    }

    /**
     * @param callable(): void $callback
     */
    private function captureOutput(callable $callback): string
    {
        \ob_start();
        $callback();

        return (string) \ob_get_clean();
    }
}
