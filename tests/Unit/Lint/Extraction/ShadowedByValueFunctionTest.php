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
 * sprintf(), implode(), join() and preg_quote() take every argument by
 * value, the ones of PHP: a namespace that declares a function of the
 * same name is called instead, and that one may take a reference. What
 * is passed to it is written off as with any other call, a variable and
 * a property alike, wherever in the file the declaration stands.
 *
 * PHP 8.4: namespace App; function sprintf(string &$format): void {
 * $format = '/(/'; } then, in a function of App, $re = '/x/';
 * sprintf($re); leaves "/(/" in $re.
 *
 * Every case runs through both strategies.
 */
final class ShadowedByValueFunctionTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{function: string}>
     */
    public static function provideByValueFunctions(): iterable
    {
        yield 'sprintf()' => ['function' => 'sprintf'];
        yield 'implode()' => ['function' => 'implode'];
        yield 'join()' => ['function' => 'join'];
        yield 'preg_quote()' => ['function' => 'preg_quote'];
    }

    #[Test]
    #[DataProvider('provideByValueFunctions')]
    public function test_a_variable_passed_to_the_function_of_the_namespace_is_written_off(string $function): void
    {
        $declaration = 'function '.$function."(string &\$text): void\n{\n    \$text = '/(/';\n}";
        $caller = "function run(string \$s): int\n{\n    \$re = '/x/';\n    ".$function."(\$re);\n\n    return preg_match(\$re, \$s);\n}";

        foreach ([$declaration."\n\n".$caller, $caller."\n\n".$declaration] as $functions) {
            $code = "<?php\n\nnamespace App;\n\n".$functions."\n";

            $this->assertExtractedByBoth([], $code);
            $this->assertArgumentsReadByBoth([
                ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $s)', 'source' => 'preg_match()'],
            ], $code);
        }
    }

    #[Test]
    #[DataProvider('provideByValueFunctions')]
    public function test_a_property_passed_to_the_function_of_the_namespace_is_written_off(string $function): void
    {
        $code = "<?php\n\nnamespace App;\n\nfunction ".$function."(string &\$text): void\n{\n    \$text = '/(/';\n}\n\nfinal class Holder\n{\n    private string \$re = '/x/';\n\n    public function run(string \$s): int\n    {\n        ".$function."(\$this->re);\n\n        return preg_match(\$this->re, \$s);\n    }\n}\n";

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$this->re', 'at' => '$this->re, $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * The object itself passed: the function may write any of its
     * properties.
     */
    #[Test]
    public function test_an_object_passed_to_the_function_of_the_namespace_has_its_properties_written_off(): void
    {
        $code = <<<'PHP'
            <?php

            namespace App;

            function implode(object $holder): void
            {
            }

            final class Holder
            {
                private string $re = '/x/';

                public function run(string $s): int
                {
                    implode($this);

                    return preg_match($this->re, $s);
                }
            }
            PHP;

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$this->re', 'at' => '$this->re, $s)', 'source' => 'preg_match()'],
        ], $code);
    }
}
