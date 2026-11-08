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
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Tests\Support\EngineProbe;
use PHPRegex\Tests\Support\Newlines;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The security warning of "regex:explain" names the verdict as every other
 * ReDoS surface, and shows the attack.
 */
final class ExplainCommandRedosTest extends LaravelTestCase
{
    /**
     * @param list<string> $sections the labels the fixture brings into the report
     */
    #[Test]
    #[DataProvider('provideHtmlPatterns')]
    public function test_html_output_encodes_pattern_derived_text(string $pattern, int $status, array $sections): void
    {
        $this->assertSame(0 === $status, EngineProbe::compiles($pattern));

        foreach ([false, true] as $decorated) {
            foreach ([false, true] as $highlight) {
                $output = new BufferedOutput(decorated: $decorated);
                $this->assertSame($status, Artisan::call('regex:explain', [
                    'pattern' => $pattern,
                    '--format' => 'html',
                    '--highlight' => $highlight,
                ], $output));
                $html = $output->fetch();

                $this->assertStringNotContainsString('<img', $html);
                $this->assertStringContainsString('&lt;img', $html);
                foreach ($sections as $section) {
                    $this->assertStringContainsString($section, $html);
                }
                if (0 === $status) {
                    $this->assertStringContainsString(htmlspecialchars($pattern, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'), $html);
                    $this->assertStringContainsString('<div class="regex-explain">', $html);
                }
            }
        }
    }

    /**
     * @return iterable<string, array{pattern: string, status: int, sections: list<string>}>
     */
    public static function provideHtmlPatterns(): iterable
    {
        yield 'literal and sample' => ['pattern' => '#<img src=x onerror=alert`1`>&"#', 'status' => 0, 'sections' => ['Sample Match:']];
        // The optimizer writes [0-9] as \d, so the suggestion quotes the pattern twice.
        yield 'optimization' => ['pattern' => '#<img src=x onerror=alert`1`>[0-9]#', 'status' => 0, 'sections' => ['Optimization Suggestion:', '&lt;img src=x onerror=alert`1`&gt;\\d']];
        yield 'security warning' => ['pattern' => '#(<img src=x onerror=alert`1`>)(a+)+$#', 'status' => 0, 'sections' => ['Security Warning:']];
        yield 'invalid pattern diagnostic' => ['pattern' => '#<img src=x onerror=alert`1`>[#', 'status' => 1, 'sections' => ['Invalid regex pattern:']];
    }

    #[Test]
    public function test_explain_prints_the_pattern_and_the_vulnerable_part_literally(): void
    {
        Artisan::call('regex:explain', ['pattern' => '#(<error>x</error>)a+#']);
        $output = Newlines::lf(Artisan::output());

        $this->assertStringContainsString('Pattern:', $output);
        $this->assertStringContainsString('(<error>x</error>)a+', $output);
    }

    #[Test]
    #[DataProvider('provideVerdicts')]
    public function test_explain_names_the_verdict_and_the_attack(string $pattern, string $headline): void
    {
        $witness = (new RedosAnalyzer())->analyze($pattern)->witness;
        $this->assertNotNull($witness);

        Artisan::call('regex:explain', ['pattern' => $pattern]);
        $output = Newlines::lf(Artisan::output());

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
        $output = Newlines::lf(Artisan::output());

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
        $output = Newlines::lf(Artisan::output());

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
        $output = Newlines::lf(Artisan::output());

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
        self::configOf($app)->set('php-regex.cache.directory', null);
        self::configOf($app)->set('php-regex.cache.store', null);
        self::configOf($app)->set('php-regex.runtime_pcre_validation', false);
    }
}
