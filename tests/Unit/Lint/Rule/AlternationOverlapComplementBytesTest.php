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
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without u, where PCRE judges no branch (one of them matches empty), the
 * bytes the branches start with decide: the complement of a class takes
 * the bytes above ASCII it does not hold, so "[^\xE9]" meets a raw 0x90,
 * and "\W" a raw 0xE9 under PCRE2's own tables. Under another locale's,
 * what the tables decide stays read in ASCII: "\W" leaves 0xE9 out under
 * fr_FR.ISO8859-1, as the engine does.
 *
 * Engine: PHP 8.4.28 / PCRE2 10.49 on macOS, under C and fr_FR.ISO8859-1:
 * preg_match("/^[^\xE9]$/", "\x90") and preg_match('/^\W$/', "\xE9") are 1
 * under C, and the second 0 under the Latin-1 locale.
 */
final class AlternationOverlapComplementBytesTest extends TestCase
{
    use InstalledLocales;

    private const ID = 'regex.lint.overlap.charset';

    private const LATIN1 = 'fr_FR.ISO8859-1';

    /**
     * @param array{string, string} $branches
     */
    #[Test]
    #[DataProvider('provideRows')]
    public function test_the_bytes_a_complement_takes_above_ascii_meet_a_byte_written_as_it_is(string $pattern, array $branches, string $byte, string $locale, bool $reported): void
    {
        if (CharacterTables::BUILT_IN !== $locale) {
            $this->requiresLocale($locale, $pattern);
        }

        // Oracle: under the row's locale, both branches take the byte where
        // the row says they overlap.
        $taken = new \ArrayObject();
        CharacterTables::under($locale, static function () use ($branches, $byte, $taken): void {
            foreach ($branches as $branch) {
                $taken[] = preg_match('/^(?:'.$branch.')$/', $byte);
            }
        });
        $this->assertSame($reported, [1, 1] === $taken->getArrayCopy(), \sprintf('Oracle: %s under %s takes %s in %s.', addcslashes($pattern, "\200..\377"), $locale, bin2hex($byte), json_encode($taken->getArrayCopy())));

        $regex = Regex::create(['cache' => null, 'php_version' => '8.4', 'locale' => $locale]);
        $linter = new PatternLinter([], $regex->target());
        $regex->parse($pattern)->accept($linter);

        $ids = array_map(static fn ($issue): string => $issue->id, $linter->getIssues());
        $this->assertSame($reported, \in_array(self::ID, $ids, true), addcslashes($pattern, "\200..\377").' under '.$locale);
    }

    /**
     * @return iterable<string, array{pattern: string, branches: array{string, string}, byte: string, locale: string, reported: bool}>
     */
    public static function provideRows(): iterable
    {
        foreach ([CharacterTables::BUILT_IN, self::LATIN1] as $locale) {
            yield 'a negated byte, then a byte it takes, '.$locale => ['pattern' => "/(?:[^\xE9]|\x90|)+/", 'branches' => ["[^\xE9]", "\x90"], 'byte' => "\x90", 'locale' => $locale, 'reported' => true];
            yield 'a byte, then a negated byte that takes it, '.$locale => ['pattern' => "/(?:\x90|[^\xE9]|)+/", 'branches' => ["\x90", "[^\xE9]"], 'byte' => "\x90", 'locale' => $locale, 'reported' => true];
            yield 'a negated letter of two bytes, then a byte it takes, '.$locale => ['pattern' => "/(?:[^é]|\x90|)+/", 'branches' => ['[^é]', "\x90"], 'byte' => "\x90", 'locale' => $locale, 'reported' => true];
            yield 'a negated ASCII letter, then a byte above ASCII, '.$locale => ['pattern' => "/(?:[^a]|\xC3|)+/", 'branches' => ['[^a]', "\xC3"], 'byte' => "\xC3", 'locale' => $locale, 'reported' => true];
            yield 'a negated byte, then that byte, '.$locale => ['pattern' => "/(?:[^\xC3]|\xC3|)+/", 'branches' => ["[^\xC3]", "\xC3"], 'byte' => "\xC3", 'locale' => $locale, 'reported' => false];
        }

        yield 'no word character, then a Latin-1 letter, C' => ['pattern' => "/(?:\\W|\xE9|)+/", 'branches' => ['\W', "\xE9"], 'byte' => "\xE9", 'locale' => CharacterTables::BUILT_IN, 'reported' => true];
        yield 'no word character, then a Latin-1 letter, '.self::LATIN1 => ['pattern' => "/(?:\\W|\xE9|)+/", 'branches' => ['\W', "\xE9"], 'byte' => "\xE9", 'locale' => self::LATIN1, 'reported' => false];
    }
}
