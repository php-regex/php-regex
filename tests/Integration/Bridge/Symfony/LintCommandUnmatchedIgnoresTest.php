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
 * regex:lint reports an inline marker that silences nothing as
 * regex.ignore.unmatched, as `regex lint` does, unless
 * "report_unmatched_ignores" turns the report off.
 */
final class LintCommandUnmatchedIgnoresTest extends TestCase
{
    use TemporaryProject;

    private const FILE = "<?php\n\npreg_match('/x/', \$subject); // @regex-ignore\npreg_match('/\\d+/i', \$subject); // @regex-ignore regex.lint.flag.useless.i\n";

    protected function tearDown(): void
    {
        $this->removeTemporaryProjects();
    }

    #[Test]
    public function test_a_marker_that_silences_nothing_is_reported_by_default(): void
    {
        $this->assertSame([[3, 'regex.ignore.unmatched']], $this->lintIssues([]));
    }

    #[Test]
    public function test_the_setting_turns_the_report_off(): void
    {
        $this->assertSame([], $this->lintIssues(['report_unmatched_ignores' => false]));
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
        $extension = new PHPRegexExtension();
        $container->registerExtension($extension);
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

        return $issues;
    }
}
