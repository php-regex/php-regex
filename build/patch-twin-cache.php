<?php

declare(strict_types=1);

/**
 * Lets a cached tree come back whole in the twin. PHP 8.1 restores an enum
 * case whatever the allowed classes, as the case itself; the twin's classes
 * of cases are objects, refused unless listed and rebuilt as new instances
 * when listed. AstSerializer::unserialize() lists them and gives each case
 * back its shared instance, so a tree read from a cache compares as the
 * tree just parsed. Each line patched must stand once in the file, or the
 * build stops.
 */
$path = __DIR__ . '/php74/Parser/Cache/AstSerializer.php';
$source = (string) file_get_contents($path);

$patches = [
	"\$value = @unserialize(\$data, ['allowed_classes' => self::NODE_CLASSES]);"
		=> "\$value = \\PHPRegex\\Parser\\EnumCases::shared(@unserialize(\$data, ['allowed_classes' => array_merge(self::NODE_CLASSES, \\PHPRegex\\Parser\\EnumCases::CLASSES)]));",
];

foreach ($patches as $from => $to) {
	$found = substr_count($source, $from);
	if ($found !== 1) {
		fwrite(STDERR, sprintf("patch-twin-cache: %s stands %d times in %s\n", $from, $found, $path));
		exit(1);
	}

	$source = str_replace($from, $to, $source);
}

file_put_contents($path, $source);
echo "cache restores the twin's enum cases\n";
