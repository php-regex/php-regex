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

namespace PHPRegex\Cli\SelfUpdate;

use PHPRegex\Cli\Exception\CliException;
use PHPRegex\Cli\Output;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Text\Ascii;

/**
 * @internal
 */
final class SelfUpdater
{
    /**
     * The phar of the latest release, and its SHA-256 checksum.
     */
    public const UPDATE_URL = 'https://github.com/php-regex/php-regex/releases/latest/download/regex.phar';

    public const CHECKSUM_URL = self::UPDATE_URL.'.sha256';

    /**
     * @param ?string $pharPath the phar to replace, the one running when null
     */
    public function __construct(private readonly Downloader $downloader = new HttpDownloader(), private readonly ?string $pharPath = null) {}

    public function run(Output $output): void
    {
        $pharPath = $this->pharPath ?? \Phar::running(false);
        $this->validatePharPath($pharPath);

        $output->write('  '.$output->dim('Downloading latest release...')."\n");

        $checksum = $this->parseChecksum($this->fetchRemoteString(self::CHECKSUM_URL));

        $tempPath = $this->createTempFile();
        $this->downloader->download(self::UPDATE_URL, $tempPath);

        $this->verifyChecksum($tempPath, $checksum);
        $this->validateDownloadedPhar($tempPath);

        $this->replacePhar($pharPath, $tempPath);

        $output->write('  '.$output->badge('PASS', Output::WHITE, Output::BG_GREEN).' '.$output->success('PHPRegex updated successfully.')."\n");
    }

    private function validatePharPath(string $pharPath): void
    {
        if ('' === $pharPath) {
            throw new CliException('Self-update is only supported for phar installs.');
        }

        if (!file_exists($pharPath)) {
            throw new CliException('Unable to locate the running phar.');
        }

        if (!is_writable($pharPath)) {
            throw new CliException('The phar file is not writable: '.$pharPath.'.');
        }
    }

    private function createTempFile(): string
    {
        $tempBase = tempnam(sys_get_temp_dir(), 'regex-phar-');
        if (false === $tempBase) {
            throw new CliException('Unable to create a temporary file.');
        }

        @unlink($tempBase);

        return $tempBase.'.phar';
    }

    private function verifyChecksum(string $tempPath, string $expectedChecksum): void
    {
        $hash = hash_file('sha256', $tempPath);
        if (false === $hash) {
            @unlink($tempPath);

            throw new CliException('Unable to hash the downloaded phar.');
        }

        if (Ascii::lower($hash) !== Ascii::lower($expectedChecksum)) {
            @unlink($tempPath);

            throw new CliException('Checksum verification failed.');
        }
    }

    private function replacePhar(string $pharPath, string $tempPath): void
    {
        $permissions = @fileperms($pharPath);
        if (false !== $permissions) {
            @chmod($tempPath, $permissions & 0o777);
        }

        if (!@rename($tempPath, $pharPath)) {
            if (!@copy($tempPath, $pharPath)) {
                @unlink($tempPath);

                throw new CliException('Unable to replace the existing binary.');
            }
            @unlink($tempPath);
        }
    }

    private function fetchRemoteString(string $url): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'regex-update-');
        if (false === $tempFile) {
            throw new CliException('Unable to create a temporary file.');
        }

        try {
            $this->downloader->download($url, $tempFile);
            $data = file_get_contents($tempFile);
            if (false === $data) {
                throw new CliException('Unable to read downloaded data.');
            }
        } finally {
            @unlink($tempFile);
        }

        return $data;
    }

    private function parseChecksum(string $contents): string
    {
        $line = trim($contents, " \t\n\r\0\x0B");
        $first = (LibraryPcre::split('/\s+/', $line) ?: [])[0] ?? '';
        $checksum = Ascii::lower($first);

        if (!LibraryPcre::match('/^[a-f0-9]{64}$/', $checksum)) {
            throw new CliException('Invalid checksum format.');
        }

        return $checksum;
    }

    private function validateDownloadedPhar(string $path): void
    {
        try {
            new \Phar($path);
        } catch (\Exception $e) {
            throw new CliException('Downloaded phar is invalid: '.$e->getMessage(), 0, $e);
        }
    }
}
