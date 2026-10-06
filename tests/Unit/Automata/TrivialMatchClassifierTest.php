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

namespace PHPRegex\Tests\Unit\Automata;

use PHPRegex\Automata\TrivialMatch;
use PHPRegex\Automata\TrivialMatchClassifier;
use PHPRegex\Automata\TrivialMatchKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A preg_match() a string function answers alike: the classifier names the
 * function only once the automata prove that preg_match() returns 1 for
 * exactly the subjects the function says true for.
 */
final class TrivialMatchClassifierTest extends TestCase
{
    private const SUBJECTS = ['', 'foo', 'xfoo', 'foox', "foo\n", "xfoo\n", 'FOO', 'GET', 'POST', 'GETX', 'a', 'b', 'ab', 'f.o', 'fxo', "it's", "\n", 'color', 'colour', 'colouur'];

    /**
     * @param list<string> $literals
     */
    #[Test]
    #[DataProvider('provideTrivialPatterns')]
    public function test_a_trivial_pattern_is_named_with_its_function(string $pattern, TrivialMatchKind $kind, array $literals, string $expression): void
    {
        $match = (new TrivialMatchClassifier())->classify($pattern);

        $this->assertInstanceOf(TrivialMatch::class, $match);
        $this->assertSame($kind, $match->kind);
        $this->assertSame($literals, $match->literals);
        $this->assertSame($expression, $match->phpExpression('$subject'));

        foreach (self::SUBJECTS as $subject) {
            $this->assertSame(1 === preg_match($pattern, $subject), $match->accepts($subject), \sprintf('%s and %s disagree on %s.', $pattern, $expression, json_encode($subject)));
        }
    }

    #[Test]
    #[DataProvider('provideOtherPatterns')]
    public function test_a_pattern_no_function_answers_alike_is_not_named(string $pattern): void
    {
        $this->assertNull((new TrivialMatchClassifier())->classify($pattern));
    }

    #[Test]
    public function test_a_limit_verb_can_fail_where_a_string_function_answers(): void
    {
        // Without the JIT, a one-step match limit set in the pattern fails
        // the call (false), where str_contains() answers: a pattern that
        // sets its own limits is no string function.
        $jit = (string) \ini_get('pcre.jit');
        ini_set('pcre.jit', '0');

        try {
            $this->assertFalse(preg_match('/(*LIMIT_MATCH=1)limit/', str_repeat('x', 100).'limit'));
        } finally {
            ini_set('pcre.jit', $jit);
        }

        $this->assertNull((new TrivialMatchClassifier())->classify('/(*LIMIT_MATCH=1)limit/'));
        $this->assertNull((new TrivialMatchClassifier())->matchedLiteral('/(*LIMIT_MATCH=1)limit/'));
    }

    /**
     * A repetition that exhausts the solver's state budget is not proven,
     * whatever the engine says of it: no proof, no answer. The same string
     * spelled out stays within the budget and is proven.
     */
    #[Test]
    public function test_a_repetition_past_the_solver_budget_is_not_proven(): void
    {
        $literal = str_repeat('a', 4500);
        $this->assertSame(1, preg_match('/^a{4500}\z/', $literal));

        $this->assertNull((new TrivialMatchClassifier())->matchedLiteral('/a{4500}/'));
        $this->assertSame($literal, (new TrivialMatchClassifier())->matchedLiteral('/'.$literal.'/'));
        $this->assertEquals(new TrivialMatch(TrivialMatchKind::Equals, [$literal]), (new TrivialMatchClassifier())->classify('/^'.$literal.'\z/'));
    }

    /**
     * preg_replace() and preg_split() scan left to right and take
     * non-overlapping matches, as str_replace() and explode() do: once the
     * pattern matches one string and only that one, they answer alike.
     *
     * @param non-empty-string $literal
     */
    #[Test]
    #[DataProvider('provideOneLiteralPatterns')]
    public function test_matched_literal_names_the_one_string_a_pattern_matches(string $pattern, string $literal): void
    {
        foreach (self::literalSubjects($literal) as $subject) {
            $this->assertSame(preg_replace($pattern, 'X', $subject), str_replace($literal, 'X', $subject), \sprintf('preg_replace() with %s and str_replace() disagree on %s.', $pattern, bin2hex($subject)));
            $this->assertSame(preg_split($pattern, $subject), explode($literal, $subject), \sprintf('preg_split() with %s and explode() disagree on %s.', $pattern, bin2hex($subject)));
        }

        $this->assertSame($literal, (new TrivialMatchClassifier())->matchedLiteral($pattern));
    }

