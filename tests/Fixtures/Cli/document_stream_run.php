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

require dirname(__DIR__, 3).'/vendor/autoload.php';

// An output buffer whose handler leaves nothing through, as the buffering
// layer of a process that died of exhaustion does: whatever it held is
// lost, and a document written into it never reaches the reader.
ob_start(static fn (string $buffer): string => '');

(new PHPRegex\Cli\Output(false, false))->writeDocumentPastBuffers('{"document": true}'.\PHP_EOL);

ob_end_flush();
