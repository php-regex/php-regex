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
        $this->assertSame([], HostRequirements::missing(windows: false, ownerCheck: true));
    }

    #[Test]
    public function test_windows_checks_no_owner_and_lacks_nothing(): void
    {
        $this->assertSame([], HostRequirements::missing(windows: true, ownerCheck: false));
        $this->assertSame([], HostRequirements::missing(windows: true, ownerCheck: true));
    }

    #[Test]
    public function test_a_host_without_the_owner_check_is_named_with_the_tests_that_fail_there(): void
    {
        $this->assertSame(
            ['posix_geteuid() is missing or disabled: the AST file cache and the class-set store keep nothing where they cannot check who owns their directory, and the tests of what they keep fail on this host.'],
            HostRequirements::missing(windows: false, ownerCheck: false),
        );
    }

    #[Test]
    public function test_this_host_is_judged_by_its_system_and_its_functions(): void
    {
        $this->assertSame(
            HostRequirements::missing(Path::onWindows(), \function_exists('posix_geteuid')),
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
     * The bootstrap of the suite, in a process of its own: silent on this
     * host, and saying what is missing where the function is disabled.
     */
    #[Test]
    public function test_the_suite_says_what_the_host_lacks_when_it_starts(): void
    {
        $this->assertSame('', $this->bootstrapErrors([]));

        $disabled = $this->bootstrapErrors(['-d', 'disable_functions=posix_geteuid']);
        if (Path::onWindows()) {
            $this->assertSame('', $disabled);

            return;
        }

        $this->assertSame(HostRequirements::notice(HostRequirements::missing(windows: false, ownerCheck: false)), $disabled);
        $this->assertStringContainsString('posix_geteuid()', $disabled);
    }

    /**
     * What tests/bootstrap.php writes to the standard error.
     *
     * @param list<string> $settings
     */
    private function bootstrapErrors(array $settings): string
    {
        $process = proc_open(
            [\PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'xdebug.mode=off', ...$settings, \dirname(__DIR__, 2).'/bootstrap.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output.$errors);
        $this->assertSame('', $output);

        return $errors;
    }
}
