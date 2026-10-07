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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE2 skips the spaces before the "^" of a negated property name:
 * "\P{ ^L}" negates twice and is "\p{L}".
 */
final class PropertyNegationSpacingTest extends TestCase
{
    #[Test]
    #[DataProvider('provideSpacedNegations')]
    public function test_a_space_before_the_caret_keeps_the_negation(string $pattern, string $subject, int $matches): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49.
        $this->assertSame($matches, preg_match($pattern, $subject), $pattern);

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertTrue($result->isValid, $pattern.': '.$result->error);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, matches: int}>
     */
    public static function provideSpacedNegations(): iterable
    {
        yield 'double negation of Any' => ['pattern' => '/^\P{ ^any}$/u', 'subject' => 'a', 'matches' => 1];
        yield 'double negation of a letter' => ['pattern' => '/^\P{ ^L}$/u', 'subject' => 'a', 'matches' => 1];
        yield 'double negation, not a letter' => ['pattern' => '/^\P{ ^L}$/u', 'subject' => '1', 'matches' => 0];
        yield 'single negation' => ['pattern' => '/^\p{ ^L}$/u', 'subject' => '1', 'matches' => 1];
    }
}
