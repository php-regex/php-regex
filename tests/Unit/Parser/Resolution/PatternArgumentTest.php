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

namespace PHPRegex\Tests\Unit\Parser\Resolution;

use PHPRegex\Parser\Resolution\ArgumentState;
use PHPRegex\Parser\Resolution\Hole;
use PHPRegex\Parser\Resolution\PatternArgument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What can be said of a pattern argument before any text is known: it is one
 * pattern, a finite set of patterns, a static pattern around preg_quote()
 * texts, a static pattern around another dynamic part, or nothing static.
 *
 * An argument is a list of alternatives, each a list of parts: a string, or
 * a hole standing for a text nobody wrote. The first part carries the
 * delimiter, so an alternative that does not open with a string holding one
 * says nothing static.
 */
final class PatternArgumentTest extends TestCase
{
    #[Test]
    public function test_state_is_one_of_a_closed_set(): void
    {
        $this->assertSame(
            ['constant', 'finite_set', 'quoted_shell', 'raw_shell', 'opaque'],
            array_map(static fn (ArgumentState $state): string => $state->value, ArgumentState::cases()),
        );
    }

    #[Test]
    public function test_a_composition_stops_at_thirty_two_alternatives(): void
    {
        $this->assertSame(32, (new \ReflectionClassConstant(PatternArgument::class, 'MAX_CANDIDATES'))->getValue());
    }

    #[Test]
    #[DataProvider('provideHoles')]
    public function test_hole_keeps_whether_its_text_is_quoted_and_with_which_delimiter(bool $quoted, ?string $delimiter): void
    {
        $hole = new Hole(quoted: $quoted, delimiter: $delimiter);

        $this->assertSame($quoted, $hole->quoted);
        $this->assertSame($delimiter, $hole->delimiter);
    }

    /**
     * @return iterable<string, array{quoted: bool, delimiter: ?string}>
     */
    public static function provideHoles(): iterable
    {
        yield 'preg_quote() with its delimiter' => ['quoted' => true, 'delimiter' => '/'];
        yield 'preg_quote() without a delimiter' => ['quoted' => true, 'delimiter' => null];
        yield 'any other dynamic part' => ['quoted' => false, 'delimiter' => null];
    }

    /**
     * @param list<list<string|Hole>> $alternatives
     */
    #[Test]
    #[DataProvider('provideWholes')]
    public function test_state_of_whole_patterns_is_constant_or_a_finite_set(array $alternatives, string $expected): void
    {
        $this->assertSame($expected, PatternArgument::state($alternatives)->value);
    }

    /**
     * @return iterable<string, array{alternatives: list<list<string|Hole>>, expected: string}>
     */
    public static function provideWholes(): iterable
    {
        yield 'one pattern' => ['alternatives' => [['/^a$/']], 'expected' => 'constant'];
        yield 'two distinct patterns' => ['alternatives' => [['/^a$/'], ['/^b$/']], 'expected' => 'finite_set'];
        yield 'the same pattern twice' => ['alternatives' => [['/^a$/'], ['/^a$/']], 'expected' => 'constant'];
        yield 'the same pattern twice among two' => ['alternatives' => [['/^a$/'], ['/^b$/'], ['/^a$/']], 'expected' => 'finite_set'];
        // A whole is a string, whatever it holds: reading it is the linter's.
        yield 'an empty string' => ['alternatives' => [['']], 'expected' => 'constant'];
        yield 'a string starting with a space' => ['alternatives' => [[' /^a$/']], 'expected' => 'constant'];
        yield 'a multi-byte pattern' => ['alternatives' => [["/^\u{e9}$/u"]], 'expected' => 'constant'];
        // Strings side by side are one string, and an empty one is no part.
        yield 'two strings side by side are one pattern' => ['alternatives' => [['/a', 'b/'], ['/ab/']], 'expected' => 'constant'];
        yield 'an empty string among the parts' => ['alternatives' => [['', '/a/', ''], ['/a/']], 'expected' => 'constant'];
    }

