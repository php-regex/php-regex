<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Integration\Bridge\Laravel;

use PhpRegex\Optimizer\OptimizerOptions;
use PhpRegex\Parser\Exception\InvalidRegexOptionException;
use PhpRegex\Laravel\PhpRegexServiceProvider;
use PhpRegex\Laravel\Command\LintCommand;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The optimization settings of config/regex-parser.php reach the optimizer
 * under the names the config gives them: in 1.x only digits, word and
 * ranges did, the snake_case ones were dropped on the way.
 */
final class OptimizationConfigTest extends TestCase
{
    #[Test]
    public function test_every_config_key_reaches_the_optimizer(): void
    {
        $options = $this->optionsFrom([
            'digits' => false,
            'word' => false,
            'ranges' => false,
            'canonicalize_char_classes' => false,
            'possessive' => true,
            'factorize' => true,
            'min_quantifier_count' => 3,
        ]);

        $this->assertEquals(new OptimizerOptions(false, false, false, false, true, true, 3, true), $options);
    }

    #[Test]
    public function test_lint_checks_a_rewrite_with_the_automata_unless_told_otherwise(): void
    {
        $this->assertTrue($this->optionsFrom([])->verifyWithAutomata);
        $this->assertFalse($this->optionsFrom(['verify_with_automata' => false])->verifyWithAutomata);
    }

    #[Test]
    public function test_an_unknown_config_key_is_refused(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('Unknown optimizer option "canonicalizeCharClasses"');

        $this->optionsFrom(['canonicalizeCharClasses' => false]);
    }

    protected function getPackageProviders($app): array
    {
        return [
            PhpRegexServiceProvider::class,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function optionsFrom(array $config): OptimizerOptions
    {
        $command = $this->app?->make(LintCommand::class);
        $this->assertInstanceOf(LintCommand::class, $command);

        $options = (new \ReflectionClass($command))->getMethod('normalizeOptimizations')->invoke($command, $config);
        $this->assertInstanceOf(OptimizerOptions::class, $options);

        return $options;
    }
}
