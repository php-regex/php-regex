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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Command;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Formatter\FormatterRegistry;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Linter\Source\PatternSourceInterface;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Symfony\Command\LintCommand;
use PHPRegex\Tests\Support\JsonContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * regex:lint --format=json prints one document whatever happens: the report,
 * or the error envelope naming the stage that failed, each ending with one
 * newline, and --quiet silences neither.
 */
final class LintJsonDocumentTest extends TestCase
{
    /**
     * @param list<PatternOccurrence> $patterns
     */
    #[Test]
    #[DataProvider('provideReports')]
    public function test_json_report_is_printed_under_quiet(array $patterns): void
    {
        $tester = new CommandTester($this->createCommand($this->sourceOf($patterns)));

        $status = $tester->execute(['paths' => ['.'], '--format' => 'json', '--jobs' => '1'], ['verbosity' => OutputInterface::VERBOSITY_QUIET]);

        $this->assertSame(0, $status);
        $document = $this->decodeDocument($tester->getDisplay());
        $this->assertArrayHasKey('stats', $document);
        $this->assertArrayHasKey('results', $document);
        $this->assertCount(\count($patterns), (array) $document['results']);
    }

    /**
     * @return iterable<string, array{patterns: list<PatternOccurrence>}>
     */
    public static function provideReports(): iterable
    {
        yield 'no pattern found' => ['patterns' => []];
        yield 'a pattern found' => ['patterns' => [new PatternOccurrence('/<info>(a)+/', 'test.php', 12, 'php:preg_match()')]];
    }

    #[Test]
    public function test_json_report_keeps_console_tags_in_a_pattern(): void
    {
        $patterns = [new PatternOccurrence('/<info>(a)+/', 'test.php', 12, 'php:preg_match()')];
        $tester = new CommandTester($this->createCommand($this->sourceOf($patterns)));

        $tester->execute(['paths' => ['.'], '--format' => 'json', '--jobs' => '1']);

        $results = $this->decodeDocument($tester->getDisplay())['results'] ?? null;
        $this->assertIsArray($results);
        $this->assertIsArray($results[0] ?? null);
        $this->assertSame('/<info>(a)+/', $results[0]['pattern'] ?? null);
    }

    #[Test]
    #[DataProvider('provideFormatSpellings')]
    public function test_format_is_case_insensitive(string $format): void
    {
        $patterns = [new PatternOccurrence('/(a)+/', 'test.php', 12, 'php:preg_match()')];
        $tester = new CommandTester($this->createCommand($this->sourceOf($patterns)));

        $status = $tester->execute(['paths' => ['.'], '--format' => $format, '--jobs' => '1']);

        $this->assertSame(0, $status);
        $document = $this->decodeDocument($tester->getDisplay());
        JsonContract::assertShape('lint', $document);
        $this->assertIsArray($document['results'] ?? null);
        $this->assertCount(1, $document['results']);
    }

    /**
     * @return iterable<string, array{format: string}>
     */
    public static function provideFormatSpellings(): iterable
    {
        yield 'upper case' => ['format' => 'JSON'];
        yield 'mixed case' => ['format' => 'Json'];
    }

    #[Test]
    public function test_collection_failure_prints_the_envelope(): void
    {
        $failing = new class implements PatternSourceInterface {
            public function getName(): string
            {
                return 'fail';
            }

            public function isSupported(): bool
            {
                return true;
            }

            public function extract(PatternSourceContext $context): array
            {
                throw new \RuntimeException('boom');
            }
        };
        $tester = new CommandTester($this->createCommand($failing));

        $status = $tester->execute(['paths' => ['.'], '--format' => 'json', '--jobs' => '1'], ['verbosity' => OutputInterface::VERBOSITY_QUIET]);

        $this->assertSame(1, $status);
        $this->assertSame(['error' => 'Failed to collect patterns: boom', 'stage' => 'collect'], $this->decodeDocument($tester->getDisplay()));
    }

    #[Test]
    public function test_invalid_bundle_target_prints_the_envelope(): void
    {
        $tester = new CommandTester($this->createCommand($this->sourceOf([]), phpVersion: 'banana'));

        $status = $tester->execute(['paths' => ['.'], '--format' => 'json', '--jobs' => '1']);

        $this->assertSame(2, $status);
        $document = $this->decodeDocument($tester->getDisplay());
        $this->assertSame('config', $document['stage'] ?? null);
        $this->assertIsString($document['error'] ?? null);
    }

    #[Test]
    public function test_invalid_jobs_prints_the_envelope(): void
    {
        $tester = new CommandTester($this->createCommand($this->sourceOf([])));

        $status = $tester->execute(['paths' => ['.'], '--format' => 'json', '--jobs' => '0']);

        $this->assertSame(2, $status);
        $this->assertSame(['error' => 'The --jobs value must be a positive integer.', 'stage' => 'usage'], $this->decodeDocument($tester->getDisplay()));
    }

    /**
     * One JSON object, ending with exactly one newline.
     *
     * @return array<mixed>
     */
    private function decodeDocument(string $display): array
    {
        $this->assertStringEndsWith("}\n", $display);

        return JsonContract::decodeDocument($display);
    }

    /**
     * @param list<PatternOccurrence> $patterns
     */
    private function sourceOf(array $patterns): PatternSourceInterface
    {
        return new class($patterns) implements PatternSourceInterface {
            /**
             * @param list<PatternOccurrence> $patterns
             */
            public function __construct(private readonly array $patterns) {}

            public function getName(): string
            {
                return 'custom';
            }

            public function isSupported(): bool
            {
                return true;
            }

            public function extract(PatternSourceContext $context): array
            {
                return $this->patterns;
            }
        };
    }

    private function createCommand(PatternSourceInterface $source, ?string $phpVersion = null): LintCommand
    {
        $analysis = new AnalysisService(RegexParser::create());

        return new LintCommand(
            lint: new LintService($analysis, new PatternSourceCollection([$source])),
            analysis: $analysis,
            formatterRegistry: new FormatterRegistry(),
            phpVersion: $phpVersion,
        );
    }
}
