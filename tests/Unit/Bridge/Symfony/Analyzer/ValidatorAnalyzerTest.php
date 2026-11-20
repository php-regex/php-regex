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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Analyzer;

use PHPRegex\Symfony\Analyzer\AnalysisContext;
use PHPRegex\Symfony\Analyzer\AnalysisIssue;
use PHPRegex\Symfony\Analyzer\AnalysisNotice;
use PHPRegex\Symfony\Analyzer\CheckOutcome;
use PHPRegex\Symfony\Analyzer\Formatter\ConsoleReportFormatter;
use PHPRegex\Symfony\Analyzer\Formatter\JsonReportFormatter;
use PHPRegex\Symfony\Analyzer\ValidatorAnalyzer;
use PHPRegex\Symfony\Command\AnalyzeCommand;
use PHPRegex\Symfony\Validator\RegexConstraintAnalyzer;
use PHPRegex\Symfony\Validator\RegexConstraintReport;
use PHPRegex\Symfony\Validator\ValidatedClassLocator;
use PHPRegex\Tests\Fixtures\Symfony\ValidatorAudit\src\Entity\Signup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Validator\Validation;

/**
 * The validator section of regex:analyze: an error of the audit fails, a
 * warning warns, a notice is a warning of the section, and the section
 * reads the classes of the project's src/ when none is given.
 *
 * @phpstan-import-type ValidatorFinding from RegexConstraintAnalyzer
 */
final class ValidatorAnalyzerTest extends TestCase
{
    private const PROJECT = __DIR__.'/../../../../Fixtures/Symfony/ValidatorAudit';

    #[Test]
    public function test_the_section_of_the_project_classes(): void
    {
        $sections = self::analyzer()->analyze(new AnalysisContext(self::PROJECT, 'test'));

        $this->assertCount(1, $sections);
        $section = $sections[0];
        $this->assertSame('validator', $section->id);
        $this->assertSame(['Classes' => 1, 'Properties' => 8, 'Regex constraints' => 9, 'Errors' => 5], $section->meta);
        $this->assertSame([
            ['Signup::$email html_pattern.dropped', CheckOutcome::Warn],
            ['Signup::$slug html_pattern.invalid', CheckOutcome::Fail],
            ['Signup::$code html_pattern.looser', CheckOutcome::Fail],
            ['Signup::$ticker length.contradiction', CheckOutcome::Fail],
            ['Signup::$tier choice.rejected', CheckOutcome::Fail],
            ['Signup::$pin regex.contradiction', CheckOutcome::Fail],
        ], array_map(static fn (AnalysisIssue $issue): array => [$issue->title, $issue->severity], $section->issues));
        $this->assertSame(['validator'], array_values(array_unique(array_map(static fn (AnalysisIssue $issue): string => $issue->kind, $section->issues))));
        $this->assertCount(3, $section->suggestions);
        $this->assertSame([], $section->warnings);
    }

    #[Test]
    public function test_an_issue_carries_the_property_patterns_example_and_suggestion(): void
    {
        $issue = self::analyzer()->analyze(new AnalysisContext(self::PROJECT, 'test'))[0]->issues[2];

        $this->assertSame([
            ['Property', Signup::class.'::$code', 'text'],
            ['Pattern', '/^\d+$/', 'pattern'],
            ['HTML pattern', '[0-9a-f]+', 'pattern'],
            ['Example', 'a', 'example'],
            ['Suggested htmlPattern', '^\d+$', 'pattern'],
        ], array_map(static fn ($detail): array => [$detail->label, $detail->value, $detail->kind], $issue->details));
        $this->assertSame(['The browser accepts "a", which the server rejects.'], $issue->notes);
    }

    #[Test]
    public function test_without_a_validator_the_section_says_so(): void
    {
        $section = (new ValidatorAnalyzer(new ValidatedClassLocator(null)))->analyze(new AnalysisContext(self::PROJECT, 'test'))[0];

        $this->assertSame([], $section->issues);
        $this->assertSame(CheckOutcome::Warn, $section->summary[0]->severity);
    }

    /**
     * regex:analyze runs the section with the others; its issues fail the
     * command when --fail-on names them, as they are not among the kinds
     * that fail by default.
     */
    #[Test]
    public function test_regex_analyze_fails_on_the_validator_kind_when_asked(): void
    {
        // Without a kernel the project is the working directory: the class is named.
        $analyzer = new ValidatorAnalyzer(new ValidatedClassLocator(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()), classes: [Signup::class]);
        $command = new AnalyzeCommand([$analyzer], new ConsoleReportFormatter(), new JsonReportFormatter());

        $tester = new CommandTester($command);
        $this->assertSame(Command::SUCCESS, $tester->execute(['--only' => ['validator'], '--format' => 'json']));
        $this->assertSame(Command::FAILURE, $tester->execute(['--only' => ['validator'], '--format' => 'json', '--fail-on' => ['validator']]));
        $this->assertStringContainsString('"kind": "validator"', $tester->getDisplay());
    }

