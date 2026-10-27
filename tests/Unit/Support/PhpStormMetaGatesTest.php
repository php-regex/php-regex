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

use PHPRegex\Toolkit\ExplainFormat;
use PHPRegex\Toolkit\HighlightFormat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the IDE may suggest, stayed honest — read from the completion files
 * themselves, never executed: their Class::method() references are signatures
 * to the IDE, calls to PHP, and a dead reference must fail here, not in the
 * editor. The gate releases and PHP versions are the very lists the gates
 * judge, the option keys the ones the parser accepts, the highlight formats
 * the ones the toolkit answers, the severity cases the ones the enum spells.
 * Every file ships with the package its API belongs to; the gate lists stay
 * with the gates.
 */
final class PhpStormMetaGatesTest extends TestCase
{
    private const PARSER_META_FILE = __DIR__.'/../../../src/Parser/.phpstorm.meta.php';
    private const REDOS_META_FILE = __DIR__.'/../../../src/Redos/.phpstorm.meta.php';
    private const META_FILE = __DIR__.'/../../../src/Toolkit/.phpstorm.meta.php';
    private const GATES_META_FILE = __DIR__.'/../../Support/.phpstorm.meta.php';

    /**
     * Every set lives with the package that owns the concept it lists.
     */
    private const SET_OWNERS = [
        'regex_option_keys' => self::PARSER_META_FILE,
        'explanation_formats' => self::META_FILE,
        'highlight_formats' => self::META_FILE,
        'redos_thresholds' => self::REDOS_META_FILE,
    ];

    private string $gatesMeta;

    protected function setUp(): void
    {
        $this->gatesMeta = (string) \file_get_contents(self::GATES_META_FILE);
    }

    #[Test]
    public function test_the_gate_releases_the_ide_suggests_are_the_judged_ones(): void
    {
        $this->assertMatchesRegularExpression(
            '/expectedArguments\(\s*\\\\PHPRegex\\\\Tests\\\\Support\\\\PcreGates::requiresPcre2\(\),\s*0,\s*\\\\PHPRegex\\\\Tests\\\\Support\\\\PcreGateLimits::KNOWN_RELEASES\s*\)/',
            $this->gatesMeta,
        );
    }

    #[Test]
    public function test_the_php_floors_and_ceilings_the_ide_suggests_are_the_judged_ones(): void
    {
        foreach (['requiresPhp', 'forbidsPhp'] as $method) {
            $this->assertMatchesRegularExpression(
                \sprintf('/expectedArguments\(\s*\\\\PHPRegex\\\\Tests\\\\Support\\\\PcreGates::%s\(\),\s*0,\s*\\\\PHPRegex\\\\Tests\\\\Support\\\\PcreGateLimits::KNOWN_PHP_VERSIONS\s*\)/', $method),
                $this->gatesMeta,
                $method,
            );
        }
    }

    #[Test]
    public function test_the_option_keys_the_ide_suggests_are_the_ones_the_parser_reads(): void
    {
        $this->assertStringContainsString("'pcre_version'", $this->argumentsSet('regex_option_keys'));
    }

    /**
     * optimize() takes its options as a value: there are no keys to suggest.
     */
    #[Test]
    public function test_the_ide_suggests_no_option_keys_for_optimize(): void
    {
        $meta = (string) \file_get_contents(self::META_FILE);

        $this->assertStringNotContainsString('optimize_option_keys', $meta);
        $this->assertStringNotContainsString('Regex::optimize()', $meta);
    }

    #[Test]
    public function test_the_highlight_formats_the_ide_suggests_are_the_ones_the_toolkit_answers(): void
    {
        $formats = $this->argumentsSet('highlight_formats');

        $this->assertSame(self::values(HighlightFormat::cases()), self::quoted($formats));
        $this->assertStringNotContainsString("'cli'", $formats);
        $this->assertStringNotContainsString("'auto'", $formats);
    }

    #[Test]
    public function test_the_explanation_formats_the_ide_suggests_are_the_ones_the_toolkit_answers(): void
    {
        $this->assertSame(self::values(ExplainFormat::cases()), self::quoted($this->argumentsSet('explanation_formats')));
    }

    #[Test]
    public function test_the_severity_cases_the_ide_suggests_are_the_ones_the_enum_spells(): void
    {
        $thresholds = $this->argumentsSet('redos_thresholds');

        $this->assertStringContainsString('RedosSeverity::Safe', $thresholds);
        $this->assertStringNotContainsString('RedosSeverity::SAFE', $thresholds);
    }

    /**
     * @param list<\BackedEnum> $cases
     *
     * @return list<int|string>
     */
    private static function values(array $cases): array
    {
        return array_map(static fn (\BackedEnum $case): int|string => $case->value, $cases);
    }

    /**
     * @return list<string>
     */
    private static function quoted(string $set): array
    {
        \preg_match_all("/'([^']*)'/", $set, $matches);

        return $matches[1];
    }

    /**
     * The body of one registerArgumentsSet() call, by the name it registers,
     * read from the file of the package that owns the set.
     */
    private function argumentsSet(string $set): string
    {
        $source = (string) \file_get_contents(self::SET_OWNERS[$set]);

        \preg_match(\sprintf('/registerArgumentsSet\(\s*\'%s\'(.*?)\);/s', \preg_quote($set, '/')), $source, $match);

        return $match[1] ?? '';
    }
}
