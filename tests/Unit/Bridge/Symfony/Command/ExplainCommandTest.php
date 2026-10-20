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

use PHPRegex\Symfony\Command\ExplainCommand;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * regex:explain takes what the Laravel command and `regex explain` take:
 * the pattern, --format console|html and --highlight.
 */
final class ExplainCommandTest extends TestCase
{
    #[Test]
    public function test_the_pattern_is_explained(): void
    {
        $tester = new CommandTester(new ExplainCommand(Regex::create()));

        $this->assertSame(Command::SUCCESS, $tester->execute(['pattern' => '/^[a-z]+$/']));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Pattern', $display);
        $this->assertStringContainsString('Explanation', $display);
        $this->assertStringContainsString('Anchor: the start of the subject', $display);
    }

    #[Test]
    public function test_the_html_format_writes_the_html_explanation(): void
    {
        $tester = new CommandTester(new ExplainCommand(Regex::create()));

        $this->assertSame(Command::SUCCESS, $tester->execute(['pattern' => '/^[a-z]+$/', '--format' => 'html']));
        $this->assertStringContainsString('<', $tester->getDisplay());
        $this->assertStringContainsString('class=', $tester->getDisplay());
    }

    #[Test]
    public function test_an_unknown_format_is_a_usage_error(): void
    {
        $tester = new CommandTester(new ExplainCommand(Regex::create()));

        $this->assertSame(Command::INVALID, $tester->execute(['pattern' => '/a/', '--format' => 'text']));
        $this->assertStringContainsString("Invalid format 'text'. Supported formats: console, html", $tester->getDisplay());
    }

    #[Test]
    public function test_an_invalid_pattern_fails_with_its_error(): void
    {
        $tester = new CommandTester(new ExplainCommand(Regex::create()));

        $this->assertSame(Command::FAILURE, $tester->execute(['pattern' => '/^(unclosed/']));
        $this->assertStringContainsString('Invalid regex pattern', $tester->getDisplay());
    }

    #[Test]
    public function test_a_pattern_over_the_limits_is_not_analyzed(): void
    {
        $tester = new CommandTester(new ExplainCommand(Regex::create(['max_pattern_length' => 4])));

        $this->assertSame(Command::FAILURE, $tester->execute(['pattern' => '/abcdefgh/']));
        $this->assertStringContainsString('Pattern not analyzed', $tester->getDisplay());
    }

    #[Test]
    public function test_the_optimization_and_the_redos_risk_are_shown_beside_the_explanation(): void
    {
        $tester = new CommandTester(new ExplainCommand(Regex::create()));

        $this->assertSame(Command::SUCCESS, $tester->execute(['pattern' => '/[0-9]+/']));
        $this->assertStringContainsString('Optimization Suggestion', $tester->getDisplay());
        $this->assertStringContainsString('/\d+/', $tester->getDisplay());

        $this->assertSame(Command::SUCCESS, $tester->execute(['pattern' => '/(a+)+$/']));
        $this->assertStringContainsString('Security Warning', $tester->getDisplay());
    }

    #[Test]
    public function test_the_pattern_is_highlighted_on_a_decorated_output_only(): void
    {
        $tester = new CommandTester(new ExplainCommand(Regex::create()));

        $tester->execute(['pattern' => '/a+/', '--highlight' => true], ['decorated' => true]);
        $this->assertStringContainsString("\e[", $tester->getDisplay());

        $tester->execute(['pattern' => '/a+/', '--highlight' => true], ['decorated' => false]);
        $this->assertStringNotContainsString("\e[", $tester->getDisplay());
        $this->assertStringContainsString('  /a+/', $tester->getDisplay());
    }

    /**
     * (*FAIL) is valid and matches nothing: there is no sample to show.
     */
    #[Test]
    public function test_a_pattern_without_a_sample_says_so(): void
    {
        $tester = new CommandTester(new ExplainCommand(Regex::create()));

        $this->assertSame(Command::SUCCESS, $tester->execute(['pattern' => '/(*FAIL)/']));
        $this->assertStringContainsString('(unable to generate sample)', $tester->getDisplay());
    }

    #[Test]
    public function test_a_pattern_is_written_as_text_never_read_for_console_tags(): void
    {
        $tester = new CommandTester(new ExplainCommand(Regex::create()));

        $this->assertSame(Command::SUCCESS, $tester->execute(['pattern' => '/<info>/', '--highlight' => true]));
        $this->assertStringContainsString('/<info>/', $tester->getDisplay());
    }
}
