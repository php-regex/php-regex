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
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PcreRule;
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
 * the PCRE2 its PHP bundles, the running one for the running PHP's minor,
 * or the pinned release at every point. A source
 * that names one version (--php-version, regex.json, config.platform.php)
 * is a range of one.
 */
final class ProjectTargetRangeTest extends TestCase
{
    use PcreRule;
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
            $this->assertSame(self::judgedPcre($target->phpVersionId), $target->pcreVersion, (string) $target->phpVersionId);
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
        yield 'tilde with a major' => ['constraint' => '~8', 'expected' => [80000, 80100, 80200, 80300, 80400, 80425, 80500, 80510]];
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
        yield 'floor on 8.1' => ['constraint' => '>=8.1,<8.4', 'expected' => [80100, 80200, 80300]];
        yield 'floor on 8.0' => ['constraint' => '>=8.0,<8.4', 'expected' => [80000, 80100, 80200, 80300]];
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
        yield 'OR branches of old majors' => ['constraint' => '^7.4 || ^8.0', 'expected' => [70400, 80000, 80100, 80200, 80300, 80400, 80425, 80500, 80510]];
        yield 'the first PHP on PCRE2' => ['constraint' => '~7.3.0', 'expected' => [70300]];
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

        // The rows name the release each PHP bundles; the running PHP's
        // minor is judged with the running one when none is pinned.
        if (null === $pcreFlag) {
            $expected = array_map(static fn (array $point): array => ['php' => $point['php'], 'pcre' => explode('/', self::judgedLabel($point['php'].'/'.$point['pcre']))[1]], $expected);
        }
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
     * @param array{phpVersion?: string|int, pcreVersion?: string} $config
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
     * @return iterable<string, array{flag: string|null, config: array{phpVersion?: string|int, pcreVersion?: string}, platform: string|null, expected: int}>
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
     * @param array{phpVersion?: string|int, pcreVersion?: string} $config
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
     * @return iterable<string, array{flag: string|null, config: array{phpVersion?: string|int, pcreVersion?: string}}>
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
            'pcre' => self::judgedPcre(80400),
            'locale' => 'C',
            'source' => 'composer.json require.php',
            'range' => [
                ['php' => '8.4', 'pcre' => self::judgedPcre(80400)],
                ['php' => '8.4.25', 'pcre' => self::judgedPcre(80425)],
                ['php' => '8.5', 'pcre' => self::judgedPcre(80500)],
                ['php' => '8.5.10', 'pcre' => self::judgedPcre(80510)],
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

    /**
     * A constraint named in regex.json or by --php-version is judged as the
     * same text in require.php: the same floor, the same points, the same
     * versions installed on for a guard or a marker to narrow, the same
     * notices; only the source differs.
     */
    #[Test]
    #[DataProvider('provideNamedConstraints')]
    public function test_a_regex_json_constraint_is_judged_as_a_require_php_range(string $constraint): void
    {
        $fromComposer = ProjectTarget::resolve(null, null, [], $this->makeProject(['composer.json' => self::composer($constraint)]), []);
        // A composer.json beside it is not read: the range is named.
        $elsewhere = $this->makeProject(['composer.json' => self::composer('8.0.*')]);
        $fromConfig = ProjectTarget::resolve(null, null, ['phpVersion' => $constraint], $elsewhere, []);
        $fromFlag = ProjectTarget::resolve($constraint, null, ['phpVersion' => '8.0'], $elsewhere, []);

        $this->assertSame('composer.json require.php', $fromComposer->source());
        $this->assertSame('regex.json', $fromConfig->source());
        $this->assertSame('--php-version', $fromFlag->source());
        foreach ([$fromConfig, $fromFlag] as $named) {
            $this->assertSame([$fromComposer->target()->phpVersionId, $fromComposer->target()->pcreVersion, $fromComposer->target()->locale], [$named->target()->phpVersionId, $named->target()->pcreVersion, $named->target()->locale]);
            $this->assertSame($fromComposer->toArray()['range'], $named->toArray()['range']);
            $this->assertSame(self::admitted($fromComposer), self::admitted($named));
            $this->assertSame($fromComposer->notices(), $named->notices());
        }
    }

    /**
     * @return iterable<string, array{constraint: string}>
     */
    public static function provideNamedConstraints(): iterable
    {
        yield 'open-ended' => ['constraint' => '>=8.1'];
        yield 'caret with an upper bound' => ['constraint' => '^8.1 <8.7'];
        yield 'hyphen range' => ['constraint' => '8.1 - 8.6'];
        yield 'OR of carets' => ['constraint' => '^7.3 || ^8.0'];
        yield 'OR branch starting between gates' => ['constraint' => '~8.3.0 || >=8.5.3'];
        // The last gate point exactly: no point above the floor.
        yield 'from the last gate point' => ['constraint' => '>=8.5.10'];
        // Above every gate point: the floor alone, no extra point.
        yield 'above every gate point' => ['constraint' => '>8.6'];
        yield 'hyphen range of one minor' => ['constraint' => '8.1 - 8.1'];
        yield 'hyphen range up to a patch gate' => ['constraint' => '8.2 - 8.4.25'];
    }

    /**
     * The points of a few named ranges, written out, so that the
     * comparison with require.php above is not one of two empty ranges.
     *
     * @param array{phpVersion?: string|int|array{min: string|int, max: string|int}} $config
     * @param list<int>                                                              $expected PHP_VERSION_IDs, floor first
     */
    #[Test]
    #[DataProvider('provideNamedRanges')]
    public function test_a_named_range_holds_the_floor_then_each_gate_point_it_allows(?string $flag, array $config, array $expected): void
    {
        $range = ProjectTarget::resolve($flag, null, $config, $this->makeProject(), [])->range();

        $this->assertSame($expected, array_map(static fn (PcreTarget $target): int => $target->phpVersionId, $range));
        foreach ($range as $target) {
            $this->assertSame(self::judgedPcre($target->phpVersionId), $target->pcreVersion, (string) $target->phpVersionId);
        }
    }

    /**
     * @return iterable<string, array{flag: string|null, config: array{phpVersion?: string|int|array{min: string|int, max: string|int}}, expected: list<int>}>
     */
    public static function provideNamedRanges(): iterable
    {
        yield 'regex.json, open-ended' => ['flag' => null, 'config' => ['phpVersion' => '>=8.1'], 'expected' => [80100, 80200, 80300, 80400, 80425, 80500, 80510]];
        yield 'regex.json, both bounds' => ['flag' => null, 'config' => ['phpVersion' => '^8.1 <8.4'], 'expected' => [80100, 80200, 80300]];
        yield 'regex.json, from the last gate point' => ['flag' => null, 'config' => ['phpVersion' => '>=8.5.10'], 'expected' => [80510]];
        // An excluded gate point is not judged: the next release stands
        // for its stretch.
        yield 'regex.json, a gate point excluded' => ['flag' => null, 'config' => ['phpVersion' => '>=8.4 !=8.4.25'], 'expected' => [80400, 80426, 80500, 80510]];
        yield 'regex.json, above every gate point' => ['flag' => null, 'config' => ['phpVersion' => '>8.6'], 'expected' => [80601]];
        yield 'regex.json, object of strings' => ['flag' => null, 'config' => ['phpVersion' => ['min' => '8.1', 'max' => '8.3']], 'expected' => [80100, 80200, 80300]];
        yield '--php-version, open-ended' => ['flag' => '>=8.3', 'config' => [], 'expected' => [80300, 80400, 80425, 80500, 80510]];
        yield '--php-version over a single version in regex.json' => ['flag' => '>=8.4', 'config' => ['phpVersion' => '8.1'], 'expected' => [80400, 80425, 80500, 80510]];
        // An OR branch that starts a stretch no point stands for keeps its
        // own point, as the CLI guide says, whatever the bound on the points.
        yield 'regex.json, an OR branch starting between gates' => ['flag' => null, 'config' => ['phpVersion' => '~8.3.0 || >=8.5.3'], 'expected' => [80300, 80503, 80510]];
        yield '--php-version, an OR branch starting between gates' => ['flag' => '~8.3.0 || >=8.5.3', 'config' => [], 'expected' => [80300, 80503, 80510]];
        yield '--php-version, OR of carets' => ['flag' => '^7.3 || ^8.0', 'config' => [], 'expected' => [70300, 70400, 80000, 80100, 80200, 80300, 80400, 80425, 80500, 80510]];
    }

    /**
     * A {min, max} object: max is inclusive; a PHP_VERSION_ID max is that
     * release, "8.6" as a string the whole 8.6 series, like the upper
     * version of a hyphen range, "8.4.24" that patch.
     *
     * @param array{min: string|int, max: string|int} $object
     * @param list<int>                               $expected PHP_VERSION_IDs, floor first
     */
    #[Test]
    #[DataProvider('provideMinMaxObjects')]
    public function test_a_min_max_object_is_judged_as_its_constraint(array $object, string $constraint, array $expected, string $admitted): void
    {
        $directory = $this->makeProject();

        $fromObject = ProjectTarget::resolve(null, null, ['phpVersion' => $object], $directory, []);
        $fromConstraint = ProjectTarget::resolve(null, null, ['phpVersion' => $constraint], $directory, []);

        $this->assertSame($expected, array_map(static fn (PcreTarget $target): int => $target->phpVersionId, $fromObject->range()));
        $this->assertSame($admitted, self::admitted($fromObject));
        $this->assertSame('regex.json', $fromObject->source());
        $this->assertSame($fromConstraint->toArray(), $fromObject->toArray());
        $this->assertSame(self::admitted($fromConstraint), self::admitted($fromObject));
        $this->assertSame($fromConstraint->notices(), $fromObject->notices());
    }

    /**
     * @return iterable<string, array{object: array{min: string|int, max: string|int}, constraint: string, expected: list<int>, admitted: string}>
     */
    public static function provideMinMaxObjects(): iterable
    {
        yield 'strings, a two-part max covers its series' => ['object' => ['min' => '8.1', 'max' => '8.6'], 'constraint' => '8.1 - 8.6', 'expected' => [80100, 80200, 80300, 80400, 80425, 80500, 80510], 'admitted' => '>=8.1 <8.7'];
        yield 'PHP_VERSION_IDs, max is that release' => ['object' => ['min' => 80100, 'max' => 80600], 'constraint' => '>=8.1 <=8.6', 'expected' => [80100, 80200, 80300, 80400, 80425, 80500, 80510], 'admitted' => '>=8.1 <8.6.1'];
        yield 'PHP_VERSION_IDs, the end of a series as its last patch' => ['object' => ['min' => 80100, 'max' => 80699], 'constraint' => '>=8.1 <=8.6.99', 'expected' => [80100, 80200, 80300, 80400, 80425, 80500, 80510], 'admitted' => '>=8.1 <8.7'];
        yield 'a two-part string max keeps the patch gate of its series' => ['object' => ['min' => '8.1', 'max' => '8.4'], 'constraint' => '8.1 - 8.4', 'expected' => [80100, 80200, 80300, 80400, 80425], 'admitted' => '>=8.1 <8.5'];
        yield 'a PHP_VERSION_ID max stops before the patch gate' => ['object' => ['min' => 80100, 'max' => 80400], 'constraint' => '>=8.1 <=8.4', 'expected' => [80100, 80200, 80300, 80400], 'admitted' => '>=8.1 <8.4.1'];
        yield 'a three-part string max is that patch' => ['object' => ['min' => '8.1.4', 'max' => '8.4.24'], 'constraint' => '8.1.4 - 8.4.24', 'expected' => [80104, 80200, 80300, 80400], 'admitted' => '>=8.1.4 <8.4.25'];
        yield 'a max on a patch gate' => ['object' => ['min' => '8.2', 'max' => '8.4.25'], 'constraint' => '8.2 - 8.4.25', 'expected' => [80200, 80300, 80400, 80425], 'admitted' => '>=8.2 <8.4.26'];
        yield 'mixed bounds' => ['object' => ['min' => '8.1', 'max' => 80600], 'constraint' => '>=8.1 <=8.6', 'expected' => [80100, 80200, 80300, 80400, 80425, 80500, 80510], 'admitted' => '>=8.1 <8.6.1'];
        yield 'one series' => ['object' => ['min' => '8.1', 'max' => '8.1'], 'constraint' => '8.1 - 8.1', 'expected' => [80100], 'admitted' => '>=8.1 <8.2'];
        yield 'one release' => ['object' => ['min' => 80510, 'max' => 80510], 'constraint' => '>=8.5.10 <=8.5.10', 'expected' => [80510], 'admitted' => '>=8.5.10 <8.5.11'];
        yield 'written max first' => ['object' => ['max' => '8.3', 'min' => '8.2'], 'constraint' => '8.2 - 8.3', 'expected' => [80200, 80300], 'admitted' => '>=8.2 <8.4'];
    }

    /**
     * A single version stays exact wherever it is named: "8.5" is not its
     * 8.5.10 gate point, and no notice says a wider require.php is hidden.
     *
     * @param array{phpVersion?: string|int} $config
     */
    #[Test]
    #[DataProvider('provideSingleVersions')]
    public function test_a_single_version_stays_exact(?string $flag, array $config, int $expected): void
    {
        $directory = $this->makeProject(['composer.json' => self::composer('>=8.1')]);

        $resolved = ProjectTarget::resolve($flag, null, $config, $directory, []);

        $this->assertSame([$expected], array_map(static fn (PcreTarget $target): int => $target->phpVersionId, $resolved->range()));
        $this->assertSame([], $resolved->notices());
    }

    /**
     * @return iterable<string, array{flag: string|null, config: array{phpVersion?: string|int}, expected: int}>
     */
    public static function provideSingleVersions(): iterable
    {
        yield 'regex.json, a major.minor' => ['flag' => null, 'config' => ['phpVersion' => '8.5'], 'expected' => 80500];
        yield 'regex.json, a PHP_VERSION_ID' => ['flag' => null, 'config' => ['phpVersion' => 80100], 'expected' => 80100];
        yield 'regex.json, a patch' => ['flag' => null, 'config' => ['phpVersion' => '8.4.1'], 'expected' => 80401];
        yield '--php-version, a major.minor' => ['flag' => '8.4', 'config' => [], 'expected' => 80400];
        yield '--php-version over a range in regex.json' => ['flag' => '8.2', 'config' => ['phpVersion' => '>=8.1'], 'expected' => 80200];
    }

    /**
     * @param array{min: string|int, max: string|int} $object
     */
    #[Test]
    #[DataProvider('provideMinAboveMax')]
    public function test_min_above_max_is_refused(array $object): void
    {
        $this->expectException(InvalidRegexOptionException::class);

        ProjectTarget::resolve(null, null, ['phpVersion' => $object], $this->makeProject(), []);
    }

    /**
     * @return iterable<string, array{object: array{min: string|int, max: string|int}}>
     */
    public static function provideMinAboveMax(): iterable
    {
        yield 'strings' => ['object' => ['min' => '8.6', 'max' => '8.1']];
        yield 'PHP_VERSION_IDs' => ['object' => ['min' => 80600, 'max' => 80100]];
        // "8.4" covers 8.4.25 only as a string max; 80400 is 8.4.0.
        yield 'a patch above a PHP_VERSION_ID' => ['object' => ['min' => '8.4.25', 'max' => 80400]];
    }

    /**
     * A constraint no PHP satisfies names no range, as min above max does:
     * refused wherever it is named, never judged at the floor it would
     * have. require.php keeps its own reading.
     */
    #[Test]
    #[DataProvider('provideConstraintsNoPhpSatisfies')]
    public function test_a_named_constraint_no_php_satisfies_is_refused(string $source, string $constraint): void
    {
        $this->expectException(InvalidRegexOptionException::class);

        ProjectTarget::fromSources([$source => $constraint], [], null, []);
    }

    /**
     * @return iterable<string, array{source: string, constraint: string}>
     */
    public static function provideConstraintsNoPhpSatisfies(): iterable
    {
        foreach (['--php-version', 'regex.json'] as $source) {
            yield $source.', a hyphen range written backwards' => ['source' => $source, 'constraint' => '8.6 - 8.1'];
            yield $source.', an upper bound below the lowest version' => ['source' => $source, 'constraint' => '>=8.4 <8.2'];
            yield $source.', strictly above the one version allowed' => ['source' => $source, 'constraint' => '>8.4.24 <=8.4.24'];
            yield $source.', the one version allowed excluded' => ['source' => $source, 'constraint' => '>=8.4.25 <8.4.26 !=8.4.25'];
            yield $source.', a wildcard after an operator' => ['source' => $source, 'constraint' => '>=8.1.*'];
            yield $source.', an upper bound below the lowest version, comma-separated' => ['source' => $source, 'constraint' => '>=8.1,<8.0'];
            yield $source.', two exact versions ANDed' => ['source' => $source, 'constraint' => '8.1 8.2'];
            yield $source.', every OR branch empty' => ['source' => $source, 'constraint' => '>=8.4 <8.2 || 8.6 - 8.1'];
        }
    }

    /**
     * A version past PHP 9.99 is refused inside a constraint as it is alone
     * ("past PHP 9.99"), wherever the constraint is named: never judged as
     * a PHP_VERSION_ID the library has no rule for, never a TypeError when
     * its major is past every integer.
     */
    #[Test]
    #[DataProvider('provideConstraintsPastTheLatestPhp')]
    public function test_a_named_constraint_past_the_latest_php_is_refused(string $source, string $constraint): void
    {
        $this->expectException(InvalidRegexOptionException::class);

        ProjectTarget::fromSources([$source => $constraint], [], null, []);
    }

    /**
     * @return iterable<string, array{source: string, constraint: string}>
     */
    public static function provideConstraintsPastTheLatestPhp(): iterable
    {
        foreach (['--php-version', 'regex.json', 'initializationOptions'] as $source) {
            yield $source.', from PHP 10' => ['source' => $source, 'constraint' => '>=10.0'];
            yield $source.', from PHP 99.1' => ['source' => $source, 'constraint' => '>=99.1'];
            yield $source.', from a major past every integer' => ['source' => $source, 'constraint' => '>=99999999999999999999.1'];
            yield $source.', an OR branch from PHP 10' => ['source' => $source, 'constraint' => '^8.1 || >=10.0'];
            yield $source.', a hyphen range up to PHP 10' => ['source' => $source, 'constraint' => '8.1 - 10.0'];
        }
    }

    /**
     * A constraint whose versions stay at or below the cap is read: the
     * refusal above starts past ParserOptions::LATEST_PHP_VERSION_ID,
     * PHP 9.99.99.
     */
    #[Test]
    public function test_a_named_constraint_up_to_the_latest_php_is_read(): void
    {
        $latest = ParserOptions::LATEST_PHP_VERSION_ID;
        $upTo = \sprintf('>=8.5 <=%d.%d.%d', intdiv($latest, 10000), intdiv($latest, 100) % 100, $latest % 100);

        $target = ProjectTarget::fromSources(['--php-version' => $upTo], [], null, []);

        $this->assertSame(80500, $target->target()->phpVersionId);
    }

    /**
     * An OR branch adds its lowest version only where no point already
     * judged stands for the same stretch between two gate points: a
     * thousand exact releases of one minor add no thousand points, however
     * the constraint is named. The bound is one point per gate, one per
     * stretch between gates, and the floor.
     *
     * @param array{phpVersion?: string} $config
     */
    #[Test]
    #[DataProvider('provideManyBranchesInOneStretch')]
    public function test_or_branches_in_one_stretch_add_no_point_each(?string $flag, array $config, ?string $requirePhp): void
    {
        $directory = null === $requirePhp ? $this->makeProject() : $this->makeProject(['composer.json' => self::composer($requirePhp)]);

        $range = ProjectTarget::resolve($flag, null, $config, $directory, [])->range();

        $this->assertSame(80100, $range[0]->phpVersionId);
        $this->assertLessThanOrEqual(2 * \count(PcreTarget::phpVersionBoundaries()) + 1, \count($range));
    }

    /**
     * A branch inside a stretch already judged still adds its lowest
     * version when that version is judged with another PCRE2: the running
     * PHP's minor gets the running engine, and the stretch past the last
     * gate holds several minors. Seen only where the running PCRE2 is not
     * the one its PHP bundles, for a minor past the last gate.
     */
    #[Test]
    public function test_a_branch_judged_with_another_pcre2_keeps_its_point(): void
    {
        $constraint = '8.4.* || 8.5.* || 8.6.* || 8.7.*';
        $range = ProjectTarget::resolve(null, null, [], $this->makeProject(['composer.json' => self::composer($constraint)]), [])->range();

        $gates = PcreTarget::phpVersionBoundaries();
        $stretch = static fn (int $version): int => \count(array_filter($gates, static fn (int $gate): bool => $gate <= $version));
        $expected = [];
        foreach ([80400, 80425, 80500, 80510, 80600, 80700] as $candidate) {
            $target = ParserOptions::resolveTarget($candidate, null, null, true);
            $expected[$stretch($candidate).'/'.$target->pcreVersion] ??= $candidate;
        }

        $this->assertSame(array_values($expected), array_map(static fn (PcreTarget $point): int => $point->phpVersionId, $range));
    }

    /**
     * @return iterable<string, array{flag: string|null, config: array{phpVersion?: string}, requirePhp: string|null}>
     */
    public static function provideManyBranchesInOneStretch(): iterable
    {
        // A thousand exact branches, all inside 8.1: a patch takes one or
        // two digits, so they cycle through the hundred releases 8.1.0 to
        // 8.1.99, a hundred points today.
        $branches = implode(' || ', array_map(static fn (int $i): string => '8.1.'.($i % 100), range(0, 999)));

        yield '--php-version' => ['flag' => $branches, 'config' => [], 'requirePhp' => null];
        yield 'regex.json' => ['flag' => null, 'config' => ['phpVersion' => $branches], 'requirePhp' => null];
        yield 'composer.json require.php' => ['flag' => null, 'config' => [], 'requirePhp' => $branches];
    }

    /**
     * A constraint that names no range is refused wherever it is named,
     * never passed over for composer.json or the running PHP. An object of
     * another shape never reaches the resolution: the schema refuses it in
     * regex.json (LintConfigSchemaTest), the server passes it over in
     * initializationOptions (WorkspaceSettingsTest).
     *
     * @param array{phpVersion?: string} $config
     */
    #[Test]
    #[DataProvider('provideUnreadableRanges')]
    public function test_a_range_that_names_nothing_is_refused(?string $flag, array $config): void
    {
        $directory = $this->makeProject(['composer.json' => self::composer('>=8.2')]);

        $this->expectException(InvalidRegexOptionException::class);

        ProjectTarget::resolve($flag, null, $config, $directory, []);
    }

    /**
     * @return iterable<string, array{flag: string|null, config: array{phpVersion?: string}}>
     */
    public static function provideUnreadableRanges(): iterable
    {
        // A doubled separator is no Composer constraint: every reader of a
        // named PHP version refuses it, the schema of regex.json first.
        foreach (['<8.4', '*', 'dev-main', '>=80100', 'runtime || ^9.0', '^8.1 || 80100', '>=8.1 <80700', '>=8.1,,<8.3'] as $constraint) {
            yield '--php-version '.$constraint => ['flag' => $constraint, 'config' => []];
            yield 'regex.json '.$constraint => ['flag' => null, 'config' => ['phpVersion' => $constraint]];
        }
    }

    /**
     * The versions the project installs on, as a guard or a marker narrows
     * them: ">=8.1 <8.7".
     */
    private static function admitted(ProjectTarget $target): string
    {
        return $target->judgedRange(RegexParser::create($target->regexOptions()))->describe();
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
