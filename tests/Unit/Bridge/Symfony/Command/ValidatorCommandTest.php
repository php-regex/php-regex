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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Command;

use PHPRegex\Symfony\Command\ValidatorCommand;
use PHPRegex\Symfony\Validator\ValidatedClassLocator;
use PHPRegex\Tests\Fixtures\Symfony\ValidatorAudit\src\Entity\Signup;
use PHPRegex\Tests\Support\JsonContract;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Validator\Validation;

/**
 * regex:validator prints the audit of the Regex constraints as
 * regex:analyze prints its validator section, and fails on an error.
 */
final class ValidatorCommandTest extends TestCase
{
    #[Test]
    public function test_the_audit_of_a_class(): void
    {
        $tester = new CommandTester(self::command());

        $status = $tester->execute(['classes' => [Signup::class]], ['decorated' => false]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $status);
        foreach (['Validator constraints', 'Signup::$email html_pattern.dropped', 'Signup::$slug html_pattern.invalid', 'Signup::$code html_pattern.looser', 'Signup::$ticker length.contradiction', 'Signup::$tier choice.rejected', 'Signup::$pin regex.contradiction', '^[a-z0-9\-]+$'] as $expected) {
            $this->assertStringContainsString($expected, $display);
        }

        $this->assertStringNotContainsString('$zip', $display);
        $this->assertStringNotContainsString('$nickname', $display);
    }

    #[Test]
    public function test_the_audit_as_json(): void
    {
        $tester = new CommandTester(self::command());

        $tester->execute(['classes' => [Signup::class], '--format' => 'json']);
        $sections = JsonContract::asArray(JsonContract::decodeDocument($tester->getDisplay())['sections'] ?? null);
        $section = JsonContract::asArray($sections[0] ?? null);

        $this->assertSame('validator', $section['id'] ?? null);
        $this->assertCount(6, JsonContract::asArray($section['issues'] ?? null));
    }

    /**
     * A class that could not be read is named at any verbosity, and the
     * audit says it is incomplete rather than that it found nothing.
     */
    #[Test]
    public function test_the_classes_it_could_not_read_are_named(): void
    {
        $tester = new CommandTester(self::command());

        $tester->execute(['classes' => ['App\Missing']], ['decorated' => false]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringNotContainsString('No Regex constraint found.', $tester->getDisplay());
        $this->assertStringContainsString('The audit is incomplete: 1 class or mapping file could not be read.', $tester->getDisplay());
        // Notices show a backslash escaped.
        $this->assertStringContainsString('App\\\\Missing: the class does not exist.', $tester->getDisplay());
    }

    #[Test]
    public function test_verbose_output_adds_the_counts(): void
    {
        $tester = new CommandTester(self::command());

        $tester->execute(['classes' => [Signup::class]], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE, 'decorated' => false]);

        $this->assertStringContainsString('HTML patterns compared', $tester->getDisplay());
    }

    #[Test]
    public function test_without_a_validator_the_command_cannot_run(): void
    {
        $tester = new CommandTester(new ValidatorCommand(new ValidatedClassLocator(null)));

        $this->assertSame(Command::INVALID, $tester->execute([]));
        $this->assertStringContainsString('Validator service is not available.', $tester->getDisplay());
    }

    /**
     * The help says a suggested htmlPattern is proved only when the solver
     * proves it, and that the command needs the validator service.
     */
    #[Test]
    public function test_the_help_says_what_a_suggestion_promises(): void
    {
        $help = self::command()->getHelp();

        $this->assertStringContainsString("a suggested htmlPattern, and whether the\n    solver proved it matches the same values", $help);
        $this->assertStringContainsString('It needs the validator service of Symfony Validator, and exits with code 2 without it.', $help);
        $this->assertStringContainsString('a Length no non-empty value of the pattern fits', $help);
    }

    #[Test]
    public function test_an_unknown_format_is_refused(): void
    {
        $tester = new CommandTester(self::command());

        $this->assertSame(Command::INVALID, $tester->execute(['--format' => 'xml']));
        $this->assertStringContainsString('The --format value must be one of: console, json.', $tester->getDisplay());
    }

    private static function command(): ValidatorCommand
    {
        return new ValidatorCommand(new ValidatedClassLocator(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()));
    }
}
