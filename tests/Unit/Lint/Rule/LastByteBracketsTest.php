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
use PHPRegex\Linter\Rule\RuleCatalog;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Tests\Support\LastByteWords;
use PHPRegex\Tests\Support\LinearTimeAssertions;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without "u", PCRE2 makes a repeat of the byte 0xFF possessive before
 * "\w", and a repeat of "\w" before that byte, lazy ones too, as if 0xFF
 * were no word character, but never a repeat written as a group, and it
 * stops a lazy repeat at the end of a group: under fr_FR.ISO8859-1, where
 * "\w" takes 0xFF, "/(?:\xFF)+\w/" finds "\xFF\xFF" where "/\xFF+\w/" finds
 * nothing, and "/\xFF+?(?:)\w/" finds it where "/\xFF+?\w/" does not. A
 * group whose brackets decide it is neither redundant nor empty where the
 * tables of the target may hold 0xFF in "\w": those of a locale that does,
 * or of a target that names no locale but C, which may run under any.
 *
 * Checked on PHP 8.4.28 with PCRE2 10.49, the JIT on and off, under C,
 * fr_FR.ISO8859-1 and el_GR.ISO8859-7.
 */
final class LastByteBracketsTest extends TestCase
{
    use InstalledLocales;
    use LinearTimeAssertions;

    private const FLAGS = \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL;

    private const LATIN1 = 'fr_FR.ISO8859-1';

    /**
     * A locale whose "\w" takes 0xFE but not 0xFF.
     */
    private const GREEK = 'el_GR.ISO8859-7';

    /**
     * Under Latin-1 the witness tells the pattern apart from the one
     * without the group, the JIT on and off; a target that names that
     * locale reports no group.
     */
    #[Test]
    #[DataProvider('provideBracketsBesideTheLastByte')]
    public function test_a_group_whose_brackets_decide_a_repeat_beside_the_last_byte_is_not_reported_under_latin1(string $rule, string $pattern, string $group, string $without, string $witness): void
    {
        $this->requiresLocale(self::LATIN1, $pattern);

        $apart = new \ArrayObject();
        CharacterTables::under(self::LATIN1, static function () use ($pattern, $without, $witness, $apart): void {
            $apart[] = self::matchesOf($pattern, $witness) !== self::matchesOf($without, $witness);
            $apart[] = self::matchesOf(PcreInterpreter::pattern($pattern), $witness) !== self::matchesOf(PcreInterpreter::pattern($without), $witness);
        });
        $this->assertSame([true, true], $apart->getArrayCopy(), \sprintf('%s and %s write the same matches on %s under Latin-1.', $pattern, $without, json_encode(bin2hex($witness))));

        $this->assertSame([], self::reported($rule, $pattern, $group, self::LATIN1));
    }

    /**
     * Under C's tables the engine reads each pattern as it reads the one
     * without the group, yet a target that names no locale but C may run
     * under any, and the given word characters of Latin-1 hold 0xFF: no
     * group is reported on any machine. The given word characters of
     * Greek leave 0xFF out, where the group goes. The Latin-1 witness is
     * checked above, where this machine can set that locale.
     */
    #[Test]
    #[DataProvider('provideBracketsBesideTheLastByte')]
    public function test_a_group_whose_brackets_decide_a_repeat_beside_the_last_byte_is_not_reported_where_the_tables_may_hold_it_in_a_word(string $rule, string $pattern, string $group, string $without, string $witness): void
    {
        $alike = new \ArrayObject();
        CharacterTables::under(CharacterTables::BUILT_IN, static function () use ($pattern, $without, $witness, $alike): void {
            $alike[] = self::matchesOf($pattern, $witness) === self::matchesOf($without, $witness);
            $alike[] = self::matchesOf(PcreInterpreter::pattern($pattern), $witness) === self::matchesOf(PcreInterpreter::pattern($without), $witness);
        });
        $this->assertSame([true, true], $alike->getArrayCopy(), 'C\'s tables write the same matches.');

        LastByteWords::give();
        foreach ([null, CharacterTables::BUILT_IN, LastByteWords::LATIN1] as $locale) {
            $this->assertSame([], self::reported($rule, $pattern, $group, $locale), (string) $locale);
        }

        $this->assertCount(1, self::reported($rule, $pattern, $group, LastByteWords::GREEK));
    }

    /**
     * Under el_GR.ISO8859-7 "\w" takes 0xFE and leaves 0xFF out: the engine
     * reads each pattern as it reads the one without the group, the JIT on
     * and off, and a target that names that locale reports the group.
     */
    #[Test]
    #[DataProvider('provideBracketsBesideTheLastByte')]
    public function test_a_group_beside_the_last_byte_is_reported_under_tables_that_leave_it_out_of_a_word(string $rule, string $pattern, string $group, string $without, string $witness): void
    {
        $this->requiresLocale(self::GREEK, $pattern);

        $alike = new \ArrayObject();
        CharacterTables::under(self::GREEK, static function () use ($pattern, $without, $witness, $alike): void {
            $alike[] = self::matchesOf($pattern, $witness) === self::matchesOf($without, $witness);
            $alike[] = self::matchesOf(PcreInterpreter::pattern($pattern), $witness) === self::matchesOf(PcreInterpreter::pattern($without), $witness);
        });
        $this->assertSame([true, true], $alike->getArrayCopy(), 'The Greek tables write the same matches.');

        $this->assertCount(1, self::reported($rule, $pattern, $group, self::GREEK));
    }

