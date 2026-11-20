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

use PHPRegex\Symfony\Validator\RegexConstraintAnalyzer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Sequentially;
use Symfony\Component\Validator\Mapping\ClassMetadata;
use Symfony\Component\Validator\Validation;

/**
 * The audit of Regex constraints against the HTML pattern a form gives the
 * browser, and against the other constraints of their property.
 *
 * Each example the audit gives was checked with Node 22: whether
 * new RegExp("^(?:" + htmlPattern + ")$", "v") accepts it is the browser
 * verdict pinned below; preg_match() gives the server's.
 */
final class RegexConstraintAnalyzerTest extends TestCase
{
    /**
     * The server's verdicts each row rests on are checked with preg_match()
     * before the audit runs; each example the audit gives of a difference
     * is checked against Symfony's own validator: the server rejects the
     * example of a looser HTML pattern or of a rejected Choice value, and
     * accepts that of a stricter one.
     *
     * @param list<Constraint>                  $constraints
     * @param list<list<string>>                $expected    each finding's check and severity
     * @param list<array{string, string, bool}> $verdicts    each pattern, subject and whether preg_match() matches
     */
    #[Test]
    #[DataProvider('provideProperties')]
    public function test_the_findings_of_a_property(array $constraints, array $expected, array $verdicts): void
    {
        foreach ($verdicts as [$pattern, $subject, $matches]) {
            $this->assertSame($matches ? 1 : 0, preg_match($pattern, $subject), $pattern.' on '.json_encode($subject));
        }

        $findings = self::audit($constraints);

        $this->assertSame($expected, array_map(static fn (array $finding): array => [$finding['check'], $finding['severity']], $findings));
        foreach ($findings as $finding) {
            if (!isset($finding['example'])) {
                continue;
            }

            $regex = self::regexOf($constraints, $finding['pattern']);
            $this->assertSame('html_pattern.stricter' !== $finding['check'], self::serverRejects($regex, $finding['example']), $finding['check'].' on '.json_encode($finding['example']));
        }
    }

