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

namespace RegexParser\Tests\Unit\Bridge\PHPStan;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\TipRuleError;
use PHPStan\Type\Constant\ConstantStringType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RegexParser\Bridge\PHPStan\RegexParserRule;

final class RegexParserRuleEdgeCasesTest extends TestCase
{
    /**
     * Every check on, the lowest ReDoS threshold: what stays silent here is
     * silent whatever the configuration.
     */
    private const ALL_CHECKS = [
        'checks' => [
            'lint' => ['enabled' => true],
            'redos' => ['enabled' => true, 'threshold' => 'low'],
            'optimizations' => ['enabled' => true],
        ],
    ];

    public function test_process_node_returns_empty_for_unknown_function(): void
    {
        $rule = new RegexParserRule();
        /** @var CollectedDataEmitter&NodeCallbackInvoker&Scope&Stub $scope */
        $scope = $this->createStub(Scope::class);

        $node = new FuncCall(new Name('strlen'), []);
        $errors = $rule->processNode($node, $scope);

        $this->assertSame([], $errors);
    }

    public function test_process_node_returns_empty_for_non_name_function(): void
    {
        $rule = new RegexParserRule();
        /** @var CollectedDataEmitter&NodeCallbackInvoker&Scope&Stub $scope */
        $scope = $this->createStub(Scope::class);

        $node = new FuncCall(new Variable('preg_match'), []);

        $this->assertSame([], $rule->processNode($node, $scope));
    }

    public function test_process_node_returns_empty_when_pattern_arg_missing(): void
    {
        $rule = new RegexParserRule();
        /** @var CollectedDataEmitter&NodeCallbackInvoker&Scope&Stub $scope */
        $scope = $this->createStub(Scope::class);

        $node = new FuncCall(new Name('preg_match'), []);

        $this->assertSame([], $rule->processNode($node, $scope));
    }

    public function test_process_node_ignores_non_array_callback_patterns(): void
    {
        $rule = new RegexParserRule();
        /** @var CollectedDataEmitter&NodeCallbackInvoker&Scope&Stub $scope */
        $scope = $this->createStub(Scope::class);

        $node = new FuncCall(new Name('preg_replace_callback_array'), [
            new Arg(new String_('/foo/')),
        ]);

        $this->assertSame([], $rule->processNode($node, $scope));
    }

    public function test_process_node_skips_non_string_callback_keys(): void
    {
        $rule = new RegexParserRule();
        /** @var CollectedDataEmitter&NodeCallbackInvoker&Scope&Stub $scope */
        $scope = $this->createStub(Scope::class);

        $array = new Array_([
            new ArrayItem(new String_('handler'), new LNumber(1)),
        ]);

        $node = new FuncCall(new Name('preg_replace_callback_array'), [
            new Arg($array),
        ]);

        $this->assertSame([], $rule->processNode($node, $scope));
    }

