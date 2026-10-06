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

namespace PHPRegex\Tests\Support;

use PHPRegex\Cli\ApplicationFactory;
use PHPRegex\Cli\Output;
use PHPUnit\Framework\Assert;

/**
 * Run the regex command the way a user does: the whole application, the
 * global options included, its stdout and stderr kept apart. In-process by
 * default; as a real process when the run forks workers.
 */
trait RunsRegexCli
{
    /**
     * @param list<string> $arguments the command line after "regex"
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function runRegex(array $arguments): array
    {
        $errors = fopen('php://memory', 'w+');
        Assert::assertIsResource($errors);

        $application = ApplicationFactory::create(new Output(false, false, '#', '-', $errors));

        $level = ob_get_level();
        ob_start();

        try {
            $exitCode = $application->run(['regex', '--no-ansi', '--no-visuals', ...$arguments]);
            $stdout = (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        rewind($errors);
        $stderr = (string) stream_get_contents($errors);
        fclose($errors);

        return [$exitCode, $stdout, $stderr];
    }

    /**
     * The same, through the binary in a process of its own, in the current
     * working directory.
     *
     * @param list<string> $arguments the command line after "regex"
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function runRegexProcess(array $arguments): array
    {
        $binary = \dirname(__DIR__, 2).'/bin/regex';
        $stdoutFile = (string) tempnam(sys_get_temp_dir(), 'regex-cli-out-');
        $stderrFile = (string) tempnam(sys_get_temp_dir(), 'regex-cli-err-');

        $environment = getenv();
        $environment['XDEBUG_MODE'] = 'off';

        try {
            $process = proc_open(
                [\PHP_BINARY, '-d', 'auto_prepend_file=', $binary, '--no-ansi', '--no-visuals', ...$arguments],
                [0 => ['pipe', 'r'], 1 => ['file', $stdoutFile, 'w'], 2 => ['file', $stderrFile, 'w']],
                $pipes,
                (string) getcwd(),
                $environment,
            );
            Assert::assertIsResource($process);
            fclose($pipes[0]);
            $exitCode = proc_close($process);

            return [$exitCode, (string) file_get_contents($stdoutFile), (string) file_get_contents($stderrFile)];
        } finally {
            @unlink($stdoutFile);
            @unlink($stderrFile);
        }
    }
}