    /**
     * @return iterable<string, array{constraints: list<Constraint>, expected: list<list<string>>, verdicts: list<array{string, string, bool}>}>
     */
    public static function provideProperties(): iterable
    {
        yield 'anchored digits read alike' => [
            'constraints' => [new Regex('/^\d+$/')],
            'expected' => [],
            'verdicts' => [['/^\d+$/', '123', true], ['/^\d+$/', '12a', false]],
        ];
        yield 'an unanchored pattern the derivation wraps in .*' => [
            'constraints' => [new Regex('/^foo/')],
            'expected' => [['html_pattern.stricter', 'warning']],
            'verdicts' => [['/^foo/', 'foo', true], ['/^foo/', "foo\u{2028}", true], ['/^foo/', 'xfoo', false]],
        ];
        yield 'an alternation the derivation wraps whole' => [
            'constraints' => [new Regex('/^(foo|bar)$/')],
            'expected' => [],
            'verdicts' => [['/^(foo|bar)$/', 'bar', true], ['/^(foo|bar)$/', 'foobar', false]],
        ];
        yield 'an explicit htmlPattern that agrees' => [
            'constraints' => [new Regex(pattern: '/^\d+$/', htmlPattern: '[0-9]+')],
            'expected' => [],
            'verdicts' => [['/^\d+$/', '42', true], ['/^\d+$/', 'x', false]],
        ];
        yield 'an empty htmlPattern turns the attribute off' => [
            'constraints' => [new Regex(pattern: '/^a$/i', htmlPattern: '')],
            'expected' => [],
            'verdicts' => [['/^a$/i', 'A', true]],
        ];
        // ((?!\d).)* under v: JavaScript's dot refuses U+2028, which holds
        // no digit.
        yield 'a pattern that must not match, derived with a dot' => [
            'constraints' => [new Regex(pattern: '/\d/', match: false)],
            'expected' => [['html_pattern.stricter', 'warning']],
            'verdicts' => [['/\d/', 'a1', true], ['/\d/', 'ab', false], ['/\d/', "\u{2028}", false]],
        ];
        yield 'a pattern that must not match, with an htmlPattern that agrees' => [
            'constraints' => [new Regex(pattern: '/\d/', match: false, htmlPattern: '[^0-9]*')],
            'expected' => [],
            'verdicts' => [['/\d/', "\u{2028}", false]],
        ];
        yield 'flags drop the attribute' => [
            'constraints' => [new Regex('/^[a-z]+$/i')],
            'expected' => [['html_pattern.dropped', 'warning']],
            'verdicts' => [['/^[a-z]+$/i', 'ABC', true]],
        ];
        yield 'a dash a class under v refuses' => [
            'constraints' => [new Regex('/^[a-z0-9-]+$/')],
            'expected' => [['html_pattern.invalid', 'error']],
            'verdicts' => [['/^[a-z0-9-]+$/', 'a-1', true]],
        ];
        yield 'a possessive a browser refuses' => [
            'constraints' => [new Regex('/^a++b$/')],
            'expected' => [['html_pattern.invalid', 'error']],
            'verdicts' => [['/^a++b$/', 'aab', true]],
        ];
        yield 'a POSIX class a browser misreads' => [
            'constraints' => [new Regex('/^[[:alpha:]]+$/')],
            'expected' => [['html_pattern.misread', 'error']],
            'verdicts' => [['/^[[:alpha:]]+$/', 'abc', true], ['/^[[:alpha:]]+$/', ':', false]],
        ];
        // An explicit htmlPattern is written for the browser: what matters
        // is how a browser reads it, \v the vertical tab alone.
        yield 'an explicit htmlPattern a browser reads as the server' => [
            'constraints' => [new Regex(pattern: '/^\x0B$/Du', htmlPattern: '\v')],
            'expected' => [],
            'verdicts' => [['/^\x0B$/Du', "\x0B", true], ['/^\x0B$/Du', "\f", false]],
        ];
        yield 'an explicit htmlPattern with \v in a class' => [
            'constraints' => [new Regex(pattern: '/^[\x0Ba]$/Du', htmlPattern: '[\va]')],
            'expected' => [],
            'verdicts' => [['/^[\x0Ba]$/Du', "\x0B", true], ['/^[\x0Ba]$/Du', "\f", false]],
        ];
        yield 'an explicit htmlPattern a browser reads otherwise than the server' => [
            'constraints' => [new Regex(pattern: '/^\v$/Du', htmlPattern: '\v')],
            'expected' => [['html_pattern.stricter', 'warning']],
            'verdicts' => [['/^\v$/Du', "\f", true]],
        ];
        yield 'an explicit htmlPattern the comparison cannot read as a browser does' => [
            'constraints' => [new Regex(pattern: '/^[a-z]$/Du', htmlPattern: '[[:alpha:]]')],
            'expected' => [['html_pattern.unchecked', 'notice']],
            'verdicts' => [['/^[a-z]$/Du', ':', false]],
        ];
        yield 'an htmlPattern that lets more through' => [
            'constraints' => [new Regex(pattern: '/^\d+$/', htmlPattern: '[0-9a-f]+')],
            'expected' => [['html_pattern.looser', 'error']],
            'verdicts' => [['/^\d+$/', 'a', false]],
        ];
        yield 'an htmlPattern that lets less through' => [
            'constraints' => [new Regex(pattern: '/^[a-z ]+$/', htmlPattern: '[a-z]+')],
            'expected' => [['html_pattern.stricter', 'warning']],
            'verdicts' => [['/^[a-z ]+$/', ' ', true]],
        ];
        yield 'bytes on the server, code points in the browser' => [
            'constraints' => [new Regex('/^.{2}$/')],
            'expected' => [['html_pattern.looser', 'error'], ['html_pattern.stricter', 'warning']],
            'verdicts' => [['/^.{2}$/', "\u{80}", true], ['/^.{2}$/', "\0\u{80}", false]],
        ];
        // The browser checks no empty value, and Symfony's Regex does not
        // either: '' is no difference.
        yield 'an empty value neither side checks' => [
            'constraints' => [new Regex(pattern: '/^a+$/Du', htmlPattern: 'a*')],
            'expected' => [],
            'verdicts' => [['/^a+$/Du', '', false], ['/^a+$/Du', 'aa', true]],
        ];
        yield 'an empty value the server matches and the browser leaves unchecked' => [
            'constraints' => [new Regex(pattern: '/^a*$/D', htmlPattern: 'a+')],
            'expected' => [],
            'verdicts' => [['/^a*$/D', '', true], ['/^a*$/D', 'aa', true]],
        ];
        // A text input strips a line break: its value holds none.
        yield 'a line break no input holds' => [
            'constraints' => [new Regex(pattern: '/^a$/Du', htmlPattern: 'a|\n')],
            'expected' => [],
            'verdicts' => [['/^a$/Du', "\n", false], ['/^a$/Du', 'a', true]],
        ];
        yield 'an empty value both sides of a match: false pattern skip' => [
            'constraints' => [new Regex(pattern: '/^a*$/Du', htmlPattern: 'b*', match: false)],
            'expected' => [['html_pattern.stricter', 'warning']],
            'verdicts' => [['/^a*$/Du', '', true], ['/^a*$/Du', "\0", false]],
        ];
        // A pattern that reads bytes as it reads code points on UTF-8: the
        // values neither it nor the browser takes are searched as such.
        yield 'a match: false pattern over bytes' => [
            'constraints' => [new Regex(pattern: '/^a$/D', htmlPattern: '[b]', match: false)],
            'expected' => [['html_pattern.stricter', 'warning']],
            'verdicts' => [['/^a$/D', 'c', false], ['/^a$/D', "\0", false]],
        ];
        // One that reads them otherwise: they are not searched, and the
        // audit says so.
        yield 'a match: false pattern bytes read otherwise' => [
            'constraints' => [new Regex(pattern: '/^.$/D', htmlPattern: '.{2}', match: false)],
            'expected' => [['html_pattern.unchecked', 'notice']],
            'verdicts' => [['/^.$/D', 'a', true], ['/^.$/D', 'é', false]],
        ];
        yield 'a match: false pattern over code points' => [
            'constraints' => [new Regex(pattern: '/^a$/Du', htmlPattern: '[b]', match: false)],
            'expected' => [['html_pattern.stricter', 'warning']],
            'verdicts' => [['/^a$/Du', "\0", false]],
        ];
        yield 'a Length no value of the pattern fits' => [
            'constraints' => [new Length(max: 3), new Regex('/^[A-Z]{5}$/')],
            'expected' => [['length.contradiction', 'error']],
            'verdicts' => [['/^[A-Z]{5}$/', 'ABCDE', true], ['/^[A-Z]{5}$/', 'ABC', false]],
        ];
        yield 'a Length min past every value' => [
            'constraints' => [new Regex('/^\d{2,4}$/'), new Length(min: 10)],
            'expected' => [['length.contradiction', 'error']],
            'verdicts' => [['/^\d{2,4}$/', '1234', true], ['/^\d{2,4}$/', '1234567890', false]],
        ];
        yield 'a Length the pattern fits' => [
            'constraints' => [new Regex('/^\d{2,4}$/'), new Length(min: 2, max: 4)],
            'expected' => [],
            'verdicts' => [['/^\d{2,4}$/', '12', true]],
        ];
        yield 'a Length in Sequentially' => [
            'constraints' => [new Sequentially([new NotBlank(), new Length(max: 3), new Regex('/^[A-Z]{5}$/')])],
            'expected' => [['length.contradiction', 'error']],
            'verdicts' => [['/^[A-Z]{5}$/', 'ABCDE', true], ['/^[A-Z]{5}$/', 'ABC', false]],
        ];
        // Lengths 1 and 3 straddle 2, which neither is.
        yield 'a Length between the lengths of the pattern' => [
            'constraints' => [new Regex(pattern: '/^(?:a|aaa)$/Du', htmlPattern: ''), new Length(exactly: 2)],
            'expected' => [['length.contradiction', 'error']],
            'verdicts' => [['/^(?:a|aaa)$/Du', 'a', true], ['/^(?:a|aaa)$/Du', 'aa', false], ['/^(?:a|aaa)$/Du', 'aaa', true]],
        ];
        // The pattern matches an empty value, which Regex does not check,
        // and no other value Length(max: 3) allows.
        yield 'a Length no non-empty value of the pattern fits' => [
            'constraints' => [new Regex(pattern: '/^(?:|abcd)$/D', htmlPattern: ''), new Length(max: 3)],
            'expected' => [['length.contradiction', 'error']],
            'verdicts' => [['/^(?:|abcd)$/D', '', true], ['/^(?:|abcd)$/D', 'abcd', true]],
        ];
        yield 'a Length min some value of the pattern reaches' => [
            'constraints' => [new Regex('/^\d{2,4}$/'), new Length(min: 3)],
            'expected' => [],
            'verdicts' => [['/^\d{2,4}$/', '000', true]],
        ];
        yield 'a Length one length of the pattern fits' => [
            'constraints' => [new Regex(pattern: '/^(?:a|aa|aaa)$/Du', htmlPattern: ''), new Length(exactly: 2)],
            'expected' => [],
            'verdicts' => [['/^(?:a|aa|aaa)$/Du', 'aa', true]],
        ];
        // Regex does not check an empty value: Length(max: 1) and the
        // pattern let it through, and nothing else.
        yield 'a Length only an empty value fits' => [
            'constraints' => [new Length(max: 1), new Regex(pattern: '/^ab$/Du', htmlPattern: '')],
            'expected' => [['length.contradiction', 'error']],
            'verdicts' => [['/^ab$/Du', 'ab', true], ['/^ab$/Du', '', false]],
        ];
        // Two bytes, one character: Length counts characters.
        yield 'a Length a pattern over bytes fits' => [
            'constraints' => [new Regex(pattern: '/^.{2}$/D', htmlPattern: ''), new Length(max: 1)],
            'expected' => [],
            'verdicts' => [['/^.{2}$/D', 'é', true]],
        ];
        yield 'a Length the solver cannot compare' => [
            'constraints' => [new Regex(pattern: '/^(a)\1$/', htmlPattern: ''), new Length(max: 1)],
            'expected' => [['length.unchecked', 'notice']],
            'verdicts' => [['/^(a)\1$/', 'aa', true]],
        ];
        yield 'a Length of another group' => [
            'constraints' => [new Length(max: 3, groups: ['short']), new Regex('/^[A-Z]{5}$/')],
            'expected' => [],
            'verdicts' => [['/^[A-Z]{5}$/', 'ABCDE', true]],
        ];
        yield 'a Choice value the pattern rejects' => [
            'constraints' => [new Choice(choices: ['ok', 'NO']), new Regex('/^[a-z]+$/')],
            'expected' => [['choice.rejected', 'error']],
            'verdicts' => [['/^[a-z]+$/', 'ok', true], ['/^[a-z]+$/', 'NO', false]],
        ];
        // match: false lists the values the property refuses, not those it
        // offers.
        yield 'a Choice of refused values' => [
            'constraints' => [new Regex(pattern: '/^ok$/Du', htmlPattern: ''), new Choice(choices: ['bad'], match: false)],
            'expected' => [],
            'verdicts' => [['/^ok$/Du', 'bad', false], ['/^ok$/Du', 'ok', true]],
        ];
        // Regex does not check an empty value: Symfony accepts "".
        yield 'a Choice of the empty value' => [
            'constraints' => [new Regex(pattern: '/^a$/Du', htmlPattern: ''), new Choice(choices: [''])],
            'expected' => [],
            'verdicts' => [['/^a$/Du', '', false]],
        ];
        yield 'a Choice the pattern takes' => [
            'constraints' => [new Choice(choices: ['ok', 'yes']), new Regex('/^[a-z]+$/')],
            'expected' => [],
            'verdicts' => [['/^[a-z]+$/', 'ok', true], ['/^[a-z]+$/', 'yes', true]],
        ];
        yield 'a Choice value a match: false pattern rejects' => [
            'constraints' => [new Choice(choices: ['ok', 'n0']), new Regex(pattern: '/\d/', match: false)],
            'expected' => [['html_pattern.stricter', 'warning'], ['choice.rejected', 'error']],
            'verdicts' => [['/\d/', 'ok', false], ['/\d/', 'n0', true]],
        ];
        yield 'two patterns no value matches' => [
            'constraints' => [new Regex('/^\d+$/'), new Regex('/^[a-z]+$/')],
            'expected' => [['regex.contradiction', 'error']],
            'verdicts' => [['/^\d+$/', '1', true], ['/^\d+$/', 'a', false], ['/^[a-z]+$/', '1', false], ['/^[a-z]+$/', 'a', true]],
        ];
        yield 'two patterns some value matches' => [
            'constraints' => [new Regex('/^\w+$/'), new Regex('/^[a-z]+$/')],
            'expected' => [],
            'verdicts' => [['/^\w+$/', 'a', true], ['/^[a-z]+$/', 'a', true]],
        ];
        yield 'a pattern every value of which the other forbids' => [
            'constraints' => [new Regex('/^\d+$/'), new Regex(pattern: '/[0-9]/', match: false)],
            'expected' => [['html_pattern.stricter', 'warning'], ['regex.contradiction', 'error']],
            'verdicts' => [['/^\d+$/', '1', true], ['/[0-9]/', '1', true]],
        ];
    }

