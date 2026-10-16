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

namespace PHPRegex\Tests\Unit\Lint\Command;

use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Config\UnsupportedTargetException;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The corners of the target resolution: a platform that is not a version,
 * a dangling constraint, a COMPOSER path, the joined source, and a version
 * named on the command line that names nothing.
 */
final class ProjectTargetEdgeCasesTest extends TestCase
{
    use TemporaryProject;

    #[Test]
    public function test_resolve_reads_require_php_when_the_platform_is_not_a_version(): void
    {
        $directory = $this->makeProject(['composer.json' => '{"require": {"php": "^8.3"}, "config": {"platform": {"php": "latest"}}}']);

        $resolved = ProjectTarget::resolve(null, null, [], $directory, []);

        $this->assertSame(80300, $resolved->target()->phpVersionId);
        $this->assertSame('composer.json require.php', $resolved->source());
        $this->assertStringContainsString('"latest"', implode("\n", $resolved->notices()));
    }

    #[Test]
    public function test_resolve_judges_an_old_platform_with_the_pcre2_it_bundles(): void
    {
        $directory = $this->makeProject(['composer.json' => '{"require": {"php": "^8.3"}, "config": {"platform": {"php": "7.4.33"}}}']);

        $resolved = ProjectTarget::resolve(null, null, [], $directory, []);

        $this->assertSame(70433, $resolved->target()->phpVersionId);
        $this->assertSame('10.33', $resolved->target()->pcreVersion);
        $this->assertSame('composer.json config.platform.php', $resolved->source());
        $this->assertStringContainsString('PCRE2 10.33', implode("\n", $resolved->notices()));
        $this->assertSame(
            ['PCRE2 10.33 is judged with the PCRE2 10.39 rules, the oldest this library knows in full.'],
            $resolved->notices(),
        );
    }

    /**
     * A version named below PHP 8.1 is judged for itself, with the PCRE2
     * its sources bundle, and the notice says that release is judged with
     * the rules of 10.39, the oldest the library knows in full: "PHP 7.3,
     * PCRE2 10.39" would name an engine no PHP 7.3 runs.
     *
     * @param array<string, string|int|null> $php
     * @param list<string>                   $notices
     */
    #[Test]
    #[DataProvider('provideNamedVersionsBelowTheFloor')]
    public function test_resolve_judges_a_version_named_below_8_1_with_the_10_39_rules(array $php, int $phpVersionId, string $pcre, array $notices): void
    {
        $resolved = ProjectTarget::fromSources($php, [], $this->makeProject(), []);

        $this->assertSame($phpVersionId, $resolved->target()->phpVersionId);
        $this->assertSame($pcre, $resolved->target()->pcreVersion);
        $this->assertSame($notices, $resolved->notices());
        $this->assertSame([['php' => ProjectTarget::phpLabel($phpVersionId), 'pcre' => $pcre]], $resolved->toArray()['range']);
    }

    /**
     * @return iterable<string, array{php: array<string, string|int|null>, phpVersionId: int, pcre: string, notices: list<string>}>
     */
    public static function provideNamedVersionsBelowTheFloor(): iterable
    {
        yield 'the flag' => [
            'php' => ['--php-version' => '7.3', 'regex.json' => '8.4'],
            'phpVersionId' => 70300,
            'pcre' => '10.32',
            'notices' => ['PCRE2 10.32 is judged with the PCRE2 10.39 rules, the oldest this library knows in full.'],
        ];
        yield 'regex.json with a patch release' => [
            'php' => ['--php-version' => null, 'regex.json' => '7.4.33'],
            'phpVersionId' => 70433,
            'pcre' => '10.33',
            'notices' => ['PCRE2 10.33 is judged with the PCRE2 10.39 rules, the oldest this library knows in full.'],
        ];
        yield 'regex.json as a PHP_VERSION_ID' => [
            'php' => ['regex.json' => 80000],
            'phpVersionId' => 80000,
            'pcre' => '10.35',
            'notices' => ['PCRE2 10.35 is judged with the PCRE2 10.39 rules, the oldest this library knows in full.'],
        ];
        yield 'a bridge setting' => [
            'php' => ['php_regex.php_version' => '7.4'],
            'phpVersionId' => 70400,
            'pcre' => '10.33',
            'notices' => ['PCRE2 10.33 is judged with the PCRE2 10.39 rules, the oldest this library knows in full.'],
        ];
    }

