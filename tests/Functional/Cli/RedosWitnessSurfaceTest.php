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
use PHPRegex\Cli\Command\CommandInterface;
use PHPRegex\Cli\Command\DebugCommand;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Output;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A witness made of a control byte or a non-ASCII code point reaches the
 * terminal and the JSON of "regex analyze" / "regex debug" in its escaped
 * form only.
 *
 * Each pattern fails preg_match() at 19 pumps followed by "!" (PCRE2 10.49,
 * JIT on and off).
 */
final class RedosWitnessSurfaceTest extends TestCase
{
    #[Test]
    #[DataProvider('provideConsoleCases')]
    public function test_console_attack_line_is_escaped(string $command, string $pattern, string $attack): void
    {
        $buffer = $this->runCommand($command, [$pattern]);

        $this->assertStringContainsString('Attack: '.$attack."\n", $buffer);
        $this->assertSame(1, preg_match('/Attack: (.*)$/m', $buffer, $match), $buffer);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F-\xFF]/', $match[1]);
    }

    /**
     * @return iterable<string, array{command: string, pattern: string, attack: string}>
     */
    public static function provideConsoleCases(): iterable
    {
        foreach (['analyze', 'debug'] as $command) {
            yield $command.', escape control character' => ['command' => $command, 'pattern' => '/(\x1b+)+$/', 'attack' => '"\x1B" x n . "!"'];
            yield $command.', e acute under u' => ['command' => $command, 'pattern' => '/(é+)+$/u', 'attack' => '"\u{E9}" x n . "!"'];
        }
    }

    /**
     * The escape byte never reaches the terminal, wherever the output
     * mentions the pattern: its source spells it "\x1b".
     */
    #[Test]
    #[DataProvider('provideCommands')]
    public function test_console_output_carries_no_raw_escape_byte(string $command): void
    {
        $buffer = $this->runCommand($command, ['/(\x1b+)+$/']);

        $this->assertStringNotContainsString("\x1B", $buffer);
    }

    #[Test]
    #[DataProvider('provideJsonCases')]
    public function test_json_witness_parts_are_escaped(string $pattern, string $pump): void
    {
        $buffer = $this->runCommand('analyze', [$pattern, '--format=json']);

        $payload = json_decode($buffer, true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertIsArray($payload['redos'] ?? null, $buffer);
        $this->assertIsArray($payload['redos']['witness'] ?? null, $buffer);
        $this->assertSame(['prefix' => '', 'pump' => $pump, 'suffix' => '!'], $payload['redos']['witness']);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', json_encode($payload['redos']['witness'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return iterable<string, array{pattern: string, pump: string}>
     */
    public static function provideJsonCases(): iterable
    {
        yield 'escape control character' => ['pattern' => '/(\x1b+)+$/', 'pump' => '\x1B'];
        yield 'e acute under u' => ['pattern' => '/(é+)+$/u', 'pump' => '\u{E9}'];
    }

    /**
     * @return iterable<string, array{command: string}>
     */
    public static function provideCommands(): iterable
    {
        yield 'analyze' => ['command' => 'analyze'];
        yield 'debug' => ['command' => 'debug'];
    }

    /**
     * @param list<string> $arguments
     */
    private function runCommand(string $command, array $arguments): string
    {
        $handler = self::command($command);
        $input = new Input($command, $arguments, new GlobalOptions(false, false, false, true, null, null), []);

        $level = ob_get_level();
        ob_start();

        try {
            $handler->run($input, new Output(false, false));
        } finally {
            $buffer = (string) ob_get_clean();
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return $buffer;
    }

    private static function command(string $name): CommandInterface
    {
        return 'debug' === $name ? new DebugCommand() : new AnalyzeCommand();
    }
}
