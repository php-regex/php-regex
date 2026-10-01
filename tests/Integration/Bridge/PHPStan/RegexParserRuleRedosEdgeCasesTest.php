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

use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\RegexParser;
use PHPRegex\PHPStan\RegexPatternRule;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosProfiler;
use PHPRegex\Redos\RedosWitness;
use PHPStan\Analyser\Error;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The ReDoS tip at its edges: a verdict the heuristics gave because the
 * model ran out of budget, and witnesses whose characters a terminal or a
 * baseline file must never receive raw.
 *
 * A "not analyzed" verdict never reaches this rule: a pattern the library
 * cannot parse or validate is reported as invalid before any ReDoS
 * analysis, and the other "not analyzed" results (mode off, ignored
 * pattern) are safe, below every threshold.
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexParserRuleRedosEdgeCasesTest extends RuleTestCase
{
    private const FIXTURE = __DIR__.'/Fixtures/RedosEdgeCasesFixture.php';

    private const OVER_BUDGET = '/^(?:(?:a{16}){16}){16}(a+)+$/';

    /**
     * 16 x 16 x 16 unrolled states exceed the 2,000-state budget; the
     * heuristics decide and the tip says the budget was exceeded.
     */
    #[Test]
    public function test_redos_tip_names_the_budget(): void
    {
        $error = $this->errorOnLine(20);

        $this->assertSame('Potential backtracking (ReDoS): '.self::OVER_BUDGET, $error->getMessage());
        $tip = (string) $error->getTip();
        $this->assertStringStartsWith('Severity: '.self::heuristicSeverity(self::OVER_BUDGET).", heuristic (budget exceeded).\n", $tip);
        $this->assertStringNotContainsString('Attack: ', $tip);
    }

    /**
     * Each fails preg_match() at 19 pumps followed by "!" (PCRE2 10.49).
     */
    #[Test]
    #[DataProvider('provideEscapedWitnesses')]
    public function test_redos_tip_escapes_the_witness(int $line, string $attack): void
    {
        $tip = (string) $this->errorOnLine($line)->getTip();

        $this->assertStringContainsString("\nAttack: ".$attack."\n", $tip);
        $this->assertSame(1, preg_match('/^Attack: (.*)$/m', $tip, $match));
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F-\xFF]/', $match[1]);
    }

    /**
     * @return iterable<string, array{line: int, attack: string}>
     */
    public static function provideEscapedWitnesses(): iterable
    {
        yield 'escape control character' => ['line' => 21, 'attack' => '"\x1B" x n . "!"'];
        yield 'e acute under u' => ['line' => 22, 'attack' => '"\u{E9}" x n . "!"'];
    }

    /**
     * PHPStan prints the tip through the Symfony console formatter: a raw
     * "<error>" in the attack would be read as a style tag and vanish from
     * the output. The attack writes "<" as "\x3C" inside its PHP literal:
     * no markup, and the literal still gives the witness' bytes when pasted
     * back from the table or the JSON output. Each pattern fails preg_match()
     * at 19 pumps followed by "!", JIT on and off (PCRE2 10.49).
     */
    #[Test]
    #[DataProvider('provideMarkupWitnesses')]
    public function test_redos_tip_escapes_console_markup_in_the_attack(int $line, string $pattern, string $attack): void
    {
        $tip = (string) $this->errorOnLine($line)->getTip();

        $this->assertSame(1, preg_match('/^Attack: (.*)$/m', $tip, $match), $tip);
        $this->assertSame($attack, $match[1]);
        $this->assertStringNotContainsString('<', $match[1]);

        $witness = (new RedosAnalyzer())->analyze($pattern)->witness;
        $this->assertInstanceOf(RedosWitness::class, $witness);
        $this->assertSame(
            ['prefix' => $witness->prefix, 'pump' => $witness->pump, 'suffix' => $witness->suffix],
            self::decodeAttack($match[1]),
        );
    }

    /**
     * @return iterable<string, array{line: int, pattern: string, attack: string}>
     */
    public static function provideMarkupWitnesses(): iterable
    {
        yield 'console tag in the prefix' => ['line' => 23, 'pattern' => '/<error>(a+)+$/', 'attack' => '"\x3Cerror>" . "a" x n . "!"'];
        // The prefix is a backslash then "<": "\\" then "\x3C", never "\\<" (which a
        // console reads as an escaped "<" and prints one backslash short).
        yield 'backslash before the tag opener' => ['line' => 24, 'pattern' => '/\\\\<(a+)+$/', 'attack' => '"\\\\\x3C" . "a" x n . "!"'];
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

    private function errorOnLine(int $line): Error
    {
        foreach ($this->gatherAnalyserErrors([self::FIXTURE]) as $error) {
            if ($line === $error->getLine() && RegexPatternRule::IDENTIFIER_REDOS === $error->getIdentifier()) {
                return $error;
            }
        }

        $this->fail('No ReDoS error on line '.$line.': '.json_encode(array_map(
            static fn (Error $error): array => [$error->getLine(), $error->getIdentifier(), $error->getMessage()],
            $this->gatherAnalyserErrors([self::FIXTURE]),
        )));
    }

    /**
     * The bytes of an attack written '"prefix" . "pump" x n . "suffix"',
     * read the way PHP reads a double-quoted literal, for the escapes a
     * witness uses, without evaluating anything.
     *
     * @return array{prefix: string, pump: string, suffix: string}
     */
    private static function decodeAttack(string $attack): array
    {
        $literal = '"((?:[^"\\\\]|\\\\.)*)"';
        if (1 !== preg_match('/^(?:'.$literal.' \. )?'.$literal.' x n(?: \. '.$literal.')?$/', $attack, $parts)) {
            self::fail('Not an attack literal: '.$attack);
        }

        return [
            'prefix' => self::decodeLiteral($parts[1]),
            'pump' => self::decodeLiteral($parts[2]),
            'suffix' => self::decodeLiteral($parts[3] ?? ''),
        ];
    }

    private static function decodeLiteral(string $body): string
    {
        $decoded = preg_replace_callback(
            '/\\\\(?:x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]+)\}|(.))/s',
            static function (array $escape): string {
                if ('' !== $escape[1]) {
                    return \chr((int) hexdec($escape[1]));
                }

                if ('' !== $escape[2]) {
                    return mb_chr((int) hexdec($escape[2]), 'UTF-8');
                }

                return match ($escape[3]) {
                    'n' => "\n",
                    't' => "\t",
                    '\\' => '\\',
                    '"' => '"',
                    '$' => '$',
                    default => '\\'.$escape[3],
                };
            },
            $body,
        );
        self::assertIsString($decoded);

        return $decoded;
    }

    private static function heuristicSeverity(string $pattern): string
    {
        $ast = RegexParser::create()->parse($pattern);
        $profiler = new RedosProfiler(new CharSetAnalyzer($ast->flags));
        $ast->accept($profiler);

        return $profiler->getResult()['severity']->value;
    }
}
