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

namespace PhpRegex\Cli\SelfUpdate;

use PhpRegex\Cli\CliException;
use PhpRegex\Cli\Output;

class SelfUpdater
{
    /**
     * How long the download may take to connect, and to finish.
     *
     * Without them an unreachable mirror leaves the updater hanging with no
     * way out but a signal.
     */
    private const CONNECT_TIMEOUT_SECONDS = 10;

    private const TRANSFER_TIMEOUT_SECONDS = 30;

    public function run(Output $output): void
    {
        $pharPath = $this->getPharPath();
        $this->validatePharPath($pharPath);

        $updateUrl = $this->getUpdateUrl();
        $checksumUrl = $this->getChecksumUrl();

        $output->write('  '.$output->dim('Downloading latest release...')."\n");

        $checksum = $this->parseChecksum($this->fetchRemoteString($checksumUrl));

        $tempPath = $this->createTempFile();
        $this->downloadFile($updateUrl, $tempPath);

        $this->verifyChecksum($tempPath, $checksum);
        $this->validateDownloadedPhar($tempPath);

        $this->replacePhar($pharPath, $tempPath);

        $output->write('  '.$output->badge('PASS', Output::WHITE, Output::BG_GREEN).' '.$output->success('PhpRegex updated successfully.')."\n");
    }

    protected function getPharPath(): string
    {
        return \Phar::running(false);
    }

    protected function getUpdateUrl(): string
    {
        return 'https://github.com/php-regex/regex-parser/releases/latest/download/regex.phar';
    }

    protected function getChecksumUrl(): string
    {
        return $this->getUpdateUrl().'.sha256';
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

        if (strtolower($hash) !== strtolower($expectedChecksum)) {
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

    private function downloadFile(string $url, string $destination): void
    {
        $streamSuccess = $this->downloadWithStreamContext($url, $destination);
        if ($streamSuccess) {
            return;
        }

        $curlSuccess = $this->downloadWithCurl($url, $destination);
        if ($curlSuccess) {
            return;
        }

        $wgetSuccess = $this->downloadWithWget($url, $destination);
        if ($wgetSuccess) {
            return;
        }

        throw new CliException('Unable to download '.$url.'.');
    }

    private function downloadWithStreamContext(string $url, string $destination): bool
    {
        $context = $this->createStreamContext();

        $read = @fopen($url, 'r', false, $context);
        if (!\is_resource($read)) {
            return false;
        }

        $write = @fopen($destination, 'w');
        if (!\is_resource($write)) {
            fclose($read);

            throw new CliException('Unable to write to '.$destination.'.');
        }

        stream_copy_to_stream($read, $write);
        fclose($read);
        fclose($write);

        if (!file_exists($destination) || 0 === (int) filesize($destination)) {
            throw new CliException('Downloaded file is empty.');
        }

        return true;
    }

    private function downloadWithCurl(string $url, string $destination): bool
    {
        if (!\function_exists('exec')) {
            return false;
        }

        $escapedUrl = escapeshellarg($url);
        $escapedDestination = escapeshellarg($destination);

        $output = [];
        $exitCode = 1;
        @exec(
            \sprintf(
                'curl -fsSL --connect-timeout %d --max-time %d %s -o %s',
                self::CONNECT_TIMEOUT_SECONDS,
                self::TRANSFER_TIMEOUT_SECONDS,
                $escapedUrl,
                $escapedDestination,
            ),
            $output,
            $exitCode,
        );
        if (0 !== $exitCode) {
            return false;
        }

        if (!file_exists($destination) || 0 === (int) filesize($destination)) {
            throw new CliException('Downloaded file is empty.');
        }

        return true;
    }

    private function downloadWithWget(string $url, string $destination): bool
    {
        if (!\function_exists('exec')) {
            return false;
        }

        $escapedUrl = escapeshellarg($url);
        $escapedDestination = escapeshellarg($destination);

        $output = [];
        $exitCode = 1;
        @exec(
            \sprintf(
                'wget -q --connect-timeout=%d --timeout=%d -O %s %s',
                self::CONNECT_TIMEOUT_SECONDS,
                self::TRANSFER_TIMEOUT_SECONDS,
                $escapedDestination,
                $escapedUrl,
            ),
            $output,
            $exitCode,
        );
        if (0 !== $exitCode) {
            return false;
        }

        if (!file_exists($destination) || 0 === (int) filesize($destination)) {
            throw new CliException('Downloaded file is empty.');
        }

        return true;
    }

    /**
     * @return resource
     */
    private function createStreamContext()
    {
        return stream_context_create([
            'http' => [
                'follow_location' => 1,
                'timeout' => self::TRANSFER_TIMEOUT_SECONDS,
                'user_agent' => 'php-regex-cli',
            ],
            'https' => [
                'follow_location' => 1,
                'timeout' => self::TRANSFER_TIMEOUT_SECONDS,
                'user_agent' => 'php-regex-cli',
            ],
        ]);
    }

    private function fetchRemoteString(string $url): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'regex-update-');
        if (false === $tempFile) {
            throw new CliException('Unable to create a temporary file.');
        }

        try {
            $this->downloadFile($url, $tempFile);
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
        $line = trim($contents);
        $parts = preg_split('/\s+/', $line);
        $checksum = strtolower($parts[0] ?? '');

        if (!preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            throw new CliException('Invalid checksum format.');
        }

        return $checksum;
    }

    private function validateDownloadedPhar(string $path): void
    {
        try {
            new \Phar($path);
        } catch (\Exception $e) {
            throw new CliException('Downloaded phar is invalid: '.$e->getMessage());
        }
    }
}
