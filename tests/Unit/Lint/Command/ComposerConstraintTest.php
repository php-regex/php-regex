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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The versions a Composer constraint allows, as PHP_VERSION_ID intervals:
 * the lowest allowed, and the first above it no longer allowed.
 */
final class ComposerConstraintTest extends TestCase
{
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
        yield 'not equal is no bound' => ['constraint' => '>=8.1 !=8.2.0', 'intervals' => [[80100, null]]];
        yield 'no lower bound' => ['constraint' => '<8.4', 'intervals' => null];
        yield 'a branch name' => ['constraint' => 'dev-main', 'intervals' => null];
        yield 'any version' => ['constraint' => '*', 'intervals' => null];
        yield 'empty' => ['constraint' => '', 'intervals' => null];
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
