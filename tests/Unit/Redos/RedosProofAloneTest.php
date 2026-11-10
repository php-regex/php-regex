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

use PHPRegex\Parser\Cache\ArrayCache;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosOptions;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The proof alone says what the analysis says of one match attempt, proven
 * linear or not, without the heuristics, the witness and the cost of the
 * search the analysis adds, and keeps nothing of the pattern: the repair of
 * a pattern asks it of every rewrite it tries.
 */
final class RedosProofAloneTest extends TestCase
{
    /**
     * The engine is asked first, without its JIT and under its default
     * limits, what it does of each pattern on a subject made to cost it:
     * it answers, it gives up at the backtrack limit, or it refuses the
     * pattern. A pattern proven linear answers; one PHP refuses is never
     * proven.
     */
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_proof_alone_answers_as_the_analysis_does(string $pattern, bool $provenSafe, string $subject, string $engine): void
    {
        $written = @preg_match(PcreInterpreter::pattern($pattern), $subject);
        $asked = match (true) {
            false !== $written => 'answers',
            \PREG_BACKTRACK_LIMIT_ERROR === preg_last_error() => 'gives up',
            default => 'refuses',
        };
        $this->assertSame($engine, $asked);
        if ($provenSafe) {
            $this->assertSame('answers', $asked, 'A pattern proven linear does not make the engine give up.');
        }

        $analyzer = new RedosAnalyzer();

        $this->assertSame($provenSafe, $analyzer->analyze($pattern)->isProvenSafe());
        $this->assertSame($provenSafe, $analyzer->provesSafe($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, provenSafe: bool, subject: string, engine: string}>
     */
    public static function providePatterns(): iterable
    {
        $letters = str_repeat('a', 5000);
        $pump = str_repeat('a', 40).'!';

        yield 'one loop' => ['pattern' => '/^a+b$/', 'provenSafe' => true, 'subject' => $letters, 'engine' => 'answers'];
        yield 'a possessive loop in a loop' => ['pattern' => '/^(?:a++)+$/', 'provenSafe' => true, 'subject' => $letters.'!', 'engine' => 'answers'];
        yield 'a search that retries a linear attempt' => ['pattern' => '/a+b/', 'provenSafe' => true, 'subject' => $letters, 'engine' => 'answers'];
        yield 'a loop in a loop' => ['pattern' => '/^(?:a+)+$/', 'provenSafe' => false, 'subject' => $pump, 'engine' => 'gives up'];
        // Not proven is no claim of a cost: the engine answers this one.
        yield 'two loops on the same letters' => ['pattern' => '/^\w+\w+!$/', 'provenSafe' => false, 'subject' => $letters.'!x', 'engine' => 'answers'];
        yield 'no culprit and no hotspot named' => ['pattern' => '/^\s*(?:\w+\s*)*$/', 'provenSafe' => false, 'subject' => $pump, 'engine' => 'gives up'];
        yield 'a pattern that does not parse' => ['pattern' => '/(a+/', 'provenSafe' => false, 'subject' => '', 'engine' => 'refuses'];
        yield 'a pattern that parses and is refused' => ['pattern' => '/a{2,1}/', 'provenSafe' => false, 'subject' => '', 'engine' => 'refuses'];
        yield 'a lookbehind with no bound' => ['pattern' => '/(?<=a+)b/', 'provenSafe' => false, 'subject' => '', 'engine' => 'refuses'];
    }

    /**
     * A pattern the caller asked not to analyse is not proven, and one the
     * model gives up on is not either: neither answer is a proof.
     */
    #[Test]
    public function test_a_pattern_ignored_or_past_the_budget_is_not_proven(): void
    {
        $this->assertTrue((new RedosAnalyzer())->provesSafe('/^a+b$/'));

        $ignoring = new RedosAnalyzer(ignoredPatterns: ['^a+b$']);
        $this->assertFalse($ignoring->analyze('/^a+b$/')->isProvenSafe());
        $this->assertFalse($ignoring->provesSafe('/^a+b$/'));

        $short = new RedosAnalyzer(options: new RedosOptions(maxStates: 1));
        $this->assertFalse($short->analyze('/^a+b$/')->isProvenSafe());
        $this->assertFalse($short->provesSafe('/^a+b$/'));
    }

    /**
     * Where PHP refuses to raise the PCRE limits, a regex of the library
     * may give up while the pattern is judged: the analysis then fails,
     * and the proof alone proves nothing. Under a backtrack limit of 1,
     * the JIT off, the regex that checks the modifiers gives up.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function test_a_library_failure_is_no_proof(): void
    {
        ini_set('pcre.jit', '0');
        $parser = RegexParser::create(['cache' => null]);
        $this->assertTrue((new RedosAnalyzer($parser))->provesSafe('/^a+b$/'), 'Proven under the default limits.');

        $saved = (string) ini_get('pcre.backtrack_limit');
        LibraryPcre::useIniSetter(static fn (): bool => false);
        ini_set('pcre.backtrack_limit', '1');

        $starved = new RedosAnalyzer($parser);

        try {
            $analysis = $starved->analyze('/^a+b$/');
            $proven = $starved->provesSafe('/^a+b$/');
        } finally {
            ini_set('pcre.backtrack_limit', $saved);
            LibraryPcre::useIniSetter(null);
        }

        $this->assertNotNull($analysis->error);
        $this->assertFalse($analysis->isProvenSafe());
        $this->assertFalse($proven);
    }

    /**
     * The analysis reads the pattern through the parser's cache, where its
     * tree stays; the proof alone asks the cache nothing and leaves nothing
     * there, nor in the results the parser keeps.
     */
    #[Test]
    public function test_the_proof_alone_keeps_nothing_of_the_pattern(): void
    {
        $cache = new ArrayCache();
        $parser = RegexParser::create(['cache' => $cache]);

        $this->assertTrue((new RedosAnalyzer($parser))->provesSafe('/^a+b$/'));
        $this->assertSame(['hits' => 0, 'misses' => 0], $cache->getStats());
        $this->assertSame([], (new \ReflectionProperty($parser, 'results'))->getValue($parser));

        $read = new ArrayCache();
        $this->assertTrue((new RedosAnalyzer(RegexParser::create(['cache' => $read])))->analyze('/^a+b$/')->isProvenSafe());
        $this->assertSame(1, $read->getStats()['misses']);
    }
}
