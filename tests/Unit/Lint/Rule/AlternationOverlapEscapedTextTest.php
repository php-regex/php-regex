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
use PHPRegex\Linter\Rule\OverlappingAlternationRule;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An escape that names one character, "\x41", "\x{E9}", "\101", "\o{101}",
 * "\N{U+E9}" or "\cA", is literal text to the overlap rules, as it is to
 * the engine: the pattern spelled with it gets the finding, rule and
 * message, of the pattern spelled with the characters themselves. Literal
 * branches whose texts start alike are reported as overlapping text,
 * "/(?:\x41x|ax)+/i" as "/(?:Ax|ax)+/i", and the texts tell branches
 * apart, "/(?:\x{E9}a|\x{E9}b)+/u" as "/(?:éa|éb)+/u". Under u, where the
 * engine judges no branch, a character past ASCII is known by the byte it
 * starts with whatever its spelling: "/(?:[a-z]|\x{E9}|[a-c]|)+/u" is
 * reported as "/(?:[a-z]|é|[a-c]|)+/u" is.
 *
 * Engine: PHP 8.4.28 / PCRE2 10.49, with and without the JIT:
 * preg_match('/^\x41x$/i', 'ax') is 1, and under fr_FR.ISO8859-1
 * preg_match('/^\xC9x$/i', "\xE9x") is 1, 0 under C.
 */
final class AlternationOverlapEscapedTextTest extends TestCase
{
    use InstalledLocales;

    private const LATIN1 = 'fr_FR.ISO8859-1';

    /**
     * The locale of the tables a test gives.
     */
    private const GIVEN = 'xx_XX.ESCAPED';

    /**
     * @param list<string> $branches the branches, each read alone under the pattern's flags
     * @param list<string> $subjects the subjects the oracle runs each branch on
     * @param string|null  $rule     the rule that reports the alternation, none for null
     * @param string|null  $quoted   the two branches the message quotes
     */
    #[Test]
    #[DataProvider('provideSpellings')]
    public function test_an_escaped_spelling_gets_the_finding_of_the_spelling_it_names(string $raw, string $escaped, array $branches, string $flags, array $subjects, ?string $rule, ?string $quoted = null): void
    {
        $this->assertSameFindings($raw, $escaped, $branches, $flags, $subjects, $rule, $quoted, CharacterTables::BUILT_IN);
    }

