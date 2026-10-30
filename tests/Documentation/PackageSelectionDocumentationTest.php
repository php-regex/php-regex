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

namespace PHPRegex\Tests\Documentation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('documentation')]
final class PackageSelectionDocumentationTest extends TestCase
{
    private const PAGE = __DIR__.'/../../docs/packages.md';

    #[Test]
    public function test_the_package_guide_is_navigable_from_onboarding(): void
    {
        $navigation = (string) file_get_contents(__DIR__.'/../../docs/_data/nav.yml');
        $quickStart = (string) file_get_contents(__DIR__.'/../../docs/quick-start.md');

        $this->assertStringContainsString('title: Choose a package', $navigation);
        $this->assertStringContainsString('url: /packages/', $navigation);
        $this->assertStringContainsString('[choose the right package](/packages/)', $quickStart);
    }

    #[Test]
    public function test_onboarding_installs_the_focused_cli_package(): void
    {
        $home = (string) file_get_contents(__DIR__.'/../../docs/index.md');
        $quickStart = self::rendered((string) file_get_contents(__DIR__.'/../../docs/quick-start.md'));
        $readme = (string) file_get_contents(__DIR__.'/../../README.md');

        $command = 'composer require --dev php-regex/regex-cli:{{ site.data.product.release }}-dev';

        $this->assertStringContainsString('composer config minimum-stability dev', $home);
        $this->assertStringContainsString('composer config prefer-stable true', $home);
        $this->assertStringContainsString($command, $home);
        $this->assertStringContainsString('composer config minimum-stability dev', $quickStart);
        $this->assertStringContainsString($command, $quickStart);
        $this->assertStringContainsString('composer config minimum-stability dev', $readme);
        $this->assertStringContainsString('composer require --dev php-regex/regex-cli:2.x-dev', $readme);
        $this->assertStringNotContainsString('composer require --dev php-regex/php-regex:2.x-dev', $readme);
    }

    #[Test]
    public function test_each_page_installs_its_own_focused_package(): void
    {
        $include = (string) file_get_contents(__DIR__.'/../../docs/_includes/install-prerelease.html');

        $this->assertStringContainsString('{{ include.package }}:{{ site.data.product.release }}-dev', $include);
        $this->assertStringNotContainsString('php-regex/php-regex:{{ site.data.product.release }}-dev', $include);
        $this->assertStringNotContainsString('unused until the 2.0.0 switch', $include);
    }

    #[Test]
    public function test_a_package_that_runs_in_production_installs_without_dev(): void
    {
        $include = (string) file_get_contents(__DIR__.'/../../docs/_includes/install-prerelease.html');
        $this->assertStringContainsString('composer require {% if include.dev %}--dev {% endif %}{{ include.package }}', $include);

        $runtime = ['guides/laravel.md', 'guides/symfony.md', 'reference/api.md', 'reference/logic-solver.md', 'cookbook.md', 'guides/regex-in-php.md'];
        $tools = ['guides/cli.md', 'guides/phpstan.md', 'guides/psalm.md', 'guides/rector.md', 'guides/lsp.md', 'concepts/redos.md'];
        foreach ([...$runtime, ...$tools] as $page) {
            $text = (string) file_get_contents(__DIR__.'/../../docs/'.$page);
            $this->assertSame(1, preg_match('~\{% include install-prerelease\.html package="[^"]+"( dev=true)? %\}~', $text, $match), $page);
            $this->assertSame(\in_array($page, $tools, true), isset($match[1]), $page.': a development tool passes dev=true, a runtime package does not.');
        }
    }

    /**
     * The Regex service is autowired into application code, so a bundle
     * registered for dev and test only leaves prod without it, and its
     * config/packages/php_regex.yaml without an extension to load it.
     */
    #[Test]
    public function test_the_symfony_bundle_is_registered_for_every_environment(): void
    {
        foreach (['docs/guides/symfony.md', 'docs/troubleshooting.md', 'src/Symfony/README.md'] as $page) {
            $text = (string) file_get_contents(__DIR__.'/../../'.$page);

            $this->assertStringContainsString("PHPRegex\\Symfony\\PHPRegexSymfonyBundle::class => ['all' => true],", $text, $page);
            $this->assertStringNotContainsString("PHPRegexSymfonyBundle::class => ['dev' => true", $text, $page);
        }

        foreach (['docs/guides/symfony.md', 'src/Symfony/README.md'] as $page) {
            $text = (string) file_get_contents(__DIR__.'/../../'.$page);

            $this->assertStringContainsString('when@dev:', $text, $page.': a dev-only registration moves the configuration under when@dev.');
        }
    }

    #[Test]
    public function test_the_readme_points_at_the_package_guide(): void
    {
        $readme = (string) file_get_contents(__DIR__.'/../../README.md');

        $this->assertStringContainsString('https://php-regex.com/packages/', $readme);
    }

    #[Test]
    public function test_the_package_guide_marks_the_monorepo_as_not_the_default(): void
    {
        $this->assertFileExists(self::PAGE);

        $page = (string) file_get_contents(self::PAGE);

        $this->assertStringContainsString('## Install the surface you need', $page);
        $this->assertStringContainsString('The focused packages are published now as `{{ site.data.product.release }}-dev`', $page);
        $this->assertStringContainsString('`php-regex/php-regex` is the development monorepo', $page);
        foreach (['regex-parser', 'regex-toolkit', 'regex-cli', 'regex-phpstan', 'regex-language-server'] as $package) {
            $this->assertStringContainsString('`php-regex/'.$package.'`', $page);
        }
    }

    /**
     * The page with each install include written out as the site renders
     * it, for the package and the dev flag the page passes.
     */
    private static function rendered(string $page): string
    {
        $include = (string) file_get_contents(__DIR__.'/../../docs/_includes/install-prerelease.html');
        $include = (string) preg_replace('/\{%-? comment -?%\}.*?\{%-? endcomment -?%\}\s*/s', '', $include);

        return (string) preg_replace_callback(
            '/\{%-? include install-prerelease\.html((?:\s+\w+=(?:"[^"]*"|\w+))*)\s*-?%\}/',
            static function (array $match) use ($include): string {
                preg_match_all('/(\w+)=(?:"([^"]*)"|(\w+))/', $match[1], $parameters, \PREG_SET_ORDER);
                $values = [];
                foreach ($parameters as $parameter) {
                    $values[$parameter[1]] = '' !== ($parameter[2] ?? '') ? $parameter[2] : ($parameter[3] ?? '');
                }

                $dev = 'true' === ($values['dev'] ?? '');
                $text = (string) preg_replace('/\{% if include\.dev %\}(.*?)\{% endif %\}/s', $dev ? '$1' : '', $include);

                return str_replace('{{ include.package }}', $values['package'] ?? '', $text);
            },
            $page,
        );
    }
}
