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

use PHPUnit\Framework\Attributes\Test;
use Illuminate\Contracts\Console\Kernel;
use PHPRegex\Laravel\PHPRegexServiceProvider;

/**
 * The Artisan commands take the options the CLI and the Symfony console
 * commands take for the same verb, under the same names and defaults.
 */
final class CommandSurfaceParityTest extends LaravelTestCase
{
    #[Test]
    public function test_compare_looks_for_an_intersection_by_default(): void
    {
        $this->command('regex:compare', ['pattern1' => '/[a-z]+/', 'pattern2' => '/[0-9]+/'])
            ->assertSuccessful()
            ->expectsOutputToContain('No intersection found. These regexes are disjoint.');

        $this->command('regex:compare', ['pattern1' => '/[a-z]+/', 'pattern2' => '/edit/'])
            ->assertFailed()
            ->expectsOutputToContain('Conflict detected!')
            ->expectsOutputToContain('Example:');
    }

    #[Test]
    public function test_compare_checks_a_subset(): void
    {
        $this->command('regex:compare', ['pattern1' => '/edit/', 'pattern2' => '/[a-z]+/', '--method' => 'subset'])
            ->assertSuccessful()
            ->expectsOutputToContain('Pattern 1 is a strict subset of Pattern 2.');

        $this->command('regex:compare', ['pattern1' => '/[a-z]+/', 'pattern2' => '/edit/', '--method' => 'subset'])
            ->assertFailed()
            ->expectsOutputToContain('Pattern 1 allows strings that Pattern 2 forbids.')
            ->expectsOutputToContain('Counterexample:');
    }

    #[Test]
    public function test_compare_checks_an_equivalence(): void
    {
        $this->command('regex:compare', ['pattern1' => '/[0-9]+/', 'pattern2' => '/\d+/', '--method' => 'equivalence'])
            ->assertSuccessful()
            ->expectsOutputToContain('EQUIVALENT');
    }

    #[Test]
    public function test_compare_refuses_an_unknown_method(): void
    {
        $this->command('regex:compare', ['pattern1' => '/a/', 'pattern2' => '/b/', '--method' => 'union'])
            ->assertExitCode(2)
            ->expectsOutputToContain("Invalid method 'union'. Supported: intersection, subset, equivalence");
    }

    #[Test]
    public function test_compare_reports_each_method_in_json(): void
    {
        $intersection = $this->compareJson(['pattern1' => '/[a-z]+/', 'pattern2' => '/edit/']);
        $this->assertSame('intersection', $intersection['method']);
        $this->assertFalse($intersection['disjoint']);
        $this->assertSame('edit', $intersection['example']);

        $subset = $this->compareJson(['pattern1' => '/[a-z]+/', 'pattern2' => '/edit/', '--method' => 'subset']);
        $this->assertSame('subset', $subset['method']);
        $this->assertFalse($subset['subset']);
        $this->assertIsString($subset['counterexample']);
        $this->assertSame(1, preg_match('/^[a-z]+$/', $subset['counterexample']));
        $this->assertSame(0, preg_match('/^edit$/', $subset['counterexample']));

        $equivalence = $this->compareJson(['pattern1' => '/[0-9]+/', 'pattern2' => '/\d+/', '--method' => 'equivalence']);
        $this->assertSame('equivalence', $equivalence['method']);
        $this->assertTrue($equivalence['equivalent']);
        $this->assertNull($equivalence['counterexample']);
    }

    #[Test]
    public function test_the_short_options_are_those_of_the_cli_and_symfony(): void
    {
        $this->assertSame('j', $this->registeredCommand('regex:lint')->getDefinition()->getOption('jobs')->getShortcut());
        $this->assertSame('t', $this->registeredCommand('regex:transpile')->getDefinition()->getOption('target')->getShortcut());
        $this->assertSame('compare', explode(':', (string) $this->registeredCommand('regex:compare')->getName())[1]);
        $this->assertSame('intersection', $this->registeredCommand('regex:compare')->getDefinition()->getOption('method')->getDefault());
    }

    #[Test]
    public function test_transpile_takes_its_target_after_t(): void
    {
        $this->command('regex:transpile', ['pattern' => '/^[a-z]+$/', '-t' => 'python'])
            ->assertSuccessful()
            ->expectsOutputToContain('Target (Python):');
    }

    /**
     * A pattern the optimizer shortens prints its suggestion beside the
     * explanation.
     */
    #[Test]
    public function test_explain_prints_the_optimization_suggestion(): void
    {
        $this->command('regex:explain', ['pattern' => '/[0-9]+/'])
            ->assertSuccessful()
            ->expectsOutputToContain('Optimization Suggestion:')
            ->expectsOutputToContain('/\d+/');
    }

    /**
     * @param array<string, string> $arguments
     *
     * @return array<string, mixed>
     */
    private function compareJson(array $arguments): array
    {
        $kernel = $this->application()->make(Kernel::class);
        $kernel->call('regex:compare', $arguments + ['--format' => 'json']);
        $document = json_decode($kernel->output(), true);
        $this->assertIsArray($document);

        /** @var array<string, mixed> $document */
        return $document;
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        self::configOf($app)->set('php-regex.cache.directory', null);
        self::configOf($app)->set('php-regex.cache.store', null);
        self::configOf($app)->set('php-regex.runtime_pcre_validation', false);
    }
}
