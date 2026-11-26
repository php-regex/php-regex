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
 * A page's title and description land in the attributes of the head's meta
 * tags: a double quote in them must stay text, never close the attribute.
 * Each attribute of head.html that writes one is rendered here as Liquid
 * would, with its own filter chain, for a description holding a quote, and
 * read back by an HTML parser.
 */
#[Group('documentation')]
final class HeadMetaEscapingTest extends TestCase
{
    private const HEAD = __DIR__.'/../../docs/_includes/head.html';

    private const DESCRIPTION = 'Why "\K" fails & <b>what</b> to do';

    #[Test]
    public function test_a_quote_in_a_description_or_a_title_stays_in_its_attribute(): void
    {
        $head = (string) file_get_contents(self::HEAD);
        preg_match_all('/<meta (?:name|property)="([^"]+)" content="((?:[^"{]|\{\{[^}]*\}\}|\{%[^%]*%\})*)">/', $head, $tags, \PREG_SET_ORDER);

        $checked = [];
        foreach ($tags as [$tag, $name, $content]) {
            if (!str_contains($content, 'page.description') && !str_contains($content, 'head_title')) {
                continue;
            }

            $rendered = $this->render($content);
            $document = new \DOMDocument();
            $this->assertTrue(@$document->loadHTML('<meta charset="utf-8"><meta content="'.$rendered.'">'), $name);
            $meta = $document->getElementsByTagName('meta')->item(1);
            $this->assertInstanceOf(\DOMElement::class, $meta);

            $expected = str_contains($content, 'page.description') ? 'Why "\K" fails & what to do' : 'Why "\K" fails & what to do — PHPRegex';
            $this->assertSame($expected, $meta->getAttribute('content'), $name.': '.$tag);
            $checked[] = $name;
        }

        $this->assertSame(['description', 'og:title', 'og:description', 'twitter:title', 'twitter:description'], $checked);
    }

    /**
     * A page whose heading is a label — "Psalm", "Quick Start" — names in
     * `head_title` the words a reader searches. The title tag and the cards
     * of a link print that one; a page that names none keeps its heading,
     * and the card of a shared link is described as the page shows itself.
     */
    #[Test]
    public function test_the_head_prints_the_title_a_page_names_before_its_heading(): void
    {
        $head = (string) file_get_contents(self::HEAD);

        $this->assertStringContainsString('{% assign head_title = page.head_title | default: page.title %}', $head, 'A page that names no title keeps its heading.');
        $this->assertSame(1, preg_match('/^\s*<title>(.*)<\/title>\s*$/m', $head, $title), 'The head carries one title tag.');
        $this->assertSame('{% if head_title %}{{ head_title | strip_html }} — {% endif %}PHPRegex', $title[1]);

        $titles = (string) preg_replace('/\{% capture head_image_alt %}.*?\{% endcapture %\}/s', '', $head);
        $titles = (string) preg_replace('/\{% assign head_title = [^%]*%}/', '', $titles);
        $this->assertStringNotContainsString('page.title', $titles, 'Every title outside the card alt reads the one the page names.');
    }

    /**
     * The attribute as Liquid renders it for a page whose title and
     * description are both DESCRIPTION: each output tag's filters applied
     * in order, the "if head_title" around the title taken.
     */
    private function render(string $content): string
    {
        $content = (string) preg_replace('/\{% if head_title %\}(.*?)\{% endif %\}/s', '$1', $content);
        $this->assertStringNotContainsString('{%', $content, 'A tag this test does not render.');

        return (string) preg_replace_callback('/\{\{\s*(.*?)\s*\}\}/', static function (array $output): string {
            $filters = array_map(trim(...), explode('|', $output[1]));
            $value = match (array_shift($filters)) {
                'page.description', 'head_title' => self::DESCRIPTION,
                default => self::fail('An output this test does not render: '.$output[1]),
            };

            foreach ($filters as $filter) {
                $value = match (true) {
                    str_starts_with($filter, 'default:') => $value,
                    'strip_html' === $filter => strip_tags($value),
                    'escape' === $filter => htmlspecialchars($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
                    default => self::fail('A filter this test does not render: '.$filter),
                };
            }

            return $value;
        }, $content);
    }
}
