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

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Two branches overlap where a character starts both, read under the
 * tables of the target's locale without /u: under fr_FR.ISO8859-1 "\xE9"
 * is a word character, so it starts both branches of (?:\w|\xE9) and
 * neither of (?:\W|\xE9)+ twice; under C, the other way round.
 *
 * Engine: PHP 8.4.27 / PCRE2 10.49 on macOS, setlocale(LC_CTYPE, ...) then
 * preg_match('/^\w$/', "\xE9"): 1 under the Latin-1 locale, 0 under C.
 */
final class OverlappingAlternationLocaleTest extends TestCase
{
    use InstalledLocales;

    private const ID = 'regex.lint.overlap.charset';

    private const LATIN1 = 'fr_FR.ISO8859-1';

    #[Test]
    #[RunInSeparateProcess]
    public function test_the_word_characters_are_the_engines(): void
    {
        $this->requiresLocale(self::LATIN1, 'The locale rows');

        $previous = (string) setlocale(\LC_CTYPE, '0');

        try {
            // C first: once a locale was named, its tables stay.
            $this->assertNotFalse(setlocale(\LC_CTYPE, 'C'));
            $this->assertSame(0, preg_match('/^\w$/', "\xE9"));
            $this->assertNotFalse(setlocale(\LC_CTYPE, self::LATIN1));
            $this->assertSame(1, preg_match('/^\w$/', "\xE9"));
        } finally {
            setlocale(\LC_CTYPE, $previous);
        }
    }

    #[Test]
    #[DataProvider('provideRows')]
    public function test_branches_overlap_by_the_tables_of_the_target_locale(string $pattern, string $locale, bool $reported): void
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
     * @return iterable<string, array{pattern: string, locale: string, reported: bool}>
     */
    public static function provideRows(): iterable
    {
        foreach (['C' => false, self::LATIN1 => true] as $locale => $word) {
            yield 'a word character or "\xE9", '.$locale => ['pattern' => '/(?:\w|\xE9)+/', 'locale' => $locale, 'reported' => $word];
            yield 'no word character or "\xE9", '.$locale => ['pattern' => '/(?:\W|\xE9)+/', 'locale' => $locale, 'reported' => !$word];
        }
    }
}
