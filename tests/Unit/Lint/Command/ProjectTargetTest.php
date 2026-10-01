<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Lint\Command;

use PhpRegex\Linter\Config\ProjectTarget;
use PhpRegex\Parser\PcreTarget;
use PhpRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The PHP and PCRE2 a project is linted for: the explicit flag, else
 * regex.json, else composer.json (config.platform.php, then the floor of
 * require.php), else the running PHP. Resolving never fails; what it could
 * not read becomes a notice.
 */
final class ProjectTargetTest extends TestCase
{
    use TemporaryProject;

    #[Test]
    public function test_resolve_prefers_the_flag_over_everything(): void
    {
        $directory = $this->makeProject(['composer.json' => self::composer('^8.2', '8.2.10')]);

        $resolved = ProjectTarget::resolve('8.3', null, ['phpVersion' => '8.4'], $directory, []);

        $this->assertTarget(80300, '10.42', $resolved);
        $this->assertSame('--php-version', $resolved->source());
    }

    #[Test]
    public function test_resolve_prefers_regex_json_over_composer(): void
    {
        $directory = $this->makeProject(['composer.json' => self::composer('^8.2', '8.2.10')]);

        $resolved = ProjectTarget::resolve(null, null, ['phpVersion' => '8.4'], $directory, []);

        $this->assertTarget(80400, '10.44', $resolved);
        $this->assertSame('regex.json', $resolved->source());
    }

    #[Test]
    public function test_resolve_reads_a_php_version_id_from_regex_json(): void
    {
        $resolved = ProjectTarget::resolve(null, null, ['phpVersion' => 80300], $this->makeProject(), []);

        $this->assertTarget(80300, '10.42', $resolved);
        $this->assertSame('regex.json', $resolved->source());
    }

    #[Test]
    public function test_resolve_prefers_the_platform_over_the_require_floor(): void
    {
        $directory = $this->makeProject(['composer.json' => self::composer('^8.2', '8.3.10')]);

        $resolved = ProjectTarget::resolve(null, null, [], $directory, []);

        $this->assertTarget(80310, '10.42', $resolved);
        $this->assertSame('composer.json config.platform.php', $resolved->source());
        $this->assertSame([], $resolved->notices());
    }

    #[Test]
    public function test_resolve_takes_the_floor_of_require_php(): void
    {
        $directory = $this->makeProject(['composer.json' => self::composer('^8.3')]);

        $resolved = ProjectTarget::resolve(null, null, [], $directory, []);

        $this->assertTarget(80300, '10.42', $resolved);
        $this->assertSame('composer.json require.php', $resolved->source());
        $this->assertSame([], $resolved->notices());
    }

    #[Test]
    public function test_resolve_pairs_a_composer_php_with_its_bundled_pcre_not_the_running_one(): void
    {
        // PHP 8.4 bundles PCRE2 10.44, whatever release the running PHP links.
        $directory = $this->makeProject(['composer.json' => self::composer('^8.4')]);

        $resolved = ProjectTarget::resolve(null, null, [], $directory, []);

        $this->assertTarget(80400, '10.44', $resolved);
    }

    #[Test]
    public function test_resolve_falls_back_to_the_running_engine(): void
    {
        $resolved = ProjectTarget::resolve(null, null, [], $this->makeProject(), []);

        $this->assertTarget(\PHP_VERSION_ID, PcreTarget::runtime()->pcreVersion, $resolved);
        $this->assertSame('running PHP', $resolved->source());
    }

    #[Test]
    public function test_resolve_takes_pcre_from_the_flag_and_php_from_composer(): void
    {
        $directory = $this->makeProject(['composer.json' => self::composer('^8.3')]);

        $resolved = ProjectTarget::resolve(null, '10.43', ['pcreVersion' => '10.45'], $directory, []);

        $this->assertTarget(80300, '10.43', $resolved);
    }

    #[Test]
    public function test_resolve_takes_pcre_from_regex_json_and_php_from_the_flag(): void
    {
        $resolved = ProjectTarget::resolve('8.4', null, ['pcreVersion' => '10.42'], $this->makeProject(), []);

        $this->assertTarget(80400, '10.42', $resolved);
    }

    #[Test]
    public function test_resolve_takes_pcre_alone_with_the_running_php(): void
    {
        $resolved = ProjectTarget::resolve(null, '10.42', [], $this->makeProject(), []);

        $this->assertTarget(\PHP_VERSION_ID, '10.42', $resolved);
    }

    #[Test]
    #[DataProvider('provideConstraints')]
    public function test_resolve_reads_the_lowest_php_a_constraint_allows(string $constraint, int $phpVersionId): void
    {
        $directory = $this->makeProject(['composer.json' => self::composer($constraint)]);

        $resolved = ProjectTarget::resolve(null, null, [], $directory, []);

        $this->assertSame($phpVersionId, $resolved->target()->phpVersionId);
        $this->assertSame(PcreTarget::bundledWith($phpVersionId)->pcreVersion, $resolved->target()->pcreVersion);
        $this->assertSame('composer.json require.php', $resolved->source());
        $this->assertSame([], $resolved->notices());
    }

