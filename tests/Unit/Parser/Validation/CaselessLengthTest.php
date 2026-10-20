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

namespace PHPRegex\Tests\Unit\Parser\Validation;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Validation\CompiledLength;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Parser\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The length the first pass of each release counts for a caseless item or
 * a class of a character and its other case, which the Unicode tables of
 * the release and the case rules the pattern sets decide: each row is the
 * "code size" pcre2test prints on that release with the "memory" modifier
 * ("u" read as "utf" and "ucp", "r" as "caseless_restrict"), the length
 * its first pass counted.
 */
final class CaselessLengthTest extends TestCase
{
    #[Test]
    #[DataProvider('provideLengthsFromTheUnicodeTables')]
    public function test_the_other_cases_come_from_the_unicode_tables_of_the_release(string $pattern, string $release, int $length): void
    {
        $this->assertSame($length, self::lengthOf($pattern, $release), \sprintf('%s on PCRE2 %s', $pattern, $release));
    }

    /**
     * @return iterable<string, array{pattern: string, release: string, length: int}>
     */
    public static function provideLengthsFromTheUnicodeTables(): iterable
    {
        yield 'caseless class of a range past 255, 10.32' => ['pattern' => '/[ā-ž]/iu', 'release' => '10.32', 'length' => 49];
        yield 'caseless class of a range past 255, 10.44' => ['pattern' => '/[ā-ž]/iu', 'release' => '10.44', 'length' => 49];
        yield 'caseless class of a range past 255, 10.45' => ['pattern' => '/[ā-ž]/iu', 'release' => '10.45', 'length' => 49];
        yield 'caseless class of a range past 255, 10.49' => ['pattern' => '/[ā-ž]/iu', 'release' => '10.49', 'length' => 49];
        yield 'caseless class of a long range past 255, 10.32' => ['pattern' => '/[\\x{100}-\\x{400}]/iu', 'release' => '10.32', 'length' => 147];
        yield 'caseless class of a long range past 255, 10.33' => ['pattern' => '/[\\x{100}-\\x{400}]/iu', 'release' => '10.33', 'length' => 147];
        yield 'caseless class of a long range past 255, 10.34' => ['pattern' => '/[\\x{100}-\\x{400}]/iu', 'release' => '10.34', 'length' => 151];
        yield 'caseless class of a long range past 255, 10.44' => ['pattern' => '/[\\x{100}-\\x{400}]/iu', 'release' => '10.44', 'length' => 151];
        yield 'caseless class of a long range past 255, 10.45' => ['pattern' => '/[\\x{100}-\\x{400}]/iu', 'release' => '10.45', 'length' => 95];
        yield 'caseless class of a long range past 255, 10.49' => ['pattern' => '/[\\x{100}-\\x{400}]/iu', 'release' => '10.49', 'length' => 95];
        yield 'caseless class of every character past 255, 10.32' => ['pattern' => '/[\\x{100}-\\x{10ffff}]/iu', 'release' => '10.32', 'length' => 51];
        yield 'caseless class of every character past 255, 10.44' => ['pattern' => '/[\\x{100}-\\x{10ffff}]/iu', 'release' => '10.44', 'length' => 51];
        yield 'caseless class of every character past 255, 10.45' => ['pattern' => '/[\\x{100}-\\x{10ffff}]/iu', 'release' => '10.45', 'length' => 40];
        yield 'caseless class of every character past 255, 10.49' => ['pattern' => '/[\\x{100}-\\x{10ffff}]/iu', 'release' => '10.49', 'length' => 40];
        yield 'caseless class of a range of titlecase and small letters, 10.32' => ['pattern' => '/[Ǆ-ǌ]/iu', 'release' => '10.32', 'length' => 42];
        yield 'caseless class of a range of titlecase and small letters, 10.44' => ['pattern' => '/[Ǆ-ǌ]/iu', 'release' => '10.44', 'length' => 42];
        yield 'caseless class of a range of titlecase and small letters, 10.45' => ['pattern' => '/[Ǆ-ǌ]/iu', 'release' => '10.45', 'length' => 17];
        yield 'caseless class of a range of titlecase and small letters, 10.49' => ['pattern' => '/[Ǆ-ǌ]/iu', 'release' => '10.49', 'length' => 17];
        yield 'caseless class of the Cyrillic capital tje and a letter, 10.32' => ['pattern' => '/[\\x{1C89}x]/iu', 'release' => '10.32', 'length' => 48];
        yield 'caseless class of the Cyrillic capital tje and a letter, 10.44' => ['pattern' => '/[\\x{1C89}x]/iu', 'release' => '10.44', 'length' => 48];
        yield 'caseless class of the Cyrillic capital tje and a letter, 10.45' => ['pattern' => '/[\\x{1C89}x]/iu', 'release' => '10.45', 'length' => 51];
        yield 'caseless class of the Cyrillic capital tje and a letter, 10.49' => ['pattern' => '/[\\x{1C89}x]/iu', 'release' => '10.49', 'length' => 51];
        yield 'caseless class of four-byte letters, 10.32' => ['pattern' => '/[\\x{10400}-\\x{10427}]/iu', 'release' => '10.32', 'length' => 21];
        yield 'caseless class of four-byte letters, 10.44' => ['pattern' => '/[\\x{10400}-\\x{10427}]/iu', 'release' => '10.44', 'length' => 21];
        yield 'caseless class of four-byte letters, 10.45' => ['pattern' => '/[\\x{10400}-\\x{10427}]/iu', 'release' => '10.45', 'length' => 21];
        yield 'caseless class of four-byte letters, 10.49' => ['pattern' => '/[\\x{10400}-\\x{10427}]/iu', 'release' => '10.49', 'length' => 21];
        yield 'negated caseless class of a range past 255, 10.32' => ['pattern' => '/[^ā-ž]/iu', 'release' => '10.32', 'length' => 49];
        yield 'negated caseless class of a range past 255, 10.44' => ['pattern' => '/[^ā-ž]/iu', 'release' => '10.44', 'length' => 49];
        yield 'negated caseless class of a range past 255, 10.45' => ['pattern' => '/[^ā-ž]/iu', 'release' => '10.45', 'length' => 49];
        yield 'negated caseless class of a range past 255, 10.49' => ['pattern' => '/[^ā-ž]/iu', 'release' => '10.49', 'length' => 49];
        yield 'caseless class of a range its other cases extend, 10.32' => ['pattern' => '/[Ā-Ă]/iu', 'release' => '10.32', 'length' => 17];
        yield 'caseless class of a range its other cases extend, 10.44' => ['pattern' => '/[Ā-Ă]/iu', 'release' => '10.44', 'length' => 17];
        yield 'caseless class of a range its other cases extend, 10.45' => ['pattern' => '/[Ā-Ă]/iu', 'release' => '10.45', 'length' => 17];
        yield 'caseless class of a range its other cases extend, 10.49' => ['pattern' => '/[Ā-Ă]/iu', 'release' => '10.49', 'length' => 17];
        yield 'caseless class of two capitals past 255, 10.32' => ['pattern' => '/[ĀĂ]/iu', 'release' => '10.32', 'length' => 22];
        yield 'caseless class of two capitals past 255, 10.44' => ['pattern' => '/[ĀĂ]/iu', 'release' => '10.44', 'length' => 22];
        yield 'caseless class of two capitals past 255, 10.45' => ['pattern' => '/[ĀĂ]/iu', 'release' => '10.45', 'length' => 17];
        yield 'caseless class of two capitals past 255, 10.49' => ['pattern' => '/[ĀĂ]/iu', 'release' => '10.49', 'length' => 17];
        yield 'caseless class of Latin-1 letters and letters past 255, 10.32' => ['pattern' => '/[à-ž]/iu', 'release' => '10.32', 'length' => 53];
        yield 'caseless class of Latin-1 letters and letters past 255, 10.44' => ['pattern' => '/[à-ž]/iu', 'release' => '10.44', 'length' => 53];
        yield 'caseless class of Latin-1 letters and letters past 255, 10.45' => ['pattern' => '/[à-ž]/iu', 'release' => '10.45', 'length' => 53];
        yield 'caseless class of Latin-1 letters and letters past 255, 10.49' => ['pattern' => '/[à-ž]/iu', 'release' => '10.49', 'length' => 53];
        yield 'caseless class of Glagolitic capitals, 10.32' => ['pattern' => '/[\\x{2C00}-\\x{2C2F}]/iu', 'release' => '10.32', 'length' => 19];
        yield 'caseless class of Glagolitic capitals, 10.44' => ['pattern' => '/[\\x{2C00}-\\x{2C2F}]/iu', 'release' => '10.44', 'length' => 19];
        yield 'caseless class of Glagolitic capitals, 10.45' => ['pattern' => '/[\\x{2C00}-\\x{2C2F}]/iu', 'release' => '10.45', 'length' => 19];
        yield 'caseless class of Glagolitic capitals, 10.49' => ['pattern' => '/[\\x{2C00}-\\x{2C2F}]/iu', 'release' => '10.49', 'length' => 19];
        yield 'caseless class of Latin letters of Unicode 12.1, 10.32' => ['pattern' => '/[\\x{A7B8}-\\x{A7C6}]/iu', 'release' => '10.32', 'length' => 19];
        yield 'caseless class of Latin letters of Unicode 12.1, 10.33' => ['pattern' => '/[\\x{A7B8}-\\x{A7C6}]/iu', 'release' => '10.33', 'length' => 19];
        yield 'caseless class of Latin letters of Unicode 12.1, 10.34' => ['pattern' => '/[\\x{A7B8}-\\x{A7C6}]/iu', 'release' => '10.34', 'length' => 30];
        yield 'caseless class of Latin letters of Unicode 12.1, 10.44' => ['pattern' => '/[\\x{A7B8}-\\x{A7C6}]/iu', 'release' => '10.44', 'length' => 30];
        yield 'caseless class of Latin letters of Unicode 12.1, 10.45' => ['pattern' => '/[\\x{A7B8}-\\x{A7C6}]/iu', 'release' => '10.45', 'length' => 30];
        yield 'caseless class of Latin letters of Unicode 12.1, 10.49' => ['pattern' => '/[\\x{A7B8}-\\x{A7C6}]/iu', 'release' => '10.49', 'length' => 30];
        yield 'caseless class of Vithkuqi capitals, 10.32' => ['pattern' => '/[\\x{10570}-\\x{1057A}]/iu', 'release' => '10.32', 'length' => 21];
        yield 'caseless class of Vithkuqi capitals, 10.38' => ['pattern' => '/[\\x{10570}-\\x{1057A}]/iu', 'release' => '10.38', 'length' => 21];
        yield 'caseless class of Vithkuqi capitals, 10.39' => ['pattern' => '/[\\x{10570}-\\x{1057A}]/iu', 'release' => '10.39', 'length' => 30];
        yield 'caseless class of Vithkuqi capitals, 10.44' => ['pattern' => '/[\\x{10570}-\\x{1057A}]/iu', 'release' => '10.44', 'length' => 30];
        yield 'caseless class of Vithkuqi capitals, 10.45' => ['pattern' => '/[\\x{10570}-\\x{1057A}]/iu', 'release' => '10.45', 'length' => 30];
        yield 'caseless class of Vithkuqi capitals, 10.49' => ['pattern' => '/[\\x{10570}-\\x{1057A}]/iu', 'release' => '10.49', 'length' => 30];
        yield 'caseless class of a letter past 255 and its other case, 10.32' => ['pattern' => '/[\\x{A7C7}\\x{A7C8}x]/iu', 'release' => '10.32', 'length' => 52];
        yield 'caseless class of a letter past 255 and its other case, 10.34' => ['pattern' => '/[\\x{A7C7}\\x{A7C8}x]/iu', 'release' => '10.34', 'length' => 52];
        yield 'caseless class of a letter past 255 and its other case, 10.35' => ['pattern' => '/[\\x{A7C7}\\x{A7C8}x]/iu', 'release' => '10.35', 'length' => 58];
        yield 'caseless class of a letter past 255 and its other case, 10.44' => ['pattern' => '/[\\x{A7C7}\\x{A7C8}x]/iu', 'release' => '10.44', 'length' => 58];
        yield 'caseless class of a letter past 255 and its other case, 10.45' => ['pattern' => '/[\\x{A7C7}\\x{A7C8}x]/iu', 'release' => '10.45', 'length' => 51];
        yield 'caseless class of a letter past 255 and its other case, 10.49' => ['pattern' => '/[\\x{A7C7}\\x{A7C8}x]/iu', 'release' => '10.49', 'length' => 51];
        yield 'class of a letter past 255 and its other case, 10.32' => ['pattern' => '/[āĀ]/u', 'release' => '10.32', 'length' => 18];
        yield 'class of a letter past 255 and its other case, 10.33' => ['pattern' => '/[āĀ]/u', 'release' => '10.33', 'length' => 18];
        yield 'class of a letter past 255 and its other case, 10.34' => ['pattern' => '/[āĀ]/u', 'release' => '10.34', 'length' => 10];
        yield 'class of a letter past 255 and its other case, 10.44' => ['pattern' => '/[āĀ]/u', 'release' => '10.44', 'length' => 10];
        yield 'class of a letter past 255 and its other case, 10.45' => ['pattern' => '/[āĀ]/u', 'release' => '10.45', 'length' => 10];
        yield 'class of a letter past 255 and its other case, 10.49' => ['pattern' => '/[āĀ]/u', 'release' => '10.49', 'length' => 10];
        yield 'class of a capital past 255 and its small letter, 10.32' => ['pattern' => '/[Āā]/u', 'release' => '10.32', 'length' => 18];
        yield 'class of a capital past 255 and its small letter, 10.33' => ['pattern' => '/[Āā]/u', 'release' => '10.33', 'length' => 18];
        yield 'class of a capital past 255 and its small letter, 10.34' => ['pattern' => '/[Āā]/u', 'release' => '10.34', 'length' => 10];
        yield 'class of a capital past 255 and its small letter, 10.44' => ['pattern' => '/[Āā]/u', 'release' => '10.44', 'length' => 10];
        yield 'class of a capital past 255 and its small letter, 10.45' => ['pattern' => '/[Āā]/u', 'release' => '10.45', 'length' => 10];
        yield 'class of a capital past 255 and its small letter, 10.49' => ['pattern' => '/[Āā]/u', 'release' => '10.49', 'length' => 10];
        yield 'class of two letters past 255 of other cases, 10.32' => ['pattern' => '/[āĂ]/u', 'release' => '10.32', 'length' => 18];
        yield 'class of two letters past 255 of other cases, 10.44' => ['pattern' => '/[āĂ]/u', 'release' => '10.44', 'length' => 18];
        yield 'class of two letters past 255 of other cases, 10.45' => ['pattern' => '/[āĂ]/u', 'release' => '10.45', 'length' => 17];
        yield 'class of two letters past 255 of other cases, 10.49' => ['pattern' => '/[āĂ]/u', 'release' => '10.49', 'length' => 17];
        yield 'class of the capital glottal a and its small letter, 10.32' => ['pattern' => '/[\\x{A7BA}\\x{A7BB}]/u', 'release' => '10.32', 'length' => 20];
        yield 'class of the capital glottal a and its small letter, 10.33' => ['pattern' => '/[\\x{A7BA}\\x{A7BB}]/u', 'release' => '10.33', 'length' => 20];
        yield 'class of the capital glottal a and its small letter, 10.34' => ['pattern' => '/[\\x{A7BA}\\x{A7BB}]/u', 'release' => '10.34', 'length' => 11];
        yield 'class of the capital glottal a and its small letter, 10.44' => ['pattern' => '/[\\x{A7BA}\\x{A7BB}]/u', 'release' => '10.44', 'length' => 11];
        yield 'class of the capital glottal a and its small letter, 10.45' => ['pattern' => '/[\\x{A7BA}\\x{A7BB}]/u', 'release' => '10.45', 'length' => 11];
        yield 'class of the capital glottal a and its small letter, 10.49' => ['pattern' => '/[\\x{A7BA}\\x{A7BB}]/u', 'release' => '10.49', 'length' => 11];
        yield 'class of the capital d with a short stroke overlay and its small letter, 10.32' => ['pattern' => '/[\\x{A7C7}\\x{A7C8}]/u', 'release' => '10.32', 'length' => 20];
        yield 'class of the capital d with a short stroke overlay and its small letter, 10.34' => ['pattern' => '/[\\x{A7C7}\\x{A7C8}]/u', 'release' => '10.34', 'length' => 20];
        yield 'class of the capital d with a short stroke overlay and its small letter, 10.35' => ['pattern' => '/[\\x{A7C7}\\x{A7C8}]/u', 'release' => '10.35', 'length' => 11];
        yield 'class of the capital d with a short stroke overlay and its small letter, 10.44' => ['pattern' => '/[\\x{A7C7}\\x{A7C8}]/u', 'release' => '10.44', 'length' => 11];
        yield 'class of the capital d with a short stroke overlay and its small letter, 10.45' => ['pattern' => '/[\\x{A7C7}\\x{A7C8}]/u', 'release' => '10.45', 'length' => 11];
        yield 'class of the capital d with a short stroke overlay and its small letter, 10.49' => ['pattern' => '/[\\x{A7C7}\\x{A7C8}]/u', 'release' => '10.49', 'length' => 11];
        yield 'class of the Cyrillic capital tje and its small letter, 10.32' => ['pattern' => '/[\\x{1C89}\\x{1C8A}]/u', 'release' => '10.32', 'length' => 20];
        yield 'class of the Cyrillic capital tje and its small letter, 10.44' => ['pattern' => '/[\\x{1C89}\\x{1C8A}]/u', 'release' => '10.44', 'length' => 20];
        yield 'class of the Cyrillic capital tje and its small letter, 10.45' => ['pattern' => '/[\\x{1C89}\\x{1C8A}]/u', 'release' => '10.45', 'length' => 11];
        yield 'class of the Cyrillic capital tje and its small letter, 10.49' => ['pattern' => '/[\\x{1C89}\\x{1C8A}]/u', 'release' => '10.49', 'length' => 11];
        yield 'class of the capital pharyngeal voiced fricative and its small letter, 10.32' => ['pattern' => '/[\\x{A7CE}\\x{A7CF}]/u', 'release' => '10.32', 'length' => 20];
        yield 'class of the capital pharyngeal voiced fricative and its small letter, 10.44' => ['pattern' => '/[\\x{A7CE}\\x{A7CF}]/u', 'release' => '10.44', 'length' => 20];
        yield 'class of the capital pharyngeal voiced fricative and its small letter, 10.45' => ['pattern' => '/[\\x{A7CE}\\x{A7CF}]/u', 'release' => '10.45', 'length' => 19];
        yield 'class of the capital pharyngeal voiced fricative and its small letter, 10.47' => ['pattern' => '/[\\x{A7CE}\\x{A7CF}]/u', 'release' => '10.47', 'length' => 19];
        yield 'class of the capital pharyngeal voiced fricative and its small letter, 10.48' => ['pattern' => '/[\\x{A7CE}\\x{A7CF}]/u', 'release' => '10.48', 'length' => 11];
        yield 'class of the capital pharyngeal voiced fricative and its small letter, 10.49' => ['pattern' => '/[\\x{A7CE}\\x{A7CF}]/u', 'release' => '10.49', 'length' => 11];
        yield 'class of the capital sharp s and the sharp s, 10.32' => ['pattern' => '/[\\x{1E9E}ß]/u', 'release' => '10.32', 'length' => 48];
        yield 'class of the capital sharp s and the sharp s, 10.33' => ['pattern' => '/[\\x{1E9E}ß]/u', 'release' => '10.33', 'length' => 48];
        yield 'class of the capital sharp s and the sharp s, 10.34' => ['pattern' => '/[\\x{1E9E}ß]/u', 'release' => '10.34', 'length' => 11];
        yield 'class of the capital sharp s and the sharp s, 10.44' => ['pattern' => '/[\\x{1E9E}ß]/u', 'release' => '10.44', 'length' => 11];
        yield 'class of the capital sharp s and the sharp s, 10.45' => ['pattern' => '/[\\x{1E9E}ß]/u', 'release' => '10.45', 'length' => 11];
        yield 'class of the capital sharp s and the sharp s, 10.49' => ['pattern' => '/[\\x{1E9E}ß]/u', 'release' => '10.49', 'length' => 11];
        yield 'class of the capital y with a diaeresis and its small letter, 10.32' => ['pattern' => '/[\\x{178}ÿ]/u', 'release' => '10.32', 'length' => 47];
        yield 'class of the capital y with a diaeresis and its small letter, 10.33' => ['pattern' => '/[\\x{178}ÿ]/u', 'release' => '10.33', 'length' => 47];
        yield 'class of the capital y with a diaeresis and its small letter, 10.34' => ['pattern' => '/[\\x{178}ÿ]/u', 'release' => '10.34', 'length' => 10];
        yield 'class of the capital y with a diaeresis and its small letter, 10.44' => ['pattern' => '/[\\x{178}ÿ]/u', 'release' => '10.44', 'length' => 10];
        yield 'class of the capital y with a diaeresis and its small letter, 10.45' => ['pattern' => '/[\\x{178}ÿ]/u', 'release' => '10.45', 'length' => 10];
        yield 'class of the capital y with a diaeresis and its small letter, 10.49' => ['pattern' => '/[\\x{178}ÿ]/u', 'release' => '10.49', 'length' => 10];
        yield 'class of a capital sigma and its small letter, which has a third case, 10.32' => ['pattern' => '/[\\x{3A3}\\x{3C3}]/u', 'release' => '10.32', 'length' => 18];
        yield 'class of a capital sigma and its small letter, which has a third case, 10.44' => ['pattern' => '/[\\x{3A3}\\x{3C3}]/u', 'release' => '10.44', 'length' => 18];
        yield 'class of a capital sigma and its small letter, which has a third case, 10.45' => ['pattern' => '/[\\x{3A3}\\x{3C3}]/u', 'release' => '10.45', 'length' => 18];
        yield 'class of a capital sigma and its small letter, which has a third case, 10.49' => ['pattern' => '/[\\x{3A3}\\x{3C3}]/u', 'release' => '10.49', 'length' => 18];
        yield 'class of k and the Kelvin sign, 10.32' => ['pattern' => '/[k\\x{212A}]/u', 'release' => '10.32', 'length' => 48];
        yield 'class of k and the Kelvin sign, 10.44' => ['pattern' => '/[k\\x{212A}]/u', 'release' => '10.44', 'length' => 48];
        yield 'class of k and the Kelvin sign, 10.45' => ['pattern' => '/[k\\x{212A}]/u', 'release' => '10.45', 'length' => 48];
        yield 'class of k and the Kelvin sign, 10.49' => ['pattern' => '/[k\\x{212A}]/u', 'release' => '10.49', 'length' => 48];
        // Up to 10.34 the other case of a byte past ASCII without UTF
        // follows the locale PHP builds its tables from.
        yield 'class of a Latin-1 letter and its other case under UCP without UTF, 10.35' => ['pattern' => '/(*UCP)[\\xe0\\xc0]/', 'release' => '10.35', 'length' => 9];
        yield 'class of a Latin-1 letter and its other case under UCP without UTF, 10.44' => ['pattern' => '/(*UCP)[\\xe0\\xc0]/', 'release' => '10.44', 'length' => 9];
        yield 'class of a Latin-1 letter and its other case under UCP without UTF, 10.45' => ['pattern' => '/(*UCP)[\\xe0\\xc0]/', 'release' => '10.45', 'length' => 9];
        yield 'class of a Latin-1 letter and its other case under UCP without UTF, 10.49' => ['pattern' => '/(*UCP)[\\xe0\\xc0]/', 'release' => '10.49', 'length' => 9];
        yield 'class of the y with a diaeresis and a letter under UCP without UTF, 10.32' => ['pattern' => '/(*UCP)[\\xff\\xe0]/', 'release' => '10.32', 'length' => 40];
        yield 'class of the y with a diaeresis and a letter under UCP without UTF, 10.44' => ['pattern' => '/(*UCP)[\\xff\\xe0]/', 'release' => '10.44', 'length' => 40];
        yield 'class of the y with a diaeresis and a letter under UCP without UTF, 10.45' => ['pattern' => '/(*UCP)[\\xff\\xe0]/', 'release' => '10.45', 'length' => 40];
        yield 'class of the y with a diaeresis and a letter under UCP without UTF, 10.49' => ['pattern' => '/(*UCP)[\\xff\\xe0]/', 'release' => '10.49', 'length' => 40];
        yield 'caseless class of bytes past ASCII under UCP without UTF, 10.32' => ['pattern' => '/(*UCP)[\\xe0-\\xfe]/i', 'release' => '10.32', 'length' => 40];
        yield 'caseless class of bytes past ASCII under UCP without UTF, 10.44' => ['pattern' => '/(*UCP)[\\xe0-\\xfe]/i', 'release' => '10.44', 'length' => 40];
        yield 'caseless class of bytes past ASCII under UCP without UTF, 10.45' => ['pattern' => '/(*UCP)[\\xe0-\\xfe]/i', 'release' => '10.45', 'length' => 40];
        yield 'caseless class of bytes past ASCII under UCP without UTF, 10.49' => ['pattern' => '/(*UCP)[\\xe0-\\xfe]/i', 'release' => '10.49', 'length' => 40];
        yield 'caseless class of the micro sign under UCP without UTF, 10.32' => ['pattern' => '/(*UCP)[\\xb5x]/i', 'release' => '10.32', 'length' => 40];
        yield 'caseless class of the micro sign under UCP without UTF, 10.44' => ['pattern' => '/(*UCP)[\\xb5x]/i', 'release' => '10.44', 'length' => 40];
        yield 'caseless class of the micro sign under UCP without UTF, 10.45' => ['pattern' => '/(*UCP)[\\xb5x]/i', 'release' => '10.45', 'length' => 40];
        yield 'caseless class of the micro sign under UCP without UTF, 10.49' => ['pattern' => '/(*UCP)[\\xb5x]/i', 'release' => '10.49', 'length' => 40];
        yield 'caseless class of every byte past ASCII but one under UCP without UTF, 10.32' => ['pattern' => '/(*UCP)[\\x80-\\xdf\\xe1-\\xff]/i', 'release' => '10.32', 'length' => 40];
        yield 'caseless class of every byte past ASCII but one under UCP without UTF, 10.44' => ['pattern' => '/(*UCP)[\\x80-\\xdf\\xe1-\\xff]/i', 'release' => '10.44', 'length' => 40];
        yield 'caseless class of every byte past ASCII but one under UCP without UTF, 10.45' => ['pattern' => '/(*UCP)[\\x80-\\xdf\\xe1-\\xff]/i', 'release' => '10.45', 'length' => 40];
        yield 'caseless class of every byte past ASCII but one under UCP without UTF, 10.49' => ['pattern' => '/(*UCP)[\\x80-\\xdf\\xe1-\\xff]/i', 'release' => '10.49', 'length' => 40];
        yield 'caseless class of every byte past ASCII but a letter whose other case it holds, under UCP without UTF, 10.32' => ['pattern' => '/(*UCP)[a\\x80-\\xdf\\xe1-\\xff]/i', 'release' => '10.32', 'length' => 40];
        yield 'caseless class of every byte past ASCII but a letter whose other case it holds, under UCP without UTF, 10.44' => ['pattern' => '/(*UCP)[a\\x80-\\xdf\\xe1-\\xff]/i', 'release' => '10.44', 'length' => 40];
        yield 'caseless class of every byte past ASCII but a letter whose other case it holds, under UCP without UTF, 10.45' => ['pattern' => '/(*UCP)[a\\x80-\\xdf\\xe1-\\xff]/i', 'release' => '10.45', 'length' => 40];
        yield 'caseless class of every byte past ASCII but a letter whose other case it holds, under UCP without UTF, 10.49' => ['pattern' => '/(*UCP)[a\\x80-\\xdf\\xe1-\\xff]/i', 'release' => '10.49', 'length' => 40];
        yield 'counted caseless class of a range past 255, 10.32' => ['pattern' => '/[ā-ž]{2}/iu', 'release' => '10.32', 'length' => 54];
        yield 'counted caseless class of a range past 255, 10.44' => ['pattern' => '/[ā-ž]{2}/iu', 'release' => '10.44', 'length' => 54];
        yield 'counted caseless class of a range past 255, 10.45' => ['pattern' => '/[ā-ž]{2}/iu', 'release' => '10.45', 'length' => 54];
        yield 'counted caseless class of a range past 255, 10.49' => ['pattern' => '/[ā-ž]{2}/iu', 'release' => '10.49', 'length' => 54];
        yield 'caseless class of a range past 255 and a property, 10.32' => ['pattern' => '/[ā-ž\\p{Lu}]/iu', 'release' => '10.32', 'length' => 52];
        yield 'caseless class of a range past 255 and a property, 10.44' => ['pattern' => '/[ā-ž\\p{Lu}]/iu', 'release' => '10.44', 'length' => 52];
        yield 'caseless class of a range past 255 and a property, 10.45' => ['pattern' => '/[ā-ž\\p{Lu}]/iu', 'release' => '10.45', 'length' => 52];
        yield 'caseless class of a range past 255 and a property, 10.49' => ['pattern' => '/[ā-ž\\p{Lu}]/iu', 'release' => '10.49', 'length' => 52];
        yield 'caseless class of a range past 255 and a negated type, 10.32' => ['pattern' => '/[ā-ž\\D]/iu', 'release' => '10.32', 'length' => 52];
        yield 'caseless class of a range past 255 and a negated type, 10.44' => ['pattern' => '/[ā-ž\\D]/iu', 'release' => '10.44', 'length' => 52];
        yield 'caseless class of a range past 255 and a negated type, 10.45' => ['pattern' => '/[ā-ž\\D]/iu', 'release' => '10.45', 'length' => 52];
        yield 'caseless class of a range past 255 and a negated type, 10.49' => ['pattern' => '/[ā-ž\\D]/iu', 'release' => '10.49', 'length' => 52];
        yield 'caseless range of Cherokee small letters, 10.32' => ['pattern' => '/[\\x{13F7}-\\x{13FD}]/iu', 'release' => '10.32', 'length' => 26];
        yield 'caseless range of Cherokee small letters, 10.44' => ['pattern' => '/[\\x{13F7}-\\x{13FD}]/iu', 'release' => '10.44', 'length' => 26];
        yield 'caseless range of Cherokee small letters, 10.45' => ['pattern' => '/[\\x{13F7}-\\x{13FD}]/iu', 'release' => '10.45', 'length' => 26];
        yield 'caseless range of Cherokee small letters, 10.49' => ['pattern' => '/[\\x{13F7}-\\x{13FD}]/iu', 'release' => '10.49', 'length' => 26];
        yield 'caseless class of the long s and a letter, 10.32' => ['pattern' => '/[ſx]/iu', 'release' => '10.32', 'length' => 47];
        yield 'caseless class of the long s and a letter, 10.44' => ['pattern' => '/[ſx]/iu', 'release' => '10.44', 'length' => 47];
        yield 'caseless class of the long s and a letter, 10.45' => ['pattern' => '/[ſx]/iu', 'release' => '10.45', 'length' => 47];
        yield 'caseless class of the long s and a letter, 10.49' => ['pattern' => '/[ſx]/iu', 'release' => '10.49', 'length' => 47];
        yield 'caseless class of the Kelvin sign and a letter, 10.32' => ['pattern' => '/[\\x{212A}x]/iu', 'release' => '10.32', 'length' => 48];
        yield 'caseless class of the Kelvin sign and a letter, 10.44' => ['pattern' => '/[\\x{212A}x]/iu', 'release' => '10.44', 'length' => 48];
        yield 'caseless class of the Kelvin sign and a letter, 10.45' => ['pattern' => '/[\\x{212A}x]/iu', 'release' => '10.45', 'length' => 48];
        yield 'caseless class of the Kelvin sign and a letter, 10.49' => ['pattern' => '/[\\x{212A}x]/iu', 'release' => '10.49', 'length' => 48];
        yield 'caseless class of every byte but one whose other case it holds, under UCP without UTF, 10.32' => ['pattern' => '/(*UCP)[\\x00-\\xdf\\xe1-\\xff]/i', 'release' => '10.32', 'length' => 40];
        yield 'caseless class of every byte but one whose other case it holds, under UCP without UTF, 10.44' => ['pattern' => '/(*UCP)[\\x00-\\xdf\\xe1-\\xff]/i', 'release' => '10.44', 'length' => 40];
        yield 'caseless class of every byte but one whose other case it holds, under UCP without UTF, 10.45' => ['pattern' => '/(*UCP)[\\x00-\\xdf\\xe1-\\xff]/i', 'release' => '10.45', 'length' => 8];
        yield 'caseless class of every byte but one whose other case it holds, under UCP without UTF, 10.49' => ['pattern' => '/(*UCP)[\\x00-\\xdf\\xe1-\\xff]/i', 'release' => '10.49', 'length' => 8];
        yield 'caseless class of every byte but the capitals and one whose other case it holds, under UCP without UTF, 10.32' => ['pattern' => '/(*UCP)[\\x00-\\x40\\x5b-\\xdf\\xe1-\\xff]/i', 'release' => '10.32', 'length' => 40];
        yield 'caseless class of every byte but the capitals and one whose other case it holds, under UCP without UTF, 10.44' => ['pattern' => '/(*UCP)[\\x00-\\x40\\x5b-\\xdf\\xe1-\\xff]/i', 'release' => '10.44', 'length' => 40];
        yield 'caseless class of every byte but the capitals and one whose other case it holds, under UCP without UTF, 10.45' => ['pattern' => '/(*UCP)[\\x00-\\x40\\x5b-\\xdf\\xe1-\\xff]/i', 'release' => '10.45', 'length' => 8];
        yield 'caseless class of every byte but the capitals and one whose other case it holds, under UCP without UTF, 10.49' => ['pattern' => '/(*UCP)[\\x00-\\x40\\x5b-\\xdf\\xe1-\\xff]/i', 'release' => '10.49', 'length' => 8];
        yield 'caseless class past 255, then the same class not caseless, 10.32' => ['pattern' => '/(?i:[ā-ž])[ā-ž]/u', 'release' => '10.32', 'length' => 65];
        yield 'caseless class past 255, then the same class not caseless, 10.44' => ['pattern' => '/(?i:[ā-ž])[ā-ž]/u', 'release' => '10.44', 'length' => 65];
        yield 'caseless class past 255, then the same class not caseless, 10.45' => ['pattern' => '/(?i:[ā-ž])[ā-ž]/u', 'release' => '10.45', 'length' => 65];
        yield 'caseless class past 255, then the same class not caseless, 10.49' => ['pattern' => '/(?i:[ā-ž])[ā-ž]/u', 'release' => '10.49', 'length' => 65];
        yield 'class of i and the last ASCII character, 10.32' => ['pattern' => '/[i\\x7f]/u', 'release' => '10.32', 'length' => 40];
        yield 'class of i and the last ASCII character, 10.44' => ['pattern' => '/[i\\x7f]/u', 'release' => '10.44', 'length' => 40];
        yield 'class of i and the last ASCII character, 10.45' => ['pattern' => '/[i\\x7f]/u', 'release' => '10.45', 'length' => 40];
        yield 'class of i and the last ASCII character, 10.49' => ['pattern' => '/[i\\x7f]/u', 'release' => '10.49', 'length' => 40];
        yield 'class of a letter and a Latin-1 letter, 10.32' => ['pattern' => '/[aé]/u', 'release' => '10.32', 'length' => 40];
        yield 'class of a letter and a Latin-1 letter, 10.44' => ['pattern' => '/[aé]/u', 'release' => '10.44', 'length' => 40];
        yield 'class of a letter and a Latin-1 letter, 10.45' => ['pattern' => '/[aé]/u', 'release' => '10.45', 'length' => 40];
        yield 'class of a letter and a Latin-1 letter, 10.49' => ['pattern' => '/[aé]/u', 'release' => '10.49', 'length' => 40];
        yield 'caseless class of the long s and a character past 255, 10.32' => ['pattern' => '/[ſ\\x{200}]/iu', 'release' => '10.32', 'length' => 52];
        yield 'caseless class of the long s and a character past 255, 10.44' => ['pattern' => '/[ſ\\x{200}]/iu', 'release' => '10.44', 'length' => 52];
        yield 'caseless class of the long s and a character past 255, 10.45' => ['pattern' => '/[ſ\\x{200}]/iu', 'release' => '10.45', 'length' => 52];
        yield 'caseless class of the long s and a character past 255, 10.49' => ['pattern' => '/[ſ\\x{200}]/iu', 'release' => '10.49', 'length' => 52];
        yield 'caseless class of the Kelvin sign and a character past 255, 10.32' => ['pattern' => '/[\\x{212A}\\x{200}]/iu', 'release' => '10.32', 'length' => 53];
        yield 'caseless class of the Kelvin sign and a character past 255, 10.44' => ['pattern' => '/[\\x{212A}\\x{200}]/iu', 'release' => '10.44', 'length' => 53];
        yield 'caseless class of the Kelvin sign and a character past 255, 10.45' => ['pattern' => '/[\\x{212A}\\x{200}]/iu', 'release' => '10.45', 'length' => 53];
        yield 'caseless class of the Kelvin sign and a character past 255, 10.49' => ['pattern' => '/[\\x{212A}\\x{200}]/iu', 'release' => '10.49', 'length' => 53];
    }

