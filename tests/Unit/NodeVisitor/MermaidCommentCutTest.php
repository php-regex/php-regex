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

use PHPRegex\Explain\MermaidRenderer;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A comment is cut at 20 bytes in the diagram, on a character boundary:
 * cut inside "é", the diagram held a lone lead byte, invalid UTF-8.
 */
final class MermaidCommentCutTest extends TestCase
{
    #[Test]
    public function test_a_long_comment_is_cut_on_a_character_boundary(): void
    {
        $pattern = '/a(?#'.str_repeat('x', 19).'é tail)b/u';

        $diagram = Regex::create(['cache' => null])->parse($pattern)->accept(new MermaidRenderer());

        $this->assertTrue(mb_check_encoding($diagram, 'UTF-8'), $diagram);
        $this->assertStringContainsString('Comment: '.str_repeat('x', 19).'"', $diagram);
    }
}
