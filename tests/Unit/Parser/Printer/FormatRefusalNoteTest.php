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

namespace PHPRegex\Tests\Unit\Parser\Printer;

use PHPRegex\Parser\Printer\FormatRefusal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FormatRefusalNoteTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRefusals')]
    public function test_every_refusal_has_a_note_that_names_what_was_left(FormatRefusal $refusal, string $names): void
    {
        $note = $refusal->note();

        $this->assertStringStartsWith('left as written: ', $note);
        $this->assertStringContainsString($names, $note);
        $this->assertStringEndsWith('.', $note);
    }

    /**
     * @return iterable<string, array{refusal: FormatRefusal, names: string}>
     */
    public static function provideRefusals(): iterable
    {
        yield 'newline convention' => ['refusal' => FormatRefusal::NewlineConvention, 'names' => '(*CR), (*CRLF) or (*NUL)'];
        yield 'spaced quantifier' => ['refusal' => FormatRefusal::SpacedQuantifier, 'names' => 'a{ 1,2 }'];
        yield 'extended mode switched off' => ['refusal' => FormatRefusal::ExtendedModeSwitchedOff, 'names' => '(?-x) or (?^)'];
        yield 'line break in a name' => ['refusal' => FormatRefusal::LineBreakInName, 'names' => '(*MARK:…)'];
        yield 'parenthesis in a comment' => ['refusal' => FormatRefusal::ParenthesisInComment, 'names' => '(?#…)'];
        yield 'delimiter misread' => ['refusal' => FormatRefusal::DelimiterMisread, 'names' => '\\c\\'];
    }

    #[Test]
    public function test_every_case_has_its_own_note(): void
    {
        $notes = array_map(static fn (FormatRefusal $refusal): string => $refusal->note(), FormatRefusal::cases());

        $this->assertCount(6, $notes);
        $this->assertSame($notes, array_values(array_unique($notes)));
    }
}
