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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The phpunit preset reads the pattern of PHPUnit's regex assertions. It is
 * the one preset matched by method name, not on a resolved class: the
 * receivers are those a test calls them on, and the names are PHPUnit's
 * own.
 *
 * Signatures, phpunit/phpunit 12.5: Assert::assertMatchesRegularExpression(
 * string $pattern, ...), assertDoesNotMatchRegularExpression(string
 * $pattern, ...), matchesRegularExpression(string $pattern), the same three
 * as functions of PHPUnit\Framework; TestCase::expectOutputRegex(string
 * $expectedRegex), expectExceptionMessageMatches(string
 * $regularExpression), expectUserDeprecationMessageMatches(string
 * $expectedUserDeprecationMessageRegularExpression). PHPUnit 9 and older:
 * assertRegExp(string $pattern, ...), assertNotRegExp(string $pattern, ...).
 * Each one hands its pattern to preg_match().
 */
final class PhpUnitPresetTest extends TestCase
{
    use ExtractsWithBothStrategies;

    private const ASSERTIONS = [
        'assertMatchesRegularExpression' => '($this->pattern, $actual)',
        'assertDoesNotMatchRegularExpression' => '($this->pattern, $actual)',
        'matchesRegularExpression' => '($this->pattern)',
        'assertRegExp' => '($this->pattern, $actual)',
        'assertNotRegExp' => '($this->pattern, $actual)',
    ];

    private const EXPECTATIONS = [
        'expectOutputRegex' => '($this->pattern)',
        'expectExceptionMessageMatches' => '($this->pattern)',
        'expectUserDeprecationMessageMatches' => '($this->pattern)',
    ];

    /**
     * @return iterable<string, array{call: string, imports: string}>
     */
    public static function provideCalls(): iterable
    {
        foreach ([...self::ASSERTIONS, ...self::EXPECTATIONS] as $method => $arguments) {
            foreach (['$this->', 'self::', 'static::', 'parent::'] as $receiver) {
                yield $receiver.$method => ['call' => $receiver.$method.$arguments, 'imports' => ''];
            }
        }

        foreach (self::ASSERTIONS as $method => $arguments) {
            yield 'Assert::'.$method => ['call' => 'Assert::'.$method.$arguments, 'imports' => "use PHPUnit\\Framework\\Assert;\n"];
            yield 'TestCase::'.$method => ['call' => 'TestCase::'.$method.$arguments, 'imports' => ''];
            yield '\PHPUnit\Framework\Assert::'.$method => ['call' => '\PHPUnit\Framework\Assert::'.$method.$arguments, 'imports' => ''];
            yield 'function '.$method => ['call' => $method.$arguments, 'imports' => "use function PHPUnit\\Framework\\{$method};\n"];
            yield 'qualified function '.$method => ['call' => '\PHPUnit\Framework\\'.$method.$arguments, 'imports' => ''];
        }
    }

    #[Test]
    #[DataProvider('provideCalls')]
    public function test_phpunit_reads_each_assertion_on_each_receiver(string $call, string $imports): void
    {
        $call = str_replace('$this->pattern', "'/phpunit/'", $call);

        $this->assertExtractedByBoth([
            ['pattern' => '/phpunit/', 'at' => "'/phpunit/'", 'location' => null],
        ], "<?php\n\nnamespace App\\Tests;\n\nuse PHPUnit\\Framework\\TestCase;\n".$imports."\nfinal class ThingTest extends TestCase\n{\n    public function test_thing(): void\n    {\n        \$actual = 'x';\n        ".$call.";\n    }\n}\n", PatternFunctionRegistry::create(['phpunit']));
    }

    /**
     * Each method is read under the name of its own pattern parameter.
     *
     * @return iterable<string, array{call: string}>
     */
    public static function provideNamedArguments(): iterable
    {
        yield 'assertMatchesRegularExpression' => ['call' => "\$this->assertMatchesRegularExpression(string: \$actual, pattern: '/named/')"];
        yield 'assertDoesNotMatchRegularExpression' => ['call' => "self::assertDoesNotMatchRegularExpression(string: \$actual, pattern: '/named/')"];
        yield 'matchesRegularExpression' => ['call' => "\$this->matchesRegularExpression(pattern: '/named/')"];
        yield 'expectOutputRegex' => ['call' => "\$this->expectOutputRegex(expectedRegex: '/named/')"];
        yield 'expectExceptionMessageMatches' => ['call' => "\$this->expectExceptionMessageMatches(regularExpression: '/named/')"];
        yield 'expectUserDeprecationMessageMatches' => ['call' => "\$this->expectUserDeprecationMessageMatches(expectedUserDeprecationMessageRegularExpression: '/named/')"];
    }

