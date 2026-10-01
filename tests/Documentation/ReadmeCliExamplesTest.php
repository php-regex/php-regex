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

namespace PhpRegex\Tests\Documentation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReadmeCliExamplesTest extends TestCase
{
    #[Test]
    public function cli_analyze_example(): void
    {
        [$output, $exitCode] = $this->runCli(['--no-ansi', 'analyze', '/(a+)+$/']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Severity   : CRITICAL', $output);
        $this->assertStringContainsString('Command   : analyze', $output);
    }

    #[Test]
    public function cli_highlight_html_example(): void
    {
        [$output, $exitCode] = $this->runCli(['--no-ansi', 'highlight', '/^[0-9]+(\\w+)$/', '--format=html']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('regex-anchor', $output);
        $this->assertStringContainsString('regex-meta', $output);
    }

    #[Test]
    public function cli_lint_example(): void
    {
        [$output, $exitCode] = $this->runCli(['--no-ansi', 'lint', 'src/', '--format=console', '--min-savings=2', '--no-validate']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('PASS', $output);
    }

    /**
     * @param array<string> $args
     *
     * @return array{0: string, 1: int}
     */
    private function runCli(array $args): array
    {
        $script = \dirname(__DIR__, 2).'/bin/regex';
        $command = array_merge([\PHP_BINARY, $script], $args);
        $escaped = array_map(escapeshellarg(...), $command);
        $output = [];
        $exitCode = 0;

        exec(implode(' ', $escaped).' 2>&1', $output, $exitCode);

        return [implode("\n", $output), $exitCode];
    }
}
