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

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\ImpossibleLookaroundRule;
use PHPRegex\Linter\Rule\OverlappingAlternationRule;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Tests\Support\ProcessPipes;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Under another locale's tables without u, the paths of the engine may
 * read the case of a byte apart: the interpreter compares a character
 * matched alone by the small letters of the tables (pcre2_match.c,
 * OP_CHARI), where the JIT, and the interpreter's repeat of it, take the
 * byte and the other case the tables give it; and PCRE2 reads a class of a
 * byte and that other case as the byte, caseless (pcre2_compile.c), even
 * where the pattern is not. Under macOS's tr_TR.ISO8859-9 "\xFD", the
 * dotless i, takes "I" on the JIT alone, "I" takes "\xDD", the dotted
 * capital I, on the interpreter alone, and "[\xFDI]" leaves "I" out there;
 * under el_GR.ISO8859-7 "\xF2", the final sigma, takes the capital sigma
 * "\xD3" on the JIT alone. The rules judge what any path takes: a lookahead
 * is impossible only where no path matches through it, and two branches
 * overlap where a path starts both with the same byte.
 *
 * Engine: PHP 8.4.28 / PCRE2 10.49 on macOS; the JIT runs in a process of
 * its own, as PHP keeps a pattern compiled with the JIT, or without it,
 * whatever pcre.jit says later, and the interpreter with "(*NO_JIT)".
 */
final class LocaleCaseOnEachEnginePathTest extends TestCase
{
    use InstalledLocales;

    private const TURKISH = 'tr_TR.ISO8859-9';

    private const GREEK = 'el_GR.ISO8859-7';

    /**
     * A byte whose case the paths read apart is one of a set not known: a
     * lookahead that asks for it is not judged, "\xFD" before "a" included.
     *
     * @param list<int> $onJit         the one-byte subjects the JIT matches
     * @param list<int> $onInterpreter the one-byte subjects the interpreter matches
     */
    #[Test]
    #[DataProvider('provideLookaheads')]
    public function test_a_lookahead_is_impossible_only_where_no_path_of_the_engine_matches_through_it(string $pattern, string $locale, array $onJit, array $onInterpreter, bool $reported): void
    {
        $this->requiresLocale($locale, $pattern);
        $label = addcslashes($pattern, "\200..\377").' under '.$locale;

        // Oracle: the one-byte subjects each path matches, the pattern
        // reading one character.
        $subjects = array_map(\chr(...), range(0, 0xFF));
        $taken = self::onEachPath([$pattern], $subjects, $locale);
        $this->assertSame($onInterpreter, $taken['interpreter'][0], 'Oracle, the interpreter: '.$label);
        if (null !== $taken['jit']) {
            $this->assertSame($onJit, $taken['jit'][0], 'Oracle, the JIT: '.$label);
        }

        $this->assertFalse($reported && ([] !== $onJit || [] !== $onInterpreter), 'A row reported where a path matches: '.$label);
        $this->assertSame($reported, \in_array(ImpossibleLookaroundRule::ID, self::issues($pattern, $locale), true), $label);
    }

