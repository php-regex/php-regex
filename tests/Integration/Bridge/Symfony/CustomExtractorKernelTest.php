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
use PHPRegex\Tests\Support\HelperCallExtractor;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * An extractor the application writes, named by "extractor_service", is
 * the one regex:lint reads patterns with: the calls it reports are linted,
 * the native calls it leaves out are not, and the inline markers of the
 * file its occurrences name apply to them as to the built-in extractor's.
 */
final class CustomExtractorKernelTest extends TestCase
{
    use TemporaryProject;

    protected function tearDown(): void
    {
        $this->removeTemporaryProjects();
    }

    #[Test]
    public function test_custom_extractor_occurrences_are_linted(): void
    {
        $issues = $this->lintIssues("<?php\n\ncheck('/\\d+/i', \$subject);\npreg_match('/\\d+/i', \$subject);\n");

        $this->assertSame([[3, 7, 'regex.lint.flag.useless.i']], $issues);
    }

    #[Test]
    public function test_custom_extractor_occurrence_is_silenced_by_a_marker_on_its_line(): void
    {
        $issues = $this->lintIssues("<?php\n\ncheck('/\\d+/i', \$subject); // @regex-ignore\ncheck('/[0-9]+/i', \$subject); // @regex-ignore regex.lint.flag.useless.i\n");

        $this->assertSame([], $issues);
    }

    /**
     * The markers of a file the extractor reads are the file's, whatever the
     * extractor finds: one on a call it leaves out silences nothing.
     */
    #[Test]
    public function test_marker_on_a_call_the_custom_extractor_leaves_out_is_unmatched(): void
    {
        $issues = $this->lintIssues("<?php\n\npreg_match('/x/', \$subject); // @regex-ignore\n");

        $this->assertSame([3], array_column($issues, 0));
        $this->assertSame(['regex.ignore.unmatched'], array_column($issues, 2));
    }

    /**
     * @return list<array{mixed, mixed, mixed}>
     */
    private function lintIssues(string $code): array
    {
        $project = $this->makeProject(['composer.json' => '{"name": "acme/app"}', 'src/Pattern.php' => $code]);

        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);
        $container->setParameter('kernel.cache_dir', sys_get_temp_dir());
        $container->register('app.regex_extractor', HelperCallExtractor::class);
        $extension = BundleContainer::register($container);
        $container->loadFromExtension($extension->getAlias(), ['extractor_service' => 'app.regex_extractor']);
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
                $issues[] = [$issue['line'] ?? null, $issue['column'] ?? null, $issue['issue_id'] ?? null];
            }
        }

        return $issues;
    }
}
