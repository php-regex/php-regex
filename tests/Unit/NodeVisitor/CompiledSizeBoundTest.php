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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without a "{", no item of a pattern is repeated by a count, and the
 * smallest size PCRE could compile it to grows with its length: at most 33
 * units per byte, a class of two members being the most, 33 units for its
 * four bytes. A pattern that short and that plain is below the 64 KiB PCRE
 * compiles without being measured; a counted one is always measured.
 */
final class CompiledSizeBoundTest extends TestCase
{
    private const UNITS_PER_BYTE = 33;

    /**
     * Pieces that cost the most units for their bytes, every kind of item
     * the measure counts, none with a "{".
     */
    private const PIECES = [
        '[ab]', '[]a]', '[a-z0-9]', '[\pL\pN]', '[\d\w]', '[[:alpha:]x]', '[^ab]', '[\x{100}a]',
        'a', 'é', '\.', '\Qab\E', '|', '(a)', '(?:a)', '(?<n>a)', '(?=a)', '(?<!a)', '(?>a)', '(?|a|b)',
        '(a)?', '(?:ab)*', '(?:ab)+?', '\1', '(?1)', '(?R)', '\d', '\w', '\pL', '.', '\x41', '\101',
        '(?C)', '(?C1)', '(?C"ab")', '(*MARK:ab)', '(*:x)', '(*ACCEPT)?', '[[:<:]]', '[[:>:]]', '^', '$', '\b', '\K',
        '(?i)', '(?i:a)', '(?#c)', '(?(1)a|b)', '(*pla:a)',
    ];

    #[Test]
    #[DataProvider('provideModifiers')]
    public function test_a_pattern_without_a_count_costs_at_most_33_units_per_byte(string $modifiers): void
    {
        mt_srand(20261019);
        $parser = RegexParser::create(['cache' => null]);
        $floor = new \ReflectionMethod(Validator::class, 'compiledSizeFloor');
        $measured = 0;

        for ($i = 0; $i < 3000; $i++) {
            $body = '(a)';
            for ($j = mt_rand(1, 30); $j > 0; $j--) {
                $body .= self::PIECES[mt_rand(0, \count(self::PIECES) - 1)];
            }

            try {
                $ast = $parser->parse('/'.$body.'/'.$modifiers);
                $validator = new Validator(255, $body, PcreTarget::runtime());
                $ast->accept($validator);
            } catch (ExceptionInterface) {
                continue;
            }

            $units = $floor->invoke($validator, $ast->pattern);
            $this->assertIsInt($units);
            $this->assertLessThanOrEqual(self::UNITS_PER_BYTE * \strlen($body), $units, $body);
            $measured++;
        }

        $this->assertGreaterThan(1000, $measured);
    }

    /**
     * @return iterable<string, array{modifiers: string}>
     */
    public static function provideModifiers(): iterable
    {
        yield 'none' => ['modifiers' => ''];
        yield 'u' => ['modifiers' => 'u'];
        yield 'iu' => ['modifiers' => 'iu'];
    }

    #[Test]
    public function test_a_long_pattern_without_a_count_is_still_measured(): void
    {
        $pattern = '/'.str_repeat('[ab]', 8000).'/';

        $this->assertFalse(@preg_match($pattern, ''), 'PCRE compiles 8 000 classes to more than 64 KiB.');
        $this->assertSame(ErrorCode::PatternTooLarge, RegexParser::create(['cache' => null])->validate($pattern)->errorCode);
    }

    /**
     * The first count the library refuses, and the one before it it
     * accepts: PCRE refuses that first one too, the measure being a floor.
     */
    #[Test]
    #[DataProvider('provideCountedGroups')]
    public function test_a_counted_group_is_measured_at_the_limit(string $group): void
    {
        $parser = RegexParser::create(['cache' => null]);
        $refused = static fn (int $count): bool => ErrorCode::PatternTooLarge === $parser->validate('/'.$group.'{'.$count.'}/')->errorCode;

        [$low, $high] = [1, 65535];
        $this->assertTrue($refused($high));
        while ($low + 1 < $high) {
            $middle = intdiv($low + $high, 2);
            if ($refused($middle)) {
                $high = $middle;
            } else {
                $low = $middle;
            }
        }

        $this->assertTrue($refused($high));
        $this->assertFalse($refused($high - 1));
        $this->assertFalse(@preg_match('/'.$group.'{'.$high.'}/', ''), $group.'{'.$high.'} compiles.');
    }

    /**
     * @return iterable<string, array{group: string}>
     */
    public static function provideCountedGroups(): iterable
    {
        yield 'non-capturing' => ['group' => '(?:ab)'];
        yield 'capturing' => ['group' => '(a)'];
        yield 'class inside' => ['group' => '(?:[ab]c)'];
        yield 'alternation inside' => ['group' => '(?:a|bc)'];
    }
}