    /**
     * @return iterable<string, array{pattern: string, locale: string, onJit: list<int>, onInterpreter: list<int>, reported: bool}>
     */
    public static function provideLookaheads(): iterable
    {
        yield 'the dotless i read caselessly, then i' => ['pattern' => '/(?=\xFD)i/i', 'locale' => self::TURKISH, 'onJit' => [0x49], 'onInterpreter' => [], 'reported' => false];
        yield 'no I read caselessly, then the dotted capital I' => ['pattern' => '/(?!(?i:I))\xDD/', 'locale' => self::TURKISH, 'onJit' => [0xDD], 'onInterpreter' => [], 'reported' => false];
        yield 'no class of the dotless i and I, then I' => ['pattern' => '/(?![\xFDI])I/', 'locale' => self::TURKISH, 'onJit' => [], 'onInterpreter' => [0x49], 'reported' => false];
        yield 'a class of i and I, then the dotted capital I' => ['pattern' => '/(?=[Ii])\xDD/', 'locale' => self::TURKISH, 'onJit' => [], 'onInterpreter' => [0xDD], 'reported' => false];
        yield 'the final sigma read caselessly, then the capital sigma' => ['pattern' => '/(?=(?i:\xF2))\xD3/', 'locale' => self::GREEK, 'onJit' => [0xD3], 'onInterpreter' => [], 'reported' => false];
        yield 'the dotless i read caselessly, then a' => ['pattern' => '/(?=\xFD)a/i', 'locale' => self::TURKISH, 'onJit' => [], 'onInterpreter' => [], 'reported' => false];
        yield 'the small c with cedilla read caselessly, then a' => ['pattern' => '/(?=\xE7)a/i', 'locale' => self::TURKISH, 'onJit' => [], 'onInterpreter' => [], 'reported' => true];
        yield 'a class of the dotless i and the small s with cedilla, then I' => ['pattern' => '/(?=[\xFD\xFE])I/', 'locale' => self::TURKISH, 'onJit' => [], 'onInterpreter' => [], 'reported' => true];
        yield 'the capital sigma read caselessly, then the small sigma' => ['pattern' => '/(?=\xD3)\xF3/i', 'locale' => self::GREEK, 'onJit' => [0xD3, 0xF3], 'onInterpreter' => [0xD3, 0xF3], 'reported' => false];
        yield 'no capital sigma read caselessly, then the small sigma' => ['pattern' => '/(?!\xD3)\xF3/i', 'locale' => self::GREEK, 'onJit' => [], 'onInterpreter' => [], 'reported' => true];
    }

    /**
     * @param array{string, string} $branches      the branches, each with the flags it reads its first character under
     * @param list<int>             $onJit         the bytes the JIT starts both branches with
     * @param list<int>             $onInterpreter the bytes the interpreter starts both branches with
     */
    #[Test]
    #[DataProvider('provideAlternations')]
    public function test_branches_overlap_where_a_path_of_the_engine_starts_both_with_the_same_byte(string $pattern, array $branches, string $locale, array $onJit, array $onInterpreter): void
    {
        $this->requiresLocale($locale, $pattern);
        $label = addcslashes($pattern, "\200..\377").' under '.$locale;

        // Oracle: the bytes each path starts both branches with.
        $subjects = array_map(\chr(...), range(0, 0xFF));
        $taken = self::onEachPath(array_map(static fn (string $branch): string => '/^(?:'.$branch.')$/', $branches), $subjects, $locale);
        $this->assertSame($onInterpreter, array_values(array_intersect(...$taken['interpreter'])), 'Oracle, the interpreter: '.$label);
        if (null !== $taken['jit']) {
            $this->assertSame($onJit, array_values(array_intersect(...$taken['jit'])), 'Oracle, the JIT: '.$label);
        }

        $reported = array_values(array_intersect([OverlappingAlternationRule::ID_OVERLAP, OverlappingAlternationRule::ID_CHARSET], self::issues($pattern, $locale)));
        $this->assertSame([] === $onJit && [] === $onInterpreter ? [] : [OverlappingAlternationRule::ID_CHARSET], $reported, $label);
    }

    /**
     * @return iterable<string, array{pattern: string, branches: array{string, string}, locale: string, onJit: list<int>, onInterpreter: list<int>}>
     */
    public static function provideAlternations(): iterable
    {
        yield 'the final sigma, then the capital sigma' => ['pattern' => '/(?:\xF2|\xD3)+/i', 'branches' => ['(?i:\xF2)', '(?i:\xD3)'], 'locale' => self::GREEK, 'onJit' => [0xD3], 'onInterpreter' => []];
        yield 'the dotless i, then I' => ['pattern' => '/(?:\xFD|I)+/i', 'branches' => ['(?i:\xFD)', '(?i:I)'], 'locale' => self::TURKISH, 'onJit' => [0x49], 'onInterpreter' => []];
        yield 'the final sigma, then the capital alpha' => ['pattern' => '/(?:\xF2|\xC1)+/i', 'branches' => ['(?i:\xF2)', '(?i:\xC1)'], 'locale' => self::GREEK, 'onJit' => [], 'onInterpreter' => []];
    }

