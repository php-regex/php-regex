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

final class LintFunctionOverrides
{
    public static ?int $pcntlForkResult = null;

    /**
     * @var array<int>
     */
    public static array $pcntlForkSequence = [];

    public static ?int $pcntlWaitpidResult = null;

    /**
     * @var array<string|false>
     */
    public static array $tempnamSequence = [];

    /**
     * @var array<bool>
     */
    public static array $classExistsSequence = [];

    /**
     * @var array<string|false>
     */
    public static array $getcwdSequence = [];

    /**
     * @var array<float>
     */
    public static array $microtimeSequence = [];

    public static ?bool $mbCheckEncodingResult = null;

    public static string|false|null $mbConvertEncodingResult = null;

    /**
     * Functions reported missing by function_exists() in the linter's
     * internal namespace.
     *
     * @var list<string>
     */
    public static array $missingFunctions = [];

    /**
     * What the next stream_socket_pair() calls give instead of a pair.
     *
     * @var list<false>
     */
    public static array $streamSocketPairSequence = [];

    /**
     * Once this many real forks happened, every further pcntl_fork() gives
     * -1 (the queued values above still come first).
     */
    public static ?int $pcntlForkFailAfter = null;

    public static int $pcntlForkRealCount = 0;

    /**
     * When set, tempnam() creates its files in this directory instead of
     * the one it is asked for: a test then sees every file the code under
     * test left behind, and only those.
     */
    public static ?string $tempnamDirectory = null;

    /**
     * How long tempnam() waits once it has created its file, as a slow disk
     * would: the file exists before the code under test can name it.
     */
    public static int $tempnamDelayMicroseconds = 0;

    public static bool $countFileGetContents = false;

    /**
     * When true, fopen() in the worker pool fails to create the file its
     * workers lock around class loading.
     */
    public static bool $lockFileFails = false;

    /**
     * How many times each path was read while counting is on.
     *
     * @var array<string, int>
     */
    public static array $fileGetContentsCalls = [];

    public static function reset(): void
    {
        self::$pcntlForkResult = null;
        self::$pcntlForkSequence = [];
        self::$pcntlWaitpidResult = null;
        self::$tempnamSequence = [];
        self::$classExistsSequence = [];
        self::$getcwdSequence = [];
        self::$microtimeSequence = [];
        self::$mbCheckEncodingResult = null;
        self::$mbConvertEncodingResult = null;
        self::$missingFunctions = [];
        self::$streamSocketPairSequence = [];
        self::$pcntlForkFailAfter = null;
        self::$pcntlForkRealCount = 0;
        self::$tempnamDirectory = null;
        self::$tempnamDelayMicroseconds = 0;
        self::$countFileGetContents = false;
        self::$fileGetContentsCalls = [];
        self::$lockFileFails = false;
    }

    /**
     * @param false $result
     */
    public static function queueStreamSocketPair(bool $result): void
    {
        self::$streamSocketPairSequence[] = $result;
    }

    public static function queuePcntlForkResult(int $result): void
    {
        self::$pcntlForkSequence[] = $result;
    }

    public static function queueTempnam(string|false $path): void
    {
        self::$tempnamSequence[] = $path;
    }

    public static function queueClassExists(bool $result): void
    {
        self::$classExistsSequence[] = $result;
    }

    public static function queueGetcwd(string|false $path): void
    {
        self::$getcwdSequence[] = $path;
    }

    public static function queueMicrotime(float $value): void
    {
        self::$microtimeSequence[] = $value;
    }

    public static function pcntlFork(): int
    {
        if ([] !== self::$pcntlForkSequence) {
            return (int) array_shift(self::$pcntlForkSequence);
        }

        if (null !== self::$pcntlForkResult) {
            return self::$pcntlForkResult;
        }

        if (null !== self::$pcntlForkFailAfter) {
            if (self::$pcntlForkRealCount >= self::$pcntlForkFailAfter) {
                return -1;
            }

            self::$pcntlForkRealCount++;
        }

        return pcntl_fork();
    }

    public static function functionExists(string $function): bool
    {
        if (\in_array(strtolower(ltrim($function, '\\')), self::$missingFunctions, true)) {
            return false;
        }

        return \function_exists($function);
    }

    /**
     * @return array<resource>|false
     */
    public static function streamSocketPair(int $domain, int $type, int $protocol): array|false
    {
        if ([] !== self::$streamSocketPairSequence) {
            return array_shift(self::$streamSocketPairSequence);
        }

        return stream_socket_pair($domain, $type, $protocol);
    }

    /**
     * @param resource|null    $context
     * @param int<0, max>|null $length
     */
    public static function fileGetContents(string $filename, bool $useIncludePath = false, $context = null, int $offset = 0, ?int $length = null): string|false
    {
        if (self::$countFileGetContents) {
            self::$fileGetContentsCalls[$filename] = (self::$fileGetContentsCalls[$filename] ?? 0) + 1;
        }

        return file_get_contents($filename, $useIncludePath, $context, $offset, $length);
    }

    /**
     * @param ?array<int|string, mixed> &$rusage
     *
     * @param-out int|null $status
     * @param-out array<int|string, mixed> $rusage
     */
    public static function pcntlWaitpid(int $pid, ?int &$status = null, int $options = 0, ?array &$rusage = null): int
    {
        if (null !== self::$pcntlWaitpidResult) {
            $status = 0;

            return self::$pcntlWaitpidResult;
        }

        $waitStatus = $status ?? 0;
        $usage = \is_array($rusage) ? $rusage : [];
        $result = pcntl_waitpid($pid, $waitStatus, $options, $usage);
        $status = \is_int($waitStatus) ? $waitStatus : null;
        $rusage = \is_array($usage) ? $usage : [];

        return $result;
    }

