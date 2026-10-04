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
 * bin/corpus, run against a scratch root holding its own corpus.json and
 * corpus/ directory, with a local file:// origin: no network.
 */
final class CorpusScriptTest extends TestCase
{
    private string $root = '';

    private string $origin = '';

    private string $firstCommit = '';

    private string $secondCommit = '';

    private string $extraOrigin = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/php-regex-corpus-'.bin2hex(random_bytes(6));
        $this->origin = $this->root.'-origin';
        mkdir($this->origin, 0o777, true);
        mkdir($this->root, 0o777, true);

        $this->git($this->origin, 'init', '--initial-branch=main', '.');
        $this->git($this->origin, 'config', 'user.email', 'corpus@example.com');
        $this->git($this->origin, 'config', 'user.name', 'Corpus Test');
        $this->git($this->origin, 'config', 'uploadpack.allowAnySHA1InWant', 'true');
        file_put_contents($this->origin.'/pattern.php', "<?php\n\npreg_match('/(a+)+\$/', \$input);\n");
        $this->git($this->origin, 'add', '.');
        $this->git($this->origin, 'commit', '-m', 'first');
        $this->firstCommit = $this->head($this->origin);

        file_put_contents($this->origin.'/pattern.php', "<?php\n\npreg_match('/(a|a)*\$/';\n");
        $this->git($this->origin, 'add', '.');
        $this->git($this->origin, 'commit', '-m', 'second');
        $this->secondCommit = $this->head($this->origin);

