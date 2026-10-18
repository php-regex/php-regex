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

/**
 * The social card's URL carries a version, so that a platform that cached
 * the image fetches it again. An article's version is its own last change,
 * its update date or else its publication date: a deploy that does not
 * touch it leaves its card cached. Every other page keeps the build time.
 */
final class SocialImageVersionTest extends TestCase
{
    private const HEAD = __DIR__.'/../../docs/_includes/head.html';

    #[Test]
    public function test_an_article_versions_its_social_card_by_its_last_change(): void
    {
        $head = (string) file_get_contents(self::HEAD);

        $this->assertMatchesRegularExpression(
            "/\\{% if page\\.layout == 'article' %\\}\\s*\\{% assign head_image_version = page\\.last_modified_at \\| default: page\\.date \\| date: '%s' %\\}\\s*\\{% else %\\}\\s*\\{% assign head_image_version = site\\.time \\| date: '%s' %\\}\\s*\\{% endif %\\}/",
            $head,
        );

        preg_match_all('/<meta (?:property|name)="(og:image|twitter:image)" content="([^"]*)">/', $head, $images, \PREG_SET_ORDER);
        $this->assertCount(2, $images);
        foreach ($images as [, $name, $content]) {
            $this->assertSame("{{ '/assets/img/social.png' | absolute_url }}?v={{ head_image_version }}", $content, $name);
        }
    }
}
