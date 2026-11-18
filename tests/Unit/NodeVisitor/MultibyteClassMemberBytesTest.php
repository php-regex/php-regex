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

use PHPRegex\Linter\Rule\Support\CharClassSets;
use PHPRegex\Linter\Rule\Support\CodePoints;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without UTF mode PCRE reads a class byte by byte: "[à-é]" is 0xC3, the
 * range 0xA0 to 0xC3, then 0xA9, and matches neither "à" nor "é" whole.
 * The tree holds those bytes, so what reads its members, the printer, the
 * explanation, the lint rules and the sample generator, sees the bytes PCRE
 * matches. Each byte set below is read from preg_match() on PHP 8.4.27 with
 * PCRE2 10.49, one byte at a time.
 */
final class MultibyteClassMemberBytesTest extends TestCase
{
    #[Test]
    #[DataProvider('provideClasses')]
    #[DataProvider('provideQuotedClasses')]
    public function test_the_members_of_the_tree_are_the_bytes_pcre_reads(string $class): void
    {
        $expected = self::bytesMatched('/^'.$class.'$/');

        $ast = Regex::create(['cache' => null])->parse('/'.$class.'/');
        $this->assertInstanceOf(CharClassNode::class, $ast->pattern);

        $members = [];
        foreach (CharClassSets::collectParts($ast->pattern->expression) as $part) {
            [$start, $end] = $part instanceof RangeNode
                ? [CodePoints::fromNode($part->start, false), CodePoints::fromNode($part->end, false)]
                : [CodePoints::fromNode($part, false), CodePoints::fromNode($part, false)];
            $this->assertNotNull($start, $class);
            $this->assertNotNull($end, $class);
            $members = [...$members, ...range($start, $end)];
        }

        $members = array_values(array_unique($members));
        if ($ast->pattern->isNegated) {
            $members = array_values(array_diff(range(0, 255), $members));
        }
        sort($members);

        $this->assertSame($expected, $members, $class);
    }

    #[Test]
    #[DataProvider('provideClasses')]
    public function test_the_printer_writes_the_class_back(string $class): void
    {
        $pattern = '/'.$class.'/';

        $this->assertSame($pattern, Regex::create(['cache' => null])->parse($pattern)->accept(PatternPrinter::asWritten()));
    }

    /**
     * Normalized, the class spells each byte "\xHH", and matches the bytes
     * the class matches: "[\xC3\xA0-\xC3\xA9]" is "[à-é]" without UTF.
     */
    #[Test]
    #[DataProvider('provideClasses')]
    #[DataProvider('provideQuotedClasses')]
    public function test_the_normalized_class_matches_the_same_bytes(string $class): void
    {
        $normalized = Regex::create(['cache' => null])->parse('/'.$class.'/')->accept(PatternPrinter::normalized());

        $this->assertSame(self::bytesMatched('/^'.$class.'$/'), self::bytesMatched('/^'.substr($normalized, 1, -1).'$/'), $normalized);
    }

    /**
     * The sample is one byte the class matches, whole: "$" would also take
     * a byte followed by a newline, which "\z" does not.
     */
    #[Test]
    #[DataProvider('provideClasses')]
    #[DataProvider('provideQuotedClasses')]
    public function test_a_sample_of_the_class_is_a_byte_it_matches(string $class): void
    {
        $pattern = '/\A'.$class.'\z/';
        $sample = Regex::create(['cache' => null])->generate($pattern);

        $this->assertSame(1, \strlen($sample), bin2hex($sample));
        $this->assertSame(1, preg_match($pattern, $sample), bin2hex($sample));
        $this->assertSame(1, preg_match($pattern, Regex::create(['cache' => null])->generate('/^'.$class.'$/')), $class.' between "^" and "$"');
    }

    /**
     * @return iterable<string, array{class: string}>
     */
    public static function provideClasses(): iterable
    {
        yield 'a lone member' => ['class' => '[é]'];
        yield 'a lone member of three bytes' => ['class' => '[€]'];
        yield 'a lone member of four bytes' => ['class' => '[😀]'];
        yield 'a negated member' => ['class' => '[^é]'];
        yield 'a range between two multibyte characters' => ['class' => '[à-é]'];
        yield 'a range between two multibyte characters, the later first' => ['class' => '[é-à]'];
        yield 'a range from a character to itself' => ['class' => '[é-é]'];
        yield 'a range between characters of another first byte' => ['class' => '[ÿ-é]'];
        yield 'a range to a multibyte character' => ['class' => '[a-é]'];
        yield 'a range from a multibyte character to a byte' => ['class' => '[é-\xff]'];
        yield 'a negated range' => ['class' => '[^à-é]'];
        yield 'a range from an escaped multibyte character' => ['class' => '[\é-ê]'];
        yield 'a range of four-byte characters' => ['class' => '[😀-😁]'];
        yield 'a multibyte range end, then a range from its last byte' => ['class' => '[a-é-ê]'];
    }

    /**
     * Classes holding quotes, which the printer does not write back.
     *
     * @return iterable<string, array{class: string}>
     */
    public static function provideQuotedClasses(): iterable
    {
        yield 'a multibyte range start past a quote' => ['class' => '[é\E-à]'];
        yield 'quoted members' => ['class' => '[\Qà-é\E]'];
    }

    /**
     * Every byte read from "[à-é]", none of the characters.
     */
    #[Test]
    public function test_the_explanation_names_the_bytes(): void
    {
        $this->assertSame(range(0xA0, 0xC3), self::bytesMatched('/^[à-é]$/'));
        $this->assertSame(0, preg_match('/^[à-é]$/', 'é'));

        $explanation = Regex::create(['cache' => null])->explain('/[à-é]/');

        $this->assertStringContainsString("'\\xC3', Range: from '\\xA0' to '\\xC3', '\\xA9'", $explanation);
    }

    /**
     * @return list<int>
     */
    private static function bytesMatched(string $pattern): array
    {
        $bytes = [];
        for ($byte = 0; $byte < 256; $byte++) {
            if (1 === preg_match($pattern, \chr($byte))) {
                $bytes[] = $byte;
            }
        }

        return $bytes;
    }
}
