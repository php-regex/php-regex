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
 * libraries, every preset unless configured, and the pattern is read at
 * the argument the library takes it.
 *
 * @extends RuleTestCase<RegexPatternRule>
 *
 * @phpstan-import-type ConfigArray from RegexPatternRule
 */
final class RegexPatternRuleWrapperCallTest extends RuleTestCase
{
    private const FIXTURE = __DIR__.'/Fixtures/WrapperCallFixture.php';

    /**
     * @var ConfigArray
     */
    private array $config = [];

    #[Test]
    public function test_every_preset_and_the_native_drop_ins_are_read_by_default(): void
    {
        $this->assertSame([26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36], $this->redosLines());
    }

    #[Test]
    public function test_the_interop_parameter_names_the_libraries_read(): void
    {
        $this->config = ['interop' => ['nette-utils', 'spatie-regex', 'illuminate-support']];

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

    /**
     * A named argument is read under the name of the library's parameter:
     * Strings::match() takes $pattern, so regex: and patterns: pass none.
     */
    #[Test]
    public function test_a_named_argument_is_read_under_the_parameter_name_of_the_library(): void
    {
        $this->assertSame([22], $this->redosLines(__DIR__.'/Fixtures/WrapperNamedArgumentFixture.php'));
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
    private function redosLines(string $fixture = self::FIXTURE): array
    {
        $lines = array_map(
            static fn (Error $error): int => (int) $error->getLine(),
            array_values(array_filter(
                $this->gatherAnalyserErrors([$fixture]),
                static fn (Error $error): bool => RegexPatternRule::IDENTIFIER_REDOS === $error->getIdentifier(),
            )),
        );
        sort($lines);

        return $lines;
    }
}
