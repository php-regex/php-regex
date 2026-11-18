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

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The input `regex redos` repeats weighs 8 MiB at most, so that the run
 * holds in PHP's default memory_limit of 128M: run in a process of its own
 * under that limit.
 */
final class RedosInputLimitTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/regex-redos-limit-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    /**
     * Each byte PHP escapes as \xHH takes four once escaped, and the
     * console copies the line as it lays it out: the largest subject of
     * such bytes peaks at about 115 MiB with --show-input (PHP 8.1 to 8.5,
     * 32- and 64-bit), and a memory_limit of 112M stops it.
     */
    #[Test]
    public function test_the_largest_subject_of_bytes_to_escape_is_printed_within_the_default_memory_limit(): void
    {
        file_put_contents($this->directory.'/byte.txt', "\xFF");

        [$exitCode, $stdout, $stderr] = $this->runRedos(['--ansi', 'redos', '/a/', '--input-file='.$this->directory.'/byte.txt', '--repeat=8388608', '--show-input']);

        $this->assertSame(0, $exitCode, $stderr);
        $this->assertSame('', $stderr);
        $this->assertStringContainsString(str_repeat('\xFF', 1024), $stdout);
        $this->assertGreaterThan(4 * 8_388_608, \strlen($stdout));
    }

    /**
     * A file past the limit is refused whatever the count: it is read to
     * one byte past the limit, never whole, and the message gives the
     * length the file system gives it. Read whole, 160 MiB stops PHP on
     * its memory_limit before anything is refused.
     */
    #[Test]
    public function test_an_input_file_past_the_memory_limit_is_refused_without_being_read_whole(): void
    {
        $file = $this->directory.'/large.txt';
        self::sparseFile($file, 160 * 1024 * 1024);

        [$exitCode, $stdout, $stderr] = $this->runRedos(['redos', '/a/', '--input-file='.$file, '--json']);

        $this->assertSame(2, $exitCode, $stdout.$stderr);
        $this->assertSame('', $stderr);
        $this->assertSame(['error' => "--repeat 1 repeats the 167772160-byte input past 8 MiB (8388608 bytes), the most it may weigh: printed with --show-input, an 8 MiB subject of bytes to escape takes up to about 115 MiB, which must fit in PHP's default memory_limit of 128M. The largest count for this input is 0.", 'stage' => 'usage'], json_decode($stdout, true));
    }

    /**
     * A file of $length zero bytes, which the file system need not store.
     *
     * @param int<0, max> $length
     */
    private static function sparseFile(string $file, int $length): void
    {
        $handle = fopen($file, 'w');
        self::assertIsResource($handle);
        ftruncate($handle, $length);
        fclose($handle);
    }

    /**
     * @param list<string> $arguments the command line after "regex"
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function runRedos(array $arguments): array
    {
        $stdoutFile = $this->directory.'/stdout';
        $stderrFile = $this->directory.'/stderr';
        $environment = getenv();
        $environment['XDEBUG_MODE'] = 'off';

        $process = proc_open(
            [\PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'auto_prepend_file=', \dirname(__DIR__, 3).'/bin/regex', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['file', $stdoutFile, 'w'], 2 => ['file', $stderrFile, 'w']],
            $pipes,
            $this->directory,
            $environment,
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $exitCode = proc_close($process);

        return [$exitCode, (string) file_get_contents($stdoutFile), (string) file_get_contents($stderrFile)];
    }
}
