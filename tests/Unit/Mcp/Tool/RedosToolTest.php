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

namespace PHPRegex\Tests\Unit\Mcp\Tool;

use PHPRegex\Mcp\ToolCatalog;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedosToolTest extends TestCase
{
    use RunsRegexCli;

    #[Test]
    public function test_the_verdict_is_the_redos_member_of_the_analyze_document(): void
    {
        $result = ToolCatalog::v1()->get('regex_redos')->tool->execute(['pattern' => '/^(a+)+$/']);

        [, $stdout] = $this->runRegex(['analyze', '/^(a+)+$/', '--json']);
        $analyze = JsonContract::decodeDocument($stdout);

        $this->assertFalse($result->isError);
        $this->assertSame(['pattern' => '/^(a+)+$/', 'redos' => $analyze['redos']], $result->structured);
        JsonContract::assertShape('redos_analysis', $result->structured['redos']);
        $redos = JsonContract::asArray($result->structured['redos']);
        $this->assertSame('critical', $redos['severity']);
        $this->assertSame('exponential', $redos['complexity']);
        $this->assertSame(['prefix' => '', 'pump' => 'a', 'suffix' => '!'], $redos['witness']);
    }

    #[Test]
    public function test_the_text_reads_the_headline_then_the_recommendations(): void
    {
        $result = ToolCatalog::v1()->get('regex_redos')->tool->execute(['pattern' => '/^(a+)+$/']);

        $this->assertStringStartsWith("Exponential backtracking (proven)\n", $result->text);
        $this->assertStringContainsString('Nested unbounded quantifiers detected', $result->text);
    }

    #[Test]
    public function test_a_safe_pattern_reads_as_linear(): void
    {
        $result = ToolCatalog::v1()->get('regex_redos')->tool->execute(['pattern' => '/^\d{4}-\d{2}$/']);

        $redos = JsonContract::asArray($result->structured['redos']);
        $this->assertSame('safe', $redos['severity']);
        $this->assertSame('linear', $redos['complexity']);
        $this->assertStringStartsWith('safe (proven)', $result->text);
    }

    #[Test]
    public function test_confirmed_mode_replays_the_witness(): void
    {
        $result = ToolCatalog::v1()->get('regex_redos')->tool->execute(['pattern' => '/^(a+)+$/', 'mode' => 'confirmed']);

        [, $stdout] = $this->runRegex(['analyze', '/^(a+)+$/', '--redos-mode=confirmed', '--json']);
        $analyze = JsonContract::decodeDocument($stdout);

        $redos = JsonContract::asArray($result->structured['redos']);
        $this->assertSame('confirmed', $redos['mode']);
        $this->assertTrue($redos['confirmed']);
        $this->assertSame('backtrack_limit', JsonContract::asArray($redos['confirmation'])['evidence']);
        // The replay times its runs: everything but the timings is the CLI's.
        $this->assertSame(self::withoutTimings(JsonContract::asArray($analyze['redos'])), self::withoutTimings($redos));
    }

    #[Test]
    public function test_the_threshold_is_read_as_the_cli_reads_it(): void
    {
        $result = ToolCatalog::v1()->get('regex_redos')->tool->execute(['pattern' => '/^(a+)+$/', 'mode' => 'confirmed', 'threshold' => 'critical']);

        $this->assertSame('confirmed', JsonContract::asArray($result->structured['redos'])['mode']);
    }

    #[Test]
    public function test_an_invalid_pattern_is_the_envelope_with_its_validation(): void
    {
        $result = ToolCatalog::v1()->get('regex_redos')->tool->execute(['pattern' => '/(a/']);

        $this->assertTrue($result->isError);
        $this->assertSame('pattern', $result->structured['stage']);
        $this->assertSame('regex.group.unclosed', JsonContract::asArray($result->structured['validation'])['error_code']);
    }

    #[Test]
    public function test_a_threshold_that_is_no_string_is_a_usage_failure(): void
    {
        $result = ToolCatalog::v1()->get('regex_redos')->tool->execute(['pattern' => '/a/', 'threshold' => ['high']]);

        $this->assertTrue($result->isError);
        $this->assertSame(['error' => 'The "threshold" argument must be low, medium, high or critical.', 'stage' => 'usage'], $result->structured);
    }

    #[Test]
    public function test_an_unknown_threshold_is_a_usage_failure_in_the_cli_words(): void
    {
        $result = ToolCatalog::v1()->get('regex_redos')->tool->execute(['pattern' => '/a/', 'threshold' => 'huge']);

        $this->assertTrue($result->isError);
        $this->assertSame('usage', $result->structured['stage']);
        $this->assertSame('Invalid value for "threshold": "huge" is not a ReDoS threshold; expected low, medium, high or critical.', $result->structured['error']);
    }

    #[Test]
    public function test_a_missing_pattern_is_a_usage_failure(): void
    {
        $result = ToolCatalog::v1()->get('regex_redos')->tool->execute(['mode' => 'confirmed']);

        $this->assertTrue($result->isError);
        $this->assertSame('usage', $result->structured['stage']);
    }

    #[Test]
    public function test_an_unknown_target_php_is_a_usage_failure(): void
    {
        $result = ToolCatalog::v1()->get('regex_redos')->tool->execute(['pattern' => '/a/', 'php_version' => ['8.2']]);

        $this->assertTrue($result->isError);
        $this->assertSame(['error' => 'Invalid option: "php_version" must be a string, array given.', 'stage' => 'usage'], $result->structured);
    }

    #[Test]
    public function test_an_unknown_mode_is_a_usage_failure(): void
    {
        $result = ToolCatalog::v1()->get('regex_redos')->tool->execute(['pattern' => '/a/', 'mode' => 'off']);

        $this->assertTrue($result->isError);
        $this->assertSame(['error' => 'The "mode" argument must be theoretical or confirmed.', 'stage' => 'usage'], $result->structured);
    }

    /**
     * @param array<mixed> $analysis
     *
     * @return array<mixed>
     */
    private static function withoutTimings(array $analysis): array
    {
        $confirmation = JsonContract::asArray($analysis['confirmation']);
        $samples = [];
        foreach (JsonContract::asArray($confirmation['samples']) as $sample) {
            $sample = JsonContract::asArray($sample);
            unset($sample['duration_ms']);
            $samples[] = $sample;
        }
        $confirmation['samples'] = $samples;
        unset($confirmation['timed_out']);
        $analysis['confirmation'] = $confirmation;

        return $analysis;
    }
}
