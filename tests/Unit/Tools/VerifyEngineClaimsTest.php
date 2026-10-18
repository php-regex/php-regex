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

namespace PHPRegex\Tests\Unit\Tools;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The verifier the engine-claims job runs on PHP 7.3 to 8.6: it checks the
 * claims made for the running PCRE2 release, and names every one the engine
 * contradicts. Each fixture here is written for the engine running the test.
 */
final class VerifyEngineClaimsTest extends TestCase
{
    private const SCRIPT = __DIR__.'/../../Tools/verify_engine_claims.php';

    private ?string $file = null;

    protected function tearDown(): void
    {
        if (null !== $this->file && is_file($this->file)) {
            unlink($this->file);
        }
        $this->file = null;
    }

    #[Test]
    public function test_claims_the_engine_holds_pass(): void
    {
        [$code, $output] = $this->verify([
            ['source' => 'a', 'pattern' => '/a+/', 'compile' => [self::release() => true]],
            ['source' => 'b', 'pattern' => '/(/', 'compile' => [self::release() => 1]],
            ['source' => 'c', 'pattern' => '/(?/', 'compile' => [self::release() => false]],
            ['source' => 'd', 'pattern' => '/a(b)/', 'subject' => 'xab', 'group' => 1, 'match' => [self::release() => 'b']],
            ['source' => 'e', 'pattern' => '/z/', 'subject' => 'abc', 'match' => [self::release() => null]],
            ['source' => 'f', 'pattern' => '/a{2}/', 'same_as' => '/aa/', 'releases' => [self::release()], 'subjects' => ['aaa', '', 'ba']],
        ]);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('6 claims checked for PCRE2 '.self::release().', 0 contradicted', $output);
    }

    #[Test]
    public function test_a_contradicted_compile_claim_names_the_pattern_and_both_verdicts(): void
    {
        [$code, $output] = $this->verify([
            ['source' => 'BeforeTest: a row', 'pattern' => '/(/', 'compile' => [self::release() => true]],
            ['source' => 'offset', 'pattern' => '/(/', 'compile' => [self::release() => 7]],
        ]);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString("CONTRADICTED compile \"/(/\"\n    expected: compiles\n    actual:   refused at offset 1", $output);
        $this->assertStringContainsString('source:   BeforeTest: a row', $output);
        $this->assertStringContainsString("expected: refused at offset 7\n    actual:   refused at offset 1", $output);
        $this->assertStringContainsString('2 claims checked for PCRE2 '.self::release().', 2 contradicted', $output);
    }

    #[Test]
    public function test_a_contradicted_match_claim_names_the_subject(): void
    {
        [$code, $output] = $this->verify([
            ['source' => 'm', 'pattern' => '/a+/', 'subject' => 'baa', 'match' => [self::release() => 'a']],
            ['source' => 'n', 'pattern' => '/a/', 'subject' => ['hex' => 'ff'], 'match' => [self::release() => 'a']],
        ]);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString("CONTRADICTED match \"/a+/\" on \"baa\"\n    expected: \"a\"\n    actual:   \"aa\"", $output);
        $this->assertStringContainsString("CONTRADICTED match \"/a/\" on hex:ff\n    expected: \"a\"\n    actual:   no match", $output);
    }

    #[Test]
    public function test_a_rewrite_that_finds_otherwise_is_contradicted_on_the_first_subject_that_shows_it(): void
    {
        [$code, $output] = $this->verify([
            ['source' => 's', 'pattern' => '/a+/', 'same_as' => '/a/', 'releases' => [self::release()], 'subjects' => ['b', 'aa', 'aaa']],
        ]);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('CONTRADICTED same "/a+/" and "/a/" on "aa"', $output);
    }

