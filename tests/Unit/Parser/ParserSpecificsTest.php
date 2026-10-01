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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class ParserSpecificsTest extends TestCase
{
    public function test_subroutine_name_unexpected_token(): void
    {
        // (?&name!) -> '!' is not allowed in subroutine name
        $regex = Regex::create();

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Unexpected token');

        $regex->parse('/(?&name!)/');
    }

    public function test_quantifier_on_start_of_pattern(): void
    {
        // A quantifier at the very start of the pattern (after delimiter)
        // hits the "Quantifier without target" check in parseQuantifiedAtom
        $regex = Regex::create();

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Quantifier without target');

        $regex->parse('/+abc/');
    }
}
