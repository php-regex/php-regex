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

use PHPRegex\Linter\Config\ComposerConstraint;
use PHPRegex\Tests\Support\LinearTimeAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The versions a Composer constraint allows, as PHP_VERSION_ID intervals:
 * the lowest allowed, and the first above it no longer allowed.
 */
final class ComposerConstraintTest extends TestCase
{
    use LinearTimeAssertions;

    /**
     * @param list<array{int, int|null}>|null $intervals
     */
    #[Test]
    #[DataProvider('provideConstraints')]
    public function test_a_constraint_reads_as_intervals(string $constraint, ?array $intervals): void
    {
        $this->assertSame($intervals, ComposerConstraint::intervals($constraint));
    }

    /**
     * @return iterable<string, array{constraint: string, intervals: list<array{int, int|null}>|null}>
     */
    public static function provideConstraints(): iterable
    {
        yield 'caret' => ['constraint' => '^8.2', 'intervals' => [[80200, 90000]]];
        yield 'tilde of two parts' => ['constraint' => '~8.2', 'intervals' => [[80200, 90000]]];
        yield 'tilde of three parts' => ['constraint' => '~8.2.3', 'intervals' => [[80203, 80300]]];
        yield 'lower bound' => ['constraint' => '>=8.1', 'intervals' => [[80100, null]]];
        yield 'lower bound after a space' => ['constraint' => '>= 8.1', 'intervals' => [[80100, null]]];
        yield 'both bounds' => ['constraint' => '>=8.1 <8.4', 'intervals' => [[80100, 80400]]];
        yield 'both bounds with a comma' => ['constraint' => '>=8.1,<=8.3', 'intervals' => [[80100, 80301]]];
        yield 'or' => ['constraint' => '^7.4 || ^8.0', 'intervals' => [[70400, 80000], [80000, 90000]]];
        yield 'single pipe' => ['constraint' => '^7.4|^8.0', 'intervals' => [[70400, 80000], [80000, 90000]]];
        yield 'wildcard' => ['constraint' => '8.2.*', 'intervals' => [[80200, 80300]]];
        yield 'wildcard before a patch' => ['constraint' => '8.*.3', 'intervals' => [[80003, 90000]]];
        yield 'bare version' => ['constraint' => '8.2.4', 'intervals' => [[80204, 80205]]];
        yield 'hyphen range, partial upper' => ['constraint' => '8.2 - 8.4', 'intervals' => [[80200, 80500]]];
        yield 'hyphen range, full upper' => ['constraint' => '8.2 - 8.4.25', 'intervals' => [[80200, 80426]]];
        yield 'with a v' => ['constraint' => '^v8.2', 'intervals' => [[80200, 90000]]];
        // Strictly above: the next release, as composer/semver's lower
        // bound, so a floor of ">8.5.9" is no version the range excludes.
        yield 'strictly above' => ['constraint' => '>8.1', 'intervals' => [[80101, null]]];
        yield 'strictly above a patch' => ['constraint' => '>8.5.9 <=8.5.10', 'intervals' => [[80510, 80511]]];
        yield 'not equal is no bound' => ['constraint' => '>=8.1 !=8.2.0', 'intervals' => [[80100, null]]];
        yield 'no lower bound' => ['constraint' => '<8.4', 'intervals' => null];
        yield 'a branch name' => ['constraint' => 'dev-main', 'intervals' => null];
        yield 'any version' => ['constraint' => '*', 'intervals' => null];
        yield 'empty' => ['constraint' => '', 'intervals' => null];
    }

    /**
     * A constraint named where the library takes a PHP version, regex.json
     * or --php-version, holds x.y[.z] versions only: a PHP_VERSION_ID or
     * "runtime" inside it names nothing, where require.php's reading takes
     * "80100" as a major.
     */
    #[Test]
    #[DataProvider('provideTokensAStrictConstraintRefuses')]
    public function test_a_strict_constraint_refuses_a_token_that_is_no_version(string $constraint): void
    {
        $this->assertNull(ComposerConstraint::intervals($constraint, strict: true));
    }

