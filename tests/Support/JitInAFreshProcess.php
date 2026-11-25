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

namespace PHPRegex\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * What preg_match() writes with the JIT, asked of a PHP of its own. The
 * suite keeps compiled patterns, in random order, some compiled while
 * pcre.jit is off: a pattern compiled once is not compiled again when
 * pcre.jit changes, so a claim about the JIT made in the suite's own
 * process may read the interpreter.
 */
final class JitInAFreshProcess
{
    private const CODE = '$m = []; $r = preg_match(base64_decode($argv[1]), base64_decode($argv[2]), $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, (int) $argv[3]); echo json_encode(["result" => $r, "matches" => $m, "jit" => PCRE_JIT_SUPPORT], JSON_INVALID_UTF8_SUBSTITUTE);';

    private function __construct() {}

    /**
     * 1, 0, or false when the pattern does not compile.
     */
    public static function matched(string $pattern, string $subject, int $offset = 0): int|false
    {
        return self::run($pattern, $subject, $offset)['result'];
    }

    /**
     * The matches, offsets with them and unset groups as null, or false
     * when the pattern does not compile.
     *
     * @return array<array-key, mixed>|false
     */
    public static function matches(string $pattern, string $subject, int $offset = 0): array|false
    {
        $run = self::run($pattern, $subject, $offset);

        return false === $run['result'] ? false : $run['matches'];
    }

    /**
     * @return array{result: int|false, matches: array<array-key, mixed>}
     */
    private static function run(string $pattern, string $subject, int $offset): array
    {
        $process = proc_open([\PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'pcre.jit=1', '-r', self::CODE, '--', base64_encode($pattern), base64_encode($subject), (string) $offset], ProcessPipes::descriptors(), $pipes);
        Assert::assertIsResource($process);
        [$output, $error] = ProcessPipes::drain($pipes);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $answer = json_decode($output, true);
        Assert::assertIsArray($answer, 'No answer from the child: '.$output.$error);
        Assert::assertTrue($answer['jit'] ?? false, 'The engine has no JIT.');
        Assert::assertTrue(\is_int($answer['result'] ?? null) || false === ($answer['result'] ?? null), 'No result from the child: '.$output.$error);
        Assert::assertIsArray($answer['matches'] ?? null);

        return ['result' => $answer['result'], 'matches' => $answer['matches']];
    }
}
