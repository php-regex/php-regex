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

use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A constant of several values has one value wherever it stands, and so
 * has what follows it: a constant that only names it, one that joins it
 * to texts, an implode() one of whose items it is. Standing beside the
 * constant they follow, or beside each other, they are one operand of
 * several values: a pattern for each value, and none the code never
 * builds.
 *
 * PHP 8.4, 64 bits: with WORD = PHP_INT_SIZE === 8 ? 'cat' : 'dog' and
 * ALIAS = self::WORD, '/^' . self::WORD . '|' . self::ALIAS . '$/' is
 * "/^cat|cat$/", and "/^dog|dog$/" where PHP_INT_SIZE is not 8, never
 * "/^cat|dog$/".
 *
 * Every case runs through both strategies.
 */
final class SameConstantUnderAnotherNameTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{argument: string, patterns: list<string>}>
     */
    public static function provideCompositionsFollowingOneConstant(): iterable
    {
        yield 'beside a constant that names it' => [
            'argument' => "'/^' . self::WORD . '|' . self::ALIAS . '$/'",
            'patterns' => ['/^cat|cat$/', '/^dog|dog$/'],
        ];

        yield 'after a constant that names it' => [
            'argument' => "'/^' . self::ALIAS . '|' . self::WORD . '$/'",
            'patterns' => ['/^cat|cat$/', '/^dog|dog$/'],
        ];

        yield 'beside a constant that joins it to texts' => [
            'argument' => "'/^' . self::GROUP . self::WORD . '$/'",
            'patterns' => ['/^(cat)cat$/', '/^(dog)dog$/'],
        ];

        yield 'a constant that names it beside one that joins it to texts' => [
            'argument' => "'/^' . self::ALIAS . self::GROUP . '$/'",
            'patterns' => ['/^cat(cat)$/', '/^dog(dog)$/'],
        ];

        yield 'in two implode() side by side' => [
            'argument' => "'/^' . implode('|', [self::WORD, 'bird']) . '-' . implode('|', [self::WORD, 'fish']) . '$/'",
            'patterns' => ['/^cat|bird-cat|fish$/', '/^dog|bird-dog|fish$/'],
        ];
    }

    /**
     * @param list<string> $patterns
     */
    #[Test]
    #[DataProvider('provideCompositionsFollowingOneConstant')]
    public function test_what_follows_one_constant_has_its_value_in_each_pattern(string $argument, array $patterns): void
    {
        $code = <<<'PHP_WRAP'
            <?php

            final class Matcher
            {
                private const WORD = \PHP_INT_SIZE === 8 ? 'cat' : 'dog';
                private const ALIAS = self::WORD;
                private const GROUP = '(' . self::WORD . ')';

                public function run(string $s): int
                {
                    return preg_match(ARGUMENT, $s);
                }
            }
            PHP_WRAP;
        $code = str_replace('ARGUMENT', $argument, $code);

        $expected = [];
        foreach ($patterns as $pattern) {
            $expected[] = ['pattern' => $pattern, 'at' => $argument.', $s)', 'source' => 'preg_match()', 'location' => null];
        }

        $this->assertExtractedByBoth($expected, $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => $argument, 'at' => $argument.', $s)', 'source' => 'preg_match()'],
        ], $code);
    }
}
