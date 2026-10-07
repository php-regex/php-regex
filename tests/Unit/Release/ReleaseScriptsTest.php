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

namespace PHPRegex\Tests\Unit\Release;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * bin/bump, bin/release and bin/status, run against a scratch copy of the
 * files they read: no network, no tag, no push.
 */
final class ReleaseScriptsTest extends TestCase
{
    private const CHANGELOG = <<<'MD'
        # Changelog

        ## [2.0.0] - Unreleased

        ### Added

        - Something.

        ## [1.3.0] - 2026-01-12

        ### Added

        - Something older.

        MD;

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/php-regex-release-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/src/Toolkit', 0o777, true);
        file_put_contents($this->root.'/src/Toolkit/Regex.php', "<?php\n\nfinal class Regex\n{\n    public const VERSION = '2.0.0-DEV';\n}\n");
        file_put_contents($this->root.'/CHANGELOG.md', self::CHANGELOG);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    #[Test]
    public function test_bump_refuses_a_version_that_is_not_semver(): void
    {
        [$code, $output] = $this->script('bump', 'v2.1');

        $this->assertSame(2, $code);
        $this->assertStringContainsString('not a version', $output);
    }

    #[Test]
    public function test_bump_refuses_a_version_that_does_not_move_forward(): void
    {
        [$code, $output] = $this->script('bump', '1.9.0');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('2.0.0-DEV', $output);
        $this->assertSame(self::CHANGELOG, $this->changelog());
    }

    #[Test]
    public function test_bump_sets_the_version_and_dates_the_unreleased_section(): void
    {
        [$code] = $this->script('bump', '--date', '2026-10-01', '2.0.0');

        $this->assertSame(0, $code);
        $this->assertSame('2.0.0', $this->version());
        $this->assertStringContainsString("## [2.0.0] - 2026-10-01\n", $this->changelog());
        $this->assertStringNotContainsString('Unreleased', $this->changelog());
    }

    #[Test]
    public function test_bump_names_the_section_after_the_released_version(): void
    {
        [$code] = $this->script('bump', '--date', '2026-10-01', '2.1.0');

        $this->assertSame(0, $code);
        $this->assertStringContainsString("## [2.1.0] - 2026-10-01\n", $this->changelog());
    }

    #[Test]
    public function test_bump_leaves_the_changelog_alone_for_a_pre_release(): void
    {
        [$code] = $this->script('bump', '2.0.0-BETA1');

        $this->assertSame(0, $code);
        $this->assertSame('2.0.0-BETA1', $this->version());
        $this->assertSame(self::CHANGELOG, $this->changelog());
    }

    #[Test]
    public function test_bump_dry_run_changes_nothing(): void
    {
        [$code, $output] = $this->script('bump', '--dry-run', '2.0.0');

        $this->assertSame(0, $code);
        $this->assertSame('2.0.0-DEV', $this->version());
        $this->assertSame(self::CHANGELOG, $this->changelog());
        $this->assertStringContainsString('src/Toolkit/Regex.php', $output);
        $this->assertStringContainsString('CHANGELOG.md', $output);
    }

    #[Test]
    public function test_bump_next_opens_the_following_version(): void
    {
        $this->script('bump', '--date', '2026-10-01', '2.0.0');
        [$code] = $this->script('bump', '--next', '2.0.1');

        $this->assertSame(0, $code);
        $this->assertSame('2.0.1-DEV', $this->version());
        $this->assertStringContainsString("## [2.0.1] - Unreleased\n\n## [2.0.0] - 2026-10-01\n", $this->changelog());
    }

    #[Test]
    public function test_release_refuses_a_version_the_code_does_not_carry(): void
    {
        $this->commit();

        [$code, $output] = $this->script('release', '--dry-run', '--skip-ci', '--skip-phar', '2.0.0');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('2.0.0-DEV', $output);
    }

    #[Test]
    public function test_release_refuses_an_undated_changelog(): void
    {
        file_put_contents($this->root.'/src/Toolkit/Regex.php', str_replace('2.0.0-DEV', '2.0.0', (string) file_get_contents($this->root.'/src/Toolkit/Regex.php')));
        $this->commit();

        [$code, $output] = $this->script('release', '--dry-run', '--skip-ci', '--skip-phar', '2.0.0');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('CHANGELOG', $output);
    }

    #[Test]
    public function test_release_refuses_a_dirty_tree(): void
    {
        $this->script('bump', '--date', '2026-10-01', '2.0.0');
        $this->commit();
        file_put_contents($this->root.'/stray.txt', 'x');

        [$code, $output] = $this->script('release', '--dry-run', '--skip-ci', '--skip-phar', '2.0.0');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('clean', $output);
    }

    #[Test]
    public function test_release_refuses_an_existing_tag(): void
    {
        $this->script('bump', '--date', '2026-10-01', '2.0.0');
        $this->commit();
        $this->git('tag', 'v2.0.0');

        [$code, $output] = $this->script('release', '--dry-run', '--skip-ci', '--skip-phar', '2.0.0');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('v2.0.0', $output);
    }

