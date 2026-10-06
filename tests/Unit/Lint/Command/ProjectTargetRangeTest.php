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
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The targets a project's patterns are validated at: when composer.json
 * require.php gives the PHP, its floor first, then every PHP version a rule
 * changes at (8.3, 8.4, 8.4.25, 8.5, 8.5.10) that the constraint allows, up
 * to its ceiling (the newest known when it is open-ended), and the lowest
 * version of each OR branch above the floor. Each point with
 * the PCRE2 its PHP bundles, or the pinned release at every point. A source
 * that names one version (--php-version, regex.json, config.platform.php)
 * is a range of one.
 */
final class ProjectTargetRangeTest extends TestCase
{
    use TemporaryProject;

    /**
     * @param list<int> $expected PHP_VERSION_IDs, floor first
     */
    #[Test]
    #[DataProvider('provideConstraints')]
    public function test_range_holds_the_floor_then_each_gate_point_the_constraint_allows(string $constraint, array $expected): void
    {
        $directory = $this->makeProject(['composer.json' => self::composer($constraint)]);

        $resolved = ProjectTarget::resolve(null, null, [], $directory, []);
        $range = $resolved->range();

        $this->assertSame($expected, array_map(static fn (PcreTarget $target): int => $target->phpVersionId, $range));
        $this->assertEquals($resolved->target(), $range[0], 'The floor comes first.');
        foreach (\array_slice($range, 1) as $target) {
            $this->assertSame(PcreTarget::bundledWith($target->phpVersionId)->pcreVersion, $target->pcreVersion, (string) $target->phpVersionId);
        }
    }

    /**
     * Upper bounds as composer/semver reads them: "^8.2" is below 9.0,
     * "~8.3.0" below 8.4, "8.2.*" below 8.3, a hyphen range's partial upper
     * version "8.4" below 8.5.
     *
     * @return iterable<string, array{constraint: string, expected: list<int>}>
     */
    public static function provideConstraints(): iterable
    {
        yield 'open-ended' => ['constraint' => '>=8.2', 'expected' => [80200, 80300, 80400, 80425, 80500, 80510]];
        yield 'caret' => ['constraint' => '^8.2', 'expected' => [80200, 80300, 80400, 80425, 80500, 80510]];
        yield 'tilde with a patch' => ['constraint' => '~8.3.0', 'expected' => [80300]];
        yield 'tilde with a patch keeps the patch gates of its minor' => ['constraint' => '~8.4.0', 'expected' => [80400, 80425]];
        // "~8.3" is ">=8.3 <9.0", "~8" is ">=8 <9.0".
        yield 'tilde with a minor' => ['constraint' => '~8.3', 'expected' => [80300, 80400, 80425, 80500, 80510]];
        yield 'tilde with a major' => ['constraint' => '~8', 'expected' => [80200, 80300, 80400, 80425, 80500, 80510]];
        yield 'wildcard minor' => ['constraint' => '8.2.*', 'expected' => [80200]];
        yield 'AND with an upper bound' => ['constraint' => '>=8.2 <8.4', 'expected' => [80200, 80300]];
        yield 'hyphen range' => ['constraint' => '8.2 - 8.4', 'expected' => [80200, 80300, 80400, 80425]];
        // A full upper version is inclusive: "8.2 - 8.4.25" is "<8.4.26".
        yield 'hyphen range up to a patch gate' => ['constraint' => '8.2 - 8.4.25', 'expected' => [80200, 80300, 80400, 80425]];
        yield 'hyphen range up to the patch before a gate' => ['constraint' => '8.2 - 8.4.24', 'expected' => [80200, 80300, 80400]];
        yield 'hyphen range up to a major' => ['constraint' => '8.2 - 8', 'expected' => [80200, 80300, 80400, 80425, 80500, 80510]];
        yield 'OR of overlapping carets' => ['constraint' => '^8.2 || ^8.4', 'expected' => [80200, 80300, 80400, 80425, 80500, 80510]];
        yield 'OR with a gap leaves 8.3 out' => ['constraint' => '8.2.* || 8.4.*', 'expected' => [80200, 80400, 80425]];
        yield 'upper bound inside a minor, before its patch gate' => ['constraint' => '>=8.2 <8.4.25', 'expected' => [80200, 80300, 80400]];
        yield 'upper bound below 8.5 keeps the 8.4 patch gate' => ['constraint' => '>=8.4,<8.5', 'expected' => [80400, 80425]];
        yield 'floor past a patch gate of its minor' => ['constraint' => '^8.4.30', 'expected' => [80430, 80500, 80510]];
        yield 'floor on a patch gate' => ['constraint' => '>=8.4.25', 'expected' => [80425, 80500, 80510]];
        yield 'bare version' => ['constraint' => '8.3.4', 'expected' => [80304]];
        yield 'floor clamped to 8.2' => ['constraint' => '>=8.1,<8.4', 'expected' => [80200, 80300]];
        // "<=8.4" is "<=8.4.0": 8.4 itself, not its 8.4.25 gate.
        yield 'inclusive upper bound' => ['constraint' => '>=8.2 <=8.4', 'expected' => [80200, 80300, 80400]];
        yield 'inclusive upper bound on a patch gate' => ['constraint' => '>=8.2 <=8.4.25', 'expected' => [80200, 80300, 80400, 80425]];
        yield 'inclusive upper bound just before a patch gate' => ['constraint' => '>=8.2 <=8.4.24', 'expected' => [80200, 80300, 80400]];
        // An excluded version leaves its branch judged: a point stands for
        // every release of its minor from there on.
        yield 'excluded version' => ['constraint' => '>=8.2 !=8.3.0', 'expected' => [80200, 80300, 80400, 80425, 80500, 80510]];
        // Each OR branch starts a stretch no gate point stands for until the
        // next one: its lowest version above the floor is judged too.
        yield 'OR branch starting between gates' => ['constraint' => '~8.3.0 || >=8.5.3', 'expected' => [80300, 80503, 80510]];
        yield 'OR branch starting past a patch gate' => ['constraint' => '8.3.* || >=8.4.30', 'expected' => [80300, 80430, 80500, 80510]];
        yield 'OR branch starting on a gate point' => ['constraint' => '8.2.* || >=8.4.25', 'expected' => [80200, 80425, 80500, 80510]];
        yield 'OR branches written highest first' => ['constraint' => '>=8.5.3 || ~8.3.0', 'expected' => [80300, 80503, 80510]];
        yield 'OR branch of one patch release' => ['constraint' => '8.2.* || 8.4.27', 'expected' => [80200, 80427]];
        yield 'OR branch that allows nothing' => ['constraint' => '8.2.* || >=8.5 <8.4 || >=8.5.3', 'expected' => [80200, 80503, 80510]];
        yield 'OR branch empty at its own lowest version' => ['constraint' => '8.2.* || >=8.4 <8.4', 'expected' => [80200]];
        yield 'OR branches below the clamped floor' => ['constraint' => '^7.4 || ^8.0', 'expected' => [80200, 80300, 80400, 80425, 80500, 80510]];
    }

