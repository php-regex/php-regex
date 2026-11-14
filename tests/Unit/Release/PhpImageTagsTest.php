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

use PHPRegex\Tests\Support\ProcessPipes;
use PHPRegex\Tests\TestUtils\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The official images tag the release candidates of PHP 8.6 "8.6-rc" until
 * its GA, 2026-11-19, and the release "8.6" from then on; the "-rc" tag
 * lives on after a GA, on the candidates of the next patch release ("8.5-rc"
 * runs 8.5.13RC1 a year after 8.5.0). A job finds the tag once per run,
 * the stable one as soon as it exists, and the rows name PHP 8.6 alone: the
 * day of the GA asks nothing of the workflows, and no cell keeps running a
 * candidate after it.
 */
final class PhpImageTagsTest extends TestCase
{
    private const IMAGE = 'php:${{ matrix.php == \'8.6\' && needs.images.outputs.php86 || matrix.php }}-cli';

    /**
     * A docker that gives, call after call, the answers listed in
     * $LOGS/answers: the manifest, Docker Hub saying there is none, its rate
     * limit, or a timeout.
     */
    private const FAKE_DOCKER = <<<'BASH'
        #!/usr/bin/env bash
        calls=$(( $(cat "$LOGS/calls" 2>/dev/null || echo 0) + 1 ))
        echo "$calls" > "$LOGS/calls"
        case "$(sed -n "${calls}p" "$LOGS/answers")" in
          release) echo '{"manifests": []}' ;;
          missing) echo 'no such manifest: docker.io/library/php:8.6-cli' >&2; exit 1 ;;
          limited) echo 'toomanyrequests: You have reached your unauthenticated pull rate limit.' >&2; exit 1 ;;
          *) echo 'Get "https://registry-1.docker.io/v2/": net/http: request canceled (Client.Timeout exceeded while awaiting headers)' >&2; exit 1 ;;
        esac
        BASH;

    private string $scratch = '';

    protected function tearDown(): void
    {
        if ('' !== $this->scratch) {
            TemporaryDirectory::remove($this->scratch);
        }
    }

    /**
     * @return iterable<string, array{workflow: string, job: string}>
     */
    public static function provideContainerJobs(): iterable
    {
        yield 'the suite on the bundled PCRE2' => ['workflow' => 'engines.yml', 'job' => 'engines-bundled'];
        yield 'the engine claims' => ['workflow' => 'engines.yml', 'job' => 'engine-claims'];
        yield 'the twin' => ['workflow' => 'php74-twin.yml', 'job' => 'twin'];
    }

    #[Test]
    #[DataProvider('provideContainerJobs')]
    public function test_a_job_on_the_official_images_runs_the_tag_found_for_php_8_6(string $workflow, string $job): void
    {
        $source = self::job($workflow, $job);

        $this->assertMatchesRegularExpression('~^    needs: (?:images|\[ ?[\w-]+, images ?\])$~m', $source, $job.' waits for the tag.');
        $this->assertStringContainsString(self::IMAGE, $source);
        $this->assertMatchesRegularExpression("~'8\\.6'~", $source, $job.' has a PHP 8.6 row.');
        $this->assertStringNotContainsString('-rc', $source);
    }

    /**
     * An image without mbstring, as the candidates of 8.6 ship, compiles
     * it: a step that asks the image, not its name, has nothing to undo
     * when a later image brings the extension back.
     */
    #[Test]
    #[DataProvider('provideContainerJobs')]
    public function test_a_job_compiles_mbstring_only_where_the_image_lacks_it(string $workflow, string $job): void
    {
        $source = self::job($workflow, $job);
        if (!str_contains($source, 'docker-php-ext-install mbstring')) {
            $this->assertSame('engine-claims', $job, 'Only the claims, which run PHP 7.3 without dependencies, read no multibyte function.');

            return;
        }

        $this->assertSame(1, preg_match('~^      - name: Install multibyte support\n((?:        .*\n)+)~m', $source, $step));
        $this->assertStringNotContainsString('if:', $step[1]);
        $this->assertStringContainsString('extension_loaded("mbstring")', $step[1]);
    }

    /**
     * The two workflows find the tag alike: the stable image when Docker Hub
     * serves it, the candidates before.
     */
    #[Test]
    public function test_the_tag_is_found_alike_in_both_workflows(): void
    {
        $engines = self::job('engines.yml', 'images');

        $this->assertSame($engines, self::job('php74-twin.yml', 'images'));
        $this->assertStringContainsString('docker manifest inspect php:8.6-cli', $engines);
        $this->assertStringContainsString("echo 'php86=8.6' >> \"\$GITHUB_OUTPUT\"", $engines);
        $this->assertStringContainsString("echo 'php86=8.6-rc' >> \"\$GITHUB_OUTPUT\"", $engines);
        $this->assertMatchesRegularExpression('~^    timeout-minutes: \d+$~m', $engines);
    }

    /**
     * @return iterable<string, array{answers: list<string>, tag: string|null}>
     */
    public static function provideDockerHubAnswers(): iterable
    {
        yield 'the release is served' => ['answers' => ['release'], 'tag' => '8.6'];
        yield 'no release yet' => ['answers' => ['missing'], 'tag' => '8.6-rc'];
        yield 'a timeout, then no release' => ['answers' => ['timeout', 'missing'], 'tag' => '8.6-rc'];
        yield 'the rate limit, then the release' => ['answers' => ['limited', 'release'], 'tag' => '8.6'];
        yield 'no answer three times' => ['answers' => ['timeout', 'limited', 'timeout'], 'tag' => null];
    }

    /**
     * The candidates run only when Docker Hub says the release is not
     * there: a timeout or its rate limit asks again, then fails the run,
     * rather than run a candidate once the release exists.
     *
     * @param list<string> $answers
     */
    #[Test]
    #[RequiresOperatingSystem('Linux|Darwin')]
    #[DataProvider('provideDockerHubAnswers')]
    public function test_the_candidates_run_only_when_docker_hub_says_there_is_no_release(array $answers, ?string $tag): void
    {
        $this->scratch = TemporaryDirectory::create('php-regex-image-tags');
        mkdir($this->scratch.'/fake');
        file_put_contents($this->scratch.'/fake/docker', self::FAKE_DOCKER);
        file_put_contents($this->scratch.'/fake/sleep', "#!/usr/bin/env bash\n");
        chmod($this->scratch.'/fake/docker', 0o755);
        chmod($this->scratch.'/fake/sleep', 0o755);
        file_put_contents($this->scratch.'/answers', implode("\n", $answers)."\n");
        file_put_contents($this->scratch.'/output', '');
        $this->assertSame(1, preg_match('~^      - name: Find the tag of PHP 8\.6\n(?:        (?!run:).*\n)*        run: \|\n((?:(?:          .*)?\n)+)~m', self::job('engines.yml', 'images'), $step));
        file_put_contents($this->scratch.'/step.sh', (string) preg_replace('~^ {10}~m', '', $step[1]));

        $process = proc_open(
            ['bash', '--noprofile', '--norc', '-eo', 'pipefail', $this->scratch.'/step.sh'],
            ProcessPipes::descriptors(),
            $pipes,
            $this->scratch,
            ['PATH' => $this->scratch.'/fake:'.getenv('PATH'), 'LOGS' => $this->scratch, 'GITHUB_OUTPUT' => $this->scratch.'/output'],
        );
        $this->assertIsResource($process);
        [, $error] = ProcessPipes::drain($pipes);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        $this->assertSame(\count($answers), (int) file_get_contents($this->scratch.'/calls'), 'Docker Hub is asked until it answers, three times at most.');
        if (null === $tag) {
            $this->assertNotSame(0, $code, 'No answer fails the run.');
            $this->assertSame('', file_get_contents($this->scratch.'/output'));
            $this->assertStringContainsString('Client.Timeout exceeded', $error);

            return;
        }

        $this->assertSame(0, $code, $error);
        $this->assertSame('php86='.$tag."\n", file_get_contents($this->scratch.'/output'));
    }

    /**
     * Outside the job that finds it, no workflow names a candidate's tag.
     */
    #[Test]
    public function test_no_other_job_names_a_release_candidate(): void
    {
        foreach (glob(self::root().'/.github/workflows/*.yml') ?: [] as $path) {
            $source = (string) file_get_contents($path);
            $source = (string) preg_replace('~\n  images:\n.*?(?=\n  [\w-]+:\n|\z)~s', "\n", $source);

            $this->assertDoesNotMatchRegularExpression('~\d+\.\d+-rc\b~', $source, basename($path).' names a candidate outside the images job.');
        }
    }

    /**
     * The tools' locks admit PHP 8.6: no install of them ignores its version
     * there, as one still may on a PHP not released yet.
     */
    #[Test]
    public function test_the_tools_install_on_php_8_6_without_ignoring_its_version(): void
    {
        $this->assertStringNotContainsString('--ignore-platform-req', self::job('engines.yml', 'engines-bundled'));

        $action = (string) file_get_contents(self::root().'/.github/actions/setup-php-project/action.yml');
        $this->assertStringNotContainsString('8.6*', $action);
        $this->assertStringContainsString('8.7*) flags+=(--ignore-platform-req=php+) ;;', $action);
    }

    private static function job(string $workflow, string $job): string
    {
        $source = (string) file_get_contents(self::root().'/.github/workflows/'.$workflow);
        self::assertSame(1, preg_match('~\n  '.preg_quote($job, '~').':\n(.*?)(?=\n  [\w-]+:\n|\z)~s', $source, $match), $workflow.' has a '.$job.' job.');

        return $match[1];
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
