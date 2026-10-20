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

use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * The identity bin/release tags with: a CI runner has none of its own.
     */
    private const IDENTITY = ['GIT_AUTHOR_NAME' => 't', 'GIT_AUTHOR_EMAIL' => 't@t', 'GIT_COMMITTER_NAME' => 't', 'GIT_COMMITTER_EMAIL' => 't@t'];

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/php-regex-release-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/src/Toolkit', 0o777, true);
        file_put_contents($this->root.'/src/Toolkit/Regex.php', "<?php\n\nfinal class Regex\n{\n    public const VERSION = '2.0.0-DEV';\n    public const VERSION_ID = 20000;\n}\n");
        file_put_contents($this->root.'/CHANGELOG.md', self::CHANGELOG);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root).' '.escapeshellarg($this->root.'.origin.git'));
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
    public function test_bump_keeps_the_version_id_in_step(): void
    {
        $this->script('bump', '--date', '2026-10-01', '2.0.0');
        [$code] = $this->script('bump', '--next', '2.1.3');

        $this->assertSame(0, $code);
        $this->assertSame('2.1.3-DEV', $this->version());
        $this->assertStringContainsString('public const VERSION_ID = 20103;', (string) file_get_contents($this->root.'/src/Toolkit/Regex.php'));
    }

    #[Test]
    public function test_release_refuses_a_version_the_code_does_not_carry(): void
    {
        $this->commit();

        [$code, $output] = $this->script('release', '--dry-run', '--skip-ci', '2.0.0');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('2.0.0-DEV', $output);
    }

    #[Test]
    public function test_release_refuses_an_undated_changelog(): void
    {
        file_put_contents($this->root.'/src/Toolkit/Regex.php', str_replace('2.0.0-DEV', '2.0.0', (string) file_get_contents($this->root.'/src/Toolkit/Regex.php')));
        $this->commit();

        [$code, $output] = $this->script('release', '--dry-run', '--skip-ci', '2.0.0');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('CHANGELOG', $output);
    }

    #[Test]
    public function test_release_refuses_a_dirty_tree(): void
    {
        $this->script('bump', '--date', '2026-10-01', '2.0.0');
        $this->commit();
        file_put_contents($this->root.'/stray.txt', 'x');

        [$code, $output] = $this->script('release', '--dry-run', '--skip-ci', '2.0.0');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('clean', $output);
    }

    #[Test]
    public function test_release_refuses_an_existing_tag(): void
    {
        $this->script('bump', '--date', '2026-10-01', '2.0.0');
        $this->commit();
        $this->git('tag', 'v2.0.0');

        [$code, $output] = $this->script('release', '--dry-run', '--skip-ci', '2.0.0');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('v2.0.0', $output);
    }

    #[Test]
    public function test_release_dry_run_says_what_it_would_do(): void
    {
        $this->script('bump', '--date', '2026-10-01', '2.0.0');
        $this->commit();
        $this->publish();

        [$code, $output] = $this->script('release', '--dry-run', '--skip-ci', '2.0.0');

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('would tag v2.0.0', $output);
        $this->assertSame('', trim($this->git('tag', '--list')));
    }

    #[Test]
    public function test_release_dry_run_names_the_changelog_section_as_the_notes(): void
    {
        $this->script('bump', '--date', '2026-10-01', '2.0.0');
        $this->commit();
        $this->publish();

        [$code, $output] = $this->script('release', '--dry-run', '--skip-ci', '2.0.0');

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('release notes: the CHANGELOG section', $output);
    }

    #[Test]
    public function test_release_notes_summarize_a_section_too_long_for_github(): void
    {
        $entries = str_repeat("- A change described at length, as a major release lists many.\n", 2500);
        file_put_contents($this->root.'/CHANGELOG.md', str_replace("### Added\n\n- Something.\n", "Opening words of the release.\n\n### Added\n\n".$entries, self::CHANGELOG));
        $this->script('bump', '--date', '2026-10-01', '2.0.0');
        $this->commit();
        $this->publish();

        [$code, $output] = $this->script('release', '--dry-run', '--skip-ci', '2.0.0');

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('release notes: a summary linking CHANGELOG.md', $output);
        $this->assertStringContainsString('Opening words of the release.', $output);
        $this->assertStringContainsString('https://github.com/php-regex/php-regex/blob/v2.0.0/CHANGELOG.md', $output);
    }

    #[Test]
    public function test_release_dry_run_leaves_the_phar_to_the_release_workflow(): void
    {
        $this->script('bump', '--date', '2026-10-01', '2.0.0');
        $this->commit();
        $this->publish();

        [$code, $output] = $this->script('release', '--dry-run', '--skip-ci', '2.0.0');

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('phar.yml', $output);
        $this->assertStringNotContainsString('phar/build', $output);
    }

    #[Test]
    public function test_release_no_longer_takes_skip_phar(): void
    {
        $this->script('bump', '--date', '2026-10-01', '2.0.0');
        $this->commit();

        [$code, $output] = $this->script('release', '--dry-run', '--skip-ci', '--skip-phar', '2.0.0');

        $this->assertSame(2, $code, $output);
        $this->assertStringContainsString('--skip-phar', $output);
    }

    /**
     * The release is drafted before the tag leaves, and stays a draft: the
     * phar workflow the tag starts builds the phar, attests it, attaches it
     * and only then publishes the release, so the latest release never goes
     * without its phar.
     */
    #[Test]
    public function test_release_drafts_the_release_and_leaves_publishing_to_the_phar_workflow(): void
    {
        // A phar/build that fails if it is run, a gh that records each call
        // and whether the tag had reached origin by then.
        mkdir($this->root.'/phar');
        file_put_contents($this->root.'/phar/build', "#!/usr/bin/env bash
echo 'built locally' >&2
exit 1
");
        chmod($this->root.'/phar/build', 0o755);
        file_put_contents($this->root.'/.gitignore', "/fake/\n/gh.log\n");
        $this->script('bump', '--date', '2026-10-01', '2.0.0');
        $this->commit();
        $this->publish();
        mkdir($this->root.'/fake');
        file_put_contents($this->root.'/fake/gh', "#!/usr/bin/env bash\npushed=no\ngit ls-remote --tags origin | grep -q 'refs/tags/v2.0.0' && pushed=yes\necho \"\$* [tag on origin: \$pushed]\" >> \"\$GH_LOG\"\n");
        chmod($this->root.'/fake/gh', 0o755);

        [$code, $output] = $this->runScript('release', ['--skip-ci', '2.0.0'], ['PATH' => $this->root.'/fake:'.getenv('PATH'), 'GH_LOG' => $this->root.'/gh.log', ...self::IDENTITY]);

        $this->assertSame(0, $code, $output);
        $this->assertStringNotContainsString('built locally', $output);
        $this->assertFileDoesNotExist($this->root.'/bin/regex.phar');
        $calls = file($this->root.'/gh.log', \FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($calls);
        $this->assertCount(1, $calls, implode("\n", $calls));
        $this->assertStringStartsWith('release create v2.0.0 --title ', $calls[0]);
        $this->assertStringContainsString('--draft', $calls[0]);
        $this->assertStringEndsWith('[tag on origin: no]', $calls[0]);
        $this->assertStringContainsString('refs/tags/v2.0.0', $this->git('ls-remote', '--tags', 'origin'));
        $this->assertStringContainsString('phar.yml', $output);
    }

    #[Test]
    public function test_release_refuses_a_head_origin_does_not_hold(): void
    {
        $this->script('bump', '--date', '2026-10-01', '2.0.0');
        $this->commit();
        $this->publish();
        $this->git('-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '--allow-empty', '-m', 'not pushed');

        [$code, $output] = $this->script('release', '--dry-run', '--skip-ci', '2.0.0');

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('HEAD is 1 commit ahead of origin/2.x', $output);
    }

    /**
     * What origin holds is read when the release runs, not when it was last
     * fetched: a commit pushed from elsewhere, then reset away here, leaves
     * HEAD on origin's history.
     */
    #[Test]
    public function test_release_reads_origin_as_it_stands_now(): void
    {
        $this->script('bump', '--date', '2026-10-01', '2.0.0');
        $this->commit();
        $this->publish();
        $this->git('-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '--allow-empty', '-m', 'pushed from elsewhere');
        $this->git('push', '-q', 'origin', 'HEAD:refs/heads/2.x');
        $this->git('update-ref', 'refs/remotes/origin/2.x', 'HEAD~1');

        [$code, $output] = $this->script('release', '--dry-run', '--skip-ci', '2.0.0');

        $this->assertSame(0, $code, $output);
    }

    #[Test]
    public function test_release_refuses_a_branch_origin_does_not_serve(): void
    {
        $this->script('bump', '--date', '2026-10-01', '2.0.0');
        $this->commit();

        [$code, $output] = $this->script('release', '--dry-run', '--skip-ci', '2.0.0');

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('cannot read 2.x from origin', $output);
    }

    #[Test]
    public function test_release_and_status_check_the_same_workflows(): void
    {
        $release = self::gatedWorkflows('release');

        $this->assertSame($release, self::gatedWorkflows('status'));
        $this->assertContains('workflows.yml', $release);
        $this->assertContains('demo.yml', $release);
    }

    /**
     * A release asks for a run of each workflow on the commit it tags, so
     * each one runs on every push to the branch, whatever the push changed.
     */
    #[Test]
    public function test_every_workflow_a_release_checks_runs_on_every_push(): void
    {
        foreach (self::gatedWorkflows('release') as $workflow) {
            $source = (string) file_get_contents(\dirname(__DIR__, 3).'/.github/workflows/'.$workflow);
            $this->assertSame(1, preg_match('~^on:\n  push:\n((?:    .*\n)+)~m', $source, $push), $workflow);
            $this->assertMatchesRegularExpression('~^    branches: \[ ?2\.x ?\]$~m', $push[1], $workflow);
            $this->assertStringNotContainsString('paths', $push[1], $workflow);
        }
    }

    #[Test]
    public function test_status_offline_shows_how_far_head_is_ahead_of_origin(): void
    {
        $this->commit();
        $this->publish();
        $this->git('-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '--allow-empty', '-m', 'one');
        $this->git('-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '--allow-empty', '-m', 'two');

        [$code, $output] = $this->script('status', '--offline');

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('HEAD is 2 commits ahead of origin/2.x', $output);
    }

    #[Test]
    public function test_status_offline_says_when_head_is_on_origin(): void
    {
        $this->commit();
        $this->publish();

        [$code, $output] = $this->script('status', '--offline');

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('HEAD is on origin/2.x', $output);
    }

    #[Test]
    public function test_release_notes_of_a_pre_release_link_the_upgrade_guide_of_its_major(): void
    {
        file_put_contents($this->root.'/UPGRADE-2.0.md', '# Upgrade');
        $this->script('bump', '2.1.0-BETA1');
        $this->commit();
        $this->publish();

        [$code, $output] = $this->script('release', '--dry-run', '--skip-ci', '2.1.0-BETA1');

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Pre-release of 2.1.0.', $output);
        $this->assertStringContainsString('[UPGRADE-2.0.md](https://github.com/php-regex/php-regex/blob/v2.1.0-BETA1/UPGRADE-2.0.md)', $output);
    }

    #[Test]
    public function test_release_notes_of_a_pre_release_link_the_changelog_alone_without_an_upgrade_guide(): void
    {
        file_put_contents($this->root.'/UPGRADE-2.0.md', '# Upgrade');
        $this->script('bump', '3.0.0-RC1');
        $this->commit();
        $this->publish();

        [$code, $output] = $this->script('release', '--dry-run', '--skip-ci', '3.0.0-RC1');

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Pre-release of 3.0.0. See [CHANGELOG.md](https://github.com/php-regex/php-regex/blob/v3.0.0-RC1/CHANGELOG.md).', $output);
        $this->assertStringNotContainsString('UPGRADE', $output);
    }

    #[Test]
    public function test_split_reads_the_branch_a_tag_checkout_only_knows_as_a_remote(): void
    {
        // A tag checkout: HEAD detached on the tag, 2.x known only as origin/2.x.
        mkdir($this->root.'/src/Parser');
        file_put_contents($this->root.'/src/Parser/Parser.php', '<?php');
        $this->commit();
        $this->git('-c', 'user.name=t', '-c', 'user.email=t@t', 'tag', '-a', 'v2.0.0', '-m', 'v2.0.0');
        $this->git('update-ref', 'refs/remotes/origin/2.x', 'HEAD');
        $this->git('checkout', '-q', '--detach', 'v2.0.0');
        $this->git('branch', '-q', '-D', '2.x');
        $remotes = $this->root.'/remotes';
        mkdir($remotes);
        exec('git init -q --bare '.escapeshellarg($remotes.'/regex-parser.git'));
        $head = trim($this->git('rev-parse', 'HEAD'));

        // A splitsh-lite that fails on an origin the repository cannot resolve.
        mkdir($this->root.'/fake');
        file_put_contents($this->root.'/fake/splitsh-lite', "#!/usr/bin/env bash\nfor a in \"\$@\"; do case \$a in --origin=*) git rev-parse -q --verify \"\${a#--origin=}^{commit}\" >/dev/null || exit 1 ;; esac; done\necho {$head}\n");
        chmod($this->root.'/fake/splitsh-lite', 0o755);

        $process = proc_open(
            [\dirname(__DIR__, 3).'/bin/split', '--root', $this->root, '--branch', '2.x', '--tag', 'v2.0.0', '--only', 'regex-parser'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => $this->root.'/fake:'.getenv('PATH'), 'SPLIT_REMOTE_BASE' => $remotes, 'HOME' => (string) getenv('HOME')],
        );
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), $output);
        $this->assertSame($head, trim((string) shell_exec('git -C '.escapeshellarg($remotes.'/regex-parser.git').' rev-parse 2.x')));
        $this->assertSame($head, trim((string) shell_exec('git -C '.escapeshellarg($remotes.'/regex-parser.git').' rev-parse v2.0.0')));
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
    public function test_split_pushes_the_organization_repository_to_its_own_branch_without_the_tag(): void
    {
        mkdir($this->root.'/.github/organization/profile', 0o777, true);
        file_put_contents($this->root.'/.github/organization/profile/README.md', '# PHPRegex');
        $this->commit();
        $this->git('-c', 'user.name=t', '-c', 'user.email=t@t', 'tag', '-a', 'v2.0.0', '-m', 'v2.0.0');
        $remotes = $this->root.'/remotes';
        mkdir($remotes);
        exec('git init -q --bare '.escapeshellarg($remotes.'/.github.git'));
        $head = trim($this->git('rev-parse', 'HEAD'));
        mkdir($this->root.'/fake');
        file_put_contents($this->root.'/fake/splitsh-lite', "#!/usr/bin/env bash\necho {$head}\n");
        chmod($this->root.'/fake/splitsh-lite', 0o755);

        $process = proc_open(
            [\dirname(__DIR__, 3).'/bin/split', '--root', $this->root, '--branch', '2.x', '--tag', 'v2.0.0', '--only', '.github'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => $this->root.'/fake:'.getenv('PATH'), 'SPLIT_REMOTE_BASE' => $remotes, 'HOME' => (string) getenv('HOME')],
        );
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), $output);
        $refs = (string) shell_exec('git -C '.escapeshellarg($remotes.'/.github.git').' for-each-ref --format="%(refname)"');
        $this->assertSame("refs/heads/main\n", $refs);
        $this->assertSame($head, trim((string) shell_exec('git -C '.escapeshellarg($remotes.'/.github.git').' rev-parse main')));
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

    #[Test]
    public function test_status_offline_prints_no_colour_when_piped(): void
    {
        [$code, $output] = $this->script('status', '--offline');

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('· php-regex/regex-parser', $output);
        $this->assertStringNotContainsString("\033[", $output);
    }

    /**
     * @return iterable<string, array{script: string, description: non-empty-string, options: list<string>}>
     */
    public static function provideHelpCases(): iterable
    {
        yield 'bump' => ['script' => 'bump', 'description' => 'Sets the version every package reports', 'options' => ['--dry-run', '--next', '--date', '--root']];
        yield 'split' => ['script' => 'split', 'description' => 'Splits the monorepo into the read-only repositories', 'options' => ['--dry-run', '--branch', '--tag', '--only', '--root']];
        yield 'release' => ['script' => 'release', 'description' => 'Releases a version', 'options' => ['--dry-run', '--skip-ci', '--branch', '--root']];
        yield 'status' => ['script' => 'status', 'description' => 'Where the release stands', 'options' => ['--offline', '--root']];
    }

    /**
     * @param non-empty-string $description
     * @param list<string>     $options
     */
    #[Test]
    #[DataProvider('provideHelpCases')]
    public function test_help_describes_the_script_and_names_every_option(string $script, string $description, array $options): void
    {
        [$code, $output] = $this->script($script, '--help');

        $this->assertSame(0, $code, $output);
        $this->assertStringStartsWith($description, $output);
        $this->assertStringContainsString('Usage:', $output);
        $this->assertStringNotContainsString('set -', $output);
        foreach ($options as $option) {
            $this->assertStringContainsString($option, $output);
        }
    }

    /**
     * @return array{int, string}
     */
    private function script(string $script, string ...$arguments): array
    {
        return $this->runScript($script, array_values($arguments));
    }

    /**
     * @param list<string>               $arguments
     * @param array<string, string>|null $env
     *
     * @return array{int, string}
     */
    private function runScript(string $script, array $arguments, ?array $env = null): array
    {
        $command = [\dirname(__DIR__, 3).'/bin/'.$script, '--root', $this->root, ...$arguments];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null === $env ? null : $env + ['HOME' => (string) getenv('HOME')]);
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

    /**
     * The workflows a script reads the CI of HEAD from.
     *
     * @return list<string>
     */
    private static function gatedWorkflows(string $script): array
    {
        preg_match('~for workflow in ([a-z.\- ]+); do~', (string) file_get_contents(\dirname(__DIR__, 3).'/bin/'.$script), $match);

        return explode(' ', $match[1] ?? '');
    }

    /**
     * Gives the scratch repository an origin holding its 2.x, as the release
     * branch is once pushed.
     */
    private function publish(): void
    {
        exec('git init -q --bare '.escapeshellarg($this->root.'.origin.git'));
        $this->git('remote', 'add', 'origin', $this->root.'.origin.git');
        $this->git('push', '-q', 'origin', 'HEAD:refs/heads/2.x');
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