    /**
     * @return iterable<string, array{constraint: string}>
     */
    public static function provideTokensAStrictConstraintRefuses(): iterable
    {
        yield 'a PHP_VERSION_ID as the lower bound' => ['constraint' => '>=80100'];
        yield 'a PHP_VERSION_ID as the upper bound' => ['constraint' => '>=8.1 <80700'];
        yield 'a PHP_VERSION_ID in one branch' => ['constraint' => '^8.1 || 80100'];
        yield 'a PHP_VERSION_ID as a hyphen bound' => ['constraint' => '8.1 - 80600'];
        yield 'runtime in a branch' => ['constraint' => 'runtime || ^9.0'];
        yield 'runtime alone' => ['constraint' => 'runtime'];
        yield 'no lower bound' => ['constraint' => '<8.4'];
        yield 'any version' => ['constraint' => '*'];
        yield 'a branch name' => ['constraint' => 'dev-main'];

        yield 'a line break between two bounds' => ['constraint' => ">=8.1\n<8.4"];
        yield 'a form feed between two bounds' => ['constraint' => ">=8.1\f<8.4"];
        yield 'a line break in a hyphen range' => ['constraint' => "8.1\n- 8.4"];
        yield 'a space inside an operator' => ['constraint' => '> =8.1'];

        // composer/semver refuses a wildcard after an operator or in a
        // hyphen range: ">=8.1.*" is no constraint.
        yield 'a wildcard after a lower bound' => ['constraint' => '>=8.1.*'];
        yield 'a wildcard after a caret' => ['constraint' => '^8.1.*'];
        yield 'a wildcard after a tilde' => ['constraint' => '~8.1.*'];
        yield 'a wildcard after an upper bound' => ['constraint' => '>=8.1 <8.4.*'];
        yield 'a wildcard after an equals sign' => ['constraint' => '=8.1.*'];
        yield 'a wildcard as the lower end of a hyphen range' => ['constraint' => '8.1.* - 8.3'];
        yield 'a wildcard as the upper end of a hyphen range' => ['constraint' => '8.1 - 8.3.*'];
        yield 'strictly above the one version allowed' => ['constraint' => '>8.4.24 <=8.4.24'];
        yield 'the one version allowed excluded' => ['constraint' => '>=8.4.25 <8.4.26 !=8.4.25'];
    }

    /**
     * @param list<array{int, int|null}> $intervals
     */
    #[Test]
    #[DataProvider('provideStrictConstraints')]
    public function test_a_strict_constraint_reads_its_versions_as_require_php_does(string $constraint, array $intervals): void
    {
        $this->assertSame($intervals, ComposerConstraint::intervals($constraint, strict: true));
        $this->assertSame(ComposerConstraint::intervals($constraint), ComposerConstraint::intervals($constraint, strict: true));
    }

    /**
     * @return iterable<string, array{constraint: string, intervals: list<array{int, int|null}>}>
     */
    public static function provideStrictConstraints(): iterable
    {
        yield 'lower bound' => ['constraint' => '>=8.1', 'intervals' => [[80100, null]]];
        yield 'caret with an upper bound' => ['constraint' => '^8.1 <8.7', 'intervals' => [[80100, 80700]]];
        yield 'hyphen range' => ['constraint' => '8.1 - 8.6', 'intervals' => [[80100, 80700]]];
        yield 'OR of carets' => ['constraint' => '^7.3 || ^8.0', 'intervals' => [[70300, 80000], [80000, 90000]]];
        yield 'a patch' => ['constraint' => '>=8.5.10', 'intervals' => [[80510, null]]];
        yield 'inclusive upper bound' => ['constraint' => '>=8.1 <=8.6', 'intervals' => [[80100, 80601]]];
        yield 'a space after an operator' => ['constraint' => '>= 8.1', 'intervals' => [[80100, null]]];
        yield 'a tab between two bounds' => ['constraint' => ">=8.1\t<8.4", 'intervals' => [[80100, 80400]]];
        yield 'a tab in a hyphen range' => ['constraint' => "8.1\t-\t8.4", 'intervals' => [[80100, 80500]]];
        yield 'a bare wildcard' => ['constraint' => '8.1.*', 'intervals' => [[80100, 80200]]];
        yield 'strictly above a patch' => ['constraint' => '>8.5.9 <=8.5.10', 'intervals' => [[80510, 80511]]];
    }