    #[Test]
    #[DataProvider('provideLengthsUnderCaseRules')]
    public function test_the_case_rules_in_force_where_an_item_is_written_decide_its_length(string $pattern, string $release, int $length): void
    {
        $this->assertSame($length, self::lengthOf($pattern, $release), \sprintf('%s on PCRE2 %s', $pattern, $release));
    }

    /**
     * Rules kept to ASCII ("r", "(?r)", "(*CASELESS_RESTRICT)", from 10.43)
     * leave "k" and "s" a letter and its other case; Turkish rules
     * ("(*TURKISH_CASING)", from 10.45) give "i" and "I" the dotted capital
     * I and the dotless i for other cases. An inline option holds to the
     * end of its group.
     *
     * @return iterable<string, array{pattern: string, release: string, length: int}>
     */
    public static function provideLengthsUnderCaseRules(): iterable
    {
        yield 'caseless k under the r modifier, 10.43' => ['pattern' => '/k/iur', 'release' => '10.43', 'length' => 9];
        yield 'caseless k under the r modifier, 10.44' => ['pattern' => '/k/iur', 'release' => '10.44', 'length' => 9];
        yield 'caseless k under the r modifier, 10.45' => ['pattern' => '/k/iur', 'release' => '10.45', 'length' => 9];
        yield 'caseless k under the r modifier, 10.49' => ['pattern' => '/k/iur', 'release' => '10.49', 'length' => 9];
        yield 'caseless k under an inline r, 10.43' => ['pattern' => '/(?r)k/iu', 'release' => '10.43', 'length' => 9];
        yield 'caseless k under an inline r, 10.44' => ['pattern' => '/(?r)k/iu', 'release' => '10.44', 'length' => 9];
        yield 'caseless k under an inline r, 10.45' => ['pattern' => '/(?r)k/iu', 'release' => '10.45', 'length' => 9];
        yield 'caseless k under an inline r, 10.49' => ['pattern' => '/(?r)k/iu', 'release' => '10.49', 'length' => 9];
        yield 'caseless capital K under an inline r, 10.43' => ['pattern' => '/(?r)K/iu', 'release' => '10.43', 'length' => 9];
        yield 'caseless capital K under an inline r, 10.44' => ['pattern' => '/(?r)K/iu', 'release' => '10.44', 'length' => 9];
        yield 'caseless capital K under an inline r, 10.45' => ['pattern' => '/(?r)K/iu', 'release' => '10.45', 'length' => 9];
        yield 'caseless capital K under an inline r, 10.49' => ['pattern' => '/(?r)K/iu', 'release' => '10.49', 'length' => 9];
        yield 'caseless s under an inline r, 10.43' => ['pattern' => '/(?r)s/iu', 'release' => '10.43', 'length' => 9];
        yield 'caseless s under an inline r, 10.44' => ['pattern' => '/(?r)s/iu', 'release' => '10.44', 'length' => 9];
        yield 'caseless s under an inline r, 10.45' => ['pattern' => '/(?r)s/iu', 'release' => '10.45', 'length' => 9];
        yield 'caseless s under an inline r, 10.49' => ['pattern' => '/(?r)s/iu', 'release' => '10.49', 'length' => 9];
        yield 'caseless Kelvin sign under an inline r, 10.43' => ['pattern' => '/(?r)\\x{212A}/iu', 'release' => '10.43', 'length' => 11];
        yield 'caseless Kelvin sign under an inline r, 10.44' => ['pattern' => '/(?r)\\x{212A}/iu', 'release' => '10.44', 'length' => 11];
        yield 'caseless Kelvin sign under an inline r, 10.45' => ['pattern' => '/(?r)\\x{212A}/iu', 'release' => '10.45', 'length' => 11];
        yield 'caseless Kelvin sign under an inline r, 10.49' => ['pattern' => '/(?r)\\x{212A}/iu', 'release' => '10.49', 'length' => 11];
        yield 'caseless long s under an inline r, 10.43' => ['pattern' => '/(?r)ſ/iu', 'release' => '10.43', 'length' => 10];
        yield 'caseless long s under an inline r, 10.44' => ['pattern' => '/(?r)ſ/iu', 'release' => '10.44', 'length' => 10];
        yield 'caseless long s under an inline r, 10.45' => ['pattern' => '/(?r)ſ/iu', 'release' => '10.45', 'length' => 10];
        yield 'caseless long s under an inline r, 10.49' => ['pattern' => '/(?r)ſ/iu', 'release' => '10.49', 'length' => 10];
        yield 'caseless a with a ring under an inline r, 10.43' => ['pattern' => '/(?r)å/iu', 'release' => '10.43', 'length' => 10];
        yield 'caseless a with a ring under an inline r, 10.44' => ['pattern' => '/(?r)å/iu', 'release' => '10.44', 'length' => 10];
        yield 'caseless a with a ring under an inline r, 10.45' => ['pattern' => '/(?r)å/iu', 'release' => '10.45', 'length' => 10];
        yield 'caseless a with a ring under an inline r, 10.49' => ['pattern' => '/(?r)å/iu', 'release' => '10.49', 'length' => 10];
        yield 'caseless k inside a group of the r option, then outside it, 10.43' => ['pattern' => '/(?r:k)k/iu', 'release' => '10.43', 'length' => 18];
        yield 'caseless k inside a group of the r option, then outside it, 10.44' => ['pattern' => '/(?r:k)k/iu', 'release' => '10.44', 'length' => 18];
        yield 'caseless k inside a group of the r option, then outside it, 10.45' => ['pattern' => '/(?r:k)k/iu', 'release' => '10.45', 'length' => 18];
        yield 'caseless k inside a group of the r option, then outside it, 10.49' => ['pattern' => '/(?r:k)k/iu', 'release' => '10.49', 'length' => 18];
        yield 'caseless k after an inline r in the branch before, 10.43' => ['pattern' => '/a|(?r)k|k/iu', 'release' => '10.43', 'length' => 19];
        yield 'caseless k after an inline r in the branch before, 10.44' => ['pattern' => '/a|(?r)k|k/iu', 'release' => '10.44', 'length' => 19];
        yield 'caseless k after an inline r in the branch before, 10.45' => ['pattern' => '/a|(?r)k|k/iu', 'release' => '10.45', 'length' => 19];
        yield 'caseless k after an inline r in the branch before, 10.49' => ['pattern' => '/a|(?r)k|k/iu', 'release' => '10.49', 'length' => 19];
        yield 'caseless k after a group that sets the r option, 10.43' => ['pattern' => '/(?:(?r)k)k/iu', 'release' => '10.43', 'length' => 18];
        yield 'caseless k after a group that sets the r option, 10.44' => ['pattern' => '/(?:(?r)k)k/iu', 'release' => '10.44', 'length' => 18];
        yield 'caseless k after a group that sets the r option, 10.45' => ['pattern' => '/(?:(?r)k)k/iu', 'release' => '10.45', 'length' => 18];
        yield 'caseless k after a group that sets the r option, 10.49' => ['pattern' => '/(?:(?r)k)k/iu', 'release' => '10.49', 'length' => 18];
        yield 'caseless k after the options are reset, 10.43' => ['pattern' => '/(?r)(?^i)k/u', 'release' => '10.43', 'length' => 10];
        yield 'caseless k after the options are reset, 10.44' => ['pattern' => '/(?r)(?^i)k/u', 'release' => '10.44', 'length' => 10];
        yield 'caseless k after the options are reset, 10.45' => ['pattern' => '/(?r)(?^i)k/u', 'release' => '10.45', 'length' => 10];
        yield 'caseless k after the options are reset, 10.49' => ['pattern' => '/(?r)(?^i)k/u', 'release' => '10.49', 'length' => 10];
        yield 'caseless k after the r option is turned off, 10.43' => ['pattern' => '/(?r)(?-r)k/iu', 'release' => '10.43', 'length' => 10];
        yield 'caseless k after the r option is turned off, 10.44' => ['pattern' => '/(?r)(?-r)k/iu', 'release' => '10.44', 'length' => 10];
        yield 'caseless k after the r option is turned off, 10.45' => ['pattern' => '/(?r)(?-r)k/iu', 'release' => '10.45', 'length' => 10];
        yield 'caseless k after the r option is turned off, 10.49' => ['pattern' => '/(?r)(?-r)k/iu', 'release' => '10.49', 'length' => 10];
        yield 'negated caseless class of k under an inline r, 10.43' => ['pattern' => '/(?r)[^k]/iu', 'release' => '10.43', 'length' => 9];
        yield 'negated caseless class of k under an inline r, 10.44' => ['pattern' => '/(?r)[^k]/iu', 'release' => '10.44', 'length' => 9];
        yield 'negated caseless class of k under an inline r, 10.45' => ['pattern' => '/(?r)[^k]/iu', 'release' => '10.45', 'length' => 9];
        yield 'negated caseless class of k under an inline r, 10.49' => ['pattern' => '/(?r)[^k]/iu', 'release' => '10.49', 'length' => 9];
        yield 'counted caseless k under the r modifier, 10.43' => ['pattern' => '/k{2}/iur', 'release' => '10.43', 'length' => 11];
        yield 'counted caseless k under the r modifier, 10.44' => ['pattern' => '/k{2}/iur', 'release' => '10.44', 'length' => 11];
        yield 'counted caseless k under the r modifier, 10.45' => ['pattern' => '/k{2}/iur', 'release' => '10.45', 'length' => 11];
        yield 'counted caseless k under the r modifier, 10.49' => ['pattern' => '/k{2}/iur', 'release' => '10.49', 'length' => 11];
        yield 'caseless quoted k and s under the r modifier, 10.43' => ['pattern' => '/\\Qks\\E/iur', 'release' => '10.43', 'length' => 11];
        yield 'caseless quoted k and s under the r modifier, 10.44' => ['pattern' => '/\\Qks\\E/iur', 'release' => '10.44', 'length' => 11];
        yield 'caseless quoted k and s under the r modifier, 10.45' => ['pattern' => '/\\Qks\\E/iur', 'release' => '10.45', 'length' => 11];
        yield 'caseless quoted k and s under the r modifier, 10.49' => ['pattern' => '/\\Qks\\E/iur', 'release' => '10.49', 'length' => 11];
        yield 'class of k and its capital under the r modifier, 10.43' => ['pattern' => '/[kK]/ur', 'release' => '10.43', 'length' => 9];
        yield 'class of k and its capital under the r modifier, 10.44' => ['pattern' => '/[kK]/ur', 'release' => '10.44', 'length' => 9];
        yield 'class of k and its capital under the r modifier, 10.45' => ['pattern' => '/[kK]/ur', 'release' => '10.45', 'length' => 9];
        yield 'class of k and its capital under the r modifier, 10.49' => ['pattern' => '/[kK]/ur', 'release' => '10.49', 'length' => 9];
        yield 'class of K and its small letter under the r modifier, 10.43' => ['pattern' => '/[Kk]/ur', 'release' => '10.43', 'length' => 9];
        yield 'class of K and its small letter under the r modifier, 10.44' => ['pattern' => '/[Kk]/ur', 'release' => '10.44', 'length' => 9];
        yield 'class of K and its small letter under the r modifier, 10.45' => ['pattern' => '/[Kk]/ur', 'release' => '10.45', 'length' => 9];
        yield 'class of K and its small letter under the r modifier, 10.49' => ['pattern' => '/[Kk]/ur', 'release' => '10.49', 'length' => 9];
        yield 'class of s and its capital under the r modifier, 10.43' => ['pattern' => '/[sS]/ur', 'release' => '10.43', 'length' => 9];
        yield 'class of s and its capital under the r modifier, 10.44' => ['pattern' => '/[sS]/ur', 'release' => '10.44', 'length' => 9];
        yield 'class of s and its capital under the r modifier, 10.45' => ['pattern' => '/[sS]/ur', 'release' => '10.45', 'length' => 9];
        yield 'class of s and its capital under the r modifier, 10.49' => ['pattern' => '/[sS]/ur', 'release' => '10.49', 'length' => 9];
        yield 'class of k and the Kelvin sign under the r modifier, 10.43' => ['pattern' => '/[k\\x{212A}]/ur', 'release' => '10.43', 'length' => 48];
        yield 'class of k and the Kelvin sign under the r modifier, 10.44' => ['pattern' => '/[k\\x{212A}]/ur', 'release' => '10.44', 'length' => 48];
        yield 'class of k and the Kelvin sign under the r modifier, 10.45' => ['pattern' => '/[k\\x{212A}]/ur', 'release' => '10.45', 'length' => 48];
        yield 'class of k and the Kelvin sign under the r modifier, 10.49' => ['pattern' => '/[k\\x{212A}]/ur', 'release' => '10.49', 'length' => 48];
        yield 'caseless class of k and s under an inline r, 10.43' => ['pattern' => '/(?r)[ks]/iu', 'release' => '10.43', 'length' => 40];
        yield 'caseless class of k and s under an inline r, 10.44' => ['pattern' => '/(?r)[ks]/iu', 'release' => '10.44', 'length' => 40];
        yield 'caseless class of k and s under an inline r, 10.45' => ['pattern' => '/(?r)[ks]/iu', 'release' => '10.45', 'length' => 40];
        yield 'caseless class of k and s under an inline r, 10.49' => ['pattern' => '/(?r)[ks]/iu', 'release' => '10.49', 'length' => 40];
        yield 'caseless class of k and its capital under the r modifier, 10.43' => ['pattern' => '/[kK]/iur', 'release' => '10.43', 'length' => 9];
        yield 'caseless class of k and its capital under the r modifier, 10.44' => ['pattern' => '/[kK]/iur', 'release' => '10.44', 'length' => 9];
        yield 'caseless class of k and its capital under the r modifier, 10.45' => ['pattern' => '/[kK]/iur', 'release' => '10.45', 'length' => 9];
        yield 'caseless class of k and its capital under the r modifier, 10.49' => ['pattern' => '/[kK]/iur', 'release' => '10.49', 'length' => 9];
        yield 'caseless range under the r modifier, 10.43' => ['pattern' => '/[a-z]/iur', 'release' => '10.43', 'length' => 40];
        yield 'caseless range under the r modifier, 10.44' => ['pattern' => '/[a-z]/iur', 'release' => '10.44', 'length' => 40];
        yield 'caseless range under the r modifier, 10.45' => ['pattern' => '/[a-z]/iur', 'release' => '10.45', 'length' => 40];
        yield 'caseless range under the r modifier, 10.49' => ['pattern' => '/[a-z]/iur', 'release' => '10.49', 'length' => 40];
        yield 'caseless range of letters past 255 under the r modifier, 10.43' => ['pattern' => '/[\\x{17F}-\\x{212A}]/iur', 'release' => '10.43', 'length' => 170];
        yield 'caseless range of letters past 255 under the r modifier, 10.44' => ['pattern' => '/[\\x{17F}-\\x{212A}]/iur', 'release' => '10.44', 'length' => 170];
        yield 'caseless range of letters past 255 under the r modifier, 10.45' => ['pattern' => '/[\\x{17F}-\\x{212A}]/iur', 'release' => '10.45', 'length' => 103];
        yield 'caseless range of letters past 255 under the r modifier, 10.49' => ['pattern' => '/[\\x{17F}-\\x{212A}]/iur', 'release' => '10.49', 'length' => 103];
        yield 'caseless class of the Kelvin sign and a letter under the r modifier, 10.43' => ['pattern' => '/[\\x{212A}x]/iur', 'release' => '10.43', 'length' => 48];
        yield 'caseless class of the Kelvin sign and a letter under the r modifier, 10.44' => ['pattern' => '/[\\x{212A}x]/iur', 'release' => '10.44', 'length' => 48];
        yield 'caseless class of the Kelvin sign and a letter under the r modifier, 10.45' => ['pattern' => '/[\\x{212A}x]/iur', 'release' => '10.45', 'length' => 48];
        yield 'caseless class of the Kelvin sign and a letter under the r modifier, 10.49' => ['pattern' => '/[\\x{212A}x]/iur', 'release' => '10.49', 'length' => 48];
        yield 'caseless class of the long s and a letter under the r modifier, 10.43' => ['pattern' => '/[ſx]/iur', 'release' => '10.43', 'length' => 47];
        yield 'caseless class of the long s and a letter under the r modifier, 10.44' => ['pattern' => '/[ſx]/iur', 'release' => '10.44', 'length' => 47];
        yield 'caseless class of the long s and a letter under the r modifier, 10.45' => ['pattern' => '/[ſx]/iur', 'release' => '10.45', 'length' => 47];
        yield 'caseless class of the long s and a letter under the r modifier, 10.49' => ['pattern' => '/[ſx]/iur', 'release' => '10.49', 'length' => 47];
        yield 'caseless class of the capital a with a ring and a letter under the r modifier, 10.43' => ['pattern' => '/[Åx]/iur', 'release' => '10.43', 'length' => 48];
        yield 'caseless class of the capital a with a ring and a letter under the r modifier, 10.44' => ['pattern' => '/[Åx]/iur', 'release' => '10.44', 'length' => 48];
        yield 'caseless class of the capital a with a ring and a letter under the r modifier, 10.45' => ['pattern' => '/[Åx]/iur', 'release' => '10.45', 'length' => 48];
        yield 'caseless class of the capital a with a ring and a letter under the r modifier, 10.49' => ['pattern' => '/[Åx]/iur', 'release' => '10.49', 'length' => 48];
        yield 'caseless class of k and a letter under UCP without UTF and the r modifier, 10.43' => ['pattern' => '/(*UCP)[kx]/ir', 'release' => '10.43', 'length' => 40];
        yield 'caseless class of k and a letter under UCP without UTF and the r modifier, 10.44' => ['pattern' => '/(*UCP)[kx]/ir', 'release' => '10.44', 'length' => 40];
        yield 'caseless class of k and a letter under UCP without UTF and the r modifier, 10.45' => ['pattern' => '/(*UCP)[kx]/ir', 'release' => '10.45', 'length' => 40];
        yield 'caseless class of k and a letter under UCP without UTF and the r modifier, 10.49' => ['pattern' => '/(*UCP)[kx]/ir', 'release' => '10.49', 'length' => 40];
        yield 'caseless k under rules kept to ASCII, 10.45' => ['pattern' => '/(*CASELESS_RESTRICT)k/iu', 'release' => '10.45', 'length' => 9];
        yield 'caseless k under rules kept to ASCII, 10.49' => ['pattern' => '/(*CASELESS_RESTRICT)k/iu', 'release' => '10.49', 'length' => 9];
        yield 'caseless capital I under rules kept to ASCII, 10.45' => ['pattern' => '/(*CASELESS_RESTRICT)I/iu', 'release' => '10.45', 'length' => 9];
        yield 'caseless capital I under rules kept to ASCII, 10.49' => ['pattern' => '/(*CASELESS_RESTRICT)I/iu', 'release' => '10.49', 'length' => 9];
        yield 'caseless dotted capital I under rules kept to ASCII, 10.45' => ['pattern' => '/(*CASELESS_RESTRICT)\\x{130}/iu', 'release' => '10.45', 'length' => 10];
        yield 'caseless dotted capital I under rules kept to ASCII, 10.49' => ['pattern' => '/(*CASELESS_RESTRICT)\\x{130}/iu', 'release' => '10.49', 'length' => 10];
        yield 'caseless dotless i under rules kept to ASCII, 10.45' => ['pattern' => '/(*CASELESS_RESTRICT)\\x{131}/iu', 'release' => '10.45', 'length' => 10];
        yield 'caseless dotless i under rules kept to ASCII, 10.49' => ['pattern' => '/(*CASELESS_RESTRICT)\\x{131}/iu', 'release' => '10.49', 'length' => 10];
        yield 'caseless Kelvin sign counted two to three times under rules kept to ASCII, 10.45' => ['pattern' => '/(*CASELESS_RESTRICT)\\x{212A}{2,3}/iu', 'release' => '10.45', 'length' => 17];
        yield 'caseless Kelvin sign counted two to three times under rules kept to ASCII, 10.49' => ['pattern' => '/(*CASELESS_RESTRICT)\\x{212A}{2,3}/iu', 'release' => '10.49', 'length' => 17];
        yield 'optional caseless Kelvin sign under rules kept to ASCII, 10.45' => ['pattern' => '/(*CASELESS_RESTRICT)\\x{212A}?/iu', 'release' => '10.45', 'length' => 11];
        yield 'optional caseless Kelvin sign under rules kept to ASCII, 10.49' => ['pattern' => '/(*CASELESS_RESTRICT)\\x{212A}?/iu', 'release' => '10.49', 'length' => 11];
        yield 'caseless k counted one to two times under rules kept to ASCII, 10.45' => ['pattern' => '/(*CASELESS_RESTRICT)k{1,2}/iu', 'release' => '10.45', 'length' => 13];
        yield 'caseless k counted one to two times under rules kept to ASCII, 10.49' => ['pattern' => '/(*CASELESS_RESTRICT)k{1,2}/iu', 'release' => '10.49', 'length' => 13];
        yield 'caseless k under UCP without UTF, rules kept to ASCII, 10.45' => ['pattern' => '/(*UCP)(*CASELESS_RESTRICT)k/i', 'release' => '10.45', 'length' => 9];
        yield 'caseless k under UCP without UTF, rules kept to ASCII, 10.49' => ['pattern' => '/(*UCP)(*CASELESS_RESTRICT)k/i', 'release' => '10.49', 'length' => 9];
        yield 'class of k and its capital under rules kept to ASCII, 10.45' => ['pattern' => '/(*CASELESS_RESTRICT)[kK]/u', 'release' => '10.45', 'length' => 9];
        yield 'class of k and its capital under rules kept to ASCII, 10.49' => ['pattern' => '/(*CASELESS_RESTRICT)[kK]/u', 'release' => '10.49', 'length' => 9];
        yield 'caseless i under Turkish rules, 10.45' => ['pattern' => '/(*TURKISH_CASING)i/iu', 'release' => '10.45', 'length' => 10];
        yield 'caseless i under Turkish rules, 10.49' => ['pattern' => '/(*TURKISH_CASING)i/iu', 'release' => '10.49', 'length' => 10];
        yield 'caseless capital I under Turkish rules, 10.45' => ['pattern' => '/(*TURKISH_CASING)I/iu', 'release' => '10.45', 'length' => 10];
        yield 'caseless capital I under Turkish rules, 10.49' => ['pattern' => '/(*TURKISH_CASING)I/iu', 'release' => '10.49', 'length' => 10];
        yield 'caseless dotted capital I under Turkish rules, 10.45' => ['pattern' => '/(*TURKISH_CASING)\\x{130}/iu', 'release' => '10.45', 'length' => 10];
        yield 'caseless dotted capital I under Turkish rules, 10.49' => ['pattern' => '/(*TURKISH_CASING)\\x{130}/iu', 'release' => '10.49', 'length' => 10];
        yield 'caseless dotless i under Turkish rules, 10.45' => ['pattern' => '/(*TURKISH_CASING)\\x{131}/iu', 'release' => '10.45', 'length' => 10];
        yield 'caseless dotless i under Turkish rules, 10.49' => ['pattern' => '/(*TURKISH_CASING)\\x{131}/iu', 'release' => '10.49', 'length' => 10];
        yield 'counted caseless i under Turkish rules, 10.45' => ['pattern' => '/(*TURKISH_CASING)i{2}/iu', 'release' => '10.45', 'length' => 13];
        yield 'counted caseless i under Turkish rules, 10.49' => ['pattern' => '/(*TURKISH_CASING)i{2}/iu', 'release' => '10.49', 'length' => 13];
        yield 'caseless k under Turkish rules, 10.45' => ['pattern' => '/(*TURKISH_CASING)k/iu', 'release' => '10.45', 'length' => 10];
        yield 'caseless k under Turkish rules, 10.49' => ['pattern' => '/(*TURKISH_CASING)k/iu', 'release' => '10.49', 'length' => 10];
        yield 'caseless i under Turkish rules and an inline r, 10.45' => ['pattern' => '/(*TURKISH_CASING)(?r)i/iu', 'release' => '10.45', 'length' => 9];
        yield 'caseless i under Turkish rules and an inline r, 10.49' => ['pattern' => '/(*TURKISH_CASING)(?r)i/iu', 'release' => '10.49', 'length' => 9];
        yield 'negated caseless class of i under Turkish rules, 10.45' => ['pattern' => '/(*TURKISH_CASING)[^i]/iu', 'release' => '10.45', 'length' => 10];
        yield 'negated caseless class of i under Turkish rules, 10.49' => ['pattern' => '/(*TURKISH_CASING)[^i]/iu', 'release' => '10.49', 'length' => 10];
        yield 'class of i and its capital under Turkish rules, 10.45' => ['pattern' => '/(*TURKISH_CASING)[iI]/u', 'release' => '10.45', 'length' => 40];
        yield 'class of i and its capital under Turkish rules, 10.49' => ['pattern' => '/(*TURKISH_CASING)[iI]/u', 'release' => '10.49', 'length' => 40];
        yield 'class of I and its small letter under Turkish rules, 10.45' => ['pattern' => '/(*TURKISH_CASING)[Ii]/u', 'release' => '10.45', 'length' => 40];
        yield 'class of I and its small letter under Turkish rules, 10.49' => ['pattern' => '/(*TURKISH_CASING)[Ii]/u', 'release' => '10.49', 'length' => 40];
        yield 'class of a letter and its capital under Turkish rules, 10.45' => ['pattern' => '/(*TURKISH_CASING)[aA]/u', 'release' => '10.45', 'length' => 9];
        yield 'class of a letter and its capital under Turkish rules, 10.49' => ['pattern' => '/(*TURKISH_CASING)[aA]/u', 'release' => '10.49', 'length' => 9];
        yield 'class of i and the dotted capital I under Turkish rules, 10.45' => ['pattern' => '/(*TURKISH_CASING)[i\\x{130}]/u', 'release' => '10.45', 'length' => 47];
        yield 'class of i and the dotted capital I under Turkish rules, 10.49' => ['pattern' => '/(*TURKISH_CASING)[i\\x{130}]/u', 'release' => '10.49', 'length' => 47];
        yield 'class of the dotless i and I under Turkish rules, 10.45' => ['pattern' => '/(*TURKISH_CASING)[\\x{131}I]/u', 'release' => '10.45', 'length' => 47];
        yield 'class of the dotless i and I under Turkish rules, 10.49' => ['pattern' => '/(*TURKISH_CASING)[\\x{131}I]/u', 'release' => '10.49', 'length' => 47];
        yield 'caseless class of i and its capital under Turkish rules, 10.45' => ['pattern' => '/(*TURKISH_CASING)[iI]/iu', 'release' => '10.45', 'length' => 49];
        yield 'caseless class of i and its capital under Turkish rules, 10.49' => ['pattern' => '/(*TURKISH_CASING)[iI]/iu', 'release' => '10.49', 'length' => 49];
        yield 'caseless range under Turkish rules, 10.45' => ['pattern' => '/(*TURKISH_CASING)[a-z]/iu', 'release' => '10.45', 'length' => 54];
        yield 'caseless range under Turkish rules, 10.49' => ['pattern' => '/(*TURKISH_CASING)[a-z]/iu', 'release' => '10.49', 'length' => 54];
        yield 'caseless range of h to j under Turkish rules, 10.45' => ['pattern' => '/(*TURKISH_CASING)[h-j]/iu', 'release' => '10.45', 'length' => 47];
        yield 'caseless range of h to j under Turkish rules, 10.49' => ['pattern' => '/(*TURKISH_CASING)[h-j]/iu', 'release' => '10.49', 'length' => 47];
        yield 'caseless range around the dotted capital I under Turkish rules, 10.45' => ['pattern' => '/(*TURKISH_CASING)[\\x{12F}-\\x{132}]/iu', 'release' => '10.45', 'length' => 49];
        yield 'caseless range around the dotted capital I under Turkish rules, 10.49' => ['pattern' => '/(*TURKISH_CASING)[\\x{12F}-\\x{132}]/iu', 'release' => '10.49', 'length' => 49];
        yield 'caseless class of i and a letter under Turkish rules and an inline r, 10.45' => ['pattern' => '/(*TURKISH_CASING)(?r)[ix]/iu', 'release' => '10.45', 'length' => 40];
        yield 'caseless class of i and a letter under Turkish rules and an inline r, 10.49' => ['pattern' => '/(*TURKISH_CASING)(?r)[ix]/iu', 'release' => '10.49', 'length' => 40];
        yield 'caseless class of the dotless i and a letter under Turkish rules, 10.45' => ['pattern' => '/(*TURKISH_CASING)[\\x{131}x]/iu', 'release' => '10.45', 'length' => 47];
        yield 'caseless class of the dotless i and a letter under Turkish rules, 10.49' => ['pattern' => '/(*TURKISH_CASING)[\\x{131}x]/iu', 'release' => '10.49', 'length' => 47];
        yield 'caseless Kelvin sign under rules kept to ASCII, 10.48' => ['pattern' => '/(*CASELESS_RESTRICT)\\x{212A}/iu', 'release' => '10.48', 'length' => 11];
        yield 'counted caseless Kelvin sign under rules kept to ASCII, 10.48' => ['pattern' => '/(*CASELESS_RESTRICT)\\x{212A}{2}/iu', 'release' => '10.48', 'length' => 13];
        yield 'caseless Kelvin sign counted zero times under rules kept to ASCII, 10.48' => ['pattern' => '/(*CASELESS_RESTRICT)\\x{212A}{0}/iu', 'release' => '10.48', 'length' => 11];
        yield 'caseless Kelvin sign counted once under rules kept to ASCII, 10.48' => ['pattern' => '/(*CASELESS_RESTRICT)\\x{212A}{1}/iu', 'release' => '10.48', 'length' => 11];
        yield 'caseless Kelvin sign counted one to two times under rules kept to ASCII, 10.48' => ['pattern' => '/(*CASELESS_RESTRICT)\\x{212A}{1,2}/iu', 'release' => '10.48', 'length' => 17];
        yield 'counted caseless k under rules kept to ASCII, 10.48' => ['pattern' => '/(*CASELESS_RESTRICT)k{2}/iu', 'release' => '10.48', 'length' => 11];
        yield 'caseless k counted once under rules kept to ASCII, 10.48' => ['pattern' => '/(*CASELESS_RESTRICT)k{1}/iu', 'release' => '10.48', 'length' => 9];
        yield 'caseless k counted zero times under rules kept to ASCII, 10.48' => ['pattern' => '/(*CASELESS_RESTRICT)k{0}/iu', 'release' => '10.48', 'length' => 9];
        yield 'caseless k counted once or more under rules kept to ASCII, 10.48' => ['pattern' => '/(*CASELESS_RESTRICT)k+/iu', 'release' => '10.48', 'length' => 9];
        yield 'counted caseless k under UCP without UTF, rules kept to ASCII, 10.48' => ['pattern' => '/(*UCP)(*CASELESS_RESTRICT)k{2}/i', 'release' => '10.48', 'length' => 11];
        yield 'quoted caseless letters under rules kept to ASCII, 10.48' => ['pattern' => '/(*CASELESS_RESTRICT)\\Qks\\E/iu', 'release' => '10.48', 'length' => 11];
        yield 'caseless i under rules kept to ASCII, 10.48' => ['pattern' => '/(*CASELESS_RESTRICT)i/iu', 'release' => '10.48', 'length' => 9];
        yield 'counted caseless capital I under rules kept to ASCII, 10.48' => ['pattern' => '/(*CASELESS_RESTRICT)I{2}/iu', 'release' => '10.48', 'length' => 11];
        yield 'caseless range from the long s under the r modifier, 10.43' => ['pattern' => '/[\\x{17F}-\\x{180}]/iur', 'release' => '10.43', 'length' => 20];
        yield 'caseless range from the long s under the r modifier, 10.44' => ['pattern' => '/[\\x{17F}-\\x{180}]/iur', 'release' => '10.44', 'length' => 20];
        yield 'caseless range from the long s under the r modifier, 10.45' => ['pattern' => '/[\\x{17F}-\\x{180}]/iur', 'release' => '10.45', 'length' => 20];
        yield 'caseless range from the long s under the r modifier, 10.49' => ['pattern' => '/[\\x{17F}-\\x{180}]/iur', 'release' => '10.49', 'length' => 20];
        yield 'counted caseless dotted capital I under Turkish rules, 10.45' => ['pattern' => '/(*TURKISH_CASING)\\x{130}{2}/iu', 'release' => '10.45', 'length' => 13];
        yield 'counted caseless dotted capital I under Turkish rules, 10.49' => ['pattern' => '/(*TURKISH_CASING)\\x{130}{2}/iu', 'release' => '10.49', 'length' => 13];
        yield 'counted caseless dotless i under Turkish rules, 10.45' => ['pattern' => '/(*TURKISH_CASING)\\x{131}{2}/iu', 'release' => '10.45', 'length' => 13];
        yield 'counted caseless dotless i under Turkish rules, 10.49' => ['pattern' => '/(*TURKISH_CASING)\\x{131}{2}/iu', 'release' => '10.49', 'length' => 13];
        yield 'caseless capital S under an inline r, 10.43' => ['pattern' => '/(?r)S/iu', 'release' => '10.43', 'length' => 9];
        yield 'caseless capital S under an inline r, 10.44' => ['pattern' => '/(?r)S/iu', 'release' => '10.44', 'length' => 9];
        yield 'caseless capital S under an inline r, 10.45' => ['pattern' => '/(?r)S/iu', 'release' => '10.45', 'length' => 9];
        yield 'caseless capital S under an inline r, 10.49' => ['pattern' => '/(?r)S/iu', 'release' => '10.49', 'length' => 9];
        yield 'counted caseless long s under an inline r, 10.43' => ['pattern' => '/(?r)ſ{2}/iu', 'release' => '10.43', 'length' => 12];
        yield 'counted caseless long s under an inline r, 10.44' => ['pattern' => '/(?r)ſ{2}/iu', 'release' => '10.44', 'length' => 12];
        yield 'counted caseless long s under an inline r, 10.45' => ['pattern' => '/(?r)ſ{2}/iu', 'release' => '10.45', 'length' => 12];
        yield 'counted caseless long s under an inline r, 10.49' => ['pattern' => '/(?r)ſ{2}/iu', 'release' => '10.49', 'length' => 12];
        yield 'caseless class of the dotted capital I and a character past 255 under Turkish rules, 10.45' => ['pattern' => '/(*TURKISH_CASING)[\\x{130}\\x{200}]/iu', 'release' => '10.45', 'length' => 52];
        yield 'caseless class of the dotted capital I and a character past 255 under Turkish rules, 10.49' => ['pattern' => '/(*TURKISH_CASING)[\\x{130}\\x{200}]/iu', 'release' => '10.49', 'length' => 52];
        yield 'caseless class of the dotless i and a character past 255 under Turkish rules, 10.45' => ['pattern' => '/(*TURKISH_CASING)[\\x{131}\\x{200}]/iu', 'release' => '10.45', 'length' => 52];
        yield 'caseless class of the dotless i and a character past 255 under Turkish rules, 10.49' => ['pattern' => '/(*TURKISH_CASING)[\\x{131}\\x{200}]/iu', 'release' => '10.49', 'length' => 52];
    }

