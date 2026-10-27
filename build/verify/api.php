<?php

declare(strict_types=1);

/**
 * Writes the public surface of the parser package the autoloader loads, the
 * reference the twin's surface is compared to.
 *
 *   php build/verify/api.php AUTOLOAD PACKAGE_DIRECTORY > api.json
 */
require $argv[1];
require __DIR__.'/ApiSurface.php';

echo json_encode(PHPRegex\Build\Verify\ApiSurface::of(rtrim($argv[2], '/')), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES), "\n";
