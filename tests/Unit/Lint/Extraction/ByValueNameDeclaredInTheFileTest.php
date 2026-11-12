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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * preg_quote(), sprintf(), implode() and join() take their arguments by
 * value: what is passed to them keeps the value the file proves. A
 * function of that name the file declares in the namespace of the call
 * is reached first, and may take a reference: what is passed to it is
 * written off, a variable as a property, as by any other call. The
 * declaration may stand after the call: the whole file is read before
 * any value is.
 *
 * PHP 8.4 (php -r): namespace App; function preg_quote(string &$text):
 * string { $text = '/(/'; return $text; } $re = '/a/'; preg_quote($re);
 * leaves "/(/" in $re.
 *
 * Every case runs through both strategies.
 */
final class ByValueNameDeclaredInTheFileTest extends TestCase
{
    use ExtractsWithBothStrategies;

    #[Test]
    public function test_a_variable_passed_to_a_function_of_the_namespace_is_written_off(): void
    {
        $code = <<<'PHP'
            <?php

            namespace App;

            function run(string $s): int
            {
                $re = '/alone/';
                preg_quote($re);
                $part = 'joined';
                preg_quote($part);

                return preg_match($re, $s) + preg_match('/^' . $part . '$/', $s);
            }

            function preg_quote(string &$text): string
            {
                $text = '/(/';

                return $text;
            }
            PHP;

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $s)'],
            ['state' => 'raw_shell', 'expression' => "'/^' . \$part . '$/'"],
        ], $code);
    }

    /**
     * The property passed to the function of the namespace is written
     * off; the one beside it keeps its default.
     */
    #[Test]
    public function test_a_property_passed_to_a_function_of_the_namespace_is_written_off(): void
    {
        $code = <<<'PHP'
            <?php

            namespace App;

            function sprintf(string &$text): string
            {
                $text = '/(/';

                return $text;
            }

            final class Matcher
            {
                private string $passed = '/passed/';
                private string $kept = '/kept/';

                public function run(string $s): int
                {
                    sprintf($this->passed);

                    return preg_match($this->passed, $s) + preg_match($this->kept, $s);
                }
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/kept/', 'at' => "'/kept/'", 'location' => 'via $this->kept'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$this->passed', 'at' => '$this->passed, $s)'],
            ['state' => 'constant', 'expression' => '$this->kept', 'at' => '$this->kept, $s)'],
        ], $code);
    }

    /**
     * The property is named by an expression: any property of the class
     * may be the one passed.
     */
    #[Test]
    public function test_a_property_named_by_an_expression_and_passed_to_a_function_of_the_namespace_writes_every_property_off(): void
    {
        $code = <<<'PHP'
            <?php

            namespace App;

            function implode(string &$text): string
            {
                $text = '/(/';

                return $text;
            }

            final class Matcher
            {
                private string $re = '/default/';

                public function run(string $s, string $name): int
                {
                    implode($this->$name);

                    return preg_match($this->re, $s);
                }
            }
            PHP;

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$this->re', 'at' => '$this->re, $s)'],
        ], $code);
    }

    /**
     * The same calls reaching the functions of PHP, which the namespace
     * does not declare: the properties keep their defaults.
     */
    #[Test]
    public function test_a_property_passed_to_the_function_of_php_keeps_its_default(): void
    {
        $code = <<<'PHP'
            <?php

            namespace App;

            final class Matcher
            {
                private string $re = '/default/';

                public function run(string $s, string $name): int
                {
                    $text = sprintf($this->re) . implode($this->$name);

                    return preg_match($this->re, $s) + \strlen($text);
                }
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/default/', 'at' => "'/default/'", 'location' => 'via $this->re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$this->re', 'at' => '$this->re, $s)'],
        ], $code);
    }
}
