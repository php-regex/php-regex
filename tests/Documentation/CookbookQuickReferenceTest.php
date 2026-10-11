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

final class CookbookQuickReferenceTest extends TestCase
{
    private const COOKBOOK = __DIR__.'/../../docs/cookbook.md';

    #[Test]
    public function test_quick_reference_uses_borderless_recipe_rows_instead_of_a_dense_table(): void
    {
        $section = self::quickReference();

        $this->assertStringContainsString('<ul class="recipe-index">', $section);
        $this->assertSame(15, substr_count($section, '<li class="recipe-entry" data-copy="">'));
        $this->assertSame(15, substr_count($section, 'class="recipe-verdict"'));
        $this->assertStringNotContainsString('<table', $section);
        $this->assertStringNotContainsString('recipe-card', $section);
    }

    #[Test]
    public function test_every_quick_reference_pattern_stays_copyable(): void
    {
        $section = self::quickReference();

        $this->assertSame(15, preg_match_all('/<li class="recipe-entry" data-copy="">/', $section));
        $this->assertSame(15, preg_match_all('/<code class="recipe-pattern">/', $section));
    }

    private static function quickReference(): string
    {
        $cookbook = (string) file_get_contents(self::COOKBOOK);
        $start = strpos($cookbook, '## Quick Reference');
        $end = strpos($cookbook, '## How to Use This Cookbook');

        if (false === $start || false === $end) {
            self::fail('The Quick Reference section is missing.');
        }

        return substr($cookbook, $start, $end - $start);
    }
}