    /**
     * The linter reports a pattern PCRE refuses; the audit says it could
     * not compare it.
     */
    #[Test]
    public function test_an_invalid_pattern_is_left_to_the_linter(): void
    {
        $regex = new Regex('/[a-z/');
        $this->assertFalse(@preg_match((string) $regex->pattern, ''));

        $findings = self::audit([$regex]);

        $this->assertSame([['html_pattern.unchecked', 'notice']], array_map(static fn (array $finding): array => [$finding['check'], $finding['severity']], $findings));
    }

    /**
     * A contradiction holds on the engine: Symfony's validator finds no
     * value over the alphabet, up to the length, that passes every
     * constraint of the property. An empty value is left out: Regex
     * constraints do not check it.
     *
     * @param list<Constraint> $constraints
     */
    #[Test]
    #[DataProvider('provideContradictions')]
    public function test_a_contradiction_leaves_no_value_on_the_engine(array $constraints, string $check, string $alphabet, int $length): void
    {
        $this->assertSame([$check], self::propertyChecks(self::audit($constraints)));

        $validator = Validation::createValidator();
        $passing = [];
        foreach (self::strings($alphabet, $length) as $value) {
            if ('' !== $value && 0 === \count($validator->validate($value, $constraints))) {
                $passing[] = $value;
            }
        }

        $this->assertSame([], $passing);
    }

