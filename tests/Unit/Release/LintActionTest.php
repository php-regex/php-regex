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

use PHPRegex\Cli\SelfUpdate\SelfUpdater;
use PHPRegex\Tests\TestUtils\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The action at the root of the repository, "uses: php-regex/php-regex@v2.0.0":
 * it downloads the phar of a release, checks it against the checksum the
 * release carries, and runs regex lint on it with the GitHub format. Its
 * steps run here under bash, the releases served from the disk.
 */
#[RequiresOperatingSystem('Linux|Darwin')]
final class LintActionTest extends TestCase
{
    /**
     * A php that prints the arguments the phar is given, one per line, and
     * runs any other script itself.
     */
    private const FAKE_PHP = <<<'BASH'
        #!/usr/bin/env bash
        case "$1" in
            *regex.phar) shift; printf '%s\n' "$@" ;;
            *) exec "$REAL_PHP" "$@" ;;
        esac
        BASH;

    private string $scratch = '';

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir().'/php-regex-lint-action-'.bin2hex(random_bytes(6));
        foreach (['fake', 'temp', 'releases/latest/download', 'releases/download/v2.0.1'] as $directory) {
            mkdir($this->scratch.'/'.$directory, 0o777, true);
        }

        file_put_contents($this->scratch.'/fake/php', self::FAKE_PHP);
        chmod($this->scratch.'/fake/php', 0o755);
        file_put_contents($this->scratch.'/output', '');
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->scratch);
    }

    #[Test]
    public function test_the_action_is_a_composite_one_at_the_root(): void
    {
        $action = self::action();

        $this->assertMatchesRegularExpression('~^runs:\n  using: composite$~m', $action);
        $this->assertMatchesRegularExpression('~^name: PHPRegex Lint$~m', $action);
        $this->assertStringContainsString('RELEASES: https://github.com/php-regex/php-regex/releases', $action);
        $this->assertStringEndsWith('/releases/latest/download/regex.phar', SelfUpdater::UPDATE_URL);
    }

    /**
     * @return iterable<string, array{version: string, release: string}>
     */
    public static function provideReleases(): iterable
    {
        yield 'the latest' => ['version' => 'latest', 'release' => 'latest/download'];
        yield 'a version' => ['version' => '2.0.1', 'release' => 'download/v2.0.1'];
        yield 'a tag' => ['version' => 'v2.0.1', 'release' => 'download/v2.0.1'];
    }

    #[Test]
    #[DataProvider('provideReleases')]
    public function test_the_phar_of_the_release_is_downloaded_when_its_checksum_matches(string $version, string $release): void
    {
        $this->publish($release, 'the phar', hash('sha256', 'the phar'));

        [$code, $output] = $this->runStep('Download the phar', ['VERSION' => $version]);

        $this->assertSame(0, $code, $output);
        $this->assertSame('phar='.$this->scratch."/temp/phpregex/regex.phar\n", file_get_contents($this->scratch.'/output'));
        $this->assertSame('the phar', file_get_contents($this->scratch.'/temp/phpregex/regex.phar'));
    }

    #[Test]
    public function test_a_phar_that_does_not_match_its_checksum_is_not_run(): void
    {
        $this->publish('latest/download', 'another phar', hash('sha256', 'the phar'));

        [$code, $output] = $this->runStep('Download the phar', ['VERSION' => 'latest']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('regex.phar does not match the checksum of its release: it is not run.', $output);
        $this->assertSame('', file_get_contents($this->scratch.'/output'));
    }

    #[Test]
    public function test_a_release_without_a_checksum_is_refused(): void
    {
        file_put_contents($this->scratch.'/releases/latest/download/regex.phar', 'the phar');

        [$code] = $this->runStep('Download the phar', ['VERSION' => 'latest']);

        $this->assertNotSame(0, $code);
        $this->assertSame('', file_get_contents($this->scratch.'/output'));
    }

    /**
     * @return iterable<string, array{version: string}>
     */
    public static function provideVersionsThatAreNoRelease(): iterable
    {
        yield 'a branch' => ['version' => '2.x-dev'];
        yield 'a path' => ['version' => '../../etc'];
        yield 'empty' => ['version' => ''];
    }

    #[Test]
    #[DataProvider('provideVersionsThatAreNoRelease')]
    public function test_a_version_that_names_no_release_is_refused(string $version): void
    {
        [$code, $output] = $this->runStep('Download the phar', ['VERSION' => $version]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('is not a release: use "latest" or a version, as "2.0.0".', $output);
    }

    /**
     * @return iterable<string, array{paths: string, args: string, expected: list<string>}>
     */
    public static function provideLintArguments(): iterable
    {
        yield 'nothing given' => ['paths' => '', 'args' => '', 'expected' => ['lint', '--format=github', '--no-ansi']];
        yield 'paths' => ['paths' => 'src tests', 'args' => '', 'expected' => ['lint', '--format=github', '--no-ansi', 'src', 'tests']];
        yield 'options and paths' => ['paths' => ' src ', 'args' => '--redos  --php-version=8.2', 'expected' => ['lint', '--format=github', '--no-ansi', '--redos', '--php-version=8.2', 'src']];
        yield 'a path holding a glob' => ['paths' => 'src/*', 'args' => '', 'expected' => ['lint', '--format=github', '--no-ansi', 'src/*']];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideLintArguments')]
    public function test_the_lint_runs_on_the_paths_with_the_github_format(string $paths, string $args, array $expected): void
    {
        [$code, $output] = $this->runStep('Lint the patterns', ['PATHS' => $paths, 'ARGS' => $args, 'PHAR' => $this->scratch.'/temp/regex.phar']);

        $this->assertSame(0, $code, $output);
        $this->assertSame($expected, explode("\n", rtrim($output, "\n")));
    }

    private function publish(string $release, string $phar, string $checksum): void
    {
        file_put_contents($this->scratch.'/releases/'.$release.'/regex.phar', $phar);
        file_put_contents($this->scratch.'/releases/'.$release.'/regex.phar.sha256', $checksum."  regex.phar\n");
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{int, string}
     */
    private function runStep(string $name, array $env): array
    {
        file_put_contents($this->scratch.'/step.sh', self::step($name));

        $process = proc_open(
            ['bash', '--noprofile', '--norc', '-eo', 'pipefail', $this->scratch.'/step.sh'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->scratch,
            $env + [
                'PATH' => $this->scratch.'/fake:'.getenv('PATH'),
                'REAL_PHP' => \PHP_BINARY,
                'PHP_BINARY' => 'php',
                'RELEASES' => 'file://'.$this->scratch.'/releases',
                'RUNNER_TEMP' => $this->scratch.'/temp',
                'GITHUB_OUTPUT' => $this->scratch.'/output',
                'HOME' => (string) getenv('HOME'),
            ],
        );
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    /**
     * The shell script of the step with this name.
     */
    private static function step(string $name): string
    {
        if (1 !== preg_match('~^( *)- name: '.preg_quote($name, '~').'\n(?:\1  (?!run:).*\n)*\1  run: \|\n((?:(?:\1    .*)?\n)+)~m', self::action(), $match)) {
            self::fail('No step named "'.$name.'" with a script.');
        }

        return (string) preg_replace('~^ {'.(\strlen($match[1]) + 4).'}~m', '', $match[2]);
    }

    private static function action(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/action.yml');
    }
}
