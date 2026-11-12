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

use PHPRegex\Cli\Command\AnalyzeCommand;
use PHPRegex\Cli\Command\DebugCommand;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Redos\Confirmation;
use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The ReDoS confirmation always runs without the JIT: no option turns it
 * off, --redos-no-jit is an unknown option like any other, and nothing
 * records a request that could change anything.
 */
final class RedosNoJitRemovedTest extends TestCase
{
    #[Test]
    #[DataProvider('provideCommands')]
    public function test_the_option_is_refused_as_an_unknown_one(string $name): void
    {
        $command = 'analyze' === $name ? new AnalyzeCommand() : new DebugCommand();
        $input = new Input($name, ['/(a+)+$/', '--redos-mode=confirmed', '--redos-no-jit'], new GlobalOptions(false, false, false, false, null, null), []);

        $output = OutputFactory::create();
        ob_start();

        try {
            $exitCode = $command->run($input, $output);
        } finally {
            $written = (string) ob_get_clean().OutputFactory::errors($output);
        }

        // A usage error, as every command reports one.
        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Unknown option: --redos-no-jit', $written);
        $this->assertStringNotContainsString('removed', $written);
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
        $this->assertFalse(property_exists(ConfirmationOptions::class, 'disableJit'));
        $this->assertFalse(property_exists(Confirmation::class, 'jitDisableRequested'));
        $this->assertArrayNotHasKey('jit_disable_requested', (new Confirmation(false, [], '0', 1, 1, 1, 1.0))->jsonSerialize());
    }
}
