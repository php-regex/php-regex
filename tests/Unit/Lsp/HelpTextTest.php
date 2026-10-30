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

namespace PHPRegex\Tests\Unit\Lsp;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The server's help points at the editor guide instead of a VS Code setting
 * that does not exist: VS Code starts a language server from an extension.
 */
#[Group('documentation')]
final class HelpTextTest extends TestCase
{
    #[Test]
    public function test_the_help_points_at_the_editor_guide(): void
    {
        $output = [];
        exec(escapeshellarg(\PHP_BINARY).' '.escapeshellarg(\dirname(__DIR__, 3).'/src/LanguageServer/bin/regex-lsp').' --help', $output, $code);
        $help = implode("\n", $output);

        $this->assertSame(0, $code, $help);
        $this->assertStringContainsString('https://php-regex.com/guides/lsp/', $help);
        $this->assertStringNotContainsString('php-regex.lsp.path', $help);
    }

    #[Test]
    public function test_the_package_readme_tells_the_vs_code_story_of_the_guide(): void
    {
        $readme = (string) file_get_contents(\dirname(__DIR__, 3).'/src/LanguageServer/README.md');

        $this->assertStringNotContainsString('"lsp.servers"', $readme);
        $this->assertStringContainsString('vscode-languageclient', $readme);
    }
}
