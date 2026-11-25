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

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeLiteralBraceRector;

use PHPRegex\Tests\Support\PcreGates;
use PHPUnit\Framework\Attributes\Test;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * Configured for PHP 8.3, which bundles PCRE2 10.42, in a project whose
 * composer.json pins a PCRE2 in config.platform.lib-pcre: every PHP of the
 * range is judged with the release the project runs, as `regex lint`
 * judges it. From PCRE2 10.43, "a{,3}" already repeats there, and escaping
 * it would change what the call matches; on 10.42, no PHP of the project
 * reads it otherwise.
 */
final class EscapeLiteralBraceRectorPinnedPcreTest extends AbstractRectorTestCase
{
    use PcreGates;

    private ?string $composer = null;

    protected function tearDown(): void
    {
        putenv('COMPOSER');
        if (null !== $this->composer) {
            @unlink($this->composer);
        }

        parent::tearDown();
    }

    #[Test]
    public function test_a_project_on_pcre2_10_43_or_later_keeps_its_brace(): void
    {
        $this->requiresPcre2('10.43', '"a{,3}" read as a quantifier');
        // The engine running the test, 10.43 or later, reads the brace as
        // the project's 10.44 does: a repeat, not the text "a{,3}".
        $this->assertSame(1, preg_match('/^a{,3}$/', 'aa'));
        $this->assertSame(0, preg_match('/^a{,3}$/', 'a{,3}'));

        $this->pinPcre2('10.44.0');

        $this->doTestFile(__DIR__.'/FixturePinnedPcre/skip_a_brace_the_project_pcre2_reads_alike_everywhere.php.inc');
    }

    #[Test]
    public function test_a_project_on_pcre2_10_42_everywhere_keeps_its_brace(): void
    {
        $this->pinPcre2('10.42');

        $this->doTestFile(__DIR__.'/FixturePinnedPcre/skip_a_brace_the_project_pcre2_reads_alike_everywhere.php.inc');
    }

    /**
     * A lib-pcre that names no release is passed over, as `regex lint`
     * passes it over: the PHP is judged with the PCRE2 it bundles.
     */
    #[Test]
    public function test_a_pin_that_names_no_release_is_passed_over(): void
    {
        $this->pinPcre2('latest');

        $this->doTestFile(__DIR__.'/Fixture/brace_without_minimum.php.inc');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__.'/config/php83.php';
    }

    private function pinPcre2(string $release): void
    {
        // The rules keep the release a file pins for the run, by its name:
        // each test names a file of its own.
        $this->composer = sys_get_temp_dir().'/regex-rector-composer-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($this->composer, json_encode(['config' => ['platform' => ['lib-pcre' => $release]]], \JSON_THROW_ON_ERROR));
        putenv('COMPOSER='.$this->composer);
    }
}
