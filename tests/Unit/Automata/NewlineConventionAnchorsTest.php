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

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Tests\Support\JitInAFreshProcess;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "^" and "$" under /m, "$" and "\Z" read where lines end, which the newline
 * convention says: "\r" under (*CR), NUL under (*NUL), "\r\n" and neither
 * of the two alone under (*CRLF), "\r", "\n" or "\r\n" under (*ANYCRLF),
 * and those or "\x0B", "\x0C", "\x85", and U+2028 and U+2029 under /u, under
 * (*ANY). Under the last two "^" holds between "\r" and "\n", and "$" too.
 * Under (*CRLF) the dot and "\N" take a "\r" and a "\n", each alone, and
 * not the "\r" a "\n" follows.
 */
final class NewlineConventionAnchorsTest extends TestCase
{
    use PhpUnitForwardCompat;

    private const BYTES = ['a', 'b', "\r", "\n", "\0"];

    private const ANY_BYTES = ['a', "\r", "\n", "\0", "\x0B", "\x0C", "\x85"];

    private const ANY_CODE_POINTS = ['a', "\r", "\n", "\u{85}", "\u{2028}", "\u{2029}"];

    /**
     * The engine answers first, with the JIT and without, on every string
     * up to a length over the letters: a search for the pattern, and a
     * match of the whole string. The automata then answer the same.
     *
     * @param list<string> $letters
     */
    #[Test]
    #[DataProvider('provideAnchorsUnderAConvention')]
    #[DataProvider('provideDotsUnderCrLf')]
    public function test_the_automata_match_what_the_engine_matches(string $options, string $body, string $flags, array $letters, int $length): void
    {
        $search = '/'.$options.$body.'/'.$flags;
        $whole = '/'.$options.'\A(?:'.$body.')\z/'.$flags;
        $unicode = str_contains($flags, 'u');

        $engine = [];
        $withoutJit = [];
        foreach (self::subjects($letters, $length) as $subject) {
            $engine[] = [$subject, 1 === preg_match($search, $subject), 1 === preg_match($whole, $subject)];
            $withoutJit[] = [$subject, 1 === preg_match('/(*NO_JIT)'.substr($search, 1), $subject), 1 === preg_match('/(*NO_JIT)'.substr($whole, 1), $subject)];
        }
        $this->assertSame($engine, $withoutJit, $search.' with the JIT and without.');

        $solver = new LanguageSolver();
        $partial = $solver->compile($search, new SolverOptions(matchMode: MatchMode::Partial));
        $full = $solver->compile($search, new SolverOptions(matchMode: MatchMode::Full));

        $automata = [];
        foreach ($engine as [$subject]) {
            $automata[] = [$subject, self::accepts($partial, $subject, $unicode), self::accepts($full, $subject, $unicode)];
        }
        $this->assertSame($engine, $automata, $search.': a search, then a match of the whole string.');
    }

