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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\RangeWording;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Validation\RangeVerdict;
use PHPRegex\Parser\Validation\TargetVerdict;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RangeWordingTest extends TestCase
{
    /**
     * @param list<array{int, bool}> $later each later version and whether it accepts the pattern
     */
    #[Test]
    #[DataProvider('provideRefusals')]
    public function test_refused_on_names_the_versions_that_refuse(array $later, string $expected): void
    {
        $this->assertSame($expected, RangeWording::refusedOn(self::verdict($later)));
    }

    /**
     * @return iterable<string, array{later: list<array{int, bool}>, expected: string}>
     */
    public static function provideRefusals(): iterable
    {
        yield 'every later version refuses' => ['later' => [[80400, false], [80500, false]], 'expected' => 'PHP 8.4 and later'];
        yield 'from a version on' => ['later' => [[80400, true], [80500, false], [80510, false]], 'expected' => 'PHP 8.5 and later'];
        yield 'a stretch that ends' => ['later' => [[80400, false], [80425, true]], 'expected' => 'PHP 8.4 and later, before 8.4.25'];
        yield 'two stretches' => ['later' => [[80400, false], [80425, true], [80500, false]], 'expected' => 'PHP 8.4 and later, before 8.4.25; PHP 8.5 and later'];
    }

    #[Test]
    public function test_reads_otherwise_names_the_version_and_its_pcre2(): void
    {
        $this->assertSame(
            'From PHP 8.4 (PCRE2 10.44) the pattern parses differently: the same text means something else there.',
            RangeWording::readsOtherwise(new PcreTarget(80400, '10.44', 'C')),
        );
    }

    #[Test]
    public function test_silent_change_names_the_version_then_the_change(): void
    {
        $this->assertSame(
            'From PHP 8.0 (PCRE2 10.35) a group that refers to itself repeats.',
            RangeWording::silentChange(new PcreTarget(80000, '10.35', 'C'), 'a group that refers to itself repeats.'),
        );
    }

    /**
     * @param list<array{int, bool}> $later
     */
    private static function verdict(array $later): RangeVerdict
    {
        return new RangeVerdict(
            new TargetVerdict(new PcreTarget(80100, '10.39', 'C'), new ValidationResult(true)),
            array_map(static fn (array $point): TargetVerdict => new TargetVerdict(new PcreTarget($point[0], '10.44', 'C'), new ValidationResult($point[1], $point[1] ? null : 'refused')), $later),
            null,
            [],
        );
    }
}
