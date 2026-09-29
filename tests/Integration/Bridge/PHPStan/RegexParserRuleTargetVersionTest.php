<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Integration\Bridge\PHPStan;

use PHPStan\Php\PhpVersion;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use RegexParser\Bridge\PHPStan\RegexParserRule;

/**
 * The rule judges patterns for the PHP version PHPStan analyses the project
 * for, not for the PHP that runs PHPStan.
 *
 * @extends RuleTestCase<RegexParserRule>
 */
final class RegexParserRuleTargetVersionTest extends RuleTestCase
{
    private ?PhpVersion $phpVersion = null;

    /**
     * @var array<string, mixed>
     */
    private array $config = [];

    #[Test]
    public function test_a_project_on_php_8_2_is_judged_with_the_pcre2_php_8_2_bundles(): void
    {
        $this->phpVersion = new PhpVersion(80200);

        $this->analyse([__DIR__.'/Fixtures/TargetVersionFixture.php'], [
            [
                'Regex syntax error: Invalid group modifier syntax at position 2 (Pattern: "/(?aD)x/")',
                22,
            ],
        ]);
    }

    #[Test]
    public function test_a_project_on_php_8_4_accepts_what_pcre2_10_44_reads(): void
    {
        $this->phpVersion = new PhpVersion(80400);

        $this->analyse([__DIR__.'/Fixtures/TargetVersionFixture.php'], []);
    }

    #[Test]
    public function test_a_pcre_version_names_the_pcre2_a_distribution_links(): void
    {
        $this->phpVersion = new PhpVersion(80400);
        $this->config = ['pcreVersion' => '10.42'];

        $this->analyse([__DIR__.'/Fixtures/TargetVersionFixture.php'], [
            [
                'Regex syntax error: Invalid group modifier syntax at position 2 (Pattern: "/(?aD)x/")',
                22,
            ],
        ]);
    }

    #[Test]
    public function test_a_php_version_parameter_names_the_version(): void
    {
        $this->phpVersion = new PhpVersion(80400);
        $this->config = ['phpVersion' => '8.2'];

        $this->analyse([__DIR__.'/Fixtures/TargetVersionFixture.php'], [
            [
                'Regex syntax error: Invalid group modifier syntax at position 2 (Pattern: "/(?aD)x/")',
                22,
            ],
        ]);
    }

    #[Test]
    public function test_runtime_judges_for_the_php_running_the_analysis(): void
    {
        $this->phpVersion = new PhpVersion(80200);
        $this->config = ['phpVersion' => 'runtime'];

        $this->analyse(
            [__DIR__.'/Fixtures/TargetVersionFixture.php'],
            false !== @preg_match('/(?aD)x/', '') ? [] : [
                ['Regex syntax error: Invalid group modifier syntax at position 2 (Pattern: "/(?aD)x/")', 22],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new RegexParserRule(
            ignoreParseErrors: false,
            reportRedos: false,
            config: $this->config,
            phpVersion: $this->phpVersion,
        );
    }
}