    /**
     * Under (*CRLF), (*ANYCRLF) and (*ANY) a search skips a start between
     * "\r" and "\n" where the pattern writes neither, and one that a "^"
     * under /m or a ".*" leads tries the starts of lines alone after its
     * first try, which skip it as well, the JIT's or not: the subjects the
     * engine finds a match in then hang on what the pattern writes and on
     * the JIT. A search that may find a match starting there and nowhere
     * else is refused; the whole string, which the match starts at the
     * start of, is read. From an offset that leaves the "\r" out, the
     * start between is the first one tried, and the engine finds a match.
     */
    #[Test]
    #[DataProvider('provideStartsBetweenCrAndLf')]
    public function test_a_search_that_may_start_between_cr_and_lf_is_refused(string $options, string $body, string $flags, int $withJit, int $withoutJit): void
    {
        $search = '/'.$options.$body.'/'.$flags;
        $whole = '/'.$options.'\A(?:'.$body.')\z/'.$flags;
        $this->assertSame($withJit, JitInAFreshProcess::matched($search, "\r\n"));
        $this->assertSame($withoutJit, preg_match('/(*NO_JIT)'.substr($search, 1), "\r\n"));
        $this->assertSame(1, preg_match($search, "\r\n", $matches, 0, 1));
        $this->assertSame(1, preg_match('/(*NO_JIT)'.substr($search, 1), "\r\n", $matches, 0, 1));

        $solver = new LanguageSolver();
        $full = $solver->compile($search, new SolverOptions(matchMode: MatchMode::Full));
        foreach (self::subjects(self::BYTES, 4) as $subject) {
            $all = 1 === preg_match($whole, $subject);
            $this->assertSame($all, 1 === preg_match('/(*NO_JIT)'.substr($whole, 1), $subject), \sprintf('%s on %s, without the JIT.', $whole, json_encode($subject)));
            $this->assertSame($all, self::accepts($full, $subject, false), \sprintf('%s matched against the whole of %s.', $search, json_encode($subject)));
        }

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains('may skip a match that starts between "\r" and "\n"');

        $solver->compile($search, new SolverOptions(matchMode: MatchMode::Partial));
    }

    /**
     * Under (*CRLF) the dot reads a "\r" alone but not one before a "\n",
     * which it asks of what follows it: inside a lookaround, it would be a
     * lookahead inside another, and stays out. Read as the contrast pattern
     * reads it, the subject would hold a match.
     */
    #[Test]
    #[DataProvider('provideCrLfConstructs')]
    public function test_what_crlf_moves_stays_refused(string $pattern, string $contrast, string $subject, string $message): void
    {
        $this->assertSame(0, preg_match($pattern, $subject));
        $this->assertSame(0, preg_match('/(*NO_JIT)'.substr($pattern, 1), $subject));
        $this->assertSame(1, preg_match($contrast, $subject));

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains($message);

        (new LanguageSolver())->compile($pattern, new SolverOptions(matchMode: MatchMode::Full));
    }