    #[Test]
    #[DataProvider('provideNoLiteralPatterns')]
    public function test_matched_literal_refuses_a_pattern_without_one_literal(string $pattern): void
    {
        $this->assertNull((new TrivialMatchClassifier())->matchedLiteral($pattern));
    }

    #[Test]
    public function test_matched_literal_refuses_a_language_of_one_string_preg_replace_reads_greedily(): void
    {
        // The partial-match language of /ab?/ is that of /a/, but its greedy
        // match replaces "ab" whole: only the full-match language counts.
        $this->assertSame('X', preg_replace('/ab?/', 'X', 'ab'));
        $this->assertSame('Xb', str_replace('a', 'X', 'ab'));

        $this->assertNull((new TrivialMatchClassifier())->matchedLiteral('/ab?/'));
    }

    /**
     * A string the pattern reaches along two paths makes the engine try
     * both at every failing position: on forty "a" and a "c", the twenty
     * paths double up past the backtrack limit and the call fails where the
     * string function answers.
     */
    #[Test]
    public function test_an_ambiguous_alternation_can_fail_where_a_string_function_answers(): void
    {
        $subject = str_repeat('a', 40).'c'.str_repeat('a', 20).'b';
        $this->assertNull(preg_replace('/(?:a|a){20}b/', 'X', $subject));
        $this->assertFalse(preg_split('/(?:a|a){20}b/', $subject));
        $this->assertFalse(preg_match('/(?:a|a){4}(?:a|a){4}(?:a|a){4}(?:a|a){4}(?:a|a){4}b/', $subject));
        $this->assertStringContainsString(str_repeat('a', 20).'b', $subject);

        $this->assertNull((new TrivialMatchClassifier())->matchedLiteral('/(?:a|a){20}b/'));
        $this->assertNull((new TrivialMatchClassifier())->classify('/(?:a|a){4}(?:a|a){4}(?:a|a){4}(?:a|a){4}(?:a|a){4}b/'));
    }