    #[Test]
    public function test_resolve_keeps_the_pcre_release_named_with_an_old_version(): void
    {
        $resolved = ProjectTarget::resolve('7.4', '10.42', [], $this->makeProject(), []);

        $this->assertSame(70400, $resolved->target()->phpVersionId);
        $this->assertSame('10.42', $resolved->target()->pcreVersion);
        $this->assertSame('--php-version; --pcre-version', $resolved->source());
        $this->assertSame([], $resolved->notices());
    }

    #[Test]
    public function test_resolve_says_nothing_of_a_release_the_library_knows_in_full(): void
    {
        $resolved = ProjectTarget::resolve('8.1', null, [], $this->makeProject(), []);

        $this->assertSame(80100, $resolved->target()->phpVersionId);
        $this->assertSame([], $resolved->notices());
    }

    #[Test]
    public function test_resolve_names_an_older_pcre2_release_named_alone(): void
    {
        $resolved = ProjectTarget::resolve('8.2', '10.34', [], $this->makeProject(), []);

        $this->assertSame(['PCRE2 10.34 is judged with the PCRE2 10.39 rules, the oldest this library knows in full.'], $resolved->notices());
    }

    /**
     * PHP 7.2 and earlier link PCRE1 (8.41 for 7.2.0, php:7.2-cli); 7.3.0
     * is the first PHP on PCRE2 (10.32), with no release in between. PCRE1
     * is another engine, not an older PCRE2: no verdict of this library
     * holds for it, so a target there is refused, never judged as another.
     *
     * @param array<string, string|int|null> $php
     * @param array<string, string>          $files
     */
    #[Test]
    #[DataProvider('providePcre1Targets')]
    public function test_resolve_refuses_a_php_that_ships_pcre1(array $php, array $files, string $message): void
    {
        $directory = $this->makeProject($files);

        try {
            ProjectTarget::fromSources($php, [], $directory, []);
            self::fail('A PHP on PCRE1 was judged.');
        } catch (UnsupportedTargetException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{php: array<string, string|int|null>, files: array<string, string>, message: string}>
     */
    public static function providePcre1Targets(): iterable
    {
        $refusal = 'PHP 7.2 and earlier ship PCRE1, which this library does not judge. Name a PHP version of 7.3 or later to judge for.';

        yield 'the flag at 7.2' => [
            'php' => ['--php-version' => '7.2'],
            'files' => [],
            'message' => '--php-version names PHP 7.2: '.$refusal,
        ];
        yield 'the flag at 5.6' => [
            'php' => ['--php-version' => '5.6'],
            'files' => [],
            'message' => '--php-version names PHP 5.6: '.$refusal,
        ];
        yield 'regex.json as the last PHP_VERSION_ID on PCRE1' => [
            'php' => ['--php-version' => null, 'regex.json' => 70299],
            'files' => [],
            'message' => 'regex.json names PHP 7.2: '.$refusal,
        ];
        yield 'a bridge setting' => [
            'php' => ['php_regex.php_version' => '7.1'],
            'files' => [],
            'message' => 'php_regex.php_version names PHP 7.1: '.$refusal,
        ];
        yield 'the floor of require.php' => [
            'php' => [],
            'files' => ['composer.json' => '{"require": {"php": "^7.1 || ^8.0"}}'],
            'message' => 'composer.json require.php allows PHP 7.1: '.$refusal,
        ];
        yield 'the platform' => [
            'php' => [],
            'files' => ['composer.json' => '{"require": {"php": "^8.1"}, "config": {"platform": {"php": "7.2.34"}}}'],
            'message' => 'composer.json config.platform.php allows PHP 7.2: '.$refusal,
        ];
    }

    #[Test]
    public function test_resolve_does_not_refuse_the_first_php_on_pcre2(): void
    {
        $resolved = ProjectTarget::fromSources([], [], $this->makeProject(['composer.json' => '{"require": {"php": ">=7.3"}}']), []);

        $this->assertNotSame([], $resolved->notices());
    }

    #[Test]
    #[DataProvider('provideDanglingConstraints')]
    public function test_resolve_falls_back_on_a_dangling_constraint(string $constraint): void
    {
        $directory = $this->makeProject(['composer.json' => json_encode(['require' => ['php' => $constraint]], \JSON_THROW_ON_ERROR)]);

        $resolved = ProjectTarget::resolve(null, null, [], $directory, []);

        $this->assertSame('running PHP', $resolved->source());
        $this->assertNotSame([], $resolved->notices());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideDanglingConstraints(): iterable
    {
        yield 'empty' => [''];
        yield 'trailing OR' => ['^8.3 ||'];
        yield 'one unreadable branch' => ['^8.3 || dev-main'];
    }

    #[Test]
    public function test_resolve_reads_an_absolute_composer_path(): void
    {
        $elsewhere = $this->makeProject(['acme.json' => '{"require": {"php": "^8.4"}}']);

        $resolved = ProjectTarget::resolve(null, null, [], $this->makeProject(), ['COMPOSER' => $elsewhere.'/acme.json']);

        $this->assertSame(80400, $resolved->target()->phpVersionId);
    }

    #[Test]
    public function test_resolve_joins_the_sources_php_first(): void
    {
        $directory = $this->makeProject(['composer.json' => '{"require": {"php": "^8.3"}}']);

        $this->assertSame('composer.json require.php; --pcre-version', ProjectTarget::resolve(null, '10.43', [], $directory, [])->source());
        $this->assertSame('regex.json; --pcre-version', ProjectTarget::resolve(null, '10.43', ['phpVersion' => '8.4'], $directory, [])->source());
        $this->assertSame('--php-version; regex.json', ProjectTarget::resolve('8.4', null, ['pcreVersion' => '10.42'], $directory, [])->source());
        $this->assertSame('running PHP; --pcre-version', ProjectTarget::resolve(null, '10.42', [], $this->makeProject(), [])->source());
    }

    #[Test]
    public function test_resolve_exposes_the_target_for_json_and_for_the_parser(): void
    {
        $resolved = ProjectTarget::resolve('8.3.7', '10.42', [], $this->makeProject(), []);

        $this->assertSame('8.3.7', $resolved->php());
        // php and the range both name the patch the flag gives.
        $this->assertSame(['php' => '8.3.7', 'pcre' => '10.42', 'source' => '--php-version; --pcre-version', 'range' => [['php' => '8.3.7', 'pcre' => '10.42']]], $resolved->toArray());
        $this->assertSame(['php_version' => 80307, 'pcre_version' => '10.42'], $resolved->regexOptions());
    }

    #[Test]
    public function test_resolve_keeps_the_running_pcre_with_the_running_php(): void
    {
        $resolved = ProjectTarget::resolve(null, null, [], $this->makeProject(), []);

        $this->assertSame(PcreTarget::runtime()->pcreVersion, $resolved->target()->pcreVersion);
    }

    /**
     * @param array{phpVersion?: string|int, pcreVersion?: string} $config
     */
    #[Test]
    #[DataProvider('provideInvalidVersions')]
    public function test_resolve_refuses_a_version_that_names_nothing(?string $php, ?string $pcre, array $config): void
    {
        $this->expectException(InvalidRegexOptionException::class);

        ProjectTarget::resolve($php, $pcre, $config, $this->makeProject(), []);
    }

    /**
     * @return iterable<string, array{?string, ?string, array{phpVersion?: string|int, pcreVersion?: string}}>
     */
    public static function provideInvalidVersions(): iterable
    {
        yield 'php flag' => ['eight', null, []];
        yield 'pcre flag' => [null, 'latest', []];
    }
}