    /**
     * @return iterable<string, array{raw: string, escaped: string, branches: list<string>, flags: string, subjects: list<string>, rule: string|null, quoted?: string}>
     */
    public static function provideSpellings(): iterable
    {
        $text = OverlappingAlternationRule::ID_OVERLAP;
        $charset = OverlappingAlternationRule::ID_CHARSET;

        foreach (['\x41', '\x{41}', '\101', '\o{101}'] as $escape) {
            yield 'a capital written '.$escape.', then its small letter, caselessly' => ['raw' => '/(?:Ax|ax)+/i', 'escaped' => '/(?:'.$escape.'x|ax)+/i', 'branches' => [$escape.'x', 'ax'], 'flags' => 'i', 'subjects' => ['ax'], 'rule' => $text, 'quoted' => '"Ax" and "ax"'];
        }

        yield 'a text that starts the other, written as escapes' => ['raw' => '/(?:a|ab)+/', 'escaped' => '/(?:\x61|\x61b)+/', 'branches' => ['\x61', '\x61b'], 'flags' => '', 'subjects' => ['ab'], 'rule' => $text, 'quoted' => '"a" and "ab"'];
        yield 'a control character written \cA' => ['raw' => "/(?:\x01x|\x01xy)+/", 'escaped' => '/(?:\cAx|\x01xy)+/', 'branches' => ['\cAx', '\x01xy'], 'flags' => '', 'subjects' => ["\x01xy"], 'rule' => $text, 'quoted' => '"\x01x" and "\x01xy"'];
        yield 'a byte past ASCII written as an escape' => ['raw' => "/(?:\xE9|\xE9x)+/", 'escaped' => '/(?:\xE9|\xE9x)+/', 'branches' => ['\xE9', '\xE9x'], 'flags' => '', 'subjects' => ["\xE9x"], 'rule' => $text, 'quoted' => '"\xE9" and "\xE9x"'];
        yield 'a character past ASCII written as an escape under u' => ['raw' => '/(?:é|éx|)+/u', 'escaped' => '/(?:\x{E9}|\x{E9}x|)+/u', 'branches' => ['\x{E9}', '\x{E9}x'], 'flags' => 'u', 'subjects' => ['éx'], 'rule' => $text, 'quoted' => '"é" and "éx"'];
        yield 'a character past ASCII written \N{U+E9} under u' => ['raw' => '/(?:é|éx)+/u', 'escaped' => '/(?:\N{U+E9}|éx)+/u', 'branches' => ['\N{U+E9}', 'éx'], 'flags' => 'u', 'subjects' => ['éx'], 'rule' => $text, 'quoted' => '"é" and "éx"'];

        // Texts that differ past their first character tell the branches
        // apart, whatever spells the character they share.
        yield 'two texts one escape starts under u' => ['raw' => '/(?:éa|éb)+/u', 'escaped' => '/(?:\x{E9}a|\x{E9}b)+/u', 'branches' => ['\x{E9}a', '\x{E9}b'], 'flags' => 'u', 'subjects' => ['éa', 'éb'], 'rule' => null];
        yield 'two texts one escape starts, then items that meet' => ['raw' => '/(?:ab\d|ac\w)+/', 'escaped' => '/(?:\x61b\d|\x61c\w)+/', 'branches' => ['\x61b\d', '\x61c\w'], 'flags' => '', 'subjects' => ['ab1', 'ac1'], 'rule' => null];
        yield 'a control character written \cA beside a class holding it, caselessly under u' => ['raw' => "/(?:\x01|[\x01é])+/iu", 'escaped' => '/(?:\cA|[\x01é])+/iu', 'branches' => ['\cA', '[\x01é]'], 'flags' => 'iu', 'subjects' => ["\x01"], 'rule' => $charset];
        yield 'a control character written \cA, then a letter in its own case' => ['raw' => "/(?:a|(?-i)\x01A)+/i", 'escaped' => '/(?:a|(?-i)\cAA)+/i', 'branches' => ['a', '(?-i)\cAA'], 'flags' => 'i', 'subjects' => ['a', 'A', "\x01A", "\x01a"], 'rule' => null];
        // A lookaround ends the text: what it looks at is read again after it.
        yield 'a text a lookahead stops, then a text that starts alike' => ['raw' => '/(?:a(?=b)b|ab\d)+/', 'escaped' => '/(?:\x61(?=b)b|\x61b\d)+/', 'branches' => ['\x61(?=b)b', '\x61b\d'], 'flags' => '', 'subjects' => ['ab1'], 'rule' => $charset];
        yield 'two capitals past ASCII the C locale gives no other case' => ['raw' => "/(?:\xC9x|\xE9x)+/i", 'escaped' => '/(?:\xC9x|\xE9x)+/i', 'branches' => ['\xC9x', '\xE9x'], 'flags' => 'i', 'subjects' => ["\xC9x", "\xE9x"], 'rule' => null];

        // Under u, where the engine judges no branch, one matching empty,
        // the first bytes decide, a character past ASCII known by its lead
        // byte, which none of the ASCII sets holds.
        foreach (['\x{E9}', '[\x{E9}]', '\351', '\o{351}', '\N{U+E9}', '\x{E9}x', '[\x{E9}-\x{FF}]'] as $escape) {
            $written = strtr($escape, ['\x{E9}' => 'é', '\351' => 'é', '\o{351}' => 'é', '\N{U+E9}' => 'é', '\x{FF}' => 'ÿ']);
            yield 'a character past ASCII written '.$escape.' between two ASCII sets that overlap under u' => ['raw' => '/(?:[a-z]|'.$written.'|[a-c]|)+/u', 'escaped' => '/(?:[a-z]|'.$escape.'|[a-c]|)+/u', 'branches' => ['[a-z]', '[a-c]'], 'flags' => 'u', 'subjects' => ['a'], 'rule' => $charset, 'quoted' => '"[a-z]" and "[a-c]"'];
        }
    }

