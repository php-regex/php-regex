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

use PHPRegex\PHPStan\RegexPatternArgumentRule;
use PHPStan\Analyser\Error;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The patterns passed to a parameter marked #[RegexPattern] or PhpStorm's
 * #[Language('RegExp')], with the shipped extension.neon alone: PHPStan core
 * does not read them, so a pattern the running engine refuses is reported
 * here, as core reports it in a preg_*() call.
 *
 * @extends RuleTestCase<RegexPatternArgumentRule>
 */
final class RegexPatternArgumentRuleTest extends RuleTestCase
{
    public static function getAdditionalConfigFiles(): array
    {
        return [
            ...parent::getAdditionalConfigFiles(),
            __DIR__.'/../../../../src/PHPStan/extension.neon',
        ];
    }

    #[Test]
    public function test_a_refused_pattern_is_reported_wherever_a_marked_parameter_receives_it(): void
    {
        // The oracle: the PCRE2 running this test refuses both, at offset 4.
        $this->assertFalse(self::runningEngineCompiles('/(foo/'));
        $this->assertFalse(self::runningEngineCompiles('/(bar/'));
        $this->assertTrue(self::runningEngineCompiles('/foo/'));

        $refused = 'Regex pattern is invalid: missing closing parenthesis at offset 4.';
        $this->analyse([__DIR__.'/Fixtures/RegexPatternArgumentFixture.php'], [
            [$refused, 66], // function
            [$refused, 67], // static method
            [$refused, 68], // instance method, the receiver's type known
            [$refused, 69], // nullsafe instance method
            [$refused, 70], // constructor
            [$refused, 71], // named argument, out of order
            [$refused, 73], // PhpStorm's #[Language('RegExp')]
            [$refused, 74], // a variadic parameter, each argument
            [$refused, 75], // each constant string the argument may hold
            [$refused, 102], // parent::__construct()
            [$refused, 103], // self::
            [$refused, 104], // static::
            [$refused, 120], // a static call on a class-string PHPStan knows
        ]);
    }

    #[Test]
    public function test_a_refused_pattern_carries_the_identifier_of_phpstan_core(): void
    {
        $identifiers = array_unique(array_map(
            static fn (Error $error): ?string => $error->getIdentifier(),
            $this->gatherAnalyserErrors([__DIR__.'/Fixtures/RegexPatternArgumentFixture.php']),
        ));

        $this->assertSame(['regexp.pattern'], array_values($identifiers));
    }

    protected function getRule(): Rule
    {
        // The fixture declares its own functions and classes, out of the
        // autoloader's reach: loaded, PHPStan reflects them as it runs.
        // PhpStorm's attribute comes from jetbrains/phpstorm-attributes,
        // which a project installs to have its arguments read; this is its
        // class, with the same constructor, declared at run time only.
        if (!class_exists('JetBrains\\PhpStorm\\Language')) {
            eval('namespace JetBrains\\PhpStorm; #[\\Attribute(\\Attribute::TARGET_PARAMETER)] final class Language { public function __construct(string $languageName) {} }');
        }
        require_once __DIR__.'/Fixtures/RegexPatternArgumentFixture.php';

        return self::getContainer()->getByType(RegexPatternArgumentRule::class);
    }

    /**
     * The oracle: whether the PCRE2 running this test compiles the pattern.
     */
    private static function runningEngineCompiles(string $pattern): bool
    {
        return false !== @preg_match($pattern, '');
    }
}
