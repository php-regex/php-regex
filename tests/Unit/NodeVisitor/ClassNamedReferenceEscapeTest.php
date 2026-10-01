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

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "\k" inside a class: pcre2test 10.40 and 10.44 refuse it (error 107,
 * "escape sequence is invalid in character class", on the "k"), 10.45 and
 * newer read it as the letter. No PHP release bundles 10.45 yet, so it is
 * refused for every PHP version targeted, and accepted only on a running PHP
 * that links 10.45 or newer.
 */
final class ClassNamedReferenceEscapeTest extends TestCase
{
    #[Test]
    #[DataProvider('provideClassesWithK')]
    public function test_validate_refuses_k_in_a_class_for_bundled_pcre2(string $pattern, int $offset): void
    {
        foreach ([80200, 80300, 80400, 80500] as $phpVersion) {
            $result = Regex::create(['cache' => null, 'php_version' => $phpVersion])->validate($pattern);

            $this->assertFalse($result->isValid, \sprintf('%s is refused by the PCRE2 PHP %d bundles.', $pattern, $phpVersion));
            $this->assertSame(ErrorCode::CharclassInvalidEscape, $result->errorCode);
            $this->assertSame($offset, $result->offset, $pattern);
        }
    }

    #[Test]
    #[DataProvider('provideClassesWithK')]
    public function test_validate_follows_the_running_pcre2_for_k_in_a_class(string $pattern, int $offset): void
    {
        // The offset is the older releases'; a newer one refuses nothing.
        unset($offset);

        $readsLetter = version_compare(explode(' ', \PCRE_VERSION)[0], '10.45', '>=');

        $this->assertSame($readsLetter, false !== @preg_match($pattern, ''));
        $this->assertSame($readsLetter, Regex::create(['cache' => null])->validate($pattern)->isValid, $pattern);
    }

    #[Test]
    public function test_validate_accepts_g_in_a_class_everywhere(): void
    {
        foreach ([80200, 80400] as $phpVersion) {
            $this->assertTrue(Regex::create(['cache' => null, 'php_version' => $phpVersion])->validate('/[\\g]/')->isValid);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideClassesWithK(): iterable
    {
        yield 'alone' => ['pattern' => '/[\\k]/', 'offset' => 2];
        yield 'after a member' => ['pattern' => '/[a\\k]/', 'offset' => 3];
        yield 'with a name after it' => ['pattern' => '/[\\k<a>]/', 'offset' => 2];
    }
}
