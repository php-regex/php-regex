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
 * An article closes on a section of its own about the library, and the
 * install commands sit inside it: the include's inline form writes them
 * without the "Installation" heading that would split that section. The
 * commands are written once in the include, whichever form a page asks
 * for, so the switch to the stable release is still one edit.
 */
#[Group('documentation')]
final class ArticleInstallIncludeTest extends TestCase
{
    private const INCLUDE = __DIR__.'/../../docs/_includes/install.html';

    private const ARTICLES = __DIR__.'/../../docs/_articles';

    #[Test]
    public function test_the_inline_form_writes_the_commands_without_the_heading(): void
    {
        $include = (string) preg_replace('/\{% comment %\}.*?\{% endcomment %\}\s*/s', '', (string) file_get_contents(self::INCLUDE));

        $this->assertSame(1, substr_count($include, 'composer config minimum-stability dev'), 'The commands are written once.');
        $this->assertMatchesRegularExpression('/\{% if include\.inline %\}\s*<div class="install">.*?<\/div>\s*\{% else %\}\s*<section class="install-block">\s*<h2 id="installation">Installation<\/h2>/s', $include);
    }

    #[Test]
    public function test_every_article_includes_the_inline_form(): void
    {
        $articles = glob(self::ARTICLES.'/*.md') ?: [];
        $this->assertNotSame([], $articles);

        foreach ($articles as $article) {
            $text = (string) file_get_contents($article);
            preg_match_all('/\{% include install\.html([^%]*)%\}/', $text, $includes);

            foreach ($includes[1] as $parameters) {
                $this->assertStringContainsString(' inline=true', $parameters, basename($article));
            }
        }
    }
}
