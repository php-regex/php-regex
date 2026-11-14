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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\PcreTarget;
use PHPRegex\Tests\Support\EngineProbe;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Two facts no release names: whether a build carries the size-limit fix
 * PCRE2 gives the distributors of 10.39 to 10.47, and whether its
 * interpreter keeps, after a subroutine call, what the groups inside the
 * called one captured. The running engine is asked, once; a named target is
 * judged by its release.
 *
 * The second was measured on the releases PCRE2 tagged, built without the
 * JIT: from 10.30 to 10.42 the interpreter writes "2: 3" and "3: 4" for
 * /(?(DEFINE)(?<pair>(\d)(\d)))((?&pair)(?&pair))/ on 1234, and from 10.43
 * it leaves both unset. The copies PHP bundles leave both unset, without
 * the JIT too: 10.39 in PHP 8.1, 10.40 in 8.2, 10.42 in 8.3 (dated
 * 2022-12-12, the day after the release).
 */
final class RunningEngineFactsTest extends TestCase
{
    use InstalledLocales;

    protected function tearDown(): void
    {
        PcreTarget::useRunningEngineAnswers(null);
    }

    #[Test]
    public function test_the_running_engine_is_asked_whether_it_measures_branches_apart(): void
    {
        // Two branches too large only together, then a reference to no
        // group: checked apart, the reference is met first.
        $apart = !str_contains((string) EngineProbe::warning('/(?:[^a]){5000}|(?:[^a]){4000}\k<n>(?:[^a]){1000}/'), 'regular expression is too large');

        $this->assertSame($apart, PcreTarget::runtime()->measuresBranchesApart());

        // A build that says otherwise than its release: as a distribution
        // that applied the fix, or one that did not.
        PcreTarget::useRunningEngineAnswers(['measuresBranchesApart' => !$apart, 'keepsCallCaptures' => false]);
        $this->assertSame(!$apart, PcreTarget::runtime()->measuresBranchesApart());
    }

    #[Test]
    public function test_the_running_engine_is_asked_whether_its_interpreter_keeps_call_captures(): void
    {
        preg_match('/(*NO_JIT)(?(DEFINE)(?<pair>(\d)(\d)))((?&pair)(?&pair))/', '1234', $written);
        $keeps = ['', ''] !== [$written[2] ?? null, $written[3] ?? null];

        $this->assertSame($keeps, PcreTarget::runtime()->keepsCallCaptures());

        PcreTarget::useRunningEngineAnswers(['measuresBranchesApart' => true, 'keepsCallCaptures' => !$keeps]);
        $this->assertSame(!$keeps, PcreTarget::runtime()->keepsCallCaptures());
    }

    /**
     * The facts belong to a build, and a patch release is another one: PHP
     * 8.2.35 bundles the size-limit fix 8.2.34 does not, with the same
     * PCRE2 10.40. A target of the PHP minor version and the PCRE2 release
     * that run, at another patch release, is judged by its release, however
     * the running engine answers.
     */
    #[Test]
    public function test_a_target_at_another_patch_release_is_judged_by_its_release(): void
    {
        $other = new PcreTarget(0 === \PHP_VERSION_ID % 100 ? \PHP_VERSION_ID + 1 : \PHP_VERSION_ID - 1, \PCRE_VERSION);
        $this->assertTrue($other->isRunningEngine(), 'The same minor version, release and locale: the engine that validates.');

        $answers = [];
        foreach ([true, false] as $answer) {
            PcreTarget::useRunningEngineAnswers(['measuresBranchesApart' => $answer, 'keepsCallCaptures' => $answer]);
            $answers[] = [$other->measuresBranchesApart(), $other->keepsCallCaptures()];
        }

        $this->assertSame($answers[0], $answers[1]);
    }

    /**
     * A locale changes the character tables, not the library compiled: the
     * two probes give the same answers under C, tr_TR.ISO8859-9 and
     * fr_FR.ISO8859-1, and the build that runs answers under any locale. A
     * build carrying the fix is not judged as its release for a locale.
     */
    #[Test]
    public function test_the_build_that_runs_answers_under_another_locale(): void
    {
        $this->requiresLocale('tr_TR.ISO8859-9', 'The build that runs under another locale');
        $running = PcreTarget::runtime();
        $other = $running->withLocale('C' === $running->locale ? 'tr_TR.ISO8859-9' : 'C');

        foreach ([true, false] as $answer) {
            PcreTarget::useRunningEngineAnswers(['measuresBranchesApart' => $answer, 'keepsCallCaptures' => !$answer]);

            $this->assertSame([$answer, !$answer], [$other->measuresBranchesApart(), $other->keepsCallCaptures()]);
        }
    }

    /**
     * A release PCRE2 tagged before 10.43 keeps them, unless it is the one
     * the PHP of the target bundles. A target that names the build that
     * runs, its patch release included, is answered by it.
     */
    #[Test]
    #[DataProvider('provideNamedTargets')]
    public function test_a_named_target_keeps_call_captures_on_a_release_before_10_43_its_php_does_not_bundle(int $php, string $pcre, bool $keeps): void
    {
        $target = new PcreTarget($php, $pcre);
        $runs = \PHP_VERSION_ID === $php && $target->isRunningEngine();

        $this->assertSame($runs ? PcreTarget::runtime()->keepsCallCaptures() : $keeps, $target->keepsCallCaptures());
    }

    /**
     * @return iterable<string, array{php: int, pcre: string, keeps: bool}>
     */
    public static function provideNamedTargets(): iterable
    {
        yield 'a system 10.42 under PHP 8.4' => ['php' => 80400, 'pcre' => '10.42', 'keeps' => true];
        yield 'a system 10.39 under PHP 8.4' => ['php' => 80400, 'pcre' => '10.39', 'keeps' => true];
        yield 'a system 10.34 under PHP 8.4' => ['php' => 80400, 'pcre' => '10.34', 'keeps' => true];
        yield 'the 10.42 PHP 8.3 bundles' => ['php' => 80300, 'pcre' => '10.42', 'keeps' => false];
        yield 'the 10.40 PHP 8.2 bundles' => ['php' => 80200, 'pcre' => '10.40', 'keeps' => false];
        yield 'the 10.39 PHP 8.1 bundles' => ['php' => 80100, 'pcre' => '10.39', 'keeps' => false];
        yield 'a system 10.43' => ['php' => 80400, 'pcre' => '10.43', 'keeps' => false];
        yield 'a system 10.46' => ['php' => 80400, 'pcre' => '10.46', 'keeps' => false];
    }
}