    /**
     * @return iterable<string, array{constraints: list<Constraint>, check: string, alphabet: string, length: int}>
     */
    public static function provideContradictions(): iterable
    {
        yield 'a Length max under every value' => ['constraints' => [new Length(max: 3), new Regex('/^[A-Z]{5}$/')], 'check' => 'length.contradiction', 'alphabet' => 'AB', 'length' => 6];
        yield 'a Length min past every value' => ['constraints' => [new Regex('/^\d{2,4}$/'), new Length(min: 10)], 'check' => 'length.contradiction', 'alphabet' => '01', 'length' => 11];
        yield 'a Length between the lengths of the pattern' => ['constraints' => [new Regex('/^(?:a|aaa)$/Du'), new Length(exactly: 2)], 'check' => 'length.contradiction', 'alphabet' => 'ab', 'length' => 4];
        yield 'a Length only an empty value fits' => ['constraints' => [new Length(max: 1), new Regex('/^ab$/Du')], 'check' => 'length.contradiction', 'alphabet' => 'ab', 'length' => 3];
        yield 'a Length no non-empty value of the pattern fits' => ['constraints' => [new Regex(pattern: '/^(?:|abcd)$/D', htmlPattern: ''), new Length(max: 3)], 'check' => 'length.contradiction', 'alphabet' => 'abcd', 'length' => 4];
        yield 'a Length in Sequentially' => ['constraints' => [new Sequentially([new NotBlank(), new Length(max: 3), new Regex('/^[A-Z]{5}$/')])], 'check' => 'length.contradiction', 'alphabet' => 'AB', 'length' => 6];
        yield 'two patterns no value matches' => ['constraints' => [new Regex('/^\d+$/'), new Regex('/^[a-z]+$/')], 'check' => 'regex.contradiction', 'alphabet' => '0a', 'length' => 4];
        yield 'a pattern every value of which the other forbids' => ['constraints' => [new Regex('/^\d+$/'), new Regex(pattern: '/[0-9]/', match: false)], 'check' => 'regex.contradiction', 'alphabet' => '0a', 'length' => 4];
    }

    /**
     * The message says the lengths the Length allows, and whether an empty
     * value, which Regex does not check, may pass both.
     *
     * @param list<Constraint> $constraints
     */
    #[Test]
    #[DataProvider('provideLengthMessages')]
    public function test_a_length_contradiction_says_what_passes(array $constraints, string $message): void
    {
        $findings = array_values(array_filter(self::audit($constraints), static fn (array $finding): bool => 'length.contradiction' === $finding['check']));

        $this->assertCount(1, $findings);
        $this->assertSame($message, $findings[0]['message']);
    }

