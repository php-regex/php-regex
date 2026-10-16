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
        ] as $repo => $withComposer) {
            mkdir($this->root.'/'.$repo, 0o777, true);
            if ($withComposer) {
                $constraint = 'repoNew' === basename($repo) ? '^8.2' : '>=5.4';
                file_put_contents(
                    $this->root.'/'.$repo.'/composer.json',
                    sprintf("{\n    \"require\": {\n        \"php\": \"%s\"\n    }\n}\n", $constraint),
                );
            }
        }

        file_put_contents($this->root.'/corpus/ownerA/repoNew/patterns.php', "<?php\n\npreg_match('/ab{1}c/', \$x);\n");
        file_put_contents($this->root.'/corpus/ownerB/repoOld/patterns.php', "<?php\n\npreg_match('/\\p{scx:Greek}/u', \$x);\n");
        file_put_contents($this->root.'/corpus/ownerC/repoBare/patterns.php', "<?php\n\npreg_match('/cd{1}e/', \$x);\n");
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
            'composer.json require.php allows PHP 5.4, older than the PHP 8.1 this library supports',
            $this->line($log, 'Note: '),
            $log,
        );

        // Per-repository findings keep the exact shape of a single-run log.
        $this->assertStringContainsString('WARN  Quantifier is redundant', $log);
        $this->assertStringContainsString('FAIL  Invalid or unsupported Unicode property', $log);

        // The merged file ends with one summary line for the whole sweep.
        $this->assertStringContainsString('3 repositories', $log);
        $this->assertStringContainsString('1 with errors', $log);
        $this->assertStringContainsString('1 fallback target', $log);
        $this->assertStringContainsString('1 floor clamped', $log);

        // One part per repository, kept beside the merged log.
        $this->assertFileExists($this->root.'/parts/ownerA__repoNew.log');
        $this->assertFileExists($this->root.'/parts/ownerB__repoOld.log');
        $this->assertFileExists($this->root.'/parts/ownerC__repoBare.log');
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

    #[Test]
    public function test_exit_is_zero_when_no_repository_has_errors(): void
    {
        [, $output] = $this->script(['--only', 'ownerA']);

        $this->assertStringContainsString('exit 0', (string) file_get_contents($this->root.'/corpus.log'), $output);
    }

    #[Test]
    public function test_require_php_constraint_reads_the_repository_floor(): void
    {
        $this->loadScriptFunctions();

        $this->assertSame('^8.2', requirePhpConstraint($this->root.'/corpus/ownerA/repoNew'));
        $this->assertSame('>=5.4', requirePhpConstraint($this->root.'/corpus/ownerB/repoOld'));
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
