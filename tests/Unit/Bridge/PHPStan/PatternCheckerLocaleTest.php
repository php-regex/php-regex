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

namespace PHPRegex\Tests\Unit\Bridge\PHPStan;

use PHPRegex\Linter\Config\JudgedRange;
use PHPRegex\Linter\Config\TargetConstraint;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\RegexParser;
use PHPRegex\PHPStan\PatternChecker;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPStan\Php\PhpVersion;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "phpRegex.locale" names the LC_CTYPE locale the patterns run under: the
 * floor, every later PHP of PHPStan's range, and the versions a guard or a
 * "@regex-target" marker keeps are all judged under it.
 */
final class PatternCheckerLocaleTest extends TestCase
{
    use InstalledLocales;
    use PhpUnitForwardCompat;

    #[Test]
    public function test_every_version_judged_keeps_the_locale_named(): void
    {
        $this->requiresLocale('fr_FR.ISO8859-1', 'The locale of the PHPStan range');
        $checker = new PatternChecker(['locale' => 'fr_FR.ISO8859-1'], new PhpVersion(80200, PhpVersion::SOURCE_CONFIG), ['min' => 80200, 'max' => 80599]);

        $this->assertSame('fr_FR.ISO8859-1', $checker->parser()->target()->locale);
        /** @var list<array{RegexParser, string}> $range */
        $range = (new \ReflectionProperty($checker, 'range'))->getValue($checker);
        $this->assertNotSame([], $range);
        foreach ($range as [$parser, $label]) {
            $this->assertSame('fr_FR.ISO8859-1', $parser->target()->locale);
            $this->assertStringEndsWith(' under the locale fr_FR.ISO8859-1', $label);
            // The pair of Latin-1 letters is one caseless character there.
            $this->assertTrue($parser->validate('/(?:[\xe9\xc9]){8000}/')->isValid, $label);
        }

        /** @var JudgedRange $judged */
        $judged = (new \ReflectionMethod($checker, 'judged'))->invoke($checker);
        $targets = $judged->narrow(TargetConstraint::phpFrom(80400, null));
        $this->assertNotSame([], $targets);
        foreach ($targets as $target) {
            $this->assertSame('fr_FR.ISO8859-1', $target->locale);
            $this->assertSame('fr_FR.ISO8859-1', $judged->parserFor($target)->target()->locale);
        }
    }

    #[Test]
    public function test_a_locale_the_machine_cannot_set_stops_the_analysis(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains('"locale" names "xx_YY.NOWHERE"');

        new PatternChecker(['locale' => 'xx_YY.NOWHERE'], new PhpVersion(80400, PhpVersion::SOURCE_CONFIG), 80400);
    }
}