    /**
     * @return iterable<string, array{options: string, body: string, flags: string, letters: list<string>, length: int}>
     */
    public static function provideAnchorsUnderAConvention(): iterable
    {
        $rows = [
            // "\r" ends a line under (*CR), NUL under (*NUL); "\n" is a
            // character like any other.
            'line start under (*CR)' => ['(*CR)', '^b', 'm'],
            'line end under (*CR)' => ['(*CR)', 'a$', 'm'],
            'an empty line under (*CR)' => ['(*CR)', '^$', 'm'],
            'a whole line under (*CR)' => ['(*CR)', '^a\n?$', 'm'],
            'a line start after its newline under (*CR)' => ['(*CR)', '\r^', 'm'],
            'a line start after a line feed under (*CR)' => ['(*CR)', '\n^', 'm'],
            'dollar under (*CR)' => ['(*CR)', 'a$', ''],
            'dollar before more under (*CR)' => ['(*CR)', 'a$\r?', ''],
            'capital Z under (*CR)' => ['(*CR)', 'a\Z', ''],
            'capital Z before a line feed under (*CR)' => ['(*CR)', 'a\Z\n?', ''],
            'dollar under D and (*CR)' => ['(*CR)', 'a$\r?', 'D'],
            'dollar under D and m and (*CR)' => ['(*CR)', 'a$\r?', 'mD'],
            'inline multiline under (*CR)' => ['(*CR)', '(?m)^a', ''],
            'multiline group under (*CR)' => ['(*CR)', 'b(?m:^a)?', ''],
            'line start under (*NUL)' => ['(*NUL)', '^b', 'm'],
            'line end under (*NUL)' => ['(*NUL)', 'a$', 'm'],
            'line end then start under (*NUL)' => ['(*NUL)', 'a$\0^', 'm'],
            'capital Z under (*NUL)' => ['(*NUL)', 'a\Z', ''],
            'dollar before more under (*NUL)' => ['(*NUL)', 'a$[\0\n]?', ''],
            'nul inline multiline among other options' => ['(*NUL)', '(?im)^a', ''],
            // "\r\n" ends a line, and neither character does alone: "^" holds
            // after the two, "$" before them, and nothing between them.
            'line start under (*CRLF)' => ['(*CRLF)', '^b', 'm'],
            'line end under (*CRLF)' => ['(*CRLF)', 'a$', 'm'],
            'an empty line under (*CRLF)' => ['(*CRLF)', '^$', 'm'],
            'a whole line under (*CRLF)' => ['(*CRLF)', '^a\n?$', 'm'],
            'a line start between cr and lf under (*CRLF)' => ['(*CRLF)', '\r^\n', 'm'],
            'a line start after cr and lf' => ['(*CRLF)', '\r\n^b?', 'm'],
            'a line start after a line feed under (*CRLF)' => ['(*CRLF)', 'a\n^b', 'm'],
            'a line end before cr and lf' => ['(*CRLF)', 'a$\r\n', 'm'],
            'a line end before a carriage return under (*CRLF)' => ['(*CRLF)', 'a$\r?b?', 'm'],
            'a line end between cr and lf under (*CRLF)' => ['(*CRLF)', 'a\r$', 'm'],
            'a line end then start under (*CRLF)' => ['(*CRLF)', 'a$\r\n^b', 'm'],
            'dollar under (*CRLF)' => ['(*CRLF)', 'a$', ''],
            'dollar after a carriage return under (*CRLF)' => ['(*CRLF)', 'a\r$', ''],
            'dollar before more under (*CRLF)' => ['(*CRLF)', 'a$\r?\n?b?', ''],
            'dollar or a letter under (*CRLF)' => ['(*CRLF)', 'a(?:$|b)', ''],
            'capital Z under (*CRLF)' => ['(*CRLF)', 'a\Z', ''],
            'capital Z before more under (*CRLF)' => ['(*CRLF)', 'a\Z\r?\n?b?', ''],
            'dollar under D and (*CRLF)' => ['(*CRLF)', 'a$\r?\n?', 'D'],
            'dollar under D and m and (*CRLF)' => ['(*CRLF)', 'a$\r?\n?', 'mD'],
            'inline multiline under (*CRLF)' => ['(*CRLF)', '(?m)^a', ''],
            'multiline group under (*CRLF)' => ['(*CRLF)', 'b(?m:^a)?', ''],
            'crlf multiline turned on after a reset' => ['(*CRLF)', '(?^m)\0|a$', ''],
            'multiline with no anchor under (*CRLF)' => ['(*CRLF)', 'ab', 'm'],
            // "\r", "\n" and "\r\n" each end a line: "^" holds between "\r"
            // and "\n", and "$" before the "\n".
            'line start under (*ANYCRLF)' => ['(*ANYCRLF)', '^b', 'm'],
            'line end under (*ANYCRLF)' => ['(*ANYCRLF)', 'a$', 'm'],
            'an empty line after a carriage return' => ['(*ANYCRLF)', '\r^$', 'm'],
            // A match may start between "\r" and "\n" only where another
            // starts before the "\r".
            'a line end, maybe after a letter' => ['(*ANYCRLF)', 'b?$', 'm'],
            'a line feed, maybe after a carriage return, or a letter' => ['(*ANYCRLF)', '\r?\n|b', ''],
            'a line start between cr and lf' => ['(*ANYCRLF)', '\r^\n', 'm'],
            'a line end then start between cr and lf' => ['(*ANYCRLF)', 'a$\r^\n', 'm'],
            'a line end between cr and lf' => ['(*ANYCRLF)', 'a\r$', 'm'],
            'dollar under (*ANYCRLF)' => ['(*ANYCRLF)', 'a$', ''],
            'capital Z under (*ANYCRLF)' => ['(*ANYCRLF)', 'a\Z', ''],
            'capital Z between cr and lf' => ['(*ANYCRLF)', 'a\r\Z', ''],
            'capital Z before more under (*ANYCRLF)' => ['(*ANYCRLF)', 'a\Z\r?\n?b?', ''],
            'dollar under D and (*ANYCRLF)' => ['(*ANYCRLF)', 'a$\n?', 'D'],
            'anycrlf multiline' => ['(*ANYCRLF)', '^a', 'm'],
            'line end before a letter under (*ANYCRLF)' => ['(*ANYCRLF)', 'a$[\r\nb]', 'm'],
            // The last convention the options name holds.
            'a carriage return named after a line feed' => ['(*LF)(*ANYCRLF)', 'a$\r', 'm'],
            'a line feed named after a carriage return' => ['(*CR)(*LF)', 'a$\n?', 'm'],
            'cr and lf named after a line feed' => ['(*LF)(*CRLF)', 'a$\r?\n?', 'm'],
            'a line feed named after cr and lf' => ['(*CRLF)(*LF)', 'a$\r?\n?', 'm'],
        ];
        foreach ($rows as $name => [$options, $body, $flags]) {
            yield $name => ['options' => $options, 'body' => $body, 'flags' => $flags, 'letters' => self::BYTES, 'length' => 5];
        }

        $any = [
            'line start under (*ANY)' => ['^a', 'm'],
            'line end under (*ANY)' => ['a$', 'm'],
            'a line start after each newline under (*ANY)' => ['[\x0B\x0C\x85\0]^a', 'm'],
            'a line start between cr and lf under (*ANY)' => ['\r^\n', 'm'],
            'dollar under (*ANY)' => ['a$', ''],
            'capital Z under (*ANY)' => ['a\Z', ''],
            'capital Z before more under (*ANY)' => ['a\Z[\r\n\x0B]?\n?', ''],
            'any multiline turned on after a reset' => ['(?^m)\0|^a', ''],
        ];
        foreach ($any as $name => [$body, $flags]) {
            yield $name => ['options' => '(*ANY)', 'body' => $body, 'flags' => $flags, 'letters' => self::ANY_BYTES, 'length' => 4];
        }

        $anyUnicode = [
            'line start under (*ANY) and u' => ['^a', 'mu'],
            'line end under (*ANY) and u' => ['a$', 'mu'],
            'a line start after a separator under (*ANY) and u' => ['[\x{2028}\x{2029}\x{85}]^a', 'mu'],
            'capital Z under (*ANY) and u' => ['a\Z', 'u'],
            'dollar before more under (*ANY) and u' => ['a$[\x{2028}\x{2029}\x{85}\r\n]?', 'u'],
        ];
        foreach ($anyUnicode as $name => [$body, $flags]) {
            yield $name => ['options' => '(*ANY)', 'body' => $body, 'flags' => $flags, 'letters' => self::ANY_CODE_POINTS, 'length' => 4];
        }

        // The characters next to the newlines of (*ANY) end no line.
        yield 'line end before a tab or a shift out under (*ANY)' => ['options' => '(*ANY)', 'body' => 'a$', 'flags' => 'm', 'letters' => ['a', "\t", "\x0E", "\r", "\n"], 'length' => 3];
        yield 'line end before the code points next to the separators under (*ANY) and u' => ['options' => '(*ANY)', 'body' => 'a$', 'flags' => 'mu', 'letters' => ['a', "\u{2027}", "\u{2028}", "\u{2029}", "\u{202A}"], 'length' => 3];
    }