    /**
     * @return resource|false
     */
    public static function fopen(string $filename, string $mode): mixed
    {
        if (self::$lockFileFails && 'x' === $mode) {
            return false;
        }

        return \fopen($filename, $mode);
    }

    public static function tempnam(string $dir, string $prefix): string|false
    {
        if ([] !== self::$tempnamSequence) {
            $path = array_shift(self::$tempnamSequence);
            if (false === $path) {
                return false;
            }

            if (!is_file($path)) {
                @copy(__DIR__.'/../../../Fixtures/empty.txt', $path);
            }

            return $path;
        }

        $path = tempnam(self::$tempnamDirectory ?? $dir, $prefix);
        if (self::$tempnamDelayMicroseconds > 0) {
            usleep(self::$tempnamDelayMicroseconds);
        }

        return $path;
    }

    public static function classExists(string $class, bool $autoload = true): bool
    {
        if ([] !== self::$classExistsSequence) {
            return (bool) array_shift(self::$classExistsSequence);
        }

        return class_exists($class, $autoload);
    }

    public static function getcwd(): string|false
    {
        if ([] !== self::$getcwdSequence) {
            return array_shift(self::$getcwdSequence);
        }

        return getcwd();
    }

    public static function microtime(bool $asFloat = false): string|float
    {
        if ([] !== self::$microtimeSequence) {
            $value = array_shift(self::$microtimeSequence);

            return $asFloat ? $value : (string) $value;
        }

        return microtime($asFloat);
    }

    public static function mbCheckEncoding(string $string, string $encoding): bool
    {
        if (null !== self::$mbCheckEncodingResult) {
            return self::$mbCheckEncodingResult;
        }

        return mb_check_encoding($string, $encoding);
    }

    public static function mbConvertEncoding(string $string, string $toEncoding, string $fromEncoding): string|false
    {
        if (null !== self::$mbConvertEncodingResult) {
            return self::$mbConvertEncodingResult;
        }

        return mb_convert_encoding($string, $toEncoding, $fromEncoding);
    }
}

namespace PHPRegex\Linter\Parallel;

use PHPRegex\Tests\Support\LintFunctionOverrides;

function pcntl_fork(): int
{
    return LintFunctionOverrides::pcntlFork();
}

/**
 * @param ?array<int|string, mixed> $rusage
 *
 * @param-out int|null $status
 */
function pcntl_waitpid(int $pid, ?int &$status = null, int $options = 0, ?array &$rusage = null): int
{
    return LintFunctionOverrides::pcntlWaitpid($pid, $status, $options, $rusage);
}

function tempnam(string $dir, string $prefix): string|false
{
    return LintFunctionOverrides::tempnam($dir, $prefix);
}

function function_exists(string $function): bool
{
    return LintFunctionOverrides::functionExists($function);
}

/**
 * @return resource|false
 */
function fopen(string $filename, string $mode): mixed
{
    return LintFunctionOverrides::fopen($filename, $mode);
}

/**
 * @return array<resource>|false
 */
function stream_socket_pair(int $domain, int $type, int $protocol): array|false
{
    return LintFunctionOverrides::streamSocketPair($domain, $type, $protocol);
}

namespace PHPRegex\Linter;

use PHPRegex\Tests\Support\LintFunctionOverrides;

function mb_check_encoding(string $string, string $encoding): bool
{
    return LintFunctionOverrides::mbCheckEncoding($string, $encoding);
}

function mb_convert_encoding(string $string, string $toEncoding, string $fromEncoding): string|false
{
    return LintFunctionOverrides::mbConvertEncoding($string, $toEncoding, $fromEncoding);
}

/**
 * @param resource|null    $context
 * @param int<0, max>|null $length
 */
function file_get_contents(string $filename, bool $use_include_path = false, $context = null, int $offset = 0, ?int $length = null): string|false
{
    return LintFunctionOverrides::fileGetContents($filename, $use_include_path, $context, $offset, $length);
}

namespace PHPRegex\Linter\Extraction;

use PHPRegex\Tests\Support\LintFunctionOverrides;

function mb_check_encoding(string $string, string $encoding): bool
{
    return LintFunctionOverrides::mbCheckEncoding($string, $encoding);
}

function mb_convert_encoding(string $string, string $toEncoding, string $fromEncoding): string|false
{
    return LintFunctionOverrides::mbConvertEncoding($string, $toEncoding, $fromEncoding);
}

namespace PHPRegex\Linter\Config;

use PHPRegex\Tests\Support\LintFunctionOverrides;

function getcwd(): string|false
{
    return LintFunctionOverrides::getcwd();
}

function class_exists(string $class, bool $autoload = true): bool
{
    return LintFunctionOverrides::classExists($class, $autoload);
}

function microtime(bool $asFloat = false): string|float
{
    return LintFunctionOverrides::microtime($asFloat);
}

namespace PHPRegex\Laravel;

use PHPRegex\Tests\Support\LintFunctionOverrides;

function class_exists(string $class, bool $autoload = true): bool
{
    return LintFunctionOverrides::classExists($class, $autoload);
}