    #[Test]
    #[DataProvider('provideLengthsNotKnown')]
    public function test_a_pair_whose_cases_follow_the_locale_has_no_length(string $pattern): void
    {
        $this->assertNull(self::lengthOf($pattern, '10.49'), $pattern);
    }

    /**
     * The other case "i" takes in the tables PHP builds from a Turkish
     * locale is a byte past ASCII: whether "i" and such a byte are a pair is
     * not known here, one past 255 never is.
     *
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideLengthsNotKnown(): iterable
    {
        yield 'class of i and the capital y with an acute' => ['pattern' => '/[iÝ]/u'];
        yield 'class of i and the last byte' => ['pattern' => '/[iÿ]/u'];
    }

    #[Test]
    #[DataProvider('provideTooLargePatterns')]
    public function test_a_pattern_too_large_for_its_cases_is_refused(string $pattern, string $php, string $release, int $offset): void
    {
        $result = self::validate($pattern, $php, $release);

        $this->assertSame(ErrorCode::PatternTooLarge, $result->errorCode, \sprintf('%s on PCRE2 %s: %s', $pattern, $release, (string) $result->error));
        $this->assertSame($offset, $result->offset, \sprintf('%s on PCRE2 %s', $pattern, $release));
    }

    /**
     * Each the first count pcre2test refuses on that release, the error at
     * the offset it reports.
     *
     * @return iterable<string, array{pattern: string, php: string, release: string, offset: int}>
     */
    public static function provideTooLargePatterns(): iterable
    {
        yield 'repeated caseless class of Latin-1 letters and letters past 255, 10.32' => ['pattern' => '/(?:[à-ž]x){1214}/iu', 'php' => '7.3', 'release' => '10.32', 'offset' => 18];
        yield 'repeated caseless class of Latin-1 letters and letters past 255, 10.44' => ['pattern' => '/(?:[à-ž]x){1214}/iu', 'php' => '8.4', 'release' => '10.44', 'offset' => 18];
        yield 'repeated caseless class of Latin-1 letters and letters past 255, 10.47' => ['pattern' => '/(?:[à-ž]x){1214}/iu', 'php' => '8.5', 'release' => '10.47', 'offset' => 0];
        yield 'repeated caseless class of Latin-1 letters and letters past 255, 10.49' => ['pattern' => '/(?:[à-ž]x){1214}/iu', 'php' => '8.5', 'release' => '10.49', 'offset' => 0];
        yield 'repeated caseless class of a long range past 255, 10.34' => ['pattern' => '/(?:[\\x{100}-\\x{400}]){437}/iu', 'php' => '7.4', 'release' => '10.34', 'offset' => 26];
        yield 'repeated class of a letter past 255 and its other case, 10.33' => ['pattern' => '/(?:[āĀ]){3855}/u', 'php' => '7.4', 'release' => '10.33', 'offset' => 16];
        yield 'repeated class of the Cyrillic capital tje and its small letter, 10.44' => ['pattern' => '/(?:[\\x{1C89}\\x{1C8A}]){3449}/u', 'php' => '8.4', 'release' => '10.44', 'offset' => 28];
        yield 'repeated class of the capital pharyngeal voiced fricative and its small letter, 10.48' => ['pattern' => '/(?:[\\x{A7CE}\\x{A7CF}]){6553}/u', 'php' => '8.5', 'release' => '10.48', 'offset' => 28];
        yield 'repeated class of two Latin-1 letters of other cases under UCP without UTF, 10.35' => ['pattern' => '/(*UCP)(?:[\\xe0\\xc1]){1681}/', 'php' => '8.0', 'release' => '10.35', 'offset' => 26];
        yield 'repeated class of two Latin-1 letters of other cases under UCP without UTF, 10.49' => ['pattern' => '/(*UCP)(?:[\\xe0\\xc1]){1681}/', 'php' => '8.5', 'release' => '10.49', 'offset' => 0];
        yield 'repeated caseless k, 10.43' => ['pattern' => '/(?:k){7282}/iu', 'php' => '8.3', 'release' => '10.43', 'offset' => 11];
        yield 'repeated caseless k under the r modifier, 10.43' => ['pattern' => '/(?:k){8192}/iur', 'php' => '8.4', 'release' => '10.43', 'offset' => 11];
        yield 'repeated caseless k under the r modifier, 10.49' => ['pattern' => '/(?:k){8192}/iur', 'php' => '8.5', 'release' => '10.49', 'offset' => 0];
        yield 'repeated caseless Kelvin sign under an inline r, 10.43' => ['pattern' => '/(?r)(?:\\x{212A}){6553}/iu', 'php' => '8.3', 'release' => '10.43', 'offset' => 22];
        yield 'repeated caseless Kelvin sign under an inline r, 10.49' => ['pattern' => '/(?r)(?:\\x{212A}){6553}/iu', 'php' => '8.5', 'release' => '10.49', 'offset' => 22];
        yield 'repeated caseless i under Turkish rules, 10.45' => ['pattern' => '/(*TURKISH_CASING)(?:i){7282}/iu', 'php' => '8.4', 'release' => '10.45', 'offset' => 28];
        yield 'repeated caseless i under Turkish rules, 10.49' => ['pattern' => '/(*TURKISH_CASING)(?:i){7282}/iu', 'php' => '8.5', 'release' => '10.49', 'offset' => 0];
    }

