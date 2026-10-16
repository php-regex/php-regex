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
    public function test_resolve_clamps_an_old_platform(): void
    {
        $directory = $this->makeProject(['composer.json' => '{"require": {"php": "^8.3"}, "config": {"platform": {"php": "7.4.33"}}}']);

        $resolved = ProjectTarget::resolve(null, null, [], $directory, []);

        $this->assertSame(80100, $resolved->target()->phpVersionId);
        $this->assertSame('composer.json config.platform.php', $resolved->source());
        $this->assertStringContainsString('PHP 7.4', implode("\n", $resolved->notices()));
        $this->assertSame(
            ['composer.json config.platform.php allows PHP 7.4, older than the PHP 8.1 this library supports: judging for PHP 8.1.'],
            $resolved->notices(),
        );
    }

    /**
     * A version named below the floor is judged as the floor, as a
     * composer.json floor is, and the substitution is said: "PHP 7.3,
     * PCRE2 10.39" judged nothing any PHP 7.3 runs.
     *
     * @param array<string, string|int|null> $php
     * @param list<string>                   $notices
     */
    #[Test]
    #[DataProvider('provideNamedVersionsBelowTheFloor')]
    public function test_resolve_clamps_a_version_named_below_the_floor(array $php, array $notices): void
    {
        $resolved = ProjectTarget::fromSources($php, [], $this->makeProject(), []);

        $this->assertSame(80100, $resolved->target()->phpVersionId);
        $this->assertSame('10.39', $resolved->target()->pcreVersion);
        $this->assertSame($notices, $resolved->notices());
        $this->assertSame([['php' => '8.1', 'pcre' => '10.39']], $resolved->toArray()['range']);
    }

    /**
     * @return iterable<string, array{php: array<string, string|int|null>, notices: list<string>}>
     */
    public static function provideNamedVersionsBelowTheFloor(): iterable
    {
        yield 'the flag' => [
            'php' => ['--php-version' => '7.3', 'regex.json' => '8.4'],
            'notices' => ['--php-version names PHP 7.3, older than the PHP 8.1 this library supports: judging for PHP 8.1.'],
        ];
        yield 'regex.json with a patch release' => [
            'php' => ['--php-version' => null, 'regex.json' => '7.4.33'],
            'notices' => ['regex.json names PHP 7.4, older than the PHP 8.1 this library supports: judging for PHP 8.1.'],
        ];
        yield 'regex.json as a PHP_VERSION_ID' => [
            'php' => ['regex.json' => 80000],
            'notices' => ['regex.json names PHP 8.0, older than the PHP 8.1 this library supports: judging for PHP 8.1.'],
        ];
        yield 'a bridge setting' => [
            'php' => ['php_regex.php_version' => '7.4'],
            'notices' => ['php_regex.php_version names PHP 7.4, older than the PHP 8.1 this library supports: judging for PHP 8.1.'],
        ];
    }

    #[Test]
    public function test_resolve_keeps_the_pcre_release_named_with_a_clamped_version(): void
    {
        $resolved = ProjectTarget::resolve('7.4', '10.42', [], $this->makeProject(), []);

        $this->assertSame(80100, $resolved->target()->phpVersionId);
        $this->assertSame('10.42', $resolved->target()->pcreVersion);
        $this->assertSame('--php-version; --pcre-version', $resolved->source());
        $this->assertSame(['--php-version names PHP 7.4, older than the PHP 8.1 this library supports: judging for PHP 8.1.'], $resolved->notices());
    }

    #[Test]
    public function test_resolve_does_not_clamp_a_version_named_at_the_floor(): void
    {
        $resolved = ProjectTarget::resolve('8.1', null, [], $this->makeProject(), []);

        $this->assertSame(80100, $resolved->target()->phpVersionId);
        $this->assertSame([], $resolved->notices());
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