    /**
     * Under a locale whose tables call 0xA0 white space, extended mode skips
     * the raw byte in the pattern: "/prix\xA0eur/x" then matches "prixeur".
     * Run apart: once setlocale() named a locale, PHP keeps building PCRE's
     * tables from LC_CTYPE, even after the previous name is set back.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function test_extended_mode_skips_a_raw_byte_the_locale_calls_white_space(): void
    {
        $previous = (string) setlocale(\LC_CTYPE, '0');
        $skipped = false;
        if (false !== setlocale(\LC_CTYPE, 'nl_NL.UTF-8')) {
            try {
                $skipped = 1 === preg_match("/prix\xa0eur/x", 'prixeur');
            } finally {
                setlocale(\LC_CTYPE, $previous);
            }
        }

        // The C library decides: macOS calls 0xA0 white space there, others
        // may not; the pattern is refused wherever it runs.
        if ('Darwin' === \PHP_OS_FAMILY) {
            $this->assertTrue($skipped);
        }
        $this->assertNull((new TrivialMatchClassifier())->matchedLiteral("/prix\xa0eur/x"));
        $this->assertNull((new TrivialMatchClassifier())->classify("/prix\xa0eur/x"));
    }

    #[Test]
    public function test_a_dollar_also_takes_a_final_newline(): void
    {
        $this->assertSame(1, preg_match('/^foo$/', "foo\n"));

        $match = (new TrivialMatchClassifier())->classify('/^foo$/');
        $this->assertInstanceOf(TrivialMatch::class, $match);
        $this->assertNotSame(TrivialMatchKind::Equals, $match->kind);
    }

    /**
     * @return iterable<string, array{pattern: string, kind: TrivialMatchKind, literals: list<string>, expression: string}>
     */
    public static function provideTrivialPatterns(): iterable
    {
        yield 'contains' => ['pattern' => '/foo/', 'kind' => TrivialMatchKind::Contains, 'literals' => ['foo'], 'expression' => "str_contains(\$subject, 'foo')"];
        yield 'starts with' => ['pattern' => '/^foo/', 'kind' => TrivialMatchKind::StartsWith, 'literals' => ['foo'], 'expression' => "str_starts_with(\$subject, 'foo')"];
        yield 'ends with' => ['pattern' => '/foo\z/', 'kind' => TrivialMatchKind::EndsWith, 'literals' => ['foo'], 'expression' => "str_ends_with(\$subject, 'foo')"];
        yield 'equals' => ['pattern' => '/^foo\z/', 'kind' => TrivialMatchKind::Equals, 'literals' => ['foo'], 'expression' => "'foo' === \$subject"];
        yield 'equals under D' => ['pattern' => '/^foo$/D', 'kind' => TrivialMatchKind::Equals, 'literals' => ['foo'], 'expression' => "'foo' === \$subject"];
        yield 'dollar takes a final newline' => ['pattern' => '/^foo$/', 'kind' => TrivialMatchKind::OneOf, 'literals' => ['foo', "foo\n"], 'expression' => "in_array(\$subject, ['foo', \"foo\\n\"], true)"];
        yield 'one of two words' => ['pattern' => '/^(?:GET|POST)\z/', 'kind' => TrivialMatchKind::OneOf, 'literals' => ['GET', 'POST'], 'expression' => "in_array(\$subject, ['GET', 'POST'], true)"];
        yield 'one of a class' => ['pattern' => '/^[ab]\z/', 'kind' => TrivialMatchKind::OneOf, 'literals' => ['a', 'b'], 'expression' => "in_array(\$subject, ['a', 'b'], true)"];
        yield 'empty' => ['pattern' => '/^\z/', 'kind' => TrivialMatchKind::IsEmpty, 'literals' => [], 'expression' => "'' === \$subject"];
        yield 'a group changes nothing' => ['pattern' => '/^(foo)\z/', 'kind' => TrivialMatchKind::Equals, 'literals' => ['foo'], 'expression' => "'foo' === \$subject"];
        yield 'escaped dot' => ['pattern' => '/f\.o/', 'kind' => TrivialMatchKind::Contains, 'literals' => ['f.o'], 'expression' => "str_contains(\$subject, 'f.o')"];
        yield 'optional letter' => ['pattern' => '/^colou?r\z/', 'kind' => TrivialMatchKind::OneOf, 'literals' => ['color', 'colour'], 'expression' => "in_array(\$subject, ['color', 'colour'], true)"];
        yield 'multiline without an anchor' => ['pattern' => '/foo/m', 'kind' => TrivialMatchKind::Contains, 'literals' => ['foo'], 'expression' => "str_contains(\$subject, 'foo')"];
        yield 'quote' => ['pattern' => "/it's/", 'kind' => TrivialMatchKind::Contains, 'literals' => ["it's"], 'expression' => "str_contains(\$subject, 'it\\'s')"];
        // Without "x", a raw byte above 0x7F is a literal whatever the locale.
        yield 'raw high byte without extended mode' => ['pattern' => "/prix\xa0eur/", 'kind' => TrivialMatchKind::Contains, 'literals' => ["prix\xa0eur"], 'expression' => 'str_contains($subject, "prix\\xA0eur")'];
        // Sixteen paths is the most read; a seventeenth is refused.
        yield 'sixteen alternatives' => ['pattern' => '/^(?:a|b|c|d|e|f|g|h|i|j|k|l|m|n|o|p)\z/', 'kind' => TrivialMatchKind::OneOf, 'literals' => range('a', 'p'), 'expression' => "in_array(\$subject, ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p'], true)"];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideOtherPatterns(): iterable
    {
        yield 'caseless' => ['pattern' => '/foo/i'];
        yield 'seventeen alternatives' => ['pattern' => '/^(?:a|b|c|d|e|f|g|h|i|j|k|l|m|n|o|p|q)\z/'];
        yield 'utf mode fails on invalid subjects' => ['pattern' => '/foo/u'];
        yield 'multiline anchor' => ['pattern' => '/^foo/m'];
        yield 'repeat' => ['pattern' => '/fo+/'];
        yield 'dollar at the end only' => ['pattern' => '/foo$/'];
        yield 'either of two, anywhere' => ['pattern' => '/a|b/'];
        yield 'anchor on one branch' => ['pattern' => '/^foo|bar/'];
        yield 'matches every subject' => ['pattern' => '//'];
        yield 'backreference' => ['pattern' => '/(a)\1/'];
        yield 'invalid' => ['pattern' => '/(foo/'];
        yield 'class of too many letters' => ['pattern' => '/^[a-z]\z/'];
        yield 'optional class of too many letters' => ['pattern' => '/^\w?\z/'];
        yield 'too many combinations' => ['pattern' => '/^[ab][ab][ab][ab][ab]\z/'];
        // PHP builds PCRE's character and case tables from LC_CTYPE once a
        // program calls setlocale(): under fr_FR.ISO8859-1 (macOS),
        // preg_match('/^[[:alpha:]]\z/', "\xE9") and preg_match('/^\w\z/',
        // "\xE9") return 1, and /^\xe9\z/i takes "\xC9". In byte mode the
        // shorthand classes, the POSIX classes and caseless matching are
        // refused alike, whatever the current locale says.
        yield 'space shorthand reads the locale' => ['pattern' => '/^\s\z/'];
        yield 'digit shorthand reads the locale' => ['pattern' => '/^\d\z/'];
        yield 'digit shorthand anywhere' => ['pattern' => '/\d/'];
        yield 'shorthand inside a class' => ['pattern' => '/^[a\d]\z/'];
        yield 'horizontal space shorthand' => ['pattern' => '/^\h\z/'];
        yield 'posix class reads the locale' => ['pattern' => '/^[[:digit:]]\z/'];
        yield 'caseless reads the locale' => ['pattern' => '/^foo\z/i'];
        yield 'inline caseless' => ['pattern' => '/^(?i)foo\z/'];
        yield 'caseless on a letterless literal' => ['pattern' => '/^1\z/i'];
        // A leading verb changes how PCRE runs the pattern: refused, even
        // where (as for the newline conventions on a plain literal) the
        // answer would not move.
        yield 'newline verb' => ['pattern' => '/(*CRLF)foo/'];
        yield 'newline verb before anchors' => ['pattern' => '/(*CR)^foo\z/'];
        yield 'bsr verb' => ['pattern' => '/(*BSR_UNICODE)foo/'];
        yield 'match limit verb' => ['pattern' => '/(*LIMIT_MATCH=1)foo/'];
        yield 'depth limit verb' => ['pattern' => '/(*LIMIT_DEPTH=1)^foo/'];
        yield 'unicode properties verb' => ['pattern' => '/(*UCP)foo/'];
        yield 'anchored modifier' => ['pattern' => '/foo/A'];
        // A string reached along two paths makes the engine backtrack
        // through both: preg_match() fails ("Backtrack limit exhausted")
        // where str_contains() answers. Paths are counted, not strings.
        yield 'alternation of one string' => ['pattern' => '/(?:a|a)/'];
        yield 'ambiguous alternation repeated' => ['pattern' => '/(?:a|a){4}(?:a|a){4}(?:a|a){4}(?:a|a){4}(?:a|a){4}b/'];
        yield 'ambiguous alternation nested' => ['pattern' => '/(?:(?:a|a)|(?:a|a)){10}b/'];
        yield 'one string through two optionals' => ['pattern' => '/^a?a?\z/'];
        yield 'class and branch sharing a member' => ['pattern' => '/^(?:[ab]|a)\z/'];
        // In extended mode PCRE skips the pattern bytes the locale's tables
        // call white space: under nl_NL.UTF-8 (macOS) a raw 0xA0 is one.
        yield 'extended mode with a raw high byte' => ['pattern' => "/prix\xa0eur/x"];
        yield 'inline extended mode with a raw high byte' => ['pattern' => "/(?x)a\xa0b/"];
        yield 'doubly extended mode with a raw high byte' => ['pattern' => "/a\xa0b/xx"];
    }

    /**
     * @return iterable<string, array{pattern: string, literal: non-empty-string}>
     */
    public static function provideOneLiteralPatterns(): iterable
    {
        yield 'literal' => ['pattern' => '/foo/', 'literal' => 'foo'];
        yield 'escaped dot' => ['pattern' => '/f\.o/', 'literal' => 'f.o'];
        yield 'quoted run' => ['pattern' => '/\Qa.b\E/', 'literal' => 'a.b'];
        yield 'class of one member' => ['pattern' => '/[x]y/', 'literal' => 'xy'];
        yield 'fixed repetition' => ['pattern' => '/a{3}/', 'literal' => 'aaa'];
        yield 'repetition of one' => ['pattern' => '/a{1}/', 'literal' => 'a'];
        yield 'empty group before a literal' => ['pattern' => '/(?:)b/', 'literal' => 'b'];
        yield 'repetition of none beside a literal' => ['pattern' => '/a{0}b/', 'literal' => 'b'];
        yield 'repetition past the classifier product cap' => ['pattern' => '/a{5}/', 'literal' => 'aaaaa'];
        yield 'non-capturing group' => ['pattern' => '/(?:foo)/', 'literal' => 'foo'];
        yield 'capturing group' => ['pattern' => '/(foo)/', 'literal' => 'foo'];
        yield 'extended mode drops spaces' => ['pattern' => '/f o o/x', 'literal' => 'foo'];
        yield 'extended mode drops a comment' => ['pattern' => "/f o o # the word\n/x", 'literal' => 'foo'];
        yield 'inline comment' => ['pattern' => '/f(?#c)oo/', 'literal' => 'foo'];
        yield 'two equal literals' => ['pattern' => '/aa/', 'literal' => 'aa'];
        yield 'escaped delimiter' => ['pattern' => '/a\/b/', 'literal' => 'a/b'];
        yield 'other delimiter' => ['pattern' => '#a/b#', 'literal' => 'a/b'];
        yield 'bracket delimiters' => ['pattern' => '{foo}', 'literal' => 'foo'];
        yield 'escaped backslash' => ['pattern' => '/a\\\\b/', 'literal' => 'a\\b'];
        yield 'tab escape' => ['pattern' => '/\t/', 'literal' => "\t"];
        yield 'nul escape' => ['pattern' => '/a\x00b/', 'literal' => "a\0b"];
        yield 'high byte in byte mode' => ['pattern' => '/\xff/', 'literal' => "\xff"];
        yield 'dotall changes nothing without a dot' => ['pattern' => '/foo/s', 'literal' => 'foo'];
        yield 'multiline changes nothing without an anchor' => ['pattern' => '/foo/m', 'literal' => 'foo'];
        yield 'dollar end only changes nothing without a dollar' => ['pattern' => '/foo/D', 'literal' => 'foo'];
        yield 'ungreedy changes nothing without a quantifier' => ['pattern' => '/foo/U', 'literal' => 'foo'];
        yield 'raw high byte without extended mode' => ['pattern' => "/prix\xa0eur/", 'literal' => "prix\xa0eur"];
        yield 'escaped high byte in extended mode' => ['pattern' => '/prix\xa0eur/x', 'literal' => "prix\xa0eur"];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideNoLiteralPatterns(): iterable
    {
        // preg_replace('/foo/i', 'X', 'FOO') is "X", str_replace() leaves it.
        yield 'caseless' => ['pattern' => '/foo/i'];
        yield 'inline caseless' => ['pattern' => '/(?i)foo/'];
        yield 'utf mode fails on invalid subjects' => ['pattern' => '/foo/u'];
        yield 'utf verb' => ['pattern' => '/(*UTF)foo/'];
        // preg_replace('/^foo/', 'X', 'foofoo') is "Xfoo".
        yield 'start anchor' => ['pattern' => '/^foo/'];
        yield 'dollar' => ['pattern' => '/foo$/'];
        yield 'end anchor' => ['pattern' => '/foo\z/'];
        yield 'two strings' => ['pattern' => '/a|ab/'];
        yield 'optional' => ['pattern' => '/a?/'];
        yield 'optional tail' => ['pattern' => '/ab?/'];
        yield 'counted range' => ['pattern' => '/a{1,3}/'];
        yield 'empty pattern' => ['pattern' => '//'];
        yield 'empty group' => ['pattern' => '/(?:)/'];
        yield 'repetition of none' => ['pattern' => '/a{0}/'];
        yield 'empty branch' => ['pattern' => '/(|a)/'];
        yield 'dot' => ['pattern' => '/./'];
        // The structural gate: only literals, concatenation, groups,
        // one-member classes and fixed repetitions reach the proof.
        // preg_replace('/\bfoo\b/', 'X', 'foofoo') is "foofoo".
        yield 'word boundary' => ['pattern' => '/\bfoo\b/'];
        // preg_replace('/a\Kb/', 'X', 'ab') is "aX".
        yield 'match start reset' => ['pattern' => '/a\Kb/'];
        // preg_replace('/(?<!x)ab/', 'X', 'xab') is "xab".
        yield 'lookbehind' => ['pattern' => '/(?<!x)ab/'];
        yield 'lookahead' => ['pattern' => '/(?=a)a/'];
        // preg_replace('/a(*COMMIT)b/', 'X', 'aab') is "aab".
        yield 'backtracking verb' => ['pattern' => '/a(*COMMIT)b/'];
        // The next three match one string each and the engine agrees with
        // str_replace() on them: refused by the gate, not by the engine.
        yield 'backreference' => ['pattern' => '/(a)\1/'];
        yield 'subroutine' => ['pattern' => '/(?1)(a)/'];
        yield 'conditional' => ['pattern' => '/(?(?=a)ab|ab)/'];
        yield 'digit shorthand' => ['pattern' => '/\d/'];
        yield 'posix class' => ['pattern' => '/[[:alpha:]]/'];
        yield 'newline verb' => ['pattern' => '/(*CR)a/'];
        yield 'unicode properties verb' => ['pattern' => '/(*UCP)foo/'];
        // preg_replace('/foo/A', 'X', 'xfoo') is "xfoo".
        yield 'anchored modifier' => ['pattern' => '/foo/A'];
        yield 'invalid' => ['pattern' => '/(foo/'];
        yield 'reversed bounds' => ['pattern' => '/a{2,1}/'];
        yield 'quantifier on nothing' => ['pattern' => '/*a/'];
        yield 'quantifier on a quantifier' => ['pattern' => '/a{3}{2}/'];
        yield 'no delimiter' => ['pattern' => 'foo'];
        // A literal is never spelled out past what the solver could prove:
        // a billion bytes here, ten thousand there.
        yield 'repetition too long to spell out' => ['pattern' => '/(?:(?:a{1000}){1000}){1000}/'];
        yield 'concatenation too long to prove' => ['pattern' => '/a{3000}b{3000}/'];
        // Each string is counted once per path through the pattern: one
        // reached twice is refused, since the engine backtracks through
        // both. preg_replace('/(?:a|a){20}b/', 'X', str_repeat('a', 40).'c'
        // .str_repeat('a', 20).'b') is null ("Backtrack limit exhausted").
        // The row expected 'a' before paths were counted.
        yield 'alternation of one string' => ['pattern' => '/(?:a|a)/'];
        yield 'ambiguous alternation repeated' => ['pattern' => '/(?:a|a){20}b/'];
        yield 'ambiguous alternation nested' => ['pattern' => '/(?:(?:a|a)|(?:a|a)){10}b/'];
        // In extended mode PCRE skips the pattern bytes the locale's tables
        // call white space: under nl_NL.UTF-8 (macOS) a raw 0xA0 is one.
        yield 'extended mode with a raw high byte' => ['pattern' => "/prix\xa0eur/x"];
        yield 'inline extended mode with a raw high byte' => ['pattern' => "/(?x)a\xa0b/"];
        yield 'doubly extended mode with a raw high byte' => ['pattern' => "/a\xa0b/xx"];
    }

    /**
     * The literal, around it, inside it, twice, with a newline and with bytes
     * above 0x7F.
     *
     * @return list<string>
     */
    private static function literalSubjects(string $literal): array
    {
        $subjects = ['', "\n", "\xff", 'x', 'FOO', $literal, $literal.$literal, $literal.$literal.$literal, 'x'.$literal, $literal.'x', 'x'.$literal.'y'.$literal, $literal."\n", "\n".$literal, "\xff".$literal."\x80", strtoupper($literal), substr($literal, 1), substr($literal, 0, -1)];

        return array_values(array_unique($subjects));
    }
}
