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

namespace PHPRegex\Tests\Unit\Lsp\Document;

use PHPRegex\LanguageServer\Document\RegexOccurrence;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RegexOccurrenceTest extends TestCase
{
    /**
     * Only a marker silences an occurrence: one built without saying so is
     * diagnosed.
     */
    #[Test]
    public function test_an_occurrence_is_diagnosed_unless_said_ignored(): void
    {
        $occurrence = new RegexOccurrence('/a/', ['line' => 1, 'character' => 11], ['line' => 1, 'character' => 16], 17, "'/a/'");

        $this->assertFalse($occurrence->ignored);
        $this->assertTrue((new RegexOccurrence('/a/', ['line' => 1, 'character' => 11], ['line' => 1, 'character' => 16], 17, "'/a/'", true))->ignored);
    }
}
