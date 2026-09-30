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

namespace RegexParser\Tests\Functional\Cli;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Cli\Command\RedosCommand;
use RegexParser\Cli\GlobalOptions;
use RegexParser\Cli\Input;
use RegexParser\Cli\Output;

/**
 * "--time-limit" holds for the run only: the process gets its own limit
 * back, and a pattern PHP refuses is measured without a warning.
 */
final class RedosCommandTimeLimitTest extends TestCase
{
    private string $timeLimit = '';

    protected function setUp(): void
    {
        $this->timeLimit = (string) \ini_get('max_execution_time');
    }

    protected function tearDown(): void
    {
        set_time_limit((int) $this->timeLimit);
    }

    #[Test]
    public function test_the_time_limit_is_given_back(): void
    {
        [$exitCode] = $this->runCommand(['/a+$/', '--input', 'aaa', '--time-limit', '345']);

        $this->assertSame(0, $exitCode);
        $this->assertSame($this->timeLimit, \ini_get('max_execution_time'));
    }

    #[Test]
    public function test_a_refused_pattern_is_measured_without_a_warning(): void
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            [$exitCode, $buffer] = $this->runCommand(['/[a/', '--input', 'aaa', '--format=json']);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"err_code": '.\PREG_INTERNAL_ERROR, $buffer);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string}
     */
    private function runCommand(array $arguments): array
    {
        $input = new Input(
            'redos',
            [...$arguments, '--iterations', '1', '--warmup', '1'],
            new GlobalOptions(false, false, false, true, null, null),
            [],
        );

        $level = ob_get_level();
        ob_start();

        try {
            $exitCode = (new RedosCommand())->run($input, new Output(false, false));
            $buffer = (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return [$exitCode, $buffer];
    }
}