    #[Test]
    public function test_release_dry_run_says_what_it_would_do(): void
    {
        $this->script('bump', '--date', '2026-10-01', '2.0.0');
        $this->commit();

        [$code, $output] = $this->script('release', '--dry-run', '--skip-ci', '--skip-phar', '2.0.0');

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('would tag v2.0.0', $output);
        $this->assertSame('', trim($this->git('tag', '--list')));
    }

    #[Test]
    public function test_split_never_pushes_into_the_monorepo(): void
    {
        // The monorepo: a commit holding bin/split, pushed as 2.x to a bare
        // repository named like a package, as a GitHub redirect would serve it.
        mkdir($this->root.'/bin');
        mkdir($this->root.'/src/Parser');
        file_put_contents($this->root.'/bin/split', '#');
        file_put_contents($this->root.'/src/Parser/Parser.php', '<?php');
        $this->commit();
        $remotes = $this->root.'/remotes';
        mkdir($remotes);
        // regex-automata is missing: reading it fails, as a refused connection does.
        foreach (['regex-parser', 'regex-explain'] as $name) {
            exec('git init -q --bare '.escapeshellarg($remotes.'/'.$name.'.git'));
        }
        $this->git('push', '-q', $remotes.'/regex-parser.git', 'HEAD:refs/heads/2.x');
        $monorepo = trim($this->git('rev-parse', 'HEAD'));

        // A splitsh-lite that hands back a commit without bin/split.
        $this->git('-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '--allow-empty', '-m', 'split');
        $this->git('rm', '-q', 'bin/split');
        $this->git('-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '-m', 'without split');
        $split = trim($this->git('rev-parse', 'HEAD'));
        $this->git('reset', '-q', '--hard', $monorepo);
        mkdir($this->root.'/fake');
        file_put_contents($this->root.'/fake/splitsh-lite', "#!/usr/bin/env bash\necho {$split}\n");
        chmod($this->root.'/fake/splitsh-lite', 0o755);

        $process = proc_open(
            [\dirname(__DIR__, 3).'/bin/split', '--root', $this->root, '--branch', '2.x', '--only', 'regex-automata', '--only', 'regex-parser', '--only', 'regex-explain'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => $this->root.'/fake:'.getenv('PATH'), 'SPLIT_REMOTE_BASE' => $remotes, 'HOME' => (string) getenv('HOME')],
        );
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('regex-parser', $output);
        $this->assertStringContainsString('monorepo', $output);
        // Serving the monorepo means the repository is not created yet: a
        // skip, not a failure. Only the unreadable regex-automata fails.
        $this->assertStringContainsString('1 split(s) failed', $output);
        $this->assertStringContainsString('skipped', $output);
        // A repository that cannot be read is skipped, and the run goes on.
        $this->assertStringContainsString('✓ regex-explain', $output);
        $this->assertSame($monorepo, trim((string) shell_exec('git -C '.escapeshellarg($remotes.'/regex-parser.git').' rev-parse 2.x')));
        $this->assertSame($split, trim((string) shell_exec('git -C '.escapeshellarg($remotes.'/regex-explain.git').' rev-parse 2.x')));
    }

    #[Test]
    public function test_status_offline_lists_every_package(): void
    {
        [$code, $output] = $this->script('status', '--offline');

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('2.0.0-DEV', $output);
        foreach (['parser', 'explain', 'optimizer', 'generator', 'automata', 'redos', 'transpiler', 'linter', 'toolkit', 'cli', 'language-server', 'phpstan', 'symfony', 'laravel'] as $name) {
            $this->assertStringContainsString('php-regex/regex-'.$name, $output);
        }
    }

    /**
     * @return array{int, string}
     */
    private function script(string $script, string ...$arguments): array
    {
        $command = [\dirname(__DIR__, 3).'/bin/'.$script, '--root', $this->root, ...array_values($arguments)];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    private function git(string ...$arguments): string
    {
        $output = [];
        exec('git -C '.escapeshellarg($this->root).' '.implode(' ', array_map(escapeshellarg(...), $arguments)).' 2>&1', $output);

        return implode("\n", $output);
    }

    private function commit(): void
    {
        $this->git('init', '-q', '-b', '2.x');
        $this->git('-c', 'user.name=t', '-c', 'user.email=t@t', 'add', '-A');
        $this->git('-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '--no-verify', '-m', 'state');
    }

    private function version(): string
    {
        preg_match("~VERSION = '([^']+)'~", (string) file_get_contents($this->root.'/src/Toolkit/Regex.php'), $match);

        return $match[1] ?? '';
    }

    private function changelog(): string
    {
        return (string) file_get_contents($this->root.'/CHANGELOG.md');
    }
}
