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

namespace PHPRegex\Tests\Integration\Bridge\Symfony;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PhpFilePatternSource;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Symfony\Command\LintCommand;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * regex:lint judges a project over the PHP range its composer.json allows,
 * as `regex lint` does: a pattern valid on PHP 8.2 and refused from 8.5 on,
 * "\K" in a lookbehind, is an error under ">=8.2", named with the versions
 * that refuse it. php_regex.php_version names one version, judged alone.
 */
final class LintCommandRangeTest extends TestCase
{
    use TemporaryProject;

    private const COMPOSER = '{"name": "acme/app", "require": {"php": ">=8.2"}}';

    /**
     * Compiles on PHP 8.4.26 (preg_match() returns 1); PHP 8.5 compiles
     * without PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK, and PCRE2 then refuses it
     * (pcre2test 10.49: error 199).
     */
    private const KEEP_IN_LOOKBEHIND = "<?php\n\npreg_match('/(?<=a\\Kb)c/', \$s);\n";

    #[Test]
    public function test_a_pattern_a_later_php_of_the_composer_range_refuses_is_an_error(): void
    {
        $project = $this->makeProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::KEEP_IN_LOOKBEHIND]);

        [$status, $document] = $this->lintJson($project, null);

        $this->assertSame(Command::FAILURE, $status);
        $issues = self::issues($document);
        $this->assertCount(1, $issues);
        $this->assertSame('error', $issues[0]['severity'] ?? null);
        $message = $issues[0]['message'] ?? null;
        $this->assertIsString($message);
        $this->assertStringContainsString('PHP 8.5 and later', (string) $message);
        $this->assertSame(['php' => '8.5', 'pcre' => '10.44'], $issues[0]['target'] ?? null);
        $range = JsonContract::asArray(JsonContract::asArray($document['target'] ?? null)['range'] ?? null);
        $this->assertSame(['php' => '8.2', 'pcre' => '10.40'], $range[0] ?? null);
    }

    #[Test]
    public function test_the_php_version_setting_judges_that_version_only(): void
    {
        $project = $this->makeProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::KEEP_IN_LOOKBEHIND]);

        [$status, $document] = $this->lintJson($project, '8.2');

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame([], self::issues($document));
        $this->assertSame([['php' => '8.2', 'pcre' => '10.40']], JsonContract::asArray($document['target'] ?? null)['range'] ?? null);
    }

    /**
     * @return array{int, array<mixed>}
     */
    private function lintJson(string $project, ?string $phpVersion): array
    {
        $analysis = new AnalysisService(RegexParser::create());
        $sources = new PatternSourceCollection([
            new PhpFilePatternSource(new PatternExtractor(new TokenBasedExtractionStrategy())),
        ]);
        $tester = new CommandTester(new LintCommand(
            lint: new LintService($analysis, $sources),
            analysis: $analysis,
            phpVersion: $phpVersion,
            projectDir: $project,
        ));

        $status = $tester->execute(
            ['paths' => [$project.'/src'], '--format' => 'json', '--no-routes' => true, '--no-validators' => true, '--jobs' => '1'],
            ['capture_stderr_separately' => true],
        );

        return [$status, JsonContract::decodeDocument($tester->getDisplay())];
    }

    /**
     * @param array<mixed> $document
     *
     * @return list<array<mixed>>
     */
    private static function issues(array $document): array
    {
        $issues = [];
        foreach (JsonContract::asArray($document['results'] ?? null) as $result) {
            foreach (JsonContract::asArray(JsonContract::asArray($result)['issues'] ?? null) as $issue) {
                $issues[] = JsonContract::asArray($issue);
            }
        }

        return $issues;
    }
}
