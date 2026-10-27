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
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Symfony\Command\LintCommand;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The PCRE2 the bundle's lint command judges with follows the rule of
 * every entry point: php_regex.pcre_version, else the project's
 * composer.json config.platform.lib-pcre, as is; else the running PCRE2 for
 * the running PHP's minor; else the one that PHP bundles.
 */
final class LintCommandPcreRuleTest extends TestCase
{
    use TemporaryProject;

    protected function tearDown(): void
    {
        $this->removeTemporaryProjects();
    }

    #[Test]
    public function test_the_running_minor_gets_the_running_pcre2(): void
    {
        $project = $this->makeProject();

        $this->assertSame(PcreTarget::runtime()->pcreVersion, $this->targetPcre($project, phpVersion: \sprintf('%d.%d', intdiv(\PHP_VERSION_ID, 10000), intdiv(\PHP_VERSION_ID, 100) % 100)));
    }

    #[Test]
    public function test_the_project_s_lib_pcre_pins_the_pcre2_and_the_setting_wins(): void
    {
        $project = $this->makeProject(['composer.json' => '{"config": {"platform": {"php": "8.2", "lib-pcre": "10.43"}}}']);

        $this->assertSame('10.43', $this->targetPcre($project));
        $this->assertSame('10.39', $this->targetPcre($project, pcreVersion: '10.39'));
    }

    private function targetPcre(string $project, ?string $phpVersion = null, ?string $pcreVersion = null): mixed
    {
        $analysis = new AnalysisService(RegexParser::create());
        $command = new LintCommand(
            lint: new LintService($analysis, new PatternSourceCollection([])),
            analysis: $analysis,
            phpVersion: $phpVersion,
            pcreVersion: $pcreVersion,
            projectDir: $project,
        );

        $tester = new CommandTester($command);
        $tester->execute(['paths' => [$project], '--format' => 'json']);
        $data = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($data, $tester->getDisplay());
        $this->assertIsArray($data['target'] ?? null);

        return $data['target']['pcre'] ?? null;
    }
}
