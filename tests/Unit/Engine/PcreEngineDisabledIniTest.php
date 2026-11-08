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

namespace PHPRegex\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The engine where ini_set() or ini_get() is disabled (disable_functions).
 * Limits it cannot set are reported in the answer, never thrown; a pattern
 * whose delimiter leaves no room for "(*NO_JIT)" runs as it is; the caller's
 * error handler is in place after every call. With only ini_get() disabled
 * the limits are set and the caller's are put back from what ini_set()
 * returned.
 *
 * A disabled function is set per process: each case runs in a PHP process
 * of its own and prints what it saw as JSON.
 *
 * Oracle (PHP 8.4, PCRE2 10.49, JIT off): "/(a+)+$/" on "a" x 12 . "!"
 * fails with PREG_BACKTRACK_LIMIT_ERROR under a backtrack limit of 10, and
 * gives 0 under 123456 and under the default limit.
 */
final class PcreEngineDisabledIniTest extends TestCase
{
    /**
     * A body holding every spare delimiter and leaving no bracket pair
     * whole: "(*NO_JIT)" finds no place in "_..._".
     */
    private const NO_ROOM_BODY = "[])(}{><]\x01#~%!@;,a+";

    #[Test]
    public function test_disabled_ini_set_reports_limits_it_cannot_set_in_the_answer(): void
    {
        $result = self::runWith('ini_set', <<<'PHP_WRAP'
            $engine = new \PHPRegex\Parser\Engine\PcreEngine();
            $limits = new \PHPRegex\Parser\Engine\PcreLimits(10, 100000);
            $subject = str_repeat('a', 12).'!';
            $out = ['ini_set' => function_exists('ini_set')];
            foreach (['match', 'test'] as $method) {
                try {
                    $answer = $engine->{$method}('/(a+)+$/', $subject, $limits);
                    $out[$method] = ['matched' => $answer->matched, 'groups' => $answer->groups, 'error' => $answer->error, 'errorCode' => $answer->errorCode];
                } catch (\Throwable $e) {
                    $out[$method] = ['thrown' => $e::class.': '.$e->getMessage()];
                }
            }
            try {
                $out['matchAll'] = $engine->matchAll('/a/', 'aaa', $limits);
            } catch (\Throwable $e) {
                $out['matchAll'] = ['thrown' => $e::class.': '.$e->getMessage()];
            }
            return $out;
            PHP_WRAP);

        $this->assertFalse($result['ini_set'], 'The child process runs with ini_set() disabled.');
        foreach (['match', 'test'] as $method) {
            $answer = $result[$method];
            $this->assertIsArray($answer);
            $this->assertArrayNotHasKey('thrown', $answer, $method.'() threw: '.json_encode($answer));
            $this->assertNull($answer['matched'], $method);
            $this->assertSame([], $answer['groups'], $method);
            $this->assertIsString($answer['error'], $method);
            $this->assertStringContainsString('ini_set', (string) $answer['error'], $method.'() names the disabled function.');
            $this->assertMatchesRegularExpression('/limit/i', $answer['error'], $method.'() says the limits could not be set.');
        }
        $this->assertNull($result['matchAll'], 'matchAll() gives no answer under limits it could not set.');
    }

    /**
     * The handler the caller installed is the one in force after the call,
     * whatever the call met: limits it could not set, a refused pattern, a
     * pattern run with the JIT left as it is.
     */
    #[Test]
    public function test_disabled_ini_set_leaves_the_caller_error_handler_in_place(): void
    {
        $result = self::runWith('ini_set', <<<'PHP_WRAP'
            $sentinel = static fn (): bool => true;
            set_error_handler($sentinel);
            $engine = new \PHPRegex\Parser\Engine\PcreEngine();
            $limits = new \PHPRegex\Parser\Engine\PcreLimits(10, 100000);
            $calls = [
                'limits' => static fn () => $engine->match('/a/', 'a', $limits),
                'refused pattern under limits' => static fn () => $engine->match('/[a/', 'a', $limits),
                'no room for the verb' => static fn () => $engine->match('_'.NO_ROOM_BODY.'_', 'a'),
            ];
            $out = [];
            foreach ($calls as $name => $call) {
                try {
                    $call();
                } catch (\Throwable) {
                }
                $current = set_error_handler(static fn (): bool => true);
                restore_error_handler();
                $out[$name] = $current === $sentinel;
                // Put the sentinel back for the next call, whatever leaked.
                while (true) {
                    $top = set_error_handler(static fn (): bool => true);
                    restore_error_handler();
                    if ($top === $sentinel || null === $top) {
                        break;
                    }
                    restore_error_handler();
                }
                if (null === $top) {
                    set_error_handler($sentinel);
                }
            }
            return $out;
            PHP_WRAP);

        $this->assertSame(
            ['limits' => true, 'refused pattern under limits' => true, 'no room for the verb' => true],
            $result,
        );
    }

