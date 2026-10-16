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

namespace PHPRegex\Tests\Integration\Bridge\Laravel;

use Illuminate\Contracts\Validation\ValidationRule;
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Laravel\Rules\ValidRegex;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * A pattern a user submits is judged as `regex validate` judges it, with the
 * application's Regex service and its limits. The ReDoS check is off unless
 * asked for, and then rejects from the threshold up.
 */
final class ValidRegexRuleTest extends LaravelTestCase
{
    /**
     * @return iterable<string, array{value: string}>
     */
    public static function provideAcceptedValues(): iterable
    {
        yield 'a valid pattern' => ['value' => '/a+/'];
        yield 'flags and another delimiter' => ['value' => '#^[a-z]+$#iu'];
        // Exponential, but the ReDoS check is off by default.
        yield 'a vulnerable pattern' => ['value' => '/(a+)+$/'];
    }

    #[Test]
    #[DataProvider('provideAcceptedValues')]
    public function test_a_valid_pattern_passes(string $value): void
    {
        $this->assertSame([], $this->errors($value, new ValidRegex()));
    }

    #[Test]
    public function test_an_invalid_pattern_carries_the_library_message(): void
    {
        // The offset PCRE2 gives depends on its release: the message is the
        // library's own for the running engine.
        $error = (string) Regex::create()->validate('/abc)/')->error;
        $this->assertStringStartsWith('Unmatched closing parenthesis at position ', $error);

        $this->assertSame(
            ['The pattern field must be a valid regular expression: '.$error],
            $this->errors('/abc)/', new ValidRegex()),
        );
    }

    #[Test]
    public function test_the_rule_judges_with_the_application_service(): void
    {
        self::configOf($this->application())->set('php-regex.max_pattern_length', 5);
        $this->application()->forgetInstance(Regex::class);

        $this->assertSame(
            ['The pattern field must be a valid regular expression: Regex pattern exceeds maximum length of 5 characters.'],
            $this->errors('/abcdef/', new ValidRegex()),
        );
    }

    #[Test]
    public function test_a_service_given_to_the_rule_is_used(): void
    {
        $rule = new ValidRegex(regex: Regex::create(['max_pattern_length' => 5]));

        $this->assertCount(1, $this->errors('/abcdef/', $rule));
    }

    #[Test]
    public function test_a_value_that_is_no_string_is_refused(): void
    {
        $this->assertSame(['The pattern field must be a string.'], $this->errors(['/a+/'], new ValidRegex()));
    }

    #[Test]
    public function test_an_empty_value_is_left_to_required(): void
    {
        $this->assertSame([], $this->errors('', new ValidRegex()));
        $this->assertSame(['The pattern field is required.'], $this->errors('', ['required', new ValidRegex()]));
    }

    #[Test]
    public function test_the_redos_check_rejects_from_high_by_default(): void
    {
        $this->assertSame(
            ['The pattern field must not be open to catastrophic backtracking (ReDoS risk: high).'],
            $this->errors('/x*x*x*$/', new ValidRegex(redos: true)),
        );
    }

    /**
     * @return iterable<string, array{pattern: string, threshold: RedosSeverity|string}>
     */
    public static function provideAcceptedUnderThreshold(): iterable
    {
        yield 'proven safe' => ['pattern' => '/a+/', 'threshold' => RedosSeverity::Low];
        // Polynomial, degree 2: medium, under the default high.
        yield 'medium under high' => ['pattern' => '/a*a*$/', 'threshold' => RedosSeverity::High];
        // Polynomial, degree 3: high, under critical.
        yield 'high under critical' => ['pattern' => '/x*x*x*$/', 'threshold' => 'critical'];
    }

    #[Test]
    #[DataProvider('provideAcceptedUnderThreshold')]
    public function test_a_pattern_under_the_threshold_passes(string $pattern, RedosSeverity|string $threshold): void
    {
        $this->assertSame([], $this->errors($pattern, new ValidRegex(redos: true, redosThreshold: $threshold)));
    }

    #[Test]
    public function test_the_threshold_may_be_lowered(): void
    {
        $this->assertSame(
            ['The pattern field must not be open to catastrophic backtracking (ReDoS risk: medium).'],
            $this->errors('/a*a*$/', new ValidRegex(redos: true, redosThreshold: 'medium')),
        );
    }

    #[Test]
    public function test_an_invalid_pattern_is_not_analyzed_for_redos(): void
    {
        $errors = $this->errors('/(a+)+$)/', new ValidRegex(redos: true));

        $this->assertCount(1, $errors);
        $this->assertStringStartsWith('The pattern field must be a valid regular expression: ', $errors[0]);
    }

    #[Test]
    public function test_an_unknown_threshold_is_refused_when_the_rule_is_built(): void
    {
        $this->expectException(InvalidRegexOptionException::class);

        new ValidRegex(redos: true, redosThreshold: 'unknown');
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }

    /**
     * @param ValidationRule|list<ValidationRule|string> $rules
     *
     * @return list<string>
     */
    private function errors(mixed $value, ValidationRule|array $rules): array
    {
        /** @var \Illuminate\Validation\Factory $factory */
        $factory = $this->application()->make('validator');
        $validator = $factory->make(['pattern' => $value], ['pattern' => \is_array($rules) ? $rules : [$rules]]);

        $messages = $validator->errors()->get('pattern');
        $strings = array_values(array_filter($messages, \is_string(...)));
        $this->assertSame($strings, $messages, 'Every message of the attribute is a string.');

        return $strings;
    }
}
