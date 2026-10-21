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
use PHPRegex\Tests\Support\BundleContainer;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * regex:lint reads "@regex-target" as `regex lint` does: under
 * php_version 8.2, a marker that keeps PHP 8.4 and later only keeps no
 * version judged, is reported as regex.target.empty, and leaves the
 * pattern it covers unjudged; "(?a)", which PHP 8.2 refuses, is reported
 * elsewhere.
 */
final class LintCommandTargetMarkerTest extends TestCase
{
    use TemporaryProject;

    private const FILE = "<?php\n\npreg_match('/(?a)\\d+/', \$subject); // @regex-target php>=8.4\npreg_match('/(?a)\\d+/', \$subject);\n";

    protected function tearDown(): void
    {
        $this->removeTemporaryProjects();
    }

    #[Test]
    public function test_a_marker_narrows_the_versions_judged(): void
    {
        $this->assertSame([[3, 'regex.target.empty'], [4, 'regex.group.syntax']], $this->lintIssues(['php_version' => '8.2']));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<array{mixed, mixed}>
     */
    private function lintIssues(array $config): array
    {
        $project = $this->makeProject(['composer.json' => '{"name": "acme/app"}', 'src/Pattern.php' => self::FILE]);

        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);
        $container->setParameter('kernel.cache_dir', sys_get_temp_dir());
        $extension = BundleContainer::register($container);
        $container->loadFromExtension($extension->getAlias(), $config);
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

        $issues = [];
        foreach ($payload['results'] as $result) {
            $this->assertIsArray($result);
            foreach ((array) ($result['issues'] ?? []) as $issue) {
                $this->assertIsArray($issue);
                $issues[] = [$issue['line'] ?? null, $issue['issue_id'] ?? null];
            }
        }

        sort($issues);

        return $issues;
    }
}
