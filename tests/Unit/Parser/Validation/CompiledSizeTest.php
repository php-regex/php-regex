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

use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\CompiledSize;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CompiledSizeTest extends TestCase
{
    /**
     * A class of two characters that the Unicode tables of the release make
     * a pair of cases is one caseless character, two units at least, though
     * PHP's own case mapping keeps them apart: U+0390 and U+1FD3 are each
     * other's case from PCRE2 10.45, and both lowercase to themselves.
     */
    #[Test]
    public function test_a_pair_of_cases_in_the_tables_of_the_release_floors_at_two_units(): void
    {
        $regex = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => '10.49', 'runtime_pcre_validation' => false])->parse('/[\x{390}\x{1FD3}]/u');

        $this->assertSame(2, (new CompiledSize($regex, true, new PcreTarget(80400, '10.49')))->floor($regex->pattern));
    }
}