    /**
     * The solver refuses a pattern holding a byte the paths read apart,
     * whose language is not one: "\xFD" read caselessly takes "I" on the
     * JIT alone. A byte they read alike is answered.
     */
    #[Test]
    public function test_the_solver_refuses_a_byte_the_paths_of_the_engine_read_apart(): void
    {
        $this->requiresLocale(self::TURKISH, 'The solver under Turkish tables');

        // Oracle: "\xFD" and "\xE7" read caselessly, on each path.
        $taken = self::onEachPath(['/^\xFD$/i', '/^\xE7$/i'], ['I', "\xC7"], self::TURKISH);
        $this->assertSame([[], [0xC7]], $taken['interpreter'], 'Oracle, the interpreter.');
        if (null !== $taken['jit']) {
            $this->assertSame([[0x49], [0xC7]], $taken['jit'], 'Oracle, the JIT.');
        }

        $solver = new LanguageSolver(RegexParser::create(['cache' => null, 'php_version' => '8.4', 'locale' => self::TURKISH]));
        $this->assertFalse($solver->intersection('/^\xE7$/i', '/^\xC7$/')->isEmpty);

        $this->expectException(ComplexityException::class);
        $solver->intersection('/^\xFD$/i', '/^I$/');
    }

    /**
     * The ids of the issues the linter raises on the pattern, for PHP 8.4
     * under the locale.
     *
     * @return list<string>
     */
    private static function issues(string $pattern, string $locale): array
    {
        $regex = Regex::create(['cache' => null, 'php_version' => '8.4', 'locale' => $locale]);
        $linter = new PatternLinter($regex->target());
        $regex->parse($pattern)->accept($linter);

        return array_map(static fn ($issue): string => $issue->id, $linter->getIssues());
    }

    /**
     * The subjects each pattern matches under the locale, as bytes, on the
     * interpreter, and on the JIT in a process of its own: null where this
     * PHP has none.
     *
     * @param list<string> $patterns
     * @param list<string> $subjects
     *
     * @return array{jit: list<list<int>>|null, interpreter: list<list<int>>}
     */
    private static function onEachPath(array $patterns, array $subjects, string $locale): array
    {
        $interpreter = [];
        CharacterTables::under($locale, static function () use ($patterns, $subjects, &$interpreter): void {
            foreach ($patterns as $pattern) {
                $interpreter[] = array_values(array_map(\ord(...), array_filter($subjects, static fn (string $subject): bool => 1 === preg_match(PcreInterpreter::pattern($pattern), $subject))));
            }
        });

        $process = proc_open(
            [\PHP_BINARY, '-n', '-d', 'pcre.jit=1', '-r', '$s = unserialize(base64_decode($argv[2])); setlocale(LC_CTYPE, $argv[1]); $t = []; foreach (unserialize(base64_decode($argv[3])) as $p) { $t[] = array_values(array_map("ord", array_filter($s, static fn ($x) => 1 === preg_match($p, $x)))); } echo json_encode(PCRE_JIT_SUPPORT ? $t : null);', $locale, base64_encode(serialize($subjects)), base64_encode(serialize($patterns))],
            ProcessPipes::descriptors(),
            $pipes,
        );
        self::assertIsResource($process);
        $output = ProcessPipes::drain($pipes)[0];
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        /** @var list<list<int>>|null $jit */
        $jit = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        return ['jit' => $jit, 'interpreter' => $interpreter];
    }
}
