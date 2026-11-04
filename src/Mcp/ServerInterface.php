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

namespace PHPRegex\Mcp;

/**
 * A server speaking the protocol over two streams: the one seam between
 * the catalog and whatever implements the protocol.
 */
interface ServerInterface
{
    /**
     * Serves until the input ends.
     *
     * @param resource $input  where the client's messages are read
     * @param resource $output where the answers are written
     *
     * @return int the exit code: 0 when the input ended, else a failure of the transport
     */
    public function run($input, $output): int;
}
