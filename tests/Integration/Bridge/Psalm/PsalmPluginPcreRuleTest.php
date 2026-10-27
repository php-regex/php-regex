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

namespace PHPRegex\Tests\Integration\Bridge\Psalm;

use PHPRegex\Tests\Support\PcreGates;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The PCRE2 the plugin judges with follows the rule of every entry point:
 * <pcreVersion>, else composer.json's config.platform.lib-pcre at Psalm's
 * base directory, as is; else the running PCRE2 for the running PHP's
 * minor; else the one that PHP bundles.
 *
 * "(?aD)" arrived in PCRE2 10.43 and "(*scs:" in 10.45: preg_match()
 * compiles both on PHP 8.4.27 with PCRE2 10.49.
 */
final class PsalmPluginPcreRuleTest extends TestCase
{
    use PcreGates;

    private const ASCII = "'/(?aD)x/'";

    private const SCAN = "'/(a)(*scs:(1)a)/'";

    /**
     * The running engine stands for its minor: what it compiles is not
     * reported, though the release that minor bundles may refuse it.
     */
    #[Test]
    public function test_the_running_minor_is_judged_with_the_running_pcre2(): void
    {
        $this->requiresPcre2('10.45', self::SCAN);
        $this->forbidsPhp('8.5', 'Psalm analysing for the running minor', 'is a version Psalm refuses to analyse for');
        $minor = \sprintf('%d.%d', intdiv(\PHP_VERSION_ID, 10000), intdiv(\PHP_VERSION_ID, 100) % 100);

        $this->assertSame([], $this->reported('RunningMinor', $minor, []));
    }

    #[Test]
    public function test_composer_json_lib_pcre_pins_the_pcre2_and_the_option_wins(): void
    {
        $this->requiresPcre2('10.45', self::SCAN);

        $this->assertSame([
            self::ASCII => 'Regex pattern is invalid for PHP 8.4 with PCRE2 10.40',
            self::SCAN => 'Regex pattern is invalid for PHP 8.4 with PCRE2 10.40',
        ], $this->reported('LibPcre', '8.4', []));
        $this->assertSame([self::SCAN => 'Regex pattern is invalid for PHP 8.4 with PCRE2 10.43'], $this->reported('LibPcre', '8.4', ['pcreVersion' => '10.43']));
    }

    /**
     * @param array<string, string> $options
     *
     * @return array<string, string> the patterns reported, each with the start of its message
     */
    private function reported(string $directory, string $phpVersion, array $options): array
    {
        $baseDir = PsalmRun::FIXTURES.'/'.$directory;
        $found = [];
        foreach (PsalmRun::inFile(PsalmRun::issues(['patterns.php'], $phpVersion, $options, baseDir: $baseDir), 'patterns.php', $baseDir) as $issue) {
            // Read as data: the issue's class is internal to Psalm.
            ['type' => $type, 'selected_text' => $text, 'message' => $message] = get_object_vars($issue) + ['type' => null, 'selected_text' => null, 'message' => null];
            if ('InvalidRegexPattern' === $type && \is_string($text) && \is_string($message)) {
                $found[$text] = (string) strstr($message, ':', true);
            }
        }
        ksort($found);

        return $found;
    }
}