    /**
     * @return iterable<string, array{options: string, body: string, flags: string, withJit: int, withoutJit: int}>
     */
    public static function provideStartsBetweenCrAndLf(): iterable
    {
        // A "^" leads: the JIT tries the start between "\r" and "\n", the
        // interpreter goes on to the next line.
        yield 'a line start before a line feed' => ['options' => '(*ANYCRLF)', 'body' => '^\n', 'flags' => 'm', 'withJit' => 1, 'withoutJit' => 0];
        // No "\r" nor "\n" written: neither tries it.
        yield 'an empty line' => ['options' => '(*ANYCRLF)', 'body' => '^$', 'flags' => 'm', 'withJit' => 1, 'withoutJit' => 1];
        yield 'a space after a space under (*ANYCRLF)' => ['options' => '(*ANYCRLF)', 'body' => '(?<=\s)\s', 'flags' => '', 'withJit' => 0, 'withoutJit' => 0];
        yield 'a space after a space under (*ANY)' => ['options' => '(*ANY)', 'body' => '(?<=\s)\s', 'flags' => '', 'withJit' => 0, 'withoutJit' => 0];
        yield 'a space after a space under (*CRLF)' => ['options' => '(*CRLF)', 'body' => '(?<=\s)\s', 'flags' => '', 'withJit' => 0, 'withoutJit' => 0];
        // The dot does not take the "\r" of the pair, and the "\n" is skipped.
        yield 'a dot under (*CRLF)' => ['options' => '(*CRLF)', 'body' => '.', 'flags' => '', 'withJit' => 0, 'withoutJit' => 0];
        yield 'no newline under (*CRLF)' => ['options' => '(*CRLF)', 'body' => '\N+', 'flags' => 's', 'withJit' => 0, 'withoutJit' => 0];
        // A leading ".*" tries the starts of lines alone, as a "^" does.
        yield 'a line feed after a run of dots' => ['options' => '(*ANY)', 'body' => '.*\n', 'flags' => '', 'withJit' => 0, 'withoutJit' => 0];
        // The engine tries the start between here, the pattern writing a
        // "\n": the search is refused all the same, as nothing tells it.
        yield 'a line feed after a run of anything but newlines' => ['options' => '(*ANYCRLF)', 'body' => '[^\r\n]*\n', 'flags' => '', 'withJit' => 1, 'withoutJit' => 1];
    }

