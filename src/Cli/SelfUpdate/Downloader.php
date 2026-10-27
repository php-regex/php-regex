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
 * Where self-update reads the release from.
 *
 * @internal
 */
interface Downloader
{
    /**
     * Write what the URL holds to the file.
     *
     * @throws CliException when it cannot be downloaded
     */
    public function download(string $url, string $destination): void;
}
