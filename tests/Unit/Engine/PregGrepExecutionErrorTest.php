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

namespace PHPRegex\Tests\Unit\Engine;

use PHPRegex\Tests\Support\PcreGates;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where preg_grep() stands when the engine fails mid-match: through 8.5 the
 * empty array comes back and the failure is silent, from 8.6 false — the
 * boundary static analysers' array|false model becomes exactly true on.
 * Observed on the running engines, PHP 8.4.26 and 8.6.0RC3.
 */
final class PregGrepExecutionErrorTest extends TestCase
{
    use PcreGates;

    #[Test]
    public function test_preg_grep_returns_false_on_execution_error(): void
    {
        $this->requiresPhp('8.6', 'preg_grep() returning false on a PCRE execution error', 'returns the empty array instead');

        $result = @preg_grep('/a/u', ["\xff"]);

        $this->assertFalse($result);
        $this->assertNotSame(\PREG_NO_ERROR, preg_last_error());
    }

    #[Test]
    public function test_preg_grep_swallows_the_execution_error_through_8_5(): void
    {
        $this->forbidsPhp('8.5', 'preg_grep() swallowing a PCRE execution error', 'returns false instead');

        $result = @preg_grep('/a/u', ["\xff"]);

        $this->assertSame([], $result);
        $this->assertNotSame(\PREG_NO_ERROR, preg_last_error());
    }
}