    /**
     * Where the JIT cannot be turned off, the pattern runs as it is, under
     * the caller's settings, and the answer is the engine's.
     */
    #[Test]
    public function test_disabled_ini_set_runs_a_pattern_without_room_for_the_verb_as_it_is(): void
    {
        $result = self::runWith('ini_set', <<<'PHP_WRAP'
            $pattern = '_'.NO_ROOM_BODY.'_';
            $engine = new \PHPRegex\Parser\Engine\PcreEngine();
            $out = ['prepared' => $engine->prepare($pattern) === $pattern];
            foreach ([")\x01#~%!@;,aa", ')#~', ''] as $subject) {
                $expected = preg_match($pattern, $subject, $groups);
                try {
                    $answer = $engine->match($pattern, $subject);
                    $out[] = [$expected, $groups, $answer->matched, $answer->groups, $answer->error];
                } catch (\Throwable $e) {
                    $out[] = [$expected, $groups, 'thrown' => $e::class.': '.$e->getMessage()];
                }
            }
            return $out;
            PHP_WRAP);

        $this->assertTrue($result['prepared'], 'The verb finds no place in the pattern.');
        unset($result['prepared']);
        $this->assertCount(3, $result);
        foreach ($result as $row) {
            $this->assertIsArray($row);
            $this->assertArrayNotHasKey('thrown', $row, (string) json_encode($row, \JSON_INVALID_UTF8_SUBSTITUTE));
            [$expected, $groups, $matched, $answerGroups, $error] = $row;
            $this->assertSame(1 === $expected, $matched);
            $this->assertSame($groups, $answerGroups);
            $this->assertNull($error);
        }
    }

    /**
     * With ini_get() disabled the limits are set for the call: the probe
     * stops at the backtrack limit of 10. The caller's limit is back after
     * it, read through ini_set()'s return value and ini_get_all(), and a
     * plain preg_match() runs under it as before.
     */
    #[Test]
    public function test_disabled_ini_get_applies_the_limits_and_restores_the_callers(): void
    {
        $result = self::runWith('ini_get', <<<'PHP_WRAP'
            ini_set('pcre.backtrack_limit', '123456');
            ini_set('pcre.recursion_limit', '54321');
            $engine = new \PHPRegex\Parser\Engine\PcreEngine();
            $subject = str_repeat('a', 12).'!';
            $out = ['ini_get' => function_exists('ini_get')];
            try {
                $answer = $engine->match('/(a+)+$/', $subject, new \PHPRegex\Parser\Engine\PcreLimits(10, 100000));
                $out['match'] = ['matched' => $answer->matched, 'errorCode' => $answer->errorCode, 'error' => $answer->error];
            } catch (\Throwable $e) {
                $out['match'] = ['thrown' => $e::class.': '.$e->getMessage()];
            }
            $all = ini_get_all('pcre', false);
            $out['after'] = [$all['pcre.backtrack_limit'], $all['pcre.recursion_limit']];
            $out['bare'] = [preg_match('/(*NO_JIT)(a+)+$/', $subject), preg_last_error()];
            $out['returned'] = ini_set('pcre.backtrack_limit', '1000000');
            return $out;
            PHP_WRAP);

        $this->assertFalse($result['ini_get'], 'The child process runs with ini_get() disabled.');
        $this->assertSame(['matched' => null, 'errorCode' => \PREG_BACKTRACK_LIMIT_ERROR, 'error' => 'Backtrack limit exhausted'], $result['match']);
        $this->assertSame(['123456', '54321'], $result['after']);
        $this->assertSame([0, \PREG_NO_ERROR], $result['bare'], 'Oracle: under 123456 the probe gives 0.');
        $this->assertSame('123456', $result['returned']);
    }