    public function test_process_node_continues_after_non_string_callback_keys(): void
    {
        $rule = new RegexParserRule(config: ['checks' => ['redos' => ['enabled' => true, 'threshold' => 'low']]]);
        /** @var CollectedDataEmitter&NodeCallbackInvoker&Scope&Stub $scope */
        $scope = $this->createStub(Scope::class);
        $scope->method('getFile')->willReturn('file.php');

        $array = new Array_([
            new ArrayItem(new String_('handler'), new LNumber(1)),
            new ArrayItem(new String_('handler'), new String_('/(a+)+$/')),
        ]);

        $node = new FuncCall(new Name('preg_replace_callback_array'), [
            new Arg($array),
        ]);

        $errors = $rule->processNode($node, $scope);

        $this->assertCount(1, $errors);
        $this->assertSame('regex.redos', $errors[0]->getIdentifier());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providePatternsTheRunningEngineRefuses(): iterable
    {
        // Each is refused by every PCRE2 (preg_match() returns false); PHPStan core
        // reports them under "regexp.pattern", so this rule must not report them again.
        yield 'empty pattern' => [''];
        yield 'no delimiters' => ['foo'];
        yield 'no closing delimiter' => ['/foo'];
        yield 'unterminated class' => ['/['];
        yield 'unclosed group' => ['/(foo/'];
        yield 'reversed quantifier bounds' => ['/a{2,1}/'];
    }

    #[Test]
    #[DataProvider('providePatternsTheRunningEngineRefuses')]
    public function test_a_pattern_the_running_engine_refuses_gets_no_error_from_any_check(string $pattern): void
    {
        $this->assertSame([], $this->errorsFor(new RegexParserRule(config: self::ALL_CHECKS), $pattern, 10));
    }

    #[Test]
    #[DataProvider('providePatternsTheRunningEngineRefuses')]
    public function test_a_pattern_the_running_engine_refuses_gets_no_error_by_default(string $pattern): void
    {
        $this->assertSame([], $this->errorsFor(new RegexParserRule(), $pattern, 10));
    }

    #[Test]
    public function test_a_target_specific_refusal_is_reported_as_invalid_for_target(): void
    {
        // "(?aD)" arrived in PCRE2 10.43; PHP 8.2 bundles 10.40. Where the running
        // engine refuses it too, PHPStan core reports it and this rule stays silent.
        $pattern = '/(?aD)x/';
        $rule = new RegexParserRule(config: ['phpVersion' => '8.2']);

        $this->assertSame(
            self::runningEngineCompiles($pattern) ? ['regex.invalidForTarget'] : [],
            $this->identifiersOf($this->errorsFor($rule, $pattern, 7)),
        );
    }

    /**
     * The library's hint on a refusal becomes the error's tip: a lookbehind
     * whose branches vary in length is refused before PCRE2 10.43.
     */
    #[Test]
    public function test_the_hint_on_a_target_specific_refusal_is_the_tip(): void
    {
        $pattern = '/(?<=ab?)x/';
        $errors = $this->errorsFor(new RegexParserRule(config: ['phpVersion' => '8.2']), $pattern, 7);

        if (!self::runningEngineCompiles($pattern)) {
            $this->assertSame([], $errors);

            return;
        }

        $this->assertCount(1, $errors);
        $this->assertInstanceOf(TipRuleError::class, $errors[0]);
        $this->assertStringContainsString('fixed length', (string) $errors[0]->getTip());
    }

    /**
     * A delimiter "(*NO_JIT)" holds, as "_", does not hide the pattern from
     * the checks: the running engine still compiles it.
     */
    #[Test]
    public function test_a_pattern_with_an_underscore_delimiter_is_checked(): void
    {
        $rule = new RegexParserRule(config: ['checks' => ['lint' => ['enabled' => true]]]);

        $this->assertNotSame([], $this->errorsFor($rule, '_\\s+_m', 7));
    }

    #[Test]
    public function test_runtime_target_reports_no_validity_error(): void
    {
        $rule = new RegexParserRule(config: ['phpVersion' => 'runtime']);

        $this->assertSame([], $this->errorsFor($rule, '/(?aD)x/', 7));
    }

    public function test_default_report_redos_is_disabled(): void
    {
        $rule = new RegexParserRule();

        $errors = $this->errorsFor($rule, '/(a+)+$/', 5);

        $hasRedos = false;
        foreach ($errors as $error) {
            if (str_starts_with($error->getIdentifier(), 'regex.redos')) {
                $hasRedos = true;

                break;
            }
        }

        // ReDoS is disabled by default for performance
        $this->assertFalse($hasRedos);
    }

    #[Test]
    public function test_default_lint_is_disabled(): void
    {
        $this->assertSame([], $this->errorsFor(new RegexParserRule(), '/no_dot/s', 5));
    }

    #[Test]
    public function test_lint_is_reported_when_enabled(): void
    {
        $rule = new RegexParserRule(config: ['checks' => ['lint' => ['enabled' => true]]]);

        $this->assertSame(['regex.lint.flag.useless.s'], $this->identifiersOf($this->errorsFor($rule, '/no_dot/s', 5)));
    }

    public function test_default_suggest_optimizations_is_disabled(): void
    {
        $rule = new RegexParserRule();

        $errors = $this->errorsFor($rule, '/[0-9]+/', 9);

        $hasOptimization = false;
        foreach ($errors as $error) {
            if ('regex.optimization' === $error->getIdentifier()) {
                $hasOptimization = true;

                break;
            }
        }

        $this->assertFalse($hasOptimization);
    }

    public function test_checks_config_enables_redos_and_optimizations(): void
    {
        $rule = new RegexParserRule(
            config: [
                'checks' => [
                    'redos' => [
                        'enabled' => true,
                        'threshold' => 'low',
                    ],
                    'optimizations' => [
                        'enabled' => true,
                        'minSavings' => 1,
                        'options' => [
                            'digits' => true,
                            'word' => true,
                            'ranges' => true,
                            'canonicalizeCharClasses' => true,
                        ],
                    ],
                ],
            ],
        );

        $redosErrors = $this->errorsFor($rule, '/(a+)+$/', 5);
        $hasRedos = false;
        foreach ($redosErrors as $error) {
            if (str_starts_with($error->getIdentifier(), 'regex.redos')) {
                $hasRedos = true;

                break;
            }
        }
        $this->assertTrue($hasRedos);

        $optimizationErrors = $this->errorsFor($rule, '/[0-9]+/', 6);
        $hasOptimization = false;
        foreach ($optimizationErrors as $error) {
            if ('regex.optimization' === $error->getIdentifier()) {
                $hasOptimization = true;

                break;
            }
        }
        $this->assertTrue($hasOptimization);
    }

    public function test_default_optimization_config_enables_word_optimization(): void
    {
        $rule = new RegexParserRule(config: ['checks' => ['optimizations' => ['enabled' => true]]]);

        $errors = $this->errorsFor($rule, '/[A-Za-z0-9_]+/', 11);

        $identifiers = array_map(static fn ($error) => $error->getIdentifier(), $errors);
        $this->assertContains('regex.optimization', $identifiers);
    }

    public function test_default_optimization_config_avoids_cross_category_ranges(): void
    {
        $rule = new RegexParserRule(config: ['checks' => ['optimizations' => ['enabled' => true]]]);

        $errors = $this->errorsFor($rule, '/[9:;<]/', 12);

        $hasOptimization = false;
        foreach ($errors as $error) {
            if ('regex.optimization' === $error->getIdentifier()) {
                $hasOptimization = true;

                break;
            }
        }

        $this->assertFalse($hasOptimization);
    }

    public function test_report_redos_flag_skips_redos_issues(): void
    {
        $rule = new RegexParserRule(config: ['checks' => ['lint' => ['enabled' => true], 'redos' => ['enabled' => false]]]);

        $errors = $this->errorsFor($rule, '/(a+)+/', 5);

        foreach ($errors as $error) {
            $this->assertStringStartsNotWith('regex.redos', (string) $error->getIdentifier());
        }
    }

    public function test_redos_low_severity_is_reported_under_regex_redos(): void
    {
        $rule = new RegexParserRule(config: ['checks' => ['redos' => ['enabled' => true, 'threshold' => 'low']]]);

        $errors = $this->errorsFor($rule, '/(a{1,5}){1,5}/', 12);

        $this->assertSame(['regex.redos'], $this->identifiersOf($errors));
        $this->assertSame(
            'Potential ReDoS risk (theoretical) (severity: LOW, confidence: LOW): /(a{1,5}){1,5}/',
            $errors[0]->getMessage(),
        );
    }

    public function test_unsafe_optimizations_are_skipped(): void
    {
        $rule = new RegexParserRule(config: ['checks' => ['optimizations' => ['enabled' => true]]]);

        $identifiers = array_map(static fn ($error): string => $error->getIdentifier(), $this->errorsFor($rule, '/(?:a)/', 20));

        $this->assertNotContains('regex.optimization', $identifiers);
    }

    public function test_is_optimization_safe_rejects_empty_optimized_pattern(): void
    {
        $rule = new RegexParserRule();

        $this->assertFalse($rule->isOptimizationFormatSafe('/abc/', ''));
    }

    public function test_is_optimization_safe_rejects_short_pattern(): void
    {
        $rule = new RegexParserRule();

        $this->assertFalse($rule->isOptimizationFormatSafe('/a/', '/a/'));
    }

    public function test_is_optimization_safe_rejects_delimiter_only(): void
    {
        $rule = new RegexParserRule();

        $this->assertFalse($rule->isOptimizationFormatSafe('/a/', '/'));
    }

    public function test_default_optimization_config_enables_digits_optimization(): void
    {
        $rule = new RegexParserRule(config: ['checks' => ['optimizations' => ['enabled' => true]]]);

        $errors = $this->errorsFor($rule, '/[0-9]+/', 11);

        $identifiers = array_map(static fn ($error) => $error->getIdentifier(), $errors);
        $this->assertContains('regex.optimization', $identifiers);
    }

    public function test_is_optimization_format_safe_rejects_empty_delimiter(): void
    {
        $rule = new RegexParserRule();

        $this->assertFalse($rule->isOptimizationFormatSafe('/abc/', ''));
    }

    public function test_is_optimization_format_safe_rejects_delimiter_at_start_only(): void
    {
        $rule = new RegexParserRule();

        $this->assertFalse($rule->isOptimizationFormatSafe('/abc/', '/'));
    }

    public function test_is_optimization_format_safe_rejects_empty_pattern_part(): void
    {
        $rule = new RegexParserRule();

        $this->assertFalse($rule->isOptimizationFormatSafe('/abc/', '//'));
    }

    public function test_is_optimization_format_safe_rejects_short_pattern(): void
    {
        $rule = new RegexParserRule();

        $this->assertFalse($rule->isOptimizationFormatSafe('/ab/', '/a/'));
    }

    public function test_redos_critical_severity_is_reported_under_regex_redos(): void
    {
        $rule = new RegexParserRule(config: ['checks' => ['redos' => ['enabled' => true, 'threshold' => 'low']]]);

        $errors = $this->errorsFor($rule, '/(x+)+/', 12);

        // One identifier whatever the severity; the severity is in the message.
        $this->assertSame(['regex.redos'], $this->identifiersOf($errors));
        $this->assertSame(
            'Potential ReDoS risk (theoretical) (severity: CRITICAL, confidence: HIGH): /(x+)+/',
            $errors[0]->getMessage(),
        );
    }

    public function test_suggest_optimizations_uses_limit_parameter(): void
    {
        $rule = new RegexParserRule(config: ['checks' => ['optimizations' => ['enabled' => true]]]);

        // This should work with the default limit of 1
        $errors = $this->errorsFor($rule, '/[0-9]+/', 12);

        $identifiers = array_map(static fn ($error) => $error->getIdentifier(), $errors);
        $this->assertContains('regex.optimization', $identifiers);
    }

    public function test_truncate_pattern_handles_edge_cases(): void
    {
        $rule = new RegexParserRule();
        $ref = new \ReflectionClass($rule);
        $refMethod = $ref->getMethod('truncatePattern');

        // Test exactly at length limit
        $result = $refMethod->invokeArgs($rule, [str_repeat('a', 50), 50]);
        $this->assertSame(str_repeat('a', 50), $result);

        // Test over length limit
        $result = $refMethod->invokeArgs($rule, [str_repeat('a', 51), 50]);
        $this->assertSame(str_repeat('a', 50).'...', $result);

        // Test default length parameter
        $result = $refMethod->invokeArgs($rule, [str_repeat('a', 55)]);
        $this->assertSame(str_repeat('a', 50).'...', $result);
    }

    public function test_format_source_concatenates_correctly(): void
    {
        $rule = new RegexParserRule();
        $ref = new \ReflectionClass($rule);
        $refMethod = $ref->getMethod('formatSource');

        $result = $refMethod->invokeArgs($rule, ['preg_match']);

        $this->assertSame('php:preg_match()', $result);
    }

    /**
     * The errors the rule reports for preg_match($pattern, ...) on the given line.
     *
     * @return list<IdentifierRuleError>
     */
    private function errorsFor(RegexParserRule $rule, string $pattern, int $line): array
    {
        /** @var CollectedDataEmitter&NodeCallbackInvoker&Scope&Stub $scope */
        $scope = $this->createStub(Scope::class);
        $scope->method('getFile')->willReturn('file.php');
        $scope->method('getType')->willReturn(new ConstantStringType($pattern));

        $node = new FuncCall(
            new Name('preg_match'),
            [new Arg(new String_($pattern)), new Arg(new String_('subject'))],
            ['startLine' => $line],
        );

        return array_values($rule->processNode($node, $scope));
    }

    /**
     * The oracle: whether the PCRE2 running this test compiles the pattern.
     */
    private static function runningEngineCompiles(string $pattern): bool
    {
        return false !== @preg_match($pattern, '');
    }

    /**
     * @param list<IdentifierRuleError> $errors
     *
     * @return list<string>
     */
    private function identifiersOf(array $errors): array
    {
        return array_map(static fn (IdentifierRuleError $error): string => $error->getIdentifier(), $errors);
    }
}
