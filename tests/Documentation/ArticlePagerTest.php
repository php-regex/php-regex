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

/**
 * The foot of an article steps to the older and the newer article and
 * lists a few more, among the articles the build publishes: a draft is not
 * in site.articles unless the build is run with --unpublished. With one
 * article published there is nowhere to step and nothing more to list, so
 * neither the pager nor the list is written.
 */
#[Group('documentation')]
final class ArticlePagerTest extends TestCase
{
    private const LAYOUT = __DIR__.'/../../docs/_layouts/article.html';

    #[Test]
    public function test_the_pager_steps_through_the_published_articles_by_date(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString("{% assign sorted_articles = site.articles | sort: 'date' %}", $layout);
        $this->assertStringContainsString('{% if current_index > 0 %}{% assign prev_article = sorted_articles[prev_index] %}{% endif %}', $layout);
        $this->assertStringContainsString('{% if current_index < last_index %}{% assign next_article = sorted_articles[next_index] %}{% endif %}', $layout);
    }

    #[Test]
    public function test_the_pager_is_written_only_when_there_is_an_article_to_step_to(): void
    {
        $this->assertMatchesRegularExpression(
            '/\{% if prev_article or next_article %\}\s*<nav class="article-nav"[^>]*>.*?<\/nav>\s*\{% endif %\}/s',
            $this->layout(),
        );
    }

    #[Test]
    public function test_the_more_articles_list_is_written_only_when_another_article_is_published(): void
    {
        $this->assertMatchesRegularExpression(
            '/\{% if more_articles\.size > 1 %\}\s*<section class="article-more"[^>]*>.*?<\/section>\s*\{% endif %\}/s',
            $this->layout(),
        );
    }

    private function layout(): string
    {
        return (string) file_get_contents(self::LAYOUT);
    }
}
