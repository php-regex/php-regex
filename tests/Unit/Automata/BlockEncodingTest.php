<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Automata;

use PhpRegex\Automata\Transform\AstToNfaTransformer;
use PhpRegex\Automata\Unicode\CodePointHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Scanning Unicode encodes a block of code points at a time.
 *
 * The fast way asks mbstring to convert the whole block; the other encodes
 * it one code point at a time, the way the rest of the package does without
 * the extension. They must agree, byte for byte, across every width change
 * in UTF-8 and around the surrogates, which are not characters.
 */
final class BlockEncodingTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRanges')]
    public function test_both_ways_of_encoding_a_block_agree(int $from, int $to): void
    {
        $encodeBlock = new \ReflectionMethod(AstToNfaTransformer::class, 'encodeBlock');
        $byHand = new \ReflectionMethod(AstToNfaTransformer::class, 'encodeCodePoints');

        $block = $encodeBlock->invoke(null, $from, $to);
        $this->assertIsArray($block);
        $this->assertIsString($block[0]);
        $this->assertIsArray($block[1]);

        $text = $block[0];
        $offsets = $block[1];

        $codePoints = [];
        for ($codePoint = $from; $codePoint <= $to; $codePoint++) {
            if ($codePoint >= 0xD800 && $codePoint <= 0xDFFF) {
                continue;
            }

            $codePoints[] = $codePoint;
        }

        $this->assertSame($byHand->invoke(null, $codePoints), $text);

        // The offsets must land on the code points they name.
        foreach ($offsets as $offset => $codePoint) {
            $this->assertIsInt($offset);
            $this->assertIsInt($codePoint);

            $char = CodePointHelper::toString($codePoint);
            $this->assertIsString($char);
            $this->assertSame($char, substr($text, $offset, \strlen($char)));
        }
    }

    /**
     * @return iterable<string, array{from: int, to: int}>
     */
    public static function provideRanges(): iterable
    {
        yield 'ascii' => ['from' => 0x41, 'to' => 0x46];
        yield 'one byte to two' => ['from' => 0x7E, 'to' => 0x82];
        yield 'two bytes to three' => ['from' => 0x7FE, 'to' => 0x802];
        yield 'around the surrogates' => ['from' => 0xD7FE, 'to' => 0xE002];
        yield 'three bytes to four' => ['from' => 0xFFFE, 'to' => 0x10002];
        yield 'the last code points' => ['from' => 0x10FFFD, 'to' => 0x10FFFF];
    }
}
