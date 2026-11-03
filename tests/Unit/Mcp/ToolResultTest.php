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

namespace PHPRegex\Tests\Unit\Mcp;

use PHPRegex\Mcp\ToolResult;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolResultTest extends TestCase
{
    #[Test]
    public function test_a_failure_carries_the_cli_error_envelope(): void
    {
        $result = ToolResult::failure('The --width value must be a positive integer.', 'usage');

        $this->assertTrue($result->isError);
        $this->assertSame('The --width value must be a positive integer.', $result->text);
        $this->assertSame(['error' => 'The --width value must be a positive integer.', 'stage' => 'usage'], $result->structured);
    }

    #[Test]
    public function test_a_failure_on_an_invalid_pattern_carries_the_validation_object(): void
    {
        $validation = Regex::create()->validate('/(a/');
        $result = ToolResult::failure((string) $validation->error, 'pattern', $validation);

        $this->assertSame(['error', 'stage', 'validation'], array_keys($result->structured));
        $validation = JsonContract::asArray($result->structured['validation']);
        $this->assertSame('regex.group.unclosed', $validation['error_code']);
        $this->assertSame(2, $validation['offset']);
    }

    #[Test]
    public function test_a_document_spells_invalid_bytes_as_the_cli_does(): void
    {
        $result = ToolResult::document('ok', ['pattern' => "/\xE9/"]);

        $this->assertFalse($result->isError);
        $this->assertSame('/\xE9/', $result->structured['pattern']);
    }
}
