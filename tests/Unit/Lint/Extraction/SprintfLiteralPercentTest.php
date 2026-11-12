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

use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "%%" in a format of sprintf() is one percent sign and no place: the
 * text before it, the sign and the text after it are one text, however
 * many of them stand before a place or after the last one.
 *
 * PHP 8.4 (php -r): sprintf('/a%%b%%c%s/', 'x') is "/a%b%cx/",
 * sprintf('/^%d%%%%$/', 50) is "/^50%%$/" and sprintf('/%s%%/', 'y') is
 * "/y%/".
 *
 * Every case runs through both strategies.
 */
final class SprintfLiteralPercentTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{argument: string, pattern: string}>
     */
    public static function provideFormatsHoldingPercentSigns(): iterable
    {
        yield 'two percent signs before a place, text between them' => ['argument' => "sprintf('/a%%b%%c%s/', 'x')", 'pattern' => '/a%b%cx/'];
        yield 'two percent signs side by side after a place' => ['argument' => "sprintf('/^%d%%%%$/', 50)", 'pattern' => '/^50%%$/'];
        yield 'a percent sign after the last place' => ['argument' => "sprintf('/%s%%/', 'y')", 'pattern' => '/y%/'];
        yield 'a percent sign between two places' => ['argument' => "sprintf('/%s%%%s/', 'x', 'y')", 'pattern' => '/x%y/'];
    }

    #[Test]
    #[DataProvider('provideFormatsHoldingPercentSigns')]
    public function test_a_doubled_percent_sign_is_one_sign_of_the_text_around_it(string $argument, string $pattern): void
    {
        $code = "<?php\n\nfunction run(string \$s): int\n{\n    return preg_match(".$argument.", \$s);\n}\n";

        $this->assertExtractedByBoth([
            ['pattern' => $pattern, 'at' => $argument, 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * Around a place the file does not prove, the percent signs are kept
     * in the text written around it.
     */
    #[Test]
    public function test_percent_signs_around_a_place_the_file_does_not_prove_leave_a_pattern_around_it(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, string $word): int
            {
                return preg_match(sprintf('/a%%b%sc%%d%s/', 'x', $word), $s);
            }
            PHP;

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => "sprintf('/a%%b%sc%%d%s/', 'x', \$word)"],
        ], $code);
    }
}
