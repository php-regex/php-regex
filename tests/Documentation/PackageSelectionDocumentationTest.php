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

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
    public function test_the_package_guide_separates_prerelease_and_split_installs(): void
    {
        $this->assertFileExists(self::PAGE);

        $page = (string) file_get_contents(self::PAGE);

        $this->assertStringContainsString('## Today: one prerelease install', $page);
        $this->assertStringContainsString('composer require --dev php-regex/php-regex:{{ site.data.product.release }}-dev', $page);
        $this->assertStringContainsString('## After the 2.0 split', $page);
        foreach (['regex-parser', 'regex-toolkit', 'regex-cli', 'regex-phpstan', 'regex-language-server'] as $package) {
            $this->assertStringContainsString('`php-regex/'.$package.'`', $page);
        }
    }
}