    #[Test]
    #[DataProvider('provideCompilingPatterns')]
    public function test_a_pattern_that_compiles_with_its_cases_is_valid(string $pattern, string $php, string $release): void
    {
        $result = self::validate($pattern, $php, $release);

        $this->assertTrue($result->isValid, \sprintf('%s on PCRE2 %s: %s', $pattern, $release, (string) $result->error));
    }

    /**
     * Each the last count pcre2test compiles on that release, or a count
     * another release refuses.
     *
     * @return iterable<string, array{pattern: string, php: string, release: string}>
     */
    public static function provideCompilingPatterns(): iterable
    {
        yield 'repeated caseless class of Latin-1 letters and letters past 255, 10.32' => ['pattern' => '/(?:[à-ž]x){1213}/iu', 'php' => '7.3', 'release' => '10.32'];
        yield 'repeated caseless class of Latin-1 letters and letters past 255, 10.49' => ['pattern' => '/(?:[à-ž]x){1213}/iu', 'php' => '8.5', 'release' => '10.49'];
        yield 'repeated caseless class of a long range past 255, 10.33' => ['pattern' => '/(?:[\\x{100}-\\x{400}]){437}/iu', 'php' => '7.4', 'release' => '10.33'];
        yield 'repeated caseless class of a long range past 255, 10.34' => ['pattern' => '/(?:[\\x{100}-\\x{400}]){436}/iu', 'php' => '7.4', 'release' => '10.34'];
        yield 'repeated caseless class of a long range past 255, 10.45' => ['pattern' => '/(?:[\\x{100}-\\x{400}]){1423}/iu', 'php' => '8.4', 'release' => '10.45'];
        yield 'repeated class of a letter past 255 and its other case, 10.34' => ['pattern' => '/(?:[āĀ]){3855}/u', 'php' => '7.4', 'release' => '10.34'];
        yield 'repeated class of the Cyrillic capital tje and its small letter, 10.45' => ['pattern' => '/(?:[\\x{1C89}\\x{1C8A}]){3449}/u', 'php' => '8.4', 'release' => '10.45'];
        // The fewest units a pair of cases compiles to follow the tables of
        // the release too, not the running PHP's alone.
        yield 'repeated class of the capital pharyngeal voiced fricative and its small letter, 10.48' => ['pattern' => '/(?:[\\x{A7CE}\\x{A7CF}]){6552}/u', 'php' => '8.5', 'release' => '10.48'];
        yield 'repeated class of a Latin-1 letter and its other case under UCP without UTF, 10.35' => ['pattern' => '/(*UCP)(?:[\\xe0\\xc0]){1681}/', 'php' => '8.0', 'release' => '10.35'];
        yield 'repeated class of two Latin-1 letters of other cases under UCP without UTF, 10.35' => ['pattern' => '/(*UCP)(?:[\\xe0\\xc1]){1680}/', 'php' => '8.0', 'release' => '10.35'];
        yield 'repeated caseless k under the r modifier, 10.43' => ['pattern' => '/(?:k){8191}/iur', 'php' => '8.4', 'release' => '10.43'];
        yield 'repeated caseless k under an inline r, 10.43' => ['pattern' => '/(?r)(?:k){7282}/iu', 'php' => '8.3', 'release' => '10.43'];
        yield 'repeated caseless Kelvin sign under an inline r, 10.43' => ['pattern' => '/(?r)(?:\\x{212A}){6552}/iu', 'php' => '8.3', 'release' => '10.43'];
        yield 'repeated caseless i, 10.45' => ['pattern' => '/(?:i){7282}/iu', 'php' => '8.4', 'release' => '10.45'];
        yield 'repeated caseless i under Turkish rules, 10.45' => ['pattern' => '/(*TURKISH_CASING)(?:i){7281}/iu', 'php' => '8.4', 'release' => '10.45'];
    }

