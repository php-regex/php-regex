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

namespace PHPRegex\Tests\Unit\Benchmark;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\Groups;
use PHPRegex\Tests\Benchmark\CaptureShapeBench;
use PHPRegex\Tests\Benchmark\LexerBench;
use PHPRegex\Tests\Benchmark\LintBench;
use PHPRegex\Tests\Benchmark\OptimizerBench;
use PHPRegex\Tests\Benchmark\ParserBench;
use PHPRegex\Tests\Benchmark\RedosCorpusBench;
use PHPRegex\Tests\Benchmark\ValidateBench;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The "table" group is what CI compares on a pull request: the corpus
 * subjects, each with its advisory assertion, and the noop floor of each
 * class the peak memory is read against.
 */
final class TableGroupTest extends TestCase
{
    private const TABLE = [
        LexerBench::class => ['benchNoop', 'benchTokenizeRealistic'],
        ParserBench::class => ['benchNoop', 'benchParseRealistic'],
        ValidateBench::class => ['benchNoop', 'benchValidateRealistic'],
        CaptureShapeBench::class => ['benchNoop', 'benchMatchShape'],
        OptimizerBench::class => ['benchNoop', 'benchOptimizeRealistic'],
        LintBench::class => ['benchNoop', 'benchLintRulesRealistic'],
        RedosCorpusBench::class => ['benchNoop', 'benchTheoreticalVerdictCorpus'],
    ];

    #[Test]
    public function test_the_table_group_holds_the_corpus_subjects_and_their_floors(): void
    {
        $expected = self::TABLE;
        ksort($expected);
        foreach ($expected as &$subjects) {
            sort($subjects);
        }

        $this->assertSame($expected, self::subjectsInGroup('table'));
    }

    #[Test]
    public function test_each_subject_of_the_table_carries_the_advisory_assertion(): void
    {
        foreach (self::TABLE as $class => $subjects) {
            foreach ($subjects as $subject) {
                $attributes = (new \ReflectionMethod($class, $subject))->getAttributes(Assert::class);
                if ('benchNoop' === $subject) {
                    $this->assertSame([], $attributes, $class.'::benchNoop is a floor, never asserted.');

                    continue;
                }

                $this->assertCount(1, $attributes, $class.'::'.$subject);
                // read as arguments: the attribute classes live with the tool, not in vendor/
                $expression = $attributes[0]->getArguments()[0] ?? '';
                $this->assertIsString($expression);
                $this->assertStringContainsString('mode(baseline.time.avg) < 50 milliseconds', (string) $expression, $class.'::'.$subject);
                $this->assertStringContainsString('mode(variant.time.avg) < mode(baseline.time.avg) +/- 30%', (string) $expression, $class.'::'.$subject);
            }
        }
    }

    /**
     * @return array<class-string, list<string>>
     */
    private static function subjectsInGroup(string $group): array
    {
        $found = [];
        foreach (glob(\dirname(__DIR__, 2).'/Benchmark/*Bench.php') ?: [] as $file) {
            /** @var class-string $class */
            $class = 'PHPRegex\\Tests\\Benchmark\\'.basename($file, '.php');
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(Groups::class) as $attribute) {
                    if (\in_array($group, (array) ($attribute->getArguments()[0] ?? []), true)) {
                        $found[$class][] = $method->getName();
                    }
                }
            }
        }
        foreach ($found as &$subjects) {
            sort($subjects);
        }

        ksort($found);

        return $found;
    }
}
