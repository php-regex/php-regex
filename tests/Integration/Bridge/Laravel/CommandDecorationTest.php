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

namespace PHPRegex\Tests\Integration\Bridge\Laravel;

use Illuminate\Support\Facades\Artisan;
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The commands highlight a pattern only on a decorated output: under
 * --no-ansi, or piped, the highlighter's escape sequences were written
 * as they are.
 */
final class CommandDecorationTest extends LaravelTestCase
{
    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('provideCommands')]
    public function test_an_undecorated_output_carries_no_escape_sequence(string $command, array $arguments, string $pattern): void
    {
        $output = $this->commandOutput($command, $arguments, false);

        $this->assertStringNotContainsString("\e[", $output);
        $this->assertStringContainsString('  '.$pattern, $output);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('provideCommands')]
    public function test_a_decorated_output_highlights_the_pattern(string $command, array $arguments, string $pattern): void
    {
        $output = $this->commandOutput($command, $arguments, true);

        $this->assertStringContainsString("\e[", $output);
        $this->assertStringNotContainsString('  '.$pattern, $output);
    }

    /**
     * @return iterable<string, array{command: string, arguments: array<string, mixed>, pattern: string}>
     */
    public static function provideCommands(): iterable
    {
        yield 'compare, first pattern' => ['command' => 'regex:compare', 'arguments' => ['pattern1' => '/(?<d>[0-9])+/', 'pattern2' => '/\d+/'], 'pattern' => '/(?<d>[0-9])+/'];
        yield 'compare, second pattern' => ['command' => 'regex:compare', 'arguments' => ['pattern1' => '/[0-9]+/', 'pattern2' => '/(?<d>\d)+/'], 'pattern' => '/(?<d>\d)+/'];
        yield 'transpile' => ['command' => 'regex:transpile', 'arguments' => ['pattern' => '/(?<d>\d)+/', '--target' => 'javascript'], 'pattern' => '/(?<d>\d)+/'];
        yield 'explain' => ['command' => 'regex:explain', 'arguments' => ['pattern' => '/(?<d>\d)+/', '--highlight' => true], 'pattern' => '/(?<d>\d)+/'];
    }

    /**
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function commandOutput(string $command, array $arguments, bool $decorated): string
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, $decorated);
        Artisan::call($command, $arguments, $output);

        return $output->fetch();
    }
}
