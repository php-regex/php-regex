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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "(*CASELESS_RESTRICT)" and "(*TURKISH_CASING)" arrived in PCRE2 10.45, as
 * settings read at the start of the pattern. Turkish casing needs UTF mode
 * (without it, error 204, or 205 under UCP alone) and does not go with the
 * caseless restriction (error 206), both reported where the leading settings
 * end. No PHP bundles 10.45: for a targeted PHP they are unknown verbs,
 * refused where the name ends (pcre2test 10.44 and 10.48, and PHP).
 */
final class CasingSettingsTest extends TestCase
{
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_without_a_target_the_running_pcre2_decides(string $pattern): void
    {
        error_clear_last();
        $compiles = false !== @preg_match($pattern, '') || null === error_get_last();

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertSame($compiles, $result->isValid, \sprintf('%s: %s', $pattern, (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'caseless restriction' => ['pattern' => '/(*CASELESS_RESTRICT)a/i'];
        yield 'Turkish casing in UTF mode' => ['pattern' => '/(*TURKISH_CASING)i/iu'];
        yield 'Turkish casing after (*UTF)' => ['pattern' => '/(*UTF)(*TURKISH_CASING)i/i'];
        yield 'Turkish casing before (*UTF)' => ['pattern' => '/(*TURKISH_CASING)(*UTF)i/i'];
        yield 'Turkish casing without UTF' => ['pattern' => '/(*TURKISH_CASING)i/i'];
        yield 'Turkish casing under UCP alone' => ['pattern' => '/(*UCP)(*TURKISH_CASING)i/i'];
        yield 'both casings' => ['pattern' => '/(*TURKISH_CASING)(*CASELESS_RESTRICT)i/iu'];
        yield 'caseless restriction after text' => ['pattern' => '/a(*CASELESS_RESTRICT)/'];
    }

    #[Test]
    public function test_the_settings_follow_pcre2_10_45(): void
    {
        if (version_compare(explode(' ', \PCRE_VERSION)[0], '10.45', '<')) {
            $this->assertFalse(@preg_match('/(*CASELESS_RESTRICT)a/', ''), 'Before 10.45 the setting is unknown.');

            return;
        }

        $regex = Regex::create(['cache' => null]);
        $this->assertTrue($regex->validate('/(*CASELESS_RESTRICT)a/i')->isValid);
        $this->assertTrue($regex->validate('/(*TURKISH_CASING)i/iu')->isValid);

        $withoutUtf = $regex->validate('/(*TURKISH_CASING)i/i');
        $this->assertSame(ErrorCode::VerbTurkishCasingWithoutUtf, $withoutUtf->errorCode);
        $this->assertSame(17, $withoutUtf->offset);

        $ucpAlone = $regex->validate('/(*UCP)(*TURKISH_CASING)i/i');
        $this->assertSame(ErrorCode::VerbTurkishCasingWithoutUtf, $ucpAlone->errorCode);
        $this->assertSame(23, $ucpAlone->offset);

        $both = $regex->validate('/(*TURKISH_CASING)(*CASELESS_RESTRICT)i/iu');
        $this->assertSame(ErrorCode::VerbConflictingCasings, $both->errorCode);
        $this->assertSame(37, $both->offset);
    }

    #[Test]
    public function test_no_targeted_php_knows_them(): void
    {
        foreach ([80200, 80300, 80400, 80500] as $phpVersion) {
            $regex = Regex::create(['cache' => null, 'php_version' => $phpVersion]);

            $this->assertSame(19, $regex->validate('/(*CASELESS_RESTRICT)a/')->offset);
            $this->assertSame(16, $regex->validate('/(*TURKISH_CASING)a/u')->offset);
            $this->assertSame(ErrorCode::VerbInvalid, $regex->validate('/(*TURKISH_CASING)a/u')->errorCode);
        }
    }
}
