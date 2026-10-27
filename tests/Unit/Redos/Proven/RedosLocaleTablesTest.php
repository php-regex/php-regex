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
use PHPRegex\Redos\RedosProof;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without /u the sets of the proof follow the character tables of the
 * target's locale. Under a Latin-1 locale "\xE9" is a word character, a
 * letter, and the small "\xC9": each row's two branches then overlap, and
 * the loop is exponential, where under C they share nothing and the loop
 * is linear.
 *
 * Engine: PHP 8.4.27 / PCRE2 10.49 on macOS, without the JIT,
 * pcre.backtrack_limit 1000000: under setlocale(LC_CTYPE,
 * "fr_FR.ISO8859-1") each row exhausts the limit on 30 "\xE9" and a "!",
 * under C it fails at once.
 */
final class RedosLocaleTablesTest extends TestCase
{
    use InstalledLocales;

    private const LATIN1 = 'fr_FR.ISO8859-1';

    #[Test]
    #[RunInSeparateProcess]
    public function test_the_rows_hold_on_the_engine(): void
    {
        $this->requiresLocale(self::LATIN1, 'The locale rows');

        $previous = (string) setlocale(\LC_CTYPE, '0');
        ini_set('pcre.backtrack_limit', '1000000');

        try {
            // C first: once a locale was named, its tables stay.
            foreach (['C' => 0, self::LATIN1 => false] as $locale => $expected) {
                $this->assertNotFalse(setlocale(\LC_CTYPE, $locale));
                foreach (self::providePatterns() as $label => ['pattern' => $pattern]) {
                    $this->assertSame($expected, @preg_match(PcreInterpreter::pattern($pattern), str_repeat("\xE9", 30).'!'), $label.' under '.$locale);
                }
            }
        } finally {
            setlocale(\LC_CTYPE, $previous);
        }
    }

    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_proof_reads_the_sets_for_the_target_locale(string $pattern): void
    {
        $this->requiresLocale(self::LATIN1, $pattern);

        $latin1 = (new RedosAnalyzer(RegexParser::create(['cache' => null, 'php_version' => '8.4', 'locale' => self::LATIN1])))->analyze($pattern);
        $this->assertFalse($latin1->isProvenSafe(), $pattern.' is "'.$latin1->headline().'" under '.self::LATIN1);
        $this->assertSame(RedosProof::Proven, $latin1->proof, $latin1->headline());
        $this->assertSame(RedosComplexity::Exponential, $latin1->complexity, $latin1->headline());

        $c = (new RedosAnalyzer(RegexParser::create(['cache' => null, 'php_version' => '8.4', 'locale' => 'C'])))->analyze($pattern);
        $this->assertTrue($c->isProvenSafe(), $pattern.' is "'.$c->headline().'" under C');
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'a word character' => ['pattern' => '/^(?:\w|\xE9)+$/'];
        yield 'a letter' => ['pattern' => '/^(?:[[:alpha:]]|\xE9)+$/'];
        yield 'the other case of a byte' => ['pattern' => '/^(?:\xC9|\xE9)+$/i'];
    }
}
