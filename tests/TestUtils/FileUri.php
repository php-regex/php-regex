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

namespace PHPRegex\Tests\TestUtils;

use PHPRegex\Parser\Text\Path;

/**
 * The file:// URI an editor sends for a local path: "file:///home/a/x.php"
 * on Linux and macOS, "file:///C%3A/Users/a/x.php" on Windows, where the
 * drive letter follows the third slash and the colon is percent-encoded
 * as VS Code spells it. Each segment is percent-encoded.
 */
final class FileUri
{
    private function __construct() {}

    public static function of(string $path): string
    {
        $path = Path::fromHost($path);
        $segments = array_map(rawurlencode(...), explode('/', $path));

        return 'file://'.(str_starts_with($path, '/') ? '' : '/').implode('/', $segments);
    }
}