    /**
     * @param list<array{php: string, pcre: string}> $expected
     */
    #[Test]
    #[DataProvider('provideOrBranchLabels')]
    public function test_or_branch_points_carry_their_patch_and_pcre2(string $constraint, ?string $pcreFlag, array $expected): void
    {
        $directory = $this->makeProject(['composer.json' => self::composer($constraint)]);

        $resolved = ProjectTarget::resolve(null, $pcreFlag, [], $directory, []);

        $this->assertSame($expected, $resolved->toArray()['range']);
    }

    /**
     * @return iterable<string, array{constraint: string, pcreFlag: string|null, expected: list<array{php: string, pcre: string}>}>
     */
    public static function provideOrBranchLabels(): iterable
    {
        yield 'bundled PCRE2' => ['constraint' => '~8.3.0 || >=8.5.3', 'pcreFlag' => null, 'expected' => [
            ['php' => '8.3', 'pcre' => '10.42'],
            ['php' => '8.5.3', 'pcre' => '10.44'],
            ['php' => '8.5.10', 'pcre' => '10.44'],
        ]];
        yield 'bundled PCRE2, a branch past a patch gate' => ['constraint' => '8.3.* || >=8.4.30', 'pcreFlag' => null, 'expected' => [
            ['php' => '8.3', 'pcre' => '10.42'],
            ['php' => '8.4.30', 'pcre' => '10.44'],
            ['php' => '8.5', 'pcre' => '10.44'],
            ['php' => '8.5.10', 'pcre' => '10.44'],
        ]];
        yield 'pinned PCRE2' => ['constraint' => '~8.3.0 || >=8.5.3', 'pcreFlag' => '10.43', 'expected' => [
            ['php' => '8.3', 'pcre' => '10.43'],
            ['php' => '8.5.3', 'pcre' => '10.43'],
            ['php' => '8.5.10', 'pcre' => '10.43'],
        ]];
    }

    #[Test]
    public function test_range_of_an_unreadable_constraint_is_the_running_engine(): void
    {
        $directory = $this->makeProject(['composer.json' => self::composer('*')]);

        $resolved = ProjectTarget::resolve(null, null, [], $directory, []);

        $this->assertEquals([PcreTarget::runtime()], $resolved->range());

        // A hyphen range whose upper version cannot be read is unreadable.
        $directory = $this->makeProject(['composer.json' => self::composer('8.2 - next')]);
        $this->assertEquals([PcreTarget::runtime()], ProjectTarget::resolve(null, null, [], $directory, [])->range());
    }

    /**
     * @param array<string, mixed> $config
     */
    #[Test]
    #[DataProvider('provideSingleVersionSources')]
    public function test_range_of_a_single_version_source_is_that_version(?string $flag, array $config, ?string $platform, int $expected): void
    {
        $directory = $this->makeProject(['composer.json' => self::composer('>=8.2', $platform)]);

        $resolved = ProjectTarget::resolve($flag, null, $config, $directory, []);

        $this->assertEquals([$resolved->target()], $resolved->range());
        $this->assertSame($expected, $resolved->target()->phpVersionId);
    }