    /**
     * @return iterable<string, array{rule: string, pattern: string, group: string, without: string, witness: string}>
     */
    public static function provideBracketsBesideTheLastByte(): iterable
    {
        $redundant = 'regex.lint.group.redundant';
        $empty = 'regex.lint.group.empty';

        yield 'the byte repeated before a word character' => ['rule' => $redundant, 'pattern' => '/(?:\xFF)+\w/', 'group' => '(?:\xFF)', 'without' => '/\xFF+\w/', 'witness' => "\xFF\xFF"];
        yield 'the byte repeated lazily' => ['rule' => $redundant, 'pattern' => '/(?:\xFF)+?\w/', 'group' => '(?:\xFF)', 'without' => '/\xFF+?\w/', 'witness' => "\xFF\xFF"];
        yield 'an optional byte' => ['rule' => $redundant, 'pattern' => '/(?:\xFF)?\w/', 'group' => '(?:\xFF)', 'without' => '/\xFF?\w/', 'witness' => "\xFF"];
        yield 'the byte counted between two bounds' => ['rule' => $redundant, 'pattern' => '/(?:\xFF){1,3}\w/', 'group' => '(?:\xFF)', 'without' => '/\xFF{1,3}\w/', 'witness' => "\xFF\xFF"];
        yield 'a word character repeated before the byte' => ['rule' => $redundant, 'pattern' => '/(?:\w)+\xFF/', 'group' => '(?:\w)', 'without' => '/\w+\xFF/', 'witness' => "a\xFF\xFF"];
        yield 'a word character repeated lazily before the byte' => ['rule' => $redundant, 'pattern' => '/(?:\w)+?\xFF/', 'group' => '(?:\w)', 'without' => '/\w+?\xFF/', 'witness' => "a\xFF\xFF"];
        yield 'U+00FF under (*UTF) without u' => ['rule' => $redundant, 'pattern' => '/(*UTF)(?:\x{FF})+\w/', 'group' => '(?:\x{FF})', 'without' => '/(*UTF)\x{FF}+\w/', 'witness' => "\u{FF}\u{FF}"];
        yield 'a comment between a lazy repeat and a word character' => ['rule' => $redundant, 'pattern' => '/\xFF+?(?:(?#c))\w/', 'group' => '(?:(?#c))', 'without' => '/\xFF+?(?#c)\w/', 'witness' => "\xFF\xFF"];
        yield 'an empty group between a lazy repeat and a word character' => ['rule' => $empty, 'pattern' => '/\xFF+?(?:)\w/', 'group' => '(?:)', 'without' => '/\xFF+?\w/', 'witness' => "\xFF\xFF"];
        yield 'an empty group between a lazy repeat of a word character and the byte' => ['rule' => $empty, 'pattern' => '/\w+?(?:)\xFF/', 'group' => '(?:)', 'without' => '/\w+?\xFF/', 'witness' => "a\xFF\xFF"];
    }

    /**
     * Where the engine reads the pattern as it reads the one without the
     * group under Latin-1, on every subject over its letters, the JIT on
     * and off, a target that names that locale reports the group.
     */
    #[Test]
    #[DataProvider('provideBracketsReadAsWritten')]
    public function test_a_group_the_engine_reads_as_its_body_is_reported_under_latin1(string $rule, string $pattern, string $group, string $without, string $letters): void
    {
        $this->requiresLocale(self::LATIN1, $pattern);

        $alike = new \ArrayObject();
        CharacterTables::under(self::LATIN1, static function () use ($pattern, $without, $letters, $alike): void {
            foreach (self::subjects($letters, 4) as $subject) {
                $alike[] = self::matchesOf($pattern, $subject) === self::matchesOf($without, $subject);
                $alike[] = self::matchesOf(PcreInterpreter::pattern($pattern), $subject) === self::matchesOf(PcreInterpreter::pattern($without), $subject);
            }
        });
        $this->assertNotContains(false, $alike->getArrayCopy(), 'Latin-1 writes the same matches.');

        $this->assertCount(1, self::reported($rule, $pattern, $group, self::LATIN1));
    }

    /**
     * Those groups are reported on every machine, for a target that names
     * no locale and for the given word characters of Latin-1.
     */
    #[Test]
    #[DataProvider('provideBracketsReadAsWritten')]
    public function test_a_group_the_engine_reads_as_its_body_is_reported_where_the_tables_may_hold_the_last_byte_in_a_word(string $rule, string $pattern, string $group, string $without, string $letters): void
    {
        $alike = new \ArrayObject();
        CharacterTables::under(CharacterTables::BUILT_IN, static function () use ($pattern, $without, $letters, $alike): void {
            foreach (self::subjects($letters, 4) as $subject) {
                $alike[] = self::matchesOf($pattern, $subject) === self::matchesOf($without, $subject);
            }
        });
        $this->assertNotContains(false, $alike->getArrayCopy(), 'C\'s tables write the same matches.');

        LastByteWords::give();
        foreach ([null, LastByteWords::LATIN1] as $locale) {
            $this->assertCount(1, self::reported($rule, $pattern, $group, $locale), (string) $locale);
        }
    }

