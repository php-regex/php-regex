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

use PHPRegex\Symfony\Command\LintCommand;
use PHPRegex\Symfony\DependencyInjection\PHPRegexExtension;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * regex:lint reports a pattern at or above "analysis.warning_threshold" once
 * "analysis.complexity" turns the report on, as `regex lint` does under
 * "checks.complexity"; off, the threshold alone reports nothing.
 */
final class LintCommandComplexityTest extends TestCase
{
    use TemporaryProject;

    // Complexity score 15.
    private const COMPLEX_FILE = "<?php\n\npreg_match('/(a|b)+c/', \$subject);\n";

    #[Test]
    public function test_lint_with_complexity_on_reports_a_pattern_at_the_threshold(): void
    {
        $issueIds = $this->lintIssueIds(['complexity' => true, 'warning_threshold' => 15]);

        $this->assertContains('regex.lint.complexity', $issueIds);
    }

    #[Test]
    public function test_lint_with_complexity_on_spares_a_pattern_below_the_threshold(): void
    {
        $issueIds = $this->lintIssueIds(['complexity' => true, 'warning_threshold' => 16]);

        $this->assertNotContains('regex.lint.complexity', $issueIds);
    }

    #[Test]
    public function test_lint_with_complexity_off_reports_nothing_whatever_the_threshold(): void
    {
        $this->assertNotContains('regex.lint.complexity', $this->lintIssueIds(['warning_threshold' => 1]));
        $this->assertNotContains('regex.lint.complexity', $this->lintIssueIds(['complexity' => false, 'warning_threshold' => 1]));
    }

    /**
     * @param array<string, mixed> $analysis
     *
     * @return list<string>
     */
    private function lintIssueIds(array $analysis): array
    {
        $project = $this->makeProject(['composer.json' => '{"name": "acme/app"}', 'src/Pattern.php' => self::COMPLEX_FILE]);

        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);
        $container->setParameter('kernel.cache_dir', sys_get_temp_dir());
        $extension = new PHPRegexExtension();
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), ['analysis' => $analysis]);
        $container->compile();

        $command = $container->get('php_regex.command.lint');
        $this->assertInstanceOf(LintCommand::class, $command);

        $tester = new CommandTester($command);
        $tester->execute(
            ['paths' => [$project.'/src'], '--format' => 'json', '--no-routes' => true, '--no-validators' => true, '--jobs' => '1'],
            ['capture_stderr_separately' => true],
        );

        $payload = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($payload, 'stdout is not JSON: '.$tester->getDisplay());
        $this->assertIsArray($payload['results'] ?? null);

        $ids = [];
        foreach ($payload['results'] as $result) {
            $this->assertIsArray($result);
            foreach ((array) ($result['issues'] ?? []) as $issue) {
                $this->assertIsArray($issue);
                $ids[] = \is_string($issue['issue_id'] ?? null) ? $issue['issue_id'] : '';
            }
        }

        return $ids;
    }
}