    /**
     * The most the validator lets a pattern compile to before it measures
     * it item by item is never below the length now known for a caseless
     * item past ASCII or under case rules, nor the fewest units it proves
     * above it.
     */
    #[Test]
    #[DataProvider('provideReleasesAndOptions')]
    public function test_the_ceiling_and_the_floor_hold_the_length_of_caseless_items(string $release, string $opening, string $modifiers): void
    {
        mt_srand(20261020);
        $parser = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => $release, 'runtime_pcre_validation' => false]);
        $target = new PcreTarget(80400, $release);
        $ceiling = new \ReflectionMethod(Validator::class, 'compiledSizeCeiling');
        $floor = new \ReflectionMethod(Validator::class, 'compiledSizeFloor');
        $prepare = new \ReflectionMethod(Validator::class, 'mayBeTooLarge');
        $pieces = ['i', 'I', 'k', 's', 'K', 'ſ', 'İ', 'ı', 'é', 'ā', 'Ā', '\x{1C89}', '[ā-ž]', '[āĀ]', '[iI]', '[kK]', '[à-ž]', '[\x{100}-\x{400}]', '[Ǆ-ǌ]', '[ĀĂ]', '[^ā]', '[ix]', '[\x{130}-\x{131}]', '(?r)', '(?-r)', '(?r:k)', '(?i)', '(?^)', '(?:ks)', '\Qki\E'];
        $counts = ['', '', '*', '{2}', '{0,3}', '+?'];
        $measured = 0;

        for ($i = 0; $i < 200; $i++) {
            $body = $opening;
            for ($j = mt_rand(1, 10); $j > 0; $j--) {
                $piece = $pieces[mt_rand(0, \count($pieces) - 1)];
                $body .= $piece.(str_starts_with($piece, '(?') && !str_contains($piece, ':') ? '' : $counts[mt_rand(0, \count($counts) - 1)]);
            }

            if (!$parser->validate('/'.$body.'/'.$modifiers)->isValid) {
                continue;
            }

            $ast = $parser->parse('/'.$body.'/'.$modifiers);
            $length = (new CompiledLength(static fn (): int => 0, $body, true, true, $modifiers, $target))->of($ast->pattern);
            if (null === $length) {
                continue;
            }

            $validator = new Validator(255, $body, $target);
            $prepare->invoke($validator, $ast);
            $most = $ceiling->invoke($validator, $ast->pattern);
            $this->assertIsInt($most);
            $this->assertGreaterThanOrEqual($length, $most + 7, $body.' on PCRE2 '.$release);
            $validator = new Validator(255, $body, $target);
            $ast->accept($validator);
            $least = $floor->invoke($validator, $ast->pattern);
            $this->assertIsInt($least);
            $this->assertLessThanOrEqual($length, $least + 7, $body.' on PCRE2 '.$release);
            $measured++;
        }

        $this->assertGreaterThan(60, $measured);
    }

    /**
     * @return iterable<string, array{release: string, opening: string, modifiers: string}>
     */
    public static function provideReleasesAndOptions(): iterable
    {
        yield 'caseless, 10.34' => ['release' => '10.34', 'opening' => '', 'modifiers' => 'iu'];
        yield 'caseless, 10.44' => ['release' => '10.44', 'opening' => '', 'modifiers' => 'iu'];
        yield 'caseless, 10.48' => ['release' => '10.48', 'opening' => '', 'modifiers' => 'iu'];
        yield 'rules kept to ASCII, 10.43' => ['release' => '10.43', 'opening' => '', 'modifiers' => 'iur'];
        yield 'rules kept to ASCII, 10.45' => ['release' => '10.45', 'opening' => '(*CASELESS_RESTRICT)', 'modifiers' => 'iu'];
        yield 'Turkish rules, 10.45' => ['release' => '10.45', 'opening' => '(*TURKISH_CASING)', 'modifiers' => 'iu'];
        yield 'Turkish rules, 10.49' => ['release' => '10.49', 'opening' => '(*TURKISH_CASING)', 'modifiers' => 'u'];
    }

    private static function lengthOf(string $pattern, string $release): ?int
    {
        $regex = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => $release, 'runtime_pcre_validation' => false])->parse($pattern);
        $source = (string) $regex->source;
        $utf = str_contains($regex->flags, 'u') || StartOptions::turnUtfOn($source);
        $ucp = str_contains($regex->flags, 'u') || \in_array('UCP', StartOptions::items($source), true);

        return (new CompiledLength(static fn (): int => 0, $source, $utf, $ucp, $regex->flags, new PcreTarget(80400, $release)))->of($regex->pattern);
    }

    private static function validate(string $pattern, string $php, string $release): ValidationResult
    {
        return RegexParser::create(['cache' => null, 'php_version' => $php, 'pcre_version' => $release, 'runtime_pcre_validation' => false])->validate($pattern);
    }
}
