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
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A control character written "\cA" is one character, as "\x01" is: the
 * rules that ask whether an item reads a character find it does, and the
 * pattern gets the findings of the same pattern written with "\x01".
 * "/(?:a+\cA)+$/" no longer reports nested quantifiers, which each "\cA"
 * keeps apart, and "/\cA+\cA+$/" reports the quantifiers that compete for
 * the same characters, as "/\x01+\x01+$/" does.
 *
 * Engine: PHP 8.4.28 / PCRE2 10.49, with and without the JIT: each pair of
 * spellings matches alike, and the interpreter answers
 * preg_match('/^(?:a+\cA)+$/', str_repeat('a', 30).'!') with 0, where
 * '/^(?:a+)+$/' exhausts the backtrack limit.
 */
final class ControlCharacterSpellingTest extends TestCase
{
    /**
     * @param list<string> $subjects the subjects both spellings are run on
     * @param list<string> $rules    the rules of the finding both spellings get, among those of the row
     */
    #[Test]
    #[DataProvider('provideSpellings')]
    public function test_a_control_character_gets_the_findings_of_its_hexadecimal_escape(string $control, string $hexadecimal, array $subjects, array $rules): void
    {
        // The oracle: both spellings match alike, with the JIT and without.
        foreach ($subjects as $subject) {
            foreach ([static fn (string $pattern): string => $pattern, PcreInterpreter::pattern(...)] as $engine) {
                $this->assertSame(preg_match($engine($hexadecimal), $subject), preg_match($engine($control), $subject), $control.' on '.bin2hex($subject));
            }
        }

        $this->assertSame(self::ids($hexadecimal), self::ids($control), $control);
        foreach (['regex.lint.quantifier.nested', 'regex.lint.quantifier.concatenation'] as $rule) {
            $this->assertSame(\in_array($rule, $rules, true), \in_array($rule, self::ids($control), true), $rule.' on '.$control);
        }
    }

    /**
     * @return iterable<string, array{control: string, hexadecimal: string, subjects: list<string>, rules: list<string>}>
     */
    public static function provideSpellings(): iterable
    {
        $subjects = ['', 'a', "\x01", "a\x01", "aa\x01a\x01", "\x01\x01\x01!", str_repeat('a', 30).'!'];
        yield 'a run of letters each closed by a control character' => ['control' => '/^(?:a+\cA)+$/', 'hexadecimal' => '/^(?:a+\x01)+$/', 'subjects' => $subjects, 'rules' => []];
        yield 'two runs of one control character' => ['control' => '/\cA+\cA+$/', 'hexadecimal' => '/\x01+\x01+$/', 'subjects' => $subjects, 'rules' => ['regex.lint.quantifier.concatenation']];
        yield 'two optional runs of one control character' => ['control' => '/\cA*\cA*$/', 'hexadecimal' => '/\x01*\x01*$/', 'subjects' => $subjects, 'rules' => ['regex.lint.quantifier.concatenation']];
        yield 'a run of control characters repeated' => ['control' => '/(?:\cA+)+$/', 'hexadecimal' => '/(?:\x01+)+$/', 'subjects' => $subjects, 'rules' => ['regex.lint.quantifier.nested']];
    }

    #[Test]
    public function test_a_run_closed_by_a_control_character_is_read_in_linear_time(): void
    {
        $this->assertSame(0, preg_match(PcreInterpreter::pattern('/^(?:a+\cA)+$/'), str_repeat('a', 30).'!'));
        $this->assertFalse(@preg_match(PcreInterpreter::pattern('/^(?:a+)+$/'), str_repeat('a', 30).'!'));

        $this->assertNotContains('regex.lint.quantifier.nested', self::ids('/^(?:a+\cA)+$/'));
    }

    /**
     * @return list<string>
     */
    private static function ids(string $pattern): array
    {
        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        $ids = array_values(array_unique(array_map(static fn ($issue): string => $issue->id, $linter->getIssues())));
        sort($ids);

        return $ids;
    }
}