    /**
     * Oracle (PHP 8.4, PCRE2 10.49 and PHP 8.1, PCRE2 10.39, JIT off): under
     * a backtrack limit of 10, preg_match('/(a+)+$/', 'a' x 12 . '!') gives
     * up with PREG_BACKTRACK_LIMIT_ERROR; the refused call answers without
     * running it. ini_set() is recorded key by key: the backtrack limit,
     * set first, is the only one refused before anything changed, and the
     * recursion limit, set second, has the backtrack limit put back after it.
     *
     * @param list<string> $iniCalls
     */
    #[Test]
    #[DataProvider('provideRefusedLimits')]
    public function test_a_refused_limit_stops_every_operation_and_restores_changed_settings(string $refused, array $iniCalls): void
    {
        $result = self::runWith('', <<<'PHP_WRAP'
            $before = ini_get_all('pcre', false);
            $engine = new \PHPRegex\Parser\Engine\PcreEngine();
            $limits = new \PHPRegex\Parser\Engine\PcreLimits(10, 500);
            $out = [];
            foreach (['match', 'test', 'matchAll', 'answer'] as $method) {
                $GLOBALS['iniCalls'] = [];
                $sentinel = static fn (): bool => true;
                set_error_handler($sentinel);
                $answer = 'answer' === $method
                    ? $engine->answer('preg_match', '/(a+)+$/', str_repeat('a', 12).'!', [], $limits)
                    : $engine->{$method}('/(a+)+$/', str_repeat('a', 12).'!', $limits);
                $out[$method] = [
                    'answer' => $answer instanceof \PHPRegex\Parser\Engine\PcreMatch
                        ? [$answer->matched, $answer->groups, $answer->errorCode, $answer->error]
                        : $answer,
                    'handler' => $sentinel === set_error_handler(static fn (): bool => true),
                    'restored' => $before === ini_get_all('pcre', false),
                    'iniCalls' => $GLOBALS['iniCalls'],
                ];
                restore_error_handler();
                restore_error_handler();
            }
            $out['calls'] = $GLOBALS['nativeCalls'] ?? 0;
            \ini_set('pcre.jit', '0');
            \ini_set('pcre.backtrack_limit', '10');
            $out['oracle'] = [@\preg_match('/(a+)+$/', str_repeat('a', 12).'!'), \preg_last_error()];
            return $out;
            PHP_WRAP, 'namespace PHPRegex\\Parser\\Engine { function ini_set($key, $value) { $GLOBALS["iniCalls"][] = $key; return '
                .var_export($refused, true).' === $key ? false : \\ini_set($key, $value); } '
                .'function preg_match(...$args) { $GLOBALS["nativeCalls"] = ($GLOBALS["nativeCalls"] ?? 0) + 1; return \\preg_match(...$args); } '
                .'function preg_match_all(...$args) { $GLOBALS["nativeCalls"] = ($GLOBALS["nativeCalls"] ?? 0) + 1; return \\preg_match_all(...$args); } }');

        $this->assertSame([false, \PREG_BACKTRACK_LIMIT_ERROR], $result['oracle'], 'Run, the call would give up.');
        foreach (['match', 'test', 'matchAll', 'answer'] as $method) {
            $outcome = $result[$method];
            $this->assertIsArray($outcome);
            if (\in_array($method, ['match', 'test'], true)) {
                $answer = $outcome['answer'];
                $this->assertIsArray($answer);
                $this->assertSame([null, [], \PREG_INTERNAL_ERROR], \array_slice($answer, 0, 3), $method);
                $this->assertSame('The PCRE limits could not be set: ini_set() refused '.$refused, $answer[3] ?? null, $method);
            } else {
                $this->assertNull($outcome['answer'], $method);
            }
            $this->assertTrue($outcome['handler'], 'The caller\'s error handler is back after '.$method.'.');
            $this->assertTrue($outcome['restored'], 'The settings are back after '.$method.'.');
            $this->assertSame($iniCalls, $outcome['iniCalls'], $method);
        }
        $this->assertSame(0, $result['calls'], 'No native match runs after '.$refused.' is refused.');
    }

    /**
     * @return iterable<string, array{refused: string, iniCalls: list<string>}>
     */
    public static function provideRefusedLimits(): iterable
    {
        yield 'the first limit, nothing changed yet' => ['refused' => 'pcre.backtrack_limit', 'iniCalls' => ['pcre.backtrack_limit']];
        yield 'the second limit, the first put back' => ['refused' => 'pcre.recursion_limit', 'iniCalls' => ['pcre.backtrack_limit', 'pcre.recursion_limit', 'pcre.backtrack_limit']];
    }

    /**
     * Runs the code in a PHP process with the function disabled; the code
     * returns what it saw, printed back as JSON.
     *
     * @return array<array-key, mixed>
     */
    private static function runWith(string $disabled, string $code, string $prelude = ''): array
    {
        $script = $prelude.' namespace { require '.var_export(\dirname(__DIR__, 3).'/vendor/autoload.php', true).';'
            .'const NO_ROOM_BODY = '.var_export(self::NO_ROOM_BODY, true).';'
            .'$run = static function () { '.$code.' };'
            .'echo json_encode($run(), JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR); }';

        $process = proc_open(
            [\PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'auto_prepend_file=', '-d', 'disable_functions='.$disabled, '-r', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, $errors.$output);
        $decoded = json_decode($output, true);
        self::assertIsArray($decoded, $errors.$output);

        return $decoded;
    }
}
