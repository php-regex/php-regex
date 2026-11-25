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

namespace PHPRegex\Tests\Unit\Redos;

use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Tests\Support\ProcessPipes;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Under another locale's tables without u, the paths of the engine may
 * read the case of a byte apart (see LocaleCaseOnEachEnginePathTest): a
 * loop whose branches start apart on one path may start alike on the
 * other, and backtrack there through every split of a run. The proof reads
 * a byte whose case the paths read apart as one of a set not known, and
 * proves no loop safe through it; the heuristics read what either path
 * takes, a set not known where the interpreter takes a third byte, and
 * find the branches meeting.
 *
 * Engine: PHP 8.4.28 / PCRE2 10.49 on macOS, under PHP's default
 * backtrack limit, on 20 times the byte and "!": the JIT in a process of
 * its own, as PHP keeps a pattern compiled with the JIT, or without it,
 * whatever pcre.jit says later, and the interpreter with "(*NO_JIT)".
 */
final class RedosLocaleCaseOnEachEnginePathTest extends TestCase
{
    use InstalledLocales;

    /**
     * @param array{jit: bool, interpreter: bool} $givesUp whether each path gives up on the subject
     */
    #[Test]
    #[DataProvider('provideLoops')]
    public function test_a_loop_whose_branches_one_path_reads_alike_is_critical(string $pattern, string $unit, string $locale, array $givesUp): void
    {
        $this->requiresLocale($locale, $pattern);
        $label = addcslashes($pattern, "\200..\377").' under '.$locale;
        $subject = str_repeat($unit, 20).'!';

        // Oracle: whether each path gives up on the subject under PHP's
        // default backtrack limit.
        $interpreter = null;
        CharacterTables::under($locale, static function () use ($pattern, $subject, &$interpreter): void {
            $interpreter = false === @preg_match(PcreInterpreter::pattern($pattern), $subject) && \PREG_BACKTRACK_LIMIT_ERROR === preg_last_error();
        });
        $this->assertSame($givesUp['interpreter'], $interpreter, 'Oracle, the interpreter: '.$label);
        $onJit = self::jitGivesUp($pattern, $subject, $locale);
        if (null !== $onJit) {
            $this->assertSame($givesUp['jit'], $onJit, 'Oracle, the JIT: '.$label);
        }

        $this->assertSame(RedosSeverity::Critical, Regex::create(['cache' => null, 'php_version' => '8.4', 'locale' => $locale])->redos($pattern)->severity, $label);
    }

    /**
     * @return iterable<string, array{pattern: string, unit: string, locale: string, givesUp: array{jit: bool, interpreter: bool}}>
     */
    public static function provideLoops(): iterable
    {
        yield 'the final sigma or the capital sigma, caseless' => ['pattern' => '/^(?:\xF2|\xD3)+$/i', 'unit' => "\xD3", 'locale' => 'el_GR.ISO8859-7', 'givesUp' => ['jit' => true, 'interpreter' => false]];
        yield 'the dotless i or I, caseless' => ['pattern' => '/^(?:\xFD|I)+$/i', 'unit' => 'I', 'locale' => 'tr_TR.ISO8859-9', 'givesUp' => ['jit' => true, 'interpreter' => false]];
        yield 'a class of i and I or the dotted capital I' => ['pattern' => '/^(?:[Ii]|\xDD)+$/', 'unit' => "\xDD", 'locale' => 'tr_TR.ISO8859-9', 'givesUp' => ['jit' => false, 'interpreter' => true]];
    }

    /**
     * Whether the JIT gives up on the subject under PHP's default
     * backtrack limit, in a process of its own; null where this PHP has no
     * JIT.
     */
    private static function jitGivesUp(string $pattern, string $subject, string $locale): ?bool
    {
        $process = proc_open(
            [\PHP_BINARY, '-n', '-d', 'pcre.jit=1', '-d', 'pcre.backtrack_limit=1000000', '-r', 'setlocale(LC_CTYPE, $argv[1]); var_export(PCRE_JIT_SUPPORT ? false === @preg_match(base64_decode($argv[2]), base64_decode($argv[3])) && PREG_BACKTRACK_LIMIT_ERROR === preg_last_error() : null);', $locale, base64_encode($pattern), base64_encode($subject)],
            ProcessPipes::descriptors(),
            $pipes,
        );
        self::assertIsResource($process);
        $output = trim(ProcessPipes::drain($pipes)[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return 'NULL' === $output ? null : 'true' === $output;
    }
}
