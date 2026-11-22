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

    /**
     * A set's group names and its mark, read from its keys in the order PHP
     * writes them: a name just before its group's number, the mark after
     * the last group. Beside a group named MARK, PHP 8.0 to 8.4 and 8.6
     * write the key once, the mark in the group's place; PHP 8.5 writes it
     * twice, the group's pair first, the mark after the last group. Either
     * way the name stays the group's and the mark reads on its own.
     *
     * @param array<string, list<array{int|string, mixed}>> $shapes
     * @param array<int, string>                            $names
     */
    #[Test]
    #[DataProvider('provideSetKeys')]
    public function test_a_set_names_its_groups_and_holds_its_mark_however_php_writes_the_key(string $regex, string $subject, array $shapes, array $names, ?string $mark): void
    {
        $this->assertSame(1, preg_match_all($regex, $subject, $sets, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL));
        $written = [];
        foreach ($sets[0] as $key => $value) {
            $written[] = [$key, $value];
        }
        $this->assertContains($written, $shapes, 'The engine here writes one of the shapes PHP is known to write.');
        $this->assertSame(['names' => $names, 'mark' => $mark], phpregex_playground_set_keys($sets[0]));

        // A generator repeats a key as PHP 8.5 does: every shape reads the
        // same, the one this engine does not write included.
        foreach ($shapes as $label => $shape) {
            $this->assertSame(['names' => $names, 'mark' => $mark], phpregex_playground_set_keys(self::keyed($shape)), $label);
        }
    }

    /**
     * @return iterable<string, array{regex: string, subject: string, shapes: array<string, list<array{int|string, mixed}>>, names: array<int, string>, mark: ?string}>
     */
    public static function provideSetKeys(): iterable
    {
        yield 'a mark after a numbered group' => [
            'regex' => '/(a)(*MARK:z)b/',
            'subject' => 'ab',
            'shapes' => ['every PHP' => [[0, ['ab', 0]], [1, ['a', 0]], ['MARK', 'z']]],
            'names' => [],
            'mark' => 'z',
        ];
        yield 'a group named MARK without a mark' => [
            'regex' => '/(?<MARK>a)b/',
            'subject' => 'ab',
            'shapes' => ['every PHP' => [[0, ['ab', 0]], ['MARK', ['a', 0]], [1, ['a', 0]]]],
            'names' => ['1' => 'MARK'],
            'mark' => null,
        ];
        yield 'a mark beside a group named MARK' => [
            'regex' => '/(?<MARK>a)(*MARK:z)b/',
            'subject' => 'ab',
            'shapes' => [
                'PHP 8.0 to 8.4, 8.6' => [[0, ['ab', 0]], ['MARK', 'z'], [1, ['a', 0]]],
                'PHP 8.5' => [[0, ['ab', 0]], ['MARK', ['a', 0]], [1, ['a', 0]], ['MARK', 'z']],
            ],
            'names' => ['1' => 'MARK'],
            'mark' => 'z',
        ];
        yield 'a mark before a group named MARK' => [
            'regex' => '/(?<x>a)(*MARK:z)(?<MARK>b)/',
            'subject' => 'ab',
            'shapes' => [
                'PHP 8.0 to 8.4, 8.6' => [[0, ['ab', 0]], ['x', ['a', 0]], [1, ['a', 0]], ['MARK', 'z'], [2, ['b', 1]]],
                'PHP 8.5' => [[0, ['ab', 0]], ['x', ['a', 0]], [1, ['a', 0]], ['MARK', ['b', 1]], [2, ['b', 1]], ['MARK', 'z']],
            ],
            'names' => ['1' => 'x', '2' => 'MARK'],
            'mark' => 'z',
        ];
    }

    /**
     * @param list<array{int|string, mixed}> $shape
     *
     * @return \Generator<int|string, mixed>
     */
    private static function keyed(array $shape): \Generator
    {
        foreach ($shape as [$key, $value]) {
            yield $key => $value;
        }
    }
}
