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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every workflow runs on each push to 2.x, which bin/release reads. A pull
 * request runs what its changes can affect: the prose (the site under docs/,
 * the READMEs, the changelogs, the upgrade guide, the community files) is
 * read by the spelling check and the documentation tests, by the link check
 * when the site changes, and by nothing else, so the workflows that judge
 * the code stay out of a
 * pull request that changes the prose alone. The prose is one list, copied
 * as is into every workflow that names it.
 */
#[Group('documentation')]
final class PullRequestScopeTest extends TestCase
{
    /**
     * The paths a pull request changes without a line of code changing.
     */
    private const PROSE = [
        'docs/**',
        'art/**',
        'README.md',
        'CHANGELOG.md',
        'UPGRADE-2.0.md',
        'CONTRIBUTING.md',
        'CODE_OF_CONDUCT.md',
        'SECURITY.md',
        'TODO.md',
        'LICENSE',
        'src/*/README.md',
        'src/*/CHANGELOG.md',
        '.github/organization/**',
        '.github/ISSUE_TEMPLATE/**',
        '.github/PULL_REQUEST_TEMPLATE.md',
        '.github/FUNDING.yml',
        '.github/CODEOWNERS',
    ];

    /**
     * The workflows that judge the code: each stays out of a pull request
     * that changes the prose alone.
     */
    private const CODE = [
        'tests.yml',
        'coverage.yml',
        'engines.yml',
        'packages.yml',
        'benchmarks.yml',
        'static-analysis.yml',
        'backward-compatibility.yml',
    ];

    /**
     * The workflows that run on a pull request for what it changes, each
     * scoped by its own list: the prose readers, the demo applications, the
     * twin, the workflow checks.
     */
    private const SCOPED = [
        'documentation.yml',
        'docs-links.yml',
        'spelling.yml',
        'demo.yml',
        'php74-twin.yml',
        'workflows.yml',
    ];

    /**
     * A workflow a pull request starts has chosen its scope: it judges the
     * code and stays out of a prose change, or it names the paths it reads.
     */
    #[Test]
    public function test_every_workflow_a_pull_request_starts_has_chosen_its_scope(): void
    {
        $started = [];
        foreach (glob(self::root().'/.github/workflows/*.yml') ?: [] as $path) {
            if (1 === preg_match('~^  pull_request:~m', (string) file_get_contents($path))) {
                $started[] = basename($path);
            }
        }
        sort($started);
        $known = [...self::CODE, ...self::SCOPED];
        sort($known);

        $this->assertSame($known, $started, 'A workflow a pull request starts is listed here, as judging the code or as scoped.');
    }

    /**
     * @return iterable<string, array{workflow: string}>
     */
    public static function provideCodeWorkflows(): iterable
    {
        foreach (self::CODE as $workflow) {
            yield $workflow => ['workflow' => $workflow];
        }
    }

    #[Test]
    #[DataProvider('provideCodeWorkflows')]
    public function test_a_code_workflow_stays_out_of_a_pull_request_changing_the_prose_alone(string $workflow): void
    {
        $block = self::pullRequestBlock($workflow);

        $this->assertMatchesRegularExpression('~^    branches: \[ 2\.x \]$~m', $block, $workflow);
        $this->assertSame(self::PROSE, self::listUnder('paths-ignore', $block), $workflow.' ignores the prose, the one list as is.');
        $this->assertStringNotContainsString("\n    paths:", $block, $workflow.' names what it ignores, not what it reads.');
    }

    #[Test]
    public function test_the_documentation_tests_run_on_a_pull_request_changing_the_prose(): void
    {
        $workflow = self::workflow('documentation.yml');
        $block = self::pullRequestBlock('documentation.yml');

        $this->assertMatchesRegularExpression('~^    branches: \[ 2\.x \]$~m', $block);
        $this->assertSame(self::PROSE, self::listUnder('paths', $block), 'The documentation tests read the prose, the one list as is.');
        $this->assertStringNotContainsString("\n  push:", $workflow, 'A push to the branch runs the whole suite, these tests in it.');
        $this->assertStringContainsString("\n  workflow_dispatch:\n", $workflow);
        $this->assertStringContainsString("permissions:\n  contents: read\n", $workflow);
        $this->assertStringNotContainsString(': write', $workflow);
        $this->assertStringContainsString('run: tools/phpunit/vendor/bin/phpunit --colors=always --group documentation', $workflow);
    }

