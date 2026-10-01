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

use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class LexerStateTest extends TestCase
{
    private Regex $regexService;

    protected function setUp(): void
    {
        $this->regexService = Regex::create();
    }

    public function test_parser_reuses_lexer_and_resets(): void
    {
        $this->expectNotToPerformAssertions();

        // First parse creates Lexer
        $this->regexService->parse('/a/');

        // Second parse reuses Lexer and calls reset()
        $this->regexService->parse('/b/');
    }
}
