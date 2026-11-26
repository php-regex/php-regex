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

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The class docblock says what the solver reads: a plain lookaround is read,
 * which LookaroundSolverTest holds, so the docblock does not list
 * lookarounds among what throws.
 */
final class LanguageSolverDocblockTest extends TestCase
{
    #[Test]
    public function test_the_docblock_says_plain_lookarounds_are_read(): void
    {
        $doc = (string) (new \ReflectionClass(LanguageSolver::class))->getDocComment();

        $this->assertStringNotContainsString('backreferences, lookarounds, recursion', $doc);
        $this->assertStringContainsString('plain lookaround', $doc);
        $this->assertTrue((new LanguageSolver())->subsetOf('/a(?=b)b/', '/ab/')->isSubset);
    }

    /**
     * A pattern that does not lex, or does not parse, throws the parser's
     * exception from every public method that reads one, and each says so.
     *
     * @param \Closure(LanguageSolver, string): mixed $call
     */
    #[Test]
    #[DataProvider('providePatternReaders')]
    public function test_a_pattern_reader_says_it_throws_what_the_parser_throws(string $method, \Closure $call): void
    {
        $this->assertMatchesRegularExpression('~@throws\s+ParserException\s~', (string) (new \ReflectionMethod(LanguageSolver::class, $method))->getDocComment());

        foreach (self::invalidPatterns() as $regex => $exception) {
            $this->assertFalse(@preg_match($regex, ''), 'Oracle: PCRE refuses '.$regex);

            try {
                $call(new LanguageSolver(), $regex);
                $this->fail($method.'() reads '.$regex);
            } catch (LexerException|ParserException $thrown) {
                $this->assertSame($exception, $thrown instanceof LexerException ? LexerException::class : ParserException::class, $regex);
            }
        }
    }

    /**
     * @return iterable<string, array{method: string, call: \Closure(LanguageSolver, string): mixed}>
     */
    public static function providePatternReaders(): iterable
    {
        yield 'intersection' => ['method' => 'intersection', 'call' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->intersection($regex, '/a/')];
        yield 'subsetOf' => ['method' => 'subsetOf', 'call' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->subsetOf('/a/', $regex)];
        yield 'equivalent' => ['method' => 'equivalent', 'call' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->equivalent($regex, '/a/')];
        yield 'matchEquivalent' => ['method' => 'matchEquivalent', 'call' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->matchEquivalent($regex, '/a/')];
        yield 'acceptsPrefix' => ['method' => 'acceptsPrefix', 'call' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->acceptsPrefix($regex, 'a')];
        yield 'language' => ['method' => 'language', 'call' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->language($regex)];
        yield 'compile' => ['method' => 'compile', 'call' => static fn (LanguageSolver $solver, string $regex): mixed => $solver->compile($regex)];
    }

    #[Test]
    public function test_no_source_comment_names_the_product_regex_parser(): void
    {
        $hits = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 3).'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()
                && 1 === preg_match('~\b(?:using|with|by|of|in) RegexParser\b(?![:\\\\(])~', (string) file_get_contents($file->getPathname()))) {
                $hits[] = $file->getPathname();
            }
        }

        $this->assertSame([], $hits, 'The product is PHPRegex; RegexParser is a class.');
    }

    /**
     * An unclosed class, which the lexer refuses, and an unclosed group,
     * which the parser refuses.
     *
     * @return array<string, class-string<LexerException|ParserException>>
     */
    private static function invalidPatterns(): array
    {
        return ['/[a/' => LexerException::class, '/(a/' => ParserException::class];
    }
}
