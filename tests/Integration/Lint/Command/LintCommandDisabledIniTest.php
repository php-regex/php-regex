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

namespace PHPRegex\Tests\Integration\Lint\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The lint where ini_set() or ini_get() is disabled (disable_functions).
 *
 * Without ini_set() the witness of a proven verdict cannot be replayed
 * under the limits it needs: the replay is skipped, and the verdict is
 * reported as proven, at its own severity, as it is where no replay is
 * attempted. Without ini_get() the memory limit cannot be read: the files
 * are read all the same.
 *
 * A disabled function is set per process: each case runs in a PHP process
 * of its own.
 *
 * Oracle (PHP 8.4, PCRE2 10.49, JIT off): "/^(a|a)+$/" on "aa" x 8 . "!"
 * fails with PREG_BACKTRACK_LIMIT_ERROR under the default backtrack limit.
 */
final class LintCommandDisabledIniTest extends TestCase
{
    private const REDOS_SOURCE = "<?php\npreg_match('/^(a|a)+\$/', \$x);\n";

    /**
     * Two patterns the proof leaves to the heuristic (a subroutine call, a
     * backreference), each on a line of its own: lines 2 and 3.
     */
    private const HEURISTIC_SOURCE = "<?php\npreg_match('/(a+)+(?1)\$/', \$s);\npreg_match('/((a+)+)\\\\2\$/', \$s);\n";

    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/regex-disabled-ini-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->directory = (string) realpath($this->directory);
        file_put_contents($this->directory.'/test.php', self::REDOS_SOURCE);
    }

    protected function tearDown(): void
    {
        foreach (array_diff(scandir($this->directory) ?: [], ['.', '..']) as $file) {
            unlink($this->directory.'/'.$file);
        }
        rmdir($this->directory);
    }

    /**
     * The same run with ini_set() available replays the attack and fails
     * with one ReDoS error; with ini_set() disabled the replay is skipped
     * and the proven verdict still fails the run as one ReDoS error.
     */
    #[Test]
    public function test_confirmed_mode_reports_a_proven_verdict_whose_replay_was_skipped(): void
    {
        $args = ['lint', $this->directory, '--redos', '--redos-mode=confirmed', '--format=json', '--jobs=1'];

        [$exitCode, $stdout, $stderr] = $this->regex($args);
        $this->assertSame(1, $exitCode, $stdout.$stderr);
        $this->assertSame(1, self::stats($stdout)['redos'] ?? 0, 'With ini_set(), the replay confirms the verdict.');

        [$exitCode, $stdout, $stderr] = $this->regex($args, 'ini_set');
        $this->assertSame(1, $exitCode, $stdout.$stderr);
        $this->assertSame(1, self::stats($stdout)['redos'] ?? 0, $stdout);
    }

    /**
     * A replay that was skipped found nothing because it never ran: the
     * hint of the error does not say the runtime checks found no evidence.
     */
    #[Test]
    public function test_confirmed_mode_hint_does_not_claim_a_skipped_replay_found_nothing(): void
    {
        [$exitCode, $stdout, $stderr] = $this->regex(['lint', $this->directory, '--redos', '--redos-mode=confirmed', '--format=json', '--jobs=1'], 'ini_set');
        $this->assertSame(1, $exitCode, $stdout.$stderr);

        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded, $stdout);
        $this->assertIsArray($decoded['results'] ?? null, $stdout);
        $hints = [];
        foreach ($decoded['results'] as $result) {
            $this->assertIsArray($result);
            $this->assertIsArray($result['issues'] ?? null);
            foreach ($result['issues'] as $issue) {
                $this->assertIsArray($issue);
                if ('regex.lint.redos' === ($issue['issueId'] ?? null)) {
                    $this->assertIsString($issue['hint'] ?? null);
                    $hints[] = $issue['hint'];
                }
            }
        }

        $this->assertCount(1, $hints, $stdout);
        $this->assertStringContainsString('engine limits unavailable', $stdout, 'The message says why the replay was skipped.');
        $this->assertStringNotContainsString('found no evidence within limits', (string) $hints[0]);
    }

    /**
     * The services the lint runs on report the skipped replay the same way:
     * the issue is an error whose analysis was not replayed, and its
     * problem in the report is critical, the severity of the proof.
     */
    #[Test]
    public function test_confirmed_mode_api_reports_a_skipped_replay_at_the_proven_severity(): void
    {
        $result = self::runWith('ini_set', <<<'PHP_WRAP'
            $service = new \PHPRegex\Linter\AnalysisService(
                \PHPRegex\Parser\RegexParser::create(),
                redosThreshold: 'high',
                redosMode: \PHPRegex\Redos\RedosMode::Confirmed,
                redosEnabled: true,
            );
            $occurrence = new \PHPRegex\Linter\PatternOccurrence('/^(a|a)+$/', 'file.php', 1, 'php:preg_match()');
            $out = ['ini_set' => function_exists('ini_set'), 'issues' => [], 'problems' => []];
            foreach ($service->lint([$occurrence]) as $issue) {
                if (isset($issue['analysis'])) {
                    $out['issues'][] = [
                        'type' => $issue['type'],
                        'replayed' => $issue['analysis']->replayed,
                        'proof' => $issue['analysis']->proof->value,
                        'severity' => $issue['analysis']->severity->value,
                    ];
                }
            }
            $report = (new \PHPRegex\Linter\LintService($service, new \PHPRegex\Linter\Source\PatternSourceCollection([])))
                ->analyze([$occurrence], new \PHPRegex\Linter\LintRequest([], [], 0, checkRedos: true));
            foreach ($report->results as $result) {
                foreach ($result['problems'] as $problem) {
                    if (\PHPRegex\Linter\DiagnosticType::Security === $problem->type) {
                        $out['problems'][] = $problem->severity->value;
                    }
                }
            }
            $out['redos'] = $report->stats['redos'] ?? 0;
            return $out;
            PHP_WRAP);

        $this->assertFalse($result['ini_set'], 'The child process runs with ini_set() disabled.');
        $this->assertSame([['type' => 'error', 'replayed' => null, 'proof' => 'proven', 'severity' => 'critical']], $result['issues']);
        $this->assertSame(['critical'], $result['problems']);
        $this->assertSame(1, $result['redos']);
    }

    /**
     * A heuristic verdict whose replay was skipped is no confirmation and
     * no clean bill: it is reported as a warning, at its heuristic
     * severity, its message saying it was not replayed and why. The run
     * exits as a run with warnings only.
     *
     * Oracle (PHP 8.4, PCRE2 10.49, JIT off): "/(a+)+(?1)$/" and
     * "/((a+)+)\2$/" on "a" x 50 . "!" fail with PREG_BACKTRACK_LIMIT_ERROR
     * under the default backtrack limit; both match "aaa".
     */
    #[Test]
    public function test_confirmed_mode_reports_a_heuristic_verdict_whose_replay_was_skipped_as_a_warning(): void
    {
        file_put_contents($this->directory.'/test.php', self::HEURISTIC_SOURCE);

        [$exitCode, $stdout, $stderr] = $this->regex(['lint', $this->directory, '--redos', '--redos-mode=confirmed', '--format=json', '--jobs=1'], 'ini_set');

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame(0, self::stats($stdout)['errors'] ?? null, $stdout);
        $this->assertSame(0, self::stats($stdout)['redos'] ?? 0, 'Only errors are counted as ReDoS verdicts.');

        $issues = self::redosIssues($stdout);
        $this->assertSame([2, 3], array_column($issues, 'line'), $stdout);
        foreach ($issues as $issue) {
            $this->assertSame('warning', $issue['type'] ?? null, $stdout);
            $this->assertIsString($issue['message'] ?? null);
            $this->assertStringContainsString('not replayed', (string) $issue['message']);
            $this->assertStringContainsString('engine limits unavailable', (string) $issue['message']);
            $this->assertStringNotContainsString('confirmed, evidence', (string) $issue['message']);
            $this->assertIsString($issue['hint'] ?? null);
            $this->assertStringNotContainsString('observed evidence of excessive backtracking', (string) $issue['hint']);
            $this->assertStringNotContainsString('found no evidence within limits', (string) $issue['hint']);
            $this->assertIsArray($issue['analysis'] ?? null);
            $this->assertSame('heuristic', $issue['analysis']['proof'] ?? null);
            $this->assertSame('critical', $issue['analysis']['severity'] ?? null);
        }
    }

    /**
     * With ini_set() available the same two heuristic verdicts are
     * replayed, reproduce, and fail the run as two ReDoS errors.
     */
    #[Test]
    public function test_confirmed_mode_reports_a_replayed_heuristic_verdict_as_an_error(): void
    {
        file_put_contents($this->directory.'/test.php', self::HEURISTIC_SOURCE);

        [$exitCode, $stdout, $stderr] = $this->regex(['lint', $this->directory, '--redos', '--redos-mode=confirmed', '--format=json', '--jobs=1']);

        $this->assertSame(1, $exitCode, $stdout.$stderr);
        $this->assertSame(2, self::stats($stdout)['redos'] ?? 0, $stdout);

        $issues = self::redosIssues($stdout);
        $this->assertSame([2, 3], array_column($issues, 'line'), $stdout);
        foreach ($issues as $issue) {
            $this->assertSame('error', $issue['type'] ?? null, $stdout);
            $this->assertIsString($issue['message'] ?? null);
            $this->assertStringContainsString('(heuristic)', (string) $issue['message']);
            $this->assertStringContainsString('confirmed, evidence: backtrack_limit', (string) $issue['message']);
            $this->assertStringNotContainsString('not replayed', (string) $issue['message']);
        }
    }

    /**
     * Without ini_get() the lint reads the files and reports what it
     * reports with it.
     */
    #[Test]
    public function test_lint_runs_where_ini_get_is_disabled(): void
    {
        $args = ['lint', $this->directory, '--format=json', '--jobs=1'];

        [$expectedExitCode, $expected] = $this->regex($args);
        [$exitCode, $stdout, $stderr] = $this->regex($args, 'ini_get');

        $this->assertStringNotContainsString('ini_get', $stdout.$stderr);
        $this->assertSame($expectedExitCode, $exitCode, $stdout.$stderr);
        $this->assertSame(self::stats($expected), self::stats($stdout));
    }

    /**
     * Runs bin/regex in the test directory, with the function disabled
     * when one is named.
     *
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function regex(array $args, ?string $disabled = null): array
    {
        $command = [\PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'auto_prepend_file='];
        if (null !== $disabled) {
            $command = [...$command, '-d', 'disable_functions='.$disabled];
        }

        $process = proc_open(
            [...$command, \dirname(__DIR__, 4).'/bin/regex', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->directory,
        );
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function stats(string $stdout): array
    {
        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded, $stdout);
        self::assertIsArray($decoded['stats'] ?? null, $stdout);

        return $decoded['stats'];
    }

    /**
     * The ReDoS issues of a JSON report, in report order.
     *
     * @return list<array<array-key, mixed>>
     */
    private static function redosIssues(string $stdout): array
    {
        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded, $stdout);
        self::assertIsArray($decoded['results'] ?? null, $stdout);

        $issues = [];
        foreach ($decoded['results'] as $result) {
            self::assertIsArray($result);
            self::assertIsArray($result['issues'] ?? null);
            foreach ($result['issues'] as $issue) {
                self::assertIsArray($issue);
                if ('regex.lint.redos' === ($issue['issueId'] ?? null)) {
                    $issues[] = $issue;
                }
            }
        }

        return $issues;
    }

    /**
     * Runs the code in a PHP process with the function disabled; the code
     * returns what it saw, printed back as JSON.
     *
     * @return array<array-key, mixed>
     */
    private static function runWith(string $disabled, string $code): array
    {
        $script = 'require '.var_export(\dirname(__DIR__, 4).'/vendor/autoload.php', true).';'
            .'$run = static function () { '.$code.' };'
            .'echo json_encode($run(), JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);';

        $process = proc_open(
            [\PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'auto_prepend_file=', '-d', 'disable_functions='.$disabled, '-r', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, $errors.$output);
        $decoded = json_decode($output, true);
        self::assertIsArray($decoded, $errors.$output);

        return $decoded;
    }
}
