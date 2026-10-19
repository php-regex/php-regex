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
 * .github/organization is the php-regex/.github repository: the organization's profile and
 * the community files GitHub shows in every repository that has none of its
 * own, the seventeen read-only ones among them. bin/split pushes it to that
 * repository's main branch.
 */
final class OrganizationProfileTest extends TestCase
{
    /**
     * The community files the organization serves, as the monorepo keeps
     * them for itself.
     */
    private const COMMUNITY_FILES = [
        'CODE_OF_CONDUCT.md' => 'CODE_OF_CONDUCT.md',
        'FUNDING.yml' => '.github/FUNDING.yml',
        'ISSUE_TEMPLATE/bug.yml' => '.github/ISSUE_TEMPLATE/bug.yml',
        'ISSUE_TEMPLATE/config.yml' => '.github/ISSUE_TEMPLATE/config.yml',
        'ISSUE_TEMPLATE/feature.yml' => '.github/ISSUE_TEMPLATE/feature.yml',
        'PULL_REQUEST_TEMPLATE.md' => '.github/PULL_REQUEST_TEMPLATE.md',
        'SECURITY.md' => 'SECURITY.md',
    ];

    #[Test]
    public function test_the_profile_presents_every_package(): void
    {
        $profile = (string) file_get_contents(self::root().'/.github/organization/profile/README.md');

        $missing = array_values(array_filter(
            self::packageRepositories(),
            static fn (string $repository): bool => !str_contains($profile, 'php-regex/'.$repository),
        ));

        $this->assertNotSame([], self::packageRepositories());
        $this->assertSame([], $missing, 'The organization profile does not present these packages.');
    }

    #[Test]
    public function test_the_community_files_are_the_monorepo_ones(): void
    {
        $root = self::root();
        $different = [];
        foreach (self::COMMUNITY_FILES as $organization => $monorepo) {
            if (!is_file($root.'/.github/organization/'.$organization) || file_get_contents($root.'/.github/organization/'.$organization) !== file_get_contents($root.'/'.$monorepo)) {
                $different[] = $organization;
            }
        }

        $this->assertSame([], $different, 'These files of .github/organization differ from the monorepo\'s own: copy them over.');
    }

    #[Test]
    public function test_the_organization_serves_no_other_file(): void
    {
        $root = self::root().'/.github/organization';
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $this->assertInstanceOf(\SplFileInfo::class, $file);
            $files[] = substr($file->getPathname(), \strlen($root) + 1);
        }
        sort($files);

        $expected = [...array_keys(self::COMMUNITY_FILES), 'profile/README.md', 'profile/org-icon.svg'];
        sort($expected);

        $this->assertSame($expected, $files);
    }

    #[Test]
    public function test_the_split_pushes_it_to_the_main_branch_of_the_organization_repository(): void
    {
        $script = (string) file_get_contents(self::root().'/bin/split');

        $this->assertMatchesRegularExpression('~^\s+"\.github/organization:\.github:main"$~m', $script);
    }

    /**
     * @return list<string>
     */
    private static function packageRepositories(): array
    {
        $script = (string) file_get_contents(self::root().'/bin/split');
        preg_match_all('~^\s+"src/\w+:([\w-]+)"$~m', $script, $rows);

        return $rows[1];
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