    /**
     * @return iterable<string, array{constraints: list<Constraint>, message: string}>
     */
    public static function provideLengthMessages(): iterable
    {
        yield 'a max an empty value fits' => [
            'constraints' => [new Length(max: 1), new Regex('/^ab$/Du')],
            'message' => 'No non-empty value the pattern accepts holds at most 1 character, as the Length constraint asks: only an empty value, which Regex does not check, can pass both.',
        ];
        yield 'an exact length' => [
            'constraints' => [new Regex('/^(?:a|aaa)$/Du'), new Length(exactly: 2)],
            'message' => 'No non-empty value the pattern accepts holds exactly 2 characters, as the Length constraint asks: no value passes both.',
        ];
        yield 'a min' => [
            'constraints' => [new Regex('/^\d{2,4}$/'), new Length(min: 10)],
            'message' => 'No non-empty value the pattern accepts holds at least 10 characters, as the Length constraint asks: no value passes both.',
        ];
        yield 'a min and a max' => [
            'constraints' => [new Regex('/^[A-Z]{5}$/'), new Length(min: 1, max: 3)],
            'message' => 'No non-empty value the pattern accepts holds 1 to 3 characters, as the Length constraint asks: no value passes both.',
        ];
        yield 'a min of none' => [
            'constraints' => [new Regex('/^[A-Z]{5}$/'), new Length(min: 0, max: 3)],
            'message' => 'No non-empty value the pattern accepts holds 0 to 3 characters, as the Length constraint asks: only an empty value, which Regex does not check, can pass both.',
        ];
        yield 'a NotBlank that refuses the empty value' => [
            'constraints' => [new NotBlank(), new Length(max: 3), new Regex('/^[A-Z]{5}$/')],
            'message' => 'No non-empty value the pattern accepts holds at most 3 characters, as the Length constraint asks: no value passes both.',
        ];
        yield 'a NotBlank of another group' => [
            'constraints' => [new NotBlank(groups: ['strict']), new Length(max: 3), new Regex('/^[A-Z]{5}$/')],
            'message' => 'No non-empty value the pattern accepts holds at most 3 characters, as the Length constraint asks: only an empty value, which Regex does not check, can pass both.',
        ];
        yield 'a NotBlank with a normalizer' => [
            'constraints' => [new NotBlank(normalizer: 'trim'), new Length(max: 3), new Regex('/^[A-Z]{5}$/')],
            'message' => 'No non-empty value the pattern accepts holds at most 3 characters, as the Length constraint asks: only an empty value, which Regex does not check, can pass both.',
        ];
    }

    #[Test]
    public function test_a_length_the_solver_cannot_compare_says_so(): void
    {
        $finding = self::audit([new Regex(pattern: '/^(a)\1$/', htmlPattern: ''), new Length(max: 1)])[0];

        $this->assertStringStartsWith('The Length constraint could not be compared with the pattern: Backreferences', $finding['message']);
    }

    #[Test]
    public function test_a_choice_beside_the_empty_value_says_some_values(): void
    {
        $finding = self::audit([new Regex(pattern: '/^a$/Du', htmlPattern: ''), new Choice(choices: ['', 'b'])])[0];

        $this->assertSame('Some values the Choice constraint offers are rejected by the pattern: "b".', $finding['message']);
        $this->assertFalse(self::serverRejects(new Regex(pattern: '/^a$/Du'), ''));
    }

    /**
     * Each value a choice.rejected finding names fails the Regex
     * constraint on the engine.
     *
     * @param list<string> $choices
     * @param list<string> $rejected
     */
    #[Test]
    #[DataProvider('provideRejectedChoices')]
    public function test_a_rejected_choice_fails_the_regex_on_the_engine(array $choices, Regex $regex, array $rejected): void
    {
        $findings = self::audit([new Choice(choices: $choices), $regex]);

        $this->assertSame(['choice.rejected'], self::propertyChecks($findings));
        $message = array_column($findings, 'message', 'check')['choice.rejected'] ?? '';
        foreach ($choices as $value) {
            $this->assertSame(\in_array($value, $rejected, true), self::serverRejects($regex, $value), $value);
            $this->assertSame(\in_array($value, $rejected, true), str_contains($message, '"'.$value.'"'), $value);
        }
    }

    /**
     * @return iterable<string, array{choices: list<string>, regex: Regex, rejected: list<string>}>
     */
    public static function provideRejectedChoices(): iterable
    {
        yield 'an uppercase value' => ['choices' => ['ok', 'NO'], 'regex' => new Regex('/^[a-z]+$/'), 'rejected' => ['NO']];
        yield 'a value beside the empty one' => ['choices' => ['', 'b'], 'regex' => new Regex(pattern: '/^a$/Du', htmlPattern: ''), 'rejected' => ['b']];
        yield 'a value a match: false pattern matches' => ['choices' => ['ok', 'n0'], 'regex' => new Regex(pattern: '/\d/', match: false), 'rejected' => ['n0']];
    }

    /**
     * @param array<string, bool> $verdicts the example's verdicts, server and browser
     */
    #[Test]
    #[DataProvider('provideExamples')]
    public function test_an_example_is_one_the_server_and_the_browser_disagree_on(Regex $regex, string $check, string $example, array $verdicts): void
    {
        $findings = array_values(array_filter(self::audit([$regex]), static fn (array $finding): bool => $check === $finding['check']));

        $this->assertCount(1, $findings);
        $this->assertSame($example, $findings[0]['example'] ?? null);
        $this->assertSame($verdicts['server'], 1 === preg_match((string) $regex->pattern, $example));
        $this->assertNotSame($verdicts['server'], $verdicts['browser']);
    }

