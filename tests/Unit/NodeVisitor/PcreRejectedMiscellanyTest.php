<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit\NodeVisitor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Node\QuantifierNode;
use RegexParser\Regex;

/**
 * Patterns PHP refuses on every supported PCRE2 release, next to the
 * nearest forms it compiles. Each row was checked with preg_match() on
 * PCRE2 10.48 and with pcre2test 10.40.
 */
final class PcreRejectedMiscellanyTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRejectedPatterns')]
    public function test_validate_rejects_pattern_php_refuses(string $pattern): void
    {
        $this->assertFalse(Regex::create()->validate($pattern)->isValid, \sprintf('%s is refused by PHP but was reported valid.', $pattern));
    }

    #[Test]
    #[DataProvider('provideAcceptedPatterns')]
    public function test_validate_accepts_neighbouring_pattern_php_compiles(string $pattern): void
    {
        $result = Regex::create()->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s compiles in PHP but was reported invalid: %s', $pattern, (string) $result->error));
    }

    #[Test]
    public function test_backslash_c_under_utf_is_refused_for_every_php(): void
    {
        // PHP 8.4.25 and 8.5.10 refuse it (GH-21134); earlier releases compile
        // it and can crash matching it, so it is reported for them too.
        foreach ([['php_version' => '8.2'], ['php_version' => '8.3'], ['php_version' => '8.4', 'pcre_version' => '10.42'], ['php_version' => '8.5']] as $target) {
            $result = Regex::create(['cache' => null] + $target)->validate('/ab\\Cde/u');

            $this->assertFalse($result->isValid, (string) json_encode($target));
            $this->assertSame('regex.escape.single_byte_in_utf', $result->errorCode);
            $this->assertSame(4, $result->offset);
        }

        $this->assertTrue(Regex::create(['cache' => null])->validate('/ab\\Cde/')->isValid);
    }

    #[Test]
    #[DataProvider('provideBraceTexts')]
    public function test_empty_braces_are_text_not_a_quantifier(string $pattern, string $subject): void
    {
        // PCRE reads "{}" and "{,}" as literal text: preg_match() matches
        // the braces themselves, on PCRE2 10.40 and 10.48.
        $this->assertSame(1, preg_match($pattern, $subject));
        $this->assertNotInstanceOf(QuantifierNode::class, Regex::create()->parse($pattern)->pattern);
        $this->assertTrue(Regex::create()->validate($pattern)->isValid);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideBraceTexts(): iterable
    {
        yield 'comma between braces' => ['pattern' => '/a{,}/', 'subject' => 'a{,}'];
        yield 'nothing between braces' => ['pattern' => '/a{}/', 'subject' => 'a{}'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRejectedPatterns(): iterable
    {
        yield '\\C under u (PHP: using \\C is incompatible with the \'u\' modifier): /\\C/u' => ['pattern' => '/\\C/u'];
        yield '\\C inside a sequence under u: /a\\Cb/u' => ['pattern' => '/a\\Cb/u'];
        yield 'code point above 0xff without u (error 134): /\\x{100}/' => ['pattern' => '/\\x{100}/'];
        yield 'code point above 0xff in a class without u (error 134): /[\\x{100}]/' => ['pattern' => '/[\\x{100}]/'];
        yield 'quantified numbered callout (error 109): /(?C1)+/' => ['pattern' => '/(?C1)+/'];
        yield 'quantified callout after a literal (error 109): /a(?C1)*/' => ['pattern' => '/a(?C1)*/'];
        yield 'counted string callout (error 109): /(?C"x"){2}/' => ['pattern' => '/(?C"x"){2}/'];
        yield 'unknown LIMIT_LOOKBEHIND setting (error 160): /(*LIMIT_LOOKBEHIND=5)a/' => ['pattern' => '/(*LIMIT_LOOKBEHIND=5)a/'];
        yield 'unknown FIRSTLINE setting (error 160): /(*FIRSTLINE)a/' => ['pattern' => '/(*FIRSTLINE)a/'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideAcceptedPatterns(): iterable
    {
        yield '\\C without u: /\\C/' => ['pattern' => '/\\C/'];
        yield '\\C inside a sequence without u: /a\\Cb/' => ['pattern' => '/a\\Cb/'];
        yield 'code point 0xff without u: /\\x{ff}/' => ['pattern' => '/\\x{ff}/'];
        yield 'code point above 0xff under u: /\\x{100}/u' => ['pattern' => '/\\x{100}/u'];
        yield 'code point above 0xff under a leading (*UTF): /(*UTF)\\x{100}/' => ['pattern' => '/(*UTF)\\x{100}/'];
        yield 'callout before a quantified atom: /(?C1)a+/' => ['pattern' => '/(?C1)a+/'];
        yield 'callout between atoms, the next one quantified: /a(?C1)b*/' => ['pattern' => '/a(?C1)b*/'];
        yield '\\C with a leading (*UTF) but no u flag (only a JIT warning at match time): /(*UTF)\\C/' => ['pattern' => '/(*UTF)\\C/'];
        yield '\\C after (*CR)(*UTF), no u flag: /(*CR)(*UTF)a\\C/' => ['pattern' => '/(*CR)(*UTF)a\\C/'];
        yield 'empty braces after a callout are text: /(?C1){,}/' => ['pattern' => '/(?C1){,}/'];
        yield 'empty braces after a literal and a callout are text: /a(?C1){}/' => ['pattern' => '/a(?C1){}/'];
        yield 'possessive-looking empty braces after a string callout: /(?C"x"){,}+/' => ['pattern' => '/(?C"x"){,}+/'];
        yield 'heap limit setting: /(*LIMIT_HEAP=5)a/' => ['pattern' => '/(*LIMIT_HEAP=5)a/'];
        yield 'not-empty-at-start setting: /(*NOTEMPTY_ATSTART)a/' => ['pattern' => '/(*NOTEMPTY_ATSTART)a/'];
    }
}
