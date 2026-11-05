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

namespace PHPRegex\Tests\Documentation;

use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\PlaygroundDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The findings the site ships, as bin/findings-report last wrote them from
 * the corpus run: one index, one file and one page per repository. The
 * tests hold the shape the page reads, that every repository says the PHP
 * and PCRE2 it was judged for, that every link is pinned to the commit the
 * code was read at, and that every witness the text field receives makes
 * PHP give up.
 *
 * @phpstan-import-type Index from FindingsReportTest
 * @phpstan-import-type RepoFile from FindingsReportTest
 */
#[Group('documentation')]
final class FindingsDataTest extends TestCase
{
    use PcreGates;

    private const DOCS = PlaygroundDriver::ROOT.'/docs';
    private const INDEX = self::DOCS.'/assets/findings/index.json';
    private const SUBJECT_CAP = 10000;

    #[Test]
    public function test_the_index_adds_up(): void
    {
        $index = self::index();

        $this->assertNotSame([], $index['repos']);
        $this->assertSame(\count($index['repos']), $index['totals']['repos']);
        $this->assertSame(array_sum(array_column($index['repos'], 'findings')), $index['totals']['findings']);
        $this->assertSame($index['totals']['findings'], $index['totals']['fail'] + $index['totals']['warn']);
        $this->assertSame(['version'], array_keys($index['library']), 'The library\'s version, never a commit that moves with every change.');
        $this->assertNotSame('', $index['library']['version']);
        $order = array_map(static fn (array $repo): array => [-$repo['fail'], strtolower($repo['repo'])], $index['repos']);
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order, 'FAIL count first, then the name.');
    }

    #[Test]
    public function test_each_repository_says_its_target_and_its_commit(): void
    {
        foreach (self::index()['repos'] as $repo) {
            $this->assertMatchesRegularExpression('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $repo['repo']);
            $this->assertSame(str_replace(['/', '.'], ['__', '~'], $repo['repo']), $repo['slug'], 'A dot is spelled "~" in the site\'s files: no file under docs/ reads as PHP.');
            $this->assertSame(explode('/', $repo['repo'])[0], $repo['owner']);
            $this->assertMatchesRegularExpression('~^[78]\.\d+(?:\.\d+)?$~D', $repo['target']['php'], $repo['repo'].': the PHP it was judged for.');
            $this->assertMatchesRegularExpression('~^10\.\d+$~D', $repo['target']['pcre'], $repo['repo'].': the PCRE2 that PHP bundles.');
            $this->assertStringNotContainsString('/', $repo['target']['source'], $repo['repo'].': the target names composer.json, never a path on the machine that ran it.');
            $this->assertMatchesRegularExpression('~^[0-9a-f]{40}$~D', $repo['sha']);
            $this->assertMatchesRegularExpression('~^\d{4}-\d{2}-\d{2}$~D', $repo['committed']);
            $this->assertGreaterThan(0, $repo['findings'], 'A repository without a FAIL or a WARN is not listed.');
            $this->assertSame($repo['findings'], $repo['fail'] + $repo['warn']);
            $this->assertArrayHasKey($repo['owner'], self::index()['owners']);
        }
    }

    #[Test]
    public function test_each_owner_has_its_avatar_on_the_site(): void
    {
        foreach (self::index()['owners'] as $login => $owner) {
            $this->assertSame('/assets/findings/avatars/'.$login.'.png', $owner['avatar'], 'A copy the site serves, never a request to another host.');
            $this->assertSame('https://github.com/'.$login, $owner['profile']);
            $this->assertFileExists(self::DOCS.$owner['avatar']);
        }
    }

    #[Test]
    public function test_each_repository_has_its_file_and_its_page_and_nothing_else_has(): void
    {
        $slugs = array_column(self::index()['repos'], 'slug');
        sort($slugs);
        $files = array_map(static fn (string $path): string => basename($path, '.json'), glob(self::DOCS.'/assets/findings/repos/*.json') ?: []);
        $pages = array_map(static fn (string $path): string => basename($path, '.md'), glob(self::DOCS.'/_findings/*.md') ?: []);
        sort($files);
        sort($pages);

        $this->assertSame($slugs, $files);
        $this->assertSame($slugs, $pages);
    }

    #[Test]
    public function test_each_finding_is_a_fail_or_a_warn_pinned_to_its_commit(): void
    {
        foreach (self::index()['repos'] as $repo) {
            $file = self::repo($repo['slug']);
            $this->assertSame($repo['repo'], $file['repo']);
            $this->assertSame($repo['sha'], $file['sha']);
            $this->assertCount($repo['findings'], $file['findings']);
            $ids = array_column($file['findings'], 'id');
            $this->assertSame(array_values(array_unique($ids)), $ids, $repo['repo'].': one id per finding.');
            $fail = 0;
            foreach ($file['findings'] as $finding) {
                $this->assertMatchesRegularExpression('~^f-[0-9a-f]{10}(?:-\d+)?$~D', $finding['id']);
                $this->assertNotSame([], $finding['issues']);
                foreach ($finding['issues'] as $issue) {
                    $this->assertContains($issue['level'], ['fail', 'warn'], 'INFO is advice, not a finding.');
                    $this->assertStringStartsWith('regex.', $issue['rule']);
                    if (null !== $issue['docs']) {
                        $this->assertStringStartsWith('https://php-regex.com/', $issue['docs'], 'A rule links to its page on this site.');
                    }
                }
                $this->assertSame($finding['issues'][0]['level'], $finding['level'], 'The worst issue leads.');
                $fail += 'fail' === $finding['level'] ? 1 : 0;
                $this->assertStringStartsWith('https://github.com/'.$repo['repo'].'/blob/'.$repo['sha'].'/', $finding['permalink']);
                $numbers = array_column($finding['snippet'], 'number');
                $this->assertNotEmpty($numbers, 'Every finding shows its line.');
                $this->assertLessThanOrEqual(7, \count($numbers), 'A citation, not a copy.');
                $this->assertSame(range(min($numbers), max($numbers)), $numbers);
                $this->assertStringEndsWith('#L'.min($numbers).'-L'.max($numbers), $finding['permalink']);
            }
            $this->assertSame($repo['fail'], $fail);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, pcre: string}>
     */
    public static function provideWitnesses(): iterable
    {
        foreach (self::index()['repos'] as $repo) {
            foreach (self::repo($repo['slug'])['findings'] as $finding) {
                if (null !== $finding['witness']) {
                    yield $repo['repo'].' '.$finding['id'] => ['pattern' => $finding['pattern'], 'subject' => $finding['subject'], 'pcre' => $finding['witness']['pcre']];
                }
            }
        }
    }

    /**
     * The oracle: what Edit a copy puts in the text field makes the real
     * preg_match() give up, JIT off, under PHP's default backtrack limit,
     * on the PCRE2 release the corpus run measured it on.
     */
    #[Test]
    #[DataProvider('provideWitnesses')]
    public function test_the_witness_makes_php_give_up(string $pattern, string $subject, string $pcre): void
    {
        $this->requiresPcre2Release($pcre, 'The witness of '.$pattern);
        $this->assertLessThanOrEqual(self::SUBJECT_CAP, \strlen($subject), 'The text field takes it whole.');
        $jit = (string) \ini_get('pcre.jit');
        $limit = (string) \ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1000000');

        try {
            $this->assertFalse(@preg_match($pattern, $subject));
            $this->assertContains(preg_last_error(), [\PREG_BACKTRACK_LIMIT_ERROR, \PREG_RECURSION_LIMIT_ERROR], preg_last_error_msg());
        } finally {
            ini_set('pcre.jit', $jit);
            ini_set('pcre.backtrack_limit', $limit);
        }
    }

    #[Test]
    public function test_the_files_stay_under_their_size_caps(): void
    {
        $this->assertLessThanOrEqual(256 * 1024, (int) filesize(self::INDEX), 'The index is fetched when the Findings list opens.');
        foreach (glob(self::DOCS.'/assets/findings/repos/*.json') ?: [] as $file) {
            // Measured on the 2026-11-05 run: moodle/moodle, the largest, 1.9 MB.
            $this->assertLessThanOrEqual((int) (2.5 * 1024 * 1024), (int) filesize($file), basename($file).': fetched when its repository opens.');
        }
    }

    /**
     * @return Index
     */
    private static function index(): array
    {
        self::assertFileExists(self::INDEX, 'The findings are missing: run bin/findings-report.');
        /** @var Index $index */
        $index = json_decode((string) file_get_contents(self::INDEX), true, 512, \JSON_THROW_ON_ERROR);

        return $index;
    }

    /**
     * @return RepoFile
     */
    private static function repo(string $slug): array
    {
        self::assertFileExists(self::DOCS.'/assets/findings/repos/'.$slug.'.json');
        /** @var RepoFile $file */
        $file = json_decode((string) file_get_contents(self::DOCS.'/assets/findings/repos/'.$slug.'.json'), true, 512, \JSON_THROW_ON_ERROR);

        return $file;
    }
}