    /**
     * @return iterable<string, array{pattern: string, contrast: string, subject: string, message: string}>
     */
    public static function provideCrLfConstructs(): iterable
    {
        $dot = 'Under (*CRLF) the dot reads a "\r" alone but not one before a "\n", which the automata solver does not read inside a lookaround.';
        yield 'a dot in a lookahead under (*CRLF)' => ['pattern' => '/(*CRLF)a(?=.\n)/', 'contrast' => '/(*CRLF)a(?=.\n)/s', 'subject' => "a\r\n", 'message' => $dot];
        yield 'no newline in a lookahead under (*CRLF)' => ['pattern' => '/(*CRLF)a(?=\N\n)/', 'contrast' => '/(*CRLF)a(?=[^\n]\n)/', 'subject' => "a\r\n", 'message' => $dot];
        // The dot of a lookbehind reads past where the lookbehind stands.
        yield 'a dot in a lookbehind under (*CRLF)' => ['pattern' => '/(*CRLF)a\r(?<=.)\n/', 'contrast' => '/(*CRLF)a\r(?<=.)\n/s', 'subject' => "a\r\n", 'message' => $dot];
        // A "\n" alone is a character the dot takes.
        yield 'a dot in a negative lookahead under (*CRLF)' => ['pattern' => '/(*CRLF)a(?!.)\n/', 'contrast' => '/(*CRLF)a(?![^\n])\n/', 'subject' => "a\n", 'message' => $dot];
        // A lookahead written inside another is refused as one, beside a dot or not.
        yield 'a lookahead written in a lookahead under (*CRLF)' => ['pattern' => '/(*CRLF)a.(?=(?!\r\n)[\s\S])/', 'contrast' => '/(*CRLF)a.(?=[\s\S])/', 'subject' => "ab\r\n", 'message' => 'A lookaround inside a lookaround is beyond what the automata solver reads.'];
    }

