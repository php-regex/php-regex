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

namespace PHPRegex\Tests\Unit\Lint\Extraction;

use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An array of options gives its pattern under the key the call reads,
 * Symfony's Regex its "pattern", filter_var() its "options" => "regexp":
 * through a constant too, never "message" or "htmlPattern". A spread after
 * that key may replace it, as a later key would: then nothing is read.
 */
final class OptionPathTest extends TestCase
{
    use ExtractsWithBothStrategies;

    #[Test]
    public function test_a_constant_array_of_options_gives_its_pattern_only(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/^[a-z]+$/', 'at' => "'/^[a-z]+$/'", 'source' => '#[Regex]', 'location' => 'via self::OPTS'],
        ], <<<'PHP'
            <?php
            namespace App;

            use Symfony\Component\Validator\Constraints as Assert;
            use Symfony\Component\Validator\Constraints\Regex;

            final class Form
            {
                const OPTS = ['pattern' => '/^[a-z]+$/', 'message' => 'Only letters.', 'htmlPattern' => '[a-z]+'];
                const NO_PATTERN = ['message' => 'Nothing to match.'];

                #[Assert\Regex(self::OPTS)]
                public $name;

                public function constraints(): array
                {
                    return [new Regex(self::OPTS), new Regex(self::NO_PATTERN)];
                }
            }

            PHP, PatternFunctionRegistry::create(['symfony-validator']));
    }

    #[Test]
    public function test_a_spread_after_the_key_may_replace_its_pattern(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/spread-before/', 'at' => "'/spread-before/'"],
        ], <<<'PHP'
            <?php
            use Symfony\Component\Validator\Constraints\Regex;

            new Regex(['pattern' => '/spread-after/', ...$more]);
            new Regex([...$more, 'pattern' => '/spread-before/']);
            filter_var($v, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/spread-after-too/', ...$more]]);
            filter_var($v, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/outer-spread/'], ...$more]);

            PHP, PatternFunctionRegistry::create(['symfony-validator']));
    }
}
