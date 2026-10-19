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

use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\NameCharacters;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NameCharactersTest extends TestCase
{
    /**
     * The table agrees with the PCRE2 the tests run on: a code point is a
     * letter or a digit there exactly when the table gives it to that
     * release, so every PHP of the test matrix checks the rows its PCRE2
     * knows.
     */
    #[Test]
    public function test_the_table_agrees_with_the_running_pcre2(): void
    {
        $runtime = PcreTarget::runtime();
        $rows = self::rows();
        $this->assertNotEmpty($rows);

        foreach ($rows as [$first, $last, $since, $digit]) {
            foreach ([$first, $last] as $codePoint) {
                $character = mb_chr($codePoint, 'UTF-8');
                $known = $runtime->pcreAtLeast($since);
                $this->assertSame($known && $digit, 1 === preg_match('/^\p{Nd}$/u', $character), \sprintf('U+%04X as a digit on PCRE2 %s', $codePoint, $runtime->pcreVersion));
                $this->assertSame($known, 1 === preg_match('/^[\p{L}\p{Nd}]$/u', $character), \sprintf('U+%04X as a name character on PCRE2 %s', $codePoint, $runtime->pcreVersion));
            }
        }
    }

    #[Test]
    public function test_a_letter_counts_from_the_release_whose_tables_have_it(): void
    {
        // U+0870, a letter from Unicode 14, which PCRE2 10.39 brought.
        $name = "a\u{870}";

        $this->assertSame(1, NameCharacters::end($name, 0, new PcreTarget(80000, '10.38')));
        $this->assertSame(\strlen($name), NameCharacters::end($name, 0, new PcreTarget(80100, '10.39')));
        $this->assertFalse(NameCharacters::isName($name, new PcreTarget(80000, '10.38')));
        $this->assertTrue(NameCharacters::isName($name, new PcreTarget(80400, '10.49')));
    }

    #[Test]
    public function test_a_digit_counts_from_the_release_whose_tables_have_it(): void
    {
        // U+10D40, a decimal digit from Unicode 16, which PCRE2 10.45 brought.
        $digit = "\u{10D40}";

        $this->assertSame(0, NameCharacters::digitAt($digit, 0, new PcreTarget(80400, '10.44')));
        $this->assertSame(4, NameCharacters::digitAt($digit, 0, new PcreTarget(80400, '10.45')));
        $this->assertSame(1, NameCharacters::digitAt('1', 0, new PcreTarget(80400, '10.44')));
        $this->assertFalse(NameCharacters::isName($digit.'a', new PcreTarget(80400, '10.45')));
    }

    /**
     * Every range of both tables: its first and last code points, the
     * release it is listed under, and whether it holds digits.
     *
     * @return list<array{int, int, string, bool}>
     */
    private static function rows(): array
    {
        $rows = [];
        foreach (['LETTERS_SINCE' => false, 'DIGITS_SINCE' => true] as $table => $digit) {
            /** @var array<string, list<array{int, int}>> $ranges */
            $ranges = (new \ReflectionClassConstant(NameCharacters::class, $table))->getValue();
            foreach ($ranges as $release => $list) {
                foreach ($list as [$first, $last]) {
                    $rows[] = [$first, $last, $release, $digit];
                }
            }
        }

        return $rows;
    }
}
