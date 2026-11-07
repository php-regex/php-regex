<?php

declare(strict_types=1);

namespace PHPRegex\Tests\Unit\Engine;

use PHPRegex\Parser\Engine\PcreEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PcreEngineMatchAllTest extends TestCase
{
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_matches_follow_native_global_matching(string $pattern, string $subject): void
    {
        $this->assertNotFalse(preg_match_all($pattern, $subject, $expected));
        $this->assertSame($expected[0], (new PcreEngine())->matchAll($pattern, $subject));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'empty then nonempty at same offset' => ['/(?:|a)/', 'aa'];
        yield 'UTF empty matches' => ['//u', 'é😀'];
        yield 'CRLF advancement' => ['/(*CRLF)/', "a\r\nb"];
        yield 'reset match start' => ['/a\\Kb/', 'abab'];
        yield 'named captures and backreferences' => ['/(?<x>a)\\k<x>/', 'aaaa'];
        yield 'search anchor' => ['/\\G(a)/', 'aaab'];
        yield 'no matches' => ['/z/', 'aaa'];
    }

    #[Test]
    public function test_result_count_is_bounded_including_empty_matches(): void
    {
        $engine = new PcreEngine();
        $this->assertCount(10000, $engine->matchAll('/a/', str_repeat('a', 10000)) ?? []);
        $this->assertNull($engine->matchAll('/a/', str_repeat('a', 10001)));
        $this->assertNull($engine->matchAll('//', str_repeat('a', 10000)));
    }

    #[Test]
    public function test_aggregate_matched_bytes_are_bounded(): void
    {
        $engine = new PcreEngine();
        $subject = str_repeat('a', 8 * 1024 * 1024);
        $this->assertSame([$subject], $engine->matchAll('/.+/s', $subject));
        $this->assertNull($engine->matchAll('/.+/s', $subject.'a'));
        $this->assertNull($engine->matchAll('/.{4194304}/s', $subject.str_repeat('a', 4194304)));
    }

    #[Test]
    public function test_captures_are_not_retained_across_matches(): void
    {
        $script = 'require '.var_export(\dirname(__DIR__, 3).'/vendor/autoload.php', true).';'
            .'$matches = (new \PHPRegex\Parser\Engine\PcreEngine())->matchAll("/".str_repeat("()", 500)."a/", str_repeat("a", 5000));'
            .'echo count($matches ?? []);';
        $process = proc_open(
            [\PHP_BINARY, '-d', 'memory_limit=32M', '-d', 'auto_prepend_file=', '-r', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), (string) $errors);
        $this->assertSame('5000', $output);
    }
}
