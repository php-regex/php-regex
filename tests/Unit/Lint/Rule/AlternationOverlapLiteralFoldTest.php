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
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without u, the text of a branch read caselessly folds through the tables
 * of the target's locale: under fr_FR.ISO8859-1, where the Latin-1 tables
 * pair 0xC9 with 0xE9, "\xC9x" and "\xE9x" fold to the same text, and two
 * literal branches that do are reported as overlapping text, as "Ax" and
 * "ax" are in ASCII; under C, where neither byte has another case, they
 * stay apart. Branches that start with something else than literal text,
 * an escape among them, are still compared by their characters, and the
 * texts they start with fold the same way. A byte joins its other case
 * only where each is the other's, on every path of the engine: Greek's
 * final sigma 0xF2 matches the capital 0xD3, whose other case is 0xF3.
 *
 * Engine: PHP 8.4.28 / PCRE2 10.49 on macOS, with and without the JIT:
 * preg_match("/^\xC9x$/i", "\xE9x") is 1 under fr_FR.ISO8859-1 and 0 under
 * C; under el_GR.ISO8859-7 preg_match("/^(?i:\xD3)$/", "\xF2") is 0 and
 * preg_match("/^\xF2$/i", "\xD3") 1 on the JIT, 0 on the interpreter.
 */
final class AlternationOverlapLiteralFoldTest extends TestCase
{
    use InstalledLocales;

    private const LATIN1 = 'fr_FR.ISO8859-1';

    /**
     * The locale of the tables a test gives.
     */
    private const GIVEN = 'xx_XX.FOLD';

    /**
     * @param array{string, string} $branches the branches, each with the flags it reads its text under
     * @param string|null           $rule     the rule that reports the alternation, none for null
     */
    #[Test]
    #[DataProvider('provideRows')]
    public function test_literal_branches_fold_through_the_tables_of_the_target_locale(string $pattern, array $branches, string $subject, string $locale, ?string $rule): void
    {
        if (CharacterTables::BUILT_IN !== $locale) {
            $this->requiresLocale($locale, $pattern);
        }

        // Oracle: under the row's locale, both branches start the subject
        // where a rule reports them.
        $taken = new \ArrayObject();
        CharacterTables::under($locale, static function () use ($branches, $subject, $taken): void {
            foreach ($branches as $branch) {
                $taken[] = preg_match('/^(?:'.$branch.')/', $subject);
            }
        });
        $label = \sprintf('%s under %s', addcslashes($pattern, "\200..\377"), $locale);
        $this->assertSame(null !== $rule, [1, 1] === $taken->getArrayCopy(), \sprintf('Oracle: %s, whose branches start %s in %s.', $label, bin2hex($subject), json_encode($taken->getArrayCopy())));

        $this->assertSame(null === $rule ? [] : [$rule], self::overlapRules($pattern, $locale), $label);
    }

    /**
     * @return iterable<string, array{pattern: string, branches: array{string, string}, subject: string, locale: string, rule: string|null}>
     */
    public static function provideRows(): iterable
    {
        $text = OverlappingAlternationRule::ID_OVERLAP;
        $charset = OverlappingAlternationRule::ID_CHARSET;
        foreach ([CharacterTables::BUILT_IN => false, self::LATIN1 => true] as $locale => $latin1) {
            yield 'two texts a Latin-1 letter starts in either case, '.$locale => ['pattern' => "/(?:\xC9x|\xE9x)+/i", 'branches' => ["(?i:\xC9x)", "(?i:\xE9x)"], 'subject' => "\xE9x", 'locale' => $locale, 'rule' => $latin1 ? $text : null];
            yield 'a Latin-1 capital and its small letter, '.$locale => ['pattern' => "/(?:\xC9|\xE9)+/i", 'branches' => ["(?i:\xC9)", "(?i:\xE9)"], 'subject' => "\xE9", 'locale' => $locale, 'rule' => $latin1 ? $text : null];
            yield 'a Latin-1 capital and its small letter beside an empty branch, '.$locale => ['pattern' => "/(?:\xC9|\xE9|)+/i", 'branches' => ["(?i:\xC9)", "(?i:\xE9)"], 'subject' => "\xE9", 'locale' => $locale, 'rule' => $latin1 ? $text : null];
            yield 'a text that starts the other in the other case, '.$locale => ['pattern' => "/(?:\xE9x|\xC9xy)+/i", 'branches' => ["(?i:\xE9x)", "(?i:\xC9xy)"], 'subject' => "\xC9xy", 'locale' => $locale, 'rule' => $latin1 ? $text : null];
            yield 'a capital a group reads caselessly, then its small letter, '.$locale => ['pattern' => "/(?:(?i:\xC9)x|\xE9x)+/", 'branches' => ["(?i:\xC9)x", "\xE9x"], 'subject' => "\xE9x", 'locale' => $locale, 'rule' => $latin1 ? $text : null];
            yield 'leading texts a Latin-1 letter starts in either case, then items that meet, '.$locale => ['pattern' => "/(?:\xC9x\\d|\xE9x\\w)+/i", 'branches' => ["(?i:\xC9x\\d)", "(?i:\xE9x\\w)"], 'subject' => "\xE9x1", 'locale' => $locale, 'rule' => $latin1 ? $charset : null];
            yield 'the same texts written as escapes, '.$locale => ['pattern' => '/(?:\xC9x|\xE9x)+/i', 'branches' => ['(?i:\xC9x)', '(?i:\xE9x)'], 'subject' => "\xE9x", 'locale' => $locale, 'rule' => $latin1 ? $charset : null];
            yield 'a capital written as it is, then its small letter as an escape, '.$locale => ['pattern' => "/(?:\xC9x|\\o{351}x)+/i", 'branches' => ["(?i:\xC9x)", '(?i:\o{351}x)'], 'subject' => "\xE9x", 'locale' => $locale, 'rule' => $latin1 ? $charset : null];
        }

        foreach ([CharacterTables::BUILT_IN, self::LATIN1] as $locale) {
            yield 'a Latin-1 capital and its small letter read in their own case, '.$locale => ['pattern' => "/(?:\xC9x|\xE9x)+/", 'branches' => ["\xC9x", "\xE9x"], 'subject' => "\xE9x", 'locale' => $locale, 'rule' => null];
            yield 'two texts the case does not join, '.$locale => ['pattern' => "/(?:\xC9x|\xE9y)+/i", 'branches' => ["(?i:\xC9x)", "(?i:\xE9y)"], 'subject' => "\xE9x", 'locale' => $locale, 'rule' => null];
            yield 'two signs without a case, '.$locale => ['pattern' => "/(?:\xD7x|\xF7x)+/i", 'branches' => ["(?i:\xD7x)", "(?i:\xF7x)"], 'subject' => "\xF7x", 'locale' => $locale, 'rule' => null];
            yield 'two ASCII texts that differ by case, '.$locale => ['pattern' => '/(?:Ax|ax)+/i', 'branches' => ['(?i:Ax)', '(?i:ax)'], 'subject' => 'ax', 'locale' => $locale, 'rule' => $text];
        }

        yield 'a Greek capital read caselessly, then the final sigma, el_GR.ISO8859-7' => ['pattern' => "/(?:(?i:\xD3)|\xF2)+/", 'branches' => ["(?i:\xD3)", "\xF2"], 'subject' => "\xF2", 'locale' => 'el_GR.ISO8859-7', 'rule' => null];
    }

