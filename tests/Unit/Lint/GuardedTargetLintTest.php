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

namespace PHPRegex\Tests\Unit\Lint;

use PhpParser\ParserFactory;
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PhpFilePatternSource;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The lint reads the guards on PHP_VERSION_ID around a pattern and judges
 * it for the versions of the composer.json range, "^8.2", the guard keeps:
 * 8.2 (PCRE2 10.40), 8.3 (10.42), 8.4, 8.4.25, 8.5 and 8.5.10 (10.44).
 *
 * "(?a)" needs PCRE2 10.43: 8.2 and 8.3 refuse it. "\K" in a lookbehind:
 * 8.5 refuses it. "a{,3}" is text before PCRE2 10.43 and a quantifier from
 * it. A guard the lint does not read keeps the whole range: a negation, an
 * "||", a comparison with no literal, an "&&" one side of which is no
 * guard, an "if" whose body does more than leave.
 */
final class GuardedTargetLintTest extends TestCase
{
    use TemporaryProject;

    private const CODE = <<<'PHP_WRAP'
        <?php
        namespace App;

        final class Guarded
        {
            public function run(string $s): void
            {
                if (PHP_VERSION_ID >= 80400) {
                    preg_match('/(?a)\d+/', $s);
                }
                preg_match('/(?a)\d+/', $s);
                if (\PHP_VERSION_ID < 80500) {
                    preg_match('/(?<=a\Kb)c/', $s);
                } else {
                    preg_match('/(?<=a\Kb)c/', $s);
                }
                if (80400 <= PHP_VERSION_ID && PHP_VERSION_ID < 80500) {
                    preg_match('/(?a)\d+/', $s);
                    preg_match('/(?<=a\Kb)c/', $s);
                }
                if (PHP_VERSION_ID < 80300) {
                    preg_match('/(?<=a\Kb)c/', $s);
                } elseif (PHP_VERSION_ID < 80400) {
                    preg_match('/(?a)\d+/', $s);
                } else {
                    preg_match('/(?a)\d+/', $s);
                }
                if (version_compare(PHP_VERSION, '8.4', '>=')) {
                    preg_match('/a{,3}/', $s);
                }
                preg_match('/a{,3}/', $s);
                if (!(PHP_VERSION_ID >= 80400)) {
                    preg_match('/(?<=a\Kb)c/', $s);
                }
                if (PHP_VERSION_ID >= 80400 || '' === $s) {
                    preg_match('/(?a)\d+/', $s);
                }
                if (PHP_VERSION_ID >= \strlen($s)) {
                    preg_match('/(?a)\d+/', $s);
                }
                if (PHP_VERSION_ID >= 80400 && '' === $s) {
                    preg_match('/(?a)\d+/', $s);
                }
                if (PHP_VERSION_ID >= 80300) {
                    preg_match('/(?<=a\Kb)c/', $s); // @regex-target php<8.5
                }
                if (PHP_VERSION_ID === 80400) {
                    preg_match('/(?a)\d+/', $s);
                }
                if (PHP_VERSION_ID !== 80400) {
                    preg_match('/(?a)\d+/', $s);
                }
                if (PHP_VERSION_ID >= 80400) preg_match('/(?a)\d+/', $s);
            }

            public function early(string $s): void
            {
                if (PHP_VERSION_ID < 80400) {
                    return;
                }
                preg_match('/(?a)\d+/', $s);
            }

            public function earlyThrow(string $s): void
            {
                if (PHP_VERSION_ID < 80400) throw new \LogicException('8.4');
                preg_match('/(?a)\d+/', $s);
            }

            public function notAnEarlyExit(string $s): void
            {
                if (PHP_VERSION_ID < 80400) {
                    $s = '';
                    return;
                }
                preg_match('/(?a)\d+/', $s);
            }

            public function ternary(string $s): int|false
            {
                return PHP_VERSION_ID >= 80400 ? preg_match('/(?a)\d+/', $s) : preg_match('/(?<=a\Kb)c/', $s);
            }

            public function matched(string $s): int|false
            {
                return match (true) {
                    PHP_VERSION_ID >= 80400 => preg_match('/(?a)\d+/', $s),
                    default => 0,
                };
            }
        }
        PHP_WRAP;

    /**
     * What both strategies read alike.
     */
    private const BOTH = [
        [11, 'regex.group.syntax'],
        [15, 'regex.keep.in_lookaround'],
        [24, 'regex.group.syntax'],
        [31, 'regex.lint.compat.meaningChanges'],
        [33, 'regex.keep.in_lookaround'],
        [36, 'regex.group.syntax'],
        [39, 'regex.group.syntax'],
        [42, 'regex.group.syntax'],
        [51, 'regex.group.syntax'],
        [76, 'regex.group.syntax'],
    ];

