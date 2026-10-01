<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Lint\Command;

use PhpRegex\Linter\Config\ProjectTarget;
use PhpRegex\Parser\Exception\InvalidRegexOptionException;
use PhpRegex\Parser\PcreTarget;
use PhpRegex\Tests\Support\TemporaryProject;
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

        $this->assertSame(80200, $resolved->target()->phpVersionId);
        $this->assertSame('composer.json config.platform.php', $resolved->source());
        $this->assertStringContainsString('PHP 7.4', implode("\n", $resolved->notices()));
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

        $this->assertSame('8.3', $resolved->php());
        $this->assertSame(['php' => '8.3', 'pcre' => '10.42', 'source' => '--php-version; --pcre-version'], $resolved->toArray());
        $this->assertSame(['php_version' => 80307, 'pcre_version' => '10.42'], $resolved->regexOptions());
    }

    #[Test]
    public function test_resolve_keeps_the_running_pcre_with_the_running_php(): void
    {
        $resolved = ProjectTarget::resolve(null, null, [], $this->makeProject(), []);

        $this->assertSame(PcreTarget::runtime()->pcreVersion, $resolved->target()->pcreVersion);
    }

    /**
     * @param array<string, mixed> $config
     */
    #[Test]
    #[DataProvider('provideInvalidVersions')]
    public function test_resolve_refuses_a_version_that_names_nothing(?string $php, ?string $pcre, array $config): void
    {
        $this->expectException(InvalidRegexOptionException::class);

        ProjectTarget::resolve($php, $pcre, $config, $this->makeProject(), []);
    }

    /**
     * @return iterable<string, array{?string, ?string, array<string, mixed>}>
     */
    public static function provideInvalidVersions(): iterable
    {
        yield 'php flag' => ['eight', null, []];
        yield 'pcre flag' => [null, 'latest', []];
    }
}
