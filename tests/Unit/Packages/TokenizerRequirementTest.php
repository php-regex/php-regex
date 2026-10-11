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

namespace PHPRegex\Tests\Unit\Packages;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TokenizerRequirementTest extends TestCase
{
    #[Test]
    public function test_tokenizer_dependent_packages_require_the_extension(): void
    {
        $manifestPaths = [
            'root' => 'composer.json',
            'linter' => 'src/Linter/composer.json',
            'language server' => 'src/LanguageServer/composer.json',
        ];

        foreach ($manifestPaths as $package => $path) {
            $manifest = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

            $this->assertSame('*', $manifest['require']['ext-tokenizer'] ?? null, $package);
        }
    }
}
