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

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE2 10.45 to 10.47 measure the lookbehinds of a pattern on a walk that
 * knows no operator of a Perl extended class: a pattern that holds a
 * lookbehind fails with "internal error: unknown meta code in
 * check_lookbehinds()" (error 170, at offset 0) on the first operator the
 * walk meets, one outside every lookbehind or inside a lookahead there. In
 * a lookbehind, an extended class that nests a class or a parenthesis is
 * read to its first inner end, and the lookbehind has no length then (error
 * 125). 10.48 compiles them all (ChangeLog: "Fix compilation of patterns
 * incorporating ECLASS and lookbehinds").
 *
 * Every row was compiled by pcre2test 10.45, 10.46, 10.47 and 10.48.
 */
final class ExtendedClassBesideLookbehindTest extends TestCase
{
    #[Test]
    #[DataProvider('provideOperatorsTheWalkMeets')]
    public function test_an_operator_the_lookbehind_walk_meets_fails_from_10_45_to_10_47(string $pattern): void
    {
        foreach (['10.45', '10.46', '10.47'] as $release) {
            $validation = self::parser($release)->validate($pattern);

            $this->assertFalse($validation->isValid, $pattern.' on PCRE2 '.$release);
            $this->assertSame(0, $validation->offset, $pattern.' on PCRE2 '.$release);
            $this->assertSame(ErrorCode::ExtendedClassBesideLookbehind, $validation->errorCode, $pattern.' on PCRE2 '.$release);
        }

        $this->assertTrue(self::parser('10.48')->validate($pattern)->isValid, $pattern.' on PCRE2 10.48');
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideOperatorsTheWalkMeets(): iterable
    {
        yield 'difference before a lookbehind' => ['pattern' => '/(?[\d-[1]]).(?<!x)/'];
        yield 'difference after a lookbehind' => ['pattern' => '/(?<!x)(?[\d-[1]])/'];
        yield 'intersection' => ['pattern' => '/(?[\d&[1]]).(?<!x)/'];
        yield 'negation' => ['pattern' => '/(?[!\d]).(?<!x)/'];
        yield 'union' => ['pattern' => '/(?[\d|[1]]).(?<!x)/'];
        yield 'union with a plus' => ['pattern' => '/(?[\d+[1]]).(?<!x)/'];
        yield 'symmetric difference' => ['pattern' => '/(?[\d^[1]]).(?<!x)/'];
        yield 'in parentheses' => ['pattern' => '/(?[(\d-[1])]).(?<!x)/'];
        yield 'in a lookahead' => ['pattern' => '/(?=(?[\d-[1]]))(?<!x)/'];
        yield 'in a lookahead in a lookbehind' => ['pattern' => '/(?<=(?=(?[\d-\w])))/'];
        yield 'in a lookahead in a branch of a lookbehind' => ['pattern' => '/(?<=a|(?=(?[\d-\w])))/'];
        yield 'in a group a lookbehind calls' => ['pattern' => '/(?<=(?1))((?[\d-\w]))/'];
        yield 'beside the lookbehind of a word end' => ['pattern' => '/[[:>:]](?[\d-\w])/'];
        yield 'in a conditional on a lookbehind' => ['pattern' => '/(?(?<=a)(?[\d-\w]))/'];
        yield 'in a substring scan in a lookbehind' => ['pattern' => '/(?<=(*scs:(1)(?[\d-\w])))(a)/'];
        yield 'before a lookbehind nesting a class' => ['pattern' => '/(?[\d-[1]])(?<=(?[[12]]))/'];
    }

    #[Test]
    #[DataProvider('provideNestedClassesInLookbehinds')]
    public function test_an_extended_class_nesting_a_class_leaves_a_lookbehind_unbounded_from_10_45_to_10_47(string $pattern, int $offset): void
    {
        foreach (['10.45', '10.46', '10.47'] as $release) {
            $validation = self::parser($release)->validate($pattern);

            $this->assertFalse($validation->isValid, $pattern.' on PCRE2 '.$release);
            $this->assertSame($offset, $validation->offset, $pattern.' on PCRE2 '.$release);
            $this->assertSame(ErrorCode::LookbehindUnbounded, $validation->errorCode, $pattern.' on PCRE2 '.$release);
        }

        $this->assertTrue(self::parser('10.48')->validate($pattern)->isValid, $pattern.' on PCRE2 10.48');
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideNestedClassesInLookbehinds(): iterable
    {
        yield 'difference with a class' => ['pattern' => '/(?<=(?[\d-[1]]))/', 'offset' => 0];
        yield 'second lookbehind' => ['pattern' => '/(?<=a)(?<=(?[\d-[1]]))/', 'offset' => 6];
        yield 'class alone' => ['pattern' => '/(?<=(?[[12]]))/', 'offset' => 0];
        yield 'negated class' => ['pattern' => '/(?<=(?[[^a]]))/', 'offset' => 0];
        yield 'after a character' => ['pattern' => '/(?<=a(?[[12]])b)/', 'offset' => 0];
        yield 'parentheses' => ['pattern' => '/(?<=(?[(\d)]))/', 'offset' => 0];
        yield 'operation in parentheses' => ['pattern' => '/(?<=(?[(\d-\w)]))/', 'offset' => 0];
        yield 'in a group a lookbehind calls' => ['pattern' => '/((?[[12]]))(?<=(?1))/', 'offset' => 11];
        yield 'before an operator outside' => ['pattern' => '/(?<=(?[[12]]))(?[\d-[1]])/', 'offset' => 0];
    }

    #[Test]
    #[DataProvider('provideCompiled')]
    public function test_what_the_walk_reads_compiles_on_every_release(string $pattern): void
    {
        foreach (['10.45', '10.46', '10.47', '10.48'] as $release) {
            $validation = self::parser($release)->validate($pattern);
            $this->assertTrue($validation->isValid, \sprintf('%s on PCRE2 %s: %s', $pattern, $release, $validation->error ?? ''));
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideCompiled(): iterable
    {
        yield 'no operator' => ['pattern' => '/(?[\d]).(?<!x)/'];
        yield 'nested class and no operator' => ['pattern' => '/(?[[\d]]).(?<!x)/'];
        yield 'operator in a lookbehind' => ['pattern' => '/(?<=(?[\d-\w]))/'];
        yield 'negation in a lookbehind' => ['pattern' => '/(?<=(?[!\d]))/'];
        yield 'intersection in a lookbehind' => ['pattern' => '/(?<!(?[\d&\w]))/'];
        yield 'operator in a lookbehind in a lookahead in a lookbehind' => ['pattern' => '/(?<=(?=(?<=(?[\d-\w]))))/'];
        yield 'beside the lookahead of a word start' => ['pattern' => '/[[:<:]](?[\d-\w])/'];
        yield 'operator and no lookbehind' => ['pattern' => '/(?[\d-[1]])a/'];
        yield 'POSIX class in a lookbehind' => ['pattern' => '/(?<=(?[[:alpha:]-\d]))/'];
        yield 'escaped bracket in a lookbehind' => ['pattern' => '/(?<=(?[\[]))/'];
        yield 'escaped parenthesis in a lookbehind' => ['pattern' => '/(?<=(?[\(]))/'];
        yield 'nested class after a lookbehind' => ['pattern' => '/(?<=a)(?[[\d]])/'];
    }

    /**
     * The walk meets the operator before the lookbehind after it, which
     * 10.48 refuses for having no bound.
     */
    #[Test]
    public function test_the_operator_fails_before_a_later_lookbehind_is_measured(): void
    {
        $old = self::parser('10.47')->validate('/(?<=a)(?[\d-[1]])(?<=b+)/');
        $this->assertSame(0, $old->offset);
        $this->assertSame(ErrorCode::ExtendedClassBesideLookbehind, $old->errorCode);

        $new = self::parser('10.48')->validate('/(?<=a)(?[\d-[1]])(?<=b+)/');
        $this->assertSame(17, $new->offset);
        $this->assertSame(ErrorCode::LookbehindUnbounded, $new->errorCode);
    }

    #[Test]
    public function test_the_change_names_its_release(): void
    {
        $this->assertSame('10.48', PcreFeature::ExtendedClassBesideLookbehindCompiles->release());
    }

    private static function parser(string $release): RegexParser
    {
        return RegexParser::create(['php_version' => 80400, 'pcre_version' => $release, 'runtime_pcre_validation' => false]);
    }
}
