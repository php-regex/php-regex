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

namespace PHPRegex\Tests\Unit\Support;

use PHPRegex\Parser\Text\Path;
use PHPRegex\Tests\Support\HostRequirements;
use PHPRegex\Tests\Support\ProcessPipes;
use PHPRegex\Tests\TestUtils\TemporaryDirectory;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The AST file cache and the class-set store keep nothing where they cannot
 * check who owns their directory, which outside Windows takes
 * posix_geteuid(). What they do without it has tests of its own, run in a
 * process that disables the function; the tests of what they keep need it,
 * and the suite says so when it starts on a host that lacks it.
 */
final class HostRequirementsTest extends TestCase
{
    #[Test]
    public function test_a_host_that_can_check_the_owner_lacks_nothing(): void
    {
        $this->assertSame([], HostRequirements::missing(windows: false, ownerCheck: true, jq: true));
    }

    #[Test]
    public function test_windows_checks_no_owner_and_lacks_nothing(): void
    {
        $this->assertSame([], HostRequirements::missing(windows: true, ownerCheck: false, jq: false));
        $this->assertSame([], HostRequirements::missing(windows: true, ownerCheck: true, jq: true));
    }

    #[Test]
    public function test_a_host_without_the_owner_check_is_named_with_the_tests_that_fail_there(): void
    {
        $this->assertSame(
            ['posix_geteuid() is missing or disabled: the AST file cache and the class-set store keep nothing where they cannot check who owns their directory, and the tests of what they keep fail on this host.'],
            HostRequirements::missing(windows: false, ownerCheck: false, jq: true),
        );
    }

    #[Test]
    public function test_a_host_without_jq_is_named_with_the_tests_that_fail_there(): void
    {
        $this->assertSame(
            ['jq is not on the PATH: the tests of the scope job of ci.yml read the files GitHub lists through it, as gh --jq does, and fail on this host.'],
            HostRequirements::missing(windows: false, ownerCheck: true, jq: false),
        );
        $this->assertCount(2, HostRequirements::missing(windows: false, ownerCheck: false, jq: false));
    }

    /**
     * Outside Windows, where a program is a file with its execute bit set:
     * Windows reads it by its extension, and needs none of these.
     */
    #[Test]
    #[RequiresOperatingSystem('Linux|Darwin')]
    public function test_a_program_is_found_on_the_path_where_it_can_be_run(): void
    {
        $directory = TemporaryDirectory::create('php-regex-host');
        mkdir($directory.'/bin');
        file_put_contents($directory.'/bin/jq', "#!/bin/sh\n");
        file_put_contents($directory.'/bin/notrun', "#!/bin/sh\n");
        chmod($directory.'/bin/jq', 0o755);
        chmod($directory.'/bin/notrun', 0o644);

        try {
            $path = $directory.'/missing'.\PATH_SEPARATOR.$directory.'/bin';
            $this->assertTrue(HostRequirements::onPath('jq', $path));
            $this->assertFalse(HostRequirements::onPath('notrun', $path));
            $this->assertFalse(HostRequirements::onPath('absent', $path));
            $this->assertFalse(HostRequirements::onPath('jq', ''));
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }

    #[Test]
    public function test_this_host_is_judged_by_its_system_and_its_functions(): void
    {
        $this->assertSame(
            HostRequirements::missing(Path::onWindows(), \function_exists('posix_geteuid'), HostRequirements::onPath('jq', (string) getenv('PATH'))),
            HostRequirements::ofThisHost(),
        );
    }

    #[Test]
    public function test_the_notice_is_empty_when_nothing_is_missing(): void
    {
        $this->assertSame('', HostRequirements::notice([]));
    }

    #[Test]
    public function test_the_notice_lists_each_missing_requirement_on_a_line(): void
    {
        $this->assertSame(
            "The test suite needs more than this host has:\n - one\n - two\n",
            HostRequirements::notice(['one', 'two']),
        );
    }

    /**
     * The bootstrap of the suite, in a process of its own: it says what
     * that process lacks, which its own settings decide, not this one's.
     * Silent where the function can be called, it names it where a setting
     * disables it.
     */
    #[Test]
    public function test_the_suite_says_what_the_host_lacks_when_it_starts(): void
    {
        $this->assertSame($this->expectedNotice([]), $this->bootstrapErrors([]));

        $disabled = ['-d', 'disable_functions=posix_geteuid'];
        $this->assertFalse($this->canCheckOwner($disabled));
        $this->assertSame($this->expectedNotice($disabled), $this->bootstrapErrors($disabled));
        $this->assertSame(!Path::onWindows(), str_contains($this->bootstrapErrors($disabled), 'posix_geteuid()'));
    }

    /**
     * What a process started with these settings is told when the suite
     * starts in it.
     *
     * @param list<string> $settings
     */
    private function expectedNotice(array $settings): string
    {
        return HostRequirements::notice(HostRequirements::missing(Path::onWindows(), $this->canCheckOwner($settings), HostRequirements::onPath('jq', (string) getenv('PATH'))));
    }

    /**
     * Whether a process started with these settings can call
     * posix_geteuid(): a php.ini may disable it for every process of the
     * host, this one included.
     *
     * @param list<string> $settings
     */
    private function canCheckOwner(array $settings): bool
    {
        [$output] = $this->runPhp([...$settings, '-r', 'echo function_exists("posix_geteuid") ? "yes" : "no";']);
        $this->assertContains($output, ['yes', 'no']);

        return 'yes' === $output;
    }

    /**
     * What tests/bootstrap.php writes to the standard error.
     *
     * @param list<string> $settings
     */
    private function bootstrapErrors(array $settings): string
    {
        [$output, $errors] = $this->runPhp([...$settings, \dirname(__DIR__, 2).'/bootstrap.php']);
        $this->assertSame('', $output);

        return $errors;
    }

    /**
     * Runs PHP in a process of its own.
     *
     * @param list<string> $arguments
     *
     * @return array{string, string} what it wrote to the standard output and to the standard error
     */
    private function runPhp(array $arguments): array
    {
        $process = proc_open(
            [\PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'xdebug.mode=off', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        [$output, $errors] = ProcessPipes::drain($pipes);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output.$errors);

        return [$output, $errors];
    }
}
