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

use PHPRegex\Tests\Support\PlaygroundDriver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A short page that says what the site measures and what it never sees,
 * linked from every footer beside the license.
 */
#[Group('documentation')]
final class PrivacyPageTest extends TestCase
{
    private const PAGE = PlaygroundDriver::ROOT.'/docs/privacy.md';
    private const FOOTER = PlaygroundDriver::ROOT.'/docs/_includes/footer.html';

    #[Test]
    public function test_privacy_is_linked_next_to_the_license_in_the_footer(): void
    {
        $footer = (string) file_get_contents(self::FOOTER);

        $this->assertSame(1, preg_match('/<span>MIT license<\/span>\s*<span class="footer-baseline-sep" aria-hidden="true">·<\/span>\s*<a href="\/privacy\/">Privacy<\/a>/', $footer));
    }

    #[Test]
    public function test_privacy_states_no_cookie_and_path_only(): void
    {
        $this->assertFileExists(self::PAGE);
        $page = (string) file_get_contents(self::PAGE);

        $this->assertStringContainsString("permalink: /privacy/\n", $page);
        $this->assertStringContainsString('No cookie', $page);
        $this->assertStringContainsString('a cookieless request reaches Google with the page path, never what you type', $page);
        $this->assertStringContainsString('14 months', $page);
        $this->assertStringContainsString('Google Signals', $page);
        $this->assertStringContainsString('Nothing you type leaves your browser', $page, 'The chip in the playground and the page say one thing.');
        $this->assertStringNotContainsString('by name only', $page, 'The events carry details: the page says which.');
        $this->assertStringNotContainsString('one detail at most', $page);
        foreach (['trigger', 'example_name', 'finding_id', 'method', 'voice', 'php_version'] as $detail) {
            $this->assertStringContainsString('`'.$detail.'`', $page);
        }
        foreach (['run', 'example_loaded', 'finding_opened', 'share_clicked', 'voice_toggled', 'version_changed'] as $event) {
            $this->assertStringContainsString('`'.$event.'`', $page, 'Every gesture measured is named.');
        }
    }
}
