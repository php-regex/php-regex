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

namespace PHPRegex\Tests\Unit\Redos;

use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pattern that does not parse fails the analysis with the message the
 * parser gives, spelled as validate() spells it: one failure, one text.
 */
final class RedosFailedAnalysisTest extends TestCase
{
    #[Test]
    public function test_the_error_is_the_parser_message_alone(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/(a/');

        $this->assertSame(RedosProof::NotAnalyzed, $analysis->proof);
        $this->assertSame(Regex::create(['cache' => null])->validate('/(a/')->error, $analysis->error);
        $this->assertStringNotContainsString('Exception', (string) $analysis->error);
    }
}
