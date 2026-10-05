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

namespace PHPRegex\Tests\Unit\Lint\Formatter;

use PHPRegex\Linter\Formatter\ConsoleFormatter;
use PHPRegex\Linter\Formatter\LinkFormatter;
use PHPRegex\Linter\Formatter\OutputConfiguration;
use PHPRegex\Linter\Formatter\RelativePathHelper;
use PHPRegex\Linter\LintReport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * With an editor configured and a terminal that shows hyperlinks, the plain
 * console formatter puts a pen after "file:line" that opens the editor
 * there: an OSC 8 hyperlink, the URL taken from the link formatter.
 */
final class ConsolePenLinkTest extends TestCase
{
    /**
     * @var array<string, string|false>
     */
    private array $environment = [];

    private mixed $ideaDirectory = null;

    private bool $hadIdeaDirectory = false;

    protected function setUp(): void
    {
        // The link formatter reads the terminal from the environment: a
        // JetBrains terminal or an old Konsole gets no hyperlink.
        foreach (['TERMINAL_EMULATOR', 'KONSOLE_VERSION'] as $name) {
            $this->environment[$name] = getenv($name);
            putenv($name);
        }
        $this->hadIdeaDirectory = \array_key_exists('IDEA_INITIAL_DIRECTORY', $_SERVER);
        $this->ideaDirectory = $_SERVER['IDEA_INITIAL_DIRECTORY'] ?? null;
        unset($_SERVER['IDEA_INITIAL_DIRECTORY']);
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv(false === $value ? $name : $name.'='.$value);
        }
        if ($this->hadIdeaDirectory) {
            $_SERVER['IDEA_INITIAL_DIRECTORY'] = $this->ideaDirectory;
        }
    }

    #[Test]
    public function test_console_formatter_links_the_location_to_the_editor(): void
    {
        $formatter = new ConsoleFormatter(
            config: new OutputConfiguration(ansi: false),
            ide: 'phpstorm',
            linkFormatter: new LinkFormatter('phpstorm', new RelativePathHelper('/project')),
        );

        $output = $formatter->format($this->report('src/a.php', 3));

        $this->assertStringContainsString("src/a.php:3 \e]8;;phpstorm://open?file=/project/src/a.php&line=3\e\\✏️\e]8;;\e\\", $output);
    }

    #[Test]
    public function test_console_formatter_shows_a_bare_pen_when_the_location_is_no_path(): void
    {
        // "test" looks like no file: no editor URL, the pen stays plain.
        $formatter = new ConsoleFormatter(
            config: new OutputConfiguration(ansi: false),
            ide: 'phpstorm',
            linkFormatter: new LinkFormatter('phpstorm', new RelativePathHelper('/project')),
        );

        $output = $formatter->format($this->report('test', 3));

        $this->assertStringContainsString('test:3 ✏️', $output);
        $this->assertStringNotContainsString("\e]8;;", $output);
    }

    private function report(string $file, int $line): LintReport
    {
        return new LintReport([[
            'file' => $file,
            'line' => $line,
            'pattern' => '/a+/',
            'issues' => [[
                'type' => 'warning',
                'message' => 'A warning.',
                'file' => $file,
                'line' => $line,
            ]],
            'optimizations' => [],
            'problems' => [],
        ]], ['errors' => 0, 'warnings' => 1, 'optimizations' => 0]);
    }
}
