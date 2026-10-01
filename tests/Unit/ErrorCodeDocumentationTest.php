<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit;

use PhpRegex\Parser\ErrorCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The error code table in the diagnostics reference is the list a caller
 * matches against: a code missing from it, or a row naming a code that no
 * longer exists, is a promise the library does not keep.
 */
final class ErrorCodeDocumentationTest extends TestCase
{
    private const DOCUMENT = __DIR__.'/../../docs/reference/diagnostics.md';

    #[Test]
    public function test_error_code_table_lists_every_case_once_with_its_doc_line(): void
    {
        $expected = [];
        foreach (ErrorCode::cases() as $case) {
            $expected[$case->value] = [$case->name, $this->docLine($case)];
        }

        $this->assertSame($expected, $this->documentedRows());
    }

    #[Test]
    public function test_error_code_table_follows_the_declaration_order(): void
    {
        $this->assertSame(
            array_map(static fn (ErrorCode $case): string => $case->value, ErrorCode::cases()),
            array_keys($this->documentedRows()),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    private function documentedRows(): array
    {
        $document = file_get_contents(self::DOCUMENT);
        $this->assertIsString($document);

        $start = strpos($document, "\n## Error Codes\n");
        $this->assertNotFalse($start, 'The diagnostics reference has no "Error Codes" section.');
        $end = strpos($document, "\n## ", $start + 1);
        $section = substr($document, $start, false === $end ? null : $end - $start);

        preg_match_all('/^\| `(regex\.[^`]+)` \| `(\w+)` \| (.+) \|$/m', $section, $matches, \PREG_SET_ORDER);

        $rows = [];
        foreach ($matches as [, $value, $name, $meaning]) {
            $this->assertArrayNotHasKey($value, $rows, \sprintf('%s is listed twice.', $value));
            $rows[$value] = [$name, str_replace('\|', '|', $meaning)];
        }

        return $rows;
    }

    private function docLine(ErrorCode $case): string
    {
        $comment = (new \ReflectionEnumBackedCase(ErrorCode::class, $case->name))->getDocComment();
        $this->assertIsString($comment, \sprintf('%s has no doc comment.', $case->name));

        $lines = array_map(
            static fn (string $line): string => trim(ltrim(trim($line), '/*')),
            explode("\n", $comment),
        );

        return implode(' ', array_filter($lines, static fn (string $line): bool => '' !== $line));
    }
}