    /**
     * Where a PHP version is named, "!=" leaves its version out, as
     * composer/semver does: "!=8.2" excludes 8.2.0, and 8.2.1 is allowed.
     * require.php's reading keeps it as no bound.
     *
     * @param list<array{int, int|null}> $intervals
     */
    #[Test]
    #[DataProvider('provideExclusions')]
    public function test_a_strict_constraint_leaves_out_the_versions_it_excludes(string $constraint, array $intervals): void
    {
        $this->assertSame($intervals, ComposerConstraint::intervals($constraint, strict: true));
    }

    /**
     * @return iterable<string, array{constraint: string, intervals: list<array{int, int|null}>}>
     */
    public static function provideExclusions(): iterable
    {
        yield 'a minor excluded from an open range' => ['constraint' => '>=8.1 !=8.2', 'intervals' => [[80100, 80200], [80201, null]]];
        yield 'the upper version of a range excluded' => ['constraint' => '>=8.4.24 <8.4.26 !=8.4.25', 'intervals' => [[80424, 80425]]];
        yield 'the lowest version excluded' => ['constraint' => '>=8.1 <8.3 !=8.1', 'intervals' => [[80101, 80300]]];
        yield 'two versions excluded' => ['constraint' => '>=8.1 <8.3 !=8.1 !=8.2.0', 'intervals' => [[80101, 80200], [80201, 80300]]];
        yield 'a version outside the range excluded' => ['constraint' => '>=8.1 !=8.0', 'intervals' => [[80100, null]]];
        yield 'exclusions out of order and repeated' => ['constraint' => '>=8.1 <8.3 !=8.2.0 !=8.1 !=8.2.0', 'intervals' => [[80101, 80200], [80201, 80300]]];
        yield 'every version of a range excluded but the last' => ['constraint' => '>=8.1 <8.1.3 !=8.1.1 !=8.1.0', 'intervals' => [[80102, 80103]]];
        yield 'an exclusion in one branch' => ['constraint' => '^7.4 || >=8.1 !=8.2', 'intervals' => [[70400, 80000], [80100, 80200], [80201, null]]];
    }

    /**
     * A range named with thousands of exclusions is read in one pass over
     * them, not once per piece they leave.
     */
    #[Test]
    public function test_exclusions_are_read_in_linear_time(): void
    {
        $this->assertLinearTime(static function (int $size): void {
            $constraint = '>=8.0';
            for ($i = 0; $i < $size; $i++) {
                $constraint .= ' !=8.'.intdiv($i, 100).'.'.($i % 100);
            }
            ComposerConstraint::intervals($constraint, strict: true);
        }, 1000, 'exclusions');
    }

    #[Test]
    public function test_a_constraint_open_below_starts_at_the_first_version(): void
    {
        $this->assertSame([[0, 80400]], ComposerConstraint::intervals('<8.4', true));
        $this->assertNull(ComposerConstraint::intervals('dev-main', true));
    }

    #[Test]
    public function test_the_floor_is_the_lowest_version_allowed(): void
    {
        $this->assertSame(70400, ComposerConstraint::floor('^8.0 || ^7.4'));
        $this->assertNull(ComposerConstraint::floor('<8.4'));
    }

    #[Test]
    public function test_the_lowest_version_from_a_point(): void
    {
        $intervals = [[70100, 70300], [80000, 90000]];

        $this->assertSame(80000, ComposerConstraint::lowestFrom($intervals, 70300));
        $this->assertSame(70200, ComposerConstraint::lowestFrom($intervals, 70200));
        $this->assertNull(ComposerConstraint::lowestFrom($intervals, 90000));
        $this->assertSame(70100, ComposerConstraint::lowestFrom($intervals, 0));
    }
}
