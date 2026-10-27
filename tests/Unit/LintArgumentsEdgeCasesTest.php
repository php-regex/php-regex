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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Linter\Config\LintArguments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LintArgumentsEdgeCasesTest extends TestCase
{
    #[Test]
    public function test_from_defaults_normalizes_min_savings_and_jobs(): void
    {
        $arguments = LintArguments::fromDefaults([
            'paths' => ['src'],
            'exclude' => [],
            'minSavings' => ['invalid'],
            'jobs' => ['invalid'],
        ]);

        $this->assertSame(1, $arguments->minSavings);
        $this->assertSame(-1, $arguments->jobs);
    }

    #[Test]
    public function test_from_defaults_reads_a_quantifier_count_written_as_digits(): void
    {
        $arguments = LintArguments::fromDefaults([
            'optimizations' => ['minQuantifierCount' => '5', 'digits' => true],
        ]);

        $this->assertSame(['minQuantifierCount' => 5, 'digits' => true], $arguments->optimizations);
    }

    #[Test]
    public function test_from_defaults_drops_a_quantifier_count_that_is_no_number(): void
    {
        // "\xB2" is a superscript two in Latin-1: no digit, whatever the locale.
        $arguments = LintArguments::fromDefaults([
            'optimizations' => ['minQuantifierCount' => "5\xB2"],
        ]);

        $this->assertSame([], $arguments->optimizations);
    }

    /**
     * @return iterable<string, array{copy: \Closure(LintArguments): LintArguments, changes: array<string, mixed>}>
     */
    public static function provideChanges(): iterable
    {
        yield 'paths' => ['copy' => static fn (LintArguments $arguments): LintArguments => $arguments->with(paths: ['lib']), 'changes' => ['paths' => ['lib']]];
        yield 'exclude' => ['copy' => static fn (LintArguments $arguments): LintArguments => $arguments->with(exclude: ['build']), 'changes' => ['exclude' => ['build']]];
        yield 'minSavings' => ['copy' => static fn (LintArguments $arguments): LintArguments => $arguments->with(minSavings: 9), 'changes' => ['minSavings' => 9]];
        yield 'verbosity' => ['copy' => static fn (LintArguments $arguments): LintArguments => $arguments->with(verbosity: 'debug'), 'changes' => ['verbosity' => 'debug']];
        yield 'format' => ['copy' => static fn (LintArguments $arguments): LintArguments => $arguments->with(format: 'json'), 'changes' => ['format' => 'json']];
        yield 'checkRedos and redosMode' => ['copy' => static fn (LintArguments $arguments): LintArguments => $arguments->with(checkRedos: false, redosMode: 'confirmed'), 'changes' => ['checkRedos' => false, 'redosMode' => 'confirmed']];
        yield 'checkValidation' => ['copy' => static fn (LintArguments $arguments): LintArguments => $arguments->with(checkValidation: true), 'changes' => ['checkValidation' => true]];
        yield 'checkOptimizations' => ['copy' => static fn (LintArguments $arguments): LintArguments => $arguments->with(checkOptimizations: true), 'changes' => ['checkOptimizations' => true]];
        yield 'checkLint' => ['copy' => static fn (LintArguments $arguments): LintArguments => $arguments->with(checkLint: true), 'changes' => ['checkLint' => true]];
        yield 'jobs' => ['copy' => static fn (LintArguments $arguments): LintArguments => $arguments->with(jobs: 8), 'changes' => ['jobs' => 8]];
        yield 'output' => ['copy' => static fn (LintArguments $arguments): LintArguments => $arguments->with(output: 'other.json'), 'changes' => ['output' => 'other.json']];
        yield 'baseline' => ['copy' => static fn (LintArguments $arguments): LintArguments => $arguments->with(baseline: 'other-baseline.json'), 'changes' => ['baseline' => 'other-baseline.json']];
        yield 'generateBaseline' => ['copy' => static fn (LintArguments $arguments): LintArguments => $arguments->with(generateBaseline: 'new-baseline.json'), 'changes' => ['generateBaseline' => 'new-baseline.json']];
        yield 'redosThreshold' => ['copy' => static fn (LintArguments $arguments): LintArguments => $arguments->with(redosThreshold: 'low'), 'changes' => ['redosThreshold' => 'low']];
        yield 'lintRules' => ['copy' => static fn (LintArguments $arguments): LintArguments => $arguments->with(lintRules: ['group.redundant' => true]), 'changes' => ['lintRules' => ['group.redundant' => true]]];
        yield 'interop' => ['copy' => static fn (LintArguments $arguments): LintArguments => $arguments->with(interop: []), 'changes' => ['interop' => []]];
        yield 'patternFunctions' => ['copy' => static fn (LintArguments $arguments): LintArguments => $arguments->with(patternFunctions: ['acme_match:0']), 'changes' => ['patternFunctions' => ['acme_match:0']]];
        yield 'reportUnmatchedIgnores' => ['copy' => static fn (LintArguments $arguments): LintArguments => $arguments->with(reportUnmatchedIgnores: true), 'changes' => ['reportUnmatchedIgnores' => true]];
        yield 'ide' => ['copy' => static fn (LintArguments $arguments): LintArguments => $arguments->with(ide: 'vscode'), 'changes' => ['ide' => 'vscode']];
        yield 'optimizations' => ['copy' => static fn (LintArguments $arguments): LintArguments => $arguments->with(optimizations: []), 'changes' => ['optimizations' => []]];
        yield 'checkComplexity and complexityThreshold' => ['copy' => static fn (LintArguments $arguments): LintArguments => $arguments->with(checkComplexity: false, complexityThreshold: 10), 'changes' => ['checkComplexity' => false, 'complexityThreshold' => 10]];
    }

    /**
     * A copy changes the properties it names and keeps every other one.
     *
     * @param \Closure(LintArguments): LintArguments $copy
     * @param array<string, mixed>                   $changes
     */
    #[Test]
    #[DataProvider('provideChanges')]
    public function test_a_copy_changes_what_it_names_and_keeps_the_rest(\Closure $copy, array $changes): void
    {
        $arguments = new LintArguments(
            paths: ['src'],
            exclude: ['vendor'],
            minSavings: 3,
            verbosity: 'quiet',
            format: 'github',
            checkRedos: true,
            checkValidation: false,
            checkOptimizations: false,
            checkLint: false,
            jobs: 4,
            output: 'report.json',
            baseline: 'baseline.json',
            generateBaseline: 'generated.json',
            ide: 'phpstorm',
            optimizations: ['digits' => true],
            redosMode: 'theoretical',
            redosThreshold: 'high',
            lintRules: ['charclass.single' => true],
            interop: ['phpunit'],
            patternFunctions: ['my_match:1'],
            checkComplexity: true,
            complexityThreshold: 70,
            reportUnmatchedIgnores: false,
        );

        $this->assertSame([...get_object_vars($arguments), ...$changes], get_object_vars($copy($arguments)));
    }
}
