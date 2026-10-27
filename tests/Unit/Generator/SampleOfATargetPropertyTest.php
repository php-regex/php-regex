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

namespace PHPRegex\Tests\Unit\Generator;

use PHPRegex\Generator\Exception\SampleGenerationException;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A sample is checked by the running engine, which may read a property as
 * the target does not. U+3001 (ideographic comma) is of the Common script,
 * with Hiragana among its script extensions: preg_match('/^\p{Hiragana}$/u',
 * "\u{3001}") is 0 on PHP 8.1 (PCRE2 10.39), which reads the script alone,
 * and 1 from 10.40, which reads the extensions. A sample for PHP 8.1 is
 * drawn and checked as PHP 8.1 reads it. A name the running engine does not
 * know, and cannot spell another way, gives no sample: "\p{sunuwar}" is
 * refused before PCRE2 10.45.
 */
final class SampleOfATargetPropertyTest extends TestCase
{
    #[Test]
    public function test_a_sample_of_a_script_is_of_the_script_the_target_reads(): void
    {
        $regex = Regex::create(['cache' => null, 'php_version' => '8.1', 'pcre_version' => '10.39']);
        // The script alone, as the running engine spells it.
        $script = PcreTarget::runtime()->supports(PcreFeature::LoosePropertyNames) ? '/^\p{sc:Hiragana}$/u' : '/^\p{Hiragana}$/u';

        for ($draw = 0; $draw < 16; $draw++) {
            $sample = $regex->generate('/^\p{Hiragana}$/u');
            $this->assertSame(1, preg_match($script, $sample), json_encode($sample, \JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    public function test_a_property_the_running_engine_cannot_read_gives_no_sample(): void
    {
        $pattern = '/^\p{sunuwar}+$/u';
        $regex = Regex::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => '10.49']);

        try {
            $sample = $regex->generate($pattern);
        } catch (SampleGenerationException $e) {
            $this->assertSame(ErrorCode::GenerateNoMatch, $e->errorCode);
            $this->assertFalse(@preg_match($pattern, ''), 'Only an engine that refuses the name gives no sample.');

            return;
        }

        $this->assertSame(1, preg_match($pattern, $sample), json_encode($sample, \JSON_THROW_ON_ERROR));
    }
}
