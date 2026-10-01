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

        return pcntl_fork();
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

        $status ??= 0;

        $rusage = \is_array($rusage) ? $rusage : [];

        /* @var array<int|string, mixed> $rusage */
        // @phpstan-ignore-next-line paramOut.type
        return pcntl_waitpid($pid, $status, $options, $rusage);
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

        return tempnam($dir, $prefix);
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

namespace PHPRegex\Linter\Internal;

use PHPRegex\Tests\Support\LintFunctionOverrides;

function pcntl_fork(): int
{
    return LintFunctionOverrides::pcntlFork();
}

namespace PHPRegex\Linter;

use PHPRegex\Tests\Support\LintFunctionOverrides;

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

function mb_check_encoding(string $string, string $encoding): bool
{
    return LintFunctionOverrides::mbCheckEncoding($string, $encoding);
}

function mb_convert_encoding(string $string, string $toEncoding, string $fromEncoding): string|false
{
    return LintFunctionOverrides::mbConvertEncoding($string, $toEncoding, $fromEncoding);
}

namespace PHPRegex\Linter\Extraction;

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
