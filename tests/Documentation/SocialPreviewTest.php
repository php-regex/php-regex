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
final class SocialPreviewTest extends TestCase
{
    private const SVG = __DIR__.'/../../docs/assets/img/social.svg';

    private const PNG = __DIR__.'/../../docs/assets/img/social.png';

    #[Test]
    public function test_the_social_preview_matches_the_ast_positioning(): void
    {
        $svg = (string) file_get_contents(self::SVG);

        $this->assertStringContainsString('See a PHP regex as a tree', $svg);
        $this->assertStringContainsString('Group (named) name=&quot;date&quot;', $svg);
        $this->assertStringContainsString('Quantifier ({4}, greedy)', $svg);
        $this->assertStringContainsString('CharType (\d)', $svg);
    }

    #[Test]
    public function test_the_social_preview_keeps_its_social_card_dimensions(): void
    {
        $svg = simplexml_load_file(self::SVG);
        $this->assertNotFalse($svg);
        $this->assertSame('1280', (string) $svg['width']);
        $this->assertSame('640', (string) $svg['height']);

        $dimensions = getimagesize(self::PNG);
        $this->assertNotFalse($dimensions);
        $this->assertSame(1280, $dimensions[0]);
        $this->assertSame(640, $dimensions[1]);
    }
}