    /**
     * Under fr_FR.ISO8859-1, where the Latin-1 tables pair 0xC9 with 0xE9,
     * the texts fold alike whether the bytes are written as they are or as
     * escapes.
     *
     * @param list<string> $branches
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideLatin1Spellings')]
    public function test_an_escaped_spelling_gets_the_finding_of_the_spelling_it_names_under_a_latin1_locale(string $raw, string $escaped, array $branches, string $flags, array $subjects, ?string $rule, ?string $quoted = null): void
    {
        $this->requiresLocale(self::LATIN1, $escaped);

        $this->assertSameFindings($raw, $escaped, $branches, $flags, $subjects, $rule, $quoted, self::LATIN1);
    }

    /**
     * @return iterable<string, array{raw: string, escaped: string, branches: list<string>, flags: string, subjects: list<string>, rule: string|null, quoted?: string}>
     */
    public static function provideLatin1Spellings(): iterable
    {
        $text = OverlappingAlternationRule::ID_OVERLAP;
        $charset = OverlappingAlternationRule::ID_CHARSET;

        yield 'two texts a Latin-1 letter starts in either case' => ['raw' => "/(?:\xC9x|\xE9x)+/i", 'escaped' => '/(?:\xC9x|\xE9x)+/i', 'branches' => ['\xC9x', '\xE9x'], 'flags' => 'i', 'subjects' => ["\xE9x"], 'rule' => $text, 'quoted' => '"\xC9x" and "\xE9x"'];
        yield 'a capital written as an octal escape, then its small letter' => ['raw' => "/(?:\xC9x|\xE9x)+/i", 'escaped' => '/(?:\o{311}x|\xE9x)+/i', 'branches' => ['\o{311}x', '\xE9x'], 'flags' => 'i', 'subjects' => ["\xE9x"], 'rule' => $text, 'quoted' => '"\xC9x" and "\xE9x"'];
        yield 'a Latin-1 capital and its small letter beside an empty branch' => ['raw' => "/(?:\xC9|\xE9|)+/i", 'escaped' => '/(?:\xC9|\xE9|)+/i', 'branches' => ['\xC9', '\xE9'], 'flags' => 'i', 'subjects' => ["\xE9"], 'rule' => $text, 'quoted' => '"\xC9" and "\xE9"'];
        yield 'leading texts a Latin-1 letter starts in either case, then items that meet' => ['raw' => "/(?:\xC9x\\d|\xE9x\\w)+/i", 'escaped' => '/(?:\xC9x\d|\xE9x\w)+/i', 'branches' => ['\xC9x\d', '\xE9x\w'], 'flags' => 'i', 'subjects' => ["\xE9x1"], 'rule' => $charset, 'quoted' => '"\xC9x\d" and "\xE9x\w"'];
        yield 'two texts the case does not join' => ['raw' => "/(?:\xC9x|\xE9y)+/i", 'escaped' => '/(?:\xC9x|\xE9y)+/i', 'branches' => ['\xC9x', '\xE9y'], 'flags' => 'i', 'subjects' => ["\xC9x", "\xE9x", "\xC9y", "\xE9y"], 'rule' => null];
    }

    /**
     * Under tables a test gives, which pair 0xC9 with 0xE9 as the Latin-1
     * tables do, the escaped texts fold as the raw ones: CI machines often
     * hold no Latin-1 locale. Each pattern opens with an empty branch,
     * which leaves the branches the text does not join to their bytes.
     */
    #[Test]
    #[DataProvider('provideSpellingsUnderGivenTables')]
    public function test_an_escaped_spelling_gets_the_finding_of_the_spelling_it_names_under_given_tables(string $raw, string $escaped, ?string $rule): void
    {
        $cases = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $cases .= \chr(match ($byte) {
                0xC9 => 0xE9,
                0xE9 => 0xC9,
                default => Ascii::isAlpha(\chr($byte)) ? $byte ^ 0x20 : $byte,
            });
        }

        CharacterTables::useTables(self::GIVEN, $cases);

