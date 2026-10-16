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

namespace PHPRegex\Tests\Unit\Redos\Proven;

use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Tests\Support\PcreGates;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Before PCRE2 10.40 "\p{Han}" names the characters whose script is Han; from
 * 10.40, those whose script extensions hold Han, as U+3001 (ideographic
 * comma) does. In /^(?:\p{Han}|、)+$/u the two branches are then disjoint
 * before 10.40 and share U+3001 after: with the JIT off, 24 commas and a "!"
 * run in microseconds on PHP 8.1.34 with its bundled PCRE2 10.39, and exhaust
 * the backtrack limit on PCRE2 10.49. The prover reads the property as the
 * target does.
 */
final class ScriptExtensionsByTargetTest extends TestCase
{
    use PcreGates;

    private const PATTERN = '/^(?:\p{Han}|、)+$/u';

    #[Test]
    public function test_a_script_name_shares_no_character_with_its_extensions_before_pcre2_10_40(): void
    {
        // The running engine is asked for the set: only one that reads
        // "sc:" can answer for an older target.
        $this->requiresPcre2('10.40', self::PATTERN);

        $before = (new RedosAnalyzer(RegexParser::create(['cache' => null, 'php_version' => 80100])))->analyze(self::PATTERN);
        $after = (new RedosAnalyzer(RegexParser::create(['cache' => null, 'php_version' => 80200])))->analyze(self::PATTERN);

        $this->assertTrue($before->isProvenSafe(), $before->headline());
        $this->assertSame(RedosComplexity::Exponential, $after->complexity, $after->headline());
    }
}