    protected function tearDown(): void
    {
        $this->removeTemporaryProjects();
    }

    /**
     * The PHP parser reads every guard form: the branches of an "if", an
     * "elseif" and an "else", an early exit, a ternary, a "match (true)"
     * arm.
     */
    #[Test]
    public function test_the_php_parser_reads_every_guard_form(): void
    {
        $this->assertSame(self::BOTH, self::issues($this->lint(PhpParserExtractionStrategy::class, self::CODE)));
    }

    /**
     * The tokenizer reads the branches and the early exits, never a ternary
     * nor a "match (true)" arm, which keep the whole range.
     */
    #[Test]
    public function test_the_tokenizer_reads_the_branches_and_the_early_exits(): void
    {
        $expected = [...self::BOTH, [81, 'regex.group.syntax'], [81, 'regex.keep.in_lookaround'], [87, 'regex.group.syntax']];
        sort($expected);

        $this->assertSame($expected, self::issues($this->lint(TokenBasedExtractionStrategy::class, self::CODE)));
    }

    /**
     * An issue judged under a guard names the version that reported it
     * and the versions the guard keeps.
     */
    #[Test]
    public function test_an_issue_under_a_guard_names_the_versions_kept(): void
    {
        $targets = [];
        foreach ($this->lint(TokenBasedExtractionStrategy::class, self::CODE)->results as $result) {
            foreach ($result['issues'] as $issue) {
                $targets[$issue['line']] = $issue['target'] ?? null;
            }
        }

        $this->assertSame(['php' => '8.5', 'pcre' => '10.44', 'guard' => '>=8.5'], $targets[15] ?? null);
        $this->assertSame(['php' => '8.3', 'pcre' => '10.42', 'guard' => '>=8.3 <8.4'], $targets[24] ?? null);
        $this->assertSame(['php' => '8.2', 'pcre' => '10.40', 'guard' => '<8.4 || >=8.4.1'], $targets[51] ?? null);
        $this->assertNull($targets[11] ?? null);
    }

    /**
     * An unqualified PHP_VERSION_ID that names a constant the file declares
     * in its namespace is no PHP version: its guard is not read, the one
     * written "\PHP_VERSION_ID" still is. In the global namespace, both are
     * read.
     */
    #[Test]
    public function test_a_constant_of_the_namespace_named_php_version_id_is_not_read(): void
    {
        $shadowed = <<<'PHP_WRAP'
            <?php
            namespace Shadow;

            const PHP_VERSION_ID = 1;
            if (PHP_VERSION_ID >= 80400) {
                preg_match('/(?a)\d+/', $s);
            }
            if (\PHP_VERSION_ID >= 80400) {
                preg_match('/(?a)\d+/', $s);
            }
            PHP_WRAP;
        $global = "<?php\nif (PHP_VERSION_ID >= 80400) {\n    preg_match('/(?a)\\d+/', \$s);\n}\n";

        foreach ([PhpParserExtractionStrategy::class, TokenBasedExtractionStrategy::class] as $strategy) {
            $this->assertSame([[6, 'regex.group.syntax']], self::issues($this->lint($strategy, $shadowed)), $strategy);
            $this->assertSame([], self::issues($this->lint($strategy, $global)), $strategy);
        }
    }

    /**
     * @param class-string<ExtractorInterface> $strategy
     */
    private function lint(string $strategy, string $code): LintReport
    {
        if (PhpParserExtractionStrategy::class === $strategy && !class_exists(ParserFactory::class)) {
            $strategy = TokenBasedExtractionStrategy::class;
        }

        $project = $this->makeProject(['composer.json' => '{"require": {"php": "^8.2"}}', 'src/a.php' => $code]);
        $target = ProjectTarget::resolve(null, null, [], $project, []);
        $parser = RegexParser::create($target->regexOptions() + ['runtime_pcre_validation' => false]);
        $service = new LintService(
            new AnalysisService($parser, range: $target->rangeParsers($parser), judged: $target->judgedRange($parser)),
            new PatternSourceCollection([new PhpFilePatternSource(new PatternExtractor(new $strategy()))]),
        );
        $request = new LintRequest([$project.'/src'], [], 1, checkOptimizations: false, analysisWorkers: 1);

        return $service->analyze($service->collectPatterns($request), $request);
    }

    /**
     * The line and identifier of each issue, sorted.
     *
     * @return list<array{int, string|null}>
     */
    private static function issues(LintReport $report): array
    {
        $issues = [];
        foreach ($report->results as $result) {
            foreach ($result['issues'] as $issue) {
                $issues[] = [$issue['line'], $issue['issueId'] ?? null];
            }
        }
        sort($issues);

        return $issues;
    }
}
