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

use PHPRegex\Cli\Command\DebugCommand;
use PHPRegex\Cli\Command\RedosCommand;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The input "regex debug" and "regex redos" print is a subject string, not
 * a pattern: a backslash in it is a plain byte, and "\Q" quotes nothing.
 * Its unprintable bytes are spelled, and nothing else changes.
 */
final class DebugRedosSubjectDisplayTest extends TestCase
{
    /**
     * @return iterable<string, array{command: string}>
     */
    public static function provideCommands(): iterable
    {
        yield 'debug' => ['command' => 'debug'];
        yield 'redos' => ['command' => 'redos'];
    }

    /**
     * A backslash before a control byte stays on screen, as before: the
     * subject "C:\" then a bell, then "dir".
     */
    #[Test]
    #[DataProvider('provideCommands')]
    public function test_a_backslash_before_a_control_byte_is_shown(string $command): void
    {
        $line = $this->inputLine($command, "C:\\\x07dir");

        // "C:", the backslash (doubled or not), then the bell's own escape.
        $this->assertMatchesRegularExpression('/C:(?:\\\\){1,2}\\\\(?:x07|a|007)dir/', $line);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $line);
    }

    /**
     * "\Q" in a subject is two characters: the byte after it is spelled
     * alone, with no "\E...\Q" around it.
     */
    #[Test]
    #[DataProvider('provideCommands')]
    public function test_a_quote_marker_in_the_subject_is_plain_text(string $command): void
    {
        $line = $this->inputLine($command, "a\\Q\x01b");

        $this->assertStringNotContainsString('\E', $line);
        $this->assertMatchesRegularExpression('/a(?:\\\\){1,2}Q\\\\(?:x01|001)b/', $line);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $line);
    }

    /**
     * The line that shows the subject, without its trailing newline.
     */
    private function inputLine(string $command, string $subject): string
    {
        $buffer = 'debug' === $command
            ? $this->runCommand(new DebugCommand(), 'debug', ['/a/', '--input='.$subject])
            : $this->runCommand(new RedosCommand(), 'redos', ['/a/', '--input', $subject, '--show-input', '--iterations', '1', '--warmup', '0']);

        $lines = array_values(array_filter(
            explode("\n", $buffer),
            static fn (string $line): bool => 1 === preg_match('/^\s*Input\s*:/', $line),
        ));
        $this->assertCount(1, $lines, $buffer);

        return $lines[0];
    }

    /**
     * @param list<string> $arguments
     */
    private function runCommand(DebugCommand|RedosCommand $command, string $name, array $arguments): string
    {
        $input = new Input($name, $arguments, new GlobalOptions(false, false, false, true, null, null), []);

        $level = ob_get_level();
        ob_start();

        try {
            $command->run($input, OutputFactory::create());
        } finally {
            $buffer = (string) ob_get_clean();
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return $buffer;
    }
}
