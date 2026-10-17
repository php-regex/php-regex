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

namespace PHPRegex\Tests\Unit\Lint\Extraction;

use PHPRegex\Linter\Extraction\VersionGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The versions a guard holds on and those it fails on, each never fewer
 * than PHP runs the code on. version_compare() as PHP compares: every
 * release of 8.4 compares above "8.4", and a release candidate of 8.4.0,
 * whose PHP_VERSION_ID is 80400, below "8.4.0".
 */
final class VersionGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{operator: string, constantFirst: bool, holds: string, fails: string}>
     */
    public static function provideComparisons(): iterable
    {
        yield '>=' => ['operator' => '>=', 'constantFirst' => true, 'holds' => '>=8.4', 'fails' => '<8.4'];
        yield '>' => ['operator' => '>', 'constantFirst' => true, 'holds' => '>=8.4.1', 'fails' => '<8.4.1'];
        yield '<' => ['operator' => '<', 'constantFirst' => true, 'holds' => '<8.4', 'fails' => '>=8.4'];
        yield '<=' => ['operator' => '<=', 'constantFirst' => true, 'holds' => '<8.4.1', 'fails' => '>=8.4.1'];
        yield '===' => ['operator' => '===', 'constantFirst' => true, 'holds' => '>=8.4 <8.4.1', 'fails' => '<8.4 || >=8.4.1'];
        yield '==' => ['operator' => '==', 'constantFirst' => true, 'holds' => '>=8.4 <8.4.1', 'fails' => '<8.4 || >=8.4.1'];
        yield '!==' => ['operator' => '!==', 'constantFirst' => true, 'holds' => '<8.4 || >=8.4.1', 'fails' => '>=8.4 <8.4.1'];
        yield '!=' => ['operator' => '!=', 'constantFirst' => true, 'holds' => '<8.4 || >=8.4.1', 'fails' => '>=8.4 <8.4.1'];
        yield '<= the other way round' => ['operator' => '<=', 'constantFirst' => false, 'holds' => '>=8.4', 'fails' => '<8.4'];
        yield '< the other way round' => ['operator' => '<', 'constantFirst' => false, 'holds' => '>=8.4.1', 'fails' => '<8.4.1'];
        yield '> the other way round' => ['operator' => '>', 'constantFirst' => false, 'holds' => '<8.4', 'fails' => '>=8.4'];
        yield '>= the other way round' => ['operator' => '>=', 'constantFirst' => false, 'holds' => '<8.4.1', 'fails' => '>=8.4.1'];
    }

    #[Test]
    #[DataProvider('provideComparisons')]
    public function test_a_comparison_of_php_version_id(string $operator, bool $constantFirst, string $holds, string $fails): void
    {
        $guard = VersionGuard::versionId($operator, 80400, $constantFirst);

        $this->assertInstanceOf(VersionGuard::class, $guard);
        $this->assertSame($holds, $guard->holds->phpConstraint());
        $this->assertSame($fails, $guard->fails->phpConstraint());
    }

    #[Test]
    public function test_an_operator_that_is_no_comparison_is_no_guard(): void
    {
        $this->assertNull(VersionGuard::versionId('<=>', 80400, true));
    }

    /**
     * @return iterable<string, array{version: string, operator: string, holds: string, fails: string}>
     */
    public static function provideVersionCompares(): iterable
    {
        yield 'a series, >=' => ['version' => '8.4', 'operator' => '>=', 'holds' => '>=8.4', 'fails' => '<8.4'];
        yield 'a series, gt' => ['version' => '8.4', 'operator' => 'gt', 'holds' => '>=8.4', 'fails' => '<8.4'];
        yield 'a series, <' => ['version' => '8.4', 'operator' => '<', 'holds' => '<8.4', 'fails' => '>=8.4'];
        yield 'a series, le' => ['version' => '8.4', 'operator' => 'le', 'holds' => '<8.4', 'fails' => '>=8.4'];
        yield 'a release, ge' => ['version' => '8.4.0', 'operator' => 'ge', 'holds' => '>=8.4', 'fails' => '<8.4.1'];
        yield 'a release, >' => ['version' => '8.4.0', 'operator' => '>', 'holds' => '>=8.4.1', 'fails' => '<8.4.1'];
        yield 'a release, lt' => ['version' => '8.4.0', 'operator' => 'lt', 'holds' => '<8.4.1', 'fails' => '>=8.4'];
        yield 'a release, <=' => ['version' => '8.4.0', 'operator' => '<=', 'holds' => '<8.4.1', 'fails' => '>=8.4.1'];
        yield 'a patch release, >=' => ['version' => '8.4.12', 'operator' => '>=', 'holds' => '>=8.4.12', 'fails' => '<8.4.13'];
    }

    #[Test]
    #[DataProvider('provideVersionCompares')]
    public function test_version_compare_of_php_version(string $version, string $operator, string $holds, string $fails): void
    {
        $guard = VersionGuard::versionCompare($version, $operator);

        $this->assertInstanceOf(VersionGuard::class, $guard);
        $this->assertSame($holds, $guard->holds->phpConstraint());
        $this->assertSame($fails, $guard->fails->phpConstraint());
    }

    /**
     * An equality, an operator version_compare() does not know, and a
     * version that is not one are not read.
     *
     * @return iterable<string, array{version: string, operator: string}>
     */
    public static function provideUnreadVersionCompares(): iterable
    {
        yield 'equal' => ['version' => '8.4', 'operator' => '=='];
        yield 'eq' => ['version' => '8.4.0', 'operator' => 'eq'];
        yield 'not equal' => ['version' => '8.4.0', 'operator' => '!='];
        yield 'ne' => ['version' => '8.4.0', 'operator' => 'ne'];
        yield 'unknown' => ['version' => '8.4', 'operator' => '>>'];
        yield 'a major alone' => ['version' => '8', 'operator' => '>='];
        yield 'a pre-release' => ['version' => '8.4.0RC1', 'operator' => '>='];
    }

    #[Test]
    #[DataProvider('provideUnreadVersionCompares')]
    public function test_version_compare_not_read(string $version, string $operator): void
    {
        $this->assertNull(VersionGuard::versionCompare($version, $operator));
    }

    #[Test]
    public function test_both_guards_hold_where_both_hold_and_fail_where_either_fails(): void
    {
        $from = VersionGuard::versionId('>=', 80300, true);
        $below = VersionGuard::versionId('<', 80500, true);
        $this->assertInstanceOf(VersionGuard::class, $from);
        $this->assertInstanceOf(VersionGuard::class, $below);

        $both = $from->and($below);

        $this->assertSame('>=8.3 <8.5', $both->holds->phpConstraint());
        $this->assertSame('<8.3 || >=8.5', $both->fails->phpConstraint());
    }

    #[Test]
    public function test_a_file_declaring_a_version_constant_or_version_compare_shadows_them(): void
    {
        $this->assertTrue(VersionGuard::shadowedIn("<?php\nnamespace A;\nconst PHP_VERSION_ID = 1;\n"));
        $this->assertTrue(VersionGuard::shadowedIn("<?php\nnamespace A;\nconst PHP_VERSION = '1';\n"));
        $this->assertTrue(VersionGuard::shadowedIn("<?php\nnamespace A;\nfunction version_compare() {}\n"));
        $this->assertTrue(VersionGuard::shadowedIn("<?php\ndefine('A\\\\PHP_VERSION_ID', 1);\n"));
        $this->assertFalse(VersionGuard::shadowedIn("<?php\nnamespace A;\nif (PHP_VERSION_ID >= 80400) {}\n"));
    }
}
