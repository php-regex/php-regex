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

namespace PHPRegex\Tests\Documentation;

use PHPRegex\Tests\Support\RunsInAnEmptyProject;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('documentation')]
final class ReadmeCliExamplesTest extends TestCase
{
    use RunsInAnEmptyProject;

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
        // The README runs it at the root of this repository, on its src/
        // and with its regex.dist.json.
        [$output, $exitCode] = $this->runCli(['--no-ansi', 'lint', 'src/', '--format=console', '--min-savings=2', '--no-validate'], \dirname(__DIR__, 2));

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('PASS', $output);
    }

    /**
     * @param list<string> $args
     * @param string|null  $directory where the command runs; the test's working directory when null
     *
     * @return array{0: string, 1: int}
     */
    private function runCli(array $args, ?string $directory = null): array
    {
        $script = \dirname(__DIR__, 2).'/bin/regex';
        // stderr to a file: a pipe left unread while stdout is read could fill and stall the run.
        $errors = (string) tempnam(sys_get_temp_dir(), 'regex-readme-err-');

        try {
            $process = proc_open([\PHP_BINARY, $script, ...$args], [1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']], $pipes, $directory);
            $this->assertIsResource($process);
            $output = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $exitCode = proc_close($process);

            return [$output.(string) file_get_contents($errors), $exitCode];
        } finally {
            @unlink($errors);
        }
    }
}