    #[Test]
    public function test_a_rewrite_of_a_pattern_the_engine_refuses_claims_nothing(): void
    {
        [$code, $output] = $this->verify([
            ['source' => 'a', 'pattern' => '/a/', 'compile' => [self::release() => true]],
            ['source' => 's', 'pattern' => '/(/', 'same_as' => '/\(/', 'releases' => [self::release()], 'subjects' => ['(']],
        ]);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('1 claims checked for PCRE2 '.self::release().', 0 contradicted, 1 not applicable here.', $output);
    }

    #[Test]
    public function test_a_claim_that_depends_on_php_is_read_for_the_running_one(): void
    {
        [$code, $output] = $this->verify([
            ['source' => 'php', 'pattern' => '/a/', 'compile' => [self::release() => ['php' => ['70300' => 5, (string) \PHP_VERSION_ID => true, (string) (\PHP_VERSION_ID + 1) => 9]]]],
            ['source' => 'later', 'pattern' => '/b/', 'compile' => [self::release() => ['php' => [(string) (\PHP_VERSION_ID + 1) => 3]]]],
        ]);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('1 claims checked for PCRE2 '.self::release().', 0 contradicted, 1 not applicable here.', $output);
    }

    #[Test]
    public function test_a_claim_for_another_release_is_not_checked(): void
    {
        [$code, $output] = $this->verify(
            [
                ['source' => 'a', 'pattern' => '/a/', 'compile' => [self::release() => true]],
                ['source' => 'b', 'pattern' => '/(/', 'compile' => ['9.99' => true]],
            ],
            [self::release(), '9.99'],
        );

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('1 claims checked', $output);
    }

    #[Test]
    public function test_a_fixture_without_the_running_release_fails(): void
    {
        [$code, $output] = $this->verify([['source' => 'a', 'pattern' => '/a/', 'compile' => ['9.99' => true]]], ['9.98', '9.99']);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('The fixture holds no claim for PCRE2 '.self::release().', only for 9.98, 9.99', $output);
    }

    #[Test]
    public function test_an_unreadable_fixture_is_a_usage_error(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'engine-claims-');
        file_put_contents($this->file, 'not json');

        [$code, $output] = self::runScript($this->file);

        $this->assertSame(2, $code, $output);
        $this->assertStringContainsString('Cannot read the engine claims in', $output);
    }

    #[Test]
    public function test_the_committed_fixture_names_the_release_of_every_claim(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/EngineClaims/engine-claims.json'), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($fixture);
        $this->assertIsArray($fixture['releases']);
        $this->assertIsArray($fixture['claims']);

        $unknown = [];
        foreach ($fixture['claims'] as $claim) {
            $this->assertIsArray($claim);
            $byRelease = $claim['compile'] ?? $claim['match'] ?? [];
            $releases = isset($claim['releases']) && \is_array($claim['releases']) ? $claim['releases'] : array_keys(\is_array($byRelease) ? $byRelease : []);
            foreach ($releases as $release) {
                if (!\is_string($release) || !\in_array($release, $fixture['releases'], true)) {
                    $unknown[] = $release;
                }
            }
        }

        $this->assertSame([], $unknown);
    }

    private static function release(): string
    {
        self::assertSame(1, preg_match('/^(\d+)\.(\d+)/', \PCRE_VERSION, $parts));

        return (int) $parts[1].'.'.$parts[2];
    }

    /**
     * @param list<array<string, mixed>> $claims
     * @param list<string>|null          $releases
     *
     * @return array{int, string}
     */
    private function verify(array $claims, ?array $releases = null): array
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'engine-claims-');
        file_put_contents($this->file, json_encode(['releases' => $releases ?? [self::release()], 'claims' => $claims], \JSON_THROW_ON_ERROR));

        return self::runScript($this->file);
    }

    /**
     * @return array{int, string}
     */
    private static function runScript(string $fixture): array
    {
        $process = proc_open(
            [\PHP_BINARY, '-d', 'auto_prepend_file=', self::SCRIPT, $fixture],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output.$errors];
    }
}
