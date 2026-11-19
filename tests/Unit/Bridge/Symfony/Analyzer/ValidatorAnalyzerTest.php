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
use PHPRegex\Symfony\Analyzer\CheckOutcome;
use PHPRegex\Symfony\Analyzer\Formatter\ConsoleReportFormatter;
use PHPRegex\Symfony\Analyzer\Formatter\JsonReportFormatter;
use PHPRegex\Symfony\Analyzer\ValidatorAnalyzer;
use PHPRegex\Symfony\Command\AnalyzeCommand;
use PHPRegex\Symfony\Validator\ValidatedClassLocator;
use PHPRegex\Tests\Fixtures\Symfony\ValidatorAudit\src\Entity\Signup;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Validator\Validation;

/**
 * The validator section of regex:analyze: an error of the audit fails, a
 * warning warns, a notice is a warning of the section, and the section
 * reads the classes of the project's src/ when none is given.
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

    private static function analyzer(): ValidatorAnalyzer
    {
        return new ValidatorAnalyzer(new ValidatedClassLocator(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()));
    }
}