    /**
     * Under tables a test gives, as no machine here builds them, the texts
     * fold as they pair the bytes: 0xC9 with 0xE9 and 0xD3 with 0xF3 each
     * way, the final sigma 0xF2 with 0xD3 alone, and 0xB5 read otherwise
     * by the interpreter. Each pattern opens with an empty branch, which
     * leaves the branches the text does not join to their bytes.
     *
     * @param string|null $rule the rule that reports the alternation, none for null
     */
    #[Test]
    #[DataProvider('provideRowsUnderGivenTables')]
    public function test_literal_branches_fold_as_given_tables_pair_their_bytes(string $pattern, ?string $rule): void
    {
        $others = [0xC9 => 0xE9, 0xE9 => 0xC9, 0xD3 => 0xF3, 0xF3 => 0xD3, 0xF2 => 0xD3];
        $cases = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $cases .= \chr($others[$byte] ?? (Ascii::isAlpha(\chr($byte)) ? $byte ^ 0x20 : $byte));
        }

        CharacterTables::useTables(self::GIVEN, $cases, [0xB5]);

        $this->assertSame(null === $rule ? [] : [$rule], self::overlapRules($pattern, self::GIVEN), addcslashes($pattern, "\200..\377"));
    }

    /**
     * @return iterable<string, array{pattern: string, rule: string|null}>
     */
    public static function provideRowsUnderGivenTables(): iterable
    {
        $text = OverlappingAlternationRule::ID_OVERLAP;
        yield 'two texts a letter starts in either case' => ['pattern' => "/(?:|\xC9x|\xE9x)+/i", 'rule' => $text];
        yield 'a capital and its small letter' => ['pattern' => "/(?:|\xC9|\xE9)+/i", 'rule' => $text];
        yield 'a small letter and its capital' => ['pattern' => "/(?:|\xF3|\xD3)+/i", 'rule' => $text];
        yield 'two texts the case does not join' => ['pattern' => "/(?:|\xC9x|\xEAx)+/i", 'rule' => null];
        yield 'a capital read caselessly, then a byte paired with it one way' => ['pattern' => "/(?:|(?i:\xD3)|\xF2)+/", 'rule' => null];
        yield 'a byte the interpreter reads otherwise' => ['pattern' => "/(?:|\xB5x|\xC9x)+/i", 'rule' => null];
        yield 'two ASCII texts that differ by case' => ['pattern' => '/(?:|Ax|ax)+/i', 'rule' => $text];
        yield 'two texts in their own case' => ['pattern' => "/(?:|\xC9x|\xE9x)+/", 'rule' => null];
        // Under u the case of a letter is Unicode's, whatever the tables.
        yield 'two texts a letter starts in either case under u' => ['pattern' => '/(?:|Éx|éx)+/iu', 'rule' => $text];
        yield 'two texts the case does not join under u' => ['pattern' => '/(?:|Éx|êx)+/iu', 'rule' => null];
    }

    /**
     * The rules of overlapping branches that report the pattern under the
     * locale: the overlap of their texts or of their characters.
     *
     * @return list<string>
     */
    private static function overlapRules(string $pattern, string $locale): array
    {
        $regex = Regex::create(['cache' => null, 'php_version' => '8.4', 'locale' => $locale]);
        $linter = new PatternLinter([], $regex->target());
        $regex->parse($pattern)->accept($linter);

        $ids = array_map(static fn ($issue): string => $issue->id, $linter->getIssues());

        return array_values(array_intersect([OverlappingAlternationRule::ID_OVERLAP, OverlappingAlternationRule::ID_CHARSET], $ids));
    }
}
