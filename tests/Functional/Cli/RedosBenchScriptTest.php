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

use PHPRegex\Tests\Support\Newlines;
use PHPRegex\Tests\Support\ProcessPipes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * bin/redos-bench repeats its input as `regex redos` does, under the same
 * limit: ten billion copies of one byte would ask PHP for 10 GB.
 */
final class RedosBenchScriptTest extends TestCase
{
    #[Test]
    public function test_a_repeat_past_the_limit_is_refused_before_the_subject_is_built(): void
    {
        [$exitCode, $output] = self::bench(['--regex', '/a+$/', '--input', 'a', '--repeat', '8388609']);

        $this->assertSame(64, $exitCode, $output);
        $this->assertStringContainsString("--repeat 8388609 repeats the 1-byte input past 8 MiB (8388608 bytes), the most it may weigh: printed with --show-input, an 8 MiB subject of bytes to escape takes up to about 115 MiB, which must fit in PHP's default memory_limit of 128M. The largest count for this input is 8388608.\n", $output);
    }

    #[Test]
    public function test_the_largest_repeat_is_benchmarked(): void
    {
        [$exitCode, $output] = self::bench(['--regex', '/a+$/', '--input', 'ab', '--repeat', '4194304', '--suffix', '!']);

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString("input length       : 8388609\n", $output);
    }

    /**
     * The script measures preg_match() alone, in a checkout without its
     * Composer dependencies too: the one class it shares with `regex
     * redos`, for the limit, is loaded from its file.
     */
    #[Test]
    public function test_the_script_runs_without_the_composer_autoloader(): void
    {
        $root = sys_get_temp_dir().'/regex-redos-bench-'.bin2hex(random_bytes(4));
        $files = ['bin/redos-bench', 'src/Cli/Command/RedosInput.php'];
        foreach ($files as $file) {
            @mkdir(\dirname($root.'/'.$file), 0o777, true);
            copy(\dirname(__DIR__, 3).'/'.$file, $root.'/'.$file);
        }

        try {
            [$helpExitCode, $help] = self::bench(['--help'], $root);
            [$benchExitCode, $bench] = self::bench(['--regex', '/a+$/', '--input', 'ab', '--repeat', '4194304', '--suffix', '!'], $root);
            [$refusalExitCode, $refusal] = self::bench(['--regex', '/a+$/', '--input', 'a', '--repeat', '8388609'], $root);
        } finally {
            foreach ($files as $file) {
                unlink($root.'/'.$file);
            }
            foreach (['bin', 'src/Cli/Command', 'src/Cli', 'src', ''] as $directory) {
                rmdir($root.'/'.$directory);
            }
        }

        $this->assertSame(0, $helpExitCode, $help);
        $this->assertStringStartsWith("ReDoS benchmark helper (raw PHP).\n", $help);
        $this->assertSame(0, $benchExitCode, $bench);
        $this->assertStringContainsString("input length       : 8388609\n", $bench);
        $this->assertSame(64, $refusalExitCode, $refusal);
        $this->assertStringContainsString("\n--repeat 8388609 repeats the 1-byte input past 8 MiB (8388608 bytes), the most it may weigh: ", $refusal);
    }

    /**
     * @param list<string> $arguments
     * @param string       $root      the checkout the script is run from
     *
     * @return array{0: int, 1: string}
     */
    private static function bench(array $arguments, string $root = __DIR__.'/../../..'): array
    {
        $process = proc_open([\PHP_BINARY, '-d', 'memory_limit=128M', $root.'/bin/redos-bench', ...$arguments], ProcessPipes::descriptors(), $pipes);
        self::assertIsResource($process);
        $output = null;

        try {
            $output = Newlines::lf(implode('', ProcessPipes::drain($pipes)));
        } finally {
            // A child whose output is read no more is stopped before it is
            // reaped: it could wait on a full pipe for ever.
            if (null === $output) {
                proc_terminate($process);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
        }

        return [$exitCode, $output];
    }
}
