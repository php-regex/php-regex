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

namespace PHPRegex\Tests\Unit\Transpiler;

use PHPRegex\Transpiler\TranspileResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TranspileResultJsonTest extends TestCase
{
    /**
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('provideResults')]
    public function test_json_serialize_writes_the_transpile_payload(TranspileResult $result, array $expected): void
    {
        $this->assertSame($expected, $result->jsonSerialize());
    }

    /**
     * @return iterable<string, array{result: TranspileResult, expected: array<string, mixed>}>
     */
    public static function provideResults(): iterable
    {
        yield 'no warning, no note' => [
            'result' => new TranspileResult('/a+/', 'javascript', 'a+', '', '/a+/', 'new RegExp("a+")'),
            'expected' => [
                'target' => 'javascript',
                'source' => '/a+/',
                'pattern' => 'a+',
                'flags' => '',
                'literal' => '/a+/',
                'constructor' => 'new RegExp("a+")',
                'warnings' => [],
                'notes' => [],
            ],
        ];

        yield 'warnings and notes, raw bytes kept' => [
            'result' => new TranspileResult("/a\x01/i", 'python', "a\x01", 'IGNORECASE', "r'a\x01'", "re.compile(r'a\x01', re.IGNORECASE)", ['Possessive quantifier dropped'], ['Flag i mapped']),
            'expected' => [
                'target' => 'python',
                'source' => "/a\x01/i",
                'pattern' => "a\x01",
                'flags' => 'IGNORECASE',
                'literal' => "r'a\x01'",
                'constructor' => "re.compile(r'a\x01', re.IGNORECASE)",
                'warnings' => ['Possessive quantifier dropped'],
                'notes' => ['Flag i mapped'],
            ],
        ];
    }
}
