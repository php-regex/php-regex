<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Support;

final class SelfUpdateFunctionOverrides
{
    public static ?bool $isWritableResult = null;

    /**
     * @var array<bool>
     */
    public static array $isWritableSequence = [];

    /**
     * @var array<string|false>
     */
    public static array $tempnamSequence = [];

    /**
     * @var array<string|false>
     */
    public static array $hashFileSequence = [];

    /**
     * @var array<bool>
     */
    public static array $renameSequence = [];

    /**
     * @var array<bool>
     */
    public static array $copySequence = [];

    public static ?bool $forceFopenReadFail = null;

    public static ?bool $forceFopenWriteFail = null;

    /**
     * @var array<resource|false>
     */
    public static array $fopenSequence = [];

    /**
     * @var array<array{exitCode: int, destination?: string|null, contents?: string|null, output?: array<int, string>}>
     */
    public static array $execSequence = [];

    /**
     * @var array<string|false>
     */
    public static array $fileGetContentsSequence = [];

    public static function reset(): void
    {
        self::$isWritableResult = null;
        self::$isWritableSequence = [];
        self::$tempnamSequence = [];
        self::$hashFileSequence = [];
        self::$renameSequence = [];
        self::$copySequence = [];
        self::$forceFopenReadFail = null;
        self::$forceFopenWriteFail = null;
        self::$fopenSequence = [];
        self::$execSequence = [];
        self::$fileGetContentsSequence = [];
    }

    public static function queueIsWritable(bool $result): void
    {
        self::$isWritableSequence[] = $result;
    }

    public static function queueTempnam(string|false $path): void
    {
        self::$tempnamSequence[] = $path;
    }

    public static function queueHashFile(string|false $value): void
    {
        self::$hashFileSequence[] = $value;
    }

    public static function queueRename(bool $value): void
    {
        self::$renameSequence[] = $value;
    }

    public static function queueCopy(bool $value): void
    {
        self::$copySequence[] = $value;
    }

    /**
     * @param array<int, string> $output
     */
    public static function queueExecResult(int $exitCode, ?string $destination = null, ?string $contents = null, array $output = []): void
    {
        self::$execSequence[] = [
            'exitCode' => $exitCode,
            'destination' => $destination,
            'contents' => $contents,
            'output' => $output,
        ];
    }

    public static function queueFileGetContents(string|false $value): void
    {
        self::$fileGetContentsSequence[] = $value;
    }

    public static function isWritable(string $path): bool
    {
        if ([] !== self::$isWritableSequence) {
            return (bool) array_shift(self::$isWritableSequence);
        }

        if (null !== self::$isWritableResult) {
            return self::$isWritableResult;
        }

        return is_writable($path);
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

    public static function hashFile(string $algo, string $filename): string|false
    {
        if ([] !== self::$hashFileSequence) {
            return array_shift(self::$hashFileSequence);
        }

        return hash_file($algo, $filename);
    }

    public static function rename(string $from, string $to): bool
    {
        if ([] !== self::$renameSequence) {
            return (bool) array_shift(self::$renameSequence);
        }

        return rename($from, $to);
    }

    public static function copy(string $from, string $to): bool
    {
        if ([] !== self::$copySequence) {
            return (bool) array_shift(self::$copySequence);
        }

        return copy($from, $to);
    }

    /**
     * @return resource|false
     */
    public static function fopen(string $filename, string $mode, bool $useIncludePath = false, mixed $context = null): mixed
    {
        if (true === self::$forceFopenReadFail && str_starts_with($mode, 'r')) {
            return false;
        }

        if (true === self::$forceFopenWriteFail && str_starts_with($mode, 'w')) {
            return false;
        }

        if ([] !== self::$fopenSequence) {
            return array_shift(self::$fopenSequence);
        }

        $contextResource = \is_resource($context) ? $context : null;
        if (null !== $contextResource) {
            return fopen($filename, $mode, $useIncludePath, $contextResource);
        }

        return fopen($filename, $mode, $useIncludePath);
    }

    /**
     * @param array<int, string>|null $output
     *
     * @param-out array<int, string> $output
     * @param-out int $resultCode
     */
    public static function exec(string $command, ?array &$output = null, ?int &$resultCode = null): string
    {
        if ([] !== self::$execSequence) {
            $entry = array_shift(self::$execSequence);
            $resultCode = (int) ($entry['exitCode'] ?? 1);
            $output = isset($entry['output']) ? array_map(strval(...), $entry['output']) : [];

            if (0 === $resultCode && isset($entry['destination'])) {
                $destination = (string) $entry['destination'];
                $directory = \dirname($destination);
                if (!is_dir($directory)) {
                    @mkdir($directory, 0o777, true);
                }
                $contents = $entry['contents'] ?? '';
                @file_put_contents($destination, $contents);
            }

            return $output[0] ?? '';
        }

        // Default mock behavior when no sequence is set up - prevent real exec calls during tests
        $resultCode = 1;
        $output = ['Mocked exec: command not mocked'];

        return '';
    }

    public static function fileGetContents(string $filename): string|false
    {
        if ([] !== self::$fileGetContentsSequence) {
            return array_shift(self::$fileGetContentsSequence);
        }

        return file_get_contents($filename);
    }
}

namespace PhpRegex\Cli\SelfUpdate;

use PhpRegex\Tests\Support\SelfUpdateFunctionOverrides;

function is_writable(string $filename): bool
{
    return SelfUpdateFunctionOverrides::isWritable($filename);
}

function tempnam(string $directory, string $prefix): string|false
{
    return SelfUpdateFunctionOverrides::tempnam($directory, $prefix);
}

function hash_file(string $algo, string $filename): string|false
{
    return SelfUpdateFunctionOverrides::hashFile($algo, $filename);
}

function rename(string $from, string $to): bool
{
    return SelfUpdateFunctionOverrides::rename($from, $to);
}

function copy(string $from, string $to): bool
{
    return SelfUpdateFunctionOverrides::copy($from, $to);
}

function fopen(string $filename, string $mode, bool $use_include_path = false, mixed $context = null): mixed
{
    return SelfUpdateFunctionOverrides::fopen($filename, $mode, $use_include_path, $context);
}

/**
 * @param array<int, string>|null $output
 *
 * @param-out array<int, string> $output
 * @param-out int $result_code
 */
function exec(string $command, ?array &$output = null, ?int &$result_code = null): string
{
    return SelfUpdateFunctionOverrides::exec($command, $output, $result_code);
}

function file_get_contents(string $filename): string|false
{
    return SelfUpdateFunctionOverrides::fileGetContents($filename);
}
