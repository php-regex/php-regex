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
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * From PCRE2 10.43, spaces and tabs may follow the "{" and precede the "}"
 * of "\g{...}" and "\k{...}". Nowhere else: not between the sign and the
 * number of "\g{-1}", not in "\k<...>" or "(?P=...)". PHP 8.4 bundles 10.44;
 * PHP 8.2 and 8.3 bundle 10.40 and 10.42, which refuse the padding (every
 * verdict and offset below is pcre2test's on those releases, and PHP's on
 * PCRE2 10.48).
 */
final class PaddedReferenceBracesTest extends TestCase
{
    #[Test]
    #[DataProvider('providePaddedReferences')]
    public function test_validate_accepts_padded_braces_from_php_8_4(string $pattern): void
    {
        foreach ([80400, 80500] as $phpVersion) {
            $result = Regex::create(['cache' => null, 'php_version' => $phpVersion])->validate($pattern);

            $this->assertTrue($result->isValid, \sprintf('%s compiles on PHP %d: %s', $pattern, $phpVersion, (string) $result->error));
        }
    }

    #[Test]
    #[DataProvider('providePaddedReferences')]
    public function test_validate_follows_the_running_php(string $pattern): void
    {
        error_clear_last();
        $compiles = false !== @preg_match($pattern, '') || null === error_get_last();

        $this->assertSame($compiles, Regex::create(['cache' => null])->validate($pattern)->isValid, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePaddedReferences(): iterable
    {
        yield 'number' => ['pattern' => '/(a)\\g{ 1 }/'];
        yield 'space after the number only' => ['pattern' => '/(a)\\g{1 }/'];
        yield 'space before the number only' => ['pattern' => '/(a)\\g{ 1}/'];
        yield 'tabs' => ['pattern' => "/(a)\\g{\t1\t}/"];
        yield 'relative number' => ['pattern' => '/(a)\\g{ -1 }/'];
        yield 'forward relative number' => ['pattern' => '/(a)\\g{ +1 }(b)/'];
        yield 'name after g' => ['pattern' => '/(?<n>a)\\g{ n }/'];
        yield 'name after k' => ['pattern' => '/(?<n>a)\\k{ n }/'];
        yield 'space after the name only' => ['pattern' => '/(?<n>a)\\k{n }/'];
        yield 'under x' => ['pattern' => '/(?<n>a)\\k{ n}/x'];
    }

    #[Test]
    #[DataProvider('provideRefusedPadding')]
    public function test_validate_refuses_padding_where_pcre_does(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''), \sprintf('%s should not compile.', $pattern));

        foreach ([80400, 80500] as $phpVersion) {
            $result = Regex::create(['cache' => null, 'php_version' => $phpVersion])->validate($pattern);

            $this->assertFalse($result->isValid, \sprintf('%s does not compile on PHP %d.', $pattern, $phpVersion));
            $this->assertSame($offset, $result->offset, $pattern);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideRefusedPadding(): iterable
    {
        yield 'space between sign and number' => ['pattern' => '/(a)\\g{- 1}/', 'offset' => 6];
        yield 'nothing but spaces' => ['pattern' => '/(a)\\g{  }/', 'offset' => 8];
        yield 'padding in angle brackets' => ['pattern' => '/(?<n>a)\\k< n >/', 'offset' => 10];
        yield 'padding in a Python reference' => ['pattern' => '/(?<n>a)(?P= n )/', 'offset' => 11];
    }

    #[Test]
    #[DataProvider('provideRefusedBefore1043')]
    public function test_validate_refuses_padding_before_php_8_4(string $pattern, int $offset): void
    {
        foreach ([80200, 80300] as $phpVersion) {
            $result = Regex::create(['cache' => null, 'php_version' => $phpVersion])->validate($pattern);

            $this->assertFalse($result->isValid, \sprintf('%s does not compile on PHP %d.', $pattern, $phpVersion));
            $this->assertSame($offset, $result->offset, \sprintf('%s on PHP %d', $pattern, $phpVersion));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideRefusedBefore1043(): iterable
    {
        yield 'number' => ['pattern' => '/(a)\\g{ 1 }/', 'offset' => 6];
        yield 'space after the number only' => ['pattern' => '/(a)\\g{1 }/', 'offset' => 5];
        yield 'relative number' => ['pattern' => '/(a)\\g{ -1 }/', 'offset' => 6];
        yield 'name after k' => ['pattern' => '/(?<n>a)\\k{ n }/', 'offset' => 10];
        yield 'space after the name only' => ['pattern' => '/(?<n>a)\\k{n }/', 'offset' => 11];
        yield 'space before the number only' => ['pattern' => '/(a)\\g{ 1}/', 'offset' => 6];
        yield 'space before the name only' => ['pattern' => '/(?<n>a)\\k{ n}/', 'offset' => 10];
    }

    /**
     * Before PCRE2 10.43 a space or a tab after the "{" leaves no name or
     * number there; one after a number ends "\\g", which PCRE2 reads first
     * and refuses as it reads it; one after a name ends the name too early
     * (pcre2test 10.32 to 10.42, php:7.3-cli under "X", php:8.2-cli and
     * php:8.3-cli: "subpattern name expected at offset 6" for "(a)\\g{ 1 }",
     * "a relative value of zero is not allowed at offset 5" for
     * "(a)\\g{+0 }", "syntax error in subpattern name (missing terminator?)
     * at offset 11" for "(?<n>a)\\g{n }").
     */
    #[Test]
    #[DataProvider('provideCodesBefore1043')]
    public function test_padding_is_refused_as_pcre2_refuses_it_before_10_43(string $pattern, ErrorCode $code, int $offset): void
    {
        foreach ([['7.3', '10.32'], ['8.2', '10.40'], ['8.3', '10.42']] as [$php, $release]) {
            $result = RegexParser::create(['cache' => null, 'php_version' => $php, 'pcre_version' => $release, 'runtime_pcre_validation' => false])->validate($pattern.('7.3' === $php ? 'X' : ''));

            $this->assertSame($code, $result->errorCode, \sprintf('%s on PCRE2 %s: %s', $pattern, $release, (string) $result->error));
            $this->assertSame($offset, $result->offset, \sprintf('%s on PCRE2 %s', $pattern, $release));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideCodesBefore1043(): iterable
    {
        yield 'space before a number' => ['pattern' => '/(a)\\g{ 1 }/', 'code' => ErrorCode::GroupNameExpected, 'offset' => 6];
        yield 'tab before a number' => ['pattern' => "/(a)\\g{\t1}/", 'code' => ErrorCode::GroupNameExpected, 'offset' => 6];
        yield 'space before a backward number' => ['pattern' => '/(a)\\g{ -1 }/', 'code' => ErrorCode::GroupNameExpected, 'offset' => 6];
        yield 'space before a name after g' => ['pattern' => '/(?<n>a)\\g{ n }/', 'code' => ErrorCode::GroupNameExpected, 'offset' => 10];
        yield 'space before a name after k' => ['pattern' => '/(?<n>a)\\k{ n }/', 'code' => ErrorCode::GroupNameExpected, 'offset' => 10];
        yield 'space after a number' => ['pattern' => '/(a)\\g{1 }/', 'code' => ErrorCode::BackrefInvalidSyntax, 'offset' => 5];
        yield 'space after a relative zero' => ['pattern' => '/(a)\\g{+0 }/', 'code' => ErrorCode::BackrefZero, 'offset' => 5];
        yield 'space after a number back past the groups' => ['pattern' => '/(a)\\g{-3 }/', 'code' => ErrorCode::BackrefRelative, 'offset' => 5];
        yield 'space after a number too big' => ['pattern' => '/(a)\\g{65536 }/', 'code' => ErrorCode::GroupNumberTooBig, 'offset' => 5];
        yield 'space after a name after g' => ['pattern' => '/(?<n>a)\\g{n }/', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 11];
        yield 'space after a name after k' => ['pattern' => '/(?<n>a)\\k{n }/', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 11];
        yield 'tab after a name after k' => ['pattern' => "/(?<n>a)\\k{n\t}/", 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 11];
        yield 'space before a sign and a name, left open' => ['pattern' => '/(a)\\g{ +n/', 'code' => ErrorCode::GroupNameExpected, 'offset' => 6];
        yield 'space before a name, left open' => ['pattern' => '/(a)\\g{ n/', 'code' => ErrorCode::GroupNameExpected, 'offset' => 6];
    }

    #[Test]
    public function test_validate_accepts_braces_without_padding_before_php_8_4(): void
    {
        foreach (['/(a)\\g{1}/', '/(a)\\g{-1}/', '/(?<n>a)\\k{n}/', '/(?<n>a)\\g{n}/'] as $pattern) {
            $this->assertTrue(Regex::create(['cache' => null, 'php_version' => 80200])->validate($pattern)->isValid, $pattern);
        }
    }

    #[Test]
    public function test_the_padding_does_not_change_what_is_referred_to(): void
    {
        $regex = Regex::create(['cache' => null, 'php_version' => 80400]);

        $number = $regex->parse('/(a)\\g{ -1 }/')->pattern;
        $this->assertInstanceOf(SequenceNode::class, $number);
        $this->assertInstanceOf(BackrefNode::class, $number->children[1]);
        $this->assertSame('\\g{-1}', $number->children[1]->ref);

        $name = $regex->parse('/(?<n>a)\\k{ n }/')->pattern;
        $this->assertInstanceOf(SequenceNode::class, $name);
        $this->assertInstanceOf(BackrefNode::class, $name->children[1]);
        $this->assertSame('\\k{n}', $name->children[1]->ref);

        // The pattern compiles back as it was written.
        $this->assertSame('/(?<n>a)\\k{ n }/', $regex->parse('/(?<n>a)\\k{ n }/')->accept(new PatternPrinter()));
    }
}
