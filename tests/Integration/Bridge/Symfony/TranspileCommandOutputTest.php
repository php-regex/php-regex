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

namespace PHPRegex\Tests\Integration\Bridge\Symfony;

use PHPRegex\Symfony\Command\TranspileCommand;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * regex:transpile on the console, and the JSON document where the run stops
 * before the pattern is read.
 */
final class TranspileCommandOutputTest extends TestCase
{
    #[Test]
    public function test_console_prints_the_translation(): void
    {
        [$status, $display] = $this->transpile(['pattern' => '/a+b/i', '--target' => 'javascript']);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Transpilation Result', $display);
        $this->assertStringContainsString('new RegExp("a+b", "i")', $display);
    }

    /**
     * The warnings and the notes of a translation each get their block, and
     * a translation without one prints no empty block.
     */
    #[Test]
    #[DataProvider('provideTranslationRemarks')]
    public function test_console_prints_the_warnings_and_the_notes(string $pattern, string $block, string $remark, string $absentBlock): void
    {
        [$status, $display] = $this->transpile(['pattern' => $pattern, '--target' => 'javascript']);

        $this->assertSame(0, $status);
        $this->assertStringContainsString($block, $display);
        $this->assertStringContainsString($remark, $display);
        $this->assertStringNotContainsString($absentBlock, $display);
    }

    /**
     * @return iterable<string, array{pattern: string, block: string, remark: string, absentBlock: string}>
     */
    public static function provideTranslationRemarks(): iterable
    {
        yield 'an octal escape converted, a warning' => [
            'pattern' => '/\o{101}/',
            'block' => '[WARNING]',
            'remark' => 'Converted octal escape to hex/Unicode escape for JavaScript.',
            'absentBlock' => '[NOTE]',
        ];
        yield 'extended mode applied, a note' => [
            'pattern' => '/a b/x',
            'block' => '[NOTE]',
            'remark' => 'Applied /x (extended mode)',
            'absentBlock' => '[WARNING]',
        ];
    }

    /**
     * Called from code with an ArrayInput, the pattern argument may hold a
     * value that is no string: a usage error, the envelope in JSON mode.
     */
    #[Test]
    #[DataProvider('provideFormats')]
    public function test_a_pattern_that_is_no_string_is_a_usage_error(string $format): void
    {
        $tester = new CommandTester(new TranspileCommand(Regex::create()));
        $status = $tester->execute(['pattern' => ['/a/'], '--format' => $format]);

        $this->assertSame(2, $status);
        $display = $tester->getDisplay();
        if ('json' === $format) {
            $this->assertStringEndsWith("}\n", $display);
            $this->assertSame(['error' => 'Pattern must be a string.', 'stage' => 'usage'], json_decode($display, true, flags: \JSON_THROW_ON_ERROR));

            return;
        }

        $this->assertStringContainsString('Pattern must be a string.', $display);
        $this->assertNull(json_decode($display, true), $display);
    }

    /**
     * @return iterable<string, array{format: string}>
     */
    public static function provideFormats(): iterable
    {
        yield 'console' => ['format' => 'console'];
        yield 'json' => ['format' => 'json'];
    }

    #[Test]
    public function test_console_prints_the_message_and_the_caret_snippet(): void
    {
        [$status, $display] = $this->transpile(['pattern' => '/(?<=a+)b/']);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Lookbehind is unbounded. PCRE requires a bounded maximum length.', $display);
        $this->assertStringContainsString('Line 1: (?<=a+)b', $display);
    }

    #[Test]
    public function test_console_reports_a_construct_the_target_lacks(): void
    {
        [$status, $display] = $this->transpile(['pattern' => '/a++b/', '--target' => 'javascript']);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Possessive quantifiers are not supported in JavaScript.', $display);
    }

    #[Test]
    public function test_console_reports_an_unknown_target(): void
    {
        [$status, $display] = $this->transpile(['pattern' => '/a/', '--target' => 'perl']);

        $this->assertSame(2, $status);
        $this->assertStringContainsString('perl', $display);
    }

    #[Test]
    public function test_an_unknown_format_is_a_usage_error(): void
    {
        // As the CLI: `regex transpile /a/ --format=xml` is a usage error, not a console run.
        [$status, $display] = $this->transpile(['pattern' => '/a/', '--format' => 'xml']);

        $this->assertSame(2, $status);
        $this->assertStringContainsString('xml', $display);
        $this->assertStringNotContainsString('Target', $display);
    }

    #[Test]
    public function test_json_unknown_target_prints_the_usage_envelope(): void
    {
        [$status, $display] = $this->transpile(['pattern' => '/a/', '--target' => 'perl', '--format' => 'json']);

        $this->assertSame(2, $status);
        $this->assertStringEndsWith("}\n", $display);
        $document = json_decode($display, true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($document);
        $this->assertSame(['error', 'stage'], array_keys($document));
        $this->assertSame('usage', $document['stage']);
    }

    #[Test]
    public function test_json_document_is_printed_under_quiet(): void
    {
        [$status, $display] = $this->transpile(['pattern' => '/<b>a+/', '--target' => 'javascript', '--format' => 'json'], OutputInterface::VERBOSITY_QUIET);

        $this->assertSame(0, $status);
        $document = json_decode($display, true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($document);
        $this->assertSame('/<b>a+/', $document['source'] ?? null);
    }

    /**
     * @param array<string, string> $input
     *
     * @return array{int, string}
     */
    private function transpile(array $input, int $verbosity = OutputInterface::VERBOSITY_NORMAL): array
    {
        $tester = new CommandTester(new TranspileCommand(Regex::create()));
        $status = $tester->execute($input, ['verbosity' => $verbosity]);

        return [$status, $tester->getDisplay()];
    }
}
