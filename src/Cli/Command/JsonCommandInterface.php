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

namespace PHPRegex\Cli\Command;

/**
 * A command with a JSON mode, --format=json or --json: once its command line
 * asks for JSON, stdout holds one JSON document, the error envelope when the
 * command line cannot be used or PHP stops the run.
 *
 * @internal
 */
interface JsonCommandInterface extends CommandInterface {}
