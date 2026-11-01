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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A php fence the tutorial marks runnable is a promise to the reader: the
 * "Run" control on that block executes the snippet in the browser, through
 * a PHP compiled to WebAssembly, and the panel under it shows what the
 * snippet printed. The promise holds only if the snippet is a whole program
 * that produces output — the fences carry no opening tag (the runner
 * prepends one, exactly like this test) and a snippet that prints nothing
 * would open a panel with nothing in it.
 *
 * The engine here is the local PHP, not the browser build; what the test
 * guarantees is the shape of the snippet (it parses, it runs to a zero exit
 * code, it writes to stdout), which is the part the prose controls. The
 * runner displays warnings and errors as the engine emits them, so a
 * version-dependent warning is not a failure — only a fatal error, a parse
 * error or an empty output is.
 */
#[Group('documentation')]
final class RunnableFenceTest extends TestCase
{
    private const TUTORIAL = __DIR__.'/../../docs/tutorial';

    /**
     * The marking pass left the tutorial with 87 runnable fences: 44 that
     * already printed (two of them gained the subject their prose had left
     * implicit), and 43 silent ones that gained the one line that prints.
     * The floor, not the exact count, is the contract: a chapter yet to be
     * written adds fences, it does not retire the ones it has.
     */
    private const MINIMUM_RUNNABLE_FENCES = 87;

    #[Test]
    public function test_the_tutorial_still_marks_enough_fences_runnable(): void
    {
        $count = 0;
        foreach (glob(self::TUTORIAL.'/*.md') ?: [] as $page) {
            $count += \count(self::runnableFences((string) $page));
        }

        $this->assertGreaterThanOrEqual(
            self::MINIMUM_RUNNABLE_FENCES,
            $count,
            sprintf('Expected at least %d runnable fences across the tutorial, found %d.', self::MINIMUM_RUNNABLE_FENCES, $count),
        );
    }

    #[Test]
    #[DataProvider('provideRunnableFences')]
    public function test_a_runnable_fence_runs_to_a_zero_exit_and_prints(string $code, string $page, int $line): void
    {
        $source = str_starts_with(ltrim($code), '<?php') ? $code : "<?php\n\n".$code;

        $stdout = '';
        $exitCode = self::runPhp($source, $stdout);

        $this->assertSame(
            0,
            $exitCode,
            sprintf('%s line %d: the snippet exits with code %d — it would show the reader an error panel.', basename($page), $line, $exitCode),
        );
        $this->assertNotSame(
            '',
            trim($stdout),
            sprintf('%s line %d: the snippet prints nothing — the run panel would open empty.', basename($page), $line),
        );
    }

    #[Test]
    #[DataProvider('provideRunnableFences')]
    public function test_a_runnable_fence_needs_only_the_php_it_runs_on(string $code, string $page, int $line): void
    {
        // The browser engine is plain PHP: the library is not installed in
        // it. A fence that names a PHPRegex class would fatal in the panel,
        // so the library fences stay copy-only and this test holds the line.
        $this->assertStringNotContainsString(
            'PHPRegex',
            $code,
            sprintf('%s line %d: the runnable engine ships plain PHP only.', basename($page), $line),
        );
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function provideRunnableFences(): iterable
    {
        foreach (glob(self::TUTORIAL.'/*.md') ?: [] as $page) {
            foreach (self::runnableFences((string) $page) as $fence) {
                yield basename($page).':'.$fence['line'] => [$fence['code'], (string) $page, $fence['line']];
            }
        }
    }

    /**
     * The same extraction the site's markup expresses: a php fence whose
     * closing fence line is followed by the attribute line `{: data-run="" }`.
     *
     * @return list<array{code: string, line: int}>
     */
    private static function runnableFences(string $page): array
    {
        $lines = preg_split('/\R/', (string) file_get_contents($page)) ?: [];

        $fences = [];
        $state = 'prose';
        $fence = '';
        $code = [];
        $first = 0;

        foreach ($lines as $number => $line) {
            if ('prose' === $state) {
                if (1 === preg_match('/^\s*(`{3,}|~{3,})\s*(\S*)/', $line, $open)) {
                    $state = 'php' === strtolower($open[2]) ? 'php' : 'other';
                    $fence = $open[1];
                    $code = [];
                    $first = $number + 2;
                }

                continue;
            }

            $closes = 1 === preg_match('/^\s*'.preg_quote($fence[0], '/').'{'.\strlen($fence).',}\s*$/', $line);
            if (!$closes) {
                if ('php' === $state) {
                    $code[] = $line;
                }

                continue;
            }

            $next = trim($lines[$number + 1] ?? '');
            if ('php' === $state && 1 === preg_match('/^\{:\s*data-run\s*=\s*""\s*\}$/', $next)) {
                $fences[] = ['code' => implode("\n", $code), 'line' => $first];
            }

            $state = 'prose';
        }

        return $fences;
    }

    /**
     * Runs the snippet under the interpreter that runs the suite. A parse
     * error or a fatal writes to stdout/stderr with a non-zero code; an
     * endless loop dies at the deadline instead of taking the suite down.
     *
     * The output lands in files, never in pipes: a pipe read can block on
     * Windows whatever the blocking mode says (php bug 47918), and a child
     * stuck in a loop closes nothing. A file is read only once the child
     * is done with it. And before PHP 8.3, proc_close() reports -1 once
     * proc_get_status() has observed the exit, so the exit code is the one
     * read at that observation, and proc_close() only reaps the child.
     */
    private static function runPhp(string $source, string &$stdout): int
    {
        $file = tempnam(sys_get_temp_dir(), 'phpregex-runnable-fence');
        \assert(\is_string($file));
        file_put_contents($file, $source);
        $stdoutFile = $file.'.out';
        $stderrFile = $file.'.err';

        $process = proc_open(
            [\PHP_BINARY, '-n', '-d', 'display_errors=0', '-d', 'error_reporting=E_ALL', $file],
            [1 => ['file', $stdoutFile, 'w'], 2 => ['file', $stderrFile, 'w']],
            $pipes,
        );
        \assert(\is_resource($process));

        $deadline = microtime(true) + 10.0;
        do {
            $status = proc_get_status($process);
            if ($status['running']) {
                usleep(5000);
            }
        } while ($status['running'] && microtime(true) < $deadline);

        if ($status['running']) {
            // The deadline expired and the child is still alive: kill it,
            // reap it, and leave the files unread — the run is a failure
            // whatever they hold.
            proc_terminate($process);
            proc_close($process);
            unlink($stdoutFile);
            unlink($stderrFile);
            unlink($file);

            return 124;
        }

        $exitCode = $status['exitcode'];
        proc_close($process);
        $stdout = (string) file_get_contents($stdoutFile);
        unlink($stdoutFile);
        unlink($stderrFile);
        unlink($file);

        return $exitCode;
    }
}
