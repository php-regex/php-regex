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

namespace PHPRegex\Tests\Unit\Parser\Text;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where PCRE2 10.47 reports an error past the character it stops on, in
 * UTF mode past the whole character: each place that steps past one, fed a
 * character of several bytes, a byte that starts none, and a sequence cut
 * short. A pattern that is not UTF-8 in UTF mode is refused at its first
 * bad byte before any of them reads it, as PCRE refuses it.
 */
final class Utf8WidthReadsTest extends TestCase
{
    /**
     * Each verified with preg_match() on PCRE2 10.49.
     *
     * @param array{0: ErrorCode, 1: int} $error
     */
    #[Test]
    #[DataProvider('provideCharactersAtFault')]
    public function test_the_error_past_a_character_steps_over_its_bytes(string $pattern, array $error): void
    {
        $result = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => '10.49', 'runtime_pcre_validation' => false])->validate($pattern);

        $this->assertSame($error, [$result->errorCode, $result->offset], bin2hex($pattern));
    }

    /**
     * Before PCRE2 10.47 the error stands on the character itself, whatever
     * its width.
     */
    #[Test]
    public function test_the_error_stands_on_the_character_before_pcre2_10_47(): void
    {
        $parser = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => '10.45', 'runtime_pcre_validation' => false]);

        foreach (["/(*abc\u{e9})/u" => 5, "/(?P\u{e9})/u" => 3, "/(?(*abc\u{1F600})a)/u" => 7] as $pattern => $offset) {
            $this->assertSame($offset, $parser->validate($pattern)->offset, $pattern);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, error: array{0: ErrorCode, 1: int}}>
     */
    public static function provideCharactersAtFault(): iterable
    {
        $sites = [
            // A closed verb PCRE does not know, read by the validator.
            'unknown verb' => ['/(*abc', ')/u', ErrorCode::VerbInvalid, 5],
            // A verb no ")" closes.
            'unclosed verb' => ['/(*abc', '/u', ErrorCode::VerbInvalid, 5],
            // An alphabetic assertion that decides a conditional.
            'condition assertion' => ['/(?(*abc', ')a)/u', ErrorCode::VerbInvalid, 7],
            // What follows "(?P".
            'after (?P' => ['/(?P', ')/u', ErrorCode::GroupSyntax, 3],
            // Where "=" belongs in a version condition.
            'version condition' => ['/(?(VERSION', 'abcdefgh))/u', ErrorCode::ConditionalVersionSyntax, 10],
            // Where ")" belongs after the major of a version condition.
            'version condition major' => ['/(?(VERSION=1', 'abcdefgh))/u', ErrorCode::ConditionalVersionSyntax, 12],
            // A quoted character in an extended class.
            'extended class' => ['/(?[\Q', '])/u', ErrorCode::ExtendedClassUnexpectedCharacter, 5],
        ];

        foreach ($sites as $site => [$before, $after, $code, $at]) {
            yield $site.', two bytes' => ['pattern' => $before."\u{e9}".$after, 'error' => [$code, $at + 2]];
            yield $site.', three bytes' => ['pattern' => $before."\u{20AC}".$after, 'error' => [$code, $at + 3]];
            yield $site.', four bytes' => ['pattern' => $before."\u{1F600}".$after, 'error' => [$code, $at + 4]];
            // "UTF-8 error: illegal byte (0xfe or 0xff)".
            yield $site.', a byte that starts no character' => ['pattern' => $before."\xFF".$after, 'error' => [ErrorCode::EncodingInvalidUtf8, $at]];
            // "UTF-8 error: isolated byte with 0x80 bit set".
            yield $site.', a lone continuation byte' => ['pattern' => $before."\x80".$after, 'error' => [ErrorCode::EncodingInvalidUtf8, $at]];
            // "UTF-8 error: byte 2 top bits not 0x80".
            yield $site.', a sequence cut short' => ['pattern' => $before."\xE2\x82".$after, 'error' => [ErrorCode::EncodingInvalidUtf8, $at]];
            // "UTF-8 error: 1 byte missing at end".
            yield $site.', a sequence cut by the end' => ['pattern' => $before."\xC3/u", 'error' => [ErrorCode::EncodingInvalidUtf8, $at]];
        }
    }
}