    #[Test]
    #[DataProvider('provideNamedArguments')]
    public function test_phpunit_reads_a_named_argument_under_the_real_parameter_name(string $call): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/named/', 'at' => "'/named/'"],
        ], "<?php\n\nuse PHPUnit\\Framework\\TestCase;\n\nfinal class ThingTest extends TestCase\n{\n    public function test_thing(): void\n    {\n        \$actual = 'x';\n        ".$call.";\n    }\n}\n", PatternFunctionRegistry::create(['phpunit']));
    }

    /**
     * Matching goes by method name: a helper trait or a class of the test
     * suite that does not extend TestCase is read the same way.
     */
    #[Test]
    public function test_phpunit_matching_needs_no_testcase_parent(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/in-a-trait/', 'at' => "'/in-a-trait/'"],
            ['pattern' => '/in-a-helper/', 'at' => "'/in-a-helper/'"],
        ], <<<'PHP'
            <?php

            namespace App\Tests;

            trait AssertsSlugs
            {
                public function assertSlug(string $actual): void
                {
                    $this->assertMatchesRegularExpression('/in-a-trait/', $actual);
                }
            }

            final class Helper
            {
                public function expect(): void
                {
                    $this->expectExceptionMessageMatches('/in-a-helper/');
                }
            }
            PHP, PatternFunctionRegistry::create(['phpunit']));
    }

    /**
     * Only the receivers a test calls them on: a method of that name on any
     * other object is no PHPUnit assertion.
     */
    #[Test]
    public function test_phpunit_methods_on_another_receiver_are_not_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/on-this/', 'at' => "'/on-this/'"],
        ], <<<'PHP_WRAP'
            <?php

            use PHPUnit\Framework\TestCase;

            final class ThingTest extends TestCase
            {
                public function test_thing(object $helper): void
                {
                    $helper->assertMatchesRegularExpression('/on-a-variable/', 'x');
                    $helper->$this->assertMatchesRegularExpression('/on-a-property-named-by-this/', 'x');
                    $this->helper->expectOutputRegex('/on-a-property/');
                    Other::assertMatchesRegularExpression('/on-another-class/', 'x');
                    $this->assertMatchesRegularExpression('/on-this/', 'x');
                }
            }
            PHP_WRAP, PatternFunctionRegistry::create(['phpunit']));
    }

    /**
     * Off, the preset reads nothing: interop [] means the native calls only.
     */
    #[Test]
    public function test_phpunit_is_not_read_when_the_preset_is_off(): void
    {
        $this->assertSame(['/native/'], $this->patternsReadByBoth(<<<'PHP_WRAP'
            <?php

            use PHPUnit\Framework\TestCase;

            final class ThingTest extends TestCase
            {
                public function test_thing(): void
                {
                    $this->assertMatchesRegularExpression('/off/', 'x');
                    preg_match('/native/', 'x');
                }
            }
            PHP_WRAP, PatternFunctionRegistry::create([])));
    }

    /**
     * A pattern a test keeps in a constant is resolved like any other.
     */
    #[Test]
    public function test_phpunit_reads_a_resolved_pattern(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/resolved-in-test/', 'at' => "'/resolved-in-test/'", 'location' => 'via self::SLUG'],
        ], <<<'PHP_WRAP'
            <?php

            use PHPUnit\Framework\TestCase;

            final class ThingTest extends TestCase
            {
                private const SLUG = '/resolved-in-test/';

                public function test_thing(): void
                {
                    $this->assertMatchesRegularExpression(self::SLUG, 'x');
                }
            }
            PHP_WRAP, PatternFunctionRegistry::create(['phpunit']));
    }
}
