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

namespace PHPRegex\Tests\Unit\Corpus;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * bin/corpus-lint, run against a scratch checkout holding its own corpus/
 * tree: every repository is judged under the PHP its own composer.json
 * requires, and the per-repository logs merge into one file.
 */
final class CorpusLintScriptTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/php-regex-corpus-lint-'.bin2hex(random_bytes(6));
        foreach ([
            'corpus/ownerA/repoNew' => true,
            'corpus/ownerB/repoOld' => true,
            'corpus/ownerC/repoBare' => false,
            'corpus/ownerD/repoClean' => true,
        ] as $repo => $withComposer) {
            mkdir($this->root.'/'.$repo, 0o777, true);
            if ($withComposer) {
                $constraint = match (basename($repo)) {
                    'repoNew' => '^8.2',
                    'repoClean' => '^8.1',
                    default => '>=7.4',
                };
                file_put_contents(
                    $this->root.'/'.$repo.'/composer.json',
                    sprintf("{\n    \"require\": {\n        \"php\": \"%s\"\n    }\n}\n", $constraint),
                );
            }
        }

        file_put_contents($this->root.'/corpus/ownerA/repoNew/patterns.php', "<?php\n\npreg_match('/ab{1}c/', \$x);\n");
        file_put_contents($this->root.'/corpus/ownerB/repoOld/patterns.php', "<?php\n\npreg_match('/\\p{scx:Greek}/u', \$x);\n");
        file_put_contents($this->root.'/corpus/ownerC/repoBare/patterns.php', "<?php\n\npreg_match('/cd{1}e/', \$x);\n");
        file_put_contents($this->root.'/corpus/ownerD/repoClean/patterns.php', "<?php\n\npreg_match('/^\\d{4}-\\d{2}-\\d{2}\$/', \$x);\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    #[Test]
    public function test_sweep_judges_each_repository_under_its_own_target(): void
    {
        [$code, $output] = $this->script([]);

        $log = (string) file_get_contents($this->root.'/corpus.log');

        $this->assertSame(1, $code, $output);
        $this->assertSame(
            '### corpus/ownerA/repoNew — Target: PHP 8.2, PCRE2 10.40 (composer.json require.php) — exit 0',
            $this->line($log, '### corpus/ownerA/repoNew'),
            $output.$log,
        );
        $this->assertSame(
            '### corpus/ownerB/repoOld — Target: PHP 8.1, PCRE2 10.39 (composer.json require.php) — exit 1',
            $this->line($log, '### corpus/ownerB/repoOld'),
            $log,
        );
        $this->assertSame(
            '### corpus/ownerC/repoBare — Target: PHP 8.1, PCRE2 10.39 (--php-version, fallback: no require.php) — exit 0',
            $this->line($log, '### corpus/ownerC/repoBare'),
            $log,
        );

        // The clamped floor of repoOld is announced inside its own section.
        $this->assertSame(
            1,
            substr_count($log, 'Note: '),
            $log,
        );
        $this->assertStringContainsString(
            'composer.json require.php allows PHP 7.4, older than the PHP 8.1 this library supports',
            $this->line($log, 'Note: '),
            $log,
        );

        // Per-repository findings keep the exact shape of a single-run log.
        $this->assertStringContainsString('WARN  Quantifier is redundant', $log);
        $this->assertStringContainsString('FAIL  Invalid or unsupported Unicode property', $log);

        // The merged log ends on the last section: no sweep summary at
        // the bottom, the per-repository headers carry everything.
        $this->assertStringNotContainsString('corpus-lint summary', $log);
        $this->assertStringNotContainsString('Total:', $log);
        $this->assertStringNotContainsString(' repositories,', $log);
        $this->assertStringNotContainsString('fallback target', $log);
        $this->assertStringNotContainsString('floor clamped', $log);

        // One part per repository, kept beside the merged log.
        $this->assertFileExists($this->root.'/parts/ownerA__repoNew.log');
        $this->assertFileExists($this->root.'/parts/ownerB__repoOld.log');
        $this->assertFileExists($this->root.'/parts/ownerC__repoBare.log');
    }

    #[Test]
    public function test_console_follows_the_corpus_update_style(): void
    {
        [, $output] = $this->script([]);

        $lines = array_values(array_filter(explode("\n", $output), static fn (string $line): bool => '' !== trim($line)));

        // No header: the console opens on the first repository row.
        $this->assertStringNotContainsString('Corpus Linter', $output);
        $this->assertStringNotContainsString('Runtime :', $output);
        $this->assertStringNotContainsString('Output  :', $output);
        $this->assertStringNotContainsString('Parts   :', $output);

        // One bare paginated row per repository — counter, name, then its
        // own target — with no tag and no findings detail: the merged log
        // and the summary carry those.
        $this->assertSame('[1/4] ownerA/repoNew  PHP 8.2, PCRE2 10.40', $lines[0]);
        $this->assertStringContainsString('[2/4] ownerB/repoOld  PHP 8.1, PCRE2 10.39', $output);
        $this->assertStringContainsString('[3/4] ownerC/repoBare  PHP 8.1, PCRE2 10.39', $output);
        $this->assertStringContainsString('[4/4] ownerD/repoClean  PHP 8.1, PCRE2 10.39', $output);

        $this->assertStringNotContainsString('[OK]', $output);
        $this->assertStringNotContainsString('[WARN]', $output);
        $this->assertStringNotContainsString('[FAIL]', $output);
        $this->assertStringNotContainsString('FAIL — ', $output);
        $this->assertStringNotContainsString('floor clamped', $output);

        // No closing summary on the console: the run ends on the last row.
        $this->assertStringNotContainsString('Summary', $output);
        $this->assertStringNotContainsString('Repositories :', $output);
        $this->assertStringNotContainsString('With findings', $output);
        $this->assertStringNotContainsString('With errors', $output);
        $this->assertStringNotContainsString('Verdicts', $output);
        $this->assertStringNotContainsString('Fallback', $output);
        $this->assertStringNotContainsString('Clamped', $output);
        $this->assertStringNotContainsString('Not judged', $output);
        $this->assertStringNotContainsString('Elapsed', $output);
    }

    #[Test]
    public function test_pagination_pads_the_counter_to_the_width_of_the_total(): void
    {
        $this->loadScriptFunctions();

        $this->assertSame('[  1/314]', paginationPrefix(1, 314));
        $this->assertSame('[ 10/314]', paginationPrefix(10, 314));
        $this->assertSame('[100/314]', paginationPrefix(100, 314));
        $this->assertSame('[2/4]', paginationPrefix(2, 4));
    }

    #[Test]
    public function test_only_sweeps_the_repositories_whose_path_matches(): void
    {
        [$code, $output] = $this->script(['--only', 'ownerB']);

        $log = (string) file_get_contents($this->root.'/corpus.log');

        $this->assertSame(1, $code, $output);
        $this->assertSame(1, substr_count($log, '### corpus/'), $log);
        $this->assertStringContainsString('### corpus/ownerB/repoOld', $log);
    }

    /**
     * A repository whose floor ships PCRE1 is refused, not judged: its
     * section says so, with no target and the refusal's exit code, and the
     * sweep does not count it as clean.
     */
    #[Test]
    public function test_a_repository_on_pcre1_is_recorded_as_refused(): void
    {
        mkdir($this->root.'/corpus/ownerE/repoAncient', 0o777, true);
        file_put_contents($this->root.'/corpus/ownerE/repoAncient/composer.json', '{"require": {"php": ">=5.4"}}');
        file_put_contents($this->root.'/corpus/ownerE/repoAncient/patterns.php', "<?php\n\npreg_match('/a/', \$x);\n");

        [$code, $output] = $this->script(['--only', 'ownerE']);

        $log = (string) file_get_contents($this->root.'/corpus.log');

        $this->assertSame(1, $code, $output);
        $this->assertSame('### corpus/ownerE/repoAncient — Target: none — exit 2', $this->line($log, '### corpus/ownerE/repoAncient'), $log);
        $this->assertSame(
            'Error: composer.json require.php allows PHP 5.4: PHP 7.2 and earlier ship PCRE1, which this library does not judge. Name a PHP version of 7.3 or later to judge for.',
            $this->line($log, 'Error: '),
            $log,
        );
        $this->assertStringContainsString('[1/1] ownerE/repoAncient  none', $output);
    }

    #[Test]
    public function test_exit_is_zero_when_no_repository_has_errors(): void
    {
        [, $output] = $this->script(['--only', 'ownerA']);

        $this->assertStringContainsString('exit 0', (string) file_get_contents($this->root.'/corpus.log'), $output);
    }

    #[Test]
    public function test_run_command_returns_when_the_child_exits_even_if_a_worker_holds_the_descriptors(): void
    {
        $this->loadScriptFunctions();

        $child = <<<'CODE'
            $holder = pcntl_fork();
            if (0 === $holder) {
                sleep(60);
                exit(0);
            }
            fwrite(STDERR, 'progress noise before the parent exits');
            exit(3);
            CODE;

        $start = microtime(true);
        [$exitCode, $console] = runCommand([\PHP_BINARY, '-r', $child], null);
        $elapsed = microtime(true) - $start;

        $this->assertSame(3, $exitCode, $console);
        $this->assertStringContainsString('progress noise before the parent exits', (string) $console);
        $this->assertLessThan(
            15.0,
            $elapsed,
            'the command waited on a leaked output descriptor instead of returning when its child exited',
        );
    }

    #[Test]
    public function test_require_php_constraint_reads_the_repository_floor(): void
    {
        $this->loadScriptFunctions();

        $this->assertSame('^8.2', requirePhpConstraint($this->root.'/corpus/ownerA/repoNew'));
        $this->assertSame('>=7.4', requirePhpConstraint($this->root.'/corpus/ownerB/repoOld'));
        $this->assertNull(requirePhpConstraint($this->root.'/corpus/ownerC/repoBare'));
    }

    #[Test]
    public function test_plan_uses_composer_env_or_the_fallback_flag(): void
    {
        $this->loadScriptFunctions();

        $plan = planRepositoryLint($this->root.'/corpus/ownerA/repoNew', '8.1', '/tmp/ownerA__repoNew.log');
        $this->assertSame($this->root.'/corpus/ownerA/repoNew/composer.json', $plan['env']);
        $this->assertFalse($plan['fallback']);
        $this->assertSame(
            ['--ansi', 'lint', $this->root.'/corpus/ownerA/repoNew', '--output', '/tmp/ownerA__repoNew.log'],
            $plan['args'],
        );

        $plan = planRepositoryLint($this->root.'/corpus/ownerC/repoBare', '8.1', '/tmp/ownerC__repoBare.log');
        $this->assertNull($plan['env']);
        $this->assertTrue($plan['fallback']);
        $this->assertSame(
            ['--ansi', 'lint', $this->root.'/corpus/ownerC/repoBare', '--php-version', '8.1', '--output', '/tmp/ownerC__repoBare.log'],
            $plan['args'],
        );
    }

    #[Test]
    public function test_extract_target_line_strips_ansi_and_keeps_the_source(): void
    {
        $this->loadScriptFunctions();

        $console = "\e[1mTarget       \e[0m : PHP \e[33m8.1\e[0m, PCRE2 \e[33m10.39\e[0m (/tmp/x/corpus/ownerB/repoOld/composer.json require.php)\n";

        $this->assertSame(
            'PHP 8.1, PCRE2 10.39 (/tmp/x/corpus/ownerB/repoOld/composer.json require.php)',
            extractTargetLine($console),
        );
        $this->assertSame('', extractTargetLine("Runtime : PHP 8.4\n"));
    }

    #[Test]
    public function test_section_header_normalizes_the_source_path(): void
    {
        $this->loadScriptFunctions();

        $this->assertSame(
            '### corpus/ownerB/repoOld — Target: PHP 8.1, PCRE2 10.39 (composer.json require.php) — exit 1',
            sectionHeader(
                'corpus/ownerB/repoOld',
                'PHP 8.1, PCRE2 10.39 (/tmp/x/corpus/ownerB/repoOld/composer.json require.php)',
                1,
                false,
            ),
        );
    }

    #[Test]
    public function test_count_severities_reads_the_padded_badges(): void
    {
        $this->loadScriptFunctions();

        $part = "corpus/ownerA/repoNew/patterns.php:3:12\n".
            "    → /ab{1}c/\n".
            "   WARN  Quantifier is redundant; it matches exactly once.\n".
            "   TIP \n".
            "       - /ab{1}c/\n".
            "corpus/ownerB/repoOld/patterns.php:3:12\n".
            "   FAIL  Invalid or unsupported Unicode property\n";

        $this->assertSame(
            ['fail' => 1, 'warn' => 1, 'tip' => 1],
            countSeverities($part),
        );
    }

    /**
     * The suite loads bin/corpus as well, for its own functions: the two
     * scripts declare nothing under the same name, or the process dies on
     * the second one, whichever test needs it first.
     */
    #[Test]
    public function test_the_script_loads_beside_bin_corpus(): void
    {
        if (!\defined('CORPUS_INCLUDED')) {
            \define('CORPUS_INCLUDED', true);
        }
        require_once \dirname(__DIR__, 3).'/bin/corpus';
        $this->loadScriptFunctions();

        $this->assertTrue(\function_exists('deriveTargetPath'));
        $this->assertTrue(\function_exists('requirePhpConstraint'));
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function script(array $arguments): array
    {
        $command = [
            \PHP_BINARY,
            \dirname(__DIR__, 3).'/bin/corpus-lint',
            '--root', $this->root,
            '--output', $this->root.'/corpus.log',
            '--parts', $this->root.'/parts',
            ...$arguments,
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    private function loadScriptFunctions(): void
    {
        if (!\defined('CORPUS_LINT_INCLUDED')) {
            \define('CORPUS_LINT_INCLUDED', true);
            require \dirname(__DIR__, 3).'/bin/corpus-lint';
        }
    }

    private function line(string $haystack, string $prefix): string
    {
        foreach (explode("\n", $haystack) as $line) {
            if (str_starts_with($line, $prefix)) {
                return $line;
            }
        }

        return '';
    }
}
