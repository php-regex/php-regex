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

use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\Confirmation;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosConfidence;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosOptions;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A verdict is one of a target: the PHP, the PCRE2 release and the locale
 * the pattern was read for. The analysis carries it beside the engine that
 * ran, which it need not be: under one running engine, "/^(?:a{,3}b?)+$/"
 * is exponential for PHP 8.4, where "{,3}" repeats, and linear for PHP 8.2,
 * where it is text.
 */
final class RedosAnalysisTargetTest extends TestCase
{
    #[Test]
    #[DataProvider('provideTargets')]
    public function test_a_verdict_carries_the_target_it_was_computed_for(string $php, string $pcre, RedosComplexity $complexity): void
    {
        // The engine first, where it is the one judged: the count is a
        // repeat from PCRE2 10.43, and three "a" match it there alone.
        $repeats = 1 === preg_match('/^a{,3}$/', 'aaa');
        $this->assertSame(PcreTarget::runtime()->pcreAtLeast('10.43'), $repeats);
        $this->assertSame(1, preg_match('/^a\{,3\}$/', 'a{,3}'));

        $parser = RegexParser::create(['php_version' => $php, 'cache' => null]);
        $analysis = (new RedosAnalyzer($parser))->analyze('/^(?:a{,3}b?)+$/');

        $this->assertSame($parser->target(), $analysis->target);
        $this->assertSame($pcre, $analysis->target->pcreVersion);
        $this->assertSame($complexity, $analysis->complexity);
        $this->assertSame(RedosProof::Proven, $analysis->proof);
        $this->assertSame(explode(' ', \PCRE_VERSION)[0], $analysis->pcreVersion, 'The engine that ran is the running one, whatever the target.');
        $this->assertSame(\PHP_VERSION, $analysis->phpVersion);
    }

    /**
     * @return iterable<string, array{php: string, pcre: string, complexity: RedosComplexity}>
     */
    public static function provideTargets(): iterable
    {
        yield 'PHP 8.2, where the braces are text' => ['php' => '8.2', 'pcre' => '10.40', 'complexity' => RedosComplexity::Linear];
        yield 'PHP 8.4, where they repeat' => ['php' => '8.4', 'pcre' => '10.44', 'complexity' => RedosComplexity::Exponential];
    }

    /**
     * Every way the analyzer builds an analysis carries its target: a
     * verdict, a pattern left out, a pattern refused, a pattern that does
     * not parse, and a verdict once replayed.
     *
     * @param list<string> $ignored
     */
    #[Test]
    #[DataProvider('provideAnalyses')]
    public function test_every_analysis_of_the_analyzer_carries_its_target(string $pattern, RedosMode $mode, array $ignored, RedosProof $proof): void
    {
        $parser = RegexParser::create(['php_version' => '8.2', 'cache' => null]);
        $this->assertNotSame(PcreTarget::runtime()->key(), $parser->target()->key());

        $analysis = (new RedosAnalyzer($parser, $ignored))->analyze($pattern, new RedosOptions($mode));

        $this->assertSame($proof, $analysis->proof);
        $this->assertSame($parser->target(), $analysis->target);
    }

    /**
     * @return iterable<string, array{pattern: string, mode: RedosMode, ignored: list<string>, proof: RedosProof}>
     */
    public static function provideAnalyses(): iterable
    {
        yield 'a verdict' => ['pattern' => '/(a+)+$/', 'mode' => RedosMode::Theoretical, 'ignored' => [], 'proof' => RedosProof::Proven];
        yield 'the analysis off' => ['pattern' => '/(a+)+$/', 'mode' => RedosMode::Off, 'ignored' => [], 'proof' => RedosProof::NotAnalyzed];
        yield 'a pattern left out' => ['pattern' => '/(a+)+$/', 'mode' => RedosMode::Theoretical, 'ignored' => ['(a+)+$'], 'proof' => RedosProof::NotAnalyzed];
        yield 'a pattern the target refuses' => ['pattern' => '/a{2,1}/', 'mode' => RedosMode::Theoretical, 'ignored' => [], 'proof' => RedosProof::NotAnalyzed];
        yield 'a pattern that does not parse' => ['pattern' => '/(/', 'mode' => RedosMode::Theoretical, 'ignored' => [], 'proof' => RedosProof::NotAnalyzed];
        yield 'a heuristic verdict' => ['pattern' => '/(a)\1+/', 'mode' => RedosMode::Theoretical, 'ignored' => [], 'proof' => RedosProof::Heuristic];
    }

    /**
     * The replay runs on the running engine, so a verdict is replayed for
     * its own target: the analysis it returns keeps it.
     */
    #[Test]
    public function test_a_replayed_verdict_keeps_its_target(): void
    {
        $parser = RegexParser::create(['cache' => null]);

        $analysis = (new RedosAnalyzer($parser))->analyze('/(a+)+$/', new RedosOptions(RedosMode::Confirmed));

        $this->assertInstanceOf(Confirmation::class, $analysis->confirmation);
        $this->assertSame($parser->target(), $analysis->target);
    }

    /**
     * Built without a target, an analysis is one of the running engine;
     * each builder takes the target and keeps it.
     */
    #[Test]
    public function test_an_analysis_built_without_a_target_carries_the_running_one(): void
    {
        $target = new PcreTarget(80200, '10.40', 'C');
        $running = PcreTarget::runtime()->key();
        $this->assertNotSame($running, $target->key());

        $this->assertSame($running, (new RedosAnalysis(RedosSeverity::Safe, 0))->target->key());
        $this->assertSame($running, RedosAnalysis::skipped(RedosMode::Off)->target->key());
        $this->assertSame($running, RedosAnalysis::failed(RedosMode::Theoretical, 'an error')->target->key());

        $this->assertSame($target, (new RedosAnalysis(RedosSeverity::Safe, 0, target: $target))->target);
        $this->assertSame($target, RedosAnalysis::skipped(RedosMode::Off, $target)->target);
        $this->assertSame($target, RedosAnalysis::failed(RedosMode::Theoretical, 'an error', null, $target)->target);
        $this->assertSame($target, RedosAnalysis::failed(RedosMode::Theoretical, 'an error', 'Advice.', $target)->target);

        $confirmed = (new RedosAnalysis(RedosSeverity::Critical, 10, target: $target))->withConfirmation(null, RedosConfidence::High, null, true);
        $this->assertSame($target, $confirmed->target);
    }

    /**
     * In JSON the target stands beside the engine that ran: "target" is
     * what was judged, "pcre_version" and "php_version" what computed it.
     */
    #[Test]
    public function test_the_json_of_an_analysis_names_its_target(): void
    {
        $target = new PcreTarget(80425, '10.44', 'C');

        $json = (new RedosAnalysis(RedosSeverity::Safe, 0, target: $target))->jsonSerialize();

        $this->assertSame(['php' => '8.4.25', 'pcre' => '10.44', 'locale' => 'C'], $json['target']);
        $this->assertSame($target->toArray(), $json['target']);
        $this->assertSame(explode(' ', \PCRE_VERSION)[0], $json['pcre_version']);
        $this->assertSame(\PHP_VERSION, $json['php_version']);

        $keys = array_keys($json);
        $this->assertSame(array_search('pcre_version', $keys, true), array_search('target', $keys, true) + 1, 'The target stands before the engine that ran.');

        $this->assertSame(PcreTarget::runtime()->toArray(), (new RedosAnalyzer())->analyze('/a/')->jsonSerialize()['target']);
    }
}
