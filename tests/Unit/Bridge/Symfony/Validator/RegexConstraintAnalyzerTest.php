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
     * @param list<Constraint>   $constraints
     * @param list<list<string>> $expected    each finding's check and severity
     */
    #[Test]
    #[DataProvider('provideProperties')]
    public function test_the_findings_of_a_property(array $constraints, array $expected): void
    {
        $findings = self::audit($constraints);

        $this->assertSame($expected, array_map(static fn (array $finding): array => [$finding['check'], $finding['severity']], $findings));
    }

    /**
     * @return iterable<string, array{list<Constraint>, list<list<string>>}>
     */
    public static function provideProperties(): iterable
    {
        yield 'anchored digits read alike' => [[new Regex('/^\d+$/')], []];
        yield 'an unanchored pattern the derivation wraps in .*' => [[new Regex('/^foo/')], [['html_pattern.stricter', 'warning']]];
        yield 'an alternation the derivation wraps whole' => [[new Regex('/^(foo|bar)$/')], []];
        yield 'an explicit htmlPattern that agrees' => [[new Regex(pattern: '/^\d+$/', htmlPattern: '[0-9]+')], []];
        yield 'an empty htmlPattern turns the attribute off' => [[new Regex(pattern: '/^a$/i', htmlPattern: '')], []];
        yield 'a pattern that must not match, derived alike' => [[new Regex(pattern: '/\d/', match: false)], []];
        yield 'flags drop the attribute' => [[new Regex('/^[a-z]+$/i')], [['html_pattern.dropped', 'warning']]];
        yield 'a dash a class under v refuses' => [[new Regex('/^[a-z0-9-]+$/')], [['html_pattern.invalid', 'error']]];
        yield 'a possessive a browser refuses' => [[new Regex('/^a++b$/')], [['html_pattern.invalid', 'error']]];
        yield 'a POSIX class a browser misreads' => [[new Regex('/^[[:alpha:]]+$/')], [['html_pattern.misread', 'error']]];
        yield 'an htmlPattern that lets more through' => [[new Regex(pattern: '/^\d+$/', htmlPattern: '[0-9a-f]+')], [['html_pattern.looser', 'error']]];
        yield 'an htmlPattern that lets less through' => [[new Regex(pattern: '/^[a-z ]+$/', htmlPattern: '[a-z]+')], [['html_pattern.stricter', 'warning']]];
        yield 'bytes on the server, code points in the browser' => [[new Regex('/^.{2}$/')], [['html_pattern.looser', 'error'], ['html_pattern.stricter', 'warning']]];
        yield 'a Length no value of the pattern fits' => [[new Length(max: 3), new Regex('/^[A-Z]{5}$/')], [['length.contradiction', 'error']]];
        yield 'a Length min past every value' => [[new Regex('/^\d{2,4}$/'), new Length(min: 10)], [['length.contradiction', 'error']]];
        yield 'a Length the pattern fits' => [[new Regex('/^\d{2,4}$/'), new Length(min: 2, max: 4)], []];
        yield 'a Length in Sequentially' => [[new Sequentially([new NotBlank(), new Length(max: 3), new Regex('/^[A-Z]{5}$/')])], [['length.contradiction', 'error']]];
        yield 'a Length of another group' => [[new Length(max: 3, groups: ['short']), new Regex('/^[A-Z]{5}$/')], []];
        yield 'a Choice value the pattern rejects' => [[new Choice(choices: ['ok', 'NO']), new Regex('/^[a-z]+$/')], [['choice.rejected', 'error']]];
        yield 'a Choice the pattern takes' => [[new Choice(choices: ['ok', 'yes']), new Regex('/^[a-z]+$/')], []];
        yield 'a Choice value a match: false pattern rejects' => [[new Choice(choices: ['ok', 'n0']), new Regex(pattern: '/\d/', match: false)], [['choice.rejected', 'error']]];
        yield 'two patterns no value matches' => [[new Regex('/^\d+$/'), new Regex('/^[a-z]+$/')], [['regex.contradiction', 'error']]];
        yield 'two patterns some value matches' => [[new Regex('/^\w+$/'), new Regex('/^[a-z]+$/')], []];
        yield 'a pattern every value of which the other forbids' => [[new Regex('/^\d+$/'), new Regex(pattern: '/[0-9]/', match: false)], [['regex.contradiction', 'error']]];
        yield 'an invalid pattern is left to the linter' => [[new Regex('/[a-z/')], [['html_pattern.unchecked', 'notice']]];
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
    public function test_a_normalizer_is_named_in_the_message(): void
    {
        $finding = self::audit([new Regex(pattern: '/^\d+$/', htmlPattern: '[0-9a-f]+', normalizer: 'trim')])[0];

        $this->assertStringEndsWith('The server normalizes the value first; the browser does not.', $finding['message']);
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
        $this->assertSame('#^(?:[a/])$#Du', RegexConstraintAnalyzer::browserPattern('[a/]'));
        $this->assertNull(RegexConstraintAnalyzer::browserPattern('/#~%@!,;`='));

        $finding = self::audit([new Regex(pattern: '/^\w+$/u', htmlPattern: '\w+')])[0];
        $this->assertSame('html_pattern.stricter', $finding['check']);
        $this->assertSame('ª', $finding['example'] ?? null);
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
