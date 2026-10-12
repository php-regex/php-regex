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

namespace PHPRegex\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the IDE may suggest, stayed honest — read from the completion file
 * itself, never executed: its Class::method() references are signatures to
 * the IDE, calls to PHP, and a dead reference must fail here, not in the
 * editor. The gate releases and PHP versions are the very lists the gates
 * judge, the option keys the ones the parser accepts, the highlight formats
 * the ones the toolkit answers, the severity cases the ones the enum spells.
 */
final class PhpStormMetaGatesTest extends TestCase
{
    private const META_FILE = __DIR__.'/../../../.phpstorm.meta.php';

    private string $meta;

    protected function setUp(): void
    {
        $this->meta = (string) \file_get_contents(self::META_FILE);
    }

    #[Test]
    public function test_the_gate_releases_the_ide_suggests_are_the_judged_ones(): void
    {
        $this->assertMatchesRegularExpression(
            '/expectedArguments\(\s*\\\\PHPRegex\\\\Tests\\\\Support\\\\PcreGates::requiresPcre2\(\),\s*0,\s*\\\\PHPRegex\\\\Tests\\\\Support\\\\PcreGateLimits::KNOWN_RELEASES\s*\)/',
            $this->meta,
        );
    }

    #[Test]
    public function test_the_php_floors_and_ceilings_the_ide_suggests_are_the_judged_ones(): void
    {
        foreach (['requiresPhp', 'forbidsPhp'] as $method) {
            $this->assertMatchesRegularExpression(
                \sprintf('/expectedArguments\(\s*\\\\PHPRegex\\\\Tests\\\\Support\\\\PcreGates::%s\(\),\s*0,\s*\\\\PHPRegex\\\\Tests\\\\Support\\\\PcreGateLimits::KNOWN_PHP_VERSIONS\s*\)/', $method),
                $this->meta,
                $method,
            );
        }
    }

    #[Test]
    public function test_the_option_keys_the_ide_suggests_are_the_ones_the_parser_reads(): void
    {
        $this->assertStringContainsString("'pcre_version'", $this->argumentsSet('regex_option_keys'));
    }

    #[Test]
    public function test_the_highlight_formats_the_ide_suggests_are_the_ones_the_toolkit_answers(): void
    {
        $formats = $this->argumentsSet('highlight_formats');

        $this->assertStringContainsString("'cli'", $formats);
        $this->assertStringContainsString("'auto'", $formats);
        $this->assertStringNotContainsString("'console'", $formats);
    }

    #[Test]
    public function test_the_severity_cases_the_ide_suggests_are_the_ones_the_enum_spells(): void
    {
        $thresholds = $this->argumentsSet('redos_thresholds');

        $this->assertStringContainsString('RedosSeverity::Safe', $thresholds);
        $this->assertStringNotContainsString('RedosSeverity::SAFE', $thresholds);
    }

    /**
     * The body of one registerArgumentsSet() call, by the name it registers.
     */
    private function argumentsSet(string $set): string
    {
        \preg_match(\sprintf('/registerArgumentsSet\(\s*\'%s\'(.*?)\);/s', \preg_quote($set, '/')), $this->meta, $match);

        return $match[1] ?? '';
    }
}