    #[Test]
    public function test_the_spelling_check_runs_on_every_pull_request(): void
    {
        $block = self::pullRequestBlock('spelling.yml');

        $this->assertMatchesRegularExpression('~^    branches: \[ 2\.x \]$~m', $block);
        $this->assertStringNotContainsString('paths', $block, 'The spelling check reads the prose and the sources: every pull request runs it.');
    }

    #[Test]
    public function test_the_workflow_checks_run_when_a_workflow_or_an_action_changes(): void
    {
        $block = self::pullRequestBlock('workflows.yml');

        $this->assertSame(
            ['.github/workflows/**', '.github/actions/**', '.github/zizmor.yml', 'src/*/.github/**'],
            self::listUnder('paths', $block),
            'The checks read the workflows and the local actions: the community files under .github/ do not start them.',
        );
    }

    #[Test]
    public function test_the_demo_applications_stay_out_of_a_pull_request_changing_a_package_readme(): void
    {
        $paths = self::listUnder('paths', self::pullRequestBlock('demo.yml'));

        $this->assertContains('src/**', $paths);
        $this->assertContains('!src/*/README.md', $paths);
        $this->assertContains('!src/*/CHANGELOG.md', $paths);
    }

    /**
     * The prose holds no code: a tracked file the prose patterns match is
     * never a PHP file, a test, a manifest or a configuration the code
     * workflows judge.
     */
    #[Test]
    public function test_the_prose_holds_no_code(): void
    {
        exec('git -C '.escapeshellarg(self::root()).' ls-files', $tracked, $code);
        $this->assertSame(0, $code);
        $this->assertNotSame([], $tracked);

        $patterns = array_map(self::globToRegex(...), self::PROSE);
        $prose = array_values(array_filter($tracked, static function (string $path) use ($patterns): bool {
            foreach ($patterns as $pattern) {
                if (1 === preg_match($pattern, $path)) {
                    return true;
                }
            }

            return false;
        }));

        $this->assertContains('docs/index.md', $prose);
        $this->assertContains('src/Parser/README.md', $prose);
        foreach ($prose as $path) {
            $this->assertStringNotContainsString('.php', $path, $path.' is code, not prose.');
            $this->assertDoesNotMatchRegularExpression('~^(tests|bin|tools|phar|build|demo)/|composer\.json$|\.neon$|\.dist$~', $path, $path.' is code, not prose.');
        }
    }

    /**
     * The lines of the list under a key of the pull request block, as
     * "      - 'docs/**'".
     *
     * @return list<string>
     */
    private static function listUnder(string $key, string $block): array
    {
        if (1 !== preg_match('~^    '.preg_quote($key, '~').":\n((?:      - '[^']+'\n)+)~m", $block, $match)) {
            return [];
        }
        preg_match_all("~^      - '([^']+)'$~m", $match[1], $items);

        return $items[1];
    }

    private static function pullRequestBlock(string $workflow): string
    {
        $source = self::workflow($workflow);
        self::assertSame(1, preg_match('~^  pull_request:\n((?:    .*\n)+)~m', $source, $match), $workflow.' runs on a pull request.');

        return $match[1];
    }

    private static function workflow(string $name): string
    {
        return (string) file_get_contents(self::root().'/.github/workflows/'.$name);
    }

    /**
     * A workflow path pattern as a regular expression: "**" crosses
     * directories, "*" stays in one.
     */
    private static function globToRegex(string $glob): string
    {
        $regex = str_replace(['\*\*', '\*'], ['.*', '[^/]*'], preg_quote($glob, '~'));

        return '~^'.$regex.'$~';
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
