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
 * An article prints its title once, and tells a search engine what it is.
 * The source of an article opens on its heading, as every page of the site
 * does: the layout prints that title above the byline, and the body under
 * it starts after the heading of the source. The structured data of the
 * article is JSON: a date in it is a string in quotes.
 */
#[Group('documentation')]
final class ArticleMarkupTest extends TestCase
{
    private const LAYOUT = __DIR__.'/../../docs/_layouts/article.html';

    private const SCHEMA = __DIR__.'/../../docs/_includes/article-schema.html';

    #[Test]
    public function test_the_title_of_an_article_is_printed_once(): void
    {
        $layout = (string) file_get_contents(self::LAYOUT);

        $this->assertStringContainsString('<h1 class="article-title">{{ page.title }}</h1>', $layout, 'The layout prints the title above the byline.');
        $this->assertStringContainsString("{% assign article_parts = content | split: '</h1>' %}", $layout, 'The heading of the source ends at the first closing tag of a title.');
        $this->assertStringContainsString('{% assign article_rest = article_parts | slice: 1, article_parts.size %}', $layout);
        $this->assertStringContainsString("{% assign article_body = article_rest | join: '</h1>' %}", $layout);
        $this->assertStringContainsString('<div class="article-content">{{ article_body }}</div>', $layout, 'The body of the page is the source after its heading.');
        $this->assertStringNotContainsString('<div class="article-content">{{ content }}</div>', $layout, 'The heading of the source is not printed a second time.');
    }

    #[Test]
    public function test_an_article_whose_source_opens_on_no_heading_keeps_its_text(): void
    {
        $layout = (string) file_get_contents(self::LAYOUT);

        $this->assertMatchesRegularExpression(
            '/\{% if article_parts\.size > 1 %\}.*?\{% else %\}\{% assign article_body = content %\}\{% endif %\}/s',
            $layout,
            'A source without a closing title tag is printed whole.',
        );
    }

    #[Test]
    public function test_the_dates_of_the_structured_data_are_strings(): void
    {
        $schema = (string) file_get_contents(self::SCHEMA);

        $this->assertStringContainsString('"datePublished": {{ page.date | date_to_xmlschema | jsonify }},', $schema);
        $this->assertStringContainsString('"dateModified": {{ page.last_modified_at | default: page.date | date_to_xmlschema | jsonify }},', $schema);
        $this->assertStringContainsString('"mainEntityOfPage": {{ page.url | absolute_url | jsonify }}', $schema);
        $this->assertStringContainsString('"headline": {{ page.title | strip_html | jsonify }}', $schema);
    }

    /**
     * While no article is published, the index of the articles says only
     * that the first ones are coming. A search engine is asked to keep it
     * out of its index until there is an article there to read.
     */
    #[Test]
    public function test_the_index_of_the_articles_is_kept_out_of_the_search_indexes_while_no_article_is_published(): void
    {
        $head = (string) file_get_contents(\dirname(self::LAYOUT, 2).'/_includes/head.html');

        $this->assertStringContainsString("{% assign published_articles = site.articles | where_exp: 'a', 'a.published != false' %}", $head, 'The articles that count are the published ones.');
        $this->assertStringContainsString("{% if page.url == '/blog/' and published_articles.size == 0 %}<meta name=\"robots\" content=\"noindex\">{% endif %}", $head, 'Only the empty index is kept out.');
    }
}