    /**
     * @return iterable<string, array{Regex, string, string, array<string, bool>}>
     */
    public static function provideExamples(): iterable
    {
        yield 'a hex digit' => [new Regex(pattern: '/^\d+$/', htmlPattern: '[0-9a-f]+'), 'html_pattern.looser', 'a', ['server' => false, 'browser' => true]];
        yield 'a space' => [new Regex(pattern: '/^[a-z ]+$/', htmlPattern: '[a-z]+'), 'html_pattern.stricter', ' ', ['server' => true, 'browser' => false]];
        yield 'two characters, three bytes' => [new Regex('/^.{2}$/'), 'html_pattern.looser', "\0\u{80}", ['server' => false, 'browser' => true]];
        yield 'one character, two bytes' => [new Regex('/^.{2}$/'), 'html_pattern.stricter', "\u{80}", ['server' => true, 'browser' => false]];
        yield 'a line separator after the prefix' => [new Regex('/^foo/'), 'html_pattern.stricter', "foo\u{2028}", ['server' => true, 'browser' => false]];
        yield 'a line separator JavaScript\'s dot refuses' => [new Regex('/\bcat\b/'), 'html_pattern.stricter', "cat\u{2028}", ['server' => true, 'browser' => false]];
    }

    #[Test]
    public function test_a_dropped_attribute_comes_with_an_html_pattern_that_matches_the_same_values(): void
    {
        $finding = self::audit([new Regex('/^[a-z0-9._%+-]+@[a-z]+$/i')])[0];

        $this->assertSame('^[a-z0-9\._\%\+\-A-Z]+@[a-zA-Z]+$', $finding['suggestion'] ?? null);
        $this->assertStringEndsWith('This htmlPattern matches the same values.', $finding['message']);
    }

    /**
     * Symfony gives no HTML pattern when the last character of the pattern
     * is not its first: flags, or a pair of delimiters. The message names
     * the flags only when the pattern has some.
     *
     * @param non-empty-string                  $message
     * @param list<array{string, string, bool}> $verdicts each pattern, subject and whether preg_match() matches
     */
    #[Test]
    #[DataProvider('provideDroppedAttributes')]
    public function test_a_dropped_attribute_says_why(string $pattern, string $message, array $verdicts): void
    {
        foreach ($verdicts as [$verdictPattern, $subject, $matches]) {
            $this->assertSame($matches ? 1 : 0, @preg_match($verdictPattern, $subject));
        }

        $regex = new Regex($pattern);
        $this->assertNull($regex->getHtmlPattern());

        $finding = self::audit([$regex])[0];

        $this->assertSame('html_pattern.dropped', $finding['check']);
        $this->assertStringStartsWith($message, $finding['message']);
    }

    /**
     * @return iterable<string, array{pattern: string, message: non-empty-string, verdicts: list<array{string, string, bool}>}>
     */
    public static function provideDroppedAttributes(): iterable
    {
        yield 'a flag' => [
            'pattern' => '/^[a-z]+$/i',
            'message' => 'The pattern has flags ("i"), so getHtmlPattern() gives no HTML pattern: the browser checks nothing.',
            'verdicts' => [['/^[a-z]+$/i', 'A', true]],
        ];
        yield 'two flags' => [
            'pattern' => '/^a$/Di',
            'message' => 'The pattern has flags ("Di"), so getHtmlPattern() gives no HTML pattern: the browser checks nothing.',
            'verdicts' => [['/^a$/Di', 'A', true]],
        ];
        yield 'a pair of delimiters' => [
            'pattern' => '{^a$}',
            'message' => 'getHtmlPattern() gives no HTML pattern, as the pattern does not end with the character it starts with: the browser checks nothing.',
            'verdicts' => [['{^a$}', 'a', true]],
        ];
    }

    #[Test]
    public function test_a_dropped_attribute_of_a_pattern_php_refuses_names_no_flag(): void
    {
        $regex = new Regex('/[a-z/i');
        $this->assertFalse(@preg_match((string) $regex->pattern, 'a'));

        $this->assertSame('getHtmlPattern() gives no HTML pattern, as the pattern does not end with the character it starts with: the browser checks nothing.', self::audit([$regex])[0]['message']);
    }

    #[Test]
    public function test_an_explicit_htmlpattern_names_what_the_comparison_cannot_read(): void
    {
        $finding = self::audit([new Regex(pattern: '/^[a-z]$/Du', htmlPattern: '[[:alpha:]]')])[0];

        $this->assertSame('A browser reads the HTML pattern under v otherwise than PCRE, which the comparison does not model: [posix_class at 1] JavaScript has no POSIX class: under v "[:alpha:]" is a nested class of the characters ":alpha:".', $finding['message']);
        $this->assertSame('[[:alpha:]]', $finding['htmlPattern'] ?? null);
    }

    #[Test]
    public function test_an_explicit_htmlpattern_a_browser_refuses_is_invalid(): void
    {
        $finding = self::audit([new Regex(pattern: '/^a$/Du', htmlPattern: 'a++')])[0];

        $this->assertSame(['html_pattern.invalid', 'error'], [$finding['check'], $finding['severity']]);
    }

    /**
     * A browser compiles the attribute alone under v before it wraps it in
     * ^(?:...)$, and checks nothing when that fails: a class or a group
     * never closed, a ")" that closes none, a "\" that ends it. PCRE
     * refuses each of them too; Node 22 throws on each:
     * "Unterminated character class", "Unmatched ')'", "Unterminated
     * group", "\ at end of pattern".
     *
     * @param non-empty-string $message
     */
    #[Test]
    #[DataProvider('provideSyntaxErrors')]
    public function test_an_html_pattern_pcre_cannot_read_is_reported_when_a_browser_refuses_it(string $html, string $message): void
    {
        $this->assertFalse(@preg_match('#'.$html.'#u', ''));
        $regex = new Regex(pattern: '/^a$/Du', htmlPattern: $html);

        $findings = self::audit([$regex]);

        $this->assertSame([['html_pattern.invalid', 'error']], array_map(static fn (array $finding): array => [$finding['check'], $finding['severity']], $findings));
        $this->assertSame('A browser refuses to compile the HTML pattern under v, and then checks nothing: '.$message, $findings[0]['message']);
    }

