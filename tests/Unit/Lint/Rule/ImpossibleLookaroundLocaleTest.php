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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without /u, the types, the POSIX classes and the other case of a byte
 * follow the character tables of the locale the pattern runs under: PHP
 * builds them from LC_CTYPE once a program calls setlocale(). Under a
 * Latin-1 locale "\xE9" is a word character, a letter, and the small "\xC9";
 * under C it is none of them. The automata read the sets for the target's
 * locale, so a lookahead is reported impossible only where the engine of
 * that locale never matches through it.
 *
 * Engine: PHP 8.4.27 / PCRE2 10.49 on macOS, setlocale(LC_CTYPE, ...) then
 * preg_match() on the row's subject; the oracle runs apart, as PHP keeps
 * building its tables from LC_CTYPE once a locale was named.
 */
final class ImpossibleLookaroundLocaleTest extends TestCase
{
    use InstalledLocales;

    private const ID = 'regex.lint.lookaround.impossible';

    private const LATIN1 = 'fr_FR.ISO8859-1';

    #[Test]
    #[RunInSeparateProcess]
    public function test_the_rows_hold_on_the_engine(): void
    {
        $this->requiresLocale(self::LATIN1, 'The locale rows');

        $previous = (string) setlocale(\LC_CTYPE, '0');

        try {
            // C first: once a locale was named, its tables stay.
            foreach (['C', self::LATIN1] as $locale) {
                $this->assertNotFalse(setlocale(\LC_CTYPE, $locale));
                foreach (self::provideRows() as $label => $row) {
                    if ($locale === $row['locale']) {
                        $this->assertSame($row['reported'] ? 0 : 1, preg_match($row['pattern'], $row['subject']), $label);
                    }
                }
            }
        } finally {
            setlocale(\LC_CTYPE, $previous);
        }
    }

    #[Test]
    #[DataProvider('provideRows')]
    public function test_a_lookahead_is_judged_by_the_tables_of_the_target_locale(string $pattern, string $locale, string $subject, bool $reported): void
    {
        if ('C' !== $locale) {
            $this->requiresLocale($locale, $pattern);
        }

        $regex = Regex::create(['cache' => null, 'php_version' => '8.4', 'locale' => $locale]);
        $linter = new PatternLinter([], $regex->target());
        $regex->parse($pattern)->accept($linter);

        $ids = array_map(static fn ($issue): string => $issue->id, $linter->getIssues());
        $this->assertSame($reported, \in_array(self::ID, $ids, true), $pattern.' under '.$locale);
    }

    /**
     * @return iterable<string, array{pattern: string, locale: string, subject: string, reported: bool}>
     */
    public static function provideRows(): iterable
    {
        foreach (['C' => true, self::LATIN1 => false] as $locale => $reported) {
            yield 'a word character, '.$locale => ['pattern' => '/(?=\w)\xE9/', 'locale' => $locale, 'subject' => "\xE9", 'reported' => $reported];
            yield 'a letter, '.$locale => ['pattern' => '/(?=[[:alpha:]])\xE9/', 'locale' => $locale, 'subject' => "\xE9", 'reported' => $reported];
            yield 'the other case of a byte, '.$locale => ['pattern' => '/(?=\xC9)\xE9/i', 'locale' => $locale, 'subject' => "\xE9", 'reported' => $reported];
            yield 'no word character, '.$locale => ['pattern' => '/(?!\w)\xE9/', 'locale' => $locale, 'subject' => "\xE9", 'reported' => !$reported];
        }

        // Under /u the Unicode properties decide, whatever the locale.
        yield 'a word character under u, C' => ['pattern' => '/(?=\w)\xE9/u', 'locale' => 'C', 'subject' => "\u{E9}", 'reported' => false];
        yield 'a word character under u, '.self::LATIN1 => ['pattern' => '/(?=\w)\xE9/u', 'locale' => self::LATIN1, 'subject' => "\u{E9}", 'reported' => false];
        // "\xA0" is no space under this Latin-1 locale either.
        yield 'no space, '.self::LATIN1 => ['pattern' => '/(?=\s)\xA0/', 'locale' => self::LATIN1, 'subject' => "\xA0", 'reported' => true];
    }

    /**
     * The solver itself reads "\w" for its parser's locale.
     */
    #[Test]
    public function test_the_solver_reads_a_type_for_the_locale_of_its_parser(): void
    {
        $this->requiresLocale(self::LATIN1, 'The solver row');

        $latin1 = new LanguageSolver(RegexParser::create(['cache' => null, 'php_version' => '8.4', 'locale' => self::LATIN1]));
        $c = new LanguageSolver(RegexParser::create(['cache' => null, 'php_version' => '8.4', 'locale' => 'C']));

        $this->assertFalse($latin1->intersection('/^\w$/', '/^\xE9$/')->isEmpty);
        $this->assertTrue($c->intersection('/^\w$/', '/^\xE9$/')->isEmpty);
        // And back again: each locale keeps its own answer.
        $this->assertFalse($latin1->intersection('/^\w$/', '/^\xE9$/')->isEmpty);

        // Under u the Unicode properties decide.
        $this->assertFalse($c->intersection('/^\w$/u', '/^\x{E9}$/u')->isEmpty);
    }
}
