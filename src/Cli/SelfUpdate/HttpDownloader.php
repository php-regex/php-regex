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

/**
 * Downloads over HTTP with PHP's streams, falling back on curl, then on
 * wget, each under a connection and a transfer timeout.
 *
 * @internal
 */
final class HttpDownloader implements Downloader
{
    /**
     * How long the download may take to connect, and to finish.
     *
     * Without them an unreachable mirror leaves the updater hanging with no
     * way out but a signal.
     */
    private const CONNECT_TIMEOUT_SECONDS = 10;

    private const TRANSFER_TIMEOUT_SECONDS = 30;

    /**
     * Download the URL to the file, through PHP's streams, else curl, else
     * wget.
     *
     * @throws CliException when none of them could
     */
    public function download(string $url, string $destination): void
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
}
