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

namespace PHPRegex\Tests\Functional\Cli;

use PHPRegex\Tests\Support\RunsInAnEmptyProject;
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "regex transpile" on the console: the warnings and the notes of the
 * translation under their own headings, and a construct the target lacks
 * refused with its offset.
 */
final class TranspileConsoleTest extends TestCase
{
    use RunsInAnEmptyProject;
    use RunsRegexCli;

    #[Test]
    public function test_transpile_lists_the_warnings_then_the_notes(): void
    {
        // Oracle: "\77" is the octal escape of "?", "\h" a horizontal space.
        $this->assertSame(1, preg_match('/\77\h/m', 'a? b'));
        // Without u "\h" takes the byte 0xA0, where the target takes U+00A0, two bytes.
        $this->assertSame(1, preg_match('/\77\h/m', "?\xA0"));
        $this->assertSame(0, preg_match('/\77\h/m', "?\u{A0}"));
        $this->assertSame(1, preg_match('/\77\h/mu', "?\u{A0}"));

        [$code, $stdout] = $this->runRegex(['transpile', '/\77\h/m']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("  Literal:\n    /\\x3F[\\x09\\x20\\xA0\\u1680\\u180E\\u2000-\\u200A\\u202F\\u205F\\u3000]/\n", $stdout);
        $this->assertStringEndsWith(
            "\n  Warnings:\n"
            ."   - Converted octal escape to hex/Unicode escape for JavaScript.\n"
            ."   - Without the u flag \"\h\" at offset 3 takes one byte in PCRE and one character in this target, so the two differ on a subject holding a character outside ASCII; under u, for UTF-8 subjects, they agree. Add u.\n"
            ."\n  Notes:\n"
            ."   - Converted \\h (horizontal whitespace) to character class.\n"
            ."   - Applied /m: \"^\" and \"$\" are written for lines ended by \"\\n\" alone, as PCRE reads them.\n",
            $stdout,
        );
    }

    /**
     * Without u a set that reaches past ASCII takes one byte in PCRE and
     * one character in the target: the JSON document says so once, for the
     * first such set, and not at all under u.
     */
    #[Test]
    public function test_transpile_json_warns_once_of_a_set_that_takes_a_byte_without_u(): void
    {
        // Oracle: "é" is two bytes to the dot, and one character under u.
        $this->assertSame(0, preg_match('/^.[^a]$/D', 'éb'));
        $this->assertSame(1, preg_match('/^.[^a]$/Du', 'éb'));

        [$code, $stdout] = $this->runRegex(['transpile', '/^.[^a]$/D', '--target=python', '--json']);

        $this->assertSame(0, $code);
        $document = json_decode($stdout, true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($document);
        $this->assertSame("r'(?a)^.[^a]\\Z'", $document['literal'] ?? null);
        $this->assertSame(['Without the u flag "." at offset 1 takes one byte in PCRE and one character in this target, so the two differ on a subject holding a character outside ASCII; under u, for UTF-8 subjects, they agree. Add u.'], $document['warnings'] ?? null);

        [$code, $stdout] = $this->runRegex(['transpile', '/^.[^a]$/Du', '--target=python', '--json']);

        $this->assertSame(0, $code);
        $document = json_decode($stdout, true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($document);
        $this->assertSame("r'^.[^a]\\Z'", $document['literal'] ?? null);
        $this->assertSame([], $document['warnings'] ?? null);
    }

    #[Test]
    public function test_transpile_prints_neither_heading_for_a_plain_translation(): void
    {
        [$code, $stdout] = $this->runRegex(['transpile', '/ab/']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("  Constructor:\n    new RegExp(\"ab\", \"\")\n", $stdout);
        $this->assertStringNotContainsString('Warnings:', $stdout);
        $this->assertStringNotContainsString('Notes:', $stdout);
    }

    #[Test]
    public function test_transpile_refuses_a_construct_the_target_lacks_with_its_offset(): void
    {
        // Oracle: PCRE reads "\g{-1}" as the group before it.
        $this->assertSame(1, preg_match('/(a)\g{-1}/', 'aa'));

        [$code, $stdout] = $this->runRegex(['transpile', '/(a)\g{-1}/']);

        $this->assertSame(1, $code);
        $this->assertSame("  Transpile failed: Relative backreferences are not supported in JavaScript: \\g{-1}.\n  At offset 3\n", $stdout);
    }

    #[Test]
    public function test_transpile_refuses_a_flag_the_target_lacks_without_an_offset(): void
    {
        [$code, $stdout] = $this->runRegex(['transpile', '/a/A']);

        $this->assertSame(1, $code);
        $this->assertSame("  Transpile failed: Unsupported PCRE flag(s) for JavaScript: A.\n", $stdout);
    }
}