    /**
     * @return iterable<string, array{html: string, message: non-empty-string}>
     */
    public static function provideSyntaxErrors(): iterable
    {
        yield 'a class never closed' => ['html' => '[', 'message' => '[class at 0] Under v a class that is never closed is a syntax error.'];
        yield 'a class whose "]" is escaped' => ['html' => 'b[a\]', 'message' => '[class at 1] Under v a class that is never closed is a syntax error.'];
        yield 'a ")" that closes no group' => ['html' => 'a)(b', 'message' => '[group at 1] A ")" that closes no group is a syntax error.'];
        yield 'a group never closed' => ['html' => 'a(b(c)', 'message' => '[group at 1] A group that is never closed is a syntax error.'];
        yield 'a "(" in a class closes nothing' => ['html' => '([)]', 'message' => '[group at 0] A group that is never closed is a syntax error.'];
        yield 'a "\\" that ends the pattern' => ['html' => 'a\\', 'message' => '[escape at 1] A "\\" that ends the pattern is a syntax error.'];
    }

    /**
     * What PCRE alone refuses is not said to be refused by a browser: Node
     * 24 compiles a name two alternatives share under v. The comparison
     * says it could not run.
     */
    #[Test]
    public function test_an_html_pattern_only_pcre_refuses_stays_unchecked(): void
    {
        $regex = new Regex(pattern: '/^a$/Du', htmlPattern: '(?<n>a)|(?<n>b)');
        $this->assertFalse(@preg_match('#'.(string) $regex->htmlPattern.'#u', ''));

        $this->assertSame([['html_pattern.unchecked', 'notice']], array_map(static fn (array $finding): array => [$finding['check'], $finding['severity']], self::audit([$regex])));
    }

    #[Test]
    public function test_a_refused_attribute_comes_with_one_a_browser_compiles(): void
    {
        $finding = self::audit([new Regex('/^[a-z0-9-]+$/')])[0];

        $this->assertSame('[a-z0-9-]+', $finding['htmlPattern'] ?? null);
        $this->assertSame('^[a-z0-9\-]+$', $finding['suggestion'] ?? null);
        $this->assertStringContainsString('[class_syntax at 7] Under v a class takes "-" escaped only', $finding['message']);
    }

    /**
     * The html-pattern target drops an inert possessive, but the solver
     * reads no possessive in a partial match: the suggestion stands
     * unproved, and the message says so.
     */
    #[Test]
    public function test_an_unproved_suggestion_says_so(): void
    {
        $finding = self::audit([new Regex('/^a++b$/i')])[0];

        $this->assertSame('html_pattern.dropped', $finding['check']);
        $this->assertSame('^[aA]+[bB]$', $finding['suggestion'] ?? null);
        $this->assertStringEndsWith('the solver could not prove it matches the same values.', $finding['message']);
    }

    #[Test]
    public function test_a_finding_names_its_class_property_and_pattern(): void
    {
        $metadata = new ClassMetadata(AuditedSubject::class);
        $metadata->addGetterConstraint('code', new Regex(pattern: '/^\d+$/', htmlPattern: '[0-9a-f]+'));

        $report = (new RegexConstraintAnalyzer())->analyze([$metadata]);

        $this->assertSame(['class' => AuditedSubject::class, 'property' => 'getCode()', 'pattern' => '/^\d+$/'], array_intersect_key($report->findings[0], ['class' => true, 'property' => true, 'pattern' => true]));
        $this->assertSame(['classes' => 1, 'properties' => 1, 'regex' => 1, 'html_compared' => 1], $report->stats);
        $this->assertTrue($report->hasErrors());
        $this->assertCount(1, $report->ofSeverity('error'));
    }

    #[Test]
    public function test_a_normalizer_leaves_the_comparison_unchecked(): void
    {
        // Symfony matches strtolower("A"), so it accepts "A" though the
        // pattern alone does not match it.
        $regex = new Regex(pattern: '/^a$/Du', htmlPattern: '[Aa]', normalizer: 'strtolower');
        $this->assertSame(0, preg_match('/^a$/Du', 'A'));
        $this->assertFalse(self::serverRejects($regex, 'A'));

        $findings = self::audit([$regex]);

        $this->assertSame([['html_pattern.unchecked', 'notice']], array_map(static fn (array $finding): array => [$finding['check'], $finding['severity']], $findings));
        $this->assertSame('The server normalizes the value before it matches the pattern, which the audit does not model: the HTML pattern was not compared with the pattern.', $findings[0]['message']);
        $this->assertSame('[Aa]', $findings[0]['htmlPattern'] ?? null);
    }

    /**
     * A normalizer leaves the syntax of the HTML pattern to check, and a
     * suggestion unproved.
     */
    #[Test]
    public function test_a_normalizer_keeps_the_syntax_checks_and_proves_no_suggestion(): void
    {
        $this->assertSame([['html_pattern.invalid', 'error']], array_map(static fn (array $finding): array => [$finding['check'], $finding['severity']], self::audit([new Regex(pattern: '/^a++$/', normalizer: 'trim')])));

        $finding = self::audit([new Regex(pattern: '/^[a-z]+$/i', normalizer: 'trim')])[0];

        $this->assertSame('html_pattern.dropped', $finding['check']);
        $this->assertSame('^[a-zA-Z]+$', $finding['suggestion'] ?? null);
        $this->assertStringEndsWith(' The html-pattern target writes this htmlPattern; the server normalizes the value first, which the audit does not model.', $finding['message']);
    }