        $this->writeManifest($this->firstCommit);
    }

    protected function tearDown(): void
    {
        $paths = [$this->root, $this->origin, $this->extraOrigin];
        exec('rm -rf '.implode(' ', array_map(escapeshellarg(...), array_filter($paths))));
    }

    #[Test]
    public function test_install_checks_out_the_pinned_commit_from_an_empty_corpus(): void
    {
        [$code, $output] = $this->script(['install']);

        $this->assertSame(0, $code, $output);
        $this->assertSame($this->firstCommit, $this->head($this->root.'/corpus/src'), $output);
        $this->assertFileExists($this->root.'/corpus/src/pattern.php');
    }

    #[Test]
    public function test_install_ignores_how_far_the_origin_moved(): void
    {
        $this->script(['install']);
        $this->advanceOrigin();

        [$code, $output] = $this->script(['install']);

        $this->assertSame(0, $code, $output);
        $this->assertSame($this->firstCommit, $this->head($this->root.'/corpus/src'), $output);
        $this->assertSame($this->firstCommit, $this->manifestCommit(), 'install must not rewrite corpus.json');
    }

    #[Test]
    public function test_install_follows_a_pin_that_update_moved(): void
    {
        $this->script(['install']);
        $this->advanceOrigin();

        [$code, $output] = $this->script(['update']);

        $this->assertSame(0, $code, $output);
        $this->assertSame($this->secondCommit, $this->manifestCommit(), $output);

        [$code, $output] = $this->script(['install']);

        $this->assertSame(0, $code, $output);
        $this->assertSame($this->secondCommit, $this->head($this->root.'/corpus/src'), $output);
    }

    #[Test]
    public function test_install_discards_local_changes_only_with_force(): void
    {
        $this->script(['install']);
        file_put_contents($this->root.'/corpus/src/pattern.php', 'changed');

        [$withoutForce] = $this->script(['install']);
        $this->assertSame(1, $withoutForce, 'a dirty checkout must fail without --force');

        [$code, $output] = $this->script(['install', '--force']);
        $this->assertSame(0, $code, $output);
        $this->assertSame(
            "<?php\n\npreg_match('/(a+)+\$/', \$input);\n",
            file_get_contents($this->root.'/corpus/src/pattern.php'),
        );
    }

    #[Test]
    public function test_install_fails_naming_a_repository_whose_pin_cannot_be_fetched(): void
    {
        $this->writeManifest(str_repeat('0', 40));

        [$code, $output] = $this->script(['install']);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('src', $output);
    }

    #[Test]
    public function test_install_prunes_checkouts_that_are_no_longer_listed(): void
    {
        $this->script(['install']);
        mkdir($this->root.'/corpus/extra', 0o777, true);
        $this->git($this->root.'/corpus/extra', 'init', '.');

        [$code, $output] = $this->script(['install']);

        $this->assertSame(0, $code, $output);
        $this->assertFileDoesNotExist($this->root.'/corpus/extra');
    }

    #[Test]
    public function test_install_debug_prints_the_git_trace(): void
    {
        [$code, $output] = $this->script(['install', '--debug']);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString("git 'fetch'", $output);
    }

    #[Test]
    public function test_install_in_parallel_reaches_the_same_pins(): void
    {
        $this->addSecondOriginRepository();

        [$code, $output] = $this->script(['install', '--jobs', '2']);

        $this->assertSame(0, '' === $output ? 1 : $code, $output);
        $this->assertSame($this->firstCommit, $this->head($this->root.'/corpus/src'));
        $this->assertSame($this->secondCommit, $this->head($this->root.'/corpus/other'));
    }

    #[Test]
    public function test_update_in_parallel_reaches_the_same_pins(): void
    {
        $this->addSecondOriginRepository();
        $this->script(['install', '--jobs', '2']);
        $this->advanceOrigin();

        [$code, $output] = $this->script(['update', '--jobs', '2']);

        $this->assertSame(0, $code, $output);
        $this->assertSame($this->secondCommit, $this->manifestCommit());
    }

    #[Test]
    public function test_install_rejects_the_update_only_options(): void
    {
        foreach ([['install', '--clone-only'], ['install', '--no-clone'], ['install', '--as', 'x'], ['install', '--branch', 'main'], ['install', '--depth', '1']] as $arguments) {
            [$code, $output] = $this->script($arguments);

            $this->assertSame(1, $code, implode(' ', $arguments));
            $this->assertStringContainsString('update command', $output);
        }
    }

    #[Test]
    public function test_install_fails_when_the_manifest_is_missing(): void
    {
        unlink($this->root.'/corpus.json');

        [$code, $output] = $this->script(['install']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('corpus.json', $output);
    }

    #[Test]
    public function test_install_prints_each_removed_checkout_once(): void
    {
        $this->script(['install']);
        mkdir($this->root.'/corpus/extra', 0o777, true);
        $this->git($this->root.'/corpus/extra', 'init', '.');

        [, $output] = $this->script(['install']);

        $this->assertSame(1, substr_count($output, '[REMOVED]'));
    }

    #[Test]
    public function test_the_summary_counts_an_install_that_built_something(): void
    {
        [$code, $output] = $this->script(['install']);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Installed:', $output);
    }

    #[Test]
    public function test_update_does_not_move_the_pin_of_a_checkout_it_skipped(): void
    {
        $this->script(['install']);
        $this->git($this->root.'/corpus/src', 'config', 'user.email', 'corpus@example.com');
        $this->git($this->root.'/corpus/src', 'config', 'user.name', 'Corpus Test');
        file_put_contents($this->root.'/corpus/src/new.php', '<?php');
        $this->git($this->root.'/corpus/src', 'add', '.');
        $this->git($this->root.'/corpus/src', 'commit', '-m', 'local');
        file_put_contents($this->root.'/corpus/src/pattern.php', 'dirty');

        [$code] = $this->script(['update']);

        $this->assertSame(0, $code);
        $this->assertSame($this->firstCommit, $this->manifestCommit(), 'a skipped checkout must not move its pin');
    }

    #[Test]
    public function test_update_does_not_move_the_pin_of_a_failed_pull(): void
    {
        $this->script(['install']);
        $this->git($this->root.'/corpus/src', 'checkout', '-B', 'main', $this->firstCommit);
        $this->advanceOrigin();
        $this->git($this->root.'/corpus/src', 'config', 'user.email', 'corpus@example.com');
        $this->git($this->root.'/corpus/src', 'config', 'user.name', 'Corpus Test');
        file_put_contents($this->root.'/corpus/src/pattern.php', 'local conflict');
        $this->git($this->root.'/corpus/src', 'add', '.');
        $this->git($this->root.'/corpus/src', 'commit', '-m', 'local');

        [$code] = $this->script(['update']);

        $this->assertSame(1, $code);
        $this->assertSame($this->firstCommit, $this->manifestCommit(), 'a failed pull must not move its pin');
    }

    #[Test]
    public function test_write_manifest_keeps_the_recorded_branch(): void
    {
        $this->script(['install']);

        [$code, $output] = $this->script(['update', '--write-manifest']);
        $this->assertSame(0, $code, $output);

        $this->assertSame('main', $this->manifestEntryField('src', 'branch'));

        [$code, $output] = $this->script(['update']);
        $this->assertSame(0, $code, $output);
    }

    #[Test]
    public function test_write_manifest_refuses_a_repository_outside_corpus(): void
    {
        $this->script(['install']);
        $outside = $this->root.'-outside';
        mkdir($outside, 0o777, true);
        $this->git($outside, 'init', '--initial-branch=main', '.');
        $this->git($outside, 'config', 'user.email', 'corpus@example.com');
        $this->git($outside, 'config', 'user.name', 'Corpus Test');
        $this->git($outside, 'remote', 'add', 'origin', 'file://'.$this->origin);
        symlink($outside, $this->root.'/corpus/link');

        [$code, $output] = $this->script(['update', '--write-manifest']);

        $this->assertSame(0, $code, $output);
        $keys = $this->manifestRepositoryKeys();
        $this->assertNotContains('link', $keys, $output);
        $this->assertSame(['src'], $keys, 'no absolute or symlinked key may be written');
    }

    #[Test]
    public function test_add_rejects_a_hidden_target(): void
    {
        [$code, $output] = $this->script(['update', '--add', 'file://'.$this->origin, '--as', '.hidden']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('.hidden', $output);
    }

    #[Test]
    public function test_add_derives_a_vendor_name_target_from_the_url(): void
    {
        [$code, $output] = $this->script(['update', '--add', 'file://'.$this->origin]);

        $expected = basename(dirname($this->origin)).'/'.basename($this->origin);
        $this->assertSame(0, $code, $output);
        $this->assertContains($expected, $this->manifestRepositoryKeys(), $output);
        $this->assertDirectoryExists($this->root.'/corpus/'.$expected);
    }

    #[Test]
    public function test_add_refuses_a_vendor_name_target_already_taken_by_another_url(): void
    {
        $derived = basename(dirname($this->origin)).'/'.basename($this->origin);
        $manifest = json_decode((string) file_get_contents($this->root.'/corpus.json'), true);
        $manifest = \is_array($manifest) ? $manifest : [];
        $repositories = \is_array($manifest['repositories'] ?? null) ? $manifest['repositories'] : [];
        $repositories[$derived] = ['url' => 'file://'.$this->root.'-elsewhere'];
        $manifest['repositories'] = $repositories;
        file_put_contents($this->root.'/corpus.json', json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        [$code, $output] = $this->script(['update', '--add', 'file://'.$this->origin]);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('--as', $output);
    }

    #[Test]
    public function test_add_refuses_a_url_without_a_vendor_segment(): void
    {
        [$code, $output] = $this->script(['update', '--add', 'file:///solo-'.bin2hex(random_bytes(4))]);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('no vendor segment', $output);
        $this->assertStringContainsString('--as', $output);
    }

    #[Test]
    public function test_install_force_keeps_a_dirty_unlisted_checkout(): void
    {
        $this->script(['install']);
        mkdir($this->root.'/corpus/extra', 0o777, true);
        $this->git($this->root.'/corpus/extra', 'init', '.');
        $this->git($this->root.'/corpus/extra', 'config', 'user.email', 'corpus@example.com');
        $this->git($this->root.'/corpus/extra', 'config', 'user.name', 'Corpus Test');
        file_put_contents($this->root.'/corpus/extra/keep.php', '<?php');
        $this->git($this->root.'/corpus/extra', 'add', '.');
        $this->git($this->root.'/corpus/extra', 'commit', '-m', 'unpushed');
        file_put_contents($this->root.'/corpus/extra/keep.php', 'dirty');

        [$code] = $this->script(['install', '--force']);

        $this->assertSame(0, $code);
        $this->assertFileExists($this->root.'/corpus/extra/keep.php', 'install --force must not delete unlisted work');
    }

    #[Test]
    public function test_install_refuses_a_non_empty_target(): void
    {
        mkdir($this->root.'/corpus/src', 0o777, true);
        file_put_contents($this->root.'/corpus/src/leftover.txt', 'junk');

        [$code, $output] = $this->script(['install']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('not empty', $output);
    }

    #[Test]
    public function test_timeout_zero_is_refused(): void
    {
        [$code, $output] = $this->script(['update', '--timeout', '0']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('positive integer', $output);
    }

    #[Test]
    public function test_no_command_prints_the_usage_and_fails(): void
    {
        [$code, $output] = $this->script([]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Usage:', $output);
        $this->assertStringContainsString('bin/corpus install', $output);
    }

    #[Test]
    public function test_help_exits_with_zero(): void
    {
        [$code, $output] = $this->script(['--help']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('bin/corpus update', $output);
        $this->assertStringContainsString('--root', $output);
    }

    #[Test]
    public function test_an_unknown_command_is_refused(): void
    {
        [$code, $output] = $this->script(['frobnicate']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Unknown command', $output);
    }

    private function addSecondOriginRepository(): void
    {
        $second = $this->root.'-origin-other';
        mkdir($second, 0o777, true);
        $this->git($second, 'init', '--initial-branch=main', '.');
        $this->git($second, 'config', 'user.email', 'corpus@example.com');
        $this->git($second, 'config', 'user.name', 'Corpus Test');
        $this->git($second, 'config', 'uploadpack.allowAnySHA1InWant', 'true');
        file_put_contents($second.'/other.php', "<?php

preg_match('/(b+)+\$/', \$input);
");
        $this->git($second, 'add', '.');
        $this->git($second, 'commit', '-m', 'first');
        $this->secondCommit = $this->head($second);
        $this->extraOrigin = $second;

        $manifest = json_decode((string) file_get_contents($this->root.'/corpus.json'), true);
        $manifest = \is_array($manifest) ? $manifest : [];
        $repositories = \is_array($manifest['repositories'] ?? null) ? $manifest['repositories'] : [];
        $repositories['other'] = ['url' => 'file://'.$second, 'branch' => 'main', 'commit' => $this->secondCommit];
        $manifest['repositories'] = $repositories;
        file_put_contents($this->root.'/corpus.json', json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string}
     */
    /**
     * @param list<string> $arguments
     *
     * @return array{int, string}
     */
    private function script(array $arguments): array
    {
        $command = [
            \PHP_BINARY,
            \dirname(__DIR__, 3).'/bin/corpus',
            '--root', $this->root,
            '--jobs', '1',
            ...array_values($arguments),
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    private function writeManifest(string $commit): void
    {
        file_put_contents($this->root.'/corpus.json', json_encode([
            'defaults' => ['remote' => 'origin', 'depth' => 1, 'protocol' => 'https'],
            'repositories' => [
                'src' => ['url' => 'file://'.$this->origin, 'branch' => 'main', 'commit' => $commit],
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return list<string>
     */
    private function manifestRepositoryKeys(): array
    {
        $manifest = json_decode((string) file_get_contents($this->root.'/corpus.json'), true);
        $repositories = \is_array($manifest) && \is_array($manifest['repositories'] ?? null)
            ? $manifest['repositories']
            : [];

        return array_keys($repositories);
    }

    private function manifestEntryField(string $repository, string $field): mixed
    {
        $manifest = json_decode((string) file_get_contents($this->root.'/corpus.json'), true);
        $entry = [];
        if (\is_array($manifest)
            && \is_array($manifest['repositories'] ?? null)
            && \is_array($manifest['repositories'][$repository] ?? null)) {
            $entry = $manifest['repositories'][$repository];
        }

        return $entry[$field] ?? null;
    }

    private function manifestCommit(): string
    {
        $manifest = json_decode((string) file_get_contents($this->root.'/corpus.json'), true);
        $src = [];
        if (\is_array($manifest)
            && \is_array($manifest['repositories'] ?? null)
            && \is_array($manifest['repositories']['src'] ?? null)) {
            $src = $manifest['repositories']['src'];
        }

        $commit = $src['commit'] ?? null;

        return \is_string($commit) ? $commit : '';
    }

    private function advanceOrigin(): void
    {
        file_put_contents($this->origin.'/pattern.php', "<?php\n\npreg_match('/(b+)+\$/', \$input);\n");
        $this->git($this->origin, 'add', '.');
        $this->git($this->origin, 'commit', '-m', 'third');
        $this->secondCommit = $this->head($this->origin);
    }

    private function head(string $dir): string
    {
        return trim((string) shell_exec('git -C '.escapeshellarg($dir).' rev-parse HEAD'));
    }

    private function git(string $dir, string ...$arguments): void
    {
        $output = [];
        exec(
            'git -C '.escapeshellarg($dir).' '.implode(' ', array_map(escapeshellarg(...), $arguments)).' 2>&1',
            $output,
            $code,
        );
        $this->assertSame(0, $code, implode("\n", $output));
    }
}
