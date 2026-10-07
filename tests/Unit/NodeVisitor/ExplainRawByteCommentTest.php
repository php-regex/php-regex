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

use PHPRegex\Explain\HtmlExplainer;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A byte-mode comment may hold bytes that are no UTF-8: the explanation
 * spells them "\xHH", so it stays text json_encode() and a browser take.
 */
final class ExplainRawByteCommentTest extends TestCase
{
    #[Test]
    public function test_the_text_explanation_spells_a_raw_byte_of_a_comment(): void
    {
        $explanation = Regex::create(['cache' => null])->explain("/a(?#\xE1)b/");

        $this->assertTrue(mb_check_encoding($explanation, 'UTF-8'));
        $this->assertNotFalse(json_encode($explanation));
        $this->assertStringContainsString('\xE1', $explanation);
    }

    #[Test]
    public function test_the_html_explanation_spells_a_raw_byte_of_a_comment(): void
    {
        $html = Regex::create(['cache' => null])->parse("/a(?#\xE1)b/")->accept(new HtmlExplainer());

        $this->assertTrue(mb_check_encoding($html, 'UTF-8'));
        $this->assertStringContainsString('\xE1', $html);
    }

    #[Test]
    public function test_a_valid_comment_is_shown_as_written(): void
    {
        $this->assertStringContainsString("Comment: 'a\\b é'", Regex::create(['cache' => null])->explain('/a(?#a\b é)b/'));
    }
}