    /**
     * @param list<list<string|Hole>> $alternatives
     */
    #[Test]
    #[DataProvider('provideCounts')]
    public function test_state_does_not_bound_the_number_of_alternatives(array $alternatives, string $expected): void
    {
        $this->assertSame($expected, PatternArgument::state($alternatives)->value);
    }

    /**
     * @return iterable<string, array{alternatives: list<list<string|Hole>>, expected: string}>
     */
    public static function provideCounts(): iterable
    {
        yield 'no alternative' => ['alternatives' => [], 'expected' => 'opaque'];
        yield 'one alternative' => ['alternatives' => self::wholes(1), 'expected' => 'constant'];
        yield 'two alternatives' => ['alternatives' => self::wholes(2), 'expected' => 'finite_set'];
        yield '32 distinct patterns' => ['alternatives' => self::wholes(32), 'expected' => 'finite_set'];
        yield '33 distinct patterns' => ['alternatives' => self::wholes(33), 'expected' => 'finite_set'];
        yield '4000 distinct patterns' => ['alternatives' => self::wholes(4000), 'expected' => 'finite_set'];
        yield '33 patterns, 32 of them distinct' => ['alternatives' => [...self::wholes(32), ['/^a1$/']], 'expected' => 'finite_set'];
        yield '33 times the same pattern' => ['alternatives' => array_fill(0, 33, ['/^a$/']), 'expected' => 'constant'];
        yield '32 distinct quoted shells' => ['alternatives' => self::quotedShells(32), 'expected' => 'quoted_shell'];
        yield '33 distinct quoted shells' => ['alternatives' => self::quotedShells(33), 'expected' => 'quoted_shell'];
        yield '31 patterns and a quoted shell' => ['alternatives' => [...self::wholes(31), ...self::quotedShells(1)], 'expected' => 'quoted_shell'];
        yield '32 patterns and a quoted shell' => ['alternatives' => [...self::wholes(32), ...self::quotedShells(1)], 'expected' => 'quoted_shell'];
        yield '33 quoted shells, the first one twice' => ['alternatives' => [...self::quotedShells(32), ...self::quotedShells(1)], 'expected' => 'quoted_shell'];
        yield 'two holes with an empty string between them' => ['alternatives' => [['/z', new Hole(quoted: true, delimiter: null), '', new Hole(quoted: true, delimiter: null), '/']], 'expected' => 'quoted_shell'];
        yield 'an alternative with no part' => ['alternatives' => [[]], 'expected' => 'opaque'];
        yield 'a pattern and an alternative with no part' => ['alternatives' => [['/a/'], []], 'expected' => 'opaque'];
    }

    /**
     * @param list<list<string|Hole>> $alternatives
     */
    #[Test]
    #[DataProvider('provideShells')]
    public function test_state_of_a_pattern_around_a_hole_is_a_shell(array $alternatives, string $expected): void
    {
        $this->assertSame($expected, PatternArgument::state($alternatives)->value);
    }

