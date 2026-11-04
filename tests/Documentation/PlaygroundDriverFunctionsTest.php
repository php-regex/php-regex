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

namespace PHPRegex\Tests\Documentation;

use PHPRegex\Tests\Support\PlaygroundDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The driver's own functions, called in this process: the ones whose
 * answer the other tests can only reach through an input the library may
 * never produce.
 */
#[Group('documentation')]
final class PlaygroundDriverFunctionsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once PlaygroundDriver::ROOT.'/docs/assets/playground/driver.php';
    }

    /**
     * The attack string the ReDoS tab offers as a subject: text, and no
     * longer than a subject can be, else withheld rather than cut.
     */
    #[Test]
    #[DataProvider('provideAttacks')]
    public function test_an_attack_is_text_within_a_subjects_length_or_withheld(string $attack, ?string $expected): void
    {
        $this->assertSame($expected, phpregex_playground_attack($attack));
    }

    /**
     * @return iterable<string, array{attack: string, expected: ?string}>
     */
    public static function provideAttacks(): iterable
    {
        yield 'text' => ['attack' => str_repeat('a', 16).'!', 'expected' => str_repeat('a', 16).'!'];
        yield 'text at the cap' => ['attack' => str_repeat('a', 10000), 'expected' => str_repeat('a', 10000)];
        yield 'past the cap' => ['attack' => str_repeat('a', 10001), 'expected' => null];
        yield 'not text' => ['attack' => "\xC3", 'expected' => null];
    }
}