    /**
     * @return iterable<string, array{rule: string, pattern: string, group: string, without: string, letters: string}>
     */
    public static function provideBracketsReadAsWritten(): iterable
    {
        $redundant = 'regex.lint.group.redundant';
        $empty = 'regex.lint.group.empty';

        yield 'the byte below repeated before a word character' => ['rule' => $redundant, 'pattern' => '/(?:\xFE)+\w/', 'group' => '(?:\xFE)', 'without' => '/\xFE+\w/', 'letters' => "\xFEa"];
        yield 'the byte after a word character, neither repeated' => ['rule' => $redundant, 'pattern' => '/\w(?:\xFF)/', 'group' => '(?:\xFF)', 'without' => '/\w\xFF/', 'letters' => "a\xFF"];
        yield 'the byte repeated before a class of word characters' => ['rule' => $redundant, 'pattern' => '/(?:\xFF)+[\w]/', 'group' => '(?:\xFF)', 'without' => '/\xFF+[\w]/', 'letters' => "\xFFa"];
        yield 'the byte repeated before a word character under u' => ['rule' => $redundant, 'pattern' => '/(?:\x{FF})+\w/u', 'group' => '(?:\x{FF})', 'without' => '/\x{FF}+\w/u', 'letters' => "\u{FF}a"];
        yield 'an empty group after a lazy repeat of the byte below' => ['rule' => $empty, 'pattern' => '/\xFE+?(?:)\w/', 'group' => '(?:)', 'without' => '/\xFE+?\w/', 'letters' => "\xFEa"];
        yield 'an empty group after a lazy repeat of the byte under u' => ['rule' => $empty, 'pattern' => '/\x{FF}+?(?:)\w/u', 'group' => '(?:)', 'without' => '/\x{FF}+?\w/u', 'letters' => "\u{FF}a"];
    }

    /**
     * The pattern is read beside the byte once, whichever of its groups is
     * asked about: thousands of groups around "\w" before the byte are
     * linted in linear time, whether or not a repeat beside it keeps them.
     */
    #[Test]
    #[DataProvider('provideManyGroupsBesideTheLastByte')]
    public function test_many_groups_beside_the_last_byte_are_linted_in_linear_time(string $tail, int $reported): void
    {
        // Parsed before: only the reading of the rules is timed.
        $tree = Regex::create(['cache' => null])->parse('/'.str_repeat('(?:\w)', 4 * 1000).$tail.'/');
        $linter = new PatternLinter(['group.redundant' => true, 'group.empty' => true] + array_fill_keys(RuleCatalog::lintRuleIds(), false));

        $this->assertLinearTime(static function () use ($tree, $linter): void {
            $tree->accept($linter);
        }, 1000, 'groups around "\w" before '.$tail);
        $this->assertCount($reported, $linter->getIssues());
    }

    /**
     * @return iterable<string, array{tail: string, reported: int}>
     */
    public static function provideManyGroupsBesideTheLastByte(): iterable
    {
        yield 'the byte' => ['tail' => '\xFF', 'reported' => 4 * 1000];
        yield 'a word character repeated before the byte' => ['tail' => '\w+\xFF', 'reported' => 0];
    }

    /**
     * The messages the rule gives about the group, for a target of the
     * locale, or of the running one when null.
     *
     * @return list<string>
     */
    private static function reported(string $rule, string $pattern, string $group, ?string $locale): array
    {
        $options = null === $locale ? ['cache' => null] : ['cache' => null, 'locale' => $locale];
        $messages = [];
        foreach (Regex::create($options)->lint($pattern) as $violation) {
            if ($rule === $violation->id && str_contains($violation->message, '"'.$group.'"')) {
                $messages[] = $violation->message;
            }
        }

        return $messages;
    }

    /**
     * @return array<int|string, mixed>|false
     */
    private static function matchesOf(string $pattern, string $subject): array|false
    {
        $matches = [];

        return false === preg_match($pattern, $subject, $matches, self::FLAGS) ? false : $matches;
    }

    /**
     * Every string over the letters, up to the length.
     *
     * @return list<string>
     */
    private static function subjects(string $letters, int $length): array
    {
        $all = [''];
        $layer = [''];
        for ($size = 1; $size <= $length; $size++) {
            $next = [];
            foreach ($layer as $prefix) {
                foreach (mb_str_split($letters, 1, mb_check_encoding($letters, 'UTF-8') ? 'UTF-8' : '8bit') as $letter) {
                    $next[] = $prefix.$letter;
                }
            }
            $all = [...$all, ...$next];
            $layer = $next;
        }

        return $all;
    }
}
