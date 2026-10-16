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

use PHPRegex\Linter\Extraction\PatternAttributeScanner;
use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A function that marks several parameters with #[RegexPattern], or with
 * PhpStorm's #[Language('RegExp')], receives a pattern at each of them:
 * every marked argument is read, by position and by name, and both
 * strategies agree on each.
 */
final class SeveralMarkedParametersTest extends TestCase
{
    use ExtractsWithBothStrategies;

    #[Test]
    public function test_the_scan_names_every_marked_parameter(): void
    {
        $specs = PatternAttributeScanner::scan(<<<'CODE'
            <?php

            namespace App;

            use JetBrains\PhpStorm\Language;
            use PHPRegex\Parser\Attribute\RegexPattern;

            function between(#[RegexPattern] string $from, string $subject, #[RegexPattern] string $to) {}

            final class Range
            {
                public static function of(#[Language('RegExp')] string $start, #[Language('JSON')] string $json, #[RegexPattern] string $end) {}
            }
            CODE);

        $this->assertSame(['App\between#0$from', 'App\between#2$to', 'App\Range::of#0$start', 'App\Range::of#2$end'], $specs);
    }

    #[Test]
    public function test_every_marked_argument_of_a_function_is_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/from/', 'at' => "'/from/'"],
            ['pattern' => '/(to/', 'at' => "'/(to/'"],
            ['pattern' => '/named-to/', 'at' => "'/named-to/'"],
            ['pattern' => '/named-from/', 'at' => "'/named-from/'"],
        ], <<<'PHP_WRAP'
            <?php

            namespace App;

            use PHPRegex\Parser\Attribute\RegexPattern;

            function between(#[RegexPattern] string $from, string $subject, #[RegexPattern] string $to): bool
            {
                return true;
            }

            between('/from/', 'not a pattern', '/(to/');
            between(to: '/named-to/', subject: 'not one either', from: '/named-from/');
            PHP_WRAP);
    }

    #[Test]
    public function test_every_marked_argument_of_a_static_method_is_read(): void
    {
        $this->assertSame(['/start/', '/end/'], $this->patternsReadByBoth(<<<'PHP_WRAP'
            <?php

            namespace App;

            use JetBrains\PhpStorm\Language;
            use PHPRegex\Parser\Attribute\RegexPattern;

            final class Range
            {
                public static function of(#[Language('RegExp')] string $start, string $subject, #[RegexPattern] string $end): bool
                {
                    return true;
                }
            }

            Range::of('/start/', 'not a pattern', '/end/');
            PHP_WRAP));
    }
}
