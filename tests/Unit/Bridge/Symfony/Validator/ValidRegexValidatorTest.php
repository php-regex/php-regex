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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Validator;

use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Symfony\Validator\Constraints\ValidRegex;
use PHPRegex\Symfony\Validator\Constraints\ValidRegexValidator;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * A pattern a user submits is judged as `regex validate` judges it: the
 * library's message and hint, the service's limits. The ReDoS check is off
 * unless asked for, and then rejects from the threshold up.
 *
 * @extends ConstraintValidatorTestCase<ValidRegexValidator>
 */
final class ValidRegexValidatorTest extends ConstraintValidatorTestCase
{
    private ?Regex $regex = null;

    /**
     * @return iterable<string, array{value: string|null}>
     */
    public static function provideAcceptedValues(): iterable
    {
        yield 'null' => ['value' => null];
        yield 'empty string' => ['value' => ''];
        yield 'a valid pattern' => ['value' => '/a+/'];
        yield 'flags and another delimiter' => ['value' => '#^[a-z]+$#iu'];
        // Exponential, but the ReDoS check is off by default.
        yield 'a vulnerable pattern' => ['value' => '/(a+)+$/'];
    }

    #[Test]
    #[DataProvider('provideAcceptedValues')]
    public function test_a_valid_pattern_raises_no_violation(?string $value): void
    {
        $this->check($value, new ValidRegex());

        $this->assertNoViolation();
    }

    #[Test]
    public function test_an_invalid_pattern_carries_the_library_message(): void
    {
        $this->check('/abc)/', new ValidRegex());

        $this->buildViolation('This value is not a valid regular expression: {{ error }}')
            ->setParameter('{{ value }}', '"/abc)/"')
            ->setParameter('{{ error }}', 'Unmatched closing parenthesis at position 4.')
            ->setParameter('{{ hint }}', '')
            ->setInvalidValue('/abc)/')
            ->setCode(ValidRegex::INVALID_ERROR)
            ->assertRaised();
    }

    #[Test]
    public function test_the_hint_is_a_parameter_of_the_message(): void
    {
        $this->check('/a{2,1}/', new ValidRegex(message: '{{ error }} {{ hint }}'));

        $this->buildViolation('{{ error }} {{ hint }}')
            ->setParameter('{{ value }}', '"/a{2,1}/"')
            ->setParameter('{{ error }}', 'Invalid quantifier range "{2,1}": min > max.')
            ->setParameter('{{ hint }}', 'Write the smaller count first: "{1,2}".')
            ->setInvalidValue('/a{2,1}/')
            ->setCode(ValidRegex::INVALID_ERROR)
            ->assertRaised();
    }

    /**
     * The validator judges with the service it is given, its limits
     * included: a pattern over the configured length is refused.
     */
    #[Test]
    public function test_a_pattern_over_a_limit_of_the_service_is_refused(): void
    {
        $this->regex = Regex::create(['max_pattern_length' => 5]);
        $this->validator = $this->createValidator();

        $this->check('/abcdef/', new ValidRegex());

        $this->buildViolation('This value is not a valid regular expression: {{ error }}')
            ->setParameter('{{ value }}', '"/abcdef/"')
            ->setParameter('{{ error }}', 'Regex pattern exceeds maximum length of 5 characters.')
            ->setParameter('{{ hint }}', 'A limit of this library, not of PCRE: raise the "max_pattern_length" option to analyze the pattern.')
            ->setInvalidValue('/abcdef/')
            ->setCode(ValidRegex::INVALID_ERROR)
            ->assertRaised();
    }

    #[Test]
    public function test_a_stringable_value_is_read_as_a_string(): void
    {
        $value = new class implements \Stringable {
            public function __toString(): string
            {
                return '/a+/';
            }
        };

        $this->check($value, new ValidRegex());

        $this->assertNoViolation();
    }

    #[Test]
    public function test_a_value_that_is_no_string_is_refused(): void
    {
        $this->expectException(UnexpectedValueException::class);

        $this->check(['/a+/'], new ValidRegex());
    }

    #[Test]
    public function test_another_constraint_is_refused(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->check('/a+/', new NotBlank());
    }

    #[Test]
    public function test_the_redos_check_rejects_from_high_by_default(): void
    {
        $this->check('/x*x*x*$/', new ValidRegex(redos: true));

        $this->buildViolation('This regular expression is open to catastrophic backtracking (ReDoS risk: {{ severity }}).')
            ->setParameter('{{ value }}', '"/x*x*x*$/"')
            ->setParameter('{{ severity }}', 'high')
            ->setInvalidValue('/x*x*x*$/')
            ->setCode(ValidRegex::REDOS_ERROR)
            ->assertRaised();
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
        $this->check($pattern, new ValidRegex(redos: true, redosThreshold: $threshold));

        $this->assertNoViolation();
    }

    #[Test]
    public function test_the_threshold_may_be_lowered(): void
    {
        $this->check('/a*a*$/', new ValidRegex(redos: true, redosThreshold: 'medium', redosMessage: 'ReDoS {{ severity }}'));

        $this->buildViolation('ReDoS {{ severity }}')
            ->setParameter('{{ value }}', '"/a*a*$/"')
            ->setParameter('{{ severity }}', 'medium')
            ->setInvalidValue('/a*a*$/')
            ->setCode(ValidRegex::REDOS_ERROR)
            ->assertRaised();
    }

    #[Test]
    public function test_an_invalid_pattern_is_not_analyzed_for_redos(): void
    {
        $this->check('/(a+)+$)/', new ValidRegex(redos: true));

        $this->assertCount(1, $this->context->getViolations());
        $this->assertSame(ValidRegex::INVALID_ERROR, $this->context->getViolations()->get(0)->getCode());
    }

    #[Test]
    public function test_an_unknown_threshold_is_refused_when_the_constraint_is_built(): void
    {
        $this->expectException(InvalidRegexOptionException::class);

        new ValidRegex(redos: true, redosThreshold: 'safe');
    }

    #[Test]
    public function test_the_constraint_is_an_attribute_with_named_arguments(): void
    {
        $property = new \ReflectionProperty(ValidRegexFixture::class, 'pattern');
        $constraint = $property->getAttributes(ValidRegex::class)[0]->newInstance();

        $this->assertTrue($constraint->redos);
        $this->assertSame(RedosSeverity::Critical, $constraint->redosThreshold);
        $this->assertSame(['Default'], $constraint->groups);
        $this->assertSame('INVALID_ERROR', ValidRegex::getErrorName(ValidRegex::INVALID_ERROR));
        $this->assertSame('REDOS_ERROR', ValidRegex::getErrorName(ValidRegex::REDOS_ERROR));
    }

    protected function createValidator(): ValidRegexValidator
    {
        return new ValidRegexValidator($this->regex);
    }

    /**
     * Symfony 8.1 hands the context to validateInContext(); 7.4, which the
     * bundle supports too, sets it with initialize() before validate().
     */
    private function check(mixed $value, Constraint $constraint): void
    {
        if (method_exists($this->validator, 'validateInContext')) {
            $this->validator->validateInContext($value, $constraint, $this->context);

            return;
        }

        $this->validator->initialize($this->context);
        $this->validator->validate($value, $constraint);
    }
}

/**
 * @internal
 */
final class ValidRegexFixture
{
    #[ValidRegex(redos: true, redosThreshold: RedosSeverity::Critical)]
    public string $pattern = '';
}
