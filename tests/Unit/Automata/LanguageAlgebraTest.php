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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The boolean algebra of languages: union, intersection, difference and
 * complement, each example checked against the engine, and the answers
 * checked against the solver's own questions.
 */
final class LanguageAlgebraTest extends TestCase
{
    private const CORPUS = [
        '/^[a-z]+$/',
        '/^[a-z0-9]+$/',
        '/^\d+$/',
        '/^(?:ab|a)c?$/',
        '/^\/a\/[^\/]+$/',
        '/^\/a\/\d{2,3}$/',
        '/^(?:foo|bar|baz)$/i',
        '/^$/',
        '/^(?!x)\w{1,3}$/',
        '/^[ab]*a[ab]$/',
    ];

    #[Test]
    #[DataProvider('providePairs')]
    public function test_each_operation_holds_what_the_engine_says(string $left, string $right): void
    {
        $solver = new LanguageSolver();
        $l = $solver->language($left);
        $r = $solver->language($right);

        foreach ([
            'union' => [$l->union($r), static fn (bool $a, bool $b): bool => $a || $b],
            'intersect' => [$l->intersect($r), static fn (bool $a, bool $b): bool => $a && $b],
            'minus' => [$l->minus($r), static fn (bool $a, bool $b): bool => $a && !$b],
        ] as $operation => [$language, $holds]) {
            $count = 0;
            foreach ($language->strings() as $string) {
                $this->assertTrue($holds(1 === preg_match($left, $string), 1 === preg_match($right, $string)), \sprintf('%s of %s and %s holds %s.', $operation, $left, $right, json_encode($string)));
                if (++$count >= 20) {
                    break;
                }
            }
        }
    }

    #[Test]
    #[DataProvider('providePairs')]
    public function test_the_answers_agree_with_the_solver(string $left, string $right): void
    {
        $solver = new LanguageSolver();
        $l = $solver->language($left);
        $r = $solver->language($right);

        $this->assertSame($solver->subsetOf($left, $right)->isSubset, $l->isSubsetOf($r));
        $this->assertSame($solver->intersection($left, $right)->isEmpty, $l->intersect($r)->isEmpty());
        $this->assertSame($solver->intersection($left, $right)->example, $l->intersect($r)->shortestExample(), 'The same least shortest string.');
        $this->assertSame($solver->subsetOf($left, $right)->counterExample, $l->minus($r)->shortestExample());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function providePairs(): iterable
    {
        foreach (self::CORPUS as $left) {
            foreach (self::CORPUS as $right) {
                yield $left.' '.$right => [$left, $right];
            }
        }
    }

    #[Test]
    public function test_a_complement_holds_what_the_language_rejects(): void
    {
        $language = (new LanguageSolver())->language('/^[a-c]+$/');
        $complement = $language->complement();

        $this->assertSame('', $complement->shortestExample());
        $this->assertTrue($complement->complement()->isSubsetOf($language));
        $this->assertTrue($language->isSubsetOf($complement->complement()));
        $this->assertTrue($language->intersect($complement)->isEmpty());
        $count = 0;
        foreach ($complement->strings() as $string) {
            $this->assertSame(0, preg_match('/^[a-c]+$/', $string), json_encode($string).' is rejected.');
            if (++$count >= 50) {
                break;
            }
        }
    }

    #[Test]
    public function test_the_operations_compose(): void
    {
        $solver = new LanguageSolver();
        $digits = $solver->language('/^\/n\/\d+$/');
        $letters = $solver->language('/^\/n\/[a-z]+$/');
        $either = $solver->language('/^\/n\/(?:\d+|[a-z]+)$/');
        $mixed = $solver->language('/^\/n\/[a-z0-9]+$/');

        $this->assertTrue($either->isSubsetOf($digits->union($letters)), 'Two earlier routes shadow it together.');
        $this->assertFalse($either->isSubsetOf($digits));
        $this->assertSame('/n/0a', $mixed->minus($digits->union($letters))->shortestExample());
        $this->assertNull($either->minus($digits)->minus($letters)->shortestExample());
    }

    #[Test]
    public function test_the_trailing_slash_question_reads_three_languages(): void
    {
        // (L ∩ swapped(E)) \ E: the paths of a later route that an earlier
        // one redirects for the slash and does not match as is.
        $solver = new LanguageSolver();
        $later = $solver->language('{^/(?P<y>a|a/|long/)$}s');
        $swapped = $solver->language('{^/(?P<x>a|long)/$}s');
        $earlier = $solver->language('{^/(?P<x>a|long)$}s');

        $this->assertSame('/a/', $later->intersect($swapped)->minus($earlier)->shortestExample());
        $this->assertSame('/long/', $later->intersect($swapped)->minus($earlier)->minus($solver->language('{^/(?P<y>a|a/|long/)/$}s'))->shortestExample());
    }

    #[Test]
    public function test_utf8_and_bytes_do_not_mix(): void
    {
        $solver = new LanguageSolver();

        $this->expectException(\InvalidArgumentException::class);
        $solver->language('/^é$/u')->union($solver->language('/^a$/'));
    }

    #[Test]
    public function test_utf8_languages_keep_their_code_points(): void
    {
        $solver = new LanguageSolver();
        $accents = $solver->language('/^[à-ÿ]$/u');
        $e = $solver->language('/^[è-ë]$/u');

        $this->assertSame('à', $accents->minus($e)->shortestExample());
        $this->assertSame('è', $accents->intersect($e)->shortestExample());
        $this->assertTrue($accents->union($e)->isSubsetOf($accents));
    }

    #[Test]
    public function test_a_product_past_its_limit_is_refused(): void
    {
        // Two cycles of 401 and 397 states: their product walks every pair
        // before it comes back to the start, some 159,000 of them.
        $solver = new LanguageSolver();
        $left = $solver->language('/^(?:a{401})*$/');
        $right = $solver->language('/^(?:a{397})*$/');

        try {
            $left->intersect($right);
            $this->fail('The product is refused.');
        } catch (ComplexityException $exception) {
            $this->assertSame('product', $exception->getDiagnostic()['phase'] ?? null);
        }
    }
}
