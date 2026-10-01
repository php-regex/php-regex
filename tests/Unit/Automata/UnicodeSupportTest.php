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

namespace PhpRegex\Tests\Unit\Automata;

use PhpRegex\Automata\LanguageSolver;
use PhpRegex\Automata\Options\MatchMode;
use PhpRegex\Automata\Options\SolverOptions;
use PhpRegex\Automata\Unicode\CodePointHelper;
use PhpRegex\Parser\Exception\LexerException;
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
        // have a case mapping.
        $result = $solver->equivalent('/[à-öa-z]/iu', '/[à-öÀ-Öa-zA-Z]/u', $options);

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

    private function fullMatchOptions(): SolverOptions
    {
        return new SolverOptions(matchMode: MatchMode::FULL);
    }
}
