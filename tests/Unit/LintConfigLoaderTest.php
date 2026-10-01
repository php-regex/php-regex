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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Linter\Config\LintConfigLoader;
use PHPUnit\Framework\TestCase;

final class LintConfigLoaderTest extends TestCase
{
    public function test_config_loader_reads_repo_defaults(): void
    {
        $loader = new LintConfigLoader();

        $result = $loader->load();
        $this->assertNull($result->error);
        $this->assertIsArray($result->config);
        $this->assertIsArray($result->files);

        $configPath = getcwd().'/regex.dist.json';
        if (false !== getcwd() && file_exists($configPath)) {
            $this->assertContains($configPath, $result->files);
            $this->assertSame(['src'], $result->config['paths'] ?? []);
        }
    }
}
