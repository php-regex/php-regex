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

use PHPRegex\Cli\SelfUpdate\Downloader;
use PHPRegex\Cli\SelfUpdate\HttpDownloader;

/**
 * Serves the release from local files: each release URL is downloaded, with
 * the real downloader, from the file it is mapped to.
 */
final class LocalReleaseDownloader implements Downloader
{
    /**
     * @var list<string>
     */
    public array $requested = [];

    /**
     * @param array<string, string> $files the file each URL is served from
     */
    public function __construct(private readonly array $files) {}

    public function download(string $url, string $destination): void
    {
        $this->requested[] = $url;
        (new HttpDownloader())->download($this->files[$url] ?? 'invalid://'.$url, $destination);
    }
}