    /**
     * @return iterable<string, array{options: string, body: string, flags: string, letters: list<string>, length: int}>
     */
    public static function provideDotsUnderCrLf(): iterable
    {
        $rows = [
            'a dot before a line feed' => ['(*CRLF)', 'a.\n', ''],
            'no newline before a line feed' => ['(*CRLF)', 'a\N\n', ''],
            'a dot under s' => ['(*CRLF)', 'a.\n', 's'],
            'no newline under s' => ['(*CRLF)', 'a\N\n', 's'],
            'a dot under an inline s' => ['(*CRLF)', 'a(?s:.)\n', ''],
            'a dot with s turned off' => ['(*CRLF)', 'a(?-s:.)\n', 's'],
            'a dot after s was set' => ['(*CRLF)', 'a(?s).\n?(?-s).', ''],
            'a dot at the end' => ['(*CRLF)', 'a.', ''],
            'two dots' => ['(*CRLF)', 'a..', ''],
            'a dot then a carriage return' => ['(*CRLF)', 'a.\r', ''],
            'a run of dots' => ['(*CRLF)', 'a.*b', ''],
            'a lazy run of dots' => ['(*CRLF)', 'a.*?\r', ''],
            'one dot or more' => ['(*CRLF)', 'a.+\n', ''],
            'a counted run of dots' => ['(*CRLF)', 'a\N{2}b?', ''],
            'a dot or cr and lf' => ['(*CRLF)', 'a(?:.|\r\n)b', ''],
            'a group of a dot repeated' => ['(*CRLF)', 'a(?:.\r)+\n', ''],
            'a dot in a capture' => ['(*CRLF)', 'a(.)?\n', ''],
            'a line of dots' => ['(*CRLF)', '^a.*$', 'm'],
            'a dot before a dollar' => ['(*CRLF)', 'a.$', ''],
            'no newline before a capital Z' => ['(*CRLF)', 'a\N\Z', ''],
            'a dot before a line end' => ['(*CRLF)', 'a.$', 'm'],
            'a dot under m' => ['(*CRLF)', 'a.\n', 'm'],
            // Under s the dot takes every character, in a lookaround too.
            'a dot under s in a lookahead' => ['(*CRLF)', 'a(?=.\n)', 's'],
            'a dot under s in a lookbehind' => ['(*CRLF)', 'a\r(?<=.)\n', 's'],
            'a dot beside a lookahead' => ['(*CRLF)', 'a.(?=\n)', ''],
            'a dot after cr and lf was named last' => ['(*LF)(*CRLF)', 'a.\n', ''],
            'a dot after a line feed was named last' => ['(*CRLF)(*LF)', 'a.\n', ''],
        ];
        foreach ($rows as $name => [$options, $body, $flags]) {
            yield $name => ['options' => $options, 'body' => $body, 'flags' => $flags, 'letters' => self::BYTES, 'length' => 5];
        }
    }

    private static function accepts(Dfa $dfa, string $subject, bool $unicode): bool
    {
        $state = $dfa->startState;
        foreach ('' === $subject ? [] : ($unicode ? mb_str_split($subject, 1, 'UTF-8') : str_split($subject)) as $character) {
            $next = $dfa->getState($state)->transitionFor($unicode ? mb_ord($character, 'UTF-8') : \ord($character));
            if (null === $next) {
                return false;
            }
            $state = $next;
        }

        return $dfa->getState($state)->isAccepting;
    }

    /**
     * @param list<string> $letters
     *
     * @return list<string>
     */
    private static function subjects(array $letters, int $length): array
    {
        $all = [''];
        $layer = [''];
        for ($size = 1; $size <= $length; $size++) {
            $next = [];
            foreach ($layer as $prefix) {
                foreach ($letters as $letter) {
                    $next[] = $prefix.$letter;
                }
            }
            array_push($all, ...$next);
            $layer = $next;
        }

        return $all;
    }
}
