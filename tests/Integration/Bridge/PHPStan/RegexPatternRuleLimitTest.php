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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan;

use PHPRegex\PHPStan\RegexPatternRule;
use PHPStan\Php\PhpVersion;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A pattern past one of the library's own limits is not analyzed: the rule
 * says nothing about it, and never calls a pattern PCRE compiles invalid.
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexPatternRuleLimitTest extends RuleTestCase
{
    private string $fixture = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = sys_get_temp_dir().'/regex-phpstan-limit-'.bin2hex(random_bytes(6)).'.php';
        file_put_contents($this->fixture, "<?php\n\npreg_match('".self::longPattern()."', 'a');\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->fixture);
    }

    #[Test]
    public function test_a_pattern_past_the_length_limit_is_not_reported_invalid(): void
    {
        $this->assertSame(1, preg_match(self::longPattern(), 'a'), 'control: PCRE compiles the pattern');

        $this->analyse([$this->fixture], []);
    }

    protected function getRule(): Rule
    {
        // A target other than the running engine: the rule judges every pattern.
        return new RegexPatternRule(config: ['phpVersion' => '8.2'], phpVersion: new PhpVersion(80400));
    }

    /**
     * Past the default "max_pattern_length" of 100,000 bytes, yet compiled
     * small: the comment adds no code.
     */
    private static function longPattern(): string
    {
        return '/a(?#'.str_repeat('x', 100_000).')/';
    }
}
