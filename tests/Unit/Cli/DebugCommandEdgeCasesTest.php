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

namespace PhpRegex\Tests\Unit\Cli;

use PhpRegex\Cli\Command\DebugCommand;
use PhpRegex\Cli\GlobalOptions;
use PhpRegex\Cli\Input;
use PhpRegex\Cli\Output;
use PHPUnit\Framework\TestCase;

final class DebugCommandEdgeCasesTest extends TestCase
{
    public function test_debug_command_renders_unknown_severity_details(): void
    {
        $command = new DebugCommand();
        $input = new Input(
            'debug',
            ['/(a/'],
            new GlobalOptions(false, false, false, true, null, null),
            [],
        );
        $output = new Output(false, false);

        $exitCode = 0;
        $buffer = $this->captureOutput(static function () use ($command, $input, $output, &$exitCode): void {
            $exitCode = $command->run($input, $output);
        });

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Error:', $buffer);
        $this->assertStringContainsString('UNKNOWN', $buffer);
    }

    /**
     * @param callable(): void $callback
     */
    private function captureOutput(callable $callback): string
    {
        ob_start();
        $callback();

        return (string) ob_get_clean();
    }
}
