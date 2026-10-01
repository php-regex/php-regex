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

namespace PHPRegex\Tests\Integration\Bridge\Laravel;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Redos\RedosAnalyzer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The security warning of "regex:explain" names the verdict as every other
 * ReDoS surface, and shows the attack.
 */
final class ExplainCommandRedosTest extends TestCase
{
    #[Test]
    #[DataProvider('provideVerdicts')]
    public function test_explain_names_the_verdict_and_the_attack(string $pattern, string $headline): void
    {
        $witness = (new RedosAnalyzer())->analyze($pattern)->witness;
        $this->assertNotNull($witness);

        Artisan::call('regex:explain', ['pattern' => $pattern]);
        $output = Artisan::output();

        $this->assertStringContainsString($headline, $output);
        $this->assertStringContainsString('Attack: '.$witness->render(), $output);
    }

    /**
     * @return iterable<string, array{pattern: string, headline: string}>
     */
    public static function provideVerdicts(): iterable
    {
        // a…a! fails at n=19 (PCRE2 10.49, JIT on and off).
        yield 'proven exponential' => ['pattern' => '/(a+)+$/', 'headline' => 'Exponential backtracking (proven)'];
        // a…a! fails at n=1411 with the JIT (PCRE2 10.49).
        yield 'proven polynomial of degree 3' => ['pattern' => '/a*a*a*$/', 'headline' => 'Polynomial backtracking, degree 3 (proven)'];
    }

    /**
     * The severity stays on its own line, right under the headline.
     */
    #[Test]
    #[DataProvider('provideSeverities')]
    public function test_explain_prints_the_severity_under_the_headline(string $pattern, string $severity): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertSame($severity, $analysis->severity->value);

        Artisan::call('regex:explain', ['pattern' => $pattern]);
        $output = Artisan::output();

        $this->assertMatchesRegularExpression(
            '/^\s*'.preg_quote($analysis->headline(), '/').'\R\s*Severity: '.preg_quote($severity, '/').'$/m',
            $output,
        );
    }

    /**
     * @return iterable<string, array{pattern: string, severity: string}>
     */
    public static function provideSeverities(): iterable
    {
        // a…a! fails at n=19 (PCRE2 10.49, JIT on and off).
        yield 'proven exponential' => ['pattern' => '/(a+)+$/', 'severity' => 'critical'];
        // Out of the model (backreference): the heuristics' severity. a…a!
        // fails at n=19 (PCRE2 10.49, JIT on and off).
        yield 'heuristic' => ['pattern' => '/(a+)+\1$/', 'severity' => 'critical'];
    }

    /**
     * Out of the model (backreference): the heuristics judge it, and there
     * is no witness to show.
     */
    #[Test]
    public function test_explain_names_a_heuristic_verdict_without_an_attack(): void
    {
        Artisan::call('regex:explain', ['pattern' => '/(a+)+\1$/']);
        $output = Artisan::output();

        $this->assertStringContainsString('Potential backtracking (heuristic)', $output);
        $this->assertStringNotContainsString('Attack:', $output);
    }

    /**
     * The attack line is the escaped literal, never the raw bytes. Each
     * pattern fails preg_match() at 19 pumps followed by "!" (PCRE2 10.49).
     */
    #[Test]
    #[DataProvider('provideEscapedWitnesses')]
    public function test_explain_prints_the_attack_escaped(string $pattern, string $attack): void
    {
        Artisan::call('regex:explain', ['pattern' => $pattern]);
        $output = Artisan::output();

        $this->assertStringContainsString('Attack: '.$attack, $output);
        $this->assertSame(1, preg_match('/Attack: (.*)$/m', $output, $match));
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F-\xFF]/', $match[1]);
    }

    /**
     * @return iterable<string, array{pattern: string, attack: string}>
     */
    public static function provideEscapedWitnesses(): iterable
    {
        yield 'escape control character' => ['pattern' => '/(\x1b+)+$/', 'attack' => '"\x1B" x n . "!"'];
        yield 'e acute under u' => ['pattern' => '/(é+)+$/u', 'attack' => '"\u{E9}" x n . "!"'];
    }

    /**
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('php-regex.cache.directory', null);
        $app['config']->set('php-regex.cache.store', null);
        $app['config']->set('php-regex.runtime_pcre_validation', false);
    }
}
