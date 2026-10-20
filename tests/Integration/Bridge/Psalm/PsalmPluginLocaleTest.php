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

namespace PHPRegex\Tests\Integration\Bridge\Psalm;

use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Psalm\PregCallAnalyzer;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Exception\ConfigException;

/**
 * <locale> names the LC_CTYPE locale the patterns run under, as the PHPStan
 * extension's locale. "(?:[\Wa-zA-Z0-9_]){8000}" compiles to 56007 units on
 * PCRE2 10.49 under C, where the class holds every byte, and is too large
 * under fr_FR.ISO8859-1, where "\W" leaves the Latin-1 letters out
 * (pcre2test 10.49 with "locale=", and PHP 8.4.26 under setlocale()).
 */
final class PsalmPluginLocaleTest extends TestCase
{
    use InstalledLocales;

    private const FILE = 'Target/locale.php';

    #[Test]
    public function test_plugin_judges_under_the_locale_named(): void
    {
        $this->requiresLocale('fr_FR.ISO8859-1', 'The plugin under a Latin-1 locale');

        $this->assertSame([], self::reported(['pcreVersion' => '10.49', 'locale' => 'C']));
        $this->assertSame(
            ['Regex pattern is invalid for PHP 8.4 with PCRE2 10.49 under the locale fr_FR.ISO8859-1: Regular expression is too large: PCRE would compile it to more than 64 KiB (offset 0).'],
            self::reported(['pcreVersion' => '10.49', 'locale' => 'fr_FR.ISO8859-1']),
        );
    }

    #[Test]
    public function test_plugin_refuses_a_locale_the_machine_cannot_set(): void
    {
        try {
            PsalmRun::issues([self::FILE], '8.4', ['locale' => 'xx_YY.NOWHERE']);
            $this->fail('An unknown locale was accepted.');
        } catch (ConfigException $e) {
            $this->assertInstanceOf(InvalidRegexOptionException::class, $e->getPrevious(), $e->getMessage());
            $this->assertStringContainsString('<locale>', $e->getPrevious()->getMessage());
        }
    }

    /**
     * Two targets that differ by their locale alone keep their verdicts and
     * their types apart.
     */
    #[Test]
    public function test_two_locales_never_share_a_verdict(): void
    {
        $this->requiresLocale('fr_FR.ISO8859-1', 'The verdicts of two locales');
        $key = new \ReflectionMethod(PregCallAnalyzer::class, 'targetKey');

        $this->assertNotSame(
            $key->invoke(null, RegexParser::create(['php_version' => '8.4', 'locale' => 'C'])),
            $key->invoke(null, RegexParser::create(['php_version' => '8.4', 'locale' => 'fr_FR.ISO8859-1'])),
        );
    }

    /**
     * @param array<string, string> $options
     *
     * @return list<string>
     */
    private static function reported(array $options): array
    {
        $reported = [];
        foreach (PsalmRun::inFile(PsalmRun::issues([self::FILE], '8.4', $options), self::FILE) as $issue) {
            // "Type line:column message".
            $described = explode(' ', PsalmRun::describe($issue), 3);
            if ('InvalidRegexPattern' === $described[0]) {
                $reported[] = $described[2] ?? '';
            }
        }

        return $reported;
    }
}
