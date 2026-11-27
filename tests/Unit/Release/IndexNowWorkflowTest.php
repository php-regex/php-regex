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

namespace PHPRegex\Tests\Unit\Release;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * IndexNow is how a site tells Bing, DuckDuckGo, Yahoo and the other
 * engines of the protocol that its pages changed, without waiting for
 * their crawlers. The proof the site owns the key is a file that holds
 * the key, served at the root under the key's name. The run waits for the
 * pages the push changed to be served, then sends every page of the
 * sitemap: the engines fetch what they do not know yet.
 */
final class IndexNowWorkflowTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';
    private const WORKFLOW = self::ROOT.'/.github/workflows/indexnow.yml';

    #[Test]
    public function test_the_key_the_site_serves_is_the_one_the_run_sends(): void
    {
        $keys = glob(self::ROOT.'/docs/*.txt') ?: [];
        $keys = array_values(array_filter($keys, static fn (string $file): bool => 1 === preg_match('~/[a-f0-9]{32}\.txt$~', $file)));
        $this->assertCount(1, $keys, 'One key file stands at the root of the site.');
        $key = basename($keys[0], '.txt');
        $this->assertSame($key, trim((string) file_get_contents($keys[0])), 'The file holds its own name: the key, and nothing else.');

        $workflow = (string) file_get_contents(self::WORKFLOW);
        // The run finds the file by the shape of its name, so that a new key is a new file and nothing else.
        $this->assertStringContainsString("grep -E '/[a-f0-9]{32}\.txt$'", $workflow, 'The run reads the key from the file the site serves.');
        $this->assertStringContainsString('https://api.indexnow.org/indexnow', $workflow);
        $this->assertStringContainsString('https://php-regex.com/sitemap.xml', $workflow, 'Every page of the sitemap is sent.');
        $this->assertStringContainsString('php-regex.com', $workflow);
    }

    #[Test]
    public function test_the_run_starts_with_a_change_to_the_documentation_and_waits_for_it_to_be_served(): void
    {
        $workflow = (string) file_get_contents(self::WORKFLOW);

        $this->assertMatchesRegularExpression('/^  push:\n    branches: \[ 2\.x \]\n    paths:\n      - \'docs\/\*\*\'$/m', $workflow, 'A change to the documentation starts the run.');
        $this->assertMatchesRegularExpression('/^  workflow_dispatch:$/m', $workflow, 'A hand can start it too.');
        $this->assertStringContainsString('main\.css\?v=[0-9]+', $workflow, 'The build time the pages carry tells which documentation the site serves.');
        $this->assertStringContainsString('the site still serves the documentation of before the push', $workflow, 'A site that never serves the change fails the run instead of announcing it.');
        $this->assertMatchesRegularExpression('/^    timeout-minutes: \d+$/m', $workflow);
        $this->assertStringContainsString("permissions:\n  contents: read\n", $workflow, 'The run reads the repository and nothing else.');
        $this->assertStringContainsString('persist-credentials: false', $workflow);
    }
}
