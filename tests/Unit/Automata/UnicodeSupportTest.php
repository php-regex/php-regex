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

namespace PHPRegex\Tests\Unit\Automata;

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Unicode\CodePointHelper;
use PHPRegex\Parser\Exception\LexerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UnicodeSupportTest extends TestCase
{
    #[Test]
    public function test_emoji_range_intersection(): void
    {
        $solver = new LanguageSolver();
        $options = $this->fullMatchOptions();

        $result = $solver->intersection('/[🥵-🥶]/u', '/[🥳-🥶]/u', $options);

        $this->assertFalse($result->isEmpty);
        $this->assertNotNull($result->example);
        $this->assertMatchesRegularExpression('/[🥵-🥶]/u', $result->example ?? '');
        $this->assertMatchesRegularExpression('/[🥳-🥶]/u', $result->example ?? '');
    }

    #[Test]
    public function test_dot_matches_single_code_point_in_unicode(): void
    {
        if (!\function_exists('mb_strlen')) {
            $this->markTestSkipped('mbstring is required for unicode length assertions.');
        }

        $solver = new LanguageSolver();
        $options = $this->fullMatchOptions();

        $result = $solver->intersection('/./u', '/🙂/u', $options);

        $this->assertFalse($result->isEmpty);
        $this->assertNotNull($result->example);
        $this->assertSame(1, \mb_strlen($result->example ?? '', 'UTF-8'));
        $this->assertMatchesRegularExpression('/./u', $result->example ?? '');
    }

    #[Test]
    public function test_unicode_word_class_matches_arabic(): void
    {
        $solver = new LanguageSolver();
        $options = $this->fullMatchOptions();

        $result = $solver->intersection('/\\w+/u', '/مرحبا/u', $options);

        $this->assertFalse($result->isEmpty);
        $this->assertSame('مرحبا', $result->example);
    }

    #[Test]
    public function test_unicode_boundary_code_point(): void
    {
        $solver = new LanguageSolver();
        $options = $this->fullMatchOptions();

        $boundaryChar = CodePointHelper::toString(0x10FFFF);
        $this->assertNotNull($boundaryChar);

        $result = $solver->intersection('/\\x{10FFFF}/u', '/./u', $options);

        $this->assertFalse($result->isEmpty);
        $this->assertSame($boundaryChar, $result->example);
    }

    #[Test]
    public function test_invalid_utf8_literal_is_rejected(): void
    {
        $solver = new LanguageSolver();
        $options = $this->fullMatchOptions();

        $this->expectException(LexerException::class);
        $solver->intersection("/\xFF/u", '/./u', $options);
    }

    #[Test]
    public function test_case_folding_covers_a_whole_unicode_block(): void
    {
        $solver = new LanguageSolver();
        $options = $this->fullMatchOptions();

        // Folding used to walk every code point of the class; a class this
        // wide is now folded against the table of code points that actually
        // have a case mapping, as equivalence classes. Under /iu the class
        // also matches the long s U+017F (via "s"), the Kelvin sign U+212A
        // (via "k") and the angstrom sign U+212B (via "å") — and nothing
        // else: the sharp s U+1E9E, İ U+0130 and DŽ U+01F1 stay out (oracle:
        // preg_match over each of them against '/[à-öa-z]/iu').
        $result = $solver->equivalent('/[à-öa-z]/iu', '/[à-öÀ-Öa-zA-Z\x{17F}\x{212A}\x{212B}]/u', $options);

        $this->assertTrue($result->isEquivalent);
    }

    #[Test]
    public function test_case_folding_reaches_outside_the_basic_plane(): void
    {
        $solver = new LanguageSolver();
        $options = $this->fullMatchOptions();

        // U+10400 DESERET CAPITAL LONG I folds to U+10428.
        $result = $solver->equivalent('/\u{10400}/iu', '/[\u{10400}\u{10428}]/u', $options);

        $this->assertTrue($result->isEquivalent);
    }

    #[Test]
    public function test_unicode_classes_keep_their_boundaries(): void
    {
        $solver = new LanguageSolver();
        $options = $this->fullMatchOptions();

        // The classes are read from PCRE a block at a time; the edges of a
        // block must not gain or lose a code point.
        $this->assertFalse($solver->intersection('/\w/u', '/é/u', $options)->isEmpty);
        $this->assertTrue($solver->intersection('/\w/u', '/ /u', $options)->isEmpty);
        $this->assertFalse($solver->intersection('/\s/u', "/\u{2028}/u", $options)->isEmpty);
        $this->assertFalse($solver->intersection('/\d/u', "/\u{0661}/u", $options)->isEmpty);
        $this->assertTrue($solver->intersection('/\d/u', '/a/u', $options)->isEmpty);
    }

    /**
     * A pattern read as UTF-8 and one read as bytes are compared on the
     * subjects both can read, the valid UTF-8 strings: there "é" is one
     * character to the one and two bytes to the other. A witness is such a
     * string, and the engine tells the two apart on it.
     */
    #[Test]
    #[DataProvider('provideMixedReadings')]
    public function test_a_pattern_read_as_utf8_is_compared_with_one_read_as_bytes_on_utf8_subjects(string $left, string $right, bool $equivalent, ?string $leftOnly, ?string $rightOnly): void
    {
        foreach (['', 'a', 'é', "\u{80}", "\u{100}", '€', "\u{10FFFF}", 'éé', '/admin', '/admin/users', "/admin/users\u{100}"] as $subject) {
            if ($equivalent) {
                $this->assertSame(preg_match($left, $subject), preg_match($right, $subject), bin2hex($subject));
            }
        }

        foreach ([[$leftOnly, 1, 0], [$rightOnly, 0, 1]] as [$witness, $leftAnswer, $rightAnswer]) {
            if (null !== $witness) {
                $this->assertSame(1, preg_match('//u', $witness), 'A witness is valid UTF-8.');
                $this->assertSame([$leftAnswer, $rightAnswer], [preg_match($left, $witness), preg_match($right, $witness)], bin2hex($witness));
            }
        }

        $result = (new LanguageSolver())->equivalent($left, $right, $this->fullMatchOptions());

        $this->assertSame([$equivalent, $leftOnly, $rightOnly], [$result->isEquivalent, $result->leftOnlyExample, $result->rightOnlyExample]);
        $this->assertSame(null === $leftOnly, (new LanguageSolver())->subsetOf($left, $right, $this->fullMatchOptions())->isSubset);
        $this->assertFalse((new LanguageSolver())->intersection($left, $right, $this->fullMatchOptions())->isEmpty);
    }

    /**
     * @return iterable<string, array{left: string, right: string, equivalent: bool, leftOnly: string|null, rightOnly: string|null}>
     */
    public static function provideMixedReadings(): iterable
    {
        yield 'a letter above ASCII under a start option' => ['left' => '/(*UTF)\Aé\z/', 'right' => '/\Aé\z/', 'equivalent' => true, 'leftOnly' => null, 'rightOnly' => null];
        yield 'a letter above ASCII under the u flag' => ['left' => '/\Aé\z/u', 'right' => '/\Aé\z/', 'equivalent' => true, 'leftOnly' => null, 'rightOnly' => null];
        yield 'the bytes above ASCII and the characters above it' => ['left' => '/\A[\x80-\xFF]+\z/', 'right' => '/(*UTF)\A[^\x00-\x7F]+\z/', 'equivalent' => true, 'leftOnly' => null, 'rightOnly' => null];
        yield 'one character and one byte' => ['left' => '/(*UTF)\A.\z/', 'right' => '/\A.\z/', 'equivalent' => false, 'leftOnly' => "\u{80}", 'rightOnly' => null];
        yield 'a narrower path searched for' => ['left' => '/(*UTF)(*NO_JIT).*(?:^\/admin\/users).*/', 'right' => '/.*(?:^\/admin).*/', 'equivalent' => false, 'leftOnly' => null, 'rightOnly' => '/admin'];
    }

    private function fullMatchOptions(): SolverOptions
    {
        return new SolverOptions(matchMode: MatchMode::Full);
    }
}