    /**
     * @return iterable<string, array{flag: string|null, config: array<string, mixed>, platform: string|null, expected: int}>
     */
    public static function provideSingleVersionSources(): iterable
    {
        yield '--php-version' => ['flag' => '8.2', 'config' => [], 'platform' => null, 'expected' => 80200];
        yield 'regex.json' => ['flag' => null, 'config' => ['phpVersion' => '8.3'], 'platform' => null, 'expected' => 80300];
        yield 'config.platform.php' => ['flag' => null, 'config' => [], 'platform' => '8.4.1', 'expected' => 80401];
    }

    #[Test]
    public function test_range_of_the_running_php_is_one_target(): void
    {
        $resolved = ProjectTarget::resolve('runtime', null, [], $this->makeProject(), []);

        $this->assertEquals([PcreTarget::runtime()], $resolved->range());
    }

    /**
     * @param array<string, mixed> $config
     */
    #[Test]
    #[DataProvider('providePinnedPcre')]
    public function test_a_pinned_pcre2_applies_at_every_point(?string $flag, array $config): void
    {
        $directory = $this->makeProject(['composer.json' => self::composer('>=8.2')]);

        $range = ProjectTarget::resolve(null, $flag, $config, $directory, [])->range();

        $this->assertSame([80200, 80300, 80400, 80425, 80500, 80510], array_map(static fn (PcreTarget $target): int => $target->phpVersionId, $range));
        foreach ($range as $target) {
            $this->assertSame('10.43', $target->pcreVersion, (string) $target->phpVersionId);
        }
    }

    /**
     * @return iterable<string, array{flag: string|null, config: array<string, mixed>}>
     */
    public static function providePinnedPcre(): iterable
    {
        yield '--pcre-version' => ['flag' => '10.43', 'config' => []];
        yield 'regex.json pcreVersion' => ['flag' => null, 'config' => ['pcreVersion' => '10.43']];
    }

    #[Test]
    public function test_bridge_sources_judge_the_range_too(): void
    {
        // Every entry point resolves through fromSources(): an unset bridge
        // setting reads composer.json and its range.
        $directory = $this->makeProject(['composer.json' => self::composer('>=8.4')]);

        $resolved = ProjectTarget::fromSources(['php_regex.php_version' => null], ['php_regex.pcre_version' => null], $directory, []);

        $this->assertSame([80400, 80425, 80500, 80510], array_map(static fn (PcreTarget $target): int => $target->phpVersionId, $resolved->range()));
    }

    #[Test]
    public function test_to_array_lists_the_range_as_php_and_pcre_floor_first(): void
    {
        $directory = $this->makeProject(['composer.json' => self::composer('^8.4')]);

        $resolved = ProjectTarget::resolve(null, null, [], $directory, []);

        // php as major.minor, or major.minor.patch when the patch is not 0.
        $this->assertSame([
            'php' => '8.4',
            'pcre' => '10.44',
            'source' => 'composer.json require.php',
            'range' => [
                ['php' => '8.4', 'pcre' => '10.44'],
                ['php' => '8.4.25', 'pcre' => '10.44'],
                ['php' => '8.5', 'pcre' => '10.44'],
                ['php' => '8.5.10', 'pcre' => '10.44'],
            ],
        ], $resolved->toArray());
    }

    /**
     * The JSON names the floor as its first range entry does: one format for
     * a PHP version in the whole document.
     */
    #[Test]
    #[DataProvider('provideFloorLabels')]
    public function test_to_array_names_the_floor_as_its_range_entry_does(?string $flag, string $requirePhp, string $expected): void
    {
        $directory = $this->makeProject(['composer.json' => self::composer($requirePhp)]);

        $document = ProjectTarget::resolve($flag, null, [], $directory, [])->toArray();

        $this->assertSame($expected, $document['php']);
        $this->assertSame($document['range'][0]['php'], $document['php']);
        $this->assertSame($expected, ProjectTarget::resolve($flag, null, [], $directory, [])->php());
    }

    /**
     * @return iterable<string, array{flag: string|null, requirePhp: string, expected: string}>
     */
    public static function provideFloorLabels(): iterable
    {
        yield 'a floor with a patch' => ['flag' => null, 'requirePhp' => '^8.4.30', 'expected' => '8.4.30'];
        yield 'a floor without a patch' => ['flag' => null, 'requirePhp' => '>=8.3', 'expected' => '8.3'];
        yield '--php-version with a patch' => ['flag' => '8.3.7', 'requirePhp' => '>=8.2', 'expected' => '8.3.7'];
        yield 'the running PHP' => ['flag' => 'runtime', 'requirePhp' => '>=8.2', 'expected' => ProjectTarget::phpLabel(\PHP_VERSION_ID)];
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
