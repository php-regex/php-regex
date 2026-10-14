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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan;

use PHPRegex\PHPStan\RegexPatternRule;
use PHPStan\Analyser\Error;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A pattern passed to a wrapper library's static method is judged as
 * `regex lint` judges it: the presets of the "interop" parameter name the
 * libraries, composer/pcre unless configured, and the pattern is read at
 * the argument the library takes it.
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexPatternRuleWrapperCallTest extends RuleTestCase
{
    private const FIXTURE = __DIR__.'/Fixtures/WrapperCallFixture.php';

    /**
     * @var array<string, mixed>
     */
    private array $config = [];

    #[Test]
    public function test_composer_pcre_and_the_native_drop_ins_are_read_by_default(): void
    {
        $this->assertSame([26, 27, 28, 29, 35, 36], $this->redosLines());
    }

    #[Test]
    public function test_the_interop_parameter_names_the_libraries_read(): void
    {
        $this->config = ['interop' => ['nette-utils', 'spatie-regex', 'laravel-str']];

        $this->assertSame([26, 30, 31, 32, 33, 34, 36], $this->redosLines());
    }

    #[Test]
    public function test_an_empty_interop_reads_the_native_functions_only(): void
    {
        $this->config = ['interop' => []];

        $this->assertSame([26, 36], $this->redosLines());
    }

    #[Test]
    public function test_a_wrapper_call_is_reported_as_the_native_call_is(): void
    {
        $messages = array_map(
            static fn (Error $error): string => $error->getMessage(),
            array_values(array_filter(
                $this->gatherAnalyserErrors([self::FIXTURE]),
                static fn (Error $error): bool => 27 === $error->getLine() && RegexPatternRule::IDENTIFIER_REDOS === $error->getIdentifier(),
            )),
        );

        $this->assertSame(['Exponential backtracking (ReDoS): /(b+)+$/'], $messages);
    }

    protected function getRule(): Rule
    {
        return new RegexPatternRule(config: [
            'checks' => [
                'lint' => ['enabled' => false],
                'redos' => ['enabled' => true, 'threshold' => 'low'],
                'optimizations' => ['enabled' => false],
            ],
            ...$this->config,
        ]);
    }

    /**
     * @return list<int>
     */
    private function redosLines(): array
    {
        $lines = array_map(
            static fn (Error $error): int => (int) $error->getLine(),
            array_values(array_filter(
                $this->gatherAnalyserErrors([self::FIXTURE]),
                static fn (Error $error): bool => RegexPatternRule::IDENTIFIER_REDOS === $error->getIdentifier(),
            )),
        );
        sort($lines);

        return $lines;
    }
}
