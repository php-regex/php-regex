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

use PHPRegex\Tests\Support\FrontMatter;
use PHPRegex\Tests\Support\PlaygroundDriver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The home is the tester, and a search result has to say so. People look
 * for "php regex tester", "php regex online" and "preg_match online": the
 * title tag, the hidden heading and the description name the page as that
 * tool, and the structured data describes it as one. The page shows no
 * text of its own beyond the tool — its heading stays hidden.
 */
#[Group('documentation')]
final class LandingPageTest extends TestCase
{
    private const HOME = PlaygroundDriver::ROOT.'/docs/index.md';
    private const SCHEMA = PlaygroundDriver::ROOT.'/docs/_includes/schema-software.html';

    #[Test]
    public function test_the_home_titles_itself_as_the_online_tester_people_look_for(): void
    {
        $front = FrontMatter::of(self::HOME);
        $title = $front['head_title'] ?? null;

        $this->assertIsString($title, 'The home names the title a search result reads.');
        $this->assertLessThanOrEqual(49, mb_strlen($title), 'The title tag stays whole with the site name.');
        $this->assertMatchesRegularExpression('/regex tester/i', $title, 'The title names the tool people look for.');
        $this->assertMatchesRegularExpression('/online/i', $title);
        $this->assertDoesNotMatchRegularExpression('/[<>`]/', $title, 'A title tag holds no markup.');

        $description = $front['description'] ?? null;
        $this->assertIsString($description);
        $this->assertMatchesRegularExpression('/^.{70,160}$/su', $description, 'The description stays whole in a result page.');
        $this->assertMatchesRegularExpression('/online/i', $description);
        $this->assertMatchesRegularExpression('/PHP and PCRE2/', $description, 'The description says what runs the pattern: the real engine.');
    }

    #[Test]
    public function test_the_hidden_heading_says_what_the_page_is_and_stays_hidden(): void
    {
        $home = (string) file_get_contents(self::HOME);

        $this->assertSame(1, preg_match('/<h1 class="visually-hidden">(?<heading>[^<]+)<\/h1>/', $home, $heading), 'The home holds one heading, hidden.');
        $this->assertMatchesRegularExpression('/regex tester/i', $heading['heading'], 'The heading names the tool.');
        $this->assertSame(0, preg_match('/<h1(?![^>]*visually-hidden)/', $home), 'No heading of the home is shown.');
    }

    #[Test]
    public function test_the_structured_data_describes_the_tool_and_stays_valid(): void
    {
        $schema = (string) file_get_contents(self::SCHEMA);

        // The one value the template engine fills is written as a version here.
        $json = (string) preg_replace('/\{\{.*?\}\}/', '2.0.0', (string) preg_replace('/\{% comment %\}.*?\{% endcomment %\}/s', '', $schema));
        $this->assertSame(1, preg_match('/<script type="application\/ld\+json">\s*(.*?)\s*<\/script>/s', $json, $block), 'The structured data is one block.');
        $data = json_decode($block[1], true);
        $this->assertIsArray($data, 'The block is JSON.');
        $this->assertSame('SoftwareApplication', $data['@type'] ?? null);
        $this->assertSame('DeveloperApplication', $data['applicationCategory'] ?? null);
        $this->assertSame('https://php-regex.com/', $data['url'] ?? null);
        $description = $data['description'] ?? null;
        $this->assertIsString($description);
        $this->assertMatchesRegularExpression('/regex tester/i', $description, 'The description names the tool.');
        $this->assertIsList($data['featureList'] ?? null);
        $features = $data['featureList'] ?? [];
        $this->assertNotEmpty($features, 'The features of the tool are listed.');
        foreach ($features as $feature) {
            $this->assertIsString($feature);
            $this->assertNotSame('', trim($feature));
        }
        $offers = $data['offers'] ?? null;
        $this->assertIsArray($offers);
        $this->assertSame('0', $offers['price'] ?? null);
    }
}