    /**
     * Floors of 8.3 and above, so that no case can pass by landing on the
     * 8.2 clamp. Each floor agrees with composer/semver's lower bound
     * (VersionParser::parseConstraints()->getLowerBound()), except `>8.3`:
     * its bound is exclusive, and the target stays on 8.3 by design, since
     * no rule depends on a patch release.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function provideConstraints(): iterable
    {
        yield 'greater or equal' => ['>=8.3', 80300];
        yield 'greater or equal with a space' => ['>= 8.3', 80300];
        yield 'strictly greater stays on the named version' => ['>8.3', 80300];
        yield 'caret' => ['^8.3', 80300];
        yield 'tilde with a patch' => ['~8.3.1', 80301];
        yield 'bare version' => ['8.3.4', 80304];
        yield 'wildcard minor' => ['8.3.*', 80300];
        yield 'AND with a comma' => ['>=8.3,<8.5', 80300];
        yield 'AND with a space' => ['>=8.3 <8.5', 80300];
        yield 'OR with double pipes' => ['^8.3 || ^8.4', 80300];
        yield 'OR with a single pipe, lowest branch last' => ['^8.4|^8.3', 80300];
        yield 'hyphen range' => ['8.3 - 8.4', 80300];
        yield 'patch-level floor kept' => ['^8.3.2', 80302];
    }

    #[Test]
    #[DataProvider('provideClampedConstraints')]
    public function test_resolve_clamps_a_floor_below_8_2(string $constraint): void
    {
        $directory = $this->makeProject(['composer.json' => self::composer($constraint)]);

        $resolved = ProjectTarget::resolve(null, null, [], $directory, []);

        $this->assertTarget(80200, '10.40', $resolved);
        $this->assertNotSame([], $resolved->notices());
        $this->assertStringContainsString('8.2', implode("\n", $resolved->notices()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideClampedConstraints(): iterable
    {
        yield 'OR of old majors' => ['^7.4 || ^8.0'];
        yield 'range starting at 8.1' => ['>=8.1,<8.4'];
    }

    #[Test]
    #[DataProvider('provideUnreadableConstraints')]
    public function test_resolve_falls_back_to_the_running_php_on_an_unreadable_constraint(string $constraint): void
    {
        $directory = $this->makeProject(['composer.json' => self::composer($constraint)]);

        $resolved = ProjectTarget::resolve(null, null, [], $directory, []);

        $this->assertTarget(\PHP_VERSION_ID, PcreTarget::runtime()->pcreVersion, $resolved);
        $this->assertSame('running PHP', $resolved->source());
        $this->assertStringContainsString($constraint, implode("\n", $resolved->notices()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnreadableConstraints(): iterable
    {
        yield 'any version' => ['*'];
        yield 'upper bound only' => ['<9'];
        yield 'branch name' => ['dev-main'];
    }

    #[Test]
    #[DataProvider('provideUnreadableComposerFiles')]
    public function test_resolve_falls_back_to_the_running_php_on_an_unreadable_composer_file(?string $contents): void
    {
        $directory = $this->makeProject(null === $contents ? [] : ['composer.json' => $contents]);

        $resolved = ProjectTarget::resolve(null, null, [], $directory, []);

        $this->assertTarget(\PHP_VERSION_ID, PcreTarget::runtime()->pcreVersion, $resolved);
        $this->assertSame('running PHP', $resolved->source());
        $this->assertStringContainsString('composer.json', implode("\n", $resolved->notices()));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function provideUnreadableComposerFiles(): iterable
    {
        yield 'no require.php' => ['{"require": {"ext-mbstring": "*"}}'];
        yield 'no require at all' => ['{"name": "acme/app"}'];
        yield 'invalid JSON' => ['{"require": '];
        yield 'not an object' => ['"acme"'];
        yield 'require.php not a string' => ['{"require": {"php": 8.3}}'];
        yield 'missing file' => [null];
    }

    #[Test]
    public function test_resolve_reads_the_composer_file_named_by_the_environment(): void
    {
        $directory = $this->makeProject([
            'composer.json' => self::composer('^8.2'),
            'composer.alt.json' => self::composer('^8.4'),
        ]);

        $resolved = ProjectTarget::resolve(null, null, [], $directory, ['COMPOSER' => 'composer.alt.json']);

        $this->assertTarget(80400, '10.44', $resolved);
        $this->assertStringContainsString('require.php', $resolved->source());
    }

    #[Test]
    public function test_resolve_reads_composer_json_in_the_project_directory_only(): void
    {
        // composer.json is read where regex.json is read, never from a parent.
        $parent = $this->makeProject(['composer.json' => self::composer('^8.3'), 'app/.keep' => '']);

        $resolved = ProjectTarget::resolve(null, null, [], $parent.'/app', []);

        $this->assertSame('running PHP', $resolved->source());
        $this->assertSame(\PHP_VERSION_ID, $resolved->target()->phpVersionId);
    }

    #[Test]
    public function test_resolve_ignores_the_composer_file_when_a_flag_decides(): void
    {
        $directory = $this->makeProject(['composer.json' => '{"require": ']);

        $resolved = ProjectTarget::resolve('8.3', null, [], $directory, []);

        $this->assertTarget(80300, '10.42', $resolved);
    }

    private function assertTarget(int $phpVersionId, string $pcreVersion, ProjectTarget $resolved): void
    {
        $this->assertSame($phpVersionId, $resolved->target()->phpVersionId, 'PHP version');
        $this->assertSame($pcreVersion, $resolved->target()->pcreVersion, 'PCRE2 release');
    }

    private static function composer(string $requirePhp, ?string $platformPhp = null): string
    {
        $composer = ['name' => 'acme/app', 'require' => ['php' => $requirePhp]];
        if (null !== $platformPhp) {
            $composer['config'] = ['platform' => ['php' => $platformPhp]];
        }

        return json_encode($composer, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}
