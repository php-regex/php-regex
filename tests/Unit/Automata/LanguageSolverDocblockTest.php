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
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Tests\Support\PcreGates;
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
    use InstalledLocales;
    use PcreGates;

    private const LATIN1 = 'fr_FR.ISO8859-1';

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

    /**
     * readAlike() promises that two patterns that read alike run alike
     * where their text is spliced as a group, which is how the router
     * splices a requirement, and no further: the docblock said "wherever
     * their text is spliced into a larger pattern".
     */
    #[Test]
    public function test_read_alike_promises_patterns_spliced_as_a_group(): void
    {
        $doc = (string) preg_replace('~\s*\*\s+~', ' ', (string) (new \ReflectionMethod(LanguageSolver::class, 'readAlike'))->getDocComment());

        $this->assertStringContainsString('wherever their text is spliced as a group into a larger pattern', $doc);
        $this->assertStringNotContainsString('wherever their text is spliced into a larger pattern', $doc);
        $this->assertStringContainsString('"/\xFF+?(?:a?)\w/" finds "\xFF\xFF" where "/\xFF+?a?\w/" finds nothing', $doc);

        // The pair the docblock names reads alike, under any tables.
        $this->assertTrue((new LanguageSolver())->readAlike('/(?:a?)/', '/a?/'));
        $this->assertTrue((new LanguageSolver())->readAlike('/a?/', '/(?:a?)/'));
    }

    /**
     * The engine behind that sentence, PCRE2 10.49 under Latin-1, where the
     * byte 0xFF is a word character, with the JIT and without it: spliced
     * bare after a lazy repeat of that byte, "(?:a?)" and "a?" part ways;
     * spliced as a group, capturing, named or not, they find the same.
     */
    #[Test]
    public function test_patterns_that_read_alike_part_ways_spliced_bare_and_agree_spliced_as_a_group(): void
    {
        $this->requiresPcre2Release('10.49', 'A lazy repeat of the byte 0xFF before a group');
        $this->requiresLocale(self::LATIN1, '/\xFF+?(?:a?)\w/');

        $found = new \ArrayObject();
        CharacterTables::under(self::LATIN1, static function () use ($found): void {
            foreach (['', '(*NO_JIT)'] as $start) {
                foreach (['bare' => '%s', 'in brackets' => '(?:%s)', 'in a group' => '(%s)', 'in a named group' => '(?<v>%s)'] as $splice => $group) {
                    foreach (["\xFF\xFF", "\xFF\xFFa", "\xFFa", "\xFF\xFF\xFF", "\xFFab"] as $subject) {
                        $matches = [];
                        foreach (['(?:a?)', 'a?'] as $text) {
                            preg_match('/'.$start.'\xFF+?'.sprintf($group, $text).'\w/', $subject, $match);
                            $matches[] = bin2hex($match[0] ?? '-');
                        }

                        $found[$start.' '.$splice.' on '.bin2hex($subject)] = $matches;
                    }
                }
            }
        });

        // Bare, the brackets of "(?:a?)" are the only ones: the engine
        // finds two bytes with them and nothing without.
        $this->assertSame(['ffff', '2d'], $found[' bare on ffff']);
        $this->assertSame(['ffff', '2d'], $found['(*NO_JIT) bare on ffff']);
        $this->assertSame(['ffff', '2d'], $found[' bare on ffffff']);

        foreach ($found as $where => [$grouped, $plain]) {
            if (!str_contains($where, ' bare on ')) {
                $this->assertSame($grouped, $plain, $where);
            }
        }

        $this->assertTrue((new LanguageSolver(RegexParser::create(['cache' => null, 'locale' => self::LATIN1])))->readAlike('/(?:a?)/', '/a?/'));
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
