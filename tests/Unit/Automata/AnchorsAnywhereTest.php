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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An anchor away from the edges of the pattern, and every anchor under /m,
 * is a lookaround in disguise: "^" under /m holds at the start or after a
 * newline that more follows, "$" under /m before a newline or at the end,
 * "\A" with nothing before, "\z" with nothing after, "$" and "\Z" at the end
 * or before a newline that ends the subject. Flags that change nothing the
 * tree does not already say, x, U, n, J, S and X, are read too.
 */
final class AnchorsAnywhereTest extends TestCase
{
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_automaton_accepts_what_the_engine_matches(string $pattern): void
    {
        $dfa = (new LanguageSolver())->compile($pattern, new SolverOptions(matchMode: MatchMode::Partial));

        foreach (self::subjects("ab\n,", 5) as $subject) {
            $this->assertSame(1 === preg_match($pattern, $subject), self::accepts($dfa, $subject), \sprintf('%s on %s.', $pattern, json_encode($subject)));
        }
    }

    #[Test]
    #[DataProvider('provideNewlineConventions')]
    public function test_a_newline_convention_the_solver_does_not_read_is_refused(string $pattern): void
    {
        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessage('A newline convention other than');

        (new LanguageSolver())->compile($pattern, new SolverOptions(matchMode: MatchMode::Partial));
    }

    #[Test]
    public function test_the_anchored_flag_stays_refused(): void
    {
        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessage('Unsupported regex flags for automata: A.');

        (new LanguageSolver())->compile('/a/A');
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'line start' => ['pattern' => '/^b/m'];
        yield 'line end' => ['pattern' => '/a$/m'];
        yield 'an empty line' => ['pattern' => '/^$/m'];
        yield 'a whole line' => ['pattern' => '/^a,?$/m'];
        yield 'subject start on one branch' => ['pattern' => '/x|^b/'];
        yield 'subject end on one branch' => ['pattern' => '/\Aa|b\z/'];
        yield 'subject start inside' => ['pattern' => '/a\Ab/'];
        yield 'subject end inside' => ['pattern' => '/a\zb/'];
        yield 'start or a comma' => ['pattern' => '/(?:^|,)a/'];
        yield 'end or a comma' => ['pattern' => '/a(?:$|,)/'];
        yield 'dollar before more' => ['pattern' => '/a$\n?/'];
        yield 'capital Z before more' => ['pattern' => '/a\Z\n?/'];
        yield 'dollar under D, before more' => ['pattern' => '/a$\n?/D'];
        yield 'extended' => ['pattern' => '/a  b/x'];
        yield 'ungreedy' => ['pattern' => '/a+b?/U'];
        yield 'no auto capture' => ['pattern' => '/(a)(?<n>b)/n'];
        yield 'duplicate names' => ['pattern' => '/(?<n>a)|(?<n>b)/J'];
        yield 'study and extra' => ['pattern' => '/ab/SX'];
        yield 'lines with ungreedy' => ['pattern' => '/^a+$/mU'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideNewlineConventions(): iterable
    {
        yield 'crlf dollar' => ['pattern' => '/(*CRLF)a$/'];
        yield 'cr capital Z' => ['pattern' => '/(*CR)a\Z/'];
        yield 'anycrlf multiline' => ['pattern' => '/(*ANYCRLF)^a/m'];
    }

    private static function accepts(Dfa $dfa, string $subject): bool
    {
        $state = $dfa->startState;
        foreach (str_split($subject) as $byte) {
            $next = $dfa->getState($state)->transitionFor(\ord($byte));
            if (null === $next) {
                return false;
            }
            $state = $next;
        }

        return $dfa->getState($state)->isAccepting;
    }

    /**
     * @return list<string>
     */
    private static function subjects(string $letters, int $length): array
    {
        $all = [''];
        $layer = [''];
        for ($size = 1; $size <= $length; $size++) {
            $next = [];
            foreach ($layer as $prefix) {
                foreach (str_split($letters) as $letter) {
                    $next[] = $prefix.$letter;
                }
            }
            $all = [...$all, ...$next];
            $layer = $next;
        }

        return $all;
    }
}
