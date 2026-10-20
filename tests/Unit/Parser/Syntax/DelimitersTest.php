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

namespace PHPRegex\Tests\Unit\Parser\Syntax;

use PHPRegex\Parser\Syntax\Delimiters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The delimiter that closes a pattern: the other bracket of a bracket
 * pair, the same character otherwise.
 *
 * Oracle (PHP 8.4.26): "(a)", "[a]", "{a}" and "<a>" compile, as "#a#" does.
 */
final class DelimitersTest extends TestCase
{
    #[Test]
    #[DataProvider('provideDelimiters')]
    public function test_the_closing_delimiter_pairs_brackets(string $opening, string $closing): void
    {
        $this->assertSame($closing, Delimiters::closing($opening));
        $this->assertSame(1, @preg_match($opening.'a'.$closing, 'a'), 'Oracle: PHP reads the pair.');
    }

    /**
     * @return iterable<string, array{opening: string, closing: string}>
     */
    public static function provideDelimiters(): iterable
    {
        yield 'parentheses' => ['opening' => '(', 'closing' => ')'];
        yield 'square brackets' => ['opening' => '[', 'closing' => ']'];
        yield 'braces' => ['opening' => '{', 'closing' => '}'];
        yield 'angle brackets' => ['opening' => '<', 'closing' => '>'];
        yield 'a hash' => ['opening' => '#', 'closing' => '#'];
        yield 'a slash' => ['opening' => '/', 'closing' => '/'];
    }
}