    /**
     * @return iterable<string, array{alternatives: list<list<string|Hole>>, expected: string}>
     */
    public static function provideShells(): iterable
    {
        yield 'a quoted hole' => ['alternatives' => [['/^', self::quoted(), '$/']], 'expected' => 'quoted_shell'];
        yield 'a quoted hole without its delimiter' => ['alternatives' => [['/^', new Hole(quoted: true, delimiter: null), '$/']], 'expected' => 'quoted_shell'];
        yield 'two quoted holes' => ['alternatives' => [['/^', self::quoted(), '-', self::quoted(), '$/']], 'expected' => 'quoted_shell'];
        yield 'two quoted holes side by side' => ['alternatives' => [['/^', self::quoted(), self::quoted(), '$/']], 'expected' => 'quoted_shell'];
        yield 'a raw hole' => ['alternatives' => [['/^', self::raw(), '$/']], 'expected' => 'raw_shell'];
        yield 'a raw hole said to carry a delimiter' => ['alternatives' => [['/^', new Hole(quoted: false, delimiter: '/'), '$/']], 'expected' => 'raw_shell'];
        yield 'strings side by side before a hole' => ['alternatives' => [['', '/', '^', self::quoted(), '$/']], 'expected' => 'quoted_shell'];
        yield 'a quoted and a raw hole' => ['alternatives' => [['/^', self::quoted(), '-', self::raw(), '$/']], 'expected' => 'raw_shell'];
        yield 'a raw and a quoted hole' => ['alternatives' => [['/^', self::raw(), '-', self::quoted(), '$/']], 'expected' => 'raw_shell'];
        yield 'the delimiter alone before the hole' => ['alternatives' => [['/', self::quoted(), '/']], 'expected' => 'quoted_shell'];
        yield 'a multi-byte first part' => ['alternatives' => [["/\u{e9}", self::quoted(), '/u']], 'expected' => 'quoted_shell'];
        // The first part alone carries the delimiter: what closes the
        // argument does not change what it is.
        yield 'a quoted hole closing the argument' => ['alternatives' => [['/^', self::quoted()]], 'expected' => 'quoted_shell'];
        yield 'a raw hole closing the argument' => ['alternatives' => [['/^', self::raw()]], 'expected' => 'raw_shell'];
    }

    /**
     * @param list<list<string|Hole>> $alternatives
     */
    #[Test]
    #[DataProvider('provideUnknownDelimiters')]
    public function test_state_is_opaque_where_the_delimiter_is_not_known(array $alternatives): void
    {
        $this->assertSame('opaque', PatternArgument::state($alternatives)->value);
    }

    /**
     * @return iterable<string, array{alternatives: list<list<string|Hole>>}>
     */
    public static function provideUnknownDelimiters(): iterable
    {
        yield 'a quoted hole first' => ['alternatives' => [[self::quoted(), '$/']]];
        yield 'a raw hole first' => ['alternatives' => [[self::raw(), '$/']]];
        yield 'a quoted hole alone' => ['alternatives' => [[self::quoted()]]];
        yield 'a raw hole alone' => ['alternatives' => [[self::raw()]]];
        yield 'an empty first part' => ['alternatives' => [['', self::quoted(), '/a/']]];
        yield 'an empty first part before a raw hole' => ['alternatives' => [['', self::raw(), '/a/']]];
        yield 'a first part starting with a space' => ['alternatives' => [[' /a', self::quoted(), '/']]];
        yield 'a first part starting with a tab' => ['alternatives' => [["\t/a", self::quoted(), '/']]];
        yield 'a first part starting with a newline' => ['alternatives' => [["\n/a", self::quoted(), '/']]];
        yield 'a first part starting with a vertical tab' => ['alternatives' => [["\v/a", self::quoted(), '/']]];
        yield 'a first part starting with a form feed' => ['alternatives' => [["\f/a", self::quoted(), '/']]];
        yield 'a first part starting with a carriage return' => ['alternatives' => [["\r/a", self::quoted(), '/']]];
        yield 'a first part starting with a space before a raw hole' => ['alternatives' => [[' /a', self::raw(), '/']]];
    }

    /**
     * @param list<list<string|Hole>> $alternatives
     */
    #[Test]
    #[DataProvider('provideMixedAlternatives')]
    public function test_state_is_the_weakest_of_its_alternatives(array $alternatives, string $expected): void
    {
        $this->assertSame($expected, PatternArgument::state($alternatives)->value);
    }