        $findings = self::findings($raw, self::GIVEN);
        $this->assertSame(null === $rule ? [] : [$rule], array_keys($findings), addcslashes($raw, "\200..\377"));
        $this->assertSame($findings, self::findings($escaped, self::GIVEN), $escaped);
    }

    /**
     * @return iterable<string, array{raw: string, escaped: string, rule: string|null}>
     */
    public static function provideSpellingsUnderGivenTables(): iterable
    {
        $text = OverlappingAlternationRule::ID_OVERLAP;
        yield 'two texts a letter starts in either case' => ['raw' => "/(?:|\xC9x|\xE9x)+/i", 'escaped' => '/(?:|\xC9x|\xE9x)+/i', 'rule' => $text];
        yield 'a capital and its small letter, as octal escapes' => ['raw' => "/(?:|\xC9|\xE9)+/i", 'escaped' => '/(?:|\311|\o{351})+/i', 'rule' => $text];
        yield 'two texts the case does not join' => ['raw' => "/(?:|\xC9x|\xEAx)+/i", 'escaped' => '/(?:|\xC9x|\xEAx)+/i', 'rule' => null];
        yield 'two texts in their own case' => ['raw' => "/(?:|\xC9x|\xE9x)+/", 'escaped' => '/(?:|\xC9x|\xE9x)+/', 'rule' => null];
    }

    /**
     * The oracle, then the findings: the two spellings match alike, both
     * branches start a subject where a rule reports them, and none where
     * none does; both spellings get the same finding.
     *
     * @param list<string> $branches
     * @param list<string> $subjects
     */
    private function assertSameFindings(string $raw, string $escaped, array $branches, string $flags, array $subjects, ?string $rule, ?string $quoted, string $locale): void
    {
        $label = \sprintf('%s under %s', addcslashes($escaped, "\200..\377"), $locale);
        $taken = new \ArrayObject();
        $alike = new \ArrayObject();
        CharacterTables::under($locale, static function () use ($raw, $escaped, $branches, $flags, $subjects, $taken, $alike): void {
            foreach ($subjects as $subject) {
                foreach ([static fn (string $pattern): string => $pattern, PcreInterpreter::pattern(...)] as $engine) {
                    $alike[] = preg_match($engine($raw), $subject) === preg_match($engine($escaped), $subject);
                    $starts = 0;
                    foreach ($branches as $branch) {
                        $starts += preg_match($engine('/^(?:'.$branch.')/'.$flags), $subject);
                    }

                    $taken[] = $starts;
                }
            }
        });

        $this->assertNotContains(false, $alike->getArrayCopy(), 'Oracle: '.$label.' matches as its other spelling does.');
        $this->assertSame(null !== $rule, \in_array(2, $taken->getArrayCopy(), true), \sprintf('Oracle: %s, whose branches start the subjects %s times.', $label, json_encode($taken->getArrayCopy())));

        $findings = self::findings($raw, $locale);
        $this->assertSame(null === $rule ? [] : [$rule], array_keys($findings), addcslashes($raw, "\200..\377"));
        if (null !== $quoted) {
            $this->assertStringContainsString($quoted, (string) reset($findings));
        }

        // The message of overlapping text quotes the texts, which the two
        // spellings share; that of overlapping characters quotes the
        // branches as written.
        $escapedFindings = self::findings($escaped, $locale);
        $this->assertSame(array_keys($findings), array_keys($escapedFindings), $label);
        if (null !== $quoted) {
            $this->assertStringContainsString($quoted, (string) reset($escapedFindings), $label);
        }

        if (isset($findings[OverlappingAlternationRule::ID_OVERLAP])) {
            $this->assertSame($findings, $escapedFindings, $label);
        }
    }

    /**
     * The findings of the rules of overlapping branches, keyed by rule.
     *
     * @return array<string, string>
     */
    private static function findings(string $pattern, string $locale): array
    {
        $regex = Regex::create(['cache' => null, 'php_version' => '8.4', 'locale' => $locale]);
        $linter = new PatternLinter($regex->target());
        $regex->parse($pattern)->accept($linter);

        $findings = [];
        foreach ($linter->getIssues() as $issue) {
            if (\in_array($issue->id, [OverlappingAlternationRule::ID_OVERLAP, OverlappingAlternationRule::ID_CHARSET], true)) {
                $findings[$issue->id] = $issue->message;
            }
        }

        return $findings;
    }
}
