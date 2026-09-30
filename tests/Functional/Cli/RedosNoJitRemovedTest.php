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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Cli\Command\AnalyzeCommand;
use RegexParser\Cli\Command\DebugCommand;
use RegexParser\Cli\GlobalOptions;
use RegexParser\Cli\Input;
use RegexParser\Cli\Output;
use RegexParser\ReDoS\ReDoSConfirmation;
use RegexParser\ReDoS\ReDoSConfirmOptions;

/**
 * The ReDoS confirmation always runs without the JIT: the option that
 * turned it off is refused with the reason, and nothing records a request
 * that can no longer change anything.
 */
final class RedosNoJitRemovedTest extends TestCase
{
    #[Test]
    #[DataProvider('provideCommands')]
    public function test_the_option_is_refused_with_the_reason(string $name): void
    {
        $command = 'analyze' === $name ? new AnalyzeCommand() : new DebugCommand();
        $input = new Input($name, ['/(a+)+$/', '--redos-mode=confirmed', '--redos-no-jit'], new GlobalOptions(false, false, false, false, null, null), []);

        ob_start();

        try {
            $exitCode = $command->run($input, new Output(false, false));
        } finally {
            $written = (string) ob_get_clean();
        }

        // A usage error, as every single-pattern command reports one.
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--redos-no-jit was removed in 2.0: the confirmation always runs without JIT', $written);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideCommands(): iterable
    {
        yield 'analyze' => ['analyze'];
        yield 'debug' => ['debug'];
    }

    #[Test]
    public function test_nothing_records_a_jit_request(): void
    {
        $this->assertFalse(property_exists(ReDoSConfirmOptions::class, 'disableJit'));
        $this->assertFalse(property_exists(ReDoSConfirmation::class, 'jitDisableRequested'));
        $this->assertArrayNotHasKey('jit_disable_requested', (new ReDoSConfirmation(false, [], '0', 1, 1, 1, 1.0))->jsonSerialize());
    }
}