    /**
     * The summary says the audit is incomplete when a class or a mapping
     * file could not be read, and warns rather than passes when a check
     * could not be made; each reason is a warning at any verbosity.
     *
     * @param list<ValidatorFinding>            $findings
     * @param list<string>                      $unread
     * @param list<array{CheckOutcome, string}> $summary
     * @param list<string>                      $warnings
     */
    #[Test]
    #[DataProvider('provideSummaries')]
    public function test_the_summary_of_the_section(array $findings, int $regex, array $unread, array $summary, array $warnings): void
    {
        $report = new RegexConstraintReport($findings, ['classes' => 1, 'properties' => $regex, 'regex' => $regex, 'html_compared' => 0]);

        $section = (new ValidatorAnalyzer(new ValidatedClassLocator(null)))->section($report, $unread);

        $this->assertSame($summary, array_map(static fn (AnalysisNotice $notice): array => [$notice->severity, $notice->message], $section->summary));
        $this->assertSame($warnings, array_map(static fn (AnalysisNotice $notice): string => $notice->message, $section->warnings));
        foreach ($section->warnings as $warning) {
            $this->assertSame(CheckOutcome::Warn, $warning->severity);
        }

        $this->assertSame([], $section->debug);
    }

    /**
     * @return iterable<string, array{findings: list<ValidatorFinding>, regex: int, unread: list<string>, summary: list<array{CheckOutcome, string}>, warnings: list<string>}>
     */
    public static function provideSummaries(): iterable
    {
        $notice = ['class' => 'App\Signup', 'property' => '$code', 'pattern' => '/[a-z/', 'check' => 'html_pattern.unchecked', 'severity' => 'notice', 'message' => 'The HTML pattern could not be compared with the pattern.'];
        $error = ['class' => 'App\Signup', 'property' => '$code', 'pattern' => '/^\d+$/', 'check' => 'html_pattern.looser', 'severity' => 'error', 'message' => 'The browser accepts "a", which the server rejects.'];

        yield 'nothing to read' => ['findings' => [], 'regex' => 0, 'unread' => [], 'summary' => [[CheckOutcome::Pass, 'No Regex constraint found.']], 'warnings' => []];
        yield 'a class not found' => [
            'findings' => [],
            'regex' => 0,
            'unread' => ['App\Missing: the class does not exist.'],
            'summary' => [[CheckOutcome::Warn, 'The audit is incomplete: 1 class or mapping file could not be read.']],
            'warnings' => ['App\Missing: the class does not exist.'],
        ];
        yield 'agreement' => ['findings' => [], 'regex' => 1, 'unread' => [], 'summary' => [[CheckOutcome::Pass, 'Every Regex constraint agrees with its HTML pattern and its property.']], 'warnings' => []];
        yield 'agreement, and two files not read' => [
            'findings' => [],
            'regex' => 1,
            'unread' => ['config/validator/a.yaml: The file is not YAML.', 'config/validator/b.xml: The file is not XML.'],
            'summary' => [[CheckOutcome::Warn, 'The audit is incomplete: 2 classes or mapping files could not be read.']],
            'warnings' => ['config/validator/a.yaml: The file is not YAML.', 'config/validator/b.xml: The file is not XML.'],
        ];
        yield 'a check not made' => [
            'findings' => [$notice],
            'regex' => 1,
            'unread' => [],
            'summary' => [[CheckOutcome::Warn, 'No finding on 1 Regex constraint, but 1 check could not be made.']],
            'warnings' => ['App\Signup::$code /[a-z/: The HTML pattern could not be compared with the pattern.'],
        ];
        yield 'two checks not made' => [
            'findings' => [$notice, $notice],
            'regex' => 2,
            'unread' => [],
            'summary' => [[CheckOutcome::Warn, 'No finding on 2 Regex constraints, but 2 checks could not be made.']],
            'warnings' => ['App\Signup::$code /[a-z/: The HTML pattern could not be compared with the pattern.', 'App\Signup::$code /[a-z/: The HTML pattern could not be compared with the pattern.'],
        ];
        yield 'an error, a check not made, a class not found' => [
            'findings' => [$error, $notice],
            'regex' => 2,
            'unread' => ['App\Missing: the class does not exist.'],
            'summary' => [[CheckOutcome::Warn, 'The audit is incomplete: 1 class or mapping file could not be read.'], [CheckOutcome::Fail, '1 finding on 2 Regex constraints.']],
            'warnings' => ['App\Signup::$code /[a-z/: The HTML pattern could not be compared with the pattern.', 'App\Missing: the class does not exist.'],
        ];
    }

    private static function analyzer(): ValidatorAnalyzer
    {
        return new ValidatorAnalyzer(new ValidatedClassLocator(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()));
    }
}