    /**
     * The browser reads \w, \d and \b over ASCII, as JavaScript does even
     * under v, though the server reads them over Unicode under u.
     */
    #[Test]
    public function test_the_browser_reads_its_shorthands_over_ascii(): void
    {
        $this->assertSame('/^(?:[A-Za-z0-9_][0-9][^\n\r\x{2028}\x{2029}])$/Du', RegexConstraintAnalyzer::browserPattern('\w\d.'));
        $this->assertSame('/^(?:[^\n\r0-9])$/Du', RegexConstraintAnalyzer::browserPattern('[^0-9]'));
        $this->assertSame('/^(?:a\/b\/)$/Du', RegexConstraintAnalyzer::browserPattern('a/b\/'));
        $this->assertSame('/^(?:\/#~%@!,;`=)$/Du', RegexConstraintAnalyzer::browserPattern('/#~%@!,;`='));

        $finding = self::audit([new Regex(pattern: '/^\w+$/u', htmlPattern: '\w+')])[0];
        $this->assertSame('html_pattern.stricter', $finding['check']);
        $this->assertSame('ª', $finding['example'] ?? null);
    }

    /**
     * Over bytes, the values a match: false pattern does not match and the
     * browser rejects are not searched: the notice says which direction.
     */
    #[Test]
    public function test_an_unsearched_direction_is_named(): void
    {
        $finding = self::audit([new Regex(pattern: '/^.$/D', htmlPattern: '.{2}', match: false)])[0];

        $this->assertSame('The pattern reads bytes and the browser code points: no value the server accepts and the browser rejects was searched for.', $finding['message']);
        $this->assertSame('.{2}', $finding['htmlPattern'] ?? null);
    }

    /**
     * The value a match: false pattern does not match and the browser
     * rejects is one an input holds: not the empty value, which neither
     * side checks. Node 22 rejects "\0" with ^(?:b+)$ under v.
     */
    #[Test]
    public function test_a_match_false_comparison_skips_the_empty_value(): void
    {
        $this->assertSame(0, preg_match('/a/u', "\0"));

        $finding = self::audit([new Regex(pattern: '/a/u', htmlPattern: 'b+', match: false)])[0];

        $this->assertSame('html_pattern.stricter', $finding['check']);
        $this->assertSame("\0", $finding['example'] ?? null);
    }

    /**
     * The audit reads an HTML pattern with PCRE between delimiters it
     * does not write: one that writes all of them is not checked, and the
     * audit says so rather than report nothing.
     */
    #[Test]
    public function test_an_html_pattern_writing_every_delimiter_is_reported_unchecked(): void
    {
        $findings = self::audit([new Regex(pattern: '/^x$/', htmlPattern: '/#~%@!,;`=')]);

        $this->assertSame([['html_pattern.unchecked', 'notice']], array_map(static fn (array $finding): array => [$finding['check'], $finding['severity']], $findings));
        $this->assertSame('The HTML pattern writes every delimiter PCRE could read it between (/#~%@!,;`=): how a browser reads it was not checked.', $findings[0]['message']);
        $this->assertSame('/#~%@!,;`=', $findings[0]['htmlPattern'] ?? null);
    }

    /**
     * @param list<Constraint> $constraints
     *
     * @return list<array<string, string>>
     */
    private static function audit(array $constraints): array
    {
        $metadata = new ClassMetadata(AuditedSubject::class);
        foreach ($constraints as $constraint) {
            $metadata->addPropertyConstraint('value', $constraint);
        }

        return (new RegexConstraintAnalyzer())->analyze([$metadata])->findings;
    }

    /**
     * The checks of the findings on the constraints of the property, not
     * on an HTML pattern.
     *
     * @param list<array<string, string>> $findings
     *
     * @return list<string>
     */
    private static function propertyChecks(array $findings): array
    {
        return array_values(array_filter(array_column($findings, 'check'), static fn (string $check): bool => !str_starts_with($check, 'html_pattern.')));
    }

    /**
     * Whether Symfony's validator rejects the value under the constraint.
     */
    private static function serverRejects(Regex $regex, string $value): bool
    {
        return \count(Validation::createValidator()->validate($value, $regex)) > 0;
    }

    /**
     * @param list<Constraint> $constraints
     */
    private static function regexOf(array $constraints, string $pattern): Regex
    {
        foreach ($constraints as $constraint) {
            if ($constraint instanceof Regex && $pattern === $constraint->pattern) {
                return $constraint;
            }
        }

        throw new \LogicException('No Regex constraint writes '.$pattern.'.');
    }

    /**
     * Every string over the alphabet, up to the length.
     *
     * @return \Generator<int, string>
     */
    private static function strings(string $alphabet, int $length): \Generator
    {
        $level = [''];
        for ($size = 0; $size <= $length; $size++) {
            yield from $level;
            $next = [];
            foreach ($level as $prefix) {
                foreach (str_split($alphabet) as $char) {
                    $next[] = $prefix.$char;
                }
            }

            $level = $next;
        }
    }
}

/**
 * @internal
 */
final class AuditedSubject
{
    public string $value = '';

    public function getCode(): string
    {
        return '';
    }
}
