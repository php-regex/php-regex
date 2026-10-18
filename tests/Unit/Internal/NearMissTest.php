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

namespace PHPRegex\Tests\Unit\Internal;

use PHPRegex\Parser\Text\NearMiss;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The name a near miss most likely meant, for the "Did you mean" of a
 * mistyped command, configuration key or group name.
 */
final class NearMissTest extends TestCase
{
    /**
     * @param list<string> $candidates
     */
    #[Test]
    #[DataProvider('provideClosest')]
    public function test_the_closest_candidate_is_suggested(string $name, array $candidates, ?string $expected): void
    {
        $this->assertSame($expected, NearMiss::closest($name, $candidates));
    }

    /**
     * @return iterable<string, array{name: string, candidates: list<string>, expected: string|null}>
     */
    public static function provideClosest(): iterable
    {
        $commands = ['analyze', 'lint', 'parse', 'explain', 'graph', 'debug'];

        yield 'a swapped letter' => ['name' => 'anlyze', 'candidates' => $commands, 'expected' => 'analyze'];
        yield 'a missing letter' => ['name' => 'lnt', 'candidates' => $commands, 'expected' => 'lint'];
        yield 'two edits' => ['name' => 'explian', 'candidates' => $commands, 'expected' => 'explain'];
        yield 'too far' => ['name' => 'compile', 'candidates' => $commands, 'expected' => null];
        yield 'the nearest of two' => ['name' => 'pas', 'candidates' => ['parse', 'pass'], 'expected' => 'pass'];
        yield 'another case' => ['name' => 'phpversion', 'candidates' => ['phpVersion', 'paths'], 'expected' => 'phpVersion'];
        yield 'snake_case for camelCase, past two edits' => ['name' => 'min_savings_bytes', 'candidates' => ['minSavingsBytes'], 'expected' => 'minSavingsBytes'];
        yield 'the name itself' => ['name' => 'lint', 'candidates' => ['lint'], 'expected' => 'lint'];
        yield 'nothing to choose from' => ['name' => 'lint', 'candidates' => [], 'expected' => null];
    }

    #[Test]
    public function test_every_candidate_within_two_edits_is_listed_in_order(): void
    {
        $this->assertSame(['year', 'yeah'], NearMiss::within('yer', ['year', 'month', 'yeah']));
        $this->assertSame([], NearMiss::within('day', ['year', 'month']));
    }
}
