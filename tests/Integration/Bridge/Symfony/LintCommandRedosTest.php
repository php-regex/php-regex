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
 * regex:lint with "redos.enabled: true" reports the ReDoS issues: 1.x built
 * the analysis with ReDoS on, then sent a lint request that never asked for
 * it, and the issues were dropped.
 */
final class LintCommandRedosTest extends TestCase
{
    use TemporaryProject;

    private const VULNERABLE_FILE = "<?php\n\npreg_match('/(a+)+\$/', \$subject);\n";

    /**
     * The same vulnerable body under a modifier, under a pair of braces,
     * and under another delimiter with a modifier.
     */
    private const SPELLINGS_FILE = "<?php\n\npreg_match('/(a+)+\$/', \$s);\npreg_match('/(a+)+\$/i', \$s);\npreg_match('{(a+)+\$}', \$s);\npreg_match('#(a+)+\$#u', \$s);\n";

    #[Test]
    public function test_lint_with_redos_enabled_reports_the_redos_issue(): void
    {
        $project = $this->makeProject(['composer.json' => '{"name": "acme/app"}', 'src/Pattern.php' => self::VULNERABLE_FILE]);

        $issueIds = $this->lintIssueIds($project, ['enabled' => true]);

        $this->assertContains('regex.lint.redos', $issueIds);
    }

    #[Test]
    public function test_lint_with_redos_disabled_reports_no_redos_issue(): void
    {
        $project = $this->makeProject(['composer.json' => '{"name": "acme/app"}', 'src/Pattern.php' => self::VULNERABLE_FILE]);

        $issueIds = $this->lintIssueIds($project, ['enabled' => false]);

        $this->assertNotContains('regex.lint.redos', $issueIds);
    }

    /**
     * "redos.ignored_patterns" takes a fragment of a pattern: its risk
     * analysis is skipped whatever the delimiters and the modifiers around
     * the body.
     */
    #[Test]
    public function test_lint_skips_an_ignored_fragment_under_any_delimiter_and_modifier(): void
    {
        $project = $this->makeProject(['composer.json' => '{"name": "acme/app"}', 'src/Pattern.php' => self::SPELLINGS_FILE]);

        $risky = static fn (array $ids): array => array_values(array_filter($ids, static fn (string $id): bool => \in_array($id, ['regex.lint.redos', 'regex.lint.quantifier.nested'], true)));

        $this->assertCount(8, $risky($this->lintIssueIds($project, ['enabled' => true])), 'control: each spelling is reported when not ignored');
        $this->assertSame([], $risky($this->lintIssueIds($project, ['enabled' => true, 'ignored_patterns' => ['(a+)+']])));
    }

    /**
     * @param array<string, mixed> $redos
     *
     * @return list<string>
     */
    private function lintIssueIds(string $project, array $redos): array
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);
        $container->setParameter('kernel.cache_dir', sys_get_temp_dir());
        $extension = new PHPRegexExtension();
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), ['redos' => $redos]);
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
