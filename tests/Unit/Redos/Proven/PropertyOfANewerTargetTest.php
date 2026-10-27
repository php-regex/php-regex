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

use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The prover reads each property as the target does, whatever engine runs
 * it. On PHP 8.4 (PCRE2 10.44) "\p{sc=Grek}" is the Greek script, which "a"
 * is not: /^(\p{sc=Grek}|a)+$/u is safe there, and PHP 8.1 (10.39), which
 * refuses the name, spells it "\p{Greek}". A bare "\p{Greek}" is the script
 * extensions of Greek from 10.40, which hold U+0342: with the JIT off, 30 of
 * them and a "!" exhaust the backtrack limit of PHP 8.4 in 0.03 s, and PHP
 * 8.1 reads the script alone, without U+0342, and has no name for the
 * extensions. Each verdict holds on every engine.
 */
final class PropertyOfANewerTargetTest extends TestCase
{
    #[Test]
    public function test_a_script_named_by_its_code_is_read_as_the_script_alone(): void
    {
        $analysis = self::forPhp84()->redos('/^(\p{sc=Grek}|a)+$/u');

        $this->assertTrue($analysis->isProvenSafe(), $analysis->headline());
    }

    #[Test]
    public function test_a_bare_script_is_never_proven_safe_by_its_script_alone(): void
    {
        $analysis = self::forPhp84()->redos('/^(\p{Greek}|\x{342})+$/u');

        $this->assertFalse($analysis->isProvenSafe(), $analysis->headline());
    }

    private static function forPhp84(): Regex
    {
        return Regex::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => '10.44']);
    }
}
