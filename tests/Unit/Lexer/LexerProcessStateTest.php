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

namespace PHPRegex\Tests\Unit\Lexer;

use PHPRegex\Parser\Cache\StaticCaches;
use PHPRegex\Parser\Lexer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The lexer builds its token regexes from constants, which PCRE keeps
 * compiled: it holds no state for the process, so there is nothing for
 * clearCaches() to empty.
 */
final class LexerProcessStateTest extends TestCase
{
    #[Test]
    public function test_the_lexer_keeps_nothing_for_the_process(): void
    {
        StaticCaches::clear();
        (new Lexer())->tokenize('(*pla:a)[a-z\d]+\Qx\E(?#c)é', 'u');
        (new Lexer())->tokenize("a\xFF[b]");

        $clearers = (new \ReflectionProperty(StaticCaches::class, 'clearers'))->getValue();

        $this->assertIsArray($clearers);
        $this->assertArrayNotHasKey(Lexer::class, $clearers);
    }
}
