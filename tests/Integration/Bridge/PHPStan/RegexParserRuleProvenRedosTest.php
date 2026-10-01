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
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosWitness;
use PHPStan\Analyser\Error;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The ReDoS error of the rule: a short prefix and the pattern, frozen for
 * 2.x so that a verdict fix never breaks a baseline; severity, proof, degree
 * and the attack live in the tip.
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexParserRuleProvenRedosTest extends RuleTestCase
{
    private const FIXTURE = __DIR__.'/Fixtures/ProvenReDoSFixture.php';

    #[Test]
    public function test_redos_messages_are_the_frozen_prefixes(): void
    {
        $this->assertSame([
            [20, 'Exponential backtracking (ReDoS): /(a+)+$/', 'regex.redos'],
            [21, 'Polynomial backtracking (ReDoS): /a*a*a*$/', 'regex.redos'],
            [22, 'Potential backtracking (ReDoS): /(a+)+\1$/', 'regex.redos'],
        ], array_map(
            static fn (Error $error): array => [$error->getLine(), $error->getMessage(), $error->getIdentifier()],
            $this->redosErrors(),
        ));
    }

    #[Test]
    public function test_redos_tip_carries_the_proven_exponential_verdict(): void
    {
        $tip = $this->tipOnLine(20);

        $this->assertStringStartsWith("Severity: critical, exponential (proven).\nAttack: ".$this->renderedWitness('/(a+)+$/')."\n", $tip);

        $this->assertStringContainsStringIgnoringCase('critical', $tip);
        $this->assertStringContainsStringIgnoringCase('proven', $tip);
        $this->assertStringContainsString('Attack: '.$this->renderedWitness('/(a+)+$/'), $tip);
        $this->assertStringContainsString('Read more about catastrophic backtracking: ', $tip);
    }

    #[Test]
    public function test_redos_tip_carries_the_polynomial_degree(): void
    {
        $tip = $this->tipOnLine(21);

        $this->assertStringStartsWith("Severity: high, polynomial degree 3 (proven).\nAttack: ".$this->renderedWitness('/a*a*a*$/')."\n", $tip);

        $this->assertStringContainsStringIgnoringCase('high', $tip);
        $this->assertStringContainsStringIgnoringCase('proven', $tip);
        $this->assertStringContainsString('degree 3', $tip);
        $this->assertStringContainsString('Attack: '.$this->renderedWitness('/a*a*a*$/'), $tip);
    }

    #[Test]
    public function test_redos_tip_says_a_heuristic_verdict_has_no_attack(): void
    {
        $tip = $this->tipOnLine(22);

        // Out of the model; the heuristics judge it critical today.
        $this->assertStringStartsWith("Severity: critical, heuristic.\n", $tip);

        $this->assertStringContainsStringIgnoringCase('heuristic', $tip);
        $this->assertStringNotContainsString('Attack: ', $tip);
        $this->assertStringContainsString('Read more about catastrophic backtracking: ', $tip);
    }

    protected function getRule(): Rule
    {
        return new RegexPatternRule(config: [
            'checks' => [
                'lint' => ['enabled' => false],
                'redos' => ['enabled' => true, 'threshold' => 'low'],
                'optimizations' => ['enabled' => false],
            ],
        ]);
    }

    /**
     * @return list<Error>
     */
    private function redosErrors(): array
    {
        $errors = array_values(array_filter(
            $this->gatherAnalyserErrors([self::FIXTURE]),
            static fn (Error $error): bool => RegexPatternRule::IDENTIFIER_REDOS === $error->getIdentifier(),
        ));
        usort($errors, static fn (Error $a, Error $b): int => $a->getLine() <=> $b->getLine());

        return $errors;
    }

    private function tipOnLine(int $line): string
    {
        foreach ($this->redosErrors() as $error) {
            if ($line === $error->getLine()) {
                return (string) $error->getTip();
            }
        }

        $this->fail('No ReDoS error on line '.$line);
    }

    private function renderedWitness(string $pattern): string
    {
        $witness = (new RedosAnalyzer())->analyze($pattern)->witness;
        $this->assertInstanceOf(RedosWitness::class, $witness, $pattern);

        return $witness->render();
    }
}
