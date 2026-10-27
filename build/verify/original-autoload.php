<?php

declare(strict_types=1);

/**
 * Loads the original parser package, src/Parser, and nothing else: what the
 * readings of the original need on a PHP image with no dependency installed.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'PHPRegex\\Parser\\';
    if (0 !== strncmp($class, $prefix, \strlen($prefix))) {
        return;
    }

    $path = __DIR__.'/../../src/Parser/'.str_replace('\\', '/', substr($class, \strlen($prefix))).'.php';
    if (is_file($path)) {
        require $path;
    }
});
