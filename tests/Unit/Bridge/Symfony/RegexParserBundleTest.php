<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Bridge\Symfony;

use PhpRegex\Symfony\PhpRegexBundle;
use PHPUnit\Framework\TestCase;

final class RegexParserBundleTest extends TestCase
{
    public function test_get_path_returns_bundle_directory(): void
    {
        $bundle = new PhpRegexBundle();

        $this->assertSame(\dirname(__DIR__, 4).'/src/Symfony', $bundle->getPath());
    }
}
