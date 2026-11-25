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

namespace PHPRegex\Tests\Unit\Lint\Rule\Support;

use PHPRegex\Linter\Rule\Support\EndOfLineTail;
use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Analysis\GroupIndex as PcreGroups;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where the items after "\Z" stop when they read the newline that ends the
 * subject, "\r\n" under (*CRLF), "\r\n", "\r" or "\n" under (*ANYCRLF):
 * before the end of the subject, inside the newline, or at the end.
 */
final class FinalNewlineStopsTest extends TestCase
{
    #[Test]
    #[DataProvider('provideTails')]
    public function test_a_tail_stops_in_the_final_newline_where_the_engine_does(string $convention, string $before, string $tail, string $flags, bool $inside, bool $atTheEnd): void
    {
        // Oracle, PHP 8.4.28 / PCRE2 10.49, the interpreter as well: whether
        // the tail may stop before the end of a subject the newline ends,
        // and at its end.
        $engine = [false, false];
        foreach ('CRLF' === $convention ? ["\r\n"] : ["\r\n", "\r", "\n"] as $newline) {
            foreach (['', '(*NO_JIT)'] as $option) {
                $prefix = '/'.$option.'(*'.$convention.')'.$before.'\Z(?:'.$tail.')';
                $engine[0] = $engine[0] || 1 === preg_match($prefix.'(?!\z)/'.$flags, $before.$newline);
                $engine[1] = $engine[1] || 1 === preg_match($prefix.'\z/'.$flags, $before.$newline);
            }
        }
        $this->assertSame([$inside, $atTheEnd], $engine, 'The engine');

        $sequence = Regex::create(['cache' => null])->parse('/(*'.$convention.')'.$before.'\Z'.$tail.'/'.$flags)->pattern;
        $this->assertInstanceOf(SequenceNode::class, $sequence);
        $anchor = 0;
        while (!$sequence->children[$anchor] instanceof AssertionNode) {
            $anchor++;
        }
        $nodes = \array_slice($sequence->children, $anchor + 1);

        $reader = new EndOfLineTail(new CharSetAnalyzer(), 'CRLF' === $convention ? "\r" : "\r\n", PcreGroups::empty(), before: '' === $before ? null : ByteCharSet::fromChar($before));

        $this->assertSame([$inside, $atTheEnd], $reader->finalNewlineStops($nodes, $convention, str_contains($flags, 's')));
    }

    /**
     * @return iterable<string, array{convention: string, before: string, tail: string, flags: string, inside: bool, atTheEnd: bool}>
     */
    public static function provideTails(): iterable
    {
        $crlf = static fn (string $tail, bool $inside, bool $atTheEnd, string $flags = '', string $before = 'a'): array => ['convention' => 'CRLF', 'before' => $before, 'tail' => $tail, 'flags' => $flags, 'inside' => $inside, 'atTheEnd' => $atTheEnd];
        $anyCrlf = static fn (string $tail, bool $inside, bool $atTheEnd, string $flags = ''): array => ['convention' => 'ANYCRLF', 'before' => 'a', 'tail' => $tail, 'flags' => $flags, 'inside' => $inside, 'atTheEnd' => $atTheEnd];

        // An accept ends the match wherever the items before it stop.
        yield 'an accept after the carriage return' => $crlf('\r(*ACCEPT)x', true, true);
        // Option settings, and the places each item may start at.
        yield 'an option setting before what fails' => $crlf('(?s)\r\nb', false, false);
        yield 'an optional carriage return, then one' => $crlf('\r?\r', true, false);
        yield 'a carriage return, then an optional line feed' => $crlf('\r\n?', true, true);
        // Dots and one-character atoms.
        yield 'a dot after the newline' => $crlf('\r\n.', false, false);
        yield 'a dot over the line feed' => $crlf('\r.\z', false, true);
        yield 'a dot under s over the line feed' => $crlf('\r.\z', false, true, 's');
        yield 'a dot under (*ANYCRLF)' => $anyCrlf('.', false, false);
        yield 'a digit' => $crlf('\d', false, false);
        yield 'two spaces, then the end' => $crlf('\s\s\z', false, true);
        yield 'a letter property after the newline' => $crlf('\r\n\pL', false, false);
        // "\R" reads the newline whole or its first character.
        yield 'a line break, then the end' => $crlf('\R\z', false, true);
        yield 'a line break after the carriage return' => $crlf('\r\R\z', false, true);
        yield 'a line break after the newline' => $crlf('\r\n\R', false, false);
        yield 'a carriage return after a line break' => $crlf('\R\r', false, false);
        // Anchors and assertions inside the newline.
        yield 'a dollar before the newline' => $crlf('$\r\n', false, true);
        yield 'a dollar inside the newline' => $crlf('\r$\n', false, false);
        yield 'a dollar before a line feed under (*ANYCRLF)' => $anyCrlf('$\n', false, true);
        yield 'a line start right after the anchor under m' => $anyCrlf('^\r', false, false, 'm');
        yield 'small z after the newline' => $crlf('\r\n\z', false, true);
        yield 'small z inside the newline' => $crlf('\r\z\n', false, false);
        yield 'capital Z before the newline' => $crlf('\Z\r\n', false, true);
        yield 'capital Z inside the newline' => $crlf('\r\Z\n', false, false);
        yield 'a subject start where nothing comes before' => $crlf('\A\r\n', false, true, before: '');
        yield 'a non-boundary inside the newline' => $crlf('\r\B\n', false, true);
        // Repeats.
        yield 'two newline characters, then the end' => $crlf('[\r\n]{2}\z', false, true);
        yield 'two newline characters, then a line feed' => $crlf('[\r\n]{2}\n', false, false);
        yield 'one newline character, then the end' => $crlf('[\r\n]{1}\z', false, false);
        yield 'a run of optional carriage returns, then a line feed' => $crlf('(?:\r|)*\n', false, true);
        // Groups, alternatives, conditionals.
        yield 'either of two letters' => $crlf('(?:x|y)', false, false);
        yield 'either the newline or a letter' => $crlf('(?:\r\n|x)', false, true);
        yield 'the carriage return or the newline, then the end' => $crlf('(?:\r|\r\n)\z', false, true);
        yield 'a group of two letters' => $crlf('(?:xy)', false, false);
        yield 'a conditional between two letters' => $crlf('(?(?=\r)x|y)', false, false);
        // What no table here reads: anywhere on.
        yield 'a grapheme cluster, then the end' => $crlf('\X\z', false, true);
        yield 'a match start reset, then the end' => $crlf('\r\K\z', false, false);
    }
}