    /**
     * @return iterable<string, array{alternatives: list<list<string|Hole>>, expected: string}>
     */
    public static function provideMixedAlternatives(): iterable
    {
        $whole = ['/^a$/'];
        $quotedShell = ['/^', self::quoted(), '$/'];
        $rawShell = ['/^b', self::raw(), '$/'];
        $nothingStatic = [self::raw(), '$/'];

        yield 'a pattern and a quoted shell' => ['alternatives' => [$whole, $quotedShell], 'expected' => 'quoted_shell'];
        yield 'a quoted shell and a pattern' => ['alternatives' => [$quotedShell, $whole], 'expected' => 'quoted_shell'];
        yield 'a pattern and a raw shell' => ['alternatives' => [$whole, $rawShell], 'expected' => 'raw_shell'];
        yield 'a quoted shell and a raw shell' => ['alternatives' => [$quotedShell, $rawShell], 'expected' => 'raw_shell'];
        yield 'a raw shell and a quoted shell' => ['alternatives' => [$rawShell, $quotedShell], 'expected' => 'raw_shell'];
        yield 'a pattern and nothing static' => ['alternatives' => [$whole, $nothingStatic], 'expected' => 'opaque'];
        yield 'nothing static and a pattern' => ['alternatives' => [$nothingStatic, $whole], 'expected' => 'opaque'];
        yield 'a quoted shell and nothing static' => ['alternatives' => [$quotedShell, $nothingStatic], 'expected' => 'opaque'];
        yield 'a raw shell and nothing static' => ['alternatives' => [$rawShell, $nothingStatic], 'expected' => 'opaque'];
        yield 'one of each' => ['alternatives' => [$whole, $quotedShell, $rawShell, $nothingStatic], 'expected' => 'opaque'];
        yield 'two patterns and a quoted shell' => ['alternatives' => [$whole, ['/^c$/'], $quotedShell], 'expected' => 'quoted_shell'];
    }

    #[Test]
    public function test_concat_follows_each_alternative_of_the_left_by_each_of_the_right(): void
    {
        $hole = new Hole(quoted: true, delimiter: '/');

        $this->assertSame([['/a', 'b/']], PatternArgument::concat([['/a']], [['b/']]));
        $this->assertSame(
            [['/a', 'x/'], ['/a', 'y/'], ['/b', 'x/'], ['/b', 'y/']],
            PatternArgument::concat([['/a'], ['/b']], [['x/'], ['y/']]),
        );
        $this->assertSame([['/^', $hole, '$/']], PatternArgument::concat([['/^', $hole]], [['$/']]));
        $this->assertSame([], PatternArgument::concat([], [['x/']]));
        $this->assertSame([], PatternArgument::concat([['/a']], []));
    }

    #[Test]
    #[DataProvider('provideProducts')]
    public function test_concat_is_not_built_past_the_cap(int $left, int $right, ?int $expected): void
    {
        $product = PatternArgument::concat(self::wholes($left), self::wholes($right));

        $this->assertSame($expected, null === $product ? null : \count($product));
    }

    /**
     * @return iterable<string, array{left: int, right: int, expected: ?int}>
     */
    public static function provideProducts(): iterable
    {
        yield '4 by 8' => ['left' => 4, 'right' => 8, 'expected' => 32];
        yield '32 by 1' => ['left' => 32, 'right' => 1, 'expected' => 32];
        yield '1 by 32' => ['left' => 1, 'right' => 32, 'expected' => 32];
        yield '3 by 11' => ['left' => 3, 'right' => 11, 'expected' => null];
        yield '33 by 1' => ['left' => 33, 'right' => 1, 'expected' => null];
        yield '1 by 33' => ['left' => 1, 'right' => 33, 'expected' => null];
        yield '4000 by 4000' => ['left' => 4000, 'right' => 4000, 'expected' => null];
    }

    /**
     * @return list<list<string>> as many distinct patterns, "/^a1$/" first
     */
    private static function wholes(int $count): array
    {
        $wholes = [];
        for ($index = 1; $index <= $count; $index++) {
            $wholes[] = ['/^a'.$index.'$/'];
        }

        return $wholes;
    }

    /**
     * @return list<list<string|Hole>> as many quoted shells, each with a first part of its own
     */
    private static function quotedShells(int $count): array
    {
        $shells = [];
        for ($index = 1; $index <= $count; $index++) {
            $shells[] = ['/^s'.$index.'-', self::quoted(), '$/'];
        }

        return $shells;
    }

    private static function quoted(): Hole
    {
        return new Hole(quoted: true, delimiter: '/');
    }

    private static function raw(): Hole
    {
        return new Hole(quoted: false, delimiter: null);
    }
}
