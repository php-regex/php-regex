<?php

declare(strict_types=1);

/**
 * Loads the generated twin for static analysis: PSR-4 PHPRegex\Parser\
 * rooted at build/php74/src, the same layout the split package maps.
 */

spl_autoload_register(static function (string $class): void {
	$prefix = 'PHPRegex\\Parser\\';

	if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
		return;
	}

	$path = __DIR__ . '/php74/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

	if (is_file($path)) {
		require $path;
	}
});
