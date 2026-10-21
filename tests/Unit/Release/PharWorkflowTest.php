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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The phar a release carries is built by the phar workflow, never on a
 * maintainer's machine: the workflow attests where it was built, and attaches
 * it under the names self-update downloads.
 */
final class PharWorkflowTest extends TestCase
{
    /**
     * A php that answers as the phar would: its version, a validation and an
     * initialize request, each overridable through the environment.
     */
    private const FAKE_PHP = <<<'BASH'
        #!/usr/bin/env bash
        [ "$1" = bin/regex.phar ] || exec "$REAL_PHP" "$@"
        case "$2" in
            --version) printf '%s\n\nRuntime : PHP 8.1.0\n' "${FAKE_VERSION:-PHPRegex 2.0.1 by Younes ENNAJI}" ;;
            validate)
                case "$*" in
                    *'/(a/'*) echo 'Status : INVALID'; exit "${FAKE_INVALID_CODE:-1}" ;;
                    *) echo 'Status : OK'; exit "${FAKE_VALID_CODE:-0}" ;;
                esac ;;
            lsp)
                cat > /dev/null
                body=${FAKE_LSP:-'{"jsonrpc": "2.0", "id": 1, "result": {"capabilities": {}, "serverInfo": {"name": "php-regex-lsp", "version": "2.0.1"}}}'}
                log='{"jsonrpc":"2.0","method":"window/logMessage","params":{"type":3,"message":"Target: PHP 8.1"}}'
                printf 'Content-Length: %d\r\n\r\n%sContent-Length: %d\r\n\r\n%s' "${#body}" "$body" "${#log}" "$log" ;;
        esac
        BASH;

    private string $scratch = '';

    protected function tearDown(): void
    {
        if ('' !== $this->scratch) {
            exec('rm -rf '.escapeshellarg($this->scratch));
        }
    }

    #[Test]
    public function test_a_pushed_version_tag_starts_the_workflow(): void
    {
        $workflow = self::workflow();

        $this->assertMatchesRegularExpression("~^on:\n  push:\n    tags: \\['v\\*'\\]$~m", $workflow);
        $this->assertStringContainsString('workflow_dispatch:', $workflow);
    }

    #[Test]
    public function test_the_phar_is_built_as_the_release_script_built_it_and_attested(): void
    {
        $workflow = self::workflow();

        $this->assertStringContainsString('run: phar/build', $workflow);
        $this->assertStringContainsString('uses: actions/attest-build-provenance@', $workflow);
        $this->assertStringContainsString('subject-path: bin/regex.phar', $workflow);
        $this->assertStringContainsString('id-token: write', $workflow);
        $this->assertStringContainsString('attestations: write', $workflow);
        $this->assertStringContainsString('contents: write', $workflow);
    }

    /**
     * The attachments are named after the paths self-update downloads, and
     * the release is published only once they are attached.
     */
    #[Test]
    public function test_the_assets_carry_the_names_self_update_downloads(): void
    {
        $workflow = self::workflow();
        $this->assertStringEndsWith('/regex.phar', SelfUpdater::UPDATE_URL);
        $this->assertStringEndsWith('/regex.phar.sha256', SelfUpdater::CHECKSUM_URL);
        $this->assertStringContainsString('gh release upload "$TAG" bin/regex.phar bin/regex.phar.sha256 --clobber', $workflow);
        $this->assertLessThan(
            strpos($workflow, 'gh release edit "$TAG" --draft=false'),
            strpos($workflow, 'gh release upload "$TAG"'),
        );
    }

    /**
     * @return iterable<string, array{tag: string, accepted: bool}>
     */
    public static function provideTagCases(): iterable
    {
        yield 'final' => ['tag' => 'v2.0.0', 'accepted' => true];
        yield 'beta' => ['tag' => 'v2.1.0-BETA1', 'accepted' => true];
        yield 'release candidate' => ['tag' => 'v2.1.0-RC2', 'accepted' => true];
        yield 'branch' => ['tag' => '2.x', 'accepted' => false];
        yield 'no v' => ['tag' => '2.0.0', 'accepted' => false];
        yield 'two numbers' => ['tag' => 'v2.0', 'accepted' => false];
        yield 'path' => ['tag' => 'v2.0.0/../heads/2.x', 'accepted' => false];
        yield 'second line' => ['tag' => "v2.0.0\nmain", 'accepted' => false];
        yield 'empty' => ['tag' => '', 'accepted' => false];
    }

    /**
     * The tag a run by hand names is checked before it becomes a ref to
     * check out or a release to edit.
     */
    #[Test]
    #[DataProvider('provideTagCases')]
    public function test_the_tag_is_checked_before_anything_reads_it(string $tag, bool $accepted): void
    {
        $workflow = self::workflow();
        $this->assertLessThan(strpos($workflow, '- name: Checkout the tag'), strpos($workflow, '- name: Check the tag'));

        [$code, $output] = $this->runStep('Check the tag', ['TAG' => $tag]);

        $this->assertSame($accepted, 0 === $code, $output);
    }

    /**
     * @return iterable<string, array{reported: string, tag: string, accepted: bool}>
     */
    public static function provideVersionCases(): iterable
    {
        yield 'same' => ['reported' => 'PHPRegex 2.0.1 by Younes ENNAJI', 'tag' => 'v2.0.1', 'accepted' => true];
        yield 'pre-release' => ['reported' => 'PHPRegex 2.1.0-RC1 by Younes ENNAJI', 'tag' => 'v2.1.0-RC1', 'accepted' => true];
        yield 'longer patch' => ['reported' => 'PHPRegex 2.0.10 by Younes ENNAJI', 'tag' => 'v2.0.1', 'accepted' => false];
        yield 'development' => ['reported' => 'PHPRegex 2.0.1-DEV by Younes ENNAJI', 'tag' => 'v2.0.1', 'accepted' => false];
        yield 'another tool' => ['reported' => 'Box 2.0.1', 'tag' => 'v2.0.1', 'accepted' => false];
    }

    #[Test]
    #[DataProvider('provideVersionCases')]
    public function test_the_phar_reports_exactly_the_tagged_version(string $reported, string $tag, bool $accepted): void
    {
        [$code, $output] = $this->runStep('Check the version', ['TAG' => $tag, 'FAKE_VERSION' => $reported]);

        $this->assertSame($accepted, 0 === $code, $output);
    }

    /**
     * @return iterable<string, array{env: array<string, string>, accepted: bool}>
     */
    public static function provideCommandCases(): iterable
    {
        yield 'valid and invalid told apart' => ['env' => [], 'accepted' => true];
        yield 'a valid pattern refused' => ['env' => ['FAKE_VALID_CODE' => '1'], 'accepted' => false];
        yield 'an invalid pattern accepted' => ['env' => ['FAKE_INVALID_CODE' => '0'], 'accepted' => false];
        yield 'a crash on an invalid pattern' => ['env' => ['FAKE_INVALID_CODE' => '255'], 'accepted' => false];
    }

    /**
     * @param array<string, string> $env
     */
    #[Test]
    #[DataProvider('provideCommandCases')]
    public function test_the_phar_validates_a_pattern_with_its_exit_code(array $env, bool $accepted): void
    {
        [$code, $output] = $this->runStep('Check the phar validates a pattern', ['TAG' => 'v2.0.1', ...$env]);

        $this->assertSame($accepted, 0 === $code, $output);
    }

    /**
     * @return iterable<string, array{answer: string|null, accepted: bool}>
     */
    public static function provideLanguageServerCases(): iterable
    {
        yield 'spaced JSON' => ['answer' => null, 'accepted' => true];
        yield 'compact JSON' => ['answer' => '{"jsonrpc":"2.0","id":1,"result":{"serverInfo":{"name":"php-regex-lsp","version":"2.0.1"}}}', 'accepted' => true];
        yield 'another server' => ['answer' => '{"jsonrpc":"2.0","id":1,"result":{"serverInfo":{"name":"other","version":"2.0.1"}}}', 'accepted' => false];
        yield 'another version' => ['answer' => '{"jsonrpc":"2.0","id":1,"result":{"serverInfo":{"name":"php-regex-lsp","version":"2.0.0"}}}', 'accepted' => false];
        yield 'an error' => ['answer' => '{"jsonrpc":"2.0","id":1,"error":{"code":-32603,"message":"php-regex-lsp"}}', 'accepted' => false];
    }

    /**
     * The answer to initialize is read as JSON, however it is spaced, and
     * after it the server may send more messages.
     */
    #[Test]
    #[DataProvider('provideLanguageServerCases')]
    public function test_the_language_server_answers_initialize(?string $answer, bool $accepted): void
    {
        [$code, $output] = $this->runStep('Check the phar carries the language server', ['TAG' => 'v2.0.1', ...(null === $answer ? [] : ['FAKE_LSP' => $answer])]);

        $this->assertSame($accepted, 0 === $code, $output);
    }

    /**
     * The check reads the answer with jq, which GitHub's runners ship and
     * the official PHP images do not: each job that runs the suite inside
     * one of them installs it, or the test above fails there.
     */
    #[Test]
    public function test_each_job_running_the_suite_in_a_php_image_installs_jq(): void
    {
        $this->assertStringContainsString('jq ', self::step('Check the phar carries the language server'));

        $workflows = glob(\dirname(__DIR__, 3).'/.github/workflows/*.yml');
        $this->assertNotFalse($workflows);
        $jobs = 0;
        foreach ($workflows as $workflow) {
            preg_match_all('~^  ([a-z][a-z0-9-]*):\n((?:(?:    .*)?\n)*)~m', (string) file_get_contents($workflow), $matches, \PREG_SET_ORDER);
            foreach ($matches as [, $id, $body]) {
                if (1 !== preg_match('~^    container: php:~m', $body) || !str_contains($body, 'vendor/bin/phpunit')) {
                    continue;
                }

                $jobs++;
                $this->assertMatchesRegularExpression('~apt-get install [^\n]*\bjq\b~', $body, basename($workflow).': '.$id);
            }
        }

        $this->assertGreaterThan(0, $jobs);
    }

    /**
     * Box needs PHP 8.2 to build; the phar itself runs on the lowest PHP the
     * packages name, and is checked there.
     */
    #[Test]
    public function test_the_phar_is_checked_on_the_lowest_php_the_packages_support(): void
    {
        $workflow = self::workflow();
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($manifest);
        $this->assertIsArray($manifest['require']);
        $this->assertSame('>=8.1', $manifest['require']['php']);

        $build = (int) strpos($workflow, 'run: phar/build');
        $lowest = strpos($workflow, "php-version: '8.1'");
        $this->assertNotFalse($lowest);
        $this->assertGreaterThan($build, $lowest);
        foreach (['Check the version', 'Check the phar validates a pattern', 'Check the phar carries the language server'] as $check) {
            $this->assertGreaterThan($lowest, strpos($workflow, '- name: '.$check), $check);
        }
    }

    /**
     * @return iterable<string, array{draft: string, accepted: bool}>
     */
    public static function provideReleaseCases(): iterable
    {
        yield 'draft' => ['draft' => 'true', 'accepted' => true];
        yield 'published' => ['draft' => 'false', 'accepted' => false];
        yield 'missing' => ['draft' => 'missing', 'accepted' => false];
    }

    /**
     * A published release keeps the phar it was published with: the run
     * stops before it builds, attests or attaches anything.
     */
    #[Test]
    #[DataProvider('provideReleaseCases')]
    public function test_a_published_release_is_never_given_another_phar(string $draft, bool $accepted): void
    {
        $workflow = self::workflow();
        $this->assertLessThan(strpos($workflow, 'run: phar/build'), strpos($workflow, '- name: Check the release is a draft'));

        [$code, $output] = $this->runStep('Check the release is a draft', ['TAG' => 'v2.0.1', 'FAKE_DRAFT' => $draft]);

        $this->assertSame($accepted, 0 === $code, $output);
    }

    /**
     * Runs the script of one step as GitHub runs it, in a scratch directory,
     * with the fake php and a fake gh first on the PATH.
     *
     * @param array<string, string> $env
     *
     * @return array{int, string}
     */
    private function runStep(string $name, array $env): array
    {
        $this->scratch = sys_get_temp_dir().'/php-regex-phar-workflow-'.bin2hex(random_bytes(6));
        mkdir($this->scratch.'/fake', 0o777, true);
        mkdir($this->scratch.'/work/bin', 0o777, true);
        mkdir($this->scratch.'/temp');
        file_put_contents($this->scratch.'/work/bin/regex.phar', 'phar');
        file_put_contents($this->scratch.'/fake/php', self::FAKE_PHP);
        file_put_contents($this->scratch.'/fake/gh', "#!/usr/bin/env bash\ncase \"\$FAKE_DRAFT\" in missing) echo 'release not found' >&2; exit 1 ;; *) echo \"\$FAKE_DRAFT\" ;; esac\n");
        chmod($this->scratch.'/fake/php', 0o755);
        chmod($this->scratch.'/fake/gh', 0o755);
        file_put_contents($this->scratch.'/step.sh', self::step($name));

        $process = proc_open(
            ['bash', '--noprofile', '--norc', '-eo', 'pipefail', $this->scratch.'/step.sh'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->scratch.'/work',
            $env + ['PATH' => $this->scratch.'/fake:'.getenv('PATH'), 'REAL_PHP' => \PHP_BINARY, 'RUNNER_TEMP' => $this->scratch.'/temp', 'HOME' => (string) getenv('HOME')],
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
        $found = preg_match('~^      - name: '.preg_quote($name, '~').'\n(?:        (?!run:).*\n)*        run: \|\n((?:(?:          .*)?\n)+)~m', self::workflow(), $match);
        self::assertSame(1, $found, 'No step named "'.$name.'" with a script.');

        return (string) preg_replace('~^ {10}~m', '', $match[1]);
    }

    private static function workflow(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/.github/workflows/phar.yml');
    }
}
