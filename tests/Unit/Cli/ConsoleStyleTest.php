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

use PHPRegex\Cli\ConsoleStyle;
use PHPRegex\Cli\Output;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What resolving the target noticed is said under the banner, else on
 * stderr when there is no banner, and never under -q. A control or a
 * hidden character of a note is spelled out, never sent to the terminal.
 */
final class ConsoleStyleTest extends TestCase
{
    private const NOTE = 'PCRE2 10.30 is judged with the PCRE2 10.39 rules, the oldest this library knows in full.';

    #[Test]
    public function test_the_banner_carries_each_note(): void
    {
        [$stdout, $stderr] = self::render(visuals: true, quiet: false, notes: [self::NOTE]);

        $this->assertStringContainsString('Note: '.self::NOTE."\n", $stdout);
        $this->assertSame('', $stderr);
    }

    #[Test]
    public function test_without_the_banner_each_note_goes_to_stderr(): void
    {
        [$stdout, $stderr] = self::render(visuals: false, quiet: false, notes: [self::NOTE, 'A second note.']);

        $this->assertSame('', $stdout, 'No banner, nothing on stdout.');
        $this->assertSame('Note: '.self::NOTE."\nNote: A second note.\n", $stderr);
    }

    #[Test]
    #[DataProvider('provideVisuals')]
    public function test_quiet_silences_the_notes(bool $visuals): void
    {
        [$stdout, $stderr] = self::render(visuals: $visuals, quiet: true, notes: [self::NOTE]);

        $this->assertSame('', $stdout);
        $this->assertSame('', $stderr);
    }

    #[Test]
    #[DataProvider('provideVisuals')]
    public function test_a_control_character_of_a_note_is_spelled_out(bool $visuals): void
    {
        [$stdout, $stderr] = self::render(visuals: $visuals, quiet: false, notes: ["we\e[2Jird.json is not a JSON object\u{202E}: judging for the running PHP."]);

        // The escape and the override, spelled as lint spells them.
        $spelled = 'Note: we\x1B[2Jird.json is not a JSON object\x{202E}: judging for the running PHP.';

        $this->assertStringContainsString($spelled."\n", $stdout.$stderr);
        $this->assertStringNotContainsString("\e", $stdout.$stderr);
        $this->assertStringNotContainsString("\u{202E}", $stdout.$stderr);
    }

    /**
     * @return iterable<string, array{visuals: bool}>
     */
    public static function provideVisuals(): iterable
    {
        yield 'under the banner' => ['visuals' => true];
        yield 'without the banner' => ['visuals' => false];
    }

    /**
     * @param list<string> $notes
     *
     * @return array{string, string} stdout and stderr
     */
    private static function render(bool $visuals, bool $quiet, array $notes): array
    {
        $errors = fopen('php://memory', 'w+');
        self::assertIsResource($errors);
        $style = new ConsoleStyle(new Output(false, $quiet, '#', '-', $errors), $visuals);

        $level = ob_get_level();
        ob_start();

        try {
            $style->renderBanner('validate', [], null, $notes);
            $stdout = (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        rewind($errors);
        $stderr = (string) stream_get_contents($errors);
        fclose($errors);

        return [$stdout, $stderr];
    }
}
