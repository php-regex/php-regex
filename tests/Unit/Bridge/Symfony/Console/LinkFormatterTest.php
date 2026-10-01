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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Console;

use PHPRegex\Linter\Formatter\LinkFormatter;
use PHPRegex\Linter\Formatter\RelativePathHelper;
use PHPUnit\Framework\TestCase;

final class LinkFormatterTest extends TestCase
{
    /**
     * @var array<string, string|null>
     */
    private array $envBackup;

    protected function setUp(): void
    {
        $this->envBackup = [
            'TERMINAL_EMULATOR' => $this->getEnvValue('TERMINAL_EMULATOR'),
            'KONSOLE_VERSION' => $this->getEnvValue('KONSOLE_VERSION'),
            'IDEA_INITIAL_DIRECTORY' => isset($_SERVER['IDEA_INITIAL_DIRECTORY']) && \is_string($_SERVER['IDEA_INITIAL_DIRECTORY']) ? $_SERVER['IDEA_INITIAL_DIRECTORY'] : null,
        ];

        $this->clearEnv('TERMINAL_EMULATOR');
        $this->clearEnv('KONSOLE_VERSION');
        unset($_SERVER['IDEA_INITIAL_DIRECTORY']);
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('TERMINAL_EMULATOR', $this->envBackup['TERMINAL_EMULATOR']);
        $this->restoreEnv('KONSOLE_VERSION', $this->envBackup['KONSOLE_VERSION']);

        if (null === $this->envBackup['IDEA_INITIAL_DIRECTORY']) {
            unset($_SERVER['IDEA_INITIAL_DIRECTORY']);
        } else {
            $_SERVER['IDEA_INITIAL_DIRECTORY'] = $this->envBackup['IDEA_INITIAL_DIRECTORY'];
        }
    }

    public function test_it_returns_plain_label_when_ide_missing(): void
    {
        $formatter = new LinkFormatter(null, new RelativePathHelper('/app'));

        $result = $formatter->format('/app/src/File.php', 10, '  10');

        $this->assertSame('  10', $result);
    }

    public function test_it_formats_href_when_terminal_supports_links(): void
    {
        $formatter = new LinkFormatter('vscode://file/%f:%l', new RelativePathHelper('/app'));

        $result = $formatter->format('/app/src/File.php', 10, '  10');

        $this->assertSame('<href=vscode://file//app/src/File.php:10>  10</>', $result);
    }

    public function test_it_falls_back_to_plain_path_when_terminal_is_unsupported(): void
    {
        putenv('TERMINAL_EMULATOR=JetBrains-JediTerm');

        $formatter = new LinkFormatter('vscode://file/%f:%l', new RelativePathHelper('/app'));

        $result = $formatter->format('/app/src/File.php', 10, '  10');

        $this->assertSame('src/File.php:10', $result);
    }

    public function test_it_supports_editor_aliases(): void
    {
        $formatter = new LinkFormatter('phpstorm', new RelativePathHelper('/app'));

        $result = $formatter->format('/app/index.php', 5, '   5');

        $this->assertSame('<href=phpstorm://open?file=/app/index.php&line=5>   5</>', $result);
    }

    public function test_it_resolves_relative_paths_using_base_path(): void
    {
        $formatter = new LinkFormatter('vscode://file/%f:%l', new RelativePathHelper('/app'));

        $result = $formatter->format('src/File.php', 10, '  10');

        $this->assertSame('<href=vscode://file//app/src/File.php:10>  10</>', $result);
    }

    public function test_relative_path_helper_handles_base_path(): void
    {
        $helper = new RelativePathHelper('/var/www/project');

        $this->assertSame('src/Example.php', $helper->getRelativePath('/var/www/project/src/Example.php'));
        $this->assertSame('/tmp/Example.php', $helper->getRelativePath('/tmp/Example.php'));
    }

    public function test_format_returns_label_when_line_missing(): void
    {
        $formatter = new LinkFormatter('vscode://file/%f:%l', new RelativePathHelper('/app'));

        $this->assertSame('label', $formatter->format('/app/src/File.php', null, 'label'));
    }

    public function test_format_uses_fallback_label_when_links_unsupported(): void
    {
        putenv('KONSOLE_VERSION=201100');

        $formatter = new LinkFormatter('vscode://file/%f:%l', new RelativePathHelper('/app'));

        $this->assertSame('fallback', $formatter->format('/app/src/File.php', 10, 'label', 1, 'fallback'));
    }

    public function test_format_disables_links_when_idea_directory_detected(): void
    {
        $_SERVER['IDEA_INITIAL_DIRECTORY'] = '/app';

        $formatter = new LinkFormatter('vscode://file/%f:%l', new RelativePathHelper('/app'));

        $this->assertSame('src/File.php:10', $formatter->format('/app/src/File.php', 10, 'label'));
    }

    public function test_format_returns_label_when_path_is_not_resolvable(): void
    {
        $formatter = new LinkFormatter('vscode://file/%f:%l', new RelativePathHelper('/app'));

        $result = $formatter->format('notapath', 10, 'label');

        $this->assertSame('label', $result);
    }

    public function test_resolve_file_path_returns_normalized_when_base_empty(): void
    {
        $formatter = new LinkFormatter('vscode://file/%f:%l', new RelativePathHelper(''));

        $result = $formatter->format('src/File.php', 5, 'label');

        $this->assertSame('<href=vscode://file/src/File.php:5>label</>', $result);
    }

    public function test_path_helpers_cover_edge_cases(): void
    {
        $formatter = new LinkFormatter('vscode://file/%f:%l', new RelativePathHelper('/app'));
        $this->assertFalse($this->invokePrivate($formatter, 'looksLikePath', ['']));
        $this->assertTrue($this->invokePrivate($formatter, 'looksLikePath', ['http://example.com']));
        $this->assertTrue($this->invokePrivate($formatter, 'looksLikePath', ['file.txt']));

        $this->assertFalse($this->invokePrivate($formatter, 'isAbsolutePath', ['']));
    }

    public function test_relative_path_helper_returns_normalized_when_base_empty(): void
    {
        $helper = new RelativePathHelper('');

        $this->assertSame('/tmp/file.php', $helper->getRelativePath('/tmp/file.php'));
    }

    /**
     * @param array<int, mixed> $args
     */
    private function invokePrivate(object $target, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionClass($target);
        $refMethod = $ref->getMethod($method);

        return $refMethod->invokeArgs($target, $args);
    }

    private function getEnvValue(string $name): ?string
    {
        $value = getenv($name);

        return false === $value ? null : $value;
    }

    private function clearEnv(string $name): void
    {
        putenv($name);
        unset($_SERVER[$name]);
    }

    private function restoreEnv(string $name, ?string $value): void
    {
        if (null === $value) {
            putenv($name);
            unset($_SERVER[$name]);
        } else {
            putenv($name.'='.$value);
            $_SERVER[$name] = $value;
        }
    }
}
